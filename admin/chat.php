<?php
require_once __DIR__ . '/../includes/functions.php';
require_login(); // Admins UND Moderatoren
$adminCurrent = 'chat';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_channel') {
        $name = trim((string)($_POST['name'] ?? ''));
        $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
        $desc = trim((string)($_POST['description'] ?? ''));
        if ($slug === '') $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        if ($name === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
            flash('error', 'Ungültiger Name/Slug.');
        } else {
            try {
                db()->prepare('INSERT INTO chat_channels (name, slug, description) VALUES (?, ?, ?)')->execute([$name, $slug, $desc]);
                flash('success', 'Channel „' . $name . '" angelegt.');
            } catch (PDOException $e) { flash('error', 'Slug existiert bereits.'); }
        }
    } elseif ($action === 'toggle_channel') {
        db()->prepare('UPDATE chat_channels SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['id']]);
    } elseif ($action === 'delete_channel') {
        $id = (int)$_POST['id'];
        db()->prepare('DELETE FROM chat_messages WHERE channel_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM chat_channel_mods WHERE channel_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM chat_reports WHERE message_id IN (SELECT id FROM chat_messages WHERE channel_id = ?)')->execute([$id]);
        db()->prepare('DELETE FROM chat_channels WHERE id = ?')->execute([$id]);
        flash('success', 'Channel inkl. Nachrichten gelöscht.');
    } elseif ($action === 'add_mod') {
        $chId = (int)$_POST['channel_id'];
        $u = find_user_by_username(trim((string)($_POST['username'] ?? '')));
        if ($u) {
            db()->prepare('INSERT IGNORE INTO chat_channel_mods (channel_id, user_id) VALUES (?, ?)')->execute([$chId, $u['id']]);
            flash('success', 'Mod hinzugefügt.');
        } else { flash('error', 'Benutzer nicht gefunden.'); }
    } elseif ($action === 'remove_mod') {
        db()->prepare('DELETE FROM chat_channel_mods WHERE channel_id = ? AND user_id = ?')
            ->execute([(int)$_POST['channel_id'], (int)$_POST['user_id']]);
    } elseif ($action === 'resolve') {
        db()->prepare("UPDATE chat_reports SET status = 'resolved' WHERE id = ?")->execute([(int)$_POST['id']]);
    } elseif ($action === 'delete_report') {
        db()->prepare('DELETE FROM chat_reports WHERE id = ?')->execute([(int)$_POST['id']]);
    } elseif ($action === 'delete_msg') {
        db()->prepare('DELETE FROM chat_messages WHERE id = ?')->execute([(int)$_POST['id']]);
        db()->prepare('DELETE FROM chat_reports WHERE message_id = ?')->execute([(int)$_POST['id']]);
        flash('success', 'Nachricht gelöscht.');
    }
    header('Location: chat.php');
    exit;
}

$channels = db()->query('SELECT c.*, (SELECT COUNT(*) FROM chat_messages m WHERE m.channel_id = c.id) AS msg_count
                         FROM chat_channels c ORDER BY c.display_order, c.id')->fetchAll();
$channelMods = db()->query("SELECT cm.channel_id, cm.user_id, u.username, u.display_name, c.name AS channel_name
                            FROM chat_channel_mods cm JOIN users u ON u.id = cm.user_id JOIN chat_channels c ON c.id = cm.channel_id
                            ORDER BY c.name, u.username")->fetchAll();
$reports = db()->query("SELECT r.*, rep.username AS reporter, u.username AS author, u.display_name AS author_display, m.message, m.channel_id, c.name AS channel_name
                        FROM chat_reports r
                        JOIN chat_messages m ON m.id = r.message_id
                        JOIN users u ON u.id = m.user_id
                        JOIN users rep ON rep.id = r.reporter_id
                        JOIN chat_channels c ON c.id = m.channel_id
                        WHERE r.status = 'open' ORDER BY r.created_at DESC")->fetchAll();
$latest = db()->query("SELECT m.*, u.username, u.display_name, c.name AS channel_name
                       FROM chat_messages m JOIN users u ON u.id = m.user_id JOIN chat_channels c ON c.id = m.channel_id
                       ORDER BY m.id DESC LIMIT 30")->fetchAll();

include __DIR__ . '/_header.php';
?>
<h1>💬 Chat-Moderation</h1>
<p class="muted">Channels verwalten, Channel-Mods ernennen, Meldungen bearbeiten und Nachrichten löschen.</p>

<div class="grid grid-2">
    <div class="card glass">
        <h3>Channels (<?= count($channels) ?>)</h3>
        <form method="post" class="form-inline" style="margin-bottom:1rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_channel">
            <input type="text" name="name" placeholder="Channel-Name" required>
            <input type="text" name="slug" class="mono" placeholder="slug (optional)">
            <button class="btn btn-sm" type="submit">Erstellen</button>
        </form>
        <table class="admin-table">
            <thead><tr><th>Name</th><th>Slug</th><th>Nachrichten</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($channels as $ch): ?>
            <tr>
                <td><?= e($ch['name']) ?></td>
                <td class="mono small"><?= e($ch['slug']) ?></td>
                <td><?= (int)$ch['msg_count'] ?></td>
                <td>
                    <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_channel">
                        <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
                        <button class="badge <?= $ch['active'] ? 'badge-green' : 'badge-red' ?>" type="submit"><?= $ch['active'] ? 'aktiv' : 'aus' ?></button>
                    </form>
                </td>
                <td class="table-actions">
                    <form method="post" class="inline" data-confirm="Channel inkl. ALLER Nachrichten wirklich löschen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_channel">
                        <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
                        <button class="btn btn-danger btn-sm" type="submit">Löschen</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card glass">
        <h3>Channel-Mods (<?= count($channelMods) ?>)</h3>
        <form method="post" class="form-inline" style="margin-bottom:1rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_mod">
            <select name="channel_id">
                <?php foreach ($channels as $ch): ?><option value="<?= (int)$ch['id'] ?>"># <?= e($ch['name']) ?></option><?php endforeach; ?>
            </select>
            <input type="text" name="username" placeholder="Benutzername" required>
            <button class="btn btn-sm" type="submit">Ernennen</button>
        </form>
        <?php foreach ($channelMods as $m): ?>
        <div class="friend-row">
            <div class="friend-info"><strong>@<?= e($m['username']) ?></strong> <span class="muted small">in # <?= e($m['channel_name']) ?></span></div>
            <form method="post" class="inline" data-confirm="Mod entfernen?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="remove_mod">
                <input type="hidden" name="channel_id" value="<?= (int)$m['channel_id'] ?>">
                <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Entfernen</button>
            </form>
        </div>
        <?php endforeach; ?>
        <?php if (!$channelMods): ?><p class="muted">Noch keine Channel-Mods ernannt.</p><?php endif; ?>
    </div>
</div>

<div class="card glass" style="margin-top:1.5rem">
    <h3>🚩 Offene Meldungen (<?= count($reports) ?>)</h3>
    <table class="admin-table">
        <thead><tr><th>Nachricht</th><th>Autor</th><th>Channel</th><th>Grund</th><th>Durch</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($reports as $r): ?>
        <tr>
            <td class="small" style="max-width:220px"><?= e(mb_strimwidth($r['message'], 0, 80, '…')) ?></td>
            <td>@<?= e($r['author']) ?></td>
            <td># <?= e($r['channel_name']) ?></td>
            <td><span class="badge badge-red"><?= e($r['reason']) ?></span></td>
            <td class="small">@<?= e($r['reporter']) ?></td>
            <td class="table-actions">
                <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resolve">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm" type="submit">✔ Erledigt</button>
                </form>
                <form method="post" class="inline" data-confirm="Nachricht wirklich löschen?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_msg">
                    <input type="hidden" name="id" value="<?= (int)$r['message_id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit">Nachricht löschen</button>
                </form>
                <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_report">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">🗑</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$reports): ?><tr><td colspan="6" class="muted">Keine offenen Meldungen. 🎉</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card glass" style="margin-top:1.5rem">
    <h3>Letzte Nachrichten (Moderation)</h3>
    <table class="admin-table">
        <thead><tr><th>Zeit</th><th>Channel</th><th>Autor</th><th>Nachricht</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($latest as $m): ?>
        <tr>
            <td class="small"><?= date('d.m. H:i', strtotime($m['created_at'])) ?></td>
            <td># <?= e($m['channel_name']) ?></td>
            <td class="small">@<?= e($m['username']) ?></td>
            <td class="small" style="max-width:320px"><?= e(mb_strimwidth($m['message'], 0, 100, '…')) ?></td>
            <td class="table-actions">
                <form method="post" class="inline" data-confirm="Nachricht löschen?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_msg">
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit">🗑</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
