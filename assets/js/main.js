/* Vorstellungs-Webseite – Haupt-Skript */
document.addEventListener('DOMContentLoaded', function () {

    /* Mobile Navigation */
    var navToggle = document.getElementById('navToggle');
    var mainNav = document.getElementById('mainNav');
    if (navToggle && mainNav) {
        navToggle.addEventListener('click', function () {
            mainNav.classList.toggle('open');
        });
    }

    /* Mobile Admin-Sidebar */
    var adminToggle = document.getElementById('adminToggle');
    var adminSidebar = document.getElementById('adminSidebar');
    if (adminToggle && adminSidebar) {
        adminToggle.addEventListener('click', function () {
            adminSidebar.classList.toggle('open');
        });
    }

    /* Scroll-Reveal-Animationen */
    var revealEls = document.querySelectorAll('.reveal');
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });
        revealEls.forEach(function (el) { io.observe(el); });
    } else {
        revealEls.forEach(function (el) { el.classList.add('visible'); });
    }

    /* Bestätigungs-Dialoge für Formulare mit data-confirm */
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });

    /* GitHub-Projektkarten mit Live-Daten füllen */
    document.querySelectorAll('.project-card[data-github]').forEach(function (card) {
        var repo = card.getAttribute('data-github').trim();
        if (!repo) return;
        var fields = {
            name: card.querySelector('[data-field="name"]'),
            description: card.querySelector('[data-field="description"]'),
            stars: card.querySelector('[data-field="stars"]'),
            forks: card.querySelector('[data-field="forks"]'),
            issues: card.querySelector('[data-field="issues"]'),
            language: card.querySelector('[data-field="language"]'),
            link: card.querySelector('[data-field="link"]')
        };
        fetch('https://api.github.com/repos/' + encodeURI(repo))
            .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
            .then(function (data) {
                if (fields.name && data.name) fields.name.textContent = data.name;
                if (fields.description && data.description) fields.description.textContent = data.description;
                if (fields.stars) fields.stars.textContent = data.stargazers_count ?? 0;
                if (fields.forks) fields.forks.textContent = data.forks_count ?? 0;
                if (fields.issues) fields.issues.textContent = data.open_issues_count ?? 0;
                if (fields.language) fields.language.textContent = data.language ?? '—';
                if (fields.link) fields.link.href = data.html_url;
            })
            .catch(function () {
                if (fields.language) fields.language.textContent = 'offline';
            });
    });

    /* Avatar-Vorschau im Mitglieder-Panel live aktualisieren */
    var avatarColor = document.getElementById('avatarColor');
    var avatarPreview = document.getElementById('avatarPreview');
    if (avatarColor && avatarPreview) {
        avatarColor.addEventListener('input', function () {
            var av = avatarPreview.querySelector('.avatar');
            if (av) av.style.background = avatarColor.value;
        });
    }

    /* Twitch-Live-Status (optional, anzeigen falls API erreichbar) */
    var dot = document.getElementById('liveDot');
    if (dot) {
        var channelEl = document.querySelector('.project-card'); // Platzhalter, Kanal kommt aus PHP
        // Öffentliche Live-Prüfung ohne Client-ID ist nicht möglich – Punkt bleibt neutral.
    }
});


/* =========================================================
   AJAX: Benachrichtigungs-Glocke
   ========================================================= */
(function () {
    var bell = document.getElementById('notifBell');
    if (!bell) return;
    var badge = document.getElementById('notifBadge');
    var panel = document.getElementById('notifPanel');
    var list = document.getElementById('notifList');
    var countEl = document.getElementById('notifCount');
    var csrf = bell.dataset.csrf || '';
    var open = false;

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

    function render(items) {
        if (!items || !items.length) {
            list.innerHTML = '<p class="muted small" style="padding:.7rem 1rem">Keine Benachrichtigungen.</p>';
            return;
        }
        list.innerHTML = items.map(function (n) {
            return '<a class="notif-item' + (n.read ? ' read' : '') + '" href="' + (n.link || '#') + '">'
                + n.avatar
                + '<div><span>' + esc(n.text) + '</span><time>' + esc(n.time) + '</time></div></a>';
        }).join('');
    }
    function renderError(msg) {
        list.innerHTML = '<p class="small" style="padding:.7rem 1rem;color:#ff9b8f">Fehler beim Laden: ' + esc(msg) + '</p>';
    }

    function load() {
        return fetch('api/notifications.php', { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (d) {
                if (d.error) throw new Error(d.error);
                badge.hidden = !(d.unread > 0);
                if (d.unread > 0) badge.textContent = d.unread;
                if (countEl) countEl.textContent = d.items.length ? d.items.length + ' Einträge' : '';
                if (open) render(d.items);
            })
            .catch(function (e) { if (open) renderError(e.message); });
    }

    // Klick: Panel SOFORT öffnen, Inhalte nachladen
    bell.addEventListener('click', function () {
        open = !open;
        panel.hidden = !open;
        if (open) {
            list.innerHTML = '<p class="muted small" style="padding:.7rem 1rem">Lade …</p>';
            load().then(function () {
                // Als gelesen markieren (mit CSRF)
                fetch('api/notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    credentials: 'same-origin',
                    body: 'action=read_all&csrf=' + encodeURIComponent(csrf)
                }).then(load).catch(function () {});
            });
        }
    });

    load();
    setInterval(load, 30000);
})();

/* =========================================================
   AJAX: Privatchat (kein Reload)
   ========================================================= */
(function () {
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
    function nl2brEsc(s) { return esc(s).replace(/\n/g, '<br>'); }

    function fetchJson(url, options) {
        return fetch(url, options || { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) return r.text().then(function (t) { throw new Error(t || ('HTTP ' + r.status)); });
            return r.json();
        });
    }

    var box = document.getElementById('pmBox');
    if (box) {
        var withUser = box.dataset.with;
        var csrf = box.dataset.csrf;
        var lastId = parseInt(box.dataset.last, 10) || 0;
        var form = document.getElementById('pmForm');
        box.scrollTop = box.scrollHeight;

        function addMsg(m) {
            var empty = document.getElementById('pmEmpty');
            if (empty) empty.remove();
            if (box.querySelector('[data-id="' + m.id + '"]')) return;
            var row = document.createElement('div');
            row.className = 'chat-row' + (m.own ? ' own' : '');
            row.dataset.id = m.id;
            row.innerHTML = '<div class="chat-bubble' + (m.own ? ' own' : '') + '">'
                + nl2brEsc(m.text) + '<span class="chat-meta">' + esc(m.time) + (m.own ? (m.read ? ' · ✔✔ gelesen' : ' · ✔ gesendet') : '') + '</span></div>';
            box.appendChild(row);
            lastId = m.id;
            box.scrollTop = box.scrollHeight;
        }

        function poll() {
            fetchJson('api/pm.php?with=' + encodeURIComponent(withUser) + '&after=' + lastId)
                .then(function (d) { (d.messages || []).forEach(addMsg); })
                .catch(function () {});
        }

        var attachInput = document.getElementById('pmAttach');
        var attachName = document.getElementById('pmAttachName');
        if (attachInput && attachName) {
            attachInput.addEventListener('change', function () {
                attachName.textContent = attachInput.files.length ? '📎 ' + attachInput.files[0].name : '';
            });
        }
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var ta = form.querySelector('textarea');
            var text = ta.value.trim();
            var hasFile = attachInput && attachInput.files.length > 0;
            if (!text && !hasFile) return;
            var body;
            if (hasFile) {
                var fd = new FormData();
                fd.append('to', withUser);
                fd.append('message', text);
                fd.append('csrf', csrf);
                fd.append('attachment', attachInput.files[0]);
                body = fd;
            } else {
                body = 'to=' + encodeURIComponent(withUser) + '&message=' + encodeURIComponent(text) + '&csrf=' + encodeURIComponent(csrf);
            }
            fetchJson('api/pm.php', {
                method: 'POST',
                headers: hasFile ? {} : { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            }).then(function (d) {
                if (d.error) { alert(d.error); return; }
                if (d.message) {
                    addMsg(d.message);
                    ta.value = ''; ta.focus();
                    if (attachInput) { attachInput.value = ''; }
                    if (attachName) { attachName.textContent = ''; }
                }
            }).catch(function (err) { alert('Senden fehlgeschlagen: ' + err.message); });
        });

        setInterval(poll, 4000);
        return;
    }

    // Neue Nachricht (Unterhaltungsübersicht)
    var newForm = document.getElementById('pmNewForm');
    if (newForm) {
        var csrfInput = newForm.querySelector('input[name="csrf"]');
        newForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var to = newForm.to.value.trim(), msg = newForm.message.value.trim();
            if (!to || !msg) return;
            fetchJson('api/pm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'to=' + encodeURIComponent(to) + '&message=' + encodeURIComponent(msg) + '&csrf=' + encodeURIComponent(csrfInput ? csrfInput.value : '')
            }).then(function (d) {
                if (d.error) { alert(d.error); return; }
                window.location.href = 'nachrichten.php?with=' + encodeURIComponent(to);
            }).catch(function (err) { alert('Senden fehlgeschlagen: ' + err.message); });
        });
    }
})();

/* =========================================================
   AJAX: Channel-Chat
   ========================================================= */
(function () {
    var box = document.getElementById('chBox');
    if (!box) return;
    var channel = box.dataset.channel;
    var csrf = box.dataset.csrf;
    var isMod = box.dataset.mod === '1';
    var lastId = parseInt(box.dataset.last, 10) || 0;
    var form = document.getElementById('chForm');
    box.scrollTop = box.scrollHeight;

    function fetchJson(url, options) {
        return fetch(url, options || { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) return r.text().then(function (t) { throw new Error(t || ('HTTP ' + r.status)); });
            return r.json();
        });
    }
    function appendMsg(m) {
        if (box.querySelector('[data-id="' + m.id + '"]')) return;
        box.insertAdjacentHTML('beforeend', m.html);
        lastId = m.id;
        box.scrollTop = box.scrollHeight;
    }

    function poll() {
        fetchJson('api/chat.php?channel=' + encodeURIComponent(channel) + '&after=' + lastId)
            .then(function (d) {
                if (d.error) return;
                (d.messages || []).forEach(appendMsg);
                var ol = document.getElementById('onlineList');
                if (ol && d.online) {
                    ol.innerHTML = d.online.map(function (o) { return '<div class="online-user">' + o.html + '</div>'; }).join('');
                    var oc = document.getElementById('onlineCount');
                    if (oc) oc.textContent = d.online.length;
                }
            })
            .catch(function () {});
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var ta = form.querySelector('textarea');
        var text = ta.value.trim();
        if (!text) return;
        fetchJson('api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=send&channel=' + encodeURIComponent(channel) + '&message=' + encodeURIComponent(text) + '&csrf=' + encodeURIComponent(csrf)
        }).then(function (d) {
            if (d.error) { alert(d.error); return; }
            if (d.message) { appendMsg(d.message); ta.value = ''; ta.focus(); }
        }).catch(function (err) { alert('Senden fehlgeschlagen: ' + err.message); });
    });

    box.addEventListener('click', function (e) {
        var pin = e.target.closest('.msg-pin');
        if (pin && isMod) {
            fetchJson('api/chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=pin&channel=' + encodeURIComponent(channel) + '&id=' + pin.dataset.id + '&csrf=' + encodeURIComponent(csrf)
            }).then(function () { location.reload(); });
        }
        var del = e.target.closest('.msg-del');
        if (del && isMod && confirm('Nachricht wirklich löschen?')) {
            fetchJson('api/chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete&channel=' + encodeURIComponent(channel) + '&id=' + del.dataset.id + '&csrf=' + encodeURIComponent(csrf)
            }).then(function () { var row = box.querySelector('[data-id="' + del.dataset.id + '"]'); if (row) row.remove(); })
              .catch(function (err) { alert('Löschen fehlgeschlagen: ' + err.message); });
        }
        var rep = e.target.closest('.msg-report');
        if (rep) {
            var reason = prompt('Grund der Meldung (z. B. Spam, Beleidigung):', 'Sonstiges');
            if (reason === null) return;
            var details = prompt('Details (optional):', '') || '';
            fetchJson('api/chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=report&channel=' + encodeURIComponent(channel) + '&id=' + rep.dataset.id
                    + '&reason=' + encodeURIComponent(reason) + '&details=' + encodeURIComponent(details) + '&csrf=' + encodeURIComponent(csrf)
            }).then(function (d) { alert(d.ok ? 'Nachricht gemeldet.' : (d.error || 'Fehler')); })
              .catch(function (err) { alert('Meldung fehlgeschlagen: ' + err.message); });
        }
    });

    var toggle = document.getElementById('onlineToggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            fetchJson('api/chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=toggle_online&csrf=' + encodeURIComponent(csrf)
            }).then(function (d) {
                if (d.ok) toggle.textContent = d.visible ? '🔴 Offline gehen' : '🟢 Online gehen';
            }).catch(function (err) { alert('Fehler: ' + err.message); });
        });
    }

    setInterval(poll, 4000);
})();


/* =========================================================
   Emoji-Picker & Rich-Text-Toolbar für Textareas
   ========================================================= */
(function () {
    var EMOJIS = ['😀','😂','🤣','😊','😍','🤩','😎','🥳','😅','😉','🙃','😢','😭','😡','🤔','😴','🤯','🥶','🤗','😇',
        '👍','👎','👌','✌️','🤞','👏','🙌','🙏','💪','🤝','✍️','👀','🧠','❤️','💥','💯','🔥','✨','🎉','🎊','🎈','🎁','🏆','🥇',
        '🎮','🕹️','👾','🎲','🎯','🎱','⚽','🏀','🎸','🎧','🎤','📱','💻','⌨️','🖥️','🖱️','🔧','🛠️','⚙️','🚀','✈️','🌙','⭐','☀️','🌈','☁️','❄️','🍀'];

    var TOOLBAR = [
        { label: '<b>B</b>', title: 'Fett', before: '<strong>', after: '</strong>' },
        { label: '<i>I</i>', title: 'Kursiv', before: '<em>', after: '</em>' },
        { label: '🔗', title: 'Link', prompt: 'URL eingeben:' },
        { label: '😊', title: 'Emoji', emoji: true },
    ];

    function insertAtCursor(ta, text) {
        var s = ta.selectionStart || 0, e = ta.selectionEnd || 0;
        ta.value = ta.value.slice(0, s) + text + ta.value.slice(e);
        ta.selectionStart = ta.selectionEnd = s + text.length;
        ta.focus();
    }

    function wrapSelection(ta, before, after) {
        var s = ta.selectionStart || 0, e = ta.selectionEnd || 0;
        var sel = ta.value.slice(s, e) || 'Text';
        ta.value = ta.value.slice(0, s) + before + sel + after + ta.value.slice(e);
        ta.selectionStart = s + before.length;
        ta.selectionEnd = s + before.length + sel.length;
        ta.focus();
    }

    // Allen markierten Textareas eine Toolbar spendieren
    document.querySelectorAll('textarea.rich-edit, textarea[name="content"], textarea[name="message"], textarea[name="details"]').forEach(function (ta) {
        if (ta.dataset.richDone) return;
        ta.dataset.richDone = '1';

        var bar = document.createElement('div');
        bar.className = 'rte-toolbar';

        TOOLBAR.forEach(function (btn) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'rte-btn';
            b.title = btn.title;
            b.innerHTML = btn.label;
            b.addEventListener('click', function () {
                if (btn.emoji) {
                    // Mini-Emoji-Palette direkt unter dem Button
                    var old = bar.querySelector('.emoji-palette');
                    if (old) { old.remove(); return; }
                    var pal = document.createElement('div');
                    pal.className = 'emoji-palette';
                    EMOJIS.forEach(function (em) {
                        var eb = document.createElement('button');
                        eb.type = 'button';
                        eb.textContent = em;
                        eb.addEventListener('click', function () {
                            insertAtCursor(ta, em);
                            pal.remove();
                        });
                        pal.appendChild(eb);
                    });
                    bar.appendChild(pal);
                } else if (btn.prompt) {
                    var url = prompt(btn.prompt, 'https://');
                    if (url && url !== 'https://') insertAtCursor(ta, '<a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>');
                } else {
                    wrapSelection(ta, btn.before, btn.after);
                }
            });
            bar.appendChild(b);
        });

        ta.parentNode.insertBefore(bar, ta);
    });
})();


/* Chat-Reaktionen */
function chatReact(msgId, emoji) {
    var box = document.getElementById('chBox');
    if (!box) return;
    fetch('api/chat.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=react&channel=' + encodeURIComponent(box.dataset.channel) + '&id=' + msgId + '&emoji=' + encodeURIComponent(emoji) + '&csrf=' + encodeURIComponent(box.dataset.csrf)
    }).then(function () { location.reload(); });
}


/* =========================================================
   @Erwähnungs-Autocomplete
   ========================================================= */
(function () {
    function attachMentions(ta) {
        if (ta.dataset.mentions) return;
        ta.dataset.mentions = '1';
        var box = null;

        function closeBox() { if (box) { box.remove(); box = null; } }

        function currentQuery() {
            var pos = ta.selectionStart || 0;
            var text = ta.value.slice(0, pos);
            var m = text.match(/@([A-Za-z0-9_.\-]{2,})$/);
            return m ? m[1] : null;
        }

        ta.addEventListener('input', function () {
            var q = currentQuery();
            if (!q) { closeBox(); return; }
            fetch('api/mentions.php?q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (users) {
                    closeBox();
                    if (!users.length) return;
                    box = document.createElement('div');
                    box.className = 'mention-box';
                    users.forEach(function (u) {
                        var item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'mention-item';
                        item.innerHTML = '<span class="mention-dot" style="background:' + (u.color || '#666') + '"></span>' +
                            '<strong>@' + u.username + '</strong>' +
                            (u.name !== u.username ? ' <span class="muted small">(' + u.name + ')</span>' : '');
                        item.addEventListener('click', function () {
                            var pos = ta.selectionStart || 0;
                            ta.value = ta.value.slice(0, pos).replace(/@[A-Za-z0-9_.\-]{2,}$/, '@' + u.username + ' ') + ta.value.slice(pos);
                            ta.focus();
                            closeBox();
                        });
                        box.appendChild(item);
                    });
                    ta.parentNode.style.position = 'relative';
                    ta.parentNode.appendChild(box);
                }).catch(function () {});
        });
        ta.addEventListener('blur', function () { setTimeout(closeBox, 200); });
    }

    document.querySelectorAll('textarea[name="content"]').forEach(attachMentions);
})();

/* =========================================================
   Lightbox für Bilder (Wall, Galerie, Ressourcen)
   ========================================================= */
(function () {
    var overlay = null, imgs = [], idx = 0;

    function buildOverlay() {
        overlay = document.createElement('div');
        overlay.className = 'lightbox';
        overlay.innerHTML =
            '<button class="lb-close" aria-label="Schließen">✕</button>' +
            '<button class="lb-prev" aria-label="Zurück">‹</button>' +
            '<img class="lb-img" alt="Vorschau">' +
            '<button class="lb-next" aria-label="Weiter">›</button>';
        document.body.appendChild(overlay);
        overlay.querySelector('.lb-close').addEventListener('click', close);
        overlay.querySelector('.lb-prev').addEventListener('click', function (e) { e.stopPropagation(); show(idx - 1); });
        overlay.querySelector('.lb-next').addEventListener('click', function (e) { e.stopPropagation(); show(idx + 1); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        document.addEventListener('keydown', function (e) {
            if (!overlay.classList.contains('open')) return;
            if (e.key === 'Escape') close();
            if (e.key === 'ArrowLeft') show(idx - 1);
            if (e.key === 'ArrowRight') show(idx + 1);
        });
    }

    function show(i) {
        idx = (i + imgs.length) % imgs.length;
        overlay.querySelector('.lb-img').src = imgs[idx];
    }

    function close() { overlay.classList.remove('open'); }

    document.addEventListener('click', function (e) {
        var t = e.target.closest('.wall-image, .gallery-grid img, .resource-thumb img');
        if (!t) return;
        e.preventDefault();
        var group = t.closest('.gallery-grid, .wall-post, .resource-card, .card') || document;
        imgs = Array.prototype.map.call(
            group.querySelectorAll('.wall-image, .gallery-grid img, .resource-thumb img'),
            function (i2) { return i2.src; }
        );
        if (!imgs.length) imgs = [t.src];
        if (!overlay) buildOverlay();
        overlay.classList.add('open');
        show(imgs.indexOf(t.src));
    });
})();


/* =========================================================
   Live-Suche (Overlay + AJAX)
   ========================================================= */
(function () {
    var overlay = document.getElementById('searchOverlay');
    if (!overlay) return;
    var input = document.getElementById('searchInput');
    var results = document.getElementById('searchResults');
    var timer = null;

    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function open() { overlay.hidden = false; input.value = ''; results.innerHTML = ''; setTimeout(function () { input.focus(); }, 50); }
    function close() { overlay.hidden = true; }

    document.getElementById('searchToggle').addEventListener('click', open);
    document.getElementById('searchClose').addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !overlay.hidden) close(); });

    input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 2) { results.innerHTML = ''; return; }
        timer = setTimeout(function () {
            fetch('api/search.php?q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (items) {
                    if (!items.length) { results.innerHTML = '<div class="search-empty">Keine Ergebnisse</div>'; return; }
                    results.innerHTML = items.map(function (it) {
                        return '<a class="search-item" href="' + it.url + '"><span class="search-icon">' + it.icon + '</span>' +
                            '<div><strong>' + esc(it.title) + '</strong>' +
                            (it.sub ? '<span class="muted small"> ' + esc(it.sub) + '</span>' : '') + '</div></a>';
                    }).join('');
                }).catch(function () {});
        }, 250);
    });
})();


/* Spoiler umschalten */
document.addEventListener('click', function (e) {
    var head = e.target.closest('.spoiler-head');
    if (head) head.parentElement.classList.toggle('open');
});


/* YouTube-Vorschau: Klick laedt den Player
   WICHTIG: iframe wird ERST ins DOM gehaengt, DANN die src gesetzt.
   Das verhindert den YouTube-Fehler 153 (Player-Konfiguration bricht ab,
   wenn die URL gleichzeitig mit dem Element gesetzt wird). */
/* YTV=4 - Offizieller IFrame-API-Player mit Fehler-Diagnose */
var ytApiLoading = false, ytApiReady = false, ytApiCbs = [];
function withYtApi(cb) {
    if (window.YT && YT.Player) { cb(); return; }
    ytApiCbs.push(cb);
    if (!ytApiLoading) {
        ytApiLoading = true;
        var tag = document.createElement('script');
        tag.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(tag);
        window.onYouTubeIframeAPIReady = function () {
            ytApiReady = true;
            ytApiCbs.forEach(function (f) { f(); });
        };
    }
}

document.addEventListener('click', function (e) {
    var box = e.target.closest('.yt-preview');
    if (!box || box.classList.contains('playing')) return;
    var id = (box.dataset.id || '').trim();
    if (!id || id.length !== 11) return;
    box.classList.add('playing');
    var holder = document.createElement('div');
    holder.className = 'yt-player-holder';
    box.textContent = '';
    box.appendChild(holder);
    withYtApi(function () {
        new YT.Player(holder, {
            videoId: id,
            playerVars: { autoplay: 1, mute: 1, rel: 0, playsinline: 1 },
            events: {
                onError: function (ev) {
                    var code = ev.data;
                    box.innerHTML = '<div class="yt-fallback">⚠️ Video kann hier nicht abgespielt werden<br>' +
                        '<span class="muted small">Fehlercode: ' + code + '</span><br>' +
                        '<a href="https://www.youtube.com/watch?v=' + encodeURIComponent(id) + '" target="_blank" rel="noopener">▶ Auf YouTube ansehen</a></div>';
                }
            }
        });
    });
});


/* Tastaturkuerzel: Strg+Enter sendet, / oeffnet die Suche */
document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        var ta = e.target.closest('textarea');
        if (ta) {
            var form = ta.closest('form');
            if (form) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.submit(); }
        }
    }
    if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) {
        var st = document.getElementById('searchToggle');
        if (st) { e.preventDefault(); st.click(); }
    }
});

/* Unread-Counter im Favicon (roter Badge) */
(function () {
    var canvas = document.createElement('canvas');
    canvas.width = 64; canvas.height = 64;
    var ctx = canvas.getContext('2d');
    var baseIcon = document.querySelector('link[rel="icon"]');
    var baseHref = baseIcon ? baseIcon.href : '';
    var img = new Image();
    img.crossOrigin = 'anonymous';
    img.src = baseHref || '/assets/img/icon-192.png';
    var lastCount = -1;
    function draw(count) {
        ctx.clearRect(0, 0, 64, 64);
        try { if (img.complete && img.naturalWidth) ctx.drawImage(img, 0, 0, 64, 64); } catch (e) {}
        if (count > 0) {
            ctx.beginPath();
            ctx.arc(48, 16, 14, 0, 7);
            ctx.fillStyle = '#e74c3c';
            ctx.fill();
            ctx.fillStyle = '#fff';
            ctx.font = 'bold 18px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(count > 9 ? '9+' : String(count), 48, 17);
        }
        var link = document.querySelector('link[rel="icon"]') || document.createElement('link');
        link.rel = 'icon';
        link.href = canvas.toDataURL('image/png');
        document.head.appendChild(link);
    }
    function poll() {
        fetch('api/notifications.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var c = d.unread || 0;
                if (c !== lastCount) { lastCount = c; draw(c); }
            }).catch(function () {});
    }
    if (img.src) { setTimeout(poll, 1500); setInterval(poll, 30000); }
})();
