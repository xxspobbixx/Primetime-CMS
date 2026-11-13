<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
if (!is_member()) { echo '[]'; exit; }
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo '[]'; exit; }
$stmt = db()->prepare("SELECT username, display_name, avatar_color FROM users
                       WHERE (username LIKE ? OR display_name LIKE ?) AND is_public = 1
                       ORDER BY username LIMIT 6");
$like = "$q%";
$stmt->execute([$like, $like]);
$out = [];
foreach ($stmt->fetchAll() as $u) {
    $out[] = [
        'username' => $u['username'],
        'name' => $u['display_name'] ?: $u['username'],
        'color' => $u['avatar_color'],
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
