/**
 * MFSD WipEout Integration
 * Hooks into the Emscripten WipEout rewrite to trigger the MFSD leaderboard.
 *
 * Score = finish position converted to points (1st=8, 2nd=7 … 8th=1).
 * Race detection: intercepts Module.print for race-result log lines.
 * Falls back to score=1 (participated) if position can't be determined.
 *
 * Controls: Arrow keys steer, X=thrust, Z=fire, C/V=brakes, A=view.
 */
(function () {
    'use strict';

    var leaderboardActive = false;
    var raceFinishPosition = null; /* 1-based finish position, null if not yet known */
    var racesCompleted = 0;

    /* ================================================================
       INTERCEPT Module.print — detect race result lines
       The Emscripten Module.print hook in mfsd-template.html calls
       window._mfsdWipeoutPrint() for every C printf() call.
       ================================================================ */
    window._mfsdWipeoutPrint = function (text) {
        /* WipEout logs race results — look for position/rank data.
           Common patterns from the source: position_rank, race complete, etc. */
        var lower = text.toLowerCase();

        /* Try to extract finish position from log output */
        var posMatch = text.match(/position[:\s]+(\d+)/i) ||
                       text.match(/rank[:\s]+(\d+)/i) ||
                       text.match(/finished?\s+(\d+)/i) ||
                       text.match(/place[:\s]+(\d+)/i);
        if (posMatch) {
            raceFinishPosition = parseInt(posMatch[1], 10);
        }

        if (lower.indexOf('race') !== -1 && (lower.indexOf('finish') !== -1 || lower.indexOf('complete') !== -1)) {
            racesCompleted++;
            onRaceComplete();
        }
    };

    /* ================================================================
       RACE COMPLETE — offer leaderboard after each race
       ================================================================ */
    function onRaceComplete() {
        if (leaderboardActive) return;

        var score = positionToScore(raceFinishPosition);

        setTimeout(function () {
            if (typeof MFSDLeaderboard !== 'undefined' && MFSDLeaderboard.isConnected()) {
                leaderboardActive = true;
                MFSDLeaderboard.onGameOver(score);
            }
            /* Reset for next race */
            raceFinishPosition = null;
        }, 2000);
    }

    /* 1st=8pts, 2nd=7pts … 8th=1pt; unknown=1pt */
    function positionToScore(pos) {
        if (!pos || pos < 1 || pos > 8) return 1;
        return Math.max(1, 9 - pos);
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
            onLeaderboardClosed();
        }
    });

    function onTimeExpired() {
        if (leaderboardActive) return;

        var score = positionToScore(raceFinishPosition) + (racesCompleted > 0 ? (racesCompleted - 1) * 4 : 0);
        score = Math.max(1, score);

        if (racesCompleted > 0 && typeof MFSDLeaderboard !== 'undefined' && MFSDLeaderboard.isConnected()) {
            leaderboardActive = true;
            MFSDLeaderboard.onGameOver(score);
        } else {
            /* Nothing worth submitting — let arcade proceed */
            window.parent.postMessage({ type: 'mfsd-leaderboard-closed' }, '*');
        }
    }

    /* Poll for overlay close (same-window) */
    setInterval(function () {
        if (!leaderboardActive) return;
        var overlay = document.getElementById('mfsd-lb-overlay');
        if (overlay && overlay.style.display === 'none') {
            onLeaderboardClosed();
        }
    }, 300);

    function onLeaderboardClosed() {
        if (!leaderboardActive) return;
        leaderboardActive = false;
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
