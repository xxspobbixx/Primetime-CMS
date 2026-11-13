<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
if (!plugin_active('contest')) {
    flash('error', 'Diese Seite ist derzeit deaktiviert.');
    header('Location: ' . SITE_URL . '/wall.php');
    exit;
}
$current = 'contest';
$pageTitle = 'Wettbewerbe';
$me = current_member();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'submit') {
        $cid = (int)$_POST['contest_id'];
        $text = trim((string)($_POST['entry_text'] ?? ''));
        $active = db()->query('SELECT id FROM contests WHERE id = ' . $cid . ' AND active = 1')->fetch();
        if ($active && $text !== '' && mb_strlen($text) <= 500) {
            db()->prepare('INSERT INTO contest_entries (contest_id, user_id, entry_text) VALUES (?, ?, ?)')
                ->execute([$cid, $me['id'], filter_words($text)]);
            flash('success', 'Einsendet abgegeben!');
        }
    } elseif ($action === 'vote') {
        $cid = (int)$_POST['contest_id'];
        $eid = (int)$_POST['entry_id'];
        $ok = db()->query('SELECT id FROM contests WHERE id = ' . $cid . ' AND active = 1')->fetch()
            && db()->query('SELECT id FROM contest_entries WHERE id = ' . $eid . ' AND contest_id = ' . $cid)->fetch();
        if ($ok) {
            db()->prepare('INSERT IGNORE INTO contest_votes (contest_id, entry_id, user_id) VALUES (?, ?, ?)')
                ->execute([$cid, $eid, $me['id']]);
            flash('success', 'Stimme abgegeben!');
        }
    }
    header('Location: wettbewerbe.php');
    exit;
}

$contests = db()->query('SELECT * FROM contests WHERE active = 1 ORDER BY id DESC LIMIT 1')->fetchAll();
$entries = [];
$myVote = 0;
$counts = [];
if ($contests) {
    $cid = (int)$contests[0]['id'];
    $entries = db()->query("SELECT ce.*, u.username, u.display_name, u.avatar, u.avatar_color,
        (SELECT COUNT(*) FROM contest_votes v WHERE v.entry_id = ce.id) AS votes
        FROM contest_entries ce JOIN users u ON u.id = ce.user_id
        WHERE ce.contest_id = $cid ORDER BY votes DESC, ce.id ASC")->fetchAll();
    $stmt = db()->prepare('SELECT entry_id FROM contest_votes WHERE contest_id = ? AND user_id = ?');
    $stmt->execute([$cid, $me['id']]);
    $myVote = (int)$stmt->fetchColumn();
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal"><h1>🏆 Wettbewerbe</h1>
<p class="muted">Einsendungen einreichen &amp; für deine Favoriten voten!</p></div></section>
<section class="section"><div class="container">
    <?php if (!$contests): ?>
        <div class="card glass reveal"><p class="muted">Aktuell läuft kein Wettbewerb.</p></div>
    <?php else: $c = $contests[0]; ?>
    <div class="card glass reveal" style="margin-bottom:1.5rem">
        <h3><?= e($c['title']) ?></h3>
        <?php if ($c['description']): ?><div class="prose"><?= nl2br(e($c['description'])) ?></div><?php endif; ?>
        <form method="post" class="form-inline" style="margin-top:1rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="submit">
            <input type="hidden" name="contest_id" value="<?= (int)$c['id'] ?>">
            <input type="text" name="entry_text" class="flex-grow" maxlength="500" placeholder="Deine Einsendung (Screenshot-Link, Idee, Text …)" required>
            <button class="btn btn-sm" type="submit">Einreichen</button>
        </form>
    </div>

    <div class="grid grid-2">
        <?php foreach ($entries as $en): $mine = (int)$en['user_id'] === (int)$me['id']; ?>
        <div class="card glass reveal">
            <div class="card-head">
                <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($en['username']) ?>"><?= avatar_html($en) ?></a>
                <div><strong><?= e($en['display_name'] ?: $en['username']) ?></strong>
                    <span class="muted small">· ⭐ <?= (int)$en['votes'] ?> Stimmen</span></div>
            </div>
            <p><?= nl2br(e($en['entry_text'])) ?></p>
            <?php if (!$mine): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="vote">
                <input type="hidden" name="contest_id" value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="entry_id" value="<?= (int)$en['id'] ?>">
                <button class="btn btn-ghost btn-sm" <?= $myVote ? 'disabled' : '' ?>><?= $myVote === (int)$en['id'] ? '✔ Deine Stimme' : '⭐ Abstimmen' ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$entries): ?><div class="card glass"><p class="muted">Noch keine Einsendungen – sei die erste!</p></div><?php endif; ?>
    </div>
    <?php endif; ?>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
