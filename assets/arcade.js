(function () {
    'use strict';

    var cfg = window.MFSD_ARCADE || {};
    if (!cfg.restBase) return;

    /* ── State ── */
    var state = {
        token:      null,
        remaining:  0,
        status:     'none',     /* none | active | paused | expired */
        timerRef:   null,
        heartbeatRef: null,
        balance:    cfg.balance || 0,
        gameUrl:    null,       /* the gated iframe URL for the current session */
    };

    /* ── DOM refs (populated on init) ── */
    var el = {};

    /* ================================================================
       INIT — runs on DOMContentLoaded
       ================================================================ */
    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('mfsd-arcade-root');
        if (!root) return;

        /* Cache DOM elements (play mode) */
        if (cfg.mode === 'play') {
            el.purchasePanel  = document.getElementById('arc-purchase-panel');
            el.gameContainer  = document.getElementById('arc-game-container');
            el.iframe         = document.getElementById('arc-game-iframe');
            el.timerDisplay   = document.getElementById('arc-timer-display');
            el.timerFill      = document.getElementById('arc-timer-fill');
            el.pauseBtn       = document.getElementById('arc-pause-btn');
            el.resumeBtn      = document.getElementById('arc-resume-btn');
            el.purchaseBtn    = document.getElementById('arc-purchase-btn');
            el.playAgainBtn   = document.getElementById('arc-play-again-btn');
            el.coinSlider     = document.getElementById('arc-coin-slider');
            el.coinAmount     = document.getElementById('arc-coin-amount');
            el.timeAmount     = document.getElementById('arc-time-amount');
            el.overlayPaused  = document.getElementById('arc-overlay-paused');
            el.overlayExpired = document.getElementById('arc-overlay-expired');
            el.pausedTime     = document.getElementById('arc-paused-time');
            el.expiredBalance = document.getElementById('arc-expired-balance');
            el.balance        = document.getElementById('arc-balance');

            initPlayMode();
        }
    });

    /* ================================================================
       PLAY MODE INIT
       ================================================================ */
    function initPlayMode() {

        /* ── Coin slider ── */
        if (el.coinSlider) {
            el.coinSlider.addEventListener('input', function () {
                var coins = parseInt(this.value, 10);
                if (el.coinAmount) el.coinAmount.textContent = coins;
                if (el.timeAmount) el.timeAmount.textContent = coinsToLabel(coins);
            });
        }

        /* ── Purchase button ── */
        if (el.purchaseBtn) {
            el.purchaseBtn.addEventListener('click', doPurchase);
        }

        /* ── Pause button ── */
        if (el.pauseBtn) {
            el.pauseBtn.addEventListener('click', doPause);
        }

        /* ── Resume button ── */
        if (el.resumeBtn) {
            el.resumeBtn.addEventListener('click', doResume);
        }

        /* ── Play again button ── */
        if (el.playAgainBtn) {
            el.playAgainBtn.addEventListener('click', function () {
                showPurchase();
                refreshBalance();
            });
        }

        /* ── Disconnect detection (Layer 1: client-side) ── */
        window.addEventListener('beforeunload', onDisconnect);
        document.addEventListener('visibilitychange', function () {
            if (document.hidden && state.status === 'active' && state.token) {
                sendPause(state.token);
            }
            if (!document.hidden && state.status === 'paused' && state.token) {
                /* Tab regained focus — show paused overlay so student can resume */
                showPaused(state.remaining);
            }
        });

        /* ── Resume existing session from server ── */
        if (cfg.liveSession) {
            state.token   = cfg.liveSession.session_token;
            state.gameUrl = cfg.liveSession.game_url || null;

            if (cfg.liveSession.status === 'active') {
                var rem = Math.max(0, Math.floor(
                    (new Date(cfg.liveSession.expires_at.replace(' ', 'T') + 'Z').getTime() - Date.now()) / 1000
                ));
                loadGameIframe();
                startSession(rem);
            } else if (cfg.liveSession.status === 'paused') {
                state.remaining = parseInt(cfg.liveSession.remaining_seconds, 10) || 0;
                state.status = 'paused';
                showGame();
                showPaused(state.remaining);
            }
        }
    }

    /* ================================================================
       PURCHASE
       ================================================================ */
    function doPurchase() {
        if (!cfg.game || !cfg.game.id) return;

        var coins = el.coinSlider ? parseInt(el.coinSlider.value, 10) : 1;
        el.purchaseBtn.disabled = true;
        el.purchaseBtn.textContent = 'Inserting coins…';

        apiPost('/purchase', { game_id: cfg.game.id, coins: coins })
            .then(function (data) {
                if (!data.ok) {
                    alert(data.message || 'Purchase failed.');
                    el.purchaseBtn.disabled = false;
                    el.purchaseBtn.innerHTML = '🪙 Insert coins &amp; play';
                    return;
                }
                state.token   = data.session.session_token;
                state.balance = data.session.new_balance;
                state.gameUrl = data.session.iframe_url;
                updateBalanceDisplay(state.balance);

                /* Load the game iframe via gated URL */
                loadGameIframe();

                startSession(data.session.remaining_seconds);
            })
            .catch(function (err) {
                alert('Error: ' + (err.message || 'Could not purchase session.'));
                el.purchaseBtn.disabled = false;
                el.purchaseBtn.innerHTML = '🪙 Insert coins &amp; play';
            });
    }

    /* ================================================================
       START SESSION — show game, start timer + heartbeat
       ================================================================ */
    function startSession(seconds) {
        state.remaining = seconds;
        state.status = 'active';
        state.totalSeconds = state.totalSeconds || seconds; /* for progress bar */

        showGame();
        hideOverlays();
        startTimer();
        startHeartbeat();
    }

    /* ================================================================
       TIMER — ticks every second on the client
       ================================================================ */
    function startTimer() {
        clearInterval(state.timerRef);
        state.timerRef = setInterval(function () {
            state.remaining = Math.max(0, state.remaining - 1);
            renderTimer(state.remaining);

            if (state.remaining <= 0) {
                onExpired();
            }
        }, 1000);
        renderTimer(state.remaining);
    }

    function stopTimer() {
        clearInterval(state.timerRef);
        state.timerRef = null;
    }

    function renderTimer(secs) {
        if (el.timerDisplay) {
            el.timerDisplay.textContent = formatTime(secs);
        }
        if (el.timerFill && state.totalSeconds) {
            var pct = Math.max(0, Math.min(100, (secs / state.totalSeconds) * 100));
            el.timerFill.style.width = pct + '%';

            /* Colour shift: green → amber → red */
            if (pct > 50) el.timerFill.style.background = '#28a745';
            else if (pct > 20) el.timerFill.style.background = '#f0ad4e';
            else el.timerFill.style.background = '#dc3545';
        }

        /* Urgent flash under 60s */
        if (el.timerDisplay) {
            el.timerDisplay.classList.toggle('arc-urgent', secs <= 60 && secs > 0);
        }
    }

    /* ================================================================
       HEARTBEAT — pings server every 30s
       ================================================================ */
    function startHeartbeat() {
        clearInterval(state.heartbeatRef);
        state.heartbeatRef = setInterval(function () {
            if (state.status !== 'active' || !state.token) return;

            apiPost('/heartbeat', { token: state.token })
                .then(function (data) {
                    if (!data.ok) return;
                    var s = data.session;

                    if (s.status === 'expired') {
                        onExpired();
                    } else if (s.status === 'paused') {
                        state.remaining = s.remaining_seconds;
                        state.status = 'paused';
                        stopTimer();
                        showPaused(s.remaining_seconds);
                    } else {
                        /* Sync client timer with server truth */
                        state.remaining = s.remaining_seconds;
                    }
                })
                .catch(function () { /* Network hiccup — timer keeps running locally */ });
        }, 30000);
    }

    function stopHeartbeat() {
        clearInterval(state.heartbeatRef);
        state.heartbeatRef = null;
    }

    /* ================================================================
       PAUSE
       ================================================================ */
    function doPause() {
        if (!state.token || state.status !== 'active') return;

        sendPause(state.token).then(function (data) {
            if (data && data.ok) {
                state.remaining = data.session.remaining_seconds;
                state.status = 'paused';
                stopTimer();
                stopHeartbeat();
                showPaused(state.remaining);
            }
        });
    }

    function sendPause(token) {
        return apiPost('/pause', { token: token }).catch(function () {
            /* Best effort — if it fails, server will auto-pause via stale detection */
            return null;
        });
    }

    /* ================================================================
       RESUME
       ================================================================ */
    function doResume() {
        if (!state.token || state.status !== 'paused') return;

        el.resumeBtn.disabled = true;
        el.resumeBtn.textContent = 'Resuming…';

        apiPost('/resume', { token: state.token })
            .then(function (data) {
                el.resumeBtn.disabled = false;
                el.resumeBtn.textContent = '▶ Resume';

                if (!data.ok) {
                    alert(data.message || 'Could not resume.');
                    return;
                }

                if (data.session.status === 'expired') {
                    onExpired();
                    return;
                }

                /* Re-load iframe if it was blanked */
                if (el.iframe && state.gameUrl) {
                    if (!el.iframe.src || el.iframe.src === 'about:blank') {
                        loadGameIframe();
                    }
                }

                startSession(data.session.remaining_seconds);
            })
            .catch(function (err) {
                el.resumeBtn.disabled = false;
                el.resumeBtn.textContent = '▶ Resume';
                alert('Error resuming: ' + (err.message || 'Unknown error'));
            });
    }

    /* ================================================================
       EXPIRED
       ================================================================ */
    function onExpired() {
        state.status = 'expired';
        state.token = null;
        stopTimer();
        stopHeartbeat();

        /* Blank the iframe */
        if (el.iframe) el.iframe.src = 'about:blank';

        renderTimer(0);
        showExpired();
        refreshBalance();
    }

    /* ================================================================
       DISCONNECT HANDLER (beforeunload)
       ================================================================ */
    function onDisconnect() {
        if (state.status === 'active' && state.token) {
            /* Fire-and-forget pause via sendBeacon for reliability */
            var url = cfg.restBase + '/pause';
            var body = JSON.stringify({ token: state.token });
            if (navigator.sendBeacon) {
                var blob = new Blob([body], { type: 'application/json' });
                /* sendBeacon doesn't support custom headers, use fetch as fallback */
                try {
                    navigator.sendBeacon(url + '?_wpnonce=' + cfg.nonce, blob);
                } catch (e) { /* fallback below */ }
            }
            /* Synchronous XHR as last resort */
            try {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', url, false); /* synchronous */
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.setRequestHeader('X-WP-Nonce', cfg.nonce);
                xhr.send(body);
            } catch (e) { /* server stale detection will catch it */ }
        }
    }

    /* ================================================================
       UI STATE MANAGEMENT
       ================================================================ */
    function showPurchase() {
        if (el.purchasePanel) el.purchasePanel.style.display = '';
        if (el.gameContainer) el.gameContainer.style.display = 'none';
        hideOverlays();
        el.purchaseBtn.disabled = false;
        el.purchaseBtn.innerHTML = '🪙 Insert coins &amp; play';

        /* Re-sync slider max with new balance */
        if (el.coinSlider) {
            el.coinSlider.max = Math.max(parseInt(el.coinSlider.min, 10), state.balance);
            el.coinSlider.value = el.coinSlider.min;
            el.coinSlider.dispatchEvent(new Event('input'));
        }
    }

    function showGame() {
        if (el.purchasePanel) el.purchasePanel.style.display = 'none';
        if (el.gameContainer) el.gameContainer.style.display = '';
    }

    function showPaused(remaining) {
        hideOverlays();
        if (el.overlayPaused) el.overlayPaused.style.display = 'flex';
        if (el.pausedTime) el.pausedTime.textContent = formatTime(remaining);
        /* Blank iframe to save resources while paused */
        if (el.iframe) el.iframe.src = 'about:blank';
    }

    function showExpired() {
        hideOverlays();
        if (el.overlayExpired) el.overlayExpired.style.display = 'flex';
        if (el.expiredBalance) el.expiredBalance.textContent = state.balance;
    }

    function hideOverlays() {
        if (el.overlayPaused) el.overlayPaused.style.display = 'none';
        if (el.overlayExpired) el.overlayExpired.style.display = 'none';
    }

    function updateBalanceDisplay(bal) {
        state.balance = bal;
        if (el.balance) el.balance.textContent = bal;
    }

    function refreshBalance() {
        apiGet('/balance').then(function (data) {
            if (data && data.ok) updateBalanceDisplay(data.balance);
        }).catch(function () {});
    }

    /* ================================================================
       LOAD GAME IFRAME — sets src to the gated URL
       ================================================================ */
    function loadGameIframe() {
        if (!el.iframe || !state.gameUrl) return;
        el.iframe.src = state.gameUrl;
        sendLeaderboardConfig();
    }

    /* ================================================================
       LEADERBOARD — send config to game iframe via postMessage
       ================================================================ */
    function sendLeaderboardConfig() {
        if (!el.iframe || !cfg.game) return;

        /* Wait for iframe to load, then send config */
        el.iframe.addEventListener('load', function onLoad() {
            el.iframe.removeEventListener('load', onLoad);
            try {
                el.iframe.contentWindow.postMessage({
                    type:     'mfsd-arcade-config',
                    apiBase:  cfg.restBase,
                    nonce:    cfg.nonce,
                    gameSlug: cfg.game.slug,
                }, '*');
            } catch (e) { /* cross-origin safety */ }
        });
    }

    /* Listen for leaderboard-closed message from game iframe */
    window.addEventListener('message', function (e) {
        if (e.data && e.data.type === 'mfsd-leaderboard-closed') {
            /* Game dismissed leaderboard — nothing to do, game continues */
        }
    });

    /* ================================================================
       API HELPERS
       ================================================================ */
    function apiPost(endpoint, body) {
        return fetch(cfg.restBase + endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   cfg.nonce,
            },
            body: JSON.stringify(body),
        }).then(parseResponse);
    }

    function apiGet(endpoint) {
        return fetch(cfg.restBase + endpoint, {
            headers: { 'X-WP-Nonce': cfg.nonce },
        }).then(parseResponse);
    }

    function parseResponse(resp) {
        return resp.json().then(function (data) {
            if (!resp.ok) {
                return { ok: false, message: data.message || 'Request failed.' };
            }
            return data;
        });
    }

    /* ================================================================
       HELPERS
       ================================================================ */
    function formatTime(totalSecs) {
        var m = Math.floor(totalSecs / 60);
        var s = totalSecs % 60;
        return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    function coinsToLabel(coins) {
        var mpc = cfg.minutesPerCoin || 3;
        var mins = Math.round(coins * mpc * 10) / 10;
        if (mins < 1) return Math.round(mins * 60) + ' secs';
        return mins + ' min' + (mins !== 1 ? 's' : '');
    }

})();
