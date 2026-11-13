<?php
require_once __DIR__ . '/includes/functions.php';
ensure_feature_tables();
require_member();
if (!plugin_active('groups')) {
    flash('error', 'Diese Seite ist derzeit deaktiviert.');
    header('Location: ' . SITE_URL . '/wall.php');
    exit;
}
$current = 'groups';
$pageTitle = 'Gruppen';
$me = current_member();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $priv = (string)($_POST['privacy'] ?? 'public');
        if ($name === '' || mb_strlen($name) > 100) {
            flash('error', 'Bitte einen Gruppennamen angeben (max. 100 Zeichen).');
        } elseif (!in_array($priv, ['public', 'private'], true)) {
            flash('error', 'Ungültige Sichtbarkeit.');
        } else {
            db()->prepare('INSERT INTO groups_tbl (name, description, privacy, creator_id) VALUES (?, ?, ?, ?)')
                ->execute([$name, $desc !== '' ? $desc : null, $priv, $me['id']]);
            $gid = (int)db()->lastInsertId();
            db()->prepare("INSERT INTO group_members (group_id, user_id, role, status) VALUES (?, ?, 'admin', 'approved')")
                ->execute([$gid, $me['id']]);
            discord_notify('👥 Neue Gruppe', '**' . $me['username'] . '** hat die Gruppe **' . $name . '** erstellt.', 3066993);
            flash('success', 'Gruppe „' . $name . '" erstellt – du bist Admin!');
            header('Location: ' . SITE_URL . '/gruppe.php?id=' . $gid);
            exit;
        }
    }
    header('Location: gruppen.php');
    exit;
}

$groups = db()->query("SELECT g.*, u.username AS creator_name, u.display_name AS creator_display,
    (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id AND m.status = 'approved') AS members,
    EXISTS(SELECT 1 FROM group_members m WHERE m.group_id = g.id AND m.user_id = " . (int)$me['id'] . " AND m.status = 'approved') AS is_member,
    EXISTS(SELECT 1 FROM group_members m WHERE m.group_id = g.id AND m.user_id = " . (int)$me['id'] . " AND m.status = 'pending') AS is_pending
    FROM groups_tbl g JOIN users u ON u.id = g.creator_id
    ORDER BY g.id DESC LIMIT 50")->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal">
    <h1>👥 Gruppen</h1>
    <p class="muted">Finde Gleichgesinnte – oder gründe deine eigene Gruppe!</p></div></section>
<section class="section"><div class="container">
    <div class="card glass reveal" style="margin-bottom:1.5rem">
        <h3>➕ Neue Gruppe gründen</h3>
        <form method="post" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-grid">
                <label>Gruppenname <input type="text" name="name" maxlength="100" required></label>
                <label>Sichtbarkeit
                    <select name="privacy">
                        <option value="public">🌍 Öffentlich – jeder kann beitreten &amp; mitlesen</option>
                        <option value="private">🔒 Privat – nur Mitglieder sehen Inhalte (Anfrage nötig)</option>
                    </select>
                </label>
            </div>
            <label>Beschreibung <textarea name="description" rows="2" maxlength="500" placeholder="Worum geht es in der Gruppe?"></textarea></label>
            <button class="btn" type="submit">Gruppe erstellen</button>
        </form>
    </div>

    <?php if (!$groups): ?>
        <div class="card glass reveal"><p class="muted">Noch keine Gruppen – gründe die erste!</p></div>
    <?php else: ?>
    <div class="grid grid-3">
        <?php foreach ($groups as $g): ?>
        <div class="card glass reveal group-card">
            <div class="card-head">
                <?php if (!empty($g['image'])): ?>
                <img src="<?= SITE_URL ?>/uploads/wall/<?= rawurlencode($g['image']) ?>" alt="" class="group-avatar">
                <?php else: ?>
                <span class="group-icon"><?= $g['privacy'] === 'private' ? '🔒' : '🌍' ?></span>
                <?php endif; ?>
                <h3><a href="<?= SITE_URL ?>/gruppe.php?id=<?= (int)$g['id'] ?>" style="color:var(--text)"><?= e($g['name']) ?></a></h3>
                <span class="badge"><?= (int)$g['members'] ?> Mitglied(er)</span>
            </div>
            <?php if ($g['description']): ?><p class="muted small"><?= e(mb_strimwidth($g['description'], 0, 90, '…')) ?></p><?php endif; ?>
            <p class="muted small">von <?= e($g['creator_display'] ?: $g['creator_name']) ?></p>
            <a class="btn btn-sm" href="<?= SITE_URL ?>/gruppe.php?id=<?= (int)$g['id'] ?>">
                <?php if ($g['is_member']): ?>Gruppe öffnen ✔<?php elseif ($g['is_pending']): ?>Anfrage läuft…<?php else: ?>Beitreten →<?php endif; ?>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
