<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
$current = '';
$pageTitle = 'Suche';
$me = current_member();

$q = trim((string)($_GET['q'] ?? ''));
$results = ['users' => [], 'wall' => [], 'forum' => [], 'resources' => []];

if ($q !== '') {
    $like = "%$q%";
    $results['users'] = db()->prepare("SELECT username, display_name, avatar, avatar_color, bio FROM users
        WHERE is_public = 1 AND (username LIKE ? OR display_name LIKE ? OR bio LIKE ?) LIMIT 10");
    $results['users']->execute([$like, $like, $like]);
    $results['users'] = $results['users']->fetchAll();

    $results['wall'] = db()->prepare("SELECT w.id, w.content, w.created_at, u.username, u.display_name
        FROM wall_posts w JOIN users u ON u.id = w.user_id
        WHERE w.content LIKE ? ORDER BY w.created_at DESC LIMIT 10");
    $results['wall']->execute([$like]);
    $results['wall'] = $results['wall']->fetchAll();

    $results['forum'] = db()->prepare("SELECT t.id, t.title, u.username, u.display_name
        FROM forum_topics t JOIN users u ON u.id = t.user_id
        WHERE t.title LIKE ? ORDER BY t.created_at DESC LIMIT 10");
    $results['forum']->execute([$like]);
    $results['forum'] = $results['forum']->fetchAll();

    if (plugin_active('resources')) {
        $results['resources'] = db()->prepare("SELECT id, title, description FROM resources
            WHERE active = 1 AND (title LIKE ? OR description LIKE ?) LIMIT 10");
        $results['resources']->execute([$like, $like]);
        $results['resources'] = $results['resources']->fetchAll();
    }
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal"><h1>🔍 Suche</h1></div></section>
<section class="section"><div class="container">
    <form method="get" class="search-bar reveal" style="margin-bottom:2rem">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Mitglieder, Beiträge, Themen, Downloads durchsuchen …" required>
        <button class="btn" type="submit">Suchen</button>
    </form>

    <?php if ($q === ''): ?>
        <div class="card glass reveal"><p class="muted">Tippe oben, wonach du suchst.</p></div>
    <?php else: ?>
        <?php if (!array_filter($results)): ?>
            <div class="card glass reveal"><p class="muted">Keine Ergebnisse für „<?= e($q) ?>".</p></div>
        <?php endif; ?>

        <?php if ($results['users']): ?>
        <div class="card glass reveal" style="margin-bottom:1.2rem">
            <h3>👤 Mitglieder (<?= count($results['users']) ?>)</h3>
            <?php foreach ($results['users'] as $u): ?>
            <a class="topic-row" href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($u['username']) ?>">
                <?= avatar_html($u) ?>
                <div class="topic-meta"><strong><?= e($u['display_name'] ?: $u['username']) ?></strong>
                <span class="muted small"><?= e(mb_strimwidth($u['bio'] ?? '', 0, 60, '…')) ?></span></div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($results['wall']): ?>
        <div class="card glass reveal" style="margin-bottom:1.2rem">
            <h3>📋 Wall-Beiträge (<?= count($results['wall']) ?>)</h3>
            <?php foreach ($results['wall'] as $w): ?>
            <a class="topic-row" href="<?= SITE_URL ?>/wall.php#post-<?= (int)$w['id'] ?>">
                <div class="topic-meta"><strong><?= e(mb_strimwidth($w['content'], 0, 70, '…')) ?></strong>
                <span class="muted small">von <?= e($w['display_name'] ?: $w['username']) ?></span></div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($results['forum']): ?>
        <div class="card glass reveal" style="margin-bottom:1.2rem">
            <h3>🗂️ Forum (<?= count($results['forum']) ?>)</h3>
            <?php foreach ($results['forum'] as $t): ?>
            <a class="topic-row" href="<?= SITE_URL ?>/forum_topic.php?id=<?= (int)$t['id'] ?>">
                <div class="topic-meta"><strong><?= e($t['title']) ?></strong>
                <span class="muted small">von <?= e($t['display_name'] ?: $t['username']) ?></span></div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($results['resources']): ?>
        <div class="card glass reveal">
            <h3>📦 Ressourcen (<?= count($results['resources']) ?>)</h3>
            <?php foreach ($results['resources'] as $r): ?>
            <a class="topic-row" href="<?= SITE_URL ?>/ressourcen.php">
                <div class="topic-meta"><strong><?= e($r['title']) ?></strong>
                <span class="muted small"><?= e(mb_strimwidth(strip_tags($r['description'] ?? ''), 0, 60, '…')) ?></span></div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
