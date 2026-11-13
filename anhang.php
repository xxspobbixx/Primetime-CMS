<?php
require_once __DIR__ . '/includes/functions.php';
require_member();

$id = (int)($_GET['id'] ?? 0);
$me = current_member();
$stmt = db()->prepare('SELECT * FROM messages WHERE id = ? AND (sender_id = ? OR receiver_id = ?)');
$stmt->execute([$id, $me['id'], $me['id']]);
$m = $stmt->fetch();

if (!$m || empty($m['attachment'])) {
    flash('error', 'Anhang nicht gefunden.');
    header('Location: ' . SITE_URL . '/nachrichten.php');
    exit;
}
$att = json_decode($m['attachment'], true);
$path = APP_ROOT . '/uploads/wall/' . basename($att['f'] ?? '');
if (!is_file($path)) {
    flash('error', 'Datei nicht mehr vorhanden.');
    header('Location: ' . SITE_URL . '/nachrichten.php');
    exit;
}
$fname = str_replace(['"', "\r", "\n"], '_', $att['n'] ?? 'anhang');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fname . '"; filename*=UTF-8\'\'' . rawurlencode($fname));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
