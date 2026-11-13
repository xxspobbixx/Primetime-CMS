<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
$current = '';
$me = current_member();

$username = trim((string)($_GET['u'] ?? ''));
$owner = $username !== '' ? find_user_by_username($username) : null;

// ---------- Zugriffsprüfung (wie bei Profilseiten) ----------
$canView = $owner && ((int)$owner['is_public'] === 1
    || ($me && ((int)$me['id'] === (int)$owner['id'] || $me['role'] === 'admin')));

// ---------- Aktionen (Like/Dislike, Kommentare) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $owner && $canView) {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $postId = (int)($_POST['post_id'] ?? 0);

    // Sicherstellen: Der Beitrag gehört dem Galerie-Besitzer und hat ein Bild
    $stmt = db()->prepare('SELECT id FROM wall_posts WHERE id = ? AND user_id = ? AND image IS NOT NULL AND image != ""');
    $stmt->execute([$postId, $owner['id']]);
    $validPost = (bool)$stmt->fetchColumn();
    $canModerate = user_can_moderate($me);

    if ($validPost && $action === 'react') {
        $type = (string)($_POST['type'] ?? '');
        if (in_array($type, ['like', 'dislike'], true)) {
            $stmt = db()->prepare('SELECT type FROM wall_reactions WHERE post_id = ? AND user_id = ?');
            $stmt->execute([$postId, $me['id']]);
            $existing = $stmt->fetchColumn();
            if ($existing === $type) {
                db()->prepare('DELETE FROM wall_reactions WHERE post_id = ? AND user_id = ?')->execute([$postId, $me['id']]);
            } elseif ($existing) {
                db()->prepare('UPDATE wall_reactions SET type = ?, created_at = NOW() WHERE post_id = ? AND user_id = ?')
                    ->execute([$type, $postId, $me['id']]);
            } else {
                db()->prepare('INSERT IGNORE INTO wall_reactions (post_id, user_id, type) VALUES (?, ?, ?)')
                    ->execute([$postId, $me['id'], $type]);
            }
        }
    } elseif ($validPost && $action === 'comment') {
        $content = trim((string)($_POST['content'] ?? ''));
        if ($content !== '' && mb_strlen($content) <= 500) {
            db()->prepare('INSERT INTO wall_comments (post_id, user_id, content) VALUES (?, ?, ?)')
                ->execute([$postId, $me['id'], $content]);
            // Besitzer benachrichtigen (außer bei eigenem Kommentar)
            if ((int)$owner['id'] !== (int)$me['id']) {
                notify((int)$owner['id'], 'gallery_comment', (int)$me['id'],
                    ($me['display_name'] ?: $me['username']) . ' hat dein Bild kommentiert.',
                    SITE_URL . '/galerie.php?u=' . urlencode($owner['username']) . '&post=' . $postId);
            }
        }
    } elseif ($action === 'delete_comment') {
        $cid = (int)$_POST['id'];
        if ($canModerate) {
            db()->prepare('DELETE FROM wall_comments WHERE id = ?')->execute([$cid]);
        } else {
            db()->prepare('DELETE FROM wall_comments WHERE id = ? AND user_id = ?')->execute([$cid, $me['id']]);
        }
    }
    header('Location: ' . SITE_URL . '/galerie.php?u=' . urlencode($owner['username']) . ($validPost ? '&post=' . $postId : ''));
    exit;
}

// ---------- Galerie-Kommentare (Bilder = Wall-Posts) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $postId = (int)($_POST['post_id'] ?? 0);
    // Pruefen: Post gehoert zum Besitzer und hat ein Bild
    $chk = db()->prepare('SELECT id FROM wall_posts WHERE id = ? AND user_id = ? AND image IS NOT NULL AND image != ""');
    $chk->execute([$postId, $owner['id']]);
    $validPost = (bool)$chk->fetchColumn();
    $canModG = user_can_moderate($me);

    if ($validPost && $action === 'comment' && $isMember) {
        $content = filter_words(trim((string)($_POST['content'] ?? '')));
        if ($content !== '' && mb_strlen($content) <= 500) {
            db()->prepare('INSERT INTO wall_comments (post_id, user_id, content) VALUES (?, ?, ?)')
                ->execute([$postId, $me['id'], $content]);
            $ownerId = (int)$owner['id'];
            if ($ownerId !== (int)$me['id']) {
                notify($ownerId, 'gallery_comment', (int)$me['id'],
                    ($me['display_name'] ?: $me['username']) . ' hat dein Bild kommentiert.',
                    SITE_URL . '/galerie.php?u=' . urlencode($owner['username']) . '&post=' . $postId);
            }
        }
    } elseif ($action === 'delete_comment') {
        $cid = (int)$_POST['id'];
        if ($canModG) {
            db()->prepare('DELETE FROM wall_comments WHERE id = ?')->execute([$cid]);
        } else {
            db()->prepare('DELETE FROM wall_comments WHERE id = ? AND user_id = ?')->execute([$cid, $me['id']]);
        }
    }
    header('Location: ' . SITE_URL . '/galerie.php?u=' . urlencode($owner['username']) . ($validPost ? '&post=' . $postId : ''));
    exit;
}

// ---------- Bilder laden ----------
$images = [];
$detail = null;
$reactions = [];
$myReaction = null;
$comments = [];

if ($owner && $canView) {
    $stmt = db()->prepare("SELECT * FROM wall_posts WHERE user_id = ? AND image IS NOT NULL AND image != '' ORDER BY created_at DESC");
    $stmt->execute([$owner['id']]);
    $images = $stmt->fetchAll();

    // Detailansicht eines Bildes
    $postId = (int)($_GET['post'] ?? 0);
    if ($postId > 0) {
        foreach ($images as $img) {
            if ((int)$img['id'] === $postId) { $detail = $img; break; }
        }
        if ($detail) {
            $stmt = db()->prepare("SELECT c.*, u.username, u.display_name, u.avatar, u.avatar_color, u.name_color
                                   FROM wall_comments c JOIN users u ON u.id = c.user_id
                                   WHERE c.post_id = ? ORDER BY c.created_at ASC");
            $stmt->execute([$postId]);
            $comments = $stmt->fetchAll();
            $stmt = db()->prepare('SELECT type, COUNT(*) AS cnt FROM wall_reactions WHERE post_id = ? GROUP BY type');
            $stmt->execute([$postId]);
            foreach ($stmt->fetchAll() as $r) { $reactions[$r['type']] = (int)$r['cnt']; }

            $stmt = db()->prepare('SELECT type FROM wall_reactions WHERE post_id = ? AND user_id = ?');
            $stmt->execute([$postId, $me['id']]);
            $myReaction = $stmt->fetchColumn() ?: null;

            $stmt = db()->prepare("SELECT c.*, u.username, u.display_name, u.avatar, u.avatar_color
                                   FROM wall_comments c JOIN users u ON u.id = c.user_id
                                   WHERE c.post_id = ? ORDER BY c.created_at ASC");
            $stmt->execute([$postId]);
            $comments = $stmt->fetchAll();
        }
    }
}

$pageTitle = 'Galerie von ' . ($owner ? ($owner['display_name'] ?: $owner['username']) : '');
include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container reveal">
        <?php if (!$owner): ?>
            <h1>Benutzer nicht gefunden</h1>
        <?php elseif (!$canView): ?>
            <h1>🔒 Privates Profil</h1>
            <p class="muted">Diese Galerie ist privat.</p>
        <?php else: ?>
        <div class="profile-hero">
            <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($owner['username']) ?>"><?= avatar_html($owner, 'avatar-lg') ?></a>
            <div>
                <h1>🖼️ Galerie von <?= e($owner['display_name'] ?: $owner['username']) ?></h1>
                <p class="muted"><?= count($images) ?> Bild<?= count($images) === 1 ? '' : 'er' ?> · aus der Wall
                    · <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($owner['username']) ?>">Zum Profil</a></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($owner && $canView): ?>
<section class="section">
    <div class="container">
        <?php if (!$images): ?>
            <div class="card glass reveal"><p class="muted">Noch keine Bilder – geteilte Wall-Bilder erscheinen hier automatisch.</p></div>

        <?php elseif ($detail): ?>
        <!-- Detailansicht mit Like/Dislike + Kommentaren -->
        <div class="gallery-detail card glass reveal">
            <div class="gallery-detail-head">
                <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/galerie.php?u=<?= urlencode($owner['username']) ?>">← Zur Galerie</a>
                <span class="muted small"><?= chat_time($detail['created_at']) ?> · <a href="<?= SITE_URL ?>/wall.php#post-<?= (int)$detail['id'] ?>">Beitrag auf der Wall</a></span>
            </div>
            <a href="<?= SITE_URL ?>/uploads/wall/<?= rawurlencode($detail['image']) ?>" target="_blank" rel="noopener">
                <img class="gallery-detail-img" src="<?= SITE_URL ?>/uploads/wall/<?= rawurlencode($detail['image']) ?>" alt="Bild von <?= e($owner['display_name'] ?: $owner['username']) ?>">
            </a>
            <?php if (trim($detail['content']) !== ''): ?>
            <div class="wall-content" style="margin-top:1rem"><?= nl2br(e($detail['content'])) ?></div>
            <?php endif; ?>

            <!-- Like / Dislike -->
            <div class="reaction-bar">
                <?php foreach (['like' => '👍', 'dislike' => '👎'] as $type => $emoji): ?>
                <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="react">
                    <input type="hidden" name="post_id" value="<?= (int)$detail['id'] ?>">
                    <button type="submit" name="type" value="<?= $type ?>" class="reaction-btn <?= $myReaction === $type ? 'active' : '' ?>">
                        <?= $emoji ?>
                        <?php if (!empty($reactions[$type])): ?><span class="reaction-count"><?= $reactions[$type] ?></span><?php endif; ?>
                    </button>
                </form>
                <?php endforeach; ?>
                <span class="muted small" style="margin-left:auto">Likes sind mit der Wall synchron</span>
            </div>

            <!-- Kommentare -->
            <div class="comments">
                <h4>Kommentare (<?= count($comments) ?>)</h4>
                <?php foreach ($comments as $c): ?>
                <div class="comment">
                    <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($c['username']) ?>"><?= avatar_html($c) ?></a>
                    <div class="comment-body">
                        <div class="comment-head">
                            <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($c['username']) ?>" class="comment-author"<?= name_color_style($c) ?>><?= e($c['display_name'] ?: $c['username']) ?></a>
                            <span class="muted small"><?= chat_time($c['created_at']) ?></span>
                            <?php if ((int)$c['user_id'] === (int)$me['id'] || $canModG): ?>
                            <form method="post" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_comment">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <input type="hidden" name="post_id" value="<?= (int)$detail['id'] ?>">
                                <button class="comment-delete" type="submit" title="Löschen">✕</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?= nl2br(e($c['content'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php foreach ($comments as $c): ?>
                <div class="comment">
                    <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($c['username']) ?>"><?= avatar_html($c) ?></a>
                    <div class="comment-body">
                        <div class="comment-head">
                            <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($c['username']) ?>" class="comment-author"><?= e($c['display_name'] ?: $c['username']) ?></a>
                            <span class="muted small"><?= chat_time($c['created_at']) ?></span>
                            <?php if ((int)$c['user_id'] === (int)$me['id'] || user_can_moderate($me)): ?>
                            <form method="post" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_comment">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <input type="hidden" name="post_id" value="<?= (int)$detail['id'] ?>">
                                <button class="comment-delete" type="submit" title="Löschen">✕</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?= nl2br(e($c['content'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <form method="post" class="comment-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="comment">
                    <input type="hidden" name="post_id" value="<?= (int)$detail['id'] ?>">
                    <input type="text" name="content" maxlength="500" placeholder="Bild kommentieren …" required>
                    <button class="btn btn-sm" type="submit">➤</button>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- Übersichts-Raster -->
        <div class="gallery-grid reveal">
            <?php foreach ($images as $img): ?>
            <a href="<?= SITE_URL ?>/galerie.php?u=<?= urlencode($owner['username']) ?>&post=<?= (int)$img['id'] ?>" title="<?= chat_time($img['created_at']) ?>">
                <img src="<?= SITE_URL ?>/uploads/wall/<?= rawurlencode($img['image']) ?>" alt="Bild" loading="lazy">
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
