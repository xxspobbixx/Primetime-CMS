<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
if (!plugin_active('events')) {
    flash('error', 'Diese Seite ist derzeit deaktiviert.');
    header('Location: ' . SITE_URL . '/wall.php');
    exit;
}
$current = 'events';
$pageTitle = 'Events';
$me = current_member();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'join') {
        db()->prepare('INSERT IGNORE INTO event_participants (event_id, user_id) VALUES (?, ?)')
            ->execute([(int)$_POST['id'], $me['id']]);
        flash('success', 'Du nimmst teil!');
    } elseif (($_POST['action'] ?? '') === 'leave') {
        db()->prepare('DELETE FROM event_participants WHERE event_id = ? AND user_id = ?')
            ->execute([(int)$_POST['id'], $me['id']]);
        flash('success', 'Teilnahme zurückgezogen.');
    }
    header('Location: events.php');
    exit;
}

$events = db()->query("SELECT e.*, u.username, u.display_name,
    (SELECT COUNT(*) FROM event_participants p WHERE p.event_id = e.id) AS participants,
    EXISTS(SELECT 1 FROM event_participants p WHERE p.event_id = e.id AND p.user_id = " . (int)$me['id'] . ") AS me_joined
    FROM events e JOIN users u ON u.id = e.created_by
    WHERE e.event_date > NOW() - INTERVAL 1 DAY
    ORDER BY e.event_date ASC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal"><h1>📅 Events</h1>
<p class="muted">Streams, Turniere &amp; Treffen – tritt bei!</p></div></section>
<section class="section"><div class="container">
    <?php if (!$events): ?>
        <div class="card glass reveal"><p class="muted">Aktuell keine Events geplant.</p></div>
    <?php endif; ?>
    <?php foreach ($events as $ev): ?>
    <div class="card glass reveal" style="margin-bottom:1.2rem">
        <div class="card-head">
            <h3><?= e($ev['title']) ?></h3>
            <span class="badge badge-green">📅 <?= date('d.m.Y H:i', strtotime($ev['event_date'])) ?></span>
        </div>
        <?php if ($ev['description']): ?><div class="prose"><?= nl2br(e($ev['description'])) ?></div><?php endif; ?>
        <p class="muted small">Erstellt von <?= e($ev['display_name'] ?: $ev['username']) ?> · 👥 <?= (int)$ev['participants'] ?> Teilnehmer</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
            <?php if ($ev['me_joined']): ?>
                <button class="btn btn-ghost btn-sm" name="action" value="leave">✔ Du nimmst teil – absagen</button>
            <?php else: ?>
                <button class="btn btn-sm" name="action" value="join">Ich nehme teil!</button>
            <?php endif; ?>
        </form>
    </div>
    <?php endforeach; ?>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
