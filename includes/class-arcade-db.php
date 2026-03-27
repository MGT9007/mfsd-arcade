<?php
/**
 * MFSD Arcade — Database Layer
 * Games catalogue + sessions table creation and queries.
 */

if (!defined('ABSPATH')) exit;

class MFSD_Arcade_DB {

    const TBL_GAMES    = 'mfsd_arcade_games';
    const TBL_SESSIONS = 'mfsd_arcade_sessions';
    const TBL_SCORES   = 'mfsd_arcade_scores';

    /* ================================================================
       CREATE TABLES
       ================================================================ */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $games = $wpdb->prefix . self::TBL_GAMES;
        dbDelta("CREATE TABLE $games (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT,
            title VARCHAR(100) NOT NULL,
            slug VARCHAR(50) NOT NULL,
            description VARCHAR(255) NULL,
            category ENUM('retro','puzzle','platformer','action') DEFAULT 'retro',
            iframe_url VARCHAR(500) NOT NULL,
            thumbnail_url VARCHAR(500) NULL,
            min_coins INT UNSIGNED DEFAULT 1,
            active TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_slug (slug),
            KEY idx_active (active, sort_order)
        ) ENGINE=InnoDB $charset;");

        $sessions = $wpdb->prefix . self::TBL_SESSIONS;
        dbDelta("CREATE TABLE $sessions (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT,
            student_id BIGINT(20) UNSIGNED NOT NULL,
            game_id BIGINT(20) UNSIGNED NOT NULL,
            session_token VARCHAR(64) NOT NULL,
            coins_spent INT UNSIGNED NOT NULL DEFAULT 0,
            minutes_purchased DECIMAL(6,1) NOT NULL DEFAULT 0,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            paused_at DATETIME NULL,
            remaining_seconds INT UNSIGNED NULL,
            status ENUM('active','paused','expired','completed') DEFAULT 'active',
            last_heartbeat DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_token (session_token),
            KEY idx_student_game (student_id, game_id, status),
            KEY idx_student_active (student_id, status),
            KEY idx_stale (status, last_heartbeat)
        ) ENGINE=InnoDB $charset;");

        /* ── Leaderboard scores ── */
        $scores = $wpdb->prefix . self::TBL_SCORES;
        dbDelta("CREATE TABLE $scores (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT,
            student_id BIGINT(20) UNSIGNED NOT NULL,
            game_slug VARCHAR(50) NOT NULL,
            initials VARCHAR(5) NOT NULL,
            score INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_game_score (game_slug, score DESC),
            KEY idx_student_game (student_id, game_slug),
            KEY idx_game_top (game_slug, score DESC, created_at ASC)
        ) ENGINE=InnoDB $charset;");
    }

    /* ================================================================
       GAME QUERIES
       ================================================================ */

    public function get_active_games() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}" . self::TBL_GAMES . " WHERE active = 1 ORDER BY sort_order ASC, title ASC",
            ARRAY_A
        );
    }

    public function get_all_games() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}" . self::TBL_GAMES . " ORDER BY sort_order ASC, title ASC",
            ARRAY_A
        );
    }

    public function get_game($game_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::TBL_GAMES . " WHERE id = %d", $game_id
        ), ARRAY_A);
    }

    public function get_game_by_slug($slug) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::TBL_GAMES . " WHERE slug = %s", $slug
        ), ARRAY_A);
    }

    public function insert_game($data) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . self::TBL_GAMES, $data);
        return $wpdb->insert_id;
    }

    public function update_game($game_id, $data) {
        global $wpdb;
        return $wpdb->update($wpdb->prefix . self::TBL_GAMES, $data, array('id' => $game_id));
    }

    public function delete_game($game_id) {
        global $wpdb;
        return $wpdb->delete($wpdb->prefix . self::TBL_GAMES, array('id' => $game_id));
    }

    /* ================================================================
       SESSION QUERIES
       ================================================================ */

    /** Find an active or paused session for a student + specific game. */
    public function get_live_session($student_id, $game_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::TBL_SESSIONS . "
             WHERE student_id = %d AND game_id = %d AND status IN ('active','paused')
             ORDER BY started_at DESC LIMIT 1",
            $student_id, $game_id
        ), ARRAY_A);
    }

    /** Find ANY active or paused session for a student (across all games). */
    public function get_any_live_session($student_id) {
        global $wpdb;
        $s = $wpdb->prefix . self::TBL_SESSIONS;
        $g = $wpdb->prefix . self::TBL_GAMES;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, g.title AS game_title, g.slug AS game_slug, g.iframe_url
             FROM $s s JOIN $g g ON g.id = s.game_id
             WHERE s.student_id = %d AND s.status IN ('active','paused')
             ORDER BY s.started_at DESC LIMIT 1",
            $student_id
        ), ARRAY_A);
    }

    /** Get session by token. */
    public function get_session_by_token($token) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::TBL_SESSIONS . " WHERE session_token = %s", $token
        ), ARRAY_A);
    }

    /** Create a new session. Returns insert ID. */
    public function create_session($data) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . self::TBL_SESSIONS, $data);
        return $wpdb->insert_id;
    }

    /** Update session fields by ID. */
    public function update_session($session_id, $data) {
        global $wpdb;
        return $wpdb->update($wpdb->prefix . self::TBL_SESSIONS, $data, array('id' => $session_id));
    }

    /** Expire active sessions past their expires_at. */
    public function expire_overdue_sessions() {
        global $wpdb;
        return $wpdb->query(
            "UPDATE {$wpdb->prefix}" . self::TBL_SESSIONS . "
             SET status = 'expired' WHERE status = 'active' AND expires_at <= NOW()"
        );
    }

    /** Auto-pause stale sessions — back-date remaining to last_heartbeat for fairness. */
    public function auto_pause_stale($threshold = 60) {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}" . self::TBL_SESSIONS . " SET
                status = 'paused',
                paused_at = last_heartbeat,
                remaining_seconds = GREATEST(0, TIMESTAMPDIFF(SECOND, last_heartbeat, expires_at))
             WHERE status = 'active'
               AND last_heartbeat < DATE_SUB(NOW(), INTERVAL %d SECOND)",
            $threshold
        ));
    }

    /** Admin stats. */
    public function get_session_stats() {
        global $wpdb;
        return $wpdb->get_row(
            "SELECT
                COUNT(*) AS total_sessions,
                SUM(status = 'active') AS active_now,
                SUM(status = 'paused') AS paused_now,
                SUM(coins_spent) AS total_coins_spent,
                SUM(minutes_purchased) AS total_minutes_purchased
             FROM {$wpdb->prefix}" . self::TBL_SESSIONS,
            ARRAY_A
        );
    }

    /** Recent sessions for admin. */
    public function get_recent_sessions($limit = 25) {
        global $wpdb;
        $s = $wpdb->prefix . self::TBL_SESSIONS;
        $g = $wpdb->prefix . self::TBL_GAMES;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, g.title AS game_title, u.display_name AS student_name
             FROM $s s
             LEFT JOIN $g g ON g.id = s.game_id
             LEFT JOIN {$wpdb->users} u ON u.ID = s.student_id
             ORDER BY s.started_at DESC LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    /* ================================================================
       LEADERBOARD SCORE QUERIES
       ================================================================ */

    /** Submit a score. Returns insert ID. */
    public function insert_score($student_id, $game_slug, $initials, $score) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . self::TBL_SCORES, array(
            'student_id' => $student_id,
            'game_slug'  => $game_slug,
            'initials'   => strtoupper(substr($initials, 0, 5)),
            'score'      => (int) $score,
        ));
        return $wpdb->insert_id;
    }

    /** Get top scores for a game (global leaderboard). */
    public function get_top_scores($game_slug, $limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . self::TBL_SCORES;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT initials, score, student_id, created_at
             FROM $table
             WHERE game_slug = %s
             ORDER BY score DESC, created_at ASC
             LIMIT %d",
            $game_slug, $limit
        ), ARRAY_A);
    }

    /** Get a student's personal best for a game. */
    public function get_personal_best($student_id, $game_slug) {
        global $wpdb;
        $table = $wpdb->prefix . self::TBL_SCORES;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT initials, score, created_at
             FROM $table
             WHERE student_id = %d AND game_slug = %s
             ORDER BY score DESC LIMIT 1",
            $student_id, $game_slug
        ), ARRAY_A);
    }

    /** Get a student's rank on a game leaderboard. */
    public function get_rank($game_slug, $score) {
        global $wpdb;
        $table = $wpdb->prefix . self::TBL_SCORES;
        $rank = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT student_id) + 1
             FROM $table
             WHERE game_slug = %s AND score > %d",
            $game_slug, $score
        ));
        return (int) $rank;
    }

    /** Get total unique players for a game. */
    public function get_player_count($game_slug) {
        global $wpdb;
        $table = $wpdb->prefix . self::TBL_SCORES;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT student_id) FROM $table WHERE game_slug = %s",
            $game_slug
        ));
    }

    /** Delete all scores for a game (admin). */
    public function delete_game_scores($game_slug) {
        global $wpdb;
        return $wpdb->delete($wpdb->prefix . self::TBL_SCORES, array('game_slug' => $game_slug));
    }
}
