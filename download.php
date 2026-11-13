<?php
require_once __DIR__ . '/includes/functions.php';
require_member();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM resources WHERE id = ? AND active = 1');
$stmt->execute([$id]);
$r = $stmt->fetch();

if (!$r) {
    flash('error', 'Ressource nicht gefunden.');
    header('Location: ' . SITE_URL . '/ressourcen.php');
    exit;
}

$path = APP_ROOT . '/uploads/resources/' . $r['file'];
if (!is_file($path)) {
    flash('error', 'Datei ist nicht mehr verfügbar.');
    header('Location: ' . SITE_URL . '/ressourcen.php');
    exit;
}

// Zähler erhöhen
db()->prepare('UPDATE resources SET downloads = downloads + 1 WHERE id = ?')->execute([$id]);

// Datei ausliefern (forced download)
$fname = $r['original_name'] ?: ('download-' . $id);
$fname = str_replace(['"', "\r", "\n"], '_', $fname);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fname . '"; filename*=UTF-8\'\'' . rawurlencode($fname));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
readfile($path);
exit;
