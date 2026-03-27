<?php
/**
 * MFSD Arcade — Session Manager
 * Handles purchase, heartbeat, pause/resume, and stale-session cleanup.
 * Interfaces with MFSD_Quest_Log_Wallet for coin transactions.
 */

if (!defined('ABSPATH')) exit;

class MFSD_Arcade_Session {

    private $db;

    public function __construct() {
        $this->db = new MFSD_Arcade_DB();
    }

    /* ================================================================
       WALLET BRIDGE
       ================================================================ */
    private function get_wallet() {
        if (!class_exists('MFSD_Quest_Log_Wallet')) return null;
        return new MFSD_Quest_Log_Wallet();
    }

    /* ================================================================
       CLEANUP — expire overdue + auto-pause stale sessions
       Run on every page load / API call as a lightweight maintenance pass.
       ================================================================ */
    public function cleanup() {
        $this->db->expire_overdue_sessions();
        $this->db->auto_pause_stale(60); /* 60s with no heartbeat */
    }

    /* ================================================================
       PURCHASE — deduct coins, create a new session
       Returns: array with session data or WP_Error
       ================================================================ */
    public function purchase($student_id, $game_id, $coins) {
        $this->cleanup();

        /* Validate game */
        $game = $this->db->get_game($game_id);
        if (!$game || !$game['active']) {
            return new WP_Error('invalid_game', 'Game not found or inactive.', array('status' => 404));
        }

        /* Enforce minimum coins */
        $coins = (int) $coins;
        if ($coins < (int) $game['min_coins']) {
            return new WP_Error('min_coins', 'Minimum ' . $game['min_coins'] . ' coin(s) required.', array('status' => 400));
        }

        /* Check no existing live session for this student */
        $existing = $this->db->get_any_live_session($student_id);
        if ($existing) {
            return new WP_Error('session_exists', 'You already have an active session. Finish or pause it first.', array('status' => 409));
        }

        /* Deduct coins via wallet */
        $wallet = $this->get_wallet();
        if (!$wallet) {
            return new WP_Error('no_wallet', 'Wallet system not available.', array('status' => 500));
        }

        $balance = $wallet->get_balance($student_id);
        if ($coins > $balance) {
            return new WP_Error('insufficient_coins', 'Not enough coins. You have ' . $balance . '.', array('status' => 400));
        }

        $mpc     = MFSD_Arcade::minutes_per_coin();
        $minutes = round($coins * $mpc, 1);
        $seconds = (int) round($minutes * 60);

        /* Spend coins — source identifies the game for the wallet ledger */
        $spent = $wallet->spend($student_id, 'arcade_' . $game['slug'], $coins,
            'Arcade: ' . $game['title'] . ' — ' . $minutes . ' min');
        if (!$spent) {
            return new WP_Error('spend_failed', 'Could not deduct coins.', array('status' => 500));
        }

        /* Create session */
        $token = wp_generate_password(48, false, false);
        $now   = current_time('mysql');

        $session_id = $this->db->create_session(array(
            'student_id'       => $student_id,
            'game_id'          => $game_id,
            'session_token'    => $token,
            'coins_spent'      => $coins,
            'minutes_purchased'=> $minutes,
            'started_at'       => $now,
            'expires_at'       => gmdate('Y-m-d H:i:s', strtotime($now) + $seconds),
            'status'           => 'active',
            'last_heartbeat'   => $now,
        ));

        if (!$session_id) {
            /* Refund on failure */
            $wallet->refund($student_id, 'arcade_' . $game['slug'], $coins, 'Arcade session creation failed — refund');
            return new WP_Error('session_create_failed', 'Could not create session.', array('status' => 500));
        }

        return array(
            'session_token'    => $token,
            'expires_at'       => gmdate('Y-m-d H:i:s', strtotime($now) + $seconds),
            'remaining_seconds'=> $seconds,
            'minutes_purchased'=> $minutes,
            'coins_spent'      => $coins,
            'iframe_url'       => self::resolve_game_url($game, $token),
            'new_balance'      => $wallet->get_balance($student_id),
        );
    }

    /* ================================================================
       HEARTBEAT — client pings every 30s
       Returns remaining seconds or expired status.
       ================================================================ */
    public function heartbeat($token) {
        $this->cleanup();

        $session = $this->db->get_session_by_token($token);
        if (!$session) {
            return new WP_Error('invalid_token', 'Session not found.', array('status' => 404));
        }

        /* If already expired */
        if ($session['status'] === 'expired' || $session['status'] === 'completed') {
            return array('status' => 'expired', 'remaining_seconds' => 0);
        }

        /* If paused, return paused state */
        if ($session['status'] === 'paused') {
            return array(
                'status'            => 'paused',
                'remaining_seconds' => (int) $session['remaining_seconds'],
            );
        }

        /* Active — update heartbeat, check time */
        $now       = current_time('mysql');
        $remaining = max(0, strtotime($session['expires_at']) - strtotime($now));

        $this->db->update_session($session['id'], array(
            'last_heartbeat' => $now,
        ));

        if ($remaining <= 0) {
            $this->db->update_session($session['id'], array('status' => 'expired'));
            return array('status' => 'expired', 'remaining_seconds' => 0);
        }

        return array(
            'status'            => 'active',
            'remaining_seconds' => (int) $remaining,
        );
    }

    /* ================================================================
       PAUSE — freeze the timer, save remaining seconds
       ================================================================ */
    public function pause($token) {
        $session = $this->db->get_session_by_token($token);
        if (!$session) {
            return new WP_Error('invalid_token', 'Session not found.', array('status' => 404));
        }
        if ($session['status'] !== 'active') {
            return array(
                'status'            => $session['status'],
                'remaining_seconds' => (int) ($session['remaining_seconds'] ?? 0),
            );
        }

        $now       = current_time('mysql');
        $remaining = max(0, strtotime($session['expires_at']) - strtotime($now));

        $this->db->update_session($session['id'], array(
            'status'            => 'paused',
            'paused_at'         => $now,
            'remaining_seconds' => $remaining,
            'last_heartbeat'    => $now,
        ));

        return array(
            'status'            => 'paused',
            'remaining_seconds' => (int) $remaining,
        );
    }

    /* ================================================================
       RESUME — unfreeze, recalculate expires_at from remaining
       ================================================================ */
    public function resume($token) {
        $session = $this->db->get_session_by_token($token);
        if (!$session) {
            return new WP_Error('invalid_token', 'Session not found.', array('status' => 404));
        }
        if ($session['status'] !== 'paused') {
            return new WP_Error('not_paused', 'Session is not paused.', array('status' => 400));
        }

        $remaining = (int) $session['remaining_seconds'];
        if ($remaining <= 0) {
            $this->db->update_session($session['id'], array('status' => 'expired'));
            return array('status' => 'expired', 'remaining_seconds' => 0);
        }

        $now        = current_time('mysql');
        $expires_at = gmdate('Y-m-d H:i:s', strtotime($now) + $remaining);

        $this->db->update_session($session['id'], array(
            'status'            => 'active',
            'paused_at'         => null,
            'remaining_seconds' => null,
            'expires_at'        => $expires_at,
            'last_heartbeat'    => $now,
        ));

        return array(
            'status'            => 'active',
            'remaining_seconds' => $remaining,
            'expires_at'        => $expires_at,
        );
    }

    /* ================================================================
       GET SESSION STATUS — for initial page load checks
       ================================================================ */
    public function get_status($student_id, $game_id) {
        $this->cleanup();

        $session = $this->db->get_live_session($student_id, $game_id);
        if (!$session) {
            return array('status' => 'none');
        }

        $remaining = 0;
        if ($session['status'] === 'active') {
            $remaining = max(0, strtotime($session['expires_at']) - strtotime(current_time('mysql')));
            if ($remaining <= 0) {
                $this->db->update_session($session['id'], array('status' => 'expired'));
                return array('status' => 'expired', 'remaining_seconds' => 0);
            }
        } elseif ($session['status'] === 'paused') {
            $remaining = (int) $session['remaining_seconds'];
        }

        return array(
            'status'            => $session['status'],
            'session_token'     => $session['session_token'],
            'remaining_seconds' => $remaining,
            'coins_spent'       => (int) $session['coins_spent'],
            'minutes_purchased' => (float) $session['minutes_purchased'],
        );
    }

    /* ================================================================
       RESOLVE GAME URL — gated endpoint for internal games,
       raw iframe_url for external games.
       ================================================================ */
    public static function resolve_game_url($game, $token) {
        /* If iframe_url is empty, use the internal game loader */
        if (empty($game['iframe_url'])) {
            return MFSD_Arcade_Game_Loader::get_game_url($game['slug'], $token);
        }

        /* External game — use the configured URL as-is */
        return $game['iframe_url'];
    }
}
