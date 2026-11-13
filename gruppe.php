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
$me = current_member();
$meId = (int)$me['id'];
$gid = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT g.*, u.username AS creator_name, u.display_name AS creator_display
                       FROM groups_tbl g JOIN users u ON u.id = g.creator_id WHERE g.id = ?');
ensure_feature_tables();
$stmt->execute([$gid]);
$g = $stmt->fetch();
if (!$g) { flash('error', 'Gruppe nicht gefunden.'); header('Location: ' . SITE_URL . '/gruppen.php'); exit; }

// Mitgliedschaft
$stmt = db()->prepare('SELECT role, status FROM group_members WHERE group_id = ? AND user_id = ?');
$stmt->execute([$gid, $meId]);
$my = $stmt->fetch();
$isMember   = $my && $my['status'] === 'approved';
$isPending  = $my && $my['status'] === 'pending';
$isSiteStaff = in_array($me['role'], ['admin', 'moderator'], true);
$isAdmin    = ($my && $my['role'] === 'admin') || $me['role'] === 'admin';
// Admins & Mods sehen ALLE Gruppen-Inhalte – keine Privatsphäre-Beschränkung
$canView    = $g['privacy'] === 'public' || $isMember || $isSiteStaff;
// Inhalte löschen dürfen: Gruppen-Admin ODER jeglicher Staff
$canMod     = $isAdmin || $isSiteStaff || ($my && $my['role'] === 'mod' && $my['status'] === 'approved');

/* ================= POST ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'post' && $isMember) {
        $content = filter_words(trim((string)($_POST['content'] ?? '')));
        if ($content === '') {
            flash('error', 'Bitte erst einen Text schreiben.');
        } elseif (mb_strlen($content) > 1000) {
            flash('error', 'Beitrag leer oder zu lang (max. 1000 Zeichen).');
        } else {
            $image = null;
            if (!empty($_FILES['image']['name'])) {
                try { $image = save_wall_image($_FILES['image']); }
                catch (RuntimeException $ex) { flash('error', 'Bild: ' . $ex->getMessage()); header('Location: ' . SITE_URL . '/gruppe.php?id=' . $gid); exit; }
            }
            db()->prepare('INSERT INTO group_posts (group_id, user_id, content, image) VALUES (?, ?, ?, ?)')
                ->execute([$gid, $meId, $content, $image]);
        }
    } elseif ($action === 'pin' && $canMod) {
        db()->prepare('UPDATE group_posts SET pinned = 1 - pinned WHERE id = ? AND group_id = ?')
            ->execute([(int)$_POST['id'], $gid]);
        flash('success', 'Beitrag angepinnt/abgepinnt.');
    } elseif ($action === 'delete_post') {
        $pid = (int)$_POST['id'];
        if ($canMod) {
            $s = db()->prepare('SELECT image FROM group_posts WHERE id = ? AND group_id = ?');
            $s->execute([$pid, $gid]);
        } else {
            $s = db()->prepare('SELECT image FROM group_posts WHERE id = ? AND group_id = ? AND user_id = ?');
            $s->execute([$pid, $gid, $meId]);
        }
        $row = $s->fetch();
        if ($row) {
            db()->prepare('DELETE FROM group_posts WHERE id = ?')->execute([$pid]);
            db()->prepare('DELETE FROM group_comments WHERE post_id = ?')->execute([$pid]);
            if (!empty($row['image'])) delete_upload_file($row['image'], 'wall');
        }
    } elseif ($action === 'comment' && $isMember) {
        $pid = (int)($_POST['post_id'] ?? 0);
        $content = filter_words(trim((string)($_POST['content'] ?? '')));
        if ($content !== '' && mb_strlen($content) <= 500) {
            db()->prepare('INSERT INTO group_comments (post_id, user_id, content) VALUES (?, ?, ?)')->execute([$pid, $meId, $content]);
        }
    } elseif ($action === 'delete_comment') {
        $cid = (int)$_POST['id'];
        if ($canMod) {
            db()->prepare('DELETE FROM group_comments WHERE id = ? AND post_id IN (SELECT id FROM group_posts WHERE group_id = ?)')->execute([$cid, $gid]);
        } else {
            db()->prepare('DELETE FROM group_comments WHERE id = ? AND user_id = ?')->execute([$cid, $meId]);
        }
    } elseif ($action === 'join' && !$my && $g['privacy'] === 'public') {
        db()->prepare("INSERT IGNORE INTO group_members (group_id, user_id, role, status) VALUES (?, ?, 'member', 'approved')")->execute([$gid, $meId]);
        flash('success', 'Willkommen in der Gruppe!');
    } elseif ($action === 'request' && !$my && $g['privacy'] === 'private') {
        db()->prepare("INSERT IGNORE INTO group_members (group_id, user_id, role, status) VALUES (?, ?, 'member', 'pending')")->execute([$gid, $meId]);
        $admins = db()->query("SELECT user_id FROM group_members WHERE group_id = $gid AND role = 'admin' AND status = 'approved'");
        foreach ($admins->fetchAll() as $a) {
            notify((int)$a['user_id'], 'group_request', $meId,
                ($me['display_name'] ?: $me['username']) . ' möchte der Gruppe „' . $g['name'] . '" beitreten.',
                SITE_URL . '/gruppe.php?id=' . $gid);
        }
        flash('success', 'Beitrittsanfrage gesendet!');
    } elseif ($action === 'leave' && $isMember) {
        if ((int)$g['creator_id'] === $meId) {
            flash('error', 'Gründer können die Gruppe nicht verlassen – lösche sie ggf.');
        } else {
            db()->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')->execute([$gid, $meId]);
            flash('success', 'Gruppe verlassen.');
        }
    } elseif ($action === 'approve' && $isAdmin) {
        $uid = (int)$_POST['user_id'];
        db()->prepare("UPDATE group_members SET status = 'approved' WHERE group_id = ? AND user_id = ? AND status = 'pending'")->execute([$gid, $uid]);
        notify($uid, 'group_approved', $meId, 'Deine Anfrage für die Gruppe „' . $g['name'] . '" wurde angenommen!', SITE_URL . '/gruppe.php?id=' . $gid);
        flash('success', 'Mitglied aufgenommen.');
    } elseif ($action === 'decline' && $isAdmin) {
        db()->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ? AND status = 'pending'")->execute([$gid, (int)$_POST['user_id']]);
    } elseif ($action === 'mod' && $isAdmin) {
        $uid = (int)$_POST['user_id'];
        if ($uid !== (int)$g['creator_id']) {
            $cur = db()->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ? AND status = 'approved'");
            $cur->execute([$gid, $uid]);
            $newRole = $cur->fetchColumn() === 'mod' ? 'member' : 'mod';
            db()->prepare('UPDATE group_members SET role = ? WHERE group_id = ? AND user_id = ?')
                ->execute([$newRole, $gid, $uid]);
            flash('success', 'Gruppen-Rolle geändert: ' . $newRole);
        }
    } elseif ($action === 'kick' && $isAdmin) {
        $uid = (int)$_POST['user_id'];
        if ($uid !== (int)$g['creator_id']) {
            db()->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ? AND role != 'admin'")->execute([$gid, $uid]);
            flash('success', 'Mitglied entfernt.');
        }
    } elseif ($action === 'poll_create' && $isAdmin) {
        $q = trim((string)($_POST['question'] ?? ''));
        $opts = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($_POST['options'] ?? '')))));
        if ($q === '' || count($opts) < 2) {
            flash('error', 'Frage + mindestens 2 Optionen angeben.');
        } else {
            db()->query('UPDATE polls SET active = 0 WHERE group_id = ' . $gid);
            db()->prepare('INSERT INTO polls (question, created_by, group_id) VALUES (?, ?, ?)')
                ->execute([$q, $meId, $gid]);
            $pid = (int)db()->lastInsertId();
            $stmt = db()->prepare('INSERT INTO poll_options (poll_id, text, display_order) VALUES (?, ?, ?)');
            foreach (array_slice($opts, 0, 10) as $i => $opt) { $stmt->execute([$pid, mb_substr($opt, 0, 255), $i]); }
            flash('success', 'Gruppen-Umfrage erstellt!');
        }
    } elseif ($action === 'poll_vote' && $isMember) {
        $optionId = (int)($_POST['option_id'] ?? 0);
        $ps = db()->prepare('SELECT poll_id FROM poll_options WHERE id = ?');
        $ps->execute([$optionId]);
        $pollId = (int)$ps->fetchColumn();
        $ok = db()->query('SELECT id FROM polls WHERE id = ' . $pollId . ' AND group_id = ' . $gid . ' AND active = 1')->fetch();
        if ($ok) {
            db()->prepare('INSERT IGNORE INTO poll_votes (poll_id, option_id, user_id) VALUES (?, ?, ?)')
                ->execute([$pollId, $optionId, $meId]);
            flash('success', 'Stimme abgegeben!');
        }
    } elseif ($action === 'event_join' && $isMember) {
        $eid = (int)$_POST['event_id'];
        $ok = db()->query('SELECT id FROM events WHERE id = ' . $eid . ' AND group_id = ' . $gid)->fetch();
        if ($ok) {
            db()->prepare('INSERT IGNORE INTO event_participants (event_id, user_id) VALUES (?, ?)')
                ->execute([$eid, $meId]);
            flash('success', 'Du nimmst teil! ✔');
        }
    } elseif ($action === 'event_leave' && $isMember) {
        db()->prepare('DELETE FROM event_participants WHERE event_id = ? AND user_id = ?')
            ->execute([(int)$_POST['event_id'], $meId]);
        flash('success', 'Teilnahme zurückgezogen.');
    } elseif ($action === 'event_edit' && $isAdmin) {
        $eid = (int)$_POST['event_id'];
        $title = trim((string)($_POST['title'] ?? ''));
        $date = trim((string)($_POST['event_date'] ?? ''));
        $own = db()->prepare('SELECT id FROM events WHERE id = ? AND group_id = ? AND created_by = ?');
        $own->execute([$eid, $gid, $meId]);
        if (($own->fetch() || $me['role'] === 'admin') && $title !== '' && strtotime($date) !== false) {
            db()->prepare('UPDATE events SET title = ?, event_date = ?, description = ? WHERE id = ? AND group_id = ?')
                ->execute([$title, date('Y-m-d H:i:s', strtotime($date)), trim((string)($_POST['description'] ?? '')), $eid, $gid]);
            flash('success', 'Event aktualisiert.');
        }
    } elseif ($action === 'event_delete' && $isAdmin) {
        $eid = (int)$_POST['event_id'];
        $own = db()->prepare('SELECT id FROM events WHERE id = ? AND group_id = ? AND created_by = ?');
        $own->execute([$eid, $gid, $meId]);
        if ($own->fetch() || $me['role'] === 'admin') {
            db()->prepare('DELETE FROM event_participants WHERE event_id = ?')->execute([$eid]);
            db()->prepare('DELETE FROM events WHERE id = ? AND group_id = ?')->execute([$eid, $gid]);
            flash('success', 'Event gelöscht.');
        }
    } elseif ($action === 'event' && $isAdmin) {
        $title = trim((string)($_POST['title'] ?? ''));
        $date = trim((string)($_POST['event_date'] ?? ''));
        if ($title !== '' && strtotime($date) !== false) {
            db()->prepare('INSERT INTO events (title, event_date, description, created_by, group_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$title, date('Y-m-d H:i:s', strtotime($date)), trim((string)($_POST['description'] ?? '')), $meId, $gid]);
            flash('success', 'Gruppen-Event erstellt.');
        } else {
            flash('error', 'Titel und gültiges Datum angeben.');
        }
    } elseif ($action === 'image' && $isAdmin) {
        if (!empty($_FILES['group_image']['name'])) {
            try {
                $f = $_FILES['group_image'];
                if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload fehlgeschlagen.');
                if ($f['size'] > 5 * 1024 * 1024) throw new RuntimeException('Bild größer als 5 MB.');
                $info = @getimagesize($f['tmp_name']);
                if ($info === false) throw new RuntimeException('Kein gültiges Bild.');
                $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
                if (!isset($allowed[$info[2]])) throw new RuntimeException('Nur JPG, PNG oder WEBP.');
                $dir = APP_ROOT . '/uploads/wall';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $token = 'group_' . $gid . '_' . bin2hex(random_bytes(6));
                if (extension_loaded('gd')) {
                    $src = null;
                    switch ($info[2]) {
                        case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($f['tmp_name']); break;
                        case IMAGETYPE_PNG:  $src = imagecreatefrompng($f['tmp_name']); break;
                        case IMAGETYPE_WEBP: $src = imagecreatefromwebp($f['tmp_name']); break;
                    }
                    if (!$src) throw new RuntimeException('Verarbeitung fehlgeschlagen.');
                    $maxW = 400;
                    $w = $info[0]; $h = $info[1];
                    if ($w > $maxW) { $newW = $maxW; $newH = max(1, (int)round($h * $maxW / $w)); }
                    else { $newW = $w; $newH = $h; }
                    $dst = imagecreatetruecolor($newW, $newH);
                    if ($info[2] === IMAGETYPE_PNG) { imagealphablending($dst, false); imagesavealpha($dst, true); }
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
                    $filename = $token . '.jpg';
                    imagejpeg($dst, $dir . '/' . $filename, 85);
                    imagedestroy($src); imagedestroy($dst);
                } else {
                    $filename = $token . '.' . $allowed[$info[2]];
                    move_uploaded_file($f['tmp_name'], $dir . '/' . $filename);
                }
                if (!empty($g['image'])) delete_upload_file($g['image'], 'wall');
                db()->prepare('UPDATE groups_tbl SET image = ? WHERE id = ?')->execute([$filename, $gid]);
                flash('success', 'Gruppenbild aktualisiert.');
            } catch (RuntimeException $ex) {
                flash('error', 'Gruppenbild: ' . $ex->getMessage());
            }
        }
        header('Location: ' . SITE_URL . '/gruppe.php?id=' . $gid);
        exit;
    } elseif ($action === 'edit' && $isAdmin) {
        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $priv = in_array($_POST['privacy'] ?? '', ['public', 'private'], true) ? $_POST['privacy'] : 'public';
        if ($name !== '') {
            db()->prepare('UPDATE groups_tbl SET name = ?, description = ?, privacy = ? WHERE id = ?')
                ->execute([$name, $desc !== '' ? $desc : null, $priv, $gid]);
            flash('success', 'Gruppe aktualisiert.');
        }
    } elseif ($action === 'delete' && ((int)$g['creator_id'] === $meId || $me['role'] === 'admin')) {
        db()->prepare('DELETE FROM group_comments WHERE post_id IN (SELECT id FROM group_posts WHERE group_id = ?)')->execute([$gid]);
        $s = db()->prepare('SELECT image FROM group_posts WHERE group_id = ?');
        $s->execute([$gid]);
        foreach ($s->fetchAll() as $p) { if (!empty($p['image'])) delete_upload_file($p['image'], 'wall'); }
        db()->prepare('DELETE FROM group_posts WHERE group_id = ?')->execute([$gid]);
        db()->prepare('DELETE FROM group_members WHERE group_id = ?')->execute([$gid]);
        db()->prepare('DELETE FROM groups_tbl WHERE id = ?')->execute([$gid]);
        mod_log('Gruppe gelöscht', $g['name']);
        flash('success', 'Gruppe gelöscht.');
        header('Location: ' . SITE_URL . '/gruppen.php');
        exit;
    } elseif ($action === 'report') {
        $reasonCat = (string)($_POST['reason'] ?? '');
        $details   = trim((string)($_POST['details'] ?? ''));
        $reasons   = ['Belästigung', 'Spam', 'Unangemessener Inhalt', 'Hassrede', 'Sonstiges'];
        if (!in_array($reasonCat, $reasons, true) || mb_strlen($details) < 5) {
            flash('error', 'Bitte Grund wählen und Beschreibung angeben (min. 5 Zeichen).');
        } else {
            $stmt = db()->prepare("SELECT id FROM group_reports WHERE group_id = ? AND reporter_id = ? AND status = 'open'");
            $stmt->execute([$gid, $meId]);
            if ($stmt->fetch()) {
                flash('error', 'Du hast diese Gruppe bereits gemeldet.');
            } else {
                db()->prepare('INSERT INTO group_reports (group_id, reporter_id, reason, details) VALUES (?, ?, ?, ?)')
                    ->execute([$gid, $meId, $reasonCat, $details]);
                discord_notify('🚩 Gruppe gemeldet',
                    '**@' . $me['username'] . '** hat die Gruppe **' . $g['name'] . '** gemeldet. Grund: ' . $reasonCat,
                    15105570);
                flash('success', 'Gruppe gemeldet – danke für deinen Hinweis!');
            }
        }
    }
    header('Location: ' . SITE_URL . '/gruppe.php?id=' . $gid);
    exit;
}

/* ================= Daten laden ================= */
$members = db()->query("SELECT m.user_id, m.role, m.status, u.username, u.display_name, u.avatar, u.avatar_color
    FROM group_members m JOIN users u ON u.id = m.user_id
    WHERE m.group_id = $gid ORDER BY m.status DESC, m.role = 'admin' DESC, m.joined_at ASC")->fetchAll();
$pending = array_filter($members, fn($m) => $m['status'] === 'pending');
$approved = array_filter($members, fn($m) => $m['status'] === 'approved');

$posts = [];
$commentMap = [];
if ($canView) {
    $posts = db()->query("SELECT p.*, u.username, u.display_name, u.avatar, u.avatar_color, u.role, u.name_color
        FROM group_posts p JOIN users u ON u.id = p.user_id
        WHERE p.group_id = $gid ORDER BY p.pinned DESC, p.created_at DESC LIMIT 50")->fetchAll();
    if ($posts) {
        $ids = implode(',', array_map('intval', array_column($posts, 'id')));
        foreach (db()->query("SELECT c.*, u.username, u.display_name, u.avatar, u.avatar_color
            FROM group_comments c JOIN users u ON u.id = c.user_id
            WHERE c.post_id IN ($ids) ORDER BY c.created_at ASC") as $c) {
            $commentMap[$c['post_id']][] = $c;
        }
    }
}

$pageTitle = 'Gruppe: ' . $g['name'];
include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal">
    <p class="muted small"><a href="<?= SITE_URL ?>/gruppen.php">← Alle Gruppen</a></p>
    <div class="group-hero">
        <?php if (!empty($g['image'])): ?>
        <img src="<?= SITE_URL ?>/uploads/wall/<?= rawurlencode($g['image']) ?>" alt="" class="group-avatar-lg">
        <?php else: ?>
        <span class="group-icon-lg"><?= $g['privacy'] === 'private' ? '🔒' : '🌍' ?></span>
        <?php endif; ?>
        <div>
            <h1><?= e($g['name']) ?></h1>
            <p class="muted"> <?= $g['privacy'] === 'private' ? 'Private Gruppe' : 'Öffentliche Gruppe' ?> · <?= count($approved) ?> Mitglied(er) · von <?= e($g['creator_display'] ?: $g['creator_name']) ?></p>
        </div>
    </div>
    <div class="profile-actions">
        <?php if ($isMember): ?>
            <?php if ((int)$g['creator_id'] !== $meId): ?>
            <form method="post" data-confirm="Gruppe wirklich verlassen?">
                <?= csrf_field() ?><input type="hidden" name="action" value="leave">
                <button class="btn btn-ghost btn-sm">Gruppe verlassen</button>
            </form>
            <?php endif; ?>
        <?php elseif ($isPending): ?>
            <button class="btn btn-ghost btn-sm" disabled>⏳ Anfrage läuft…</button>
        <?php elseif ($g['privacy'] === 'public'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="join">
                <button class="btn btn-sm">Beitreten</button></form>
        <?php else: ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="request">
                <button class="btn btn-sm">Beitrittsanfrage senden</button></form>
        <?php endif; ?>
        <details class="wall-report">
            <summary class="btn btn-ghost btn-sm" title="Gruppe melden">🚩</summary>
            <div class="wall-report-form" style="width:280px">
                <form method="post" class="form-stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="report">
                    <select name="reason">
                        <option>Belästigung</option><option>Spam</option>
                        <option>Unangemessener Inhalt</option><option>Hassrede</option><option>Sonstiges</option>
                    </select>
                    <textarea name="details" rows="2" maxlength="2000" placeholder="Was ist das Problem? (min. 5 Zeichen)" required></textarea>
                    <button class="btn btn-danger btn-sm" type="submit">Gruppe melden</button>
                </form>
            </div>
        </details>
        <?php if ($isAdmin): ?>
        <details class="wall-report"><summary class="btn btn-ghost btn-sm">⚙️ Verwaltung</summary>
            <div class="wall-report-form" style="width:320px">
                <form method="post" enctype="multipart/form-data" class="form-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="edit">
                    <input type="text" name="name" value="<?= e($g['name']) ?>" required>
                    <textarea name="description" rows="2" maxlength="500"><?= e($g['description']) ?></textarea>
                    <select name="privacy">
                        <option value="public" <?= $g['privacy'] === 'public' ? 'selected' : '' ?>>🌍 Öffentlich</option>
                        <option value="private" <?= $g['privacy'] === 'private' ? 'selected' : '' ?>>🔒 Privat</option>
                    </select>
                    <button class="btn btn-sm" type="submit">Speichern</button>
                </form>
                <form method="post" enctype="multipart/form-data" class="form-stack" style="margin-top:.6rem;border-top:1px dashed var(--border);padding-top:.6rem">
                    <?= csrf_field() ?><input type="hidden" name="action" value="image">
                    <label style="font-size:.8rem">Gruppenbild (400px, JPG/PNG/WEBP)
                        <input type="file" name="group_image" accept="image/jpeg,image/png,image/webp" required style="font-size:.78rem;padding:.4rem">
                    </label>
                    <button class="btn btn-ghost btn-sm" type="submit">Bild hochladen</button>
                </form>
            </div>
        </details>
        <form method="post" class="inline" data-confirm="GANZE Gruppe inkl. aller Beiträge löschen?">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete">
            <button class="btn btn-danger btn-sm">Gruppe löschen</button>
        </form>
        <?php endif; ?>
    </div>
</div></section>

<section class="section"><div class="container">
<?php if (!$canView): ?>
    <div class="card glass reveal"><p>🔒 Diese Gruppe ist <strong>privat</strong>. Nur Mitglieder können die Beiträge sehen. Sende eine Beitrittsanfrage!</p></div>
<?php else: ?>
<?php if (!$isMember && $isSiteStaff): ?>
    <div class="flash flash-success reveal">🛡️ Du siehst diese Gruppe als <?= e($me['role'] === 'admin' ? 'Admin' : 'Moderator') ?> – voller Zugriff auf alle Inhalte.</div>
<?php endif; ?>
<div class="forum-layout">
    <div>
        <?php if ($isMember): ?>
        <form method="post" enctype="multipart/form-data" class="card glass form-stack reveal" style="margin-bottom:1.5rem">
            <?= csrf_field() ?><input type="hidden" name="action" value="post">
            <textarea name="content" rows="3" maxlength="1000" placeholder="Etwas mit der Gruppe teilen …" required></textarea>
            <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" style="font-size:.85rem;padding:.5rem">
            <button class="btn" type="submit">Posten</button>
        </form>
        <?php endif; ?>

        <?php if (!$posts): ?><div class="card glass reveal"><p class="muted">Noch keine Beiträge in dieser Gruppe.</p></div><?php endif; ?>
        <?php foreach ($posts as $p): ?>
        <article class="card glass wall-post reveal">
            <div class="wall-head">
                <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($p['username']) ?>"><?= avatar_html($p) ?></a>
                <div>
                    <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($p['username']) ?>" class="wall-author"<?= name_color_style($p) ?>><?= e($p['display_name'] ?: $p['username']) ?></a>
                    <?= role_badge($p['role']) ?>
                    <span class="muted small wall-time"><?= date('d.m.Y H:i', strtotime($p['created_at'])) ?></span>
                </div>
                <span style="flex:1"></span>
                <?php if (!empty($p['pinned'])): ?><span class="badge badge-green">📌</span><?php endif; ?>
                <?php if ($canMod): ?>
                <form method="post" class="inline">
                    <?= csrf_field() ?><input type="hidden" name="action" value="pin">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit"><?= !empty($p['pinned']) ? '📌✕' : '📌' ?></button>
                </form>
                <?php endif; ?>
                <?php if ((int)$p['user_id'] === $meId || $canMod): ?>
                <form method="post" class="inline" data-confirm="Beitrag löschen?">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_post">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">🗑</button>
                </form>
                <?php endif; ?>
            </div>
            <div class="wall-content"><?= render_spoilers(nl2br(e($p['content']))) ?></div>
            <?php if (!empty($p['image'])): ?>
            <img class="wall-image" src="<?= SITE_URL ?>/uploads/wall/<?= rawurlencode($p['image']) ?>" alt="" loading="lazy">
            <?php endif; ?>
            <div class="comments">
                <?php foreach ($commentMap[$p['id']] ?? [] as $c): ?>
                <div class="comment">
                    <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($c['username']) ?>"><?= avatar_html($c) ?></a>
                    <div class="comment-body">
                        <div class="comment-head">
                            <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($c['username']) ?>" class="comment-author"<?= name_color_style($c) ?>><?= e($c['display_name'] ?: $c['username']) ?></a>
                            <span class="muted small"><?= date('d.m. H:i', strtotime($c['created_at'])) ?></span>
                            <?php if ((int)$c['user_id'] === $meId || $isAdmin): ?>
                            <form method="post" class="inline">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete_comment">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="comment-delete" type="submit">✕</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?= nl2br(e($c['content'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <form method="post" class="comment-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="comment">
                    <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                    <input type="text" name="content" maxlength="500" placeholder="Kommentieren …" required>
                    <button class="btn btn-sm" type="submit">➤</button>
                </form>
            </div>
        </article>
        <?php endforeach; ?>
    </div>

    <aside class="card glass reveal" style="align-self:start">
        <?php
        // Gruppen-Umfrage
        $gpoll = db()->query("SELECT * FROM polls WHERE group_id = $gid AND active = 1 ORDER BY id DESC LIMIT 1")->fetch();
        if ($gpoll):
            $gopts = db()->query('SELECT * FROM poll_options WHERE poll_id = ' . (int)$gpoll['id'] . ' ORDER BY display_order, id')->fetchAll();
            $gtotal = (int)db()->query('SELECT COUNT(*) FROM poll_votes WHERE poll_id = ' . (int)$gpoll['id'])->fetchColumn();
            $gcounts = [];
            foreach (db()->query('SELECT option_id, COUNT(*) AS c FROM poll_votes WHERE poll_id = ' . (int)$gpoll['id'] . ' GROUP BY option_id') as $gr) {
                $gcounts[$gr['option_id']] = (int)$gr['c'];
            }
            $gStmt = db()->prepare('SELECT option_id FROM poll_votes WHERE poll_id = ? AND user_id = ?');
            $gStmt->execute([(int)$gpoll['id'], $meId]);
            $gMy = (int)$gStmt->fetchColumn();
        ?>
        <h3>📊 Umfrage</h3>
        <p style="margin-bottom:.6rem"><strong><?= e($gpoll['question']) ?></strong></p>
        <?php if ($gMy === 0 && $isMember): ?>
            <?php foreach ($gopts as $o): ?>
            <form method="post" style="margin-bottom:.35rem">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="poll_vote">
                <input type="hidden" name="option_id" value="<?= (int)$o['id'] ?>">
                <button class="btn btn-ghost btn-sm" style="width:100%;text-align:left" type="submit"><?= e($o['text']) ?></button>
            </form>
            <?php endforeach; ?>
        <?php else: ?>
            <?php foreach ($gopts as $o):
                $c = $gcounts[$o['id']] ?? 0;
                $pct = $gtotal > 0 ? round($c / $gtotal * 100) : 0; ?>
            <div class="small" style="margin-bottom:.45rem">
                <div style="display:flex;justify-content:space-between"><span><?= e($o['text']) ?> <?= (int)$o['id'] === $gMy ? '<span class="badge badge-green">du</span>' : '' ?></span><strong><?= $pct ?>%</strong></div>
                <div class="poll-bar"><div class="poll-fill" style="width:<?= max(3, $pct) ?>%"></div></div>
            </div>
            <?php endforeach; ?>
            <p class="muted small"><?= $gtotal ?> Stimme(n)</p>
        <?php endif; ?>
        <hr style="border-color:var(--border);margin:1rem 0">
        <?php endif; ?>

        <?php $gEvents = db()->query("SELECT e.*,
                (SELECT COUNT(*) FROM event_participants p WHERE p.event_id = e.id) AS participants,
                EXISTS(SELECT 1 FROM event_participants p WHERE p.event_id = e.id AND p.user_id = $meId) AS me_joined
                FROM events e WHERE e.group_id = $gid AND e.event_date > NOW()
                ORDER BY e.event_date ASC LIMIT 5")->fetchAll(); ?>
        <?php if ($gEvents): ?>
        <h3>📅 Gruppen-Events</h3>
        <?php foreach ($gEvents as $ev): ?>
        <div style="padding:.6rem 0;border-bottom:1px dashed var(--border)">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem">
                <div>
                    <strong><?= e($ev['title']) ?></strong><br>
                    <span class="muted small">📅 <?= date('d.m.Y H:i', strtotime($ev['event_date'])) ?> · 👥 <?= (int)$ev['participants'] ?> Teilnehmer</span>
                </div>
                <?php if ($isAdmin): ?>
                <div style="display:flex;gap:.3rem;flex-shrink:0">
                    <details class="wall-report">
                        <summary class="btn btn-ghost btn-sm" style="padding:.15rem .5rem" title="Bearbeiten">✎</summary>
                        <form method="post" class="wall-report-form" style="width:280px">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="event_edit">
                            <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                            <input type="text" name="title" value="<?= e($ev['title']) ?>" required>
                            <input type="datetime-local" name="event_date" value="<?= date('Y-m-d\TH:i', strtotime($ev['event_date'])) ?>" required>
                            <textarea name="description" rows="2" placeholder="Beschreibung"><?= e($ev['description'] ?? '') ?></textarea>
                            <button class="btn btn-sm" type="submit">Speichern</button>
                        </form>
                    </details>
                    <form method="post" class="inline" data-confirm="Event wirklich löschen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="event_delete">
                        <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                        <button class="btn btn-ghost btn-sm" style="padding:.15rem .5rem" title="Löschen">🗑</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($isMember): ?>
            <form method="post" style="margin-top:.4rem">
                <?= csrf_field() ?>
                <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                <?php if ($ev['me_joined']): ?>
                    <button class="btn btn-ghost btn-sm" name="action" value="event_leave">✔ Du nimmst teil – absagen</button>
                <?php else: ?>
                    <button class="btn btn-sm" name="action" value="event_join">✔ Zusagen</button>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <h3 style="<?= $gEvents ? 'margin-top:1rem' : '' ?>">👥 Mitglieder (<?= count($approved) ?>)</h3>
        <?php foreach ($approved as $m): ?>
        <div class="friend-row">
            <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($m['username']) ?>">
                <?= avatar_html(['display_name' => $m['display_name'], 'username' => $m['username'], 'avatar' => $m['avatar'], 'avatar_color' => $m['avatar_color']]) ?>
            </a>
            <div class="friend-info">
                <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($m['username']) ?>" style="color:var(--text)"><?= e($m['display_name'] ?: $m['username']) ?></a>
                <?= $m['role'] === 'admin' ? '<span class="badge">Admin</span>' : ($m['role'] === 'mod' ? '<span class="badge badge-green">Mod</span>' : '') ?>
            </div>
            <?php if ($isAdmin && (int)$m['user_id'] !== (int)$g['creator_id']): ?>
            <form method="post" class="inline" title="Zum Gruppen-Mod ernennen/absetzen">
                <?= csrf_field() ?><input type="hidden" name="action" value="mod">
                <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= $m['role'] === 'mod' ? '▼' : '▲' ?></button>
            </form>
            <?php endif; ?>
            <?php if ($isAdmin && (int)$m['user_id'] !== (int)$g['creator_id'] && $m['role'] !== 'admin'): ?>
            <form method="post" class="inline" data-confirm="Mitglied wirklich entfernen?">
                <?= csrf_field() ?><input type="hidden" name="action" value="kick">
                <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">✕</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if ($isAdmin): ?>
        <details style="margin-top:1rem">
            <summary class="btn btn-ghost btn-sm" style="cursor:pointer">📊 Umfrage erstellen</summary>
            <form method="post" class="form-stack" style="margin-top:.6rem">
                <?= csrf_field() ?><input type="hidden" name="action" value="poll_create">
                <input type="text" name="question" placeholder="Frage" required>
                <textarea name="options" rows="3" placeholder="Optionen (eine pro Zeile)" required></textarea>
                <button class="btn btn-sm" type="submit">Umfrage starten</button>
            </form>
        </details>
        <details style="margin-top:.6rem">
            <summary class="btn btn-ghost btn-sm" style="cursor:pointer">📅 Event erstellen</summary>
            <form method="post" class="form-stack" style="margin-top:.6rem">
                <?= csrf_field() ?><input type="hidden" name="action" value="event">
                <input type="text" name="title" placeholder="Event-Titel" required>
                <input type="datetime-local" name="event_date" required>
                <input type="text" name="description" placeholder="Beschreibung (optional)">
                <button class="btn btn-sm" type="submit">Erstellen</button>
            </form>
        </details>
        <?php endif; ?>

        <?php if ($isAdmin && $pending): ?>
        <h4 style="margin-top:1.2rem">⏳ Offene Anfragen (<?= count($pending) ?>)</h4>
        <?php foreach ($pending as $m): ?>
        <div class="friend-row">
            <?= avatar_html(['display_name' => $m['display_name'], 'username' => $m['username'], 'avatar' => $m['avatar'], 'avatar_color' => $m['avatar_color']]) ?>
            <div class="friend-info"><span><?= e($m['display_name'] ?: $m['username']) ?></span></div>
            <form method="post" class="inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="approve">
                <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
                <button class="btn btn-sm" type="submit">✔</button>
            </form>
            <form method="post" class="inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="decline">
                <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">✕</button>
            </form>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </aside>
</div>
<?php endif; ?>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
