<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
if (!is_member()) { echo '[]'; exit; }
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo '[]'; exit; }
$like = "%$q%";
$out = [];

// Mitglieder
$stmt = db()->prepare("SELECT username, display_name, avatar_color FROM users
    WHERE is_public = 1 AND (username LIKE ? OR display_name LIKE ?) LIMIT 4");
$stmt->execute([$like, $like]);
foreach ($stmt->fetchAll() as $u) {
    $out[] = ['type' => 'user', 'icon' => '👤', 'title' => '@' . $u['username'],
        'sub' => $u['display_name'] !== $u['username'] ? $u['display_name'] : '',
        'url' => SITE_URL . '/profil.php?u=' . urlencode($u['username'])];
}
// Wall
$stmt = db()->prepare("SELECT w.id, LEFT(w.content, 60) AS snippet FROM wall_posts w
    WHERE w.content LIKE ? LIMIT 3");
$stmt->execute([$like]);
foreach ($stmt->fetchAll() as $w) {
    $out[] = ['type' => 'wall', 'icon' => '📋', 'title' => $w['snippet'] . '…',
        'sub' => 'Wall-Beitrag', 'url' => SITE_URL . '/wall.php#post-' . (int)$w['id']];
}
// Forum
$stmt = db()->prepare("SELECT t.id, t.title FROM forum_topics t WHERE t.title LIKE ? LIMIT 3");
$stmt->execute([$like]);
foreach ($stmt->fetchAll() as $t) {
    $out[] = ['type' => 'forum', 'icon' => '🗂️', 'title' => $t['title'], 'sub' => 'Forum-Thema',
        'url' => SITE_URL . '/forum_topic.php?id=' . (int)$t['id']];
}
// Gruppen
$stmt = db()->prepare("SELECT g.id, g.name FROM groups_tbl g WHERE g.name LIKE ? LIMIT 2");
$stmt->execute([$like]);
foreach ($stmt->fetchAll() as $g) {
    $out[] = ['type' => 'group', 'icon' => '👥', 'title' => $g['name'], 'sub' => 'Gruppe',
        'url' => SITE_URL . '/gruppe.php?id=' . (int)$g['id']];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
