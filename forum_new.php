<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
$current = 'forum';
$pageTitle = 'Neues Thema';
$me = current_member();

$catId = (int)($_GET['category'] ?? 0);
$stmt = db()->prepare('SELECT * FROM forum_categories WHERE id = ? AND active = 1');
$stmt->execute([$catId]);
$cat = $stmt->fetch();
// Nur in Foren OHNE eigene Unterforen duerfen Themen erstellt werden
$stmt = db()->prepare('SELECT id FROM forum_categories WHERE parent_id = ? AND active = 1 LIMIT 1');
$stmt->execute([$catId]);
$hasSub = (bool)$stmt->fetchColumn();
if (!$cat || $hasSub) {
    flash('error', $cat ? 'Bitte wähle ein Unterforum für dein Thema.' : 'Kategorie nicht gefunden.');
    header('Location: ' . SITE_URL . '/forum.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title = trim((string)($_POST['title'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    if ($title === '' || mb_strlen($title) > 200) {
        flash('error', 'Titel fehlt oder zu lang (max. 200 Zeichen).');
    } elseif ($content === '' || mb_strlen($content) > 5000) {
        flash('error', 'Beitrag leer oder zu lang (max. 5000 Zeichen).');
    } else {
        db()->prepare('INSERT INTO forum_topics (category_id, user_id, title) VALUES (?, ?, ?)')
            ->execute([$catId, $me['id'], $title]);
        $topicId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO forum_posts (topic_id, user_id, content) VALUES (?, ?, ?)')
            ->execute([$topicId, $me['id'], $content]);
        db()->prepare('UPDATE forum_topics SET last_post_at = NOW() WHERE id = ?')->execute([$topicId]);
        flash('success', 'Thema erstellt! +10 Punkte');
        header('Location: ' . SITE_URL . '/forum_topic.php?id=' . $topicId);
        exit;
    }
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal"><h1>➕ Neues Thema</h1>
<p class="muted">in # <?= e($cat['name']) ?></p></div></section>
<section class="section"><div class="container narrow">
<form method="post" class="card glass form-stack reveal">
    <?= csrf_field() ?>
    <label>Titel <input type="text" name="title" maxlength="200" required autofocus></label>
    <label>Beitrag <textarea name="content" rows="8" maxlength="5000" required></textarea></label>
    <div><button class="btn" type="submit">Thema erstellen</button>
    <a class="btn btn-ghost" href="<?= SITE_URL ?>/forum.php">Abbrechen</a></div>
</form>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
