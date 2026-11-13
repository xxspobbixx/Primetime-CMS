<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

function jout($data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        // Bei ungültigem UTF-8: Texte bereinigen und erneut versuchen
        array_walk_recursive($data, function (&$v) {
            if (is_string($v)) $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
        });
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    echo $json !== false ? $json : '{"error":"JSON-Fehler"}';
}

if (!is_member()) { http_response_code(401); jout(['error' => 'Nicht angemeldet']); exit; }
$me = current_member();

try {
    function channel_by_slug(string $slug): ?array
    {
        $stmt = db()->prepare('SELECT * FROM chat_channels WHERE slug = ? AND active = 1');
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }
    function msg_json(array $m): array
    {
        $own = (int)$m['user_id'] === (int)$_SESSION['member_id'];
        return [
            'id' => (int)$m['id'],
            'html' => '<div class="chat-row' . ($own ? ' own' : '') . '" data-id="' . (int)$m['id'] . '">'
                . '<span class="chat-avatar">' . avatar_html(['display_name' => $m['display_name'], 'username' => $m['username'], 'avatar' => $m['avatar'], 'avatar_color' => $m['avatar_color']]) . '</span>'
                . '<div class="chat-bubble' . ($own ? ' own' : '') . '">'
                . '<span class="bubble-author"' . name_color_style($m) . '>' . e($m['display_name'] ?: $m['username']) . ' · ' . date('H:i', strtotime($m['created_at'])) . '</span>'
                . (!empty($m['pinned']) ? '<span class="badge badge-green" style="margin-right:.3rem">📌</span>' : '')
            . nl2br(e($m['message'])) . '</div></div>',
            'time' => chat_time($m['created_at']),
        ];
    }
    function online_json(): array
    {
        $out = [];
        foreach (online_users() as $u) {
            $out[] = ['html' => avatar_html($u) . '<span>' . e($u['display_name'] ?: $u['username']) . '</span>' . ($u['role'] !== 'member' ? '<span class="badge">' . e($u['role']) . '</span>' : '')];
        }
        return $out;
    }

    // ---------- GET: neue Nachrichten + Online-Liste ----------
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $channel = channel_by_slug(trim((string)($_GET['channel'] ?? '')));
        if (!$channel) { jout(['error' => 'Channel nicht gefunden', 'online' => online_json()]); exit; }
        $after = max(0, (int)($_GET['after'] ?? 0));
        $stmt = db()->prepare("SELECT m.*, u.username, u.display_name, u.avatar, u.avatar_color, u.name_color
                               FROM chat_messages m JOIN users u ON u.id = m.user_id
                               WHERE m.channel_id = ? AND m.id > ? AND u.shadow_banned = 0 ORDER BY m.id ASC LIMIT 100");
        $stmt->execute([$channel['id'], $after]);
        jout(['messages' => array_map('msg_json', $stmt->fetchAll()), 'online' => online_json(), 'me' => $me['username']]);
        exit;
    }

    // ---------- POST ----------
    csrf_check();
    $action = $_POST['action'] ?? '';
    $channel = channel_by_slug(trim((string)($_POST['channel'] ?? '')));
    if (!$channel && $action !== 'toggle_online') { jout(['error' => 'Channel nicht gefunden']); exit; }

    if ($action === 'send') {
        if (plugin_active('rate_limit') && rate_limited((int)$me['id'], 'chat_messages', (int)setting('plugin_rate_limit_chat', '10'))) {
            jout(['error' => 'Zu viele Nachrichten – bitte etwas langsamer.']); exit;
        }
        // Slow-Mode (Sekunden zwischen Nachrichten, 0 = aus)
        if ((int)$channel['slow_mode'] > 0) {
            $s = db()->prepare('SELECT created_at FROM chat_messages WHERE user_id = ? AND channel_id = ? ORDER BY id DESC LIMIT 1');
            $s->execute([$me['id'], $channel['id']]);
            $last = $s->fetchColumn();
            if ($last && strtotime($last) > time() - (int)$channel['slow_mode']) {
                jout(['error' => '⏱ Slow-Mode: bitte ' . (int)$channel['slow_mode'] . 's warten!']); exit;
            }
        }
        $text = filter_words(trim((string)($_POST['message'] ?? '')));
        if ($text === '' || mb_strlen($text) > 1000) { jout(['error' => 'Nachricht leer oder zu lang']); exit; }
        check_badges((int)$me['id']);
        // @Erwähnungen im Chat -> Benachrichtigung
        if (preg_match_all('/@([A-Za-z0-9_.\-]{3,30})/', $text, $mm)) {
            foreach (array_unique($mm[1]) as $mun) {
                $mu = find_user_by_username($mun);
                if ($mu) {
                    notify((int)$mu['id'], 'chat_mention', (int)$me['id'],
                        ($me['display_name'] ?: $me['username']) . ' hat dich im Chat erwähnt.',
                        SITE_URL . '/chat.php?channel=' . urlencode($channel['slug']));
                }
            }
        }
        if (!(is_shadow_hidden((int)$me['id'], $me) && $me['shadow_banned'] ?? false)) {
            db()->prepare('INSERT INTO chat_messages (channel_id, user_id, message) VALUES (?, ?, ?)')
                ->execute([$channel['id'], $me['id'], $text]);
        }
        $id = (int)db()->lastInsertId();
        $stmt = db()->prepare("SELECT m.*, u.username, u.display_name, u.avatar, u.avatar_color FROM chat_messages m JOIN users u ON u.id = m.user_id WHERE m.id = ?");
        $stmt->execute([$id]);
        jout(['ok' => true, 'message' => msg_json($stmt->fetch())]);
    } elseif ($action === 'delete') {
        if (!can_mod_channel($me, $channel)) { jout(['error' => 'Keine Moderationsrechte']); exit; }
        db()->prepare('DELETE FROM chat_messages WHERE id = ? AND channel_id = ?')->execute([(int)$_POST['id'], $channel['id']]);
        jout(['ok' => true]);
    } elseif ($action === 'pin') {
        if (!can_mod_channel($me, $channel)) { jout(['error' => 'Keine Moderationsrechte']); exit; }
        db()->prepare('UPDATE chat_messages SET pinned = 1 - pinned WHERE id = ? AND channel_id = ?')
            ->execute([(int)$_POST['id'], $channel['id']]);
        jout(['ok' => true]);
    } elseif ($action === 'react') {
        $msgId = (int)($_POST['id'] ?? 0);
        $emoji = (string)($_POST['emoji'] ?? '');
        if (in_array($emoji, ['👍', '❤️', '😂'], true)) {
            $stmt = db()->prepare('SELECT 1 FROM chat_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?');
            $stmt->execute([$msgId, $me['id'], $emoji]);
            if ($stmt->fetch()) {
                db()->prepare('DELETE FROM chat_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?')
                    ->execute([$msgId, $me['id'], $emoji]);
            } else {
                db()->prepare('INSERT IGNORE INTO chat_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)')
                    ->execute([$msgId, $me['id'], $emoji]);
            }
        }
        jout(['ok' => true]);
    } elseif ($action === 'edit') {
        $msgId = (int)($_POST['id'] ?? 0);
        $text = filter_words(trim((string)($_POST['message'] ?? '')));
        if ($text === '' || mb_strlen($text) > 1000) { jout(['error' => 'Leer oder zu lang']); exit; }
        $stmt = db()->prepare("SELECT user_id, created_at FROM chat_messages WHERE id = ? AND channel_id = ?");
        $stmt->execute([$msgId, $channel['id']]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['user_id'] !== (int)$me['id'] || strtotime($row['created_at']) < time() - 600) {
            jout(['error' => 'Bearbeiten nicht erlaubt (nur eigene, max. 10 Min.)']); exit;
        }
        db()->prepare('UPDATE chat_messages SET message = ?, edited = 1 WHERE id = ?')->execute([$text, $msgId]);
        jout(['ok' => true]);
    } elseif ($action === 'delete') {
        // bereits vorhanden (Moderation)
        jout(['ok' => true]);
    } elseif ($action === 'report') {
        $msgId = (int)($_POST['id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $details = trim((string)($_POST['details'] ?? ''));
        $stmt = db()->prepare('SELECT id FROM chat_messages WHERE id = ? AND channel_id = ?');
        $stmt->execute([$msgId, $channel['id']]);
        if (!$stmt->fetch()) { jout(['error' => 'Nachricht nicht gefunden']); exit; }
        $stmt = db()->prepare("SELECT id FROM chat_reports WHERE message_id = ? AND reporter_id = ? AND status = 'open'");
        $stmt->execute([$msgId, $me['id']]);
        if ($stmt->fetch()) { jout(['error' => 'Bereits gemeldet']); exit; }
        db()->prepare('INSERT INTO chat_reports (message_id, reporter_id, reason, details) VALUES (?, ?, ?, ?)')
            ->execute([$msgId, $me['id'], $reason !== '' ? $reason : 'Sonstiges', $details]);
        jout(['ok' => true]);
    } elseif ($action === 'toggle_online') {
        db()->prepare('UPDATE users SET show_online = 1 - show_online WHERE id = ?')->execute([$me['id']]);
        $stmt = db()->prepare('SELECT show_online FROM users WHERE id = ?');
        $stmt->execute([$me['id']]);
        jout(['ok' => true, 'visible' => (bool)$stmt->fetchColumn()]);
    } else {
        jout(['error' => 'Unbekannte Aktion']);
    }
} catch (Throwable $ex) {
    http_response_code(500);
    jout(['error' => 'Serverfehler: ' . $ex->getMessage()]);
}
