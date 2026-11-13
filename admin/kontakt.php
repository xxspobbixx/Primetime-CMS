<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
$adminCurrent = 'kontakt';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'read') {
        db()->prepare('UPDATE contact_messages SET is_read = 1 WHERE id = ?')->execute([(int)$_POST['id']]);
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM contact_messages WHERE id = ?')->execute([(int)$_POST['id']]);
    }
    header('Location: kontakt.php');
    exit;
}

$msgs = db()->query('SELECT * FROM contact_messages ORDER BY id DESC LIMIT 50')->fetchAll();
$unread = (int)db()->query('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0')->fetchColumn();

include __DIR__ . '/_header.php';
?>
<h1>✉️ Kontakt-Nachrichten</h1>
<p class="muted">Nachrichten aus dem Kontaktformular (<?= $unread ?> ungelesen).</p>

<div class="card glass">
    <?php if (!$msgs): ?><p class="muted">Keine Nachrichten vorhanden.</p><?php endif; ?>
    <?php foreach ($msgs as $m): ?>
    <div class="flash <?= $m['is_read'] ? '' : 'flash-success' ?>" style="margin-bottom:.8rem">
        <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap">
            <strong><?= e($m['subject'] ?: '(kein Betreff)') ?></strong>
            <span class="muted small"><?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></span>
        </div>
        <p class="small" style="margin:.4rem 0">Von: <?= e($m['name']) ?> &lt;<?= e($m['email']) ?>&gt;</p>
        <p style="margin:.4rem 0"><?= nl2br(e($m['message'])) ?></p>
        <div style="display:flex;gap:.5rem;margin-top:.5rem">
            <a class="btn btn-ghost btn-sm" href="mailto:<?= e($m['email']) ?>?subject=<?= urlencode('AW: ' . ($m['subject'] ?: 'Kontakt')) ?>">↩ Antworten</a>
            <?php if (!$m['is_read']): ?>
            <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="read">
                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                <button class="btn btn-sm" type="submit">✔ Gelesen</button>
            </form>
            <?php endif; ?>
            <form method="post" class="inline" data-confirm="Nachricht löschen?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">Löschen</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
