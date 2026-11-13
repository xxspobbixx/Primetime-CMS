<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
if (!plugin_active('shoutbox')) {
    flash('error', 'Diese Seite ist derzeit deaktiviert.');
    header('Location: ' . SITE_URL . '/wall.php');
    exit;
}
$current = 'shoutbox';
$pageTitle = 'Shoutbox';
$me = current_member();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $text = trim((string)($_POST['message'] ?? ''));
    if (plugin_active('rate_limit') && rate_limited((int)$me['id'], 'shoutbox', (int)setting('plugin_rate_limit_chat', '10'))) {
        flash('error', 'Zu viele Nachrichten – etwas langsamer.');
    } elseif ($text !== '' && mb_strlen($text) <= 300) {
        db()->prepare('INSERT INTO shoutbox (user_id, message) VALUES (?, ?)')
            ->execute([$me['id'], filter_words($text)]);
        check_badges((int)$me['id']);
    }
    header('Location: shoutbox.php');
    exit;
}

$msgs = db()->query("SELECT s.*, u.username, u.display_name, u.avatar, u.avatar_color, u.role, u.name_color
                     FROM shoutbox s JOIN users u ON u.id = s.user_id
                     WHERE u.shadow_banned = 0 ORDER BY s.id DESC LIMIT 30")->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal"><h1>💬 Shoutbox</h1>
<p class="muted">Kurze Nachrichten für alle – schnell &amp; unkompliziert.</p></div></section>
<section class="section"><div class="container narrow">
    <form method="post" class="chat-form card glass reveal" style="margin-bottom:1.5rem">
        <?= csrf_field() ?>
        <input type="text" name="message" maxlength="300" placeholder="Etwas shouten …" required autofocus>
        <button class="btn" type="submit">Senden</button>
    </form>
    <div class="card glass reveal">
        <?php if (!$msgs): ?><p class="muted">Noch nichts geshoutet – leg los!</p><?php endif; ?>
        <?php foreach ($msgs as $m): ?>
        <div class="comment">
            <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($m['username']) ?>"><?= avatar_html($m) ?></a>
            <div class="comment-body">
                <div class="comment-head">
                    <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($m['username']) ?>" class="comment-author"<?= name_color_style($m) ?>><?= e($m['display_name'] ?: $m['username']) ?></a>
                    <?php if ($m['role'] !== 'member'): ?><span class="badge"><?= e($m['role']) ?></span><?php endif; ?>
                    <span class="muted small"><?= chat_time($m['created_at']) ?></span>
                </div>
                <?= nl2br(e($m['message'])) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
