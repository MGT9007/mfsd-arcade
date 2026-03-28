/**
 * MFSD Infinite Mario Integration
 * Hooks into robertkleffner/marioHTML5 to trigger the MFSD leaderboard.
 *
 * Score tracking: counts coins collected via Mario.Character.prototype.GetCoin.
 * Game over detection: monkey-patches Mario.LoseState.prototype.Enter.
 *
 * Controls reference:
 *   S     = Start game (from title screen)
 *   Arrow keys = Move / Duck
 *   A     = Jump (hold for higher)
 *   S     = Fire (when Fire Mario)
 */
(function () {
    'use strict';

    var patched = false;
    var leaderboardActive = false;
    var mfsdScore = 0;   /* running coin count for this playthrough */

    /* Poll until the Mario object and game engine are available */
    var initInterval = setInterval(function () {
        if (typeof window.Mario === 'undefined' ||
            typeof Mario.Character === 'undefined' ||
            typeof Mario.LoseState === 'undefined') return;

        if (!patched) {
            patchGame();
            patched = true;
            clearInterval(initInterval);
        }
    }, 200);

    /* ================================================================
       PATCH GAME — track coins + intercept game over
       ================================================================ */
    function patchGame() {

        /* ── Track coins collected as score ── */
        var origGetCoin = Mario.Character.prototype.GetCoin;
        Mario.Character.prototype.GetCoin = function () {
            mfsdScore++;
            return origGetCoin.apply(this, arguments);
        };

        /* ── Reset score when a new game starts from the title ── */
        if (Mario.TitleState && Mario.TitleState.prototype.CheckForChange) {
            var origTitleCheck = Mario.TitleState.prototype.CheckForChange;
            Mario.TitleState.prototype.CheckForChange = function (app) {
                /* If the player presses S (start), reset the coin counter */
                if (typeof Enjine !== 'undefined' && Enjine.KeyboardInput &&
                    Enjine.KeyboardInput.IsKeyDown(Enjine.Keys.S)) {
                    mfsdScore = 0;
                }
                return origTitleCheck.apply(this, arguments);
            };
        }

        /* ── Intercept Game Over (LoseState) ── */
        var origLoseEnter = Mario.LoseState.prototype.Enter;
        Mario.LoseState.prototype.Enter = function () {
            origLoseEnter.apply(this, arguments);

            /* Show leaderboard after the "GAME OVER" screen displays briefly */
            var finalScore = mfsdScore;
            setTimeout(function () {
                if (typeof MFSDLeaderboard !== 'undefined' && MFSDLeaderboard.isConnected()) {
                    if (!leaderboardActive) {
                        leaderboardActive = true;
                        MFSDLeaderboard.onGameOver(finalScore);
                    }
                }
            }, 2500);
        };

        /* ── Optionally intercept Win State to also offer score submission ── */
        if (Mario.WinState && Mario.WinState.prototype.Enter) {
            var origWinEnter = Mario.WinState.prototype.Enter;
            Mario.WinState.prototype.Enter = function () {
                origWinEnter.apply(this, arguments);

                var finalScore = mfsdScore;
                setTimeout(function () {
                    if (typeof MFSDLeaderboard !== 'undefined' && MFSDLeaderboard.isConnected()) {
                        if (!leaderboardActive) {
                            leaderboardActive = true;
                            MFSDLeaderboard.onGameOver(finalScore);
                        }
                    }
                }, 3000);
            };
        }
    }

    /* ================================================================
       LISTEN FOR LEADERBOARD CLOSE
       ================================================================ */

    /* From postMessage (arcade iframe) */
    window.addEventListener('message', function (e) {
        if (e.data && e.data.type === 'mfsd-leaderboard-closed') {
            onLeaderboardClosed();
        }
    });

    /* Direct DOM check (same-window close) */
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
        /* Score resets when player starts a new game from title screen */
    }

    /* ================================================================
       KEYBOARD: press L on title screen to view leaderboard
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
