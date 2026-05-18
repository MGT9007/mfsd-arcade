/**
 * MFSD Pac-Man Integration
 * Detects game over by polling the game's state machine.
 * Score is read from the game's getScore() global.
 * pacman.js exposes: state, overState, getScore() as window-level globals.
 */
(function () {
    'use strict';

    var leaderboardActive = false;
    var pollTimer = null;

    function getGameScore() {
        try {
            if (typeof getScore === 'function') return getScore() || 0;
        } catch (e) {}
        return 0;
    }

    function onGameOver() {
        if (leaderboardActive) return;
        var score = getGameScore();
        if (typeof MFSDLeaderboard !== 'undefined' && MFSDLeaderboard.isConnected()) {
            leaderboardActive = true;
            MFSDLeaderboard.onGameOver(Math.max(1, score));
        }
    }

    /* Poll state machine — fires when state === overState */
    function startPolling() {
        pollTimer = setInterval(function () {
            if (leaderboardActive) return;
            try {
                if (typeof state !== 'undefined' &&
                    typeof overState !== 'undefined' &&
                    state === overState) {
                    onGameOver();
                }
            } catch (e) {}
        }, 500);
    }

    /* Wait for pacman.js to finish initialising before polling */
    if (document.readyState === 'complete') {
        startPolling();
    } else {
        window.addEventListener('load', startPolling);
    }

    /* ================================================================
       LISTEN FOR ARCADE MESSAGES
       ================================================================ */
    window.addEventListener('message', function (e) {
        if (!e.data) return;
        if (e.data.type === 'mfsd-time-expired') {
            onTimeExpired();
        }
        if (e.data.type === 'mfsd-leaderboard-closed') {
            leaderboardActive = false;
            if (pollTimer) clearInterval(pollTimer);
        }
    });

    function onTimeExpired() {
        if (leaderboardActive) return;
        var score = getGameScore();
        if (typeof MFSDLeaderboard !== 'undefined' && MFSDLeaderboard.isConnected()) {
            leaderboardActive = true;
            MFSDLeaderboard.onGameOver(Math.max(1, score));
        } else {
            window.parent.postMessage({ type: 'mfsd-leaderboard-closed' }, '*');
        }
    }

    /* ================================================================
       KEYBOARD: L to view leaderboard from menus
       ================================================================ */
    document.addEventListener('keydown', function (e) {
        if ((e.key === 'l' || e.key === 'L') && !leaderboardActive) {
            if (typeof MFSDLeaderboard !== 'undefined' && MFSDLeaderboard.isConnected()) {
                MFSDLeaderboard.showLeaderboard();
                leaderboardActive = true;
            }
        }
    });

}());
