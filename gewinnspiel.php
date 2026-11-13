<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
if (!plugin_active('giveaway')) {
    flash('error', 'Diese Seite ist derzeit deaktiviert.');
    header('Location: ' . SITE_URL . '/wall.php');
    exit;
}
$current = 'giveaway';
$pageTitle = 'Gewinnspiel';
$me = current_member();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'join') {
    csrf_check();
    $gid = (int)$_POST['id'];
    $active = db()->query('SELECT id FROM giveaways WHERE id = ' . $gid . ' AND active = 1 AND winner_id IS NULL')->fetch();
    if ($active) {
        db()->prepare('INSERT IGNORE INTO giveaway_entries (giveaway_id, user_id) VALUES (?, ?)')->execute([$gid, $me['id']]);
        flash('success', 'Du bist dabei – viel Glück!');
    }
    header('Location: gewinnspiel.php');
    exit;
}

$giveaways = db()->query("SELECT g.*, u.display_name AS host_name, u.username AS host_username,
    (SELECT COUNT(*) FROM giveaway_entries e WHERE e.giveaway_id = g.id) AS entries,
    EXISTS(SELECT 1 FROM giveaway_entries e WHERE e.giveaway_id = g.id AND e.user_id = " . (int)$me['id'] . ") AS me_joined,
    (SELECT username FROM users WHERE id = g.winner_id) AS winner_name
    FROM giveaways g JOIN users u ON u.id = g.created_by
    ORDER BY g.id DESC LIMIT 5")->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal"><h1>🎁 Gewinnspiele</h1>
<p class="muted">Mitmachen, gewinnen, feiern!</p></div></section>
<section class="section"><div class="container narrow">
    <?php if (!$giveaways): ?>
        <div class="card glass reveal"><p class="muted">Aktuell läuft kein Gewinnspiel.</p></div>
    <?php endif; ?>
    <?php foreach ($giveaways as $g): ?>
    <div class="card glass reveal" style="margin-bottom:1.2rem">
        <div class="card-head"><h3><?= e($g['title']) ?></h3>
            <?php if ($g['winner_id']): ?><span class="badge">beendet</span>
            <?php elseif ($g['active']): ?><span class="badge badge-green">läuft</span><?php endif; ?></div>
        <p class="muted small">Hosted von <?= e($g['host_name'] ?: $g['host_username']) ?> · <?= (int)$g['entries'] ?> Teilnehmer</p>
        <?php if ($g['winner_id']): ?>
            <div class="flash flash-success">🏆 Gewinner: <strong><?= e($g['winner_name']) ?></strong></div>
        <?php elseif ($g['active']): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                <?php if ($g['me_joined']): ?>
                    <button class="btn btn-ghost btn-sm" disabled>✔ Du bist dabei</button>
                <?php else: ?>
                    <button class="btn btn-sm" name="action" value="join">Jetzt teilnehmen</button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
