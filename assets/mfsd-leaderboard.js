/**
 * MFSD Arcade Leaderboard — Drop-in integration for any game.
 *
 * USAGE IN YOUR GAME:
 *   1. Include this script + mfsd-leaderboard.css in your game's HTML
 *   2. When the game ends, call:  MFSDLeaderboard.onGameOver(score)
 *   3. That's it. The overlay handles initials entry, API submission, and display.
 *
 * CONFIG is received automatically from the arcade iframe parent via postMessage.
 * If running standalone (no parent), the leaderboard gracefully does nothing.
 */
(function () {
    'use strict';

    var config = null;  /* { apiBase, nonce, gameSlug } — set by postMessage from parent */
    var overlay = null;
    var ready = false;

    /* ================================================================
       LISTEN FOR CONFIG FROM PARENT (arcade iframe wrapper)
       ================================================================ */
    window.addEventListener('message', function (e) {
        if (e.data && e.data.type === 'mfsd-arcade-config') {
            config = {
                apiBase:  e.data.apiBase,
                nonce:    e.data.nonce,
                gameSlug: e.data.gameSlug,
            };
            ready = true;
            buildOverlay();
        }
    });

    /* ================================================================
       PUBLIC API — games call this on game over
       ================================================================ */
    window.MFSDLeaderboard = {

        /** Call this when the game ends. Shows initials entry + leaderboard. */
        onGameOver: function (score) {
            if (!ready || !config) return;

            score = Math.max(0, Math.floor(score));
            showInitialsEntry(score);
        },

        /** Check if leaderboard is connected to the arcade. */
        isConnected: function () {
            return ready && !!config;
        },

        /** Fetch and show leaderboard without submitting (e.g. from a menu). */
        showLeaderboard: function () {
            if (!ready || !config) return;
            fetchAndShowLeaderboard();
        }
    };

    /* ================================================================
       BUILD OVERLAY DOM (once, on config received)
       ================================================================ */
    function buildOverlay() {
        if (overlay) return;

        overlay = document.createElement('div');
        overlay.id = 'mfsd-lb-overlay';
        overlay.className = 'mfsd-lb-overlay';
        overlay.style.display = 'none';
        overlay.innerHTML =
            '<div class="mfsd-lb-panel">' +
                /* Initials entry screen */
                '<div id="mfsd-lb-entry" class="mfsd-lb-screen">' +
                    '<h2 class="mfsd-lb-title">Game Over</h2>' +
                    '<div class="mfsd-lb-score-display">' +
                        '<span class="mfsd-lb-score-label">Score</span>' +
                        '<span class="mfsd-lb-score-value" id="mfsd-lb-score">0</span>' +
                    '</div>' +
                    '<div class="mfsd-lb-initials-wrap">' +
                        '<label class="mfsd-lb-initials-label">Enter your initials</label>' +
                        '<div class="mfsd-lb-initials-boxes" id="mfsd-lb-boxes"></div>' +
                        '<p class="mfsd-lb-initials-hint">5 characters max — letters &amp; numbers</p>' +
                    '</div>' +
                    '<button id="mfsd-lb-submit-btn" class="mfsd-lb-btn mfsd-lb-btn-submit" disabled>Submit Score</button>' +
                '</div>' +

                /* Leaderboard display screen */
                '<div id="mfsd-lb-board" class="mfsd-lb-screen" style="display:none;">' +
                    '<h2 class="mfsd-lb-title">Leaderboard</h2>' +
                    '<div id="mfsd-lb-rank-banner" class="mfsd-lb-rank-banner" style="display:none;"></div>' +
                    '<div class="mfsd-lb-table-wrap">' +
                        '<table class="mfsd-lb-table">' +
                            '<thead><tr><th>#</th><th>Name</th><th>Score</th></tr></thead>' +
                            '<tbody id="mfsd-lb-tbody"></tbody>' +
                        '</table>' +
                    '</div>' +
                    '<div class="mfsd-lb-meta" id="mfsd-lb-meta"></div>' +
                    '<button id="mfsd-lb-close-btn" class="mfsd-lb-btn mfsd-lb-btn-close">Continue</button>' +
                '</div>' +

                /* Loading spinner */
                '<div id="mfsd-lb-loading" class="mfsd-lb-screen" style="display:none;">' +
                    '<div class="mfsd-lb-spinner"></div>' +
                    '<p>Saving score…</p>' +
                '</div>' +
            '</div>';

        document.body.appendChild(overlay);

        /* ── Wire up initials input boxes ── */
        var boxContainer = document.getElementById('mfsd-lb-boxes');
        for (var i = 0; i < 5; i++) {
            var input = document.createElement('input');
            input.type = 'text';
            input.maxLength = 1;
            input.className = 'mfsd-lb-char-box';
            input.dataset.index = i;
            input.setAttribute('autocomplete', 'off');
            input.setAttribute('autocapitalize', 'characters');
            boxContainer.appendChild(input);
        }

        var charBoxes = boxContainer.querySelectorAll('.mfsd-lb-char-box');

        charBoxes.forEach(function (box, idx) {
            box.addEventListener('input', function () {
                /* Uppercase and filter to alphanumeric */
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
                if (this.value && idx < 4) {
                    charBoxes[idx + 1].focus();
                }
                updateSubmitState();
            });

            box.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !this.value && idx > 0) {
                    charBoxes[idx - 1].focus();
                    charBoxes[idx - 1].value = '';
                    updateSubmitState();
                }
                if (e.key === 'Enter') {
                    document.getElementById('mfsd-lb-submit-btn').click();
                }
            });

            /* Auto-focus first box on paste */
            box.addEventListener('paste', function (e) {
                e.preventDefault();
                var pasted = (e.clipboardData || window.clipboardData).getData('text')
                    .toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 5);
                for (var j = 0; j < 5; j++) {
                    charBoxes[j].value = pasted[j] || '';
                }
                var focusIdx = Math.min(pasted.length, 4);
                charBoxes[focusIdx].focus();
                updateSubmitState();
            });
        });

        function updateSubmitState() {
            var initials = getInitials();
            document.getElementById('mfsd-lb-submit-btn').disabled = initials.length < 1;
        }

        /* ── Submit button ── */
        document.getElementById('mfsd-lb-submit-btn').addEventListener('click', function () {
            var initials = getInitials();
            var score = parseInt(document.getElementById('mfsd-lb-score').textContent, 10);
            if (initials.length < 1) return;
            submitScore(initials, score);
        });

        /* ── Close button — tell parent to dismiss, hide overlay ── */
        document.getElementById('mfsd-lb-close-btn').addEventListener('click', function () {
            hideOverlay();
            /* Notify parent the leaderboard is dismissed */
            window.parent.postMessage({ type: 'mfsd-leaderboard-closed' }, '*');
        });
    }

    /* ================================================================
       SHOW INITIALS ENTRY
       ================================================================ */
    function showInitialsEntry(score) {
        document.getElementById('mfsd-lb-score').textContent = score;
        showScreen('mfsd-lb-entry');
        showOverlay();

        /* Focus first char box */
        var firstBox = document.querySelector('.mfsd-lb-char-box');
        if (firstBox) {
            /* Small delay to ensure overlay is visible and focusable */
            setTimeout(function () { firstBox.focus(); }, 100);
        }

        /* Clear previous initials */
        document.querySelectorAll('.mfsd-lb-char-box').forEach(function (b) { b.value = ''; });
        document.getElementById('mfsd-lb-submit-btn').disabled = true;
    }

    /* ================================================================
       SUBMIT SCORE TO API
       ================================================================ */
    function submitScore(initials, score) {
        showScreen('mfsd-lb-loading');

        fetch(config.apiBase + '/scores', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   config.nonce,
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                game:     config.gameSlug,
                initials: initials,
                score:    score,
            }),
        })
        .then(function (resp) { return resp.json(); })
        .then(function (data) {
            if (data.ok) {
                renderLeaderboard(data.scores, data.rank, data.player_count, data.personal_best, data.is_new_best, score);
            } else {
                alert(data.message || 'Could not save score.');
                showScreen('mfsd-lb-entry');
            }
        })
        .catch(function (err) {
            console.error('MFSD Leaderboard error:', err);
            alert('Network error — score not saved.');
            showScreen('mfsd-lb-entry');
        });
    }

    /* ================================================================
       FETCH + SHOW LEADERBOARD (without submitting)
       ================================================================ */
    function fetchAndShowLeaderboard() {
        showScreen('mfsd-lb-loading');
        showOverlay();

        fetch(config.apiBase + '/scores?game=' + encodeURIComponent(config.gameSlug) + '&limit=10', {
            headers: { 'X-WP-Nonce': config.nonce },
            credentials: 'same-origin',
        })
        .then(function (resp) { return resp.json(); })
        .then(function (data) {
            if (data.ok) {
                renderLeaderboard(data.scores, data.my_rank, data.player_count, data.personal_best, false, null);
            }
        })
        .catch(function () {
            hideOverlay();
        });
    }

    /* ================================================================
       RENDER LEADERBOARD TABLE
       ================================================================ */
    function renderLeaderboard(scores, rank, playerCount, personalBest, isNewBest, submittedScore) {
        var tbody = document.getElementById('mfsd-lb-tbody');
        tbody.innerHTML = '';

        if (!scores || scores.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:#8b949e;">No scores yet — be the first!</td></tr>';
        } else {
            scores.forEach(function (s, i) {
                var row = document.createElement('tr');
                var isMe = submittedScore !== null && parseInt(s.score, 10) === submittedScore && i + 1 === rank;
                if (isMe) row.className = 'mfsd-lb-highlight';

                row.innerHTML =
                    '<td class="mfsd-lb-rank">' + (i + 1) + '</td>' +
                    '<td class="mfsd-lb-name">' + escHtml(s.initials) + '</td>' +
                    '<td class="mfsd-lb-pts">' + numberWithCommas(s.score) + '</td>';
                tbody.appendChild(row);
            });
        }

        /* Rank banner */
        var banner = document.getElementById('mfsd-lb-rank-banner');
        if (rank && playerCount) {
            var bannerText = '';
            if (isNewBest) bannerText += '🏆 New personal best! ';
            bannerText += 'You ranked <strong>#' + rank + '</strong> out of <strong>' + playerCount + '</strong> player' + (playerCount !== 1 ? 's' : '');
            banner.innerHTML = bannerText;
            banner.style.display = '';
        } else {
            banner.style.display = 'none';
        }

        /* Meta line */
        var meta = document.getElementById('mfsd-lb-meta');
        if (personalBest) {
            meta.innerHTML = 'Your best: <strong>' + numberWithCommas(personalBest.score) + '</strong>';
        } else {
            meta.innerHTML = '';
        }

        showScreen('mfsd-lb-board');
    }

    /* ================================================================
       OVERLAY VISIBILITY
       ================================================================ */
    function showOverlay() {
        if (overlay) overlay.style.display = 'flex';
    }

    function hideOverlay() {
        if (overlay) overlay.style.display = 'none';
    }

    function showScreen(screenId) {
        overlay.querySelectorAll('.mfsd-lb-screen').forEach(function (s) { s.style.display = 'none'; });
        var el = document.getElementById(screenId);
        if (el) el.style.display = '';
    }

    /* ================================================================
       HELPERS
       ================================================================ */
    function getInitials() {
        var chars = [];
        document.querySelectorAll('.mfsd-lb-char-box').forEach(function (b) {
            if (b.value) chars.push(b.value);
        });
        return chars.join('');
    }

    function escHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function numberWithCommas(n) {
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

})();
