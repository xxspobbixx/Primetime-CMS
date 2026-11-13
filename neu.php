<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
$current = 'neu';
$pageTitle = 'Was ist neu?';
$me = current_member();

$wall = db()->query("SELECT w.id, w.content, w.created_at, u.username, u.display_name FROM wall_posts w
    JOIN users u ON u.id = w.user_id ORDER BY w.created_at DESC LIMIT 8")->fetchAll();
$forum = db()->query("SELECT p.id, p.content, p.created_at, t.id AS topic_id, t.title, u.username, u.display_name
    FROM forum_posts p JOIN forum_topics t ON t.id = p.topic_id JOIN users u ON u.id = p.user_id
    ORDER BY p.created_at DESC LIMIT 8")->fetchAll();
$groups = db()->query("SELECT p.id, p.content, p.created_at, g.id AS group_id, g.name, u.username, u.display_name
    FROM group_posts p JOIN groups_tbl g ON g.id = p.group_id JOIN users u ON u.id = p.user_id
    ORDER BY p.created_at DESC LIMIT 8")->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal"><h1>⚡ Was ist neu?</h1>
<p class="muted">Die neuesten Aktivitäten aus der gesamten Community – auf einen Blick.</p></div></section>
<section class="section"><div class="container">
    <div class="grid grid-3">
        <div class="card glass reveal">
            <h3>📋 Wall</h3>
            <?php foreach ($wall as $w): ?>
            <a class="topic-row" href="<?= SITE_URL ?>/wall.php#post-<?= (int)$w['id'] ?>">
                <div class="topic-meta"><strong><?= e(mb_strimwidth($w['content'], 0, 45, '…')) ?></strong>
                <span class="muted small"><?= e($w['display_name'] ?: $w['username']) ?> · <?= chat_time($w['created_at']) ?></span></div>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="card glass reveal">
            <h3>🗂️ Forum</h3>
            <?php foreach ($forum as $f): ?>
            <a class="topic-row" href="<?= SITE_URL ?>/forum_topic.php?id=<?= (int)$f['topic_id'] ?>">
                <div class="topic-meta"><strong><?= e($f['title']) ?></strong>
                <span class="muted small"><?= e($f['display_name'] ?: $f['username']) ?> · <?= chat_time($f['created_at']) ?></span></div>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="card glass reveal">
            <h3>👥 Gruppen</h3>
            <?php foreach ($groups as $g): ?>
            <a class="topic-row" href="<?= SITE_URL ?>/gruppe.php?id=<?= (int)$g['group_id'] ?>">
                <div class="topic-meta"><strong><?= e($g['name']) ?></strong>
                <span class="muted small"><?= e($g['display_name'] ?: $g['username']) ?> · <?= chat_time($g['created_at']) ?></span></div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
