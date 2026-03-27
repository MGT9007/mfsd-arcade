<?php
/**
 * MFSD Arcade — REST API
 * Endpoints for session purchase, heartbeat, pause/resume.
 */

if (!defined('ABSPATH')) exit;

class MFSD_Arcade_API {

    public function register_routes() {
        $ns = 'mfsd-arcade/v1';

        /* Session status check */
        register_rest_route($ns, '/session', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_session'),
            'permission_callback' => array($this, 'is_student'),
            'args' => array(
                'game_id' => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
            ),
        ));

        /* Purchase — create session */
        register_rest_route($ns, '/purchase', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'purchase'),
            'permission_callback' => array($this, 'is_student'),
            'args' => array(
                'game_id' => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
                'coins'   => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
            ),
        ));

        /* Heartbeat */
        register_rest_route($ns, '/heartbeat', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'heartbeat'),
            'permission_callback' => array($this, 'is_student'),
            'args' => array(
                'token' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            ),
        ));

        /* Pause */
        register_rest_route($ns, '/pause', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'pause'),
            'permission_callback' => array($this, 'is_student'),
            'args' => array(
                'token' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            ),
        ));

        /* Resume */
        register_rest_route($ns, '/resume', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'resume'),
            'permission_callback' => array($this, 'is_student'),
            'args' => array(
                'token' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            ),
        ));

        /* Wallet balance (quick check) */
        register_rest_route($ns, '/balance', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_balance'),
            'permission_callback' => array($this, 'is_student'),
        ));

        /* ── Leaderboard ── */

        /* Get top scores for a game */
        register_rest_route($ns, '/scores', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_scores'),
            'permission_callback' => array($this, 'is_student'),
            'args' => array(
                'game'  => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key'),
                'limit' => array('required' => false, 'type' => 'integer', 'default' => 10, 'sanitize_callback' => 'absint'),
            ),
        ));

        /* Submit a score */
        register_rest_route($ns, '/scores', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'submit_score'),
            'permission_callback' => array($this, 'is_student'),
            'args' => array(
                'game'     => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key'),
                'initials' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
                'score'    => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
            ),
        ));
    }

    /* ── Permission: logged-in student or admin ── */
    public function is_student() {
        if (!is_user_logged_in()) return false;
        $user = wp_get_current_user();
        return in_array('student', (array) $user->roles) || in_array('administrator', (array) $user->roles);
    }

    /* ================================================================
       ENDPOINTS
       ================================================================ */

    public function get_session($req) {
        $mgr = new MFSD_Arcade_Session();
        $result = $mgr->get_status(get_current_user_id(), $req->get_param('game_id'));
        return rest_ensure_response(array('ok' => true, 'session' => $result));
    }

    public function purchase($req) {
        $mgr = new MFSD_Arcade_Session();
        $result = $mgr->purchase(
            get_current_user_id(),
            $req->get_param('game_id'),
            $req->get_param('coins')
        );

        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(array('ok' => true, 'session' => $result));
    }

    public function heartbeat($req) {
        $mgr = new MFSD_Arcade_Session();
        $result = $mgr->heartbeat($req->get_param('token'));

        if (is_wp_error($result)) return $result;
        return rest_ensure_response(array('ok' => true, 'session' => $result));
    }

    public function pause($req) {
        $mgr = new MFSD_Arcade_Session();
        $result = $mgr->pause($req->get_param('token'));

        if (is_wp_error($result)) return $result;
        return rest_ensure_response(array('ok' => true, 'session' => $result));
    }

    public function resume($req) {
        $mgr = new MFSD_Arcade_Session();
        $result = $mgr->resume($req->get_param('token'));

        if (is_wp_error($result)) return $result;
        return rest_ensure_response(array('ok' => true, 'session' => $result));
    }

    public function get_balance($req) {
        $balance = 0;
        if (class_exists('MFSD_Quest_Log_Wallet')) {
            $wallet  = new MFSD_Quest_Log_Wallet();
            $balance = $wallet->get_balance(get_current_user_id());
        }
        return rest_ensure_response(array('ok' => true, 'balance' => $balance));
    }

    /* ================================================================
       LEADERBOARD ENDPOINTS
       ================================================================ */

    public function get_scores($req) {
        $db    = new MFSD_Arcade_DB();
        $game  = $req->get_param('game');
        $limit = min(50, max(1, $req->get_param('limit') ?: 10));

        $scores       = $db->get_top_scores($game, $limit);
        $player_count = $db->get_player_count($game);

        /* Include current student's personal best + rank */
        $student_id    = get_current_user_id();
        $personal_best = $db->get_personal_best($student_id, $game);
        $my_rank       = $personal_best ? $db->get_rank($game, (int) $personal_best['score']) : null;

        return rest_ensure_response(array(
            'ok'           => true,
            'scores'       => $scores,
            'player_count' => $player_count,
            'personal_best'=> $personal_best,
            'my_rank'      => $my_rank,
        ));
    }

    public function submit_score($req) {
        $student_id = get_current_user_id();
        $game       = $req->get_param('game');
        $initials   = strtoupper(substr(trim($req->get_param('initials')), 0, 5));
        $score      = (int) $req->get_param('score');

        /* Validate initials: 1-5 alphanumeric characters */
        if (!preg_match('/^[A-Z0-9]{1,5}$/', $initials)) {
            return new WP_Error('bad_initials', 'Initials must be 1-5 letters/numbers.', array('status' => 400));
        }

        if ($score < 0) {
            return new WP_Error('bad_score', 'Score must be positive.', array('status' => 400));
        }

        $db = new MFSD_Arcade_DB();

        /* Insert the score */
        $score_id = $db->insert_score($student_id, $game, $initials, $score);
        if (!$score_id) {
            return new WP_Error('insert_failed', 'Could not save score.', array('status' => 500));
        }

        /* Return updated leaderboard + rank */
        $scores       = $db->get_top_scores($game, 10);
        $rank         = $db->get_rank($game, $score);
        $player_count = $db->get_player_count($game);
        $personal_best= $db->get_personal_best($student_id, $game);

        return rest_ensure_response(array(
            'ok'            => true,
            'rank'          => $rank,
            'player_count'  => $player_count,
            'scores'        => $scores,
            'personal_best' => $personal_best,
            'is_new_best'   => $personal_best && (int) $personal_best['score'] === $score,
        ));
    }
}
