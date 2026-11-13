<?php
require_once __DIR__ . '/includes/functions.php';
$current = 'projekte';

$projects = [];
try {
    $projects = db()->query('SELECT * FROM projects WHERE active = 1 ORDER BY display_order ASC, id DESC')->fetchAll();
} catch (Throwable $e) { /* vor Installation */ }

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container reveal">
        <h1>Projekte</h1>
        <p class="muted">Meine Projekte direkt von GitHub – mit Live-Daten wie Stars und Sprache.</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if (!$projects): ?>
            <div class="card glass reveal"><p>Noch keine Projekte vorhanden – diese werden über das Admin-Panel hinzugefügt.</p></div>
        <?php else: ?>
        <div class="grid grid-3" id="projectGrid">
            <?php foreach ($projects as $p): ?>
            <article class="card glass project-card reveal" data-github="<?= e(trim($p['github_repo'])) ?>">
                <div class="card-head">
                    <span class="gh-lang" data-field="language">···</span>
                    <h3 data-field="name"><?= e($p['title']) ?></h3>
                </div>
                <p data-field="description"><?= e($p['description']) ?></p>
                <div class="gh-stats">
                    <span title="Stars">⭐ <span data-field="stars">–</span></span>
                    <span title="Forks">⑂ <span data-field="forks">–</span></span>
                    <span title="Offene Issues">◎ <span data-field="issues">–</span></span>
                </div>
                <div class="card-actions">
                    <?php if (trim($p['github_repo']) !== ''): ?>
                    <a class="btn btn-sm" data-field="link" href="https://github.com/<?= e($p['github_repo']) ?>" target="_blank" rel="noopener">Auf GitHub ansehen</a>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <p class="muted small center">Repository-Daten werden live von der GitHub-API geladen.</p>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
