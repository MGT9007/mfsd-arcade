<?php
/**
 * MFSD Arcade — Admin Page
 * Global settings (coins-to-time ratio), game catalogue CRUD, session overview.
 */

if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) wp_die('Unauthorized');

$db = new MFSD_Arcade_DB();

/* ================================================================
   HANDLE ACTIONS
   ================================================================ */

/* ── Save global settings ── */
if (isset($_POST['mfsd_arcade_save_settings']) && check_admin_referer('mfsd_arcade_settings')) {
    $mpc = max(0.5, min(60, (float) ($_POST['minutes_per_coin'] ?? 3)));
    update_option(MFSD_Arcade::OPTION_MPC, $mpc);
    echo '<div class="notice notice-success"><p>Settings saved. 1 coin = ' . esc_html($mpc) . ' minute(s) of arcade time.</p></div>';
}

/* ── Add game ── */
if (isset($_POST['mfsd_arcade_add_game']) && check_admin_referer('mfsd_arcade_game')) {
    $title = sanitize_text_field($_POST['game_title'] ?? '');
    $slug  = sanitize_key($_POST['game_slug'] ?? '');
    $url   = esc_url_raw($_POST['game_iframe_url'] ?? '');

    if ($title && $slug) {
        $db->insert_game(array(
            'title'         => $title,
            'slug'          => $slug,
            'description'   => sanitize_text_field($_POST['game_description'] ?? ''),
            'category'      => sanitize_key($_POST['game_category'] ?? 'retro'),
            'iframe_url'    => $url,  /* empty = internal game */
            'thumbnail_url' => esc_url_raw($_POST['game_thumbnail_url'] ?? ''),
            'min_coins'     => max(1, (int) ($_POST['game_min_coins'] ?? 1)),
            'active'        => isset($_POST['game_active']) ? 1 : 0,
            'sort_order'    => (int) ($_POST['game_sort_order'] ?? 0),
        ));
        $type = $url ? 'external' : 'internal (from plugin /games/' . esc_html($slug) . '/)';
        echo '<div class="notice notice-success"><p>Game "' . esc_html($title) . '" added (' . $type . ').</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>Title and slug are required.</p></div>';
    }
}

/* ── Update game ── */
if (isset($_POST['mfsd_arcade_update_game']) && check_admin_referer('mfsd_arcade_game')) {
    $gid = (int) $_POST['game_id'];
    if ($gid > 0) {
        $db->update_game($gid, array(
            'title'         => sanitize_text_field($_POST['game_title'] ?? ''),
            'slug'          => sanitize_key($_POST['game_slug'] ?? ''),
            'description'   => sanitize_text_field($_POST['game_description'] ?? ''),
            'category'      => sanitize_key($_POST['game_category'] ?? 'retro'),
            'iframe_url'    => esc_url_raw($_POST['game_iframe_url'] ?? ''),
            'thumbnail_url' => esc_url_raw($_POST['game_thumbnail_url'] ?? ''),
            'min_coins'     => max(1, (int) ($_POST['game_min_coins'] ?? 1)),
            'active'        => isset($_POST['game_active']) ? 1 : 0,
            'sort_order'    => (int) ($_POST['game_sort_order'] ?? 0),
        ));
        echo '<div class="notice notice-success"><p>Game updated.</p></div>';
    }
}

/* ── Delete game ── */
if (isset($_POST['mfsd_arcade_delete_game']) && check_admin_referer('mfsd_arcade_delete_game')) {
    $gid = (int) $_POST['delete_game_id'];
    if ($gid > 0) {
        $db->delete_game($gid);
        echo '<div class="notice notice-success"><p>Game deleted.</p></div>';
    }
}

/* ── Load data ── */
$mpc   = (float) get_option(MFSD_Arcade::OPTION_MPC, 3);
$games = $db->get_all_games();
$stats = $db->get_session_stats();
$recent_sessions = $db->get_recent_sessions(20);
$categories = array('retro' => 'Retro', 'puzzle' => 'Puzzle', 'platformer' => 'Platformer', 'action' => 'Action');
?>

<div class="wrap">
    <h1>🕹️ MFSD Arcade</h1>

    <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
        <a href="#" class="nav-tab nav-tab-active" onclick="arcTab(event,'arc-tab-settings')">Settings</a>
        <a href="#" class="nav-tab" onclick="arcTab(event,'arc-tab-games')">Games (<?php echo count($games); ?>)</a>
        <a href="#" class="nav-tab" onclick="arcTab(event,'arc-tab-sessions')">Sessions</a>
        <a href="#" class="nav-tab" onclick="arcTab(event,'arc-tab-add')">Add Game</a>
    </nav>

    <!-- ═══════════════ SETTINGS TAB ═══════════════ -->
    <div id="arc-tab-settings" class="arc-admin-tab">
        <form method="post">
            <?php wp_nonce_field('mfsd_arcade_settings'); ?>

            <div style="background:#fff;padding:24px;border:1px solid #ddd;border-radius:8px;max-width:600px;">
                <h3 style="margin-top:0;">🪙 Coin Economy</h3>
                <p class="description" style="margin-bottom:16px;">This setting controls how much arcade time each coin buys. It applies globally across all games.</p>

                <table class="form-table" style="margin:0;">
                    <tr>
                        <th style="width:200px;">Minutes per coin</th>
                        <td>
                            <input type="number" name="minutes_per_coin" min="0.5" max="60" step="0.5" value="<?php echo esc_attr($mpc); ?>" style="width:80px;">
                            <p class="description" style="margin-top:8px;">
                                <strong>Current rate:</strong> 1 coin = <?php echo esc_html($mpc); ?> minute<?php echo $mpc != 1 ? 's' : ''; ?> of arcade time.
                            </p>
                            <p class="description" style="margin-top:4px;">
                                Examples: a student spending <strong>5 coins</strong> would get <strong><?php echo esc_html(round(5 * $mpc, 1)); ?> minutes</strong>.
                                Spending <strong>10 coins</strong> = <strong><?php echo esc_html(round(10 * $mpc, 1)); ?> minutes</strong>.
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <p style="margin-top:16px;">
                <button type="submit" name="mfsd_arcade_save_settings" class="button button-primary" style="font-size:14px;padding:6px 20px;">Save Settings</button>
            </p>
        </form>

        <!-- Quick stats -->
        <?php if ($stats): ?>
        <div style="background:#f0f6fc;padding:20px;border:1px solid #c8d8e8;border-radius:8px;max-width:600px;margin-top:24px;">
            <h3 style="margin-top:0;">📊 Arcade Stats</h3>
            <table class="widefat" style="max-width:400px;">
                <tr><td>Total sessions</td><td><strong><?php echo (int) $stats['total_sessions']; ?></strong></td></tr>
                <tr><td>Active now</td><td><strong style="color:#28a745;"><?php echo (int) $stats['active_now']; ?></strong></td></tr>
                <tr><td>Paused</td><td><strong style="color:#f0ad4e;"><?php echo (int) $stats['paused_now']; ?></strong></td></tr>
                <tr><td>Total coins spent</td><td><strong><?php echo (int) $stats['total_coins_spent']; ?></strong> 🪙</td></tr>
                <tr><td>Total minutes purchased</td><td><strong><?php echo round((float) $stats['total_minutes_purchased'], 1); ?></strong> min</td></tr>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══════════════ GAMES TAB ═══════════════ -->
    <div id="arc-tab-games" class="arc-admin-tab" style="display:none;">
        <?php if (empty($games)): ?>
            <p>No games added yet. Go to the <strong>Add Game</strong> tab to add your first game.</p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:1000px;">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Slug</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Min coins</th>
                        <th>Status</th>
                        <th>Order</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($games as $g): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($g['title']); ?></strong>
                            <?php if ($g['description']): ?><br><small style="color:#888;"><?php echo esc_html($g['description']); ?></small><?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html($g['slug']); ?></code></td>
                        <td><?php echo empty($g['iframe_url']) ? '<span style="color:#28a745;">Internal 🔒</span>' : '<span style="color:#888;">External</span>'; ?></td>
                        <td><?php echo esc_html(ucfirst($g['category'])); ?></td>
                        <td><?php echo (int) $g['min_coins']; ?> (<?php echo esc_html(MFSD_Arcade::coins_to_minutes_label($g['min_coins'], $mpc)); ?>)</td>
                        <td><?php echo $g['active'] ? '<span style="color:#28a745;">Active</span>' : '<span style="color:#999;">Inactive</span>'; ?></td>
                        <td><?php echo (int) $g['sort_order']; ?></td>
                        <td>
                            <button type="button" class="button button-small" onclick="arcEditGame(<?php echo esc_attr(wp_json_encode($g)); ?>)">Edit</button>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('mfsd_arcade_delete_game'); ?>
                                <input type="hidden" name="delete_game_id" value="<?php echo (int) $g['id']; ?>">
                                <button type="submit" name="mfsd_arcade_delete_game" class="button button-small" style="color:#dc3545;" onclick="return confirm('Delete this game?');">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Edit game modal (inline form, shown via JS) -->
        <div id="arc-edit-game-form" style="display:none;background:#fff;padding:24px;border:1px solid #0073aa;border-radius:8px;max-width:700px;margin-top:24px;">
            <h3 style="margin-top:0;">Edit Game</h3>
            <form method="post">
                <?php wp_nonce_field('mfsd_arcade_game'); ?>
                <input type="hidden" name="game_id" id="edit-game-id">
                <table class="form-table" style="margin:0;">
                    <tr><th>Title</th><td><input type="text" name="game_title" id="edit-game-title" style="width:100%;" required></td></tr>
                    <tr><th>Slug</th><td><input type="text" name="game_slug" id="edit-game-slug" style="width:100%;" required></td></tr>
                    <tr><th>Description</th><td><input type="text" name="game_description" id="edit-game-desc" style="width:100%;"></td></tr>
                    <tr><th>Category</th><td>
                        <select name="game_category" id="edit-game-cat">
                            <?php foreach ($categories as $val => $label): ?>
                                <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th>Iframe URL</th><td><input type="url" name="game_iframe_url" id="edit-game-url" style="width:100%;" placeholder="Blank = internal game"><p class="description">Leave blank for internal games (served from plugin). Enter URL for external games.</p></td></tr>
                    <tr><th>Thumbnail URL</th><td><input type="url" name="game_thumbnail_url" id="edit-game-thumb" style="width:100%;"></td></tr>
                    <tr><th>Min coins</th><td><input type="number" name="game_min_coins" id="edit-game-min" min="1" max="100" style="width:80px;"></td></tr>
                    <tr><th>Sort order</th><td><input type="number" name="game_sort_order" id="edit-game-order" min="0" style="width:80px;"></td></tr>
                    <tr><th>Active</th><td><label><input type="checkbox" name="game_active" id="edit-game-active" value="1"> Visible in arcade</label></td></tr>
                </table>
                <p style="margin-top:16px;">
                    <button type="submit" name="mfsd_arcade_update_game" class="button button-primary">Update Game</button>
                    <button type="button" class="button" onclick="document.getElementById('arc-edit-game-form').style.display='none'">Cancel</button>
                </p>
            </form>
        </div>
    </div>

    <!-- ═══════════════ SESSIONS TAB ═══════════════ -->
    <div id="arc-tab-sessions" class="arc-admin-tab" style="display:none;">
        <?php if (empty($recent_sessions)): ?>
            <p>No sessions yet.</p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:1000px;">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Game</th>
                        <th>Coins</th>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Started</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent_sessions as $s): ?>
                    <tr>
                        <td><?php echo esc_html($s['student_name'] ?? 'User #' . $s['student_id']); ?></td>
                        <td><?php echo esc_html($s['game_title'] ?? '—'); ?></td>
                        <td><?php echo (int) $s['coins_spent']; ?> 🪙</td>
                        <td><?php echo esc_html($s['minutes_purchased']); ?> min</td>
                        <td>
                            <?php
                            $colours = array('active' => '#28a745', 'paused' => '#f0ad4e', 'expired' => '#999', 'completed' => '#0073aa');
                            $col = $colours[$s['status']] ?? '#999';
                            echo '<span style="color:' . $col . ';font-weight:600;">' . esc_html(ucfirst($s['status'])) . '</span>';
                            ?>
                        </td>
                        <td><?php echo esc_html($s['started_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ═══════════════ ADD GAME TAB ═══════════════ -->
    <div id="arc-tab-add" class="arc-admin-tab" style="display:none;">
        <form method="post" style="background:#fff;padding:24px;border:1px solid #ddd;border-radius:8px;max-width:700px;">
            <?php wp_nonce_field('mfsd_arcade_game'); ?>
            <h3 style="margin-top:0;">Add New Game</h3>
            <table class="form-table" style="margin:0;">
                <tr><th>Title *</th><td><input type="text" name="game_title" style="width:100%;" required></td></tr>
                <tr><th>Slug *</th><td><input type="text" name="game_slug" style="width:100%;" required placeholder="e.g. hextris"><p class="description">URL-safe identifier, lowercase, no spaces.</p></td></tr>
                <tr><th>Description</th><td><input type="text" name="game_description" style="width:100%;" placeholder="Short description for the lobby"></td></tr>
                <tr><th>Category</th><td>
                    <select name="game_category">
                        <?php foreach ($categories as $val => $label): ?>
                            <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td></tr>
                <tr><th>Iframe URL</th><td><input type="url" name="game_iframe_url" style="width:100%;" placeholder="Leave blank for internal games"><p class="description"><strong>Internal game:</strong> leave blank — game files must be in <code>mfsd-arcade/games/{slug}/</code> inside the plugin. They are served through a session-gated endpoint (no direct URL access).<br><strong>External game:</strong> enter the full URL to the game's HTML file.</p></td></tr>
                <tr><th>Thumbnail URL</th><td><input type="url" name="game_thumbnail_url" style="width:100%;" placeholder="Optional — displayed in the lobby grid"></td></tr>
                <tr><th>Min coins</th><td><input type="number" name="game_min_coins" min="1" max="100" value="1" style="width:80px;"><p class="description">Minimum coins to start a session. At current rate: 1 coin = <?php echo esc_html($mpc); ?> min.</p></td></tr>
                <tr><th>Sort order</th><td><input type="number" name="game_sort_order" min="0" value="0" style="width:80px;"><p class="description">Lower numbers appear first.</p></td></tr>
                <tr><th>Active</th><td><label><input type="checkbox" name="game_active" value="1" checked> Visible in arcade</label></td></tr>
            </table>
            <p style="margin-top:16px;">
                <button type="submit" name="mfsd_arcade_add_game" class="button button-primary" style="font-size:14px;padding:6px 20px;">Add Game</button>
            </p>
        </form>
    </div>
</div>

<script>
function arcTab(e, tabId) {
    e.preventDefault();
    document.querySelectorAll('.arc-admin-tab').forEach(function(t) { t.style.display = 'none'; });
    document.querySelectorAll('.nav-tab').forEach(function(t) { t.classList.remove('nav-tab-active'); });
    document.getElementById(tabId).style.display = 'block';
    e.target.classList.add('nav-tab-active');
}

function arcEditGame(g) {
    document.getElementById('edit-game-id').value    = g.id;
    document.getElementById('edit-game-title').value  = g.title;
    document.getElementById('edit-game-slug').value   = g.slug;
    document.getElementById('edit-game-desc').value   = g.description || '';
    document.getElementById('edit-game-cat').value    = g.category;
    document.getElementById('edit-game-url').value    = g.iframe_url;
    document.getElementById('edit-game-thumb').value  = g.thumbnail_url || '';
    document.getElementById('edit-game-min').value    = g.min_coins;
    document.getElementById('edit-game-order').value  = g.sort_order;
    document.getElementById('edit-game-active').checked = g.active == 1;
    document.getElementById('arc-edit-game-form').style.display = 'block';
    document.getElementById('arc-edit-game-form').scrollIntoView({behavior:'smooth'});
}
</script>
