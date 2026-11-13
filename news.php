<?php
require_once __DIR__ . '/includes/functions.php';
$current = 'news';
$pageTitle = 'News';

$perPage = max(1, (int)setting('news_per_page', '9'));
$pageNum = max(1, (int)($_GET['page'] ?? 1));

$articles = [];
$total = 0;
try {
    $total = (int)db()->query('SELECT COUNT(*) FROM news_articles WHERE published = 1')->fetchColumn();
    $offset = ($pageNum - 1) * $perPage;
    // Geplante Artikel erst ab Erscheinungsdatum zeigen
$articles = db()->query("SELECT * FROM news_articles WHERE published = 1
                             AND (publish_at IS NULL OR publish_at <= NOW())
                             ORDER BY created_at DESC LIMIT $perPage OFFSET $offset")->fetchAll();
// Kommentare je Artikel laden
$ncMap = [];
if ($articles) {
    $aIds = implode(',', array_map('intval', array_column($articles, 'id')));
    foreach (db()->query("SELECT c.*, u.username, u.display_name, u.avatar, u.avatar_color
                          FROM news_comments c JOIN users u ON u.id = c.user_id
                          WHERE c.article_id IN ($aIds) ORDER BY c.created_at ASC") as $c) {
        $ncMap[$c['article_id']][] = $c;
    }
}
// Kommentar posten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'comment') {
    require_member();
    csrf_check();
    $aid = (int)($_POST['article_id'] ?? 0);
    $content = filter_words(trim((string)($_POST['content'] ?? '')));
    if ($content !== '' && mb_strlen($content) <= 1000) {
        db()->prepare('INSERT INTO news_comments (article_id, user_id, content) VALUES (?, ?, ?)')
            ->execute([$aid, current_member()['id'], $content]);
    }
    header('Location: ' . SITE_URL . '/news.php#article-' . $aid);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'del_comment' && is_member()) {
    csrf_check();
    db()->prepare('DELETE FROM news_comments WHERE id = ? AND user_id = ?')
        ->execute([(int)$_POST['id'], (int)current_member()['id']]);
    header('Location: ' . SITE_URL . '/news.php');
    exit;
}
} catch (Throwable $e) { /* Tabellen noch nicht angelegt */ }

$totalPages = max(1, (int)ceil($total / $perPage));

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container reveal">
        <h1>📰 News</h1>
        <p class="muted">Die neuesten Meldungen und Updates.</p>
    </div>
</section>

<section class="section">
    <div class="container narrow">
        <?php if (!$articles): ?>
            <div class="card glass reveal">
                <p class="muted">Noch keine News vorhanden – schau bald wieder vorbei!</p>
            </div>
        <?php else: ?>
            <?php foreach ($articles as $a): ?>
            <article class="card glass news-article reveal" id="article-<?= (int)$a['id'] ?>">
                <div class="news-meta">
                    <span class="badge">News</span>
                    <time><?= date('d.m.Y', strtotime($a['created_at'])) ?></time>
                </div>
                <h2 class="news-title"><?= e($a['title']) ?></h2>
                <div class="share-row" style="margin-bottom:.8rem">
                    <a class="share-btn" target="_blank" rel="noopener" href="https://twitter.com/intent/tweet?text=<?= urlencode($a['title']) ?>&url=<?= urlencode(SITE_URL . '/news.php#article-' . (int)$a['id']) ?>">𝕏</a>
                    <a class="share-btn" target="_blank" rel="noopener" href="https://wa.me/?text=<?= urlencode($a['title'] . ' ' . SITE_URL . '/news.php#article-' . (int)$a['id']) ?>">💬</a>
                    <a class="share-btn" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode(SITE_URL . '/news.php#article-' . (int)$a['id']) ?>">f</a>
                </div>
                <?php
                $plainLen = mb_strlen(strip_tags($a['content']));
                if ($plainLen > 600):
                    $cut = mb_substr(strip_tags($a['content']), 0, 500);
                ?>
                <div class="prose news-content"><?= nl2br(e($cut)) ?> …</div>
                <a class="btn btn-sm" href="<?= SITE_URL ?>/artikel.php?id=<?= (int)$a['id'] ?>" style="margin-top:.5rem">Weiterlesen →</a>
                <?php else: ?>
                <div class="prose news-content"><?= nl2br($a['content']) ?></div>
                <?php endif; ?>

                <div class="comments" style="margin-top:1rem">
                    <?php foreach ($ncMap[$a['id']] ?? [] as $cm): ?>
                    <div class="comment">
                        <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($cm['username']) ?>"><?= avatar_html($cm) ?></a>
                        <div class="comment-body">
                            <div class="comment-head">
                                <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($cm['username']) ?>" class="comment-author"><?= e($cm['display_name'] ?: $cm['username']) ?></a>
                                <span class="muted small"><?= date('d.m.Y H:i', strtotime($cm['created_at'])) ?></span>
                                <?php if (is_member() && (int)$cm['user_id'] === (int)current_member()['id']): ?>
                                <form method="post" class="inline" data-confirm="Kommentar löschen?">
                                    <input type="hidden" name="action" value="del_comment">
                                    <input type="hidden" name="id" value="<?= (int)$cm['id'] ?>">
                                    <button class="comment-delete" type="submit">✕</button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <?= nl2br(e($cm['content'])) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (is_member()): ?>
                    <form method="post" class="comment-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="comment">
                        <input type="hidden" name="article_id" value="<?= (int)$a['id'] ?>">
                        <input type="text" name="content" maxlength="1000" placeholder="Kommentar schreiben …" required>
                        <button class="btn btn-sm" type="submit">➤</button>
                    </form>
                    <?php else: ?>
                    <p class="muted small">🔒 <a href="<?= SITE_URL ?>/login.php">Anmelden</a> zum Kommentieren</p>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>

            <?php if ($totalPages > 1): ?>
            <nav class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a class="<?= $i === $pageNum ? 'active' : '' ?>" href="<?= SITE_URL ?>/news.php?page=<?= $i ?>"><?= $i ?></a>
                <?php endfor; ?>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
