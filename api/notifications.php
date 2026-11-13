<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
if (!is_member()) { http_response_code(401); echo json_encode(['unread' => 0, 'items' => []]); exit; }
$me = current_member();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'read_all') {
        csrf_check();
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$me['id']]);
    }

    $stmt = db()->prepare("SELECT n.*, a.username AS actor_name, a.display_name AS actor_display, a.avatar AS actor_avatar, a.avatar_color AS actor_color
                           FROM notifications n LEFT JOIN users a ON a.id = n.actor_id
                           WHERE n.user_id = ? ORDER BY n.created_at DESC LIMIT 15");
    $stmt->execute([$me['id']]);
    $items = [];
    foreach ($stmt->fetchAll() as $n) {
        $items[] = [
            'text' => mb_convert_encoding($n['text'], 'UTF-8', 'UTF-8'),
            'link' => $n['link'],
            'time' => chat_time($n['created_at']),
            'read' => (bool)$n['is_read'],
            'avatar' => $n['actor_id'] ? avatar_html(['display_name' => $n['actor_display'], 'username' => $n['actor_name'], 'avatar' => $n['actor_avatar'], 'avatar_color' => $n['actor_color']]) : '<span class="avatar" style="background:#666">🔔</span>',
        ];
    }
    $json = json_encode(['unread' => unread_notification_count((int)$me['id']), 'items' => $items], JSON_UNESCAPED_UNICODE);
    echo $json !== false ? $json : '{"unread":0,"items":[]}';
} catch (Throwable $ex) {
    http_response_code(500);
    echo json_encode(['error' => $ex->getMessage()]);
}
