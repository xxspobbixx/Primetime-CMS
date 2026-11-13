<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
$current = 'forum';
$me = current_member();

$topicId = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT t.*, t.solved, c.name AS cat_name, c.parent_id AS cat_parent,
                              pc.name AS parent_name,
                              u.username, u.display_name, u.avatar, u.avatar_color
                       FROM forum_topics t
                       JOIN forum_categories c ON c.id = t.category_id
                       LEFT JOIN forum_categories pc ON pc.id = c.parent_id
                       JOIN users u ON u.id = t.user_id
                       WHERE t.id = ?');
$stmt->execute([$topicId]);
$topic = $stmt->fetch();
if (!$topic) { flash('error', 'Thema nicht gefunden.'); header('Location: forum.php'); exit; }
$pageTitle = $topic['title'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'reply') {
        $content = trim((string)($_POST['content'] ?? ''));
        if ($content === '' || mb_strlen($content) > 5000) {
            flash('error', 'Antwort leer oder zu lang.');
        } else {
            db()->prepare('INSERT INTO forum_posts (topic_id, user_id, content) VALUES (?, ?, ?)')
                ->execute([$topicId, $me['id'], filter_words($content)]);
            // Abonnenten benachrichtigen (ohne sich selbst & ohne Themen-Starter, der schon notify-Logik hat)
            $subs = db()->prepare("SELECT user_id FROM topic_subscriptions WHERE topic_id = ? AND user_id != ? AND user_id != ?");
            $subs->execute([$topicId, $me['id'], (int)$topic['user_id']]);
            foreach ($subs->fetchAll() as $s) {
                notify((int)$s['user_id'], 'forum_sub', (int)$me['id'],
                    ($me['display_name'] ?: $me['username']) . ' hat in „' . mb_strimwidth($topic['title'], 0, 30, '…') . '" geantwortet.',
                    SITE_URL . '/forum_topic.php?id=' . $topicId);
                push_notify((int)$s['user_id'], setting('site_name', 'Forum'), ($me['display_name'] ?: $me['username']) . ' hat in „' . mb_strimwidth($topic['title'], 0, 30, '…') . '" geantwortet.', SITE_URL . '/forum_topic.php?id=' . $topicId);
            }
            db()->prepare('UPDATE forum_topics SET last_post_at = NOW() WHERE id = ?')->execute([$topicId]);
            check_badges((int)$me['id']);
            // Themen-Starter benachrichtigen (nicht bei eigener Antwort)
            if ((int)$topic['user_id'] !== (int)$me['id']) {
                notify((int)$topic['user_id'], 'forum_reply', (int)$me['id'],
                    ($me['display_name'] ?: $me['username']) . ' hat auf dein Thema „' . mb_strimwidth($topic['title'], 0, 30, '…') . '" geantwortet.',
                    SITE_URL . '/forum_topic.php?id=' . $topicId);
            }
            flash('success', 'Antwort veröffentlicht! +5 Punkte');
        }
    } elseif ($action === 'solve') {
        // Themen-Starter oder Staff duerfen loesen/abloesen
        if ((int)$topic['user_id'] === (int)$me['id'] || user_can_moderate($me)) {
            db()->prepare('UPDATE forum_topics SET solved = 1 - solved WHERE id = ?')->execute([$topicId]);
            flash('success', !empty($topic['solved']) ? 'Thema wieder geöffnet.' : 'Thema als erledigt markiert! ✔');
        } else {
            flash('error', 'Nur der Ersteller oder das Team kann das Thema lösen.');
        }
    } elseif ($action === 'subscribe') {
        db()->prepare('INSERT IGNORE INTO topic_subscriptions (topic_id, user_id) VALUES (?, ?)')
            ->execute([$topicId, $me['id']]);
        flash('success', 'Thema abonniert – du wirst über Antworten benachrichtigt.');
    } elseif ($action === 'unsubscribe') {
        db()->prepare('DELETE FROM topic_subscriptions WHERE topic_id = ? AND user_id = ?')
            ->execute([$topicId, $me['id']]);
        flash('success', 'Abo beendet.');
    } elseif ($action === 'vote') {
        $pid = (int)$_POST['post_id'];
        $v = (int)$_POST['vote'] === 1 ? 1 : -1;
        // Nur andere Posts bewerten
        $own = db()->prepare('SELECT user_id FROM forum_posts WHERE id = ? AND topic_id = ?');
        $own->execute([$pid, $topicId]);
        $owner = (int)$own->fetchColumn();
        if ($owner > 0 && $owner !== (int)$me['id']) {
            db()->prepare('INSERT INTO forum_votes (post_id, user_id, vote) VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE vote = VALUES(vote)')
                ->execute([$pid, $me['id'], $v]);
        }
    } elseif ($action === 'vote_remove') {
        db()->prepare('DELETE FROM forum_votes WHERE post_id = ? AND user_id = ?')
            ->execute([(int)$_POST['post_id'], $me['id']]);
    } elseif ($action === 'best_answer') {
        // Nur Themen-Starter oder Staff
        if ((int)$topic['user_id'] === (int)$me['id'] || user_can_moderate($me)) {
            $pid = (int)$_POST['post_id'];
            $valid = db()->prepare('SELECT id FROM forum_posts WHERE id = ? AND topic_id = ? AND user_id != ?');
            $valid->execute([$pid, $topicId, $me['id']]);
            if ($valid->fetch()) {
                db()->prepare('UPDATE forum_posts SET best_answer = 0 WHERE topic_id = ?')->execute([$topicId]);
                db()->prepare('UPDATE forum_posts SET best_answer = 1 WHERE id = ?')->execute([$pid]);
                $author = db()->prepare('SELECT user_id FROM forum_posts WHERE id = ?');
                $author->execute([$pid]);
                $aid = (int)$author->fetchColumn();
                if ($aid > 0) {
                    notify($aid, 'best_answer', (int)$me['id'],
                        'Deine Antwort in „' . mb_strimwidth($topic['title'], 0, 30, '…') . '" wurde als beste Antwort markiert! 💎',
                        SITE_URL . '/forum_topic.php?id=' . $topicId);
                    add_points($aid, 20);
                }
                flash('success', 'Beste Antwort markiert! (+20 Punkte für die Antwort)');
            }
        }
    } elseif ($action === 'pin' && user_can_moderate($me)) {
        db()->prepare('UPDATE forum_topics SET pinned = 1 - pinned WHERE id = ?')->execute([$topicId]);
        mod_log('Thema an/entpinnt', $topic['title']);
        flash('success', 'Thema angepinnt/abgepinnt.');
    } elseif ($action === 'delete_post') {
        $pid = (int)$_POST['id'];
        if (user_can_moderate($me)) {
            db()->prepare('DELETE FROM forum_posts WHERE id = ? AND topic_id = ?')->execute([$pid, $topicId]);
        } else {
            db()->prepare('DELETE FROM forum_posts WHERE id = ? AND topic_id = ? AND user_id = ?')->execute([$pid, $topicId, $me['id']]);
        }
        flash('success', 'Beitrag gelöscht.');
    } elseif ($action === 'delete_topic' && (user_can_moderate($me) || (int)$topic['user_id'] === (int)$me['id'])) {
        db()->prepare('DELETE FROM forum_posts WHERE topic_id = ?')->execute([$topicId]);
        db()->prepare('DELETE FROM forum_topics WHERE id = ?')->execute([$topicId]);
        flash('success', 'Thema gelöscht.');
        header('Location: ' . SITE_URL . '/forum.php?cat=' . (int)$topic['category_id']);
        exit;
    }
    header('Location: ' . SITE_URL . '/forum_topic.php?id=' . $topicId);
    exit;
}

$posts = db()->prepare('SELECT p.*, u.username, u.display_name, u.avatar, u.avatar_color, u.role, u.name_color,
        (SELECT COALESCE(SUM(vote), 0) FROM forum_votes v WHERE v.post_id = p.id) AS score,
        EXISTS(SELECT 1 FROM forum_votes v WHERE v.post_id = p.id AND v.user_id = ' . (int)$me['id'] . ' AND v.vote = 1) AS my_up,
        EXISTS(SELECT 1 FROM forum_votes v WHERE v.post_id = p.id AND v.user_id = ' . (int)$me['id'] . ' AND v.vote = -1) AS my_down
        FROM forum_posts p JOIN users u ON u.id = p.user_id
        WHERE p.topic_id = ? ORDER BY p.best_answer DESC, p.created_at ASC');
$posts->execute([$topicId]);
$posts = $posts->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal">
    <?php if (!empty($topic['solved'])): ?>
    <div class="topic-solved-banner">✔ Dieses Thema ist als <strong>erledigt</strong> markiert</div>
    <?php endif; ?>
    <?php if ((int)$topic['user_id'] === (int)$me['id'] || user_can_moderate($me)): ?>
    <form method="post" class="inline" style="margin-bottom:.5rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="solve">
        <button class="btn btn-ghost btn-sm" type="submit"><?= !empty($topic['solved']) ? '↩ Wieder öffnen' : '✔ Als erledigt markieren' ?></button>
    </form>
    <?php endif; ?>
    <?php
    $subStmt = db()->prepare('SELECT 1 FROM topic_subscriptions WHERE topic_id = ? AND user_id = ?');
    $subStmt->execute([$topicId, $me['id']]);
    $isSubscribed = (bool)$subStmt->fetchColumn();
    ?>
    <form method="post" class="inline" style="margin-bottom:.5rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $isSubscribed ? 'unsubscribe' : 'subscribe' ?>">
        <button class="btn btn-ghost btn-sm" type="submit"><?= $isSubscribed ? '🔔 Abo beenden' : '🔔 Thema abonnieren' ?></button>
    </form>
    <?php if (user_can_moderate($me)): ?>
    <form method="post" class="inline" style="margin-bottom:.5rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="pin">
        <button class="btn btn-ghost btn-sm" type="submit"><?= !empty($topic['pinned']) ? '📌 Thema abpinnen' : '📌 Thema anpinnen' ?></button>
    </form>
    <?php endif; ?>
    <p class="muted"><a href="<?= SITE_URL ?>/forum.php">Forum</a>
    <?php if (!empty($topic['parent_name'])): ?> → <a href="<?= SITE_URL ?>/forum.php"><?= e($topic['parent_name']) ?></a><?php endif; ?>
    → <a href="<?= SITE_URL ?>/forum.php?cat=<?= (int)$topic['category_id'] ?>"><?= e($topic['cat_name']) ?></a></p>
    <h1><?= e($topic['title']) ?></h1>
</div></section>
<section class="section"><div class="container">
    <div class="card glass reveal" style="margin-bottom:1rem">
        <?php foreach ($posts as $post): ?>
        <div class="forum-post">
            <div class="forum-post-side">
                <?= avatar_html(['display_name' => $post['display_name'], 'username' => $post['username'], 'avatar' => $post['avatar'], 'avatar_color' => $post['avatar_color']], 'avatar-md') ?>
                <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($post['username']) ?>" class="forum-author"<?= name_color_style($post) ?>><?= e($post['display_name'] ?: $post['username']) ?></a>
                <span class="badge"><?= rank_title(rank_points((int)$post['user_id'])) ?></span>
                <?= role_badge($post['role']) ?>
            </div>
            <div class="forum-post-body <?= !empty($post['best_answer']) ? 'best-answer' : '' ?>">
                <div class="forum-post-meta"><?= chat_time($post['created_at']) ?>
                    <?php if (!empty($post['best_answer'])): ?><span class="badge solved-badge">💎 Beste Antwort</span><?php endif; ?>
                    <?php if (user_can_moderate($me) || (int)$post['user_id'] === (int)$me['id']): ?>
                    <form method="post" class="inline" data-confirm="Beitrag löschen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_post">
                        <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                        <button class="comment-delete" type="submit">🗑</button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="prose"><?= nl2br(e($post['content'])) ?></div>
                <?php
                $sigStmt = db()->prepare('SELECT forum_signature FROM users WHERE id = ?');
                $sigStmt->execute([(int)$post['user_id']]);
                $sig = trim((string)$sigStmt->fetchColumn());
                if ($sig !== ''): ?>
                <div class="forum-signature"><?= nl2br(e($sig)) ?></div>
                <?php endif; ?>
                <div class="forum-vote-bar">
                    <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="vote">
                        <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                        <button class="vote-btn <?= !empty($post['my_up']) ? 'voted' : '' ?>" name="vote" value="1" type="submit" title="Gut">▲</button>
                    </form>
                    <strong class="vote-score"><?= (int)$post['score'] ?></strong>
                    <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="vote">
                        <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                        <button class="vote-btn <?= !empty($post['my_down']) ? 'voted-down' : '' ?>" name="vote" value="-1" type="submit" title="Schlecht">▼</button>
                    </form>
                    <?php if (((int)$topic['user_id'] === (int)$me['id'] || user_can_moderate($me)) && (int)$post['user_id'] !== (int)$me['id']): ?>
                    <form method="post" class="inline" title="Als beste Antwort markieren">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="best_answer">
                        <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= !empty($post['best_answer']) ? '💎 Hervorgehoben' : '💎' ?></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (user_can_moderate($me) || (int)$topic['user_id'] === (int)$me['id']): ?>
        <form method="post" class="inline" data-confirm="Ganzes Thema löschen?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_topic">
            <button class="btn btn-danger btn-sm" type="submit">Thema löschen</button>
        </form>
        <?php endif; ?>
    </div>

    <form method="post" class="card glass form-stack reveal">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reply">
        <h3>Antworten</h3>
        <textarea name="content" rows="5" maxlength="5000" required></textarea>
        <button class="btn" type="submit">Antwort absenden (+5 Punkte)</button>
    </form>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
