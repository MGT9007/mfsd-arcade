/**
 * MFSD Asteroids Integration
 * Hooks into dmcinnes/HTML5-Asteroids game to trigger the MFSD leaderboard.
 *
 * Game state reference (from game.js):
 *   Game.score      — current score (int)
 *   Game.lives      — remaining lives (starts 2, game over when < 0)
 *   Game.FSM.state  — FSM state: 'boot','waiting','spawn_ship','run','player_died','end_game'
 *
 * Strategy: monkey-patch Game.FSM.end_game to intercept the game-over
 * state and show the leaderboard instead of the 5-second auto-reset.
 */
(function () {
    'use strict';

    var patched = false;
    var leaderboardActive = false;

    /* Poll until the Game object is available, then patch once */
    var initInterval = setInterval(function () {
        if (typeof window.Game === 'undefined' || !window.Game.FSM) return;

        if (!patched) {
            patchEndGame();
            patched = true;
            clearInterval(initInterval);
        }
    }, 200);

    /* ================================================================
       MONKEY-PATCH: Game.FSM.end_game
       Intercepts game over to show leaderboard before auto-reset.
       ================================================================ */
    function patchEndGame() {

        Game.FSM.end_game = function () {
            /* If leaderboard is already showing, freeze the game-over state
               (don't let the original timer tick to 'waiting') */
            if (leaderboardActive) {
                /* Keep rendering GAME OVER text while leaderboard is up */
                if (typeof Text !== 'undefined' && Text.renderText) {
                    Text.renderText('GAME OVER', 50, Game.canvasWidth / 2 - 160, Game.canvasHeight / 2 + 10);
                }
                return;
            }

            /* First frame of end_game — start the sequence */
            if (this.timer == null) {
                this.timer = Date.now();

                /* Show "GAME OVER" for 1.5s, then trigger leaderboard */
                var finalScore = Game.score || 0;
                setTimeout(function () {
                    if (typeof MFSDLeaderboard !== 'undefined' && MFSDLeaderboard.isConnected()) {
                        leaderboardActive = true;
                        MFSDLeaderboard.onGameOver(finalScore);
                    }
                }, 1500);
            }

            /* Render "GAME OVER" text (replicates line 1000 in game.js) */
            if (typeof Text !== 'undefined' && Text.renderText) {
                Text.renderText('GAME OVER', 50, Game.canvasWidth / 2 - 160, Game.canvasHeight / 2 + 10);
            }

            /* Fallback: if leaderboard isn't connected, auto-reset after 5s */
            if (typeof MFSDLeaderboard === 'undefined' || !MFSDLeaderboard.isConnected()) {
                if (Date.now() - this.timer > 5000) {
                    this.timer = null;
                    this.state = 'waiting';
                }
                window.gameStart = false;
            }
        };
    }

    /* ================================================================
       LISTEN FOR LEADERBOARD CLOSE — resume the game cycle
       ================================================================ */

    /* From postMessage (when inside arcade iframe) */
    window.addEventListener('message', function (e) {
        if (e.data && e.data.type === 'mfsd-leaderboard-closed') {
            onLeaderboardClosed();
        }
    });

    /* Direct DOM check (same-window close via Continue button) */
    var closeObserver = setInterval(function () {
        if (!leaderboardActive) return;

        var overlay = document.getElementById('mfsd-lb-overlay');
        if (overlay && overlay.style.display === 'none') {
            onLeaderboardClosed();
        }
    }, 300);

    function onLeaderboardClosed() {
        if (!leaderboardActive) return;

        leaderboardActive = false;

        /* Reset back to the waiting/title screen */
        if (typeof Game !== 'undefined' && Game.FSM) {
            Game.FSM.timer = null;
            Game.FSM.state = 'waiting';
            window.gameStart = false;
        }
    }

    /* ================================================================
       KEYBOARD: press L on waiting screen to view leaderboard
       ================================================================ */
    document.addEventListener('keydown', function (e) {
        if ((e.key === 'l' || e.key === 'L') && !leaderboardActive) {
            if (typeof Game !== 'undefined' && Game.FSM &&
                (Game.FSM.state === 'waiting' || Game.FSM.state === 'end_game')) {
                if (typeof MFSDLeaderboard !== 'undefined' && MFSDLeaderboard.isConnected()) {
                    MFSDLeaderboard.showLeaderboard();
                    leaderboardActive = true;
                }
            }
        }
    });

})();
