<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
$current = 'chat';
$pageTitle = 'Chat';
$me = current_member();

$channels = db()->query('SELECT * FROM chat_channels WHERE active = 1 ORDER BY display_order ASC, id ASC')->fetchAll();
$activeSlug = trim((string)($_GET['channel'] ?? ''));
$channel = null;
foreach ($channels as $ch) {
    if ($ch['slug'] === $activeSlug) { $channel = $ch; break; }
}
if (!$channel && $channels) $channel = $channels[0];

$messages = [];
$canMod = false;
$lastId = 0;
if ($channel) {
    $stmt = db()->prepare("SELECT m.*, u.username, u.display_name, u.avatar, u.avatar_color, u.name_color
                           FROM chat_messages m JOIN users u ON u.id = m.user_id
                           WHERE m.channel_id = ? ORDER BY m.id DESC LIMIT 40");
    $stmt->execute([$channel['id']]);
    $messages = array_reverse($stmt->fetchAll());
    $canMod = can_mod_channel($me, $channel);
    $lastId = $messages ? (int)$messages[count($messages) - 1]['id'] : 0;
}
$online = online_users();

// Klassischer Fallback: Nachricht senden (ohne JavaScript)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    csrf_check();
    $chSlug = trim((string)($_POST['channel'] ?? ''));
    $text   = trim((string)($_POST['message'] ?? ''));
    $stmt = db()->prepare('SELECT * FROM chat_channels WHERE slug = ? AND active = 1');
    $stmt->execute([$chSlug]);
    $ch = $stmt->fetch();
    if ($ch && $text !== '' && mb_strlen($text) <= 1000) {
        db()->prepare('INSERT INTO chat_messages (channel_id, user_id, message) VALUES (?, ?, ?)')
            ->execute([$ch['id'], $me['id'], $text]);
    }
    header('Location: ' . SITE_URL . '/chat.php?channel=' . urlencode($chSlug));
    exit;
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container reveal"><h1>💬 Chat</h1>
    <p class="muted">Community-Channels · <span id="onlineCount"><?= count($online) ?></span> Mitglieder online</p></div>
</section>
<section class="section">
    <div class="container">
        <div class="chat-layout">
            <!-- Seitenleiste: Channels + Online -->
            <aside class="chat-side card glass reveal">
                <div class="side-block">
                    <h4>Channels</h4>
                    <?php foreach ($channels as $ch): ?>
                    <a class="channel-link <?= $channel && $channel['id'] === $ch['id'] ? 'active' : '' ?>"
                       href="<?= SITE_URL ?>/chat.php?channel=<?= urlencode($ch['slug']) ?>"># <?= e($ch['name']) ?></a>
                    <?php endforeach; ?>
                    <?php if (!$channels): ?><p class="muted small">Noch keine Channels.</p><?php endif; ?>
                </div>
                <div class="side-block">
                    <div class="card-head" style="margin-bottom:.4rem">
                        <h4>🟢 Online</h4>
                        <button type="button" id="onlineToggle" class="btn btn-ghost btn-sm" title="Online-Status umschalten">
                            <?= $me['show_online'] ? '🔴 Offline gehen' : '🟢 Online gehen' ?>
                        </button>
                    </div>
                    <div id="onlineList" class="online-list">
                        <?php foreach ($online as $u): ?>
                        <div class="online-user"><?= avatar_html($u) ?><span><?= e($u['display_name'] ?: $u['username']) ?></span><?php if ($u['role'] !== 'member'): ?><span class="badge"><?= e($u['role']) ?></span><?php endif; ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </aside>

            <!-- Hauptbereich: Nachrichten -->
            <div class="card glass reveal">
                <?php if (!$channel): ?>
                    <p class="muted">Kein Channel vorhanden.</p>
                <?php else: ?>
                <div class="card-head">
                    <h3># <?= e($channel['name']) ?></h3>
                    <?php if ($channel['description']): ?><span class="muted small"><?= e($channel['description']) ?></span><?php endif; ?>
                    <?php if ((int)$channel['slow_mode'] > 0): ?><span class="badge" title="Mindestabstand zwischen Nachrichten">⏱ <?= (int)$channel['slow_mode'] ?>s Slow-Mode</span><?php endif; ?>
                </div>
                <div class="chat-box ch-box" id="chBox"
                     data-channel="<?= e($channel['slug']) ?>"
                     data-csrf="<?= csrf_token() ?>"
                     data-last="<?= $lastId ?>"
                     data-mod="<?= $canMod ? '1' : '0' ?>">
                    <?php
            $crCounts = [];
            if ($messages) {
                $mIds = implode(',', array_map('intval', array_column($messages, 'id')));
                foreach (db()->query("SELECT message_id, emoji, COUNT(*) AS c FROM chat_reactions WHERE message_id IN ($mIds) GROUP BY message_id, emoji") as $cr) {
                    $crCounts[$cr['message_id']][$cr['emoji']] = (int)$cr['c'];
                }
            }
            foreach ($messages as $m): ?>
                    <div class="chat-row <?= (int)$m['user_id'] === (int)$me['id'] ? 'own' : '' ?>" data-id="<?= (int)$m['id'] ?>">
                        <span class="chat-avatar"><?= avatar_html(['display_name' => $m['display_name'], 'username' => $m['username'], 'avatar' => $m['avatar'], 'avatar_color' => $m['avatar_color']]) ?></span>
                        <div class="chat-bubble <?= (int)$m['user_id'] === (int)$me['id'] ? 'own' : '' ?>">
                            <span class="bubble-author"<?= name_color_style($m) ?>><?= !empty($m['pinned']) ? '📌 ' : '' ?><?= e($m['display_name'] ?: $m['username']) ?> · <?= date('H:i', strtotime($m['created_at'])) ?><?= !empty($m['edited']) ? ' · ✎' : '' ?></span>
                            <?= nl2br(e($m['message'])) ?>
                        </div>
                        <?php if (!empty($crCounts[$m['id']])): ?>
                        <div class="chat-reactions">
                            <?php foreach ($crCounts[$m['id']] as $em => $cnt): ?>
                            <span class="chat-react-badge" data-emoji="<?= e($em) ?>" onclick="chatReact(<?= (int)$m['id'] ?>, '<?= e($em) ?>')"><?= e($em) ?> <?= $cnt ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($canMod): ?>
                        <button type="button" class="msg-pin" data-id="<?= (int)$m['id'] ?>" title="Anpinnen/Abpinnen"><?= !empty($m['pinned']) ? '📌✕' : '📌' ?></button>
                        <button type="button" class="msg-del" data-id="<?= (int)$m['id'] ?>" title="Nachricht löschen">🗑</button>
                        <?php endif; ?>
                        <button type="button" class="msg-report" data-id="<?= (int)$m['id'] ?>" title="Nachricht melden">🚩</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <form id="chForm" class="chat-form" method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send">
                    <input type="hidden" name="channel" value="<?= e($channel['slug']) ?>">
                    <textarea name="message" rows="2" maxlength="1000" placeholder="Nachricht an #<?= e($channel['name']) ?> …" required></textarea>
                    <button class="btn" type="submit">Senden</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
