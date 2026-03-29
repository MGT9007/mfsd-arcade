/**
 * MFSD Hyperspace Garbage Collection Integration
 * Hooks into razh/game-off-2013 to trigger the MFSD leaderboard.
 *
 * Score = number of levels completed.
 *
 * Strategy: Use RequireJS to access the game's internal modules
 * and track level transitions. Falls back to DOM observation
 * if module access isn't possible.
 *
 * The game uses RequireJS AMD modules. Key modules to look for:
 *   - game/game or app — main game controller
 *   - game/level or game/world — level management
 */
(function () {
    'use strict';

    var levelsCompleted = 0;
    var currentLevel = -1;
    var leaderboardActive = false;
    var hooked = false;
    var gameOver = false;

    /* ================================================================
       POLL FOR GAME STATE — wait for RequireJS + game modules
       ================================================================ */
    var initInterval = setInterval(function () {
        if (typeof window.require === 'undefined' || !window.require.defined) return;

        if (!hooked) {
            attemptHook();
        }
    }, 500);

    function attemptHook() {
        /* Try to access game modules via RequireJS.
           The exact module names depend on the game's structure.
           We try several common patterns. */

        /* Approach 1: Try to access the game module directly */
        try {
            if (window.require.defined('game')) {
                window.require(['game'], function (game) {
                    hookIntoGame(game);
                });
                return;
            }
        } catch (e) { /* continue */ }

        /* Approach 2: Look for the game object on the window */
        if (window.game || window.Game) {
            hookIntoGame(window.game || window.Game);
            return;
        }

        /* Approach 3: Try app module */
        try {
            if (window.require.defined('app')) {
                window.require(['app'], function (app) {
                    hookIntoGame(app);
                });
                return;
            }
        } catch (e) { /* continue */ }

        /* Approach 4: Canvas-based detection — watch for level transitions
           by monitoring canvas redraws and checking for level indicators */
        setupCanvasWatcher();
    }

    /* ================================================================
       HOOK INTO GAME — patch level transition methods
       ================================================================ */
    function hookIntoGame(gameObj) {
        if (hooked || !gameObj) return;
        hooked = true;
        clearInterval(initInterval);

        /* Look for common level-related methods/properties */
        var possibleLevelProps = ['level', 'currentLevel', 'levelIndex', 'levelNumber', 'world'];
        var levelProp = null;

        for (var i = 0; i < possibleLevelProps.length; i++) {
            if (gameObj[possibleLevelProps[i]] !== undefined) {
                levelProp = possibleLevelProps[i];
                break;
            }
        }

        if (levelProp !== null) {
            /* Poll the level property for changes */
            currentLevel = gameObj[levelProp];
            setInterval(function () {
                var nowLevel = gameObj[levelProp];
                if (typeof nowLevel === 'number' && nowLevel !== currentLevel && nowLevel > currentLevel) {
                    levelsCompleted++;
                    currentLevel = nowLevel;
                }
            }, 500);
        }

        /* Look for a loadLevel / nextLevel / setLevel method to patch */
        var levelMethods = ['loadLevel', 'nextLevel', 'setLevel', 'gotoLevel', 'changeLevel', 'load'];
        for (var j = 0; j < levelMethods.length; j++) {
            if (typeof gameObj[levelMethods[j]] === 'function') {
                patchMethod(gameObj, levelMethods[j]);
            }
        }

        /* Look for game-over / death / lose state */
        var endMethods = ['gameOver', 'onGameOver', 'die', 'lose', 'endGame', 'restart'];
        for (var k = 0; k < endMethods.length; k++) {
            if (typeof gameObj[endMethods[k]] === 'function') {
                patchEndMethod(gameObj, endMethods[k]);
            }
        }
    }

    function patchMethod(obj, methodName) {
        var orig = obj[methodName];
        obj[methodName] = function () {
            var result = orig.apply(this, arguments);
            levelsCompleted++;
            return result;
        };
    }

    function patchEndMethod(obj, methodName) {
        var orig = obj[methodName];
        obj[methodName] = function () {
            var result = orig.apply(this, arguments);
            if (!gameOver) {
                gameOver = true;
                onGameEnd();
            }
            return result;
        };
    }

    /* ================================================================
       CANVAS WATCHER — fallback if we can't access game modules
       Monitors for level transition indicators in the DOM.
       ================================================================ */
    function setupCanvasWatcher() {
        hooked = true;
        clearInterval(initInterval);

        /* Watch for any elements that indicate level changes.
           Many games display level numbers or use specific CSS classes. */
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                /* Check added nodes for level indicators */
                if (m.addedNodes) {
                    for (var i = 0; i < m.addedNodes.length; i++) {
                        var node = m.addedNodes[i];
                        if (node.textContent) {
                            var text = node.textContent.toLowerCase();
                            if (text.indexOf('level') !== -1 || text.indexOf('stage') !== -1) {
                                levelsCompleted++;
                            }
                        }
                    }
                }
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        /* Also monitor hash changes — some games use URL hash for levels */
        window.addEventListener('hashchange', function () {
            var hash = window.location.hash;
            if (hash && hash.indexOf('level') !== -1) {
                levelsCompleted++;
            }
        });
    }

    /* ================================================================
       GAME END — trigger leaderboard
       ================================================================ */
    function onGameEnd() {
        if (leaderboardActive) return;

        var finalScore = Math.max(levelsCompleted, 1);
        setTimeout(function () {
            if (typeof MFSDLeaderboard !== 'undefined' && MFSDLeaderboard.isConnected()) {
                leaderboardActive = true;
                MFSDLeaderboard.onGameOver(finalScore);
            }
        }, 2000);
    }

    /* ================================================================
       LISTEN FOR LEADERBOARD CLOSE
       ================================================================ */
    window.addEventListener('message', function (e) {
        if (e.data && e.data.type === 'mfsd-leaderboard-closed') {
            onLeaderboardClosed();
        }
    });

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
        gameOver = false;
    }

    /* ================================================================
       KEYBOARD: L to view leaderboard
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
