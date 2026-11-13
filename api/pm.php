<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

if (!is_member()) { http_response_code(401); echo json_encode(['error' => 'Nicht angemeldet']); exit; }
function jout($data): void {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        array_walk_recursive($data, function (&$v) { if (is_string($v)) $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8'); });
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    echo $json !== false ? $json : '{"error":"JSON-Fehler"}';
}
$me = current_member();

try {
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Neue Nachrichten abrufen
    $partner = find_user_by_username(trim((string)($_GET['with'] ?? '')));
    if (!$partner || (int)$partner['id'] === (int)$me['id']) { echo json_encode(['messages' => []]); exit; }
    $after = max(0, (int)($_GET['after'] ?? 0));
    $stmt = db()->prepare('SELECT m.id, m.sender_id, m.message, m.created_at, m.read_at
                           FROM messages m
                           WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
                             AND m.id > ? ORDER BY m.id ASC LIMIT 100');
    $stmt->execute([$me['id'], $partner['id'], $partner['id'], $me['id'], $after]);
    $out = [];
    foreach ($stmt->fetchAll() as $m) {
        $out[] = [
            'id' => (int)$m['id'],
            'own' => (int)$m['sender_id'] === (int)$me['id'],
            'text' => $m['message'],
            'time' => chat_time($m['created_at']),
            'read' => $m['read_at'] !== null,
            'attachment' => $m['attachment'],
        ];
    }
    // Als gelesen markieren (Beim Empfänger)
    db()->prepare('UPDATE messages SET read_at = NOW() WHERE sender_id = ? AND receiver_id = ? AND read_at IS NULL')
        ->execute([$partner['id'], $me['id']]);
    // PM-Reaktionen (toggle)
if (isset($_GET['react']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $msgId = (int)$_POST['id'];
    $emoji = (string)($_POST['emoji'] ?? '');
    if (!in_array($emoji, ['👍', '❤️', '😂'], true)) { jout(['error' => 'Emoji ungültig']); exit; }
    $stmt = db()->prepare('SELECT id FROM messages WHERE id = ? AND (sender_id = ? OR receiver_id = ?)');
    $stmt->execute([$msgId, $me['id'], $me['id']]);
    if (!$stmt->fetch()) { jout(['error' => 'Nachricht nicht gefunden']); exit; }
    $stmt = db()->prepare('SELECT 1 FROM pm_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?');
    $stmt->execute([$msgId, $me['id'], $emoji]);
    if ($stmt->fetch()) {
        db()->prepare('DELETE FROM pm_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?')->execute([$msgId, $me['id'], $emoji]);
    } else {
        db()->prepare('INSERT IGNORE INTO pm_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)')->execute([$msgId, $me['id'], $emoji]);
    }
    jout(['ok' => true]);
    exit;
}

jout(['messages' => $out]);
    exit;
}

// POST: Nachricht senden
csrf_check();
$toName = trim((string)($_POST['to'] ?? ''));
$text   = trim((string)($_POST['message'] ?? ''));
$to = $toName !== '' ? find_user_by_username($toName) : null;

if (!$to || (int)$to['id'] === (int)$me['id']) { jout(['error' => 'Empfänger nicht gefunden']); exit; }
// Block-Check in beide Richtungen
if (is_blocked((int)$to['id'], (int)$me['id'])) { jout(['error' => '🚫 Du wurdest von diesem Mitglied blockiert.']); exit; }
if (is_blocked((int)$me['id'], (int)$to['id'])) { jout(['error' => '🚫 Du hast dieses Mitglied blockiert.']); exit; }
// Attachment (optional)
$attachment = null;
if (!empty($_FILES['attachment']['name'])) {
    try {
        $f = save_resource_file($_FILES['attachment']); // aus pluginlib (alle Formate)
        $attachment = json_encode(['f' => $f['stored'], 'n' => $f['original']], JSON_UNESCAPED_UNICODE);
    } catch (RuntimeException $ex) {
        jout(['error' => 'Anhang: ' . $ex->getMessage()]); exit;
    }
}
if ($text === '' && $attachment === null) { jout(['error' => 'Nachricht oder Anhang erforderlich']); exit; }
if (mb_strlen($text) > 2000) { jout(['error' => 'Nachricht zu lang']); exit; }

db()->prepare('INSERT INTO messages (sender_id, receiver_id, message, attachment) VALUES (?, ?, ?, ?)')
    ->execute([$me['id'], $to['id'], $text !== '' ? $text : '', $attachment]);
// Unterhaltung für beide wieder sichtbar machen (falls geschlossen)
db()->prepare('DELETE FROM pm_hidden WHERE (user_id = ? AND partner_id = ?) OR (user_id = ? AND partner_id = ?)')
    ->execute([$me['id'], $to['id'], $to['id'], $me['id']]);
// Benachrichtigung an Empfänger
notify((int)$to['id'], 'message', (int)$me['id'],
    ($me['display_name'] ?: $me['username']) . ' hat dir eine Nachricht geschickt.',
    SITE_URL . '/nachrichten.php?with=' . urlencode($me['username']));

$id = (int)db()->lastInsertId();
jout(['ok' => true, 'message' => [
    'id' => $id, 'own' => true, 'text' => $text, 'time' => chat_time(date('Y-m-d H:i:s')), 'read' => false,
    'attachment' => $attachment,
]]);
} catch (Throwable $ex) {
    http_response_code(500);
    jout(['error' => 'Serverfehler: ' . $ex->getMessage()]);
}
