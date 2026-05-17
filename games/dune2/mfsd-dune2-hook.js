/**
 * MFSD Dune II Integration
 * Detects mission win/lose by intercepting Module.print output from
 * the Emscripten-compiled OpenDUNE engine.
 *
 * Score: missions won × 10, reduced by losses. Min 1 (participated).
 */
(function () {
    'use strict';

    var leaderboardActive = false;
    var missionsWon       = 0;
    var missionsLost      = 0;

    /* ================================================================
       INTERCEPT Module.print — detect mission outcomes
       OpenDUNE logs mission results via printf; we scan for keywords.
       ================================================================ */
    window._mfsdDune2Print = function (text) {
        var lower = text.toLowerCase();

        if (lower.indexOf('you are victorious') !== -1 ||
            lower.indexOf('mission complete') !== -1 ||
            lower.indexOf('mission accomplished') !== -1 ||
            lower.indexOf('scenario_won') !== -1) {
            missionsWon++;
            setTimeout(onMissionEnd, 3000);
        }

        if (lower.indexOf('you have failed') !== -1 ||
            lower.indexOf('mission failed') !== -1 ||
            lower.indexOf('scenario_lose') !== -1 ||
            lower.indexOf('scenario_lost') !== -1) {
            missionsLost++;
            setTimeout(onMissionEnd, 3000);
        }
    };

    function onMissionEnd() {
        if (leaderboardActive) return;
        var score = calculateScore();
        if (typeof MFSDLeaderboard !== 'undefined' && MFSDLeaderboard.isConnected()) {
            leaderboardActive = true;
            MFSDLeaderboard.onGameOver(score);
        }
    }

    function calculateScore() {
        if (missionsWon === 0 && missionsLost === 0) return 1;
        var score = (missionsWon * 10) - (missionsLost * 3);
        return Math.max(1, score);
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
        }
    });

    function onTimeExpired() {
        if (leaderboardActive) return;
        var score = calculateScore();
        if (typeof MFSDLeaderboard !== 'undefined' && MFSDLeaderboard.isConnected()) {
            leaderboardActive = true;
            MFSDLeaderboard.onGameOver(score);
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

})();
