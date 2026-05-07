# MFSD Arcade — Technical Specification v1.0

**Plugin directory:** `mfsd-arcade/`
**Shortcode(s):** `[mfsd_arcade]`, `[mfsd_arcade_play game="slug"]`
**Version:** 3.0.9
**Author:** MisterT9007
**Requires Plugins:** mfsd-quest-log
**Purpose:** A coin-operated browser-game arcade for MFSD students. Students spend Quest Log coins to purchase timed play sessions on browser-based games. The plugin manages a game catalogue, a session lifecycle (purchase → active → paused → expired), and a per-game leaderboard system. Games can be hosted internally (served through a session-gated REST endpoint from `mfsd-arcade/games/`) or externally (any iframe URL). A reusable leaderboard library (`mfsd-leaderboard.js` + `mfsd-leaderboard.css`) can be dropped into any game to enable score submission and display.

---

## File Structure

| File | Purpose |
|------|---------|
| `mfsd-arcade.php` | Bootstrap: singleton class, shortcodes, asset registration, REST route registration, admin menu, activation hook |
| `admin/admin-page.php` | Admin UI: four-tab page (Settings, Games, Sessions, Add Game); handles all admin POST actions |
| `includes/class-arcade-db.php` | Database layer: table creation and all SQL queries for games, sessions, and scores |
| `includes/class-arcade-session.php` | Session manager: purchase, heartbeat, pause, resume, cleanup (expire + auto-pause stale) |
| `includes/class-arcade-api.php` | REST API: registers all `/mfsd-arcade/v1/` routes and delegates to the session and DB classes |
| `includes/class-arcade-game-loader.php` | Serves internal game HTML and static assets through session-gated REST endpoints; rewrites CSS `url()` references and patches JS `Audio`/`Image` constructors at runtime |
| `assets/arcade.css` | Front-end styles: dark gaming theme, lobby grid, play screen, timer bar, overlays |
| `assets/arcade.js` | Front-end logic: coin slider, purchase flow, session timer, heartbeat, pause/resume, overlay management, leaderboard config handoff via `postMessage` |
| `assets/mfsd-leaderboard.js` | Reusable drop-in leaderboard library for games: receives config via `postMessage`, shows initials-entry overlay, submits scores, renders leaderboard table |
| `assets/mfsd-leaderboard.css` | Styles for the in-game leaderboard overlay |
| `assets/mfsd-asteroids-hook.js` | Game-specific integration for the Asteroids game: monkey-patches `Game.FSM.end_game` to intercept game-over and trigger the leaderboard |
| `games/asteroids/` | Asteroids game files (internal game, HTML5 Canvas) |
| `games/asteroids/mfsd-manifest.json` | Asteroids manifest: canvas size, script/stylesheet lists, keyboard controls |
| `games/hgc/` | Hyperspace Garbage Collection game files (internal game, uses custom template) |
| `games/hgc/mfsd-manifest.json` | HGC manifest: version, uses_custom_template flag, keyboard controls |
| `games/hgc/mfsd-template.html` | Custom HTML template for HGC (replaces auto-generated page) |

---

## Database Schema

All tables are created in `register_activation_hook` via `MFSD_Arcade_DB::create_tables()`.

### wp_mfsd_arcade_games
| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| title | VARCHAR(100) | Display name |
| slug | VARCHAR(50) | URL-safe identifier; UNIQUE |
| description | VARCHAR(255) | Short description shown in lobby |
| category | ENUM('retro','puzzle','platformer','action') | Default: retro |
| iframe_url | VARCHAR(500) | Empty string = internal game (served from `/games/{slug}/`); non-empty = external iframe URL |
| thumbnail_url | VARCHAR(500) | Optional lobby thumbnail |
| min_coins | INT UNSIGNED | Minimum coins to start a session; default 1 |
| active | TINYINT(1) | 1 = visible in lobby; default 1 |
| sort_order | INT | Ascending sort order in lobby grid; default 0 |
| created_at | DATETIME | Auto-set on insert |

### wp_mfsd_arcade_sessions
| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| student_id | BIGINT UNSIGNED | WordPress user ID |
| game_id | BIGINT UNSIGNED | FK to wp_mfsd_arcade_games |
| session_token | VARCHAR(64) | Random token (48 chars, alphanumeric); UNIQUE; used to gate game asset access |
| coins_spent | INT UNSIGNED | Coins deducted at purchase |
| minutes_purchased | DECIMAL(6,1) | Play time purchased (coins × minutes_per_coin) |
| started_at | DATETIME | When session was created |
| expires_at | DATETIME | When session timer runs out (recalculated on resume) |
| paused_at | DATETIME | When session was paused (NULL if not paused) |
| remaining_seconds | INT UNSIGNED | Saved remaining time on pause (NULL when active) |
| status | ENUM('active','paused','expired','completed') | Default: active |
| last_heartbeat | DATETIME | Updated every 30s by client; used for stale detection |

**Indexes:**
- `idx_student_game (student_id, game_id, status)` — live session lookup
- `idx_student_active (student_id, status)` — cross-game live session check (lobby banner)
- `idx_stale (status, last_heartbeat)` — stale session cleanup

### wp_mfsd_arcade_scores
| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| student_id | BIGINT UNSIGNED | WordPress user ID |
| game_slug | VARCHAR(50) | Identifies the game (matches wp_mfsd_arcade_games.slug) |
| initials | VARCHAR(5) | 1–5 uppercase alphanumeric characters |
| score | INT UNSIGNED | Numeric score submitted by the game |
| created_at | DATETIME | Auto-set on insert |

**Indexes:**
- `idx_game_score (game_slug, score DESC)` — leaderboard queries
- `idx_student_game (student_id, game_slug)` — personal best lookup
- `idx_game_top (game_slug, score DESC, created_at ASC)` — tie-breaking

---

## Key Flows

### 1. Lobby (no active session)
1. Student visits the page containing `[mfsd_arcade]`.
2. Plugin checks login and `student` or `administrator` role; shows error message if not met.
3. `MFSD_Arcade_Session::cleanup()` runs: expires overdue sessions, auto-pauses sessions with no heartbeat for >60 seconds.
4. `MFSD_Arcade_DB::get_active_games()` returns the game catalogue sorted by `sort_order`.
5. `MFSD_Quest_Log_Wallet::get_balance()` returns the student's coin balance.
6. `MFSD_Arcade_DB::get_any_live_session()` checks for an active/paused session on any game; if found, a "resume" banner is shown.
7. PHP renders the lobby HTML (game grid with thumbnail, category, min coins, time equivalent). JS config is injected via `wp_localize_script`.
8. Student clicks "Play" on a game card — navigates to `?game={slug}` (same page, which switches to play mode via `shortcode_lobby`).

### 2. Play Screen — New Purchase
1. Student arrives at `?game={slug}`, triggering `shortcode_play` via the lobby shortcode.
2. Game looked up by slug; if inactive or not found, error is shown.
3. Existing live session for this student+game is checked; if found, it is shown with the resume overlay.
4. PHP renders the play screen: purchase panel (coin slider), empty game container, timer bar, paused/expired overlays.
5. Student adjusts the coin slider (min = game's `min_coins`, max = current balance).
6. Student clicks "Insert coins & play" → JS calls `POST /mfsd-arcade/v1/purchase`.
7. Server: validates game, enforces min_coins, checks balance, deducts coins via `MFSD_Quest_Log_Wallet::spend()`, generates a 48-char session token, creates the session row.
8. Server returns `session_token`, `expires_at`, `remaining_seconds`, `iframe_url` (gated URL for internal games).
9. JS loads the gated URL into the iframe, starts the client-side countdown timer, starts the 30-second heartbeat interval, hides the purchase panel.
10. After the iframe loads, JS sends `postMessage({ type: 'mfsd-arcade-config', apiBase, nonce, gameSlug })` to the iframe for leaderboard use.

### 3. Session Timer & Heartbeat
- Client decrements `remaining_seconds` every second and updates the timer bar (green → amber at 50% → red at 20%, urgent flash under 60s).
- Every 30 seconds, client calls `POST /mfsd-arcade/v1/heartbeat` with the session token.
- Server updates `last_heartbeat`, recalculates true remaining time, and syncs client if drift detected.
- If the server returns `status: expired`, `onExpired()` fires: iframe blanked, expired overlay shown, balance refreshed.

### 4. Pause
- Student clicks the pause button → JS calls `POST /mfsd-arcade/v1/pause`.
- Server calculates `remaining_seconds = expires_at - NOW()`, sets `status = paused`, saves `paused_at`.
- Client stops the timer and heartbeat, shows the paused overlay with the saved time.
- If the tab becomes hidden (`visibilitychange`), the client automatically fires a pause (best-effort, no guarantee).
- On page close (`beforeunload`), a `sendBeacon` (with synchronous XHR fallback) fires a pause. If all else fails, the server's stale detection auto-pauses the session after 60 seconds without a heartbeat.

### 5. Resume
- Student clicks "Resume" → JS calls `POST /mfsd-arcade/v1/resume`.
- Server recalculates `expires_at = NOW() + remaining_seconds`, clears `paused_at` and `remaining_seconds`, sets `status = active`.
- Client restarts the timer and heartbeat; if the iframe is blank, it reloads the gated URL.

### 6. Session Expiry
- Either the client timer hits zero, or the server confirms `status: expired` via heartbeat.
- `onExpired()`: iframe blanked, client state cleared, expired overlay shown with current coin balance.
- Student can click "Play again" to reset the purchase panel for a new session.

### 7. Leaderboard — Score Submission (in-game)
1. When a game ends, it calls `MFSDLeaderboard.onGameOver(score)`.
2. The leaderboard overlay appears showing the final score and 5 initials input boxes.
3. Student types initials (1–5 uppercase alphanumeric chars), presses "Submit Score".
4. JS calls `POST /mfsd-arcade/v1/scores` with `game`, `initials`, `score`.
5. Server validates initials and score, inserts the row, fetches top 10, calculates the student's rank and personal best.
6. Leaderboard table rendered with the student's row highlighted; rank banner shown.
7. Student clicks "Continue" → overlay hidden, `postMessage({ type: 'mfsd-leaderboard-closed' })` sent to parent window, game resets to title screen.

### 8. Internal Game Asset Serving
- Internal game files live in `games/{slug}/` and are blocked from direct HTTP access via `.htaccess`.
- Every request to `GET /mfsd-arcade/v1/game/{slug}?token=xxx` or `GET /mfsd-arcade/v1/game-asset/{slug}?file=path&token=xxx` must present a valid, active-or-paused session token.
- The token is matched against `wp_mfsd_arcade_sessions`; the session owner is confirmed to be a student or admin.
- For HTML: the game loader checks for `mfsd-template.html` (custom template) or `mfsd-manifest.json` (structured assembly); if neither, it auto-detects `.css` and `.js` files. The full HTML page is built with gated asset URLs injected throughout, plus a JS shim that patches `Audio()` and `HTMLImageElement.src` to rewrite relative paths through the gated endpoint.
- For assets: the `file` path is resolved with `realpath()` and confirmed to stay within the game directory, blocking path traversal. Dotfiles are rejected. Only file extensions in the `MIME_TYPES` allowlist are served.
- CSS files are additionally post-processed: `url()` references are rewritten to gated asset endpoints on the fly.

---

## AJAX / REST Endpoints

All session and score endpoints are REST API routes under the `/wp-json/mfsd-arcade/v1/` namespace. All require an authenticated session (`X-WP-Nonce` header) and the `student` or `administrator` role.

| Route | Method | Auth | Description |
|-------|--------|------|-------------|
| `/session?game_id={id}` | GET | student/admin | Get active/paused session status for current user + game |
| `/purchase` | POST | student/admin | Deduct coins, create a new session; returns `session_token`, `expires_at`, `remaining_seconds`, `iframe_url`, `new_balance` |
| `/heartbeat` | POST | student/admin | Ping to keep session alive; syncs remaining seconds; detects expiry |
| `/pause` | POST | student/admin | Freeze session timer; saves remaining seconds |
| `/resume` | POST | student/admin | Unfreeze session; recalculates `expires_at` from saved remaining |
| `/balance` | GET | student/admin | Returns current Quest Log coin balance |
| `/scores?game={slug}&limit={n}` | GET | student/admin | Returns top scores, player count, personal best, and current user's rank |
| `/scores` | POST | student/admin | Submit a score; validates initials (1–5 alphanumeric); returns updated leaderboard + rank |
| `/game/{slug}?token={token}` | GET | token validation | Serves internal game as a full HTML page (no WP cookie required; authenticated by session token) |
| `/game-asset/{slug}?file={path}&token={token}` | GET | token validation | Serves individual static game assets (JS, CSS, images, audio) gated by session token |

---

## Admin Panel

Accessed via **WP Admin > Arcade** (dashicon: games, menu position 32). Requires `manage_options` capability.

The admin page has four tabs navigated by JavaScript `onclick`:

**Settings tab**
- "Minutes per coin" field (0.5–60 in 0.5 steps); saved to `mfsd_arcade_minutes_per_coin` option.
- Live preview of the rate with examples (5 coins = X minutes, 10 coins = Y minutes).
- Quick stats panel: total sessions, active now, paused now, total coins spent, total minutes purchased.

**Games tab**
- Table of all games: title, slug, type (Internal/External), category, min coins, status, sort order.
- Edit button opens an inline edit form (JavaScript) pre-populated with the game's data; updates via `POST mfsd_arcade_update_game` with nonce `mfsd_arcade_game`.
- Delete button shows a confirmation and deletes via `POST mfsd_arcade_delete_game` with nonce `mfsd_arcade_delete_game`.

**Sessions tab**
- Table of the 20 most recent sessions: student name, game title, coins spent, minutes purchased, status (colour coded), started time.
- Read-only view; no actions.

**Add Game tab**
- Form fields: Title (required), Slug (required, URL-safe), Description, Category (select: retro/puzzle/platformer/action), Iframe URL (leave blank for internal), Thumbnail URL, Min coins (1–100), Sort order, Active checkbox.
- All actions protected by WordPress admin nonces (`check_admin_referer`).

---

## SteveGPT Integration

None. This plugin does not call SteveGPT or the Anthropic API.

---

## Assets

**arcade.css** — Front-end stylesheet for both lobby and play screens. Uses CSS custom properties for the dark gaming theme (dark background `#0d1117`, surface `#161b22`, gold coins `#fbbf24`, accent blue `#58a6ff`). Covers the header bar, wallet badge, economy rate label, controls bar, live session banner, game grid cards with hover effects, purchase panel with coin slider readout, timer bar (animated fill), iframe wrapper, and paused/expired full-screen overlays. Responsive breakpoint at 600px.

**arcade.js** — Single IIFE managing the front-end play flow. Reads `window.MFSD_ARCADE` config injected by PHP. In play mode it initialises all DOM references, wires up the coin slider, purchase, pause, resume, and "play again" buttons, connects visibility-change and beforeunload handlers, and restores any existing live/paused session from server data. Core functions: `doPurchase()`, `startSession()`, `startTimer()`, `startHeartbeat()`, `doPause()`, `doResume()`, `onExpired()`, `loadGameIframe()`, `sendLeaderboardConfig()`. Uses `navigator.sendBeacon` + synchronous XHR fallback for reliable pause on page unload.

**mfsd-leaderboard.js** — Drop-in leaderboard library designed to work inside any arcade game iframe. Waits for a `mfsd-arcade-config` postMessage from the parent arcade page (supplies `apiBase`, `nonce`, `gameSlug`). Exposes `window.MFSDLeaderboard.onGameOver(score)`, `isConnected()`, and `showLeaderboard()`. Builds the overlay DOM dynamically (initials-entry screen, leaderboard table screen, loading spinner). Handles 5-box initials input with auto-advance and backspace navigation. Submits scores to `POST /scores`. Highlights the current player's row in the leaderboard. Notifies the parent on close via `postMessage({ type: 'mfsd-leaderboard-closed' })`.

**mfsd-leaderboard.css** — Styles for the in-game leaderboard overlay (dark theme matching the arcade shell). Includes the fixed full-screen overlay, panel card, score display, 5-box initials entry, leaderboard table (gold/silver/bronze rank colours), rank banner, personal best meta line, and loading spinner.

**mfsd-asteroids-hook.js** — Asteroids-specific integration. Polls for `window.Game` to be available, then monkey-patches `Game.FSM.end_game` to intercept the game-over state. After 1.5 seconds of "GAME OVER" display, triggers `MFSDLeaderboard.onGameOver(finalScore)`. Monitors for leaderboard close (via postMessage and DOM observation) and resets the FSM to the `waiting` state. Also adds an `L` keyboard shortcut to open the leaderboard from the title/end screen.

---

## Security

**Role gating (shortcodes):** `shortcode_lobby` and `shortcode_play` both check `is_user_logged_in()` and that the user has the `student` or `administrator` role. Other roles see an access-denied message.

**REST API permissions:** All session and leaderboard endpoints use the `is_student()` permission callback, which verifies login and `student`/`administrator` role. The WordPress nonce is sent as the `X-WP-Nonce` header and validated automatically by the WP REST API.

**Game asset gating:** The game loader endpoints do not use `is_student()` (since the iframe cannot send WP cookies). Instead, they validate the `token` query parameter against an active-or-paused session in the database and also confirm the session owner has the student/admin role.

**Path traversal prevention (asset serving):** The requested file path is resolved with `realpath()` and confirmed to remain inside the game's directory. Dotfiles are individually rejected by checking every path segment. Only extensions in the explicit `MIME_TYPES` allowlist are served.

**Admin actions:** All admin form submissions use `check_admin_referer()` with per-action nonce strings. The `manage_options` capability is checked at both the menu-page render and at the top of `admin-page.php`.

**Input sanitisation:** All user-supplied values are sanitised: `sanitize_text_field`, `sanitize_key`, `esc_url_raw`, `absint`/`(int)` casts. All DB queries with variable input use `$wpdb->prepare()`.

**Score validation:** Initials are server-side validated against `/^[A-Z0-9]{1,5}$/`; score must be a non-negative integer.

**Coin deduction atomicity:** Coins are deducted via `MFSD_Quest_Log_Wallet::spend()` before the session row is created. If session creation fails, a refund is issued immediately.

---

## Inter-Plugin Dependencies

| Plugin | Usage |
|--------|-------|
| `mfsd-quest-log` | Required. Uses `MFSD_Quest_Log_Wallet` class for coin balance (`get_balance`), deductions (`spend`), and refunds (`refund`). The plugin checks `class_exists('MFSD_Quest_Log_Wallet')` and degrades gracefully if missing (purchase returns a 500 error). |

---

## Game Manifest Format (`mfsd-manifest.json`)

Internal games can include an `mfsd-manifest.json` in their directory to control how the game loader assembles the HTML page.

| Key | Type | Purpose |
|-----|------|---------|
| `title` | string | Human-readable game title |
| `canvas_id` | string | ID of the `<canvas>` element; default `canvas` |
| `canvas_width` | int | Canvas width in pixels |
| `canvas_height` | int | Canvas height in pixels |
| `canvas_bg` | string | Canvas background colour; default `#fff` |
| `head_scripts` | string[] | JS files loaded in `<head>` (before game code) |
| `body_scripts` | string[] | JS files loaded at end of `<body>` (after canvas) |
| `stylesheets` | string[] | CSS files loaded in `<head>` |
| `cdn_scripts` | string[] | External CDN script URLs (e.g. jQuery) loaded in `<head>` |
| `extra_head_html` | string | Raw HTML injected into `<head>` |
| `init_script` | string | Inline JS injected at end of `<body>` |
| `controls` | array | `[{ "key": "Space", "action": "Shoot" }]` — shown in the arcade controls bar |
| `uses_custom_template` | bool | If true, the loader uses `mfsd-template.html` instead of auto-building |

If no manifest is present, the loader auto-detects: `.css` files → stylesheets; `.js` files with `mfsd-` prefix → body scripts; all other `.js` → head scripts.

---

## Version History

| Version | Changes |
|---------|---------|
| 3.0.9 | Current version as of spec date |
| 3.0.x | Leaderboard system added (wp_mfsd_arcade_scores table, /scores endpoints, mfsd-leaderboard.js drop-in library, Asteroids hook) |
| 2.x | Session system (purchase, heartbeat, pause/resume, stale cleanup, gated game loader) |
| 1.x | Initial coin-operated arcade concept |
