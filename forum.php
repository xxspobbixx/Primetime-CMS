<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
$current = 'forum';
$pageTitle = 'Forum';
$me = current_member();

$allCats = db()->query("SELECT c.*,
    (SELECT COUNT(*) FROM forum_topics t WHERE t.category_id = c.id
        OR t.category_id IN (SELECT id FROM forum_categories sc WHERE sc.parent_id = c.id)) AS topic_count,
    (SELECT COUNT(*) FROM forum_posts p JOIN forum_topics t2 ON t2.id = p.topic_id
        WHERE t2.category_id = c.id OR t2.category_id IN (SELECT id FROM forum_categories sc WHERE sc.parent_id = c.id)) AS post_count,
    (SELECT t3.title FROM forum_topics t3 WHERE t3.category_id = c.id
        OR t3.category_id IN (SELECT id FROM forum_categories sc WHERE sc.parent_id = c.id)
        ORDER BY t3.last_post_at DESC LIMIT 1) AS last_topic
    FROM forum_categories c WHERE c.active = 1 ORDER BY c.display_order ASC, c.id ASC")->fetchAll();
// Aufbau: nur Kategorien (ohne Eltern) als Hauptkarten, Unterforen kommen in die Karte
$categories = [];
foreach ($allCats as $c) {
    if ((int)$c['parent_id'] === 0) $categories[] = $c;
}
$hasChildren = function (int $id) use ($allCats): bool {
    foreach ($allCats as $c) { if ((int)$c['parent_id'] === $id) return true; }
    return false;
};

$board = rank_leaderboard(10);

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container reveal"><h1>🗂️ Forum</h1>
    <p class="muted">Diskutiere mit der Community – sammle Punkte für dein Ranking!</p></div>
</section>
<section class="section">
    <div class="container">
        <div class="forum-layout">
            <div>
                <?php foreach ($categories as $cat): ?>
                <div class="card glass forum-cat reveal">
                    <div class="forum-cat-head">
                        <h3><?= e($cat['name']) ?></h3>
                        <span class="badge"><?= (int)$cat['topic_count'] ?> Themen · <?= (int)$cat['post_count'] ?> Beiträge</span>
                    </div>
                    <?php if ($cat['description']): ?><p class="muted small"><?= e($cat['description']) ?></p><?php endif; ?>
                    <?php if ($cat['last_topic']): ?><p class="small">🕐 Zuletzt: „<?= e($cat['last_topic']) ?>"</p><?php endif; ?>
                    <?php $isParent = $hasChildren((int)$cat['id']); ?>
                    <?php if (!$isParent): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/forum_new.php?category=<?= (int)$cat['id'] ?>">➕ Neues Thema</a>
                    <?php endif; ?>
                    <a class="btn btn-sm" href="<?= SITE_URL ?>/forum.php?cat=<?= (int)$cat['id'] ?>" style="margin-left:.5rem">Themen ansehen</a>
                    <?php foreach ($allCats as $sub): if ((int)$sub['parent_id'] !== (int)$cat['id']) continue; ?>
                    <div class="forum-sub">
                        <div class="forum-cat-head">
                            <h3>↳ <?= e($sub['name']) ?></h3>
                            <span class="badge"><?= (int)$sub['topic_count'] ?> Themen · <?= (int)$sub['post_count'] ?> Beiträge</span>
                        </div>
                        <?php if ($sub['description']): ?><p class="muted small"><?= e($sub['description']) ?></p><?php endif; ?>
                        <?php if ($sub['last_topic']): ?><p class="small">🕐 Zuletzt: „<?= e($sub['last_topic']) ?>"</p><?php endif; ?>
                        <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/forum_new.php?category=<?= (int)$sub['id'] ?>">➕ Neues Thema</a>
                        <a class="btn btn-sm" href="<?= SITE_URL ?>/forum.php?cat=<?= (int)$sub['id'] ?>" style="margin-left:.5rem">Themen ansehen</a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <?php if (!$categories): ?><div class="card glass"><p class="muted">Noch keine Kategorien vorhanden.</p></div><?php endif; ?>

                <?php if (isset($_GET['cat'])):
                    $catId = (int)$_GET['cat'];
                    $stmt = db()->prepare("SELECT t.*, u.username, u.display_name, u.avatar, u.avatar_color,
                        (SELECT COUNT(*) FROM forum_posts p WHERE p.topic_id = t.id) AS post_count,
                        (SELECT created_at FROM forum_posts p2 WHERE p2.topic_id = t.id ORDER BY p2.created_at DESC LIMIT 1) AS last_post_at
                        FROM forum_topics t JOIN users u ON u.id = t.user_id
                        WHERE t.category_id = ? ORDER BY COALESCE(t.last_post_at, t.created_at) DESC");
                    $stmt->execute([$catId]);
                    $topics = $stmt->fetchAll();
                ?>
                <div class="card glass reveal" style="margin-top:1.5rem">
                    <h3>Themen (<?= count($topics) ?>)</h3>
                    <?php if (!$topics): ?><p class="muted">Noch keine Themen – starte die erste Diskussion!</p><?php endif; ?>
                    <?php foreach ($topics as $t): ?>
                    <a class="topic-row" href="<?= SITE_URL ?>/forum_topic.php?id=<?= (int)$t['id'] ?>">
                        <?= avatar_html(['display_name' => $t['display_name'], 'username' => $t['username'], 'avatar' => $t['avatar'], 'avatar_color' => $t['avatar_color']]) ?>
                        <div class="topic-meta">
                            <strong><?= !empty($t['pinned']) ? '📌 ' : '' ?><?= !empty($t['solved']) ? '<span class="badge solved-badge">✔ gelöst</span> ' : '' ?><?= e($t['title']) ?></strong>
                            <span class="muted small">von <?= e($t['display_name'] ?: $t['username']) ?> · <?= (int)$t['post_count'] ?> Antwort(en)
                            <?php if ($t['last_post_at']): ?> · zuletzt <?= chat_time($t['last_post_at']) ?><?php endif; ?></span>
                        </div>
                        <span class="btn btn-ghost btn-sm">Öffnen →</span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <aside class="card glass reveal leaderboard">
                <h3>🏆 Ranking</h3>
                <p class="muted small">Punkte durch Beiträge, Reaktionen, Kommentare &amp; Nachrichten.</p>
                <?php $rank = 0; foreach ($board as $b): $rank++; ?>
                <a class="board-row" href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($b['username']) ?>">
                    <span class="board-rank"><?= $rank <= 3 ? ['🥇','🥈','🥉'][$rank-1] : $rank . '.' ?></span>
                    <?= avatar_html(['display_name' => $b['display_name'], 'username' => $b['username'], 'avatar' => $b['avatar'], 'avatar_color' => $b['avatar_color']]) ?>
                    <div class="topic-meta">
                        <strong><?= e($b['display_name'] ?: $b['username']) ?></strong>
                        <span class="muted small"><?= rank_title((int)$b['points']) ?> · <?= (int)$b['points'] ?> Punkte</span>
                    </div>
                </a>
                <?php endforeach; ?>
                <div class="board-me">
                    <?php $myPoints = rank_points((int)$me['id']); ?>
                    <strong>Dein Rang:</strong> <?= rank_title($myPoints) ?> · <?= $myPoints ?> Punkte
                </div>
            </aside>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
