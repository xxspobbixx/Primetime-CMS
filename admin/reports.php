<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
$me = current_admin();
$adminCurrent = 'reports';

$tab = $_GET['tab'] ?? 'users';   // users | posts | groups
$showResolved = ($_GET['show'] ?? '') === 'resolved';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'resolve') {
        db()->prepare("UPDATE reports SET status = 'resolved', resolved_at = NOW() WHERE id = ?")->execute([$id]);
        flash('success', 'Meldung als bearbeitet markiert.');
    } elseif ($action === 'resolve_wall') {
        db()->prepare("UPDATE wall_reports SET status = 'resolved', resolved_at = NOW() WHERE id = ?")->execute([$id]);
        flash('success', 'Meldung als bearbeitet markiert.');
    } elseif ($action === 'reopen') {
        db()->prepare("UPDATE reports SET status = 'open', resolved_at = NULL WHERE id = ?")->execute([$id]);
        flash('success', 'Meldung wieder geöffnet.');
    } elseif ($action === 'reopen_wall') {
        db()->prepare("UPDATE wall_reports SET status = 'open', resolved_at = NULL WHERE id = ?")->execute([$id]);
        flash('success', 'Meldung wieder geöffnet.');
    } elseif ($action === 'delete_report') {
        db()->prepare('DELETE FROM reports WHERE id = ?')->execute([$id]);
        flash('success', 'Meldung gelöscht.');
    } elseif ($action === 'delete_wall_report') {
        db()->prepare('DELETE FROM wall_reports WHERE id = ?')->execute([$id]);
        flash('success', 'Meldung gelöscht.');
    } elseif ($action === 'delete_user' && $me['role'] === 'admin') {
        $stmt = db()->prepare('SELECT reported_id FROM reports WHERE id = ?');
        $stmt->execute([$id]);
        $uid = (int)$stmt->fetchColumn();
        if ($uid > 0 && $uid !== (int)$me['id']) {
            delete_user_full($uid);
            flash('success', 'Gemeldetes Mitglied wurde gelöscht.');
        }
    } elseif ($action === 'resolve_group') {
        db()->prepare("UPDATE group_reports SET status = 'resolved', resolved_at = NOW() WHERE id = ?")->execute([$id]);
        flash('success', 'Meldung als bearbeitet markiert.');
    } elseif ($action === 'delete_group' && $me['role'] === 'admin') {
        $stmt = db()->prepare('SELECT group_id FROM group_reports WHERE id = ?');
        $stmt->execute([$id]);
        $grid = (int)$stmt->fetchColumn();
        if ($grid > 0) {
            db()->prepare('DELETE FROM group_comments WHERE post_id IN (SELECT id FROM group_posts WHERE group_id = ?)')->execute([$grid]);
            $s = db()->prepare('SELECT image FROM group_posts WHERE group_id = ?');
            $s->execute([$grid]);
            foreach ($s->fetchAll() as $p) { if (!empty($p['image'])) delete_upload_file($p['image'], 'wall'); }
            db()->prepare('DELETE FROM group_posts WHERE group_id = ?')->execute([$grid]);
            db()->prepare('DELETE FROM group_members WHERE group_id = ?')->execute([$grid]);
            db()->prepare('DELETE FROM group_reports WHERE group_id = ?')->execute([$grid]);
            db()->prepare('DELETE FROM groups_tbl WHERE id = ?')->execute([$grid]);
            mod_log('Gemeldete Gruppe gelöscht', 'Gruppe #' . $grid);
            flash('success', 'Gruppe wurde gelöscht.');
        }
    } elseif ($action === 'delete_post' && $me['role'] === 'admin') {
        delete_wall_post_full($id); // $id = report-id -> post ermitteln
        flash('success', 'Beitrag wurde gelöscht.');
    }
    $qs = '?tab=' . urlencode($tab) . ($showResolved ? '&show=resolved' : '');
    header('Location: reports.php' . $qs);
    exit;
}

/** Löscht einen Wall-Beitrag inkl. Reaktionen, Kommentaren und Meldungen. */
function delete_wall_post_full(int $reportId): void
{
    $stmt = db()->prepare('SELECT r.post_id, p.image FROM wall_reports r JOIN wall_posts p ON p.id = r.post_id WHERE r.id = ?');
    $stmt->execute([$reportId]);
    $row = $stmt->fetch();
    $postId = (int)($row['post_id'] ?? 0);
    if (!empty($row['image'])) delete_upload_file($row['image'], 'wall');
    if ($postId <= 0) return;
    db()->prepare('DELETE FROM wall_reactions WHERE post_id = ?')->execute([$postId]);
    db()->prepare('DELETE FROM wall_comments WHERE post_id = ?')->execute([$postId]);
    db()->prepare('DELETE FROM wall_reports WHERE post_id = ?')->execute([$postId]);
    db()->prepare('DELETE FROM wall_posts WHERE id = ?')->execute([$postId]);
}

/** Löscht ein Mitglied vollständig inkl. aller Community-Daten. */
function delete_user_full(int $uid): void
{
    $stmt = db()->prepare('SELECT avatar FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    delete_avatar_file($stmt->fetchColumn());
    db()->prepare('DELETE FROM friendships WHERE user_id = ? OR friend_id = ?')->execute([$uid, $uid]);
    db()->prepare('DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?')->execute([$uid, $uid]);
    db()->prepare('DELETE FROM reports WHERE reporter_id = ? OR reported_id = ?')->execute([$uid, $uid]);
    // Wall-Daten des Mitglieds (inkl. Bilddateien)
    delete_user_wall_posts($uid);
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
}

$openUsers = (int)db()->query("SELECT COUNT(*) FROM reports WHERE status = 'open'")->fetchColumn();
$openPosts = (int)db()->query("SELECT COUNT(*) FROM wall_reports WHERE status = 'open'")->fetchColumn();
$openGroups = (int)db()->query("SELECT COUNT(*) FROM group_reports WHERE status = 'open'")->fetchColumn();

if ($tab === 'groups') {
    $reports = db()->prepare("SELECT r.*, rep.username AS reporter, g.name AS group_name, g.privacy
                              FROM group_reports r
                              JOIN users rep ON rep.id = r.reporter_id
                              JOIN groups_tbl g ON g.id = r.group_id
                              WHERE r.status = ?
                              ORDER BY r.created_at DESC");
    $reports->execute([$showResolved ? 'resolved' : 'open']);
} else

if ($tab === 'posts') {
    $reports = db()->prepare("SELECT r.*, rep.username AS reporter_name,
                                     p.content AS post_content, p.id AS post_id,
                                     au.username AS author_name, au.display_name AS author_display
                              FROM wall_reports r
                              JOIN users rep ON rep.id = r.reporter_id
                              JOIN wall_posts p ON p.id = r.post_id
                              JOIN users au ON au.id = p.user_id
                              WHERE r.status = ?
                              ORDER BY r.created_at DESC");
    $reports->execute([$showResolved ? 'resolved' : 'open']);
} else {
    $reports = db()->prepare("SELECT r.*, rep.username AS reporter_name, rep.display_name AS reporter_display,
                                     tgt.username AS target_name, tgt.display_name AS target_display
                              FROM reports r
                              JOIN users rep ON rep.id = r.reporter_id
                              JOIN users tgt ON tgt.id = r.reported_id
                              WHERE r.status = ?
                              ORDER BY r.created_at DESC");
    $reports->execute([$showResolved ? 'resolved' : 'open']);
}
$reports = $reports->fetchAll();

include __DIR__ . '/_header.php';
?>
<h1>Meldungen</h1>
<p class="muted">Gemeldete Mitglieder und Wall-Beiträge prüfen und bearbeiten.
    <br><span class="small">„Benutzer löschen" steht nur Admins zur Verfügung – Moderatoren können Beiträge und Meldungen bearbeiten.</span></p>

<div class="filter-row">
    <a class="pill <?= $tab === 'users' && !$showResolved ? 'pill-active' : '' ?>" href="?tab=users">Mitglieder (<?= $openUsers ?> offen)</a>
    <a class="pill <?= $tab === 'posts' && !$showResolved ? 'pill-active' : '' ?>" href="?tab=posts">Beiträge (<?= $openPosts ?> offen)</a>
    <a class="pill <?= $tab === 'groups' && !$showResolved ? 'pill-active' : '' ?>" href="?tab=groups">Gruppen (<?= $openGroups ?> offen)</a>
    <a class="pill <?= $showResolved ? 'pill-active' : '' ?>" href="?tab=<?= urlencode($tab) ?>&show=resolved">Bearbeitet</a>
</div>

<div class="card glass">
    <?php if (!$reports): ?>
        <p class="muted">Keine <?= $showResolved ? 'bearbeiteten' : 'offenen' ?> Meldungen. 🎉</p>
    <?php else: ?>
    <table class="admin-table">
        <thead><tr>
            <th><?= $tab === 'posts' ? 'Beitrag' : 'Gemeldet' ?></th><th>Grund</th><th>Details</th><th>Durch</th><th>Datum</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($reports as $r): ?>
            <tr>
                <td>
                    <?php if ($tab === 'groups'): ?>
                        <span class="small">👥 <?= e($r['group_name']) ?> (<?= e($r['privacy']) ?>)</span><br>
                        <span class="muted small">gemeldet von @<?= e($r['reporter']) ?></span>
                    <?php elseif ($tab === 'posts'): ?>
                        <span class="small">„<?= e(mb_strimwidth($r['post_content'], 0, 60, '…')) ?>"</span><br>
                        <span class="muted small">von @<?= e($r['author_name']) ?></span>
                    <?php else: ?>
                        <a href="../profil.php?u=<?= urlencode($r['target_name']) ?>" target="_blank"><?= e($r['target_display'] ?: $r['target_name']) ?></a>
                        <br><span class="muted small">@<?= e($r['target_name']) ?></span>
                    <?php endif; ?>
                </td>
                <td><span class="badge badge-red"><?= e($r['reason']) ?></span></td>
                <td class="small" style="max-width:260px"><?= e($r['details']) ?></td>
                <td class="small">@<?= e($r['reporter_name']) ?></td>
                <td class="small"><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></td>
                <td class="table-actions">
                    <?php if (!$showResolved): ?>
                    <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?= $tab === 'groups' ? 'resolve_group' : ($tab === 'posts' ? 'resolve_wall' : 'resolve') ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm" type="submit">✔ Erledigt</button>
                    </form>
                    <form method="post" class="inline" data-confirm="Wirklich löschen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?= $tab === 'groups' ? 'delete_group' : ($tab === 'posts' ? 'delete_post' : 'delete_user') ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-danger btn-sm" type="submit"><?= $tab === 'groups' ? 'Gruppe löschen' : ($tab === 'posts' ? 'Beitrag löschen' : 'Benutzer löschen') ?></button>
                    </form>
                    <?php else: ?>
                    <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?= $tab === 'posts' ? 'reopen_wall' : 'reopen' ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit">Wieder öffnen</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" class="inline" data-confirm="Meldung wirklich löschen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?= $tab === 'posts' ? 'delete_wall_report' : 'delete_report' ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit">🗑</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
