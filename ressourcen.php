<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
if (!plugin_active('resources')) {
    flash('error', 'Diese Seite ist derzeit deaktiviert.');
    header('Location: ' . SITE_URL . '/wall.php');
    exit;
}
$current = 'resources';
$pageTitle = 'Ressourcen';

$items = db()->query('SELECT * FROM resources WHERE active = 1 ORDER BY created_at DESC')->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container reveal"><h1>📦 Ressourcen</h1>
    <p class="muted">Nützliche Dateien zum Herunterladen – von der Community, für die Community.</p></div>
</section>
<section class="section">
    <div class="container">
        <?php if (!$items): ?>
            <div class="card glass reveal"><p class="muted">Noch keine Ressourcen vorhanden – schau bald wieder vorbei!</p></div>
        <?php else: ?>
        <div class="grid grid-3">
            <?php foreach ($items as $r): ?>
            <article class="card glass resource-card reveal">
                <?php if (!empty($r['image'])): ?>
                <div class="resource-thumb">
                    <img src="<?= SITE_URL ?>/uploads/resources/<?= rawurlencode($r['image']) ?>" alt="<?= e($r['title']) ?>" loading="lazy">
                </div>
                <?php endif; ?>
                <h3><?= e($r['title']) ?></h3>
                <?php if (!empty($r['description'])): ?>
                    <p class="muted small resource-desc"><?= nl2br(e($r['description'])) ?></p>
                <?php endif; ?>
                <div class="resource-meta">
                    <span class="badge">⬇ <?= (int)$r['downloads'] ?> Downloads</span>
                    <span class="muted small"><?= date('d.m.Y', strtotime($r['created_at'])) ?></span>
                </div>
                <a class="btn btn-sm" href="<?= SITE_URL ?>/download.php?id=<?= (int)$r['id'] ?>">Herunterladen</a>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
