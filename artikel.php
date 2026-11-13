<?php
require_once __DIR__ . '/includes/functions.php';
$current = 'news';
$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM news_articles WHERE id = ? AND published = 1
    AND (publish_at IS NULL OR publish_at <= NOW())");
$stmt->execute([$id]);
$a = $stmt->fetch();
if (!$a) { flash('error', 'Artikel nicht gefunden.'); header('Location: ' . SITE_URL . '/news.php'); exit; }
$pageTitle = $a['title'];

// Kommentare laden
$comments = db()->query("SELECT c.*, u.username, u.display_name, u.avatar, u.avatar_color
    FROM news_comments c JOIN users u ON u.id = c.user_id
    WHERE c.article_id = " . (int)$a['id'] . " ORDER BY c.created_at ASC")->fetchAll();

// Kommentar posten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'comment' && is_member()) {
    csrf_check();
    $content = filter_words(trim((string)($_POST['content'] ?? '')));
    if ($content !== '' && mb_strlen($content) <= 1000) {
        db()->prepare('INSERT INTO news_comments (article_id, user_id, content) VALUES (?, ?, ?)')
            ->execute([(int)$a['id'], (int)current_member()['id'], $content]);
    }
    header('Location: ' . SITE_URL . '/artikel.php?id=' . (int)$a['id']);
    exit;
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal">
    <p class="muted small"><a href="<?= SITE_URL ?>/news.php">← Alle News</a></p>
    <h1><?= e($a['title']) ?></h1>
    <p class="muted"><?= date('d.m.Y', strtotime($a['created_at'])) ?></p>
</div></section>
<section class="section"><div class="container narrow">
    <article class="card glass reveal">
        <div class="prose news-content"><?= nl2br($a['content']) ?></div>
        <div class="share-row">
            <a class="share-btn" target="_blank" rel="noopener" href="https://twitter.com/intent/tweet?text=<?= urlencode($a['title']) ?>&url=<?= urlencode(SITE_URL . '/artikel.php?id=' . (int)$a['id']) ?>">𝕏</a>
            <a class="share-btn" target="_blank" rel="noopener" href="https://wa.me/?text=<?= urlencode($a['title'] . ' ' . SITE_URL . '/artikel.php?id=' . (int)$a['id']) ?>">💬</a>
        </div>
    </article>

    <div class="card glass reveal" style="margin-top:1.5rem">
        <h3>💬 Kommentare (<?= count($comments) ?>)</h3>
        <?php foreach ($comments as $cm): ?>
        <div class="comment">
            <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($cm['username']) ?>"><?= avatar_html($cm) ?></a>
            <div class="comment-body">
                <div class="comment-head">
                    <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($cm['username']) ?>" class="comment-author"><?= e($cm['display_name'] ?: $cm['username']) ?></a>
                    <span class="muted small"><?= date('d.m.Y H:i', strtotime($cm['created_at'])) ?></span>
                </div>
                <?= nl2br(e($cm['content'])) ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (is_member()): ?>
        <form method="post" class="comment-form" style="margin-top:1rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="comment">
            <input type="text" name="content" maxlength="1000" placeholder="Kommentar schreiben …" required>
            <button class="btn btn-sm" type="submit">➤</button>
        </form>
        <?php endif; ?>
    </div>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
