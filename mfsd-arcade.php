<?php
/**
 * Plugin Name: MFSD Arcade
 * Description: Coin-operated game arcade for MFSD students. Spend quest coins for timed play sessions on browser-based games.
 * Version: 3.0.1
 * Author: MisterT9007
 * Requires Plugins: mfsd-quest-log
 */

if (!defined('ABSPATH')) exit;

/* ── Autoload includes ── */
foreach (array('db', 'session', 'api', 'game-loader') as $f) {
    require_once __DIR__ . '/includes/class-arcade-' . $f . '.php';
}

final class MFSD_Arcade {

    const VERSION      = '3.0.1';
    const OPTION_MPC   = 'mfsd_arcade_minutes_per_coin';   /* global: 1 coin = X minutes */

    public static function instance() {
        static $i = null;
        return $i ?: $i = new self();
    }

    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'install'));
        add_action('init',          array($this, 'register_assets'));
        add_shortcode('mfsd_arcade',     array($this, 'shortcode_lobby'));
        add_shortcode('mfsd_arcade_play', array($this, 'shortcode_play'));
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('admin_menu',    array($this, 'admin_menu'));
    }

    /* ================================================================
       INSTALL — create DB tables + default options
       ================================================================ */
    public function install() {
        MFSD_Arcade_DB::create_tables();
        add_option(self::OPTION_MPC, 3);   /* default: 1 coin = 3 minutes */
    }

    /** Global getter — 1 coin = X minutes of arcade time. */
    public static function minutes_per_coin() {
        return (float) get_option(self::OPTION_MPC, 3);
    }

    /* ================================================================
       ASSETS
       ================================================================ */
    public function register_assets() {
        $base = plugin_dir_url(__FILE__);
        wp_register_style('mfsd-arcade',  $base . 'assets/arcade.css', array(), self::VERSION);
        wp_register_script('mfsd-arcade', $base . 'assets/arcade.js',  array(), self::VERSION, true);
    }

    /* ================================================================
       SHORTCODE — [mfsd_arcade] — Game Lobby
       ================================================================ */
    public function shortcode_lobby($atts) {
        /* If ?game=slug is in the URL, switch to play mode automatically */
        if (!empty($_GET['game'])) {
            return $this->shortcode_play($atts);
        }

        if (!is_user_logged_in()) {
            return '<p class="mfsd-arcade-msg">Please log in to enter the Arcade.</p>';
        }

        $student_id = get_current_user_id();
        $user = get_userdata($student_id);
        if ($user && !in_array('student', (array)$user->roles) && !in_array('administrator', (array)$user->roles)) {
            return '<p class="mfsd-arcade-msg">The Arcade is only available for students.</p>';
        }

        /* Run stale-session cleanup */
        $session_mgr = new MFSD_Arcade_Session();
        $session_mgr->cleanup();

        $db    = new MFSD_Arcade_DB();
        $games = $db->get_active_games();

        /* Wallet balance */
        $balance = 0;
        if (class_exists('MFSD_Quest_Log_Wallet')) {
            $wallet  = new MFSD_Quest_Log_Wallet();
            $balance = $wallet->get_balance($student_id);
        }

        /* Check for existing live session */
        $live = $db->get_any_live_session($student_id);

        $mpc = self::minutes_per_coin();

        wp_localize_script('mfsd-arcade', 'MFSD_ARCADE', array(
            'restBase'       => esc_url_raw(rest_url('mfsd-arcade/v1')),
            'nonce'          => wp_create_nonce('wp_rest'),
            'studentId'      => $student_id,
            'balance'        => $balance,
            'minutesPerCoin' => $mpc,
            'liveSession'    => $live,
            'mode'           => 'lobby',
        ));

        wp_enqueue_style('mfsd-arcade');
        wp_enqueue_script('mfsd-arcade');

        return $this->render_lobby($games, $balance, $mpc, $live);
    }

    /* ================================================================
       SHORTCODE — [mfsd_arcade_play game="slug"] — Single Game Player
       ================================================================ */
    public function shortcode_play($atts) {
        $atts = shortcode_atts(array('game' => ''), $atts);

        if (!is_user_logged_in()) {
            return '<p class="mfsd-arcade-msg">Please log in to play.</p>';
        }

        $student_id = get_current_user_id();
        $user = get_userdata($student_id);
        if ($user && !in_array('student', (array)$user->roles) && !in_array('administrator', (array)$user->roles)) {
            return '<p class="mfsd-arcade-msg">The Arcade is only available for students.</p>';
        }

        /* Determine game — from shortcode attr or URL param */
        $slug = sanitize_key($atts['game'] ?: ($_GET['game'] ?? ''));
        if (empty($slug)) {
            return '<p class="mfsd-arcade-msg">No game selected. <a href="javascript:history.back()">Back to Arcade</a></p>';
        }

        $session_mgr = new MFSD_Arcade_Session();
        $session_mgr->cleanup();

        $db   = new MFSD_Arcade_DB();
        $game = $db->get_game_by_slug($slug);
        if (!$game || !$game['active']) {
            return '<p class="mfsd-arcade-msg">Game not found. <a href="javascript:history.back()">Back to Arcade</a></p>';
        }

        $balance = 0;
        if (class_exists('MFSD_Quest_Log_Wallet')) {
            $wallet  = new MFSD_Quest_Log_Wallet();
            $balance = $wallet->get_balance($student_id);
        }

        $live = $db->get_live_session($student_id, $game['id']);
        $mpc  = self::minutes_per_coin();

        /* Resolve the gated game URL if there's a live session */
        if ($live) {
            $live['game_url'] = MFSD_Arcade_Session::resolve_game_url($game, $live['session_token']);
        }

        wp_localize_script('mfsd-arcade', 'MFSD_ARCADE', array(
            'restBase'       => esc_url_raw(rest_url('mfsd-arcade/v1')),
            'nonce'          => wp_create_nonce('wp_rest'),
            'studentId'      => $student_id,
            'balance'        => $balance,
            'minutesPerCoin' => $mpc,
            'liveSession'    => $live,
            'game'           => $game,
            'mode'           => 'play',
        ));

        wp_enqueue_style('mfsd-arcade');
        wp_enqueue_script('mfsd-arcade');

        /* Read controls from the game's manifest (if available) */
        $controls = array();
        $games_dir = plugin_dir_path(__FILE__) . 'games/' . $slug . '/mfsd-manifest.json';
        if (file_exists($games_dir)) {
            $manifest = json_decode(file_get_contents($games_dir), true);
            if (!empty($manifest['controls'])) {
                $controls = $manifest['controls'];
            }
        }

        return $this->render_play($game, $balance, $mpc, $live, $controls);
    }

    /* ================================================================
       RENDER — Lobby
       ================================================================ */
    private function render_lobby($games, $balance, $mpc, $live) {
        ob_start();
        ?>
        <div id="mfsd-arcade-root" class="mfsd-arcade" data-mode="lobby">

            <!-- Header bar -->
            <div class="arc-header">
                <h2 class="arc-title">Arcade</h2>
                <div class="arc-wallet">
                    <span class="arc-coin-icon">🪙</span>
                    <span class="arc-balance" id="arc-balance"><?php echo (int) $balance; ?></span>
                    <span class="arc-balance-label">coins</span>
                </div>
            </div>

            <!-- Active session banner -->
            <?php if ($live): ?>
            <div class="arc-live-banner" id="arc-live-banner">
                <span>⏱️ You have an active session on <strong><?php echo esc_html($live['game_title']); ?></strong></span>
                <a href="?game=<?php echo esc_attr($live['game_slug']); ?>" class="arc-btn arc-btn-sm">Resume</a>
            </div>
            <?php endif; ?>

            <!-- Economy info -->
            <div class="arc-economy-info">
                <span class="arc-rate">1 coin = <?php echo esc_html($mpc); ?> minute<?php echo $mpc != 1 ? 's' : ''; ?></span>
            </div>

            <!-- Game grid -->
            <div class="arc-game-grid">
                <?php if (empty($games)): ?>
                    <p class="mfsd-arcade-msg">No games available yet. Check back soon!</p>
                <?php else: ?>
                    <?php foreach ($games as $g): ?>
                    <div class="arc-game-card" data-game-id="<?php echo (int) $g['id']; ?>" data-slug="<?php echo esc_attr($g['slug']); ?>">
                        <div class="arc-game-thumb">
                            <?php if ($g['thumbnail_url']): ?>
                                <img src="<?php echo esc_url($g['thumbnail_url']); ?>" alt="<?php echo esc_attr($g['title']); ?>">
                            <?php else: ?>
                                <div class="arc-game-thumb-placeholder">🎮</div>
                            <?php endif; ?>
                            <span class="arc-game-category"><?php echo esc_html(ucfirst($g['category'])); ?></span>
                        </div>
                        <div class="arc-game-info">
                            <h3 class="arc-game-title"><?php echo esc_html($g['title']); ?></h3>
                            <?php if ($g['description']): ?>
                                <p class="arc-game-desc"><?php echo esc_html($g['description']); ?></p>
                            <?php endif; ?>
                            <div class="arc-game-cost">
                                <span>Min <?php echo (int) $g['min_coins']; ?> coin<?php echo $g['min_coins'] != 1 ? 's' : ''; ?></span>
                                <span class="arc-game-time">(<?php echo esc_html(self::coins_to_minutes_label($g['min_coins'], $mpc)); ?>)</span>
                            </div>
                        </div>
                        <a href="?game=<?php echo esc_attr($g['slug']); ?>" class="arc-btn arc-btn-play">Play</a>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ================================================================
       RENDER — Play screen (buy time + game iframe)
       ================================================================ */
    private function render_play($game, $balance, $mpc, $live, $controls = array()) {
        ob_start();
        ?>
        <div id="mfsd-arcade-root" class="mfsd-arcade" data-mode="play" data-game-id="<?php echo (int) $game['id']; ?>">

            <!-- Header bar -->
            <div class="arc-header">
                <a href="javascript:history.back()" class="arc-back">← Arcade</a>
                <h2 class="arc-title"><?php echo esc_html($game['title']); ?></h2>
                <div class="arc-wallet">
                    <span class="arc-coin-icon">🪙</span>
                    <span class="arc-balance" id="arc-balance"><?php echo (int) $balance; ?></span>
                </div>
            </div>

            <!-- Controls bar (from manifest) -->
            <?php if (!empty($controls)): ?>
            <div class="arc-controls-bar" id="arc-controls-bar">
                <span class="arc-controls-label">🎮 Controls:</span>
                <div class="arc-controls-list">
                    <?php foreach ($controls as $c): ?>
                    <span class="arc-control-item">
                        <kbd class="arc-key"><?php echo esc_html($c['key']); ?></kbd>
                        <span class="arc-action"><?php echo esc_html($c['action']); ?></span>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Purchase panel (shown when no active session) -->
            <div class="arc-purchase-panel" id="arc-purchase-panel" style="<?php echo $live ? 'display:none;' : ''; ?>">
                <div class="arc-purchase-inner">
                    <h3>Insert coins to play</h3>
                    <p class="arc-rate-info">1 coin = <?php echo esc_html($mpc); ?> minute<?php echo $mpc != 1 ? 's' : ''; ?> of play time</p>

                    <div class="arc-coin-slider-wrap">
                        <label for="arc-coin-input">Coins to spend:</label>
                        <input type="range" id="arc-coin-slider" min="<?php echo (int) $game['min_coins']; ?>" max="<?php echo max((int) $game['min_coins'], (int) $balance); ?>" value="<?php echo (int) $game['min_coins']; ?>" <?php echo $balance < $game['min_coins'] ? 'disabled' : ''; ?>>
                        <div class="arc-coin-readout">
                            <span class="arc-coin-amount" id="arc-coin-amount"><?php echo (int) $game['min_coins']; ?></span>
                            <span class="arc-coin-label">coins</span>
                            <span class="arc-time-equals">=</span>
                            <span class="arc-time-amount" id="arc-time-amount"><?php echo esc_html(self::coins_to_minutes_label($game['min_coins'], $mpc)); ?></span>
                        </div>
                    </div>

                    <?php if ($balance < $game['min_coins']): ?>
                        <div class="arc-insufficient">
                            <p>You need at least <?php echo (int) $game['min_coins']; ?> coin<?php echo $game['min_coins'] != 1 ? 's' : ''; ?> to play. Complete activities to earn more!</p>
                        </div>
                    <?php else: ?>
                        <button type="button" id="arc-purchase-btn" class="arc-btn arc-btn-purchase">
                            🪙 Insert coins &amp; play
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Game container (shown when session is active) -->
            <div class="arc-game-container" id="arc-game-container" style="<?php echo $live ? '' : 'display:none;'; ?>">

                <!-- Timer bar -->
                <div class="arc-timer-bar" id="arc-timer-bar">
                    <div class="arc-timer-fill" id="arc-timer-fill"></div>
                    <div class="arc-timer-text">
                        <span id="arc-timer-display">--:--</span>
                        <button type="button" id="arc-pause-btn" class="arc-btn arc-btn-sm arc-btn-pause" title="Pause">⏸ Pause</button>
                    </div>
                </div>

                <!-- Game iframe — always starts blank; JS loads the gated URL after purchase/resume -->
                <div class="arc-iframe-wrap" id="arc-iframe-wrap">
                    <iframe id="arc-game-iframe"
                        src="about:blank"
                        class="arc-iframe"
                        sandbox="allow-scripts allow-same-origin allow-popups"
                        allowfullscreen
                    ></iframe>
                </div>
            </div>

            <!-- Paused overlay -->
            <div class="arc-overlay arc-overlay-paused" id="arc-overlay-paused" style="display:none;">
                <div class="arc-overlay-inner">
                    <h3>⏸ Game paused</h3>
                    <p>Your remaining time is saved.</p>
                    <p class="arc-time-remaining" id="arc-paused-time">--:--</p>
                    <button type="button" id="arc-resume-btn" class="arc-btn arc-btn-purchase">▶ Resume</button>
                </div>
            </div>

            <!-- Time's up overlay -->
            <div class="arc-overlay arc-overlay-expired" id="arc-overlay-expired" style="display:none;">
                <div class="arc-overlay-inner">
                    <h3>⏰ Time's up!</h3>
                    <p>Your session has ended.</p>
                    <div class="arc-expired-balance">
                        <span class="arc-coin-icon">🪙</span>
                        <span id="arc-expired-balance">0</span> coins remaining
                    </div>
                    <div class="arc-expired-actions">
                        <button type="button" id="arc-play-again-btn" class="arc-btn arc-btn-purchase">Play again</button>
                        <a href="javascript:history.back()" class="arc-btn arc-btn-sm">Back to Arcade</a>
                    </div>
                    <p class="arc-earn-more">Complete your activities to earn more coins!</p>
                </div>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }

    /* ================================================================
       REST API — delegate to API class
       ================================================================ */
    public function register_routes() {
        $api = new MFSD_Arcade_API();
        $api->register_routes();

        $loader = new MFSD_Arcade_Game_Loader();
        $loader->register_routes();
    }

    /* ================================================================
       ADMIN
       ================================================================ */
    public function admin_menu() {
        add_menu_page(
            'Arcade Admin',
            'Arcade',
            'manage_options',
            'mfsd-arcade',
            array($this, 'admin_page'),
            'dashicons-games',
            32
        );
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        include __DIR__ . '/admin/admin-page.php';
    }

    /* ================================================================
       HELPERS
       ================================================================ */
    public static function coins_to_minutes_label($coins, $mpc = null) {
        if ($mpc === null) $mpc = self::minutes_per_coin();
        $mins = round($coins * $mpc, 1);
        if ($mins < 1) return round($mins * 60) . ' sec';
        return $mins . ' min' . ($mins != 1 ? 's' : '');
    }
}

MFSD_Arcade::instance();