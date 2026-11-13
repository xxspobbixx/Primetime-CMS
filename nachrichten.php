<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
$current = 'messages';
$me = current_member();

// Klassischer Fallback: Nachricht senden (funktioniert auch ohne JavaScript)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    csrf_check();
    $toName = trim((string)($_POST['to'] ?? ''));
    $text   = trim((string)($_POST['message'] ?? ''));
    $to = $toName !== '' ? find_user_by_username($toName) : null;
    if ($to && (int)$to['id'] !== (int)$me['id'] && $text !== '' && mb_strlen($text) <= 2000) {
        db()->prepare('INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)')
            ->execute([$me['id'], $to['id'], $text]);
        db()->prepare('DELETE FROM pm_hidden WHERE (user_id = ? AND partner_id = ?) OR (user_id = ? AND partner_id = ?)')
            ->execute([$me['id'], $to['id'], $to['id'], $me['id']]);
        notify((int)$to['id'], 'message', (int)$me['id'],
            ($me['display_name'] ?: $me['username']) . ' hat dir eine Nachricht geschickt.',
            SITE_URL . '/nachrichten.php?with=' . urlencode($me['username']));
    }
    header('Location: ' . SITE_URL . '/nachrichten.php?with=' . urlencode($toName));
    exit;
}

// Unterhaltung schließen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hide') {
    csrf_check();
    $partner = find_user_by_username(trim((string)($_POST['with'] ?? '')));
    if ($partner) {
        db()->prepare('INSERT IGNORE INTO pm_hidden (user_id, partner_id) VALUES (?, ?)')
            ->execute([$me['id'], $partner['id']]);
        flash('success', 'Unterhaltung geschlossen.');
    }
    header('Location: nachrichten.php');
    exit;
}

// Chat mit einem Partner laden
$withName = trim((string)($_GET['with'] ?? ''));
$partner = $withName !== '' ? find_user_by_username($withName) : null;
$thread = [];

if ($partner && (int)$partner['id'] !== (int)$me['id']) {
    $stmt = db()->prepare('SELECT m.*, m.attachment, s.username AS sender_name, s.avatar AS sender_avatar, s.avatar_color AS sender_color, s.display_name AS sender_display
                           FROM messages m JOIN users s ON s.id = m.sender_id
                           WHERE (m.sender_id = ? AND m.receiver_id = ?)
                              OR (m.sender_id = ? AND m.receiver_id = ?)
                           ORDER BY m.created_at ASC LIMIT 300');
    $stmt->execute([$me['id'], $partner['id'], $partner['id'], $me['id']]);
    $thread = $stmt->fetchAll();
    db()->prepare('UPDATE messages SET read_at = NOW() WHERE sender_id = ? AND receiver_id = ? AND read_at IS NULL')
        ->execute([$partner['id'], $me['id']]);
    $pageTitle = 'Chat mit ' . ($partner['display_name'] ?: $partner['username']);
    $lastId = $thread ? (int)$thread[count($thread) - 1]['id'] : 0;
}

// Unterhaltungsübersicht (ohne geschlossene)
$meId = (int)$me['id'];
$conversations = db()->query("SELECT u.username, u.display_name, u.avatar_color, u.avatar,
        (SELECT message FROM messages m
          WHERE (m.sender_id = $meId AND m.receiver_id = u.id) OR (m.sender_id = u.id AND m.receiver_id = $meId)
          ORDER BY m.created_at DESC LIMIT 1) AS last_msg,
        (SELECT created_at FROM messages m
          WHERE (m.sender_id = $meId AND m.receiver_id = u.id) OR (m.sender_id = u.id AND m.receiver_id = $meId)
          ORDER BY m.created_at DESC LIMIT 1) AS last_at,
        (SELECT COUNT(*) FROM messages WHERE sender_id = u.id AND receiver_id = $meId AND read_at IS NULL) AS unread
    FROM users u
    WHERE u.id != $meId AND u.id NOT IN (SELECT partner_id FROM pm_hidden WHERE user_id = $meId) AND (
        u.id IN (SELECT receiver_id FROM messages WHERE sender_id = $meId) OR
        u.id IN (SELECT sender_id FROM messages WHERE receiver_id = $meId))
    ORDER BY last_at DESC");
$conversations = $conversations->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container reveal"><h1>✉ Nachrichten</h1></div>
</section>
<section class="section">
    <div class="container">
        <?php if ($partner && (int)$partner['id'] !== (int)$me['id']): ?>
        <div class="card glass reveal">
            <div class="card-head chat-head">
                <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($partner['username']) ?>" class="chat-head-user">
                    <?= avatar_html($partner) ?>
                    <div>
                        <strong><?= e($partner['display_name'] ?: $partner['username']) ?></strong>
                        <span class="muted small">@<?= e($partner['username']) ?></span>
                    </div>
                </a>
                <span style="flex:1"></span>
                <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/nachrichten.php">← Unterhaltungen</a>
            </div>
            <div class="chat-box pm-box" id="pmBox"
                 data-with="<?= e($partner['username']) ?>"
                 data-csrf="<?= csrf_token() ?>"
                 data-last="<?= (int)($lastId ?? 0) ?>">
                <?php if (!$thread): ?><p class="muted center" id="pmEmpty">Noch keine Nachrichten – sag Hallo! 👋</p><?php endif; ?>
                <?php
                $lastDay = '';
                foreach ($thread as $msg):
                    $own = (int)$msg['sender_id'] === (int)$me['id'];
                    $day = date('Y-m-d', strtotime($msg['created_at']));
                    if ($day !== $lastDay):
                        $lastDay = $day; ?>
                        <div class="chat-day"><span><?= date('Y-m-d') === $day ? 'Heute' : (date('Y-m-d', strtotime('-1 day')) === $day ? 'Gestern' : date('d.m.Y', strtotime($day))) ?></span></div>
                    <?php endif; ?>
                <div class="chat-row <?= $own ? 'own' : '' ?>" data-id="<?= (int)$msg['id'] ?>">
                    <?php if (!$own): ?>
                    <span class="chat-avatar"><?= avatar_html(['display_name' => $msg['sender_display'], 'username' => $msg['sender_name'], 'avatar' => $msg['sender_avatar'], 'avatar_color' => $msg['sender_color']]) ?></span>
                    <?php endif; ?>
                    <div class="chat-bubble <?= $own ? 'own' : '' ?>">
                        <?= $msg['message'] !== '' ? nl2br(e($msg['message'])) : '' ?>
                        <?php if (!empty($msg['attachment'])):
                            $att = json_decode($msg['attachment'], true); ?>
                        <a class="pm-attach" href="<?= SITE_URL ?>/anhang.php?id=<?= (int)$msg['id'] ?>">📎 <?= e($att['n'] ?? 'Anhang') ?></a>
                        <?php endif; ?>
                        <span class="chat-meta"><?= date('H:i', strtotime($msg['created_at'])) ?><?= $own ? ($msg['read_at'] ? ' · ✔✔ gelesen' : ' · ✔ gesendet') : '' ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <form id="pmForm" class="chat-form" method="post" action="" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="to" value="<?= e($partner['username']) ?>">
                <textarea name="message" rows="2" maxlength="2000" placeholder="Nachricht schreiben …"></textarea>
                <label class="pm-attach-btn" title="Datei anhängen">📎<input type="file" name="attachment" id="pmAttach" hidden></label>
                <span id="pmAttachName" class="muted small"></span>
                <button class="btn" type="submit">Senden</button>
            </form>
        </div>

        <?php else: ?>
        <div class="card glass reveal" style="margin-bottom:1.5rem">
            <form id="pmNewForm" class="form-inline" method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send">
                <input type="text" name="to" class="flex-grow" placeholder="Neue Nachricht an Benutzername …" required>
                <input type="text" name="message" class="flex-grow" placeholder="Nachricht …" maxlength="2000" required>
                <button class="btn" type="submit">Senden</button>
            </form>
        </div>

        <div class="card glass reveal">
            <h3>Unterhaltungen (<?= count($conversations) ?>)</h3>
            <?php if (!$conversations): ?><p class="muted">Noch keine Unterhaltungen vorhanden.</p><?php endif; ?>
            <div class="convo-list">
                <?php foreach ($conversations as $c): ?>
                <div class="convo-item-wrap">
                    <a class="convo-item" href="<?= SITE_URL ?>/nachrichten.php?with=<?= urlencode($c['username']) ?>">
                        <?= avatar_html($c) ?>
                        <div class="convo-meta">
                            <strong><?= e($c['display_name'] ?: $c['username']) ?> <?php if ($c['unread'] > 0): ?><span class="unread-badge"><?= (int)$c['unread'] ?> neu</span><?php endif; ?></strong>
                            <span><?= e(mb_strimwidth($c['last_msg'] ?? '', 0, 60, '…')) ?></span>
                        </div>
                        <time><?= $c['last_at'] ? chat_time($c['last_at']) : '' ?></time>
                    </a>
                    <form method="post" class="convo-close" data-confirm="Unterhaltung wirklich schließen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="hide">
                        <input type="hidden" name="with" value="<?= e($c['username']) ?>">
                        <button type="submit" title="Unterhaltung schließen">✕</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
