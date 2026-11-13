<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
$adminCurrent = 'users';
$me = current_admin();
$isAdmin = ($me['role'] === 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $stmt = db()->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$me['id']]);
        $hash = (string)$stmt->fetchColumn();
        if (!password_verify($current, $hash)) {
            flash('error', 'Aktuelles Passwort ist falsch.');
        } elseif (strlen($new) < 8) {
            flash('error', 'Neues Passwort muss mindestens 8 Zeichen haben.');
        } else {
            db()->prepare('UPDATE users SET password = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            flash('success', 'Passwort geändert.');
        }
    } elseif ($action === 'add_staff' && $isAdmin) {
        // Neuen Admin oder Moderator hinterlegen
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $newRole  = (string)($_POST['new_role'] ?? 'moderator');
        if (!in_array($newRole, ['admin', 'moderator'], true)) $newRole = 'moderator';
        if ($username === '' || strlen($password) < 8) {
            flash('error', 'Benutzername fehlt oder Passwort zu kurz (min. 8 Zeichen).');
        } else {
            try {
                db()->prepare('INSERT INTO users (username, email, password, role, display_name) VALUES (?, NULL, ?, ?, ?)')
                    ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $newRole, $username]);
                flash('success', ($newRole === 'admin' ? 'Admin' : 'Moderator') . ' angelegt.');
            } catch (PDOException $e) {
                flash('error', 'Benutzername existiert bereits.');
            }
        }
    } elseif ($action === 'demote' && $isAdmin) {
        // Moderator wieder zum Mitglied machen
        db()->prepare("UPDATE users SET role = 'member' WHERE id = ? AND role = 'moderator'")->execute([(int)$_POST['id']]);
        flash('success', 'Moderator wurde zum Mitglied zurückgestuft.');
    } elseif ($action === 'member_group' && $isAdmin) {
        $g = trim((string)($_POST['group'] ?? ''));
        $uid = (int)$_POST['id'];
        if ($g !== '' && !array_key_exists($g, user_group_list())) {
            flash('error', 'Unbekannte Gruppe.');
        } else {
            db()->prepare('UPDATE users SET user_group = ? WHERE id = ?')->execute([$g !== '' ? $g : null, $uid]);
            mod_log('Gruppe geändert', '#' . $uid . ' → ' . ($g ?: '—'));
            flash('success', 'Benutzergruppe aktualisiert.');
        }
    } elseif ($action === 'member_role' && $isAdmin) {
        // Rolle frei wählbar (Dropdown) – eigene Rolle nicht änderbar
        $targetRole = (string)($_POST['role'] ?? '');
        $uid = (int)$_POST['id'];
        if ($uid === (int)$me['id']) {
            flash('error', 'Deine eigene Rolle kannst du hier nicht ändern.');
        } elseif (!array_key_exists($targetRole, role_list())) {
            flash('error', 'Unbekannte Rolle.');
        } else {
            $oldStmt = db()->prepare('SELECT role, username FROM users WHERE id = ?');
            $oldStmt->execute([$uid]);
            $oldRow = $oldStmt->fetch();
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$targetRole, $uid]);
            mod_log('Rolle geändert', '@' . ($oldRow['username'] ?? '?') . ': ' . ($oldRow['role'] ?? '?') . ' → ' . $targetRole);
            flash('success', 'Rolle aktualisiert.');
        }
    } elseif ($action === 'member_promote' && $isAdmin) {
        // Mitglied zum Moderator ernennen
        db()->prepare("UPDATE users SET role = 'moderator' WHERE id = ? AND role = 'member'")->execute([(int)$_POST['id']]);
        flash('success', 'Mitglied wurde zum Moderator ernannt.');
    } elseif ($action === 'delete' && $isAdmin) {
        $id = (int)$_POST['id'];
        $uStmt = db()->prepare('SELECT username FROM users WHERE id = ?');
        $uStmt->execute([$id]);
        $usernameForLog = (string)$uStmt->fetchColumn();
        if ($id !== (int)$me['id']) {
            $stmt = db()->prepare('SELECT avatar FROM users WHERE id = ?');
            $stmt->execute([$id]);
            delete_avatar_file($stmt->fetchColumn());
            db()->prepare('DELETE FROM friendships WHERE user_id = ? OR friend_id = ?')->execute([$id, $id]);
            db()->prepare('DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?')->execute([$id, $id]);
            db()->prepare('DELETE FROM reports WHERE reporter_id = ? OR reported_id = ?')->execute([$id, $id]);
            delete_user_wall_posts($id);
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            mod_log('Benutzer gelöscht', '@' . ($usernameForLog ?? (string)$id));
            flash('success', 'Benutzer und alle zugehörigen Daten gelöscht.');
        } else {
            flash('error', 'Du kannst dich nicht selbst löschen.');
        }
    } elseif ($action === 'member_verify' && $isAdmin) {
        db()->prepare('UPDATE users SET is_verified = 1 - is_verified WHERE id = ? AND role = ?')
            ->execute([(int)$_POST['id'], 'member']);
        flash('success', 'Verifizierungs-Status geändert.');
    } elseif ($action === 'member_shadow' && $isAdmin) {
        db()->prepare('UPDATE users SET shadow_banned = 1 - shadow_banned WHERE id = ? AND role = ?')
            ->execute([(int)$_POST['id'], 'member']);
        flash('success', 'Shadow-Ban-Status geändert.');
    } elseif ($action === 'member_unban') {
        // Admins UND Moderatoren dürfen entsperren
        db()->prepare("UPDATE users SET status = 'active', ban_reason = NULL WHERE id = ? AND role = 'member'")
            ->execute([(int)$_POST['id']]);
        flash('success', 'Mitglied wurde entsperrt.');
    } elseif ($action === 'member_public' && $isAdmin) {
        db()->prepare('UPDATE users SET is_public = 1 - is_public WHERE id = ? AND role = ?')
            ->execute([(int)$_POST['id'], 'member']);
    }
    header('Location: users.php');
    exit;
}

$staff   = db()->query("SELECT id, username, role, created_at FROM users WHERE role IN ('admin','moderator') ORDER BY role = 'moderator', id")->fetchAll();
$members = db()->query("SELECT id, username, email, display_name, role, is_public, status, ban_reason, is_verified, shadow_banned, created_at FROM users WHERE role NOT IN ('admin','moderator') ORDER BY id DESC")->fetchAll();

include __DIR__ . '/_header.php';
?>
<h1>Benutzer &amp; Rechte</h1>
<p class="muted">
    <strong>Admins:</strong> voller Zugriff auf alles ·
    <strong>Moderatoren:</strong> Mitglieder sperren/entsperren, fremde Wall-Beiträge &amp; Kommentare löschen,
    Meldungen bearbeiten ·
    <strong>Mitglieder:</strong> normale Community-Nutzer
</p>

<div class="grid grid-2">
    <div class="form-stack">
        <form method="post" class="card glass form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <h3>Mein Passwort ändern</h3>
            <label>Aktuelles Passwort <input type="password" name="current_password" required></label>
            <label>Neues Passwort (min. 8 Zeichen) <input type="password" name="new_password" required></label>
            <button class="btn" type="submit">Passwort ändern</button>
        </form>

        <?php if ($isAdmin): ?>
        <form method="post" class="card glass form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_staff">
            <h3>➕ Admin / Moderator hinterlegen</h3>
            <label>Benutzername <input type="text" name="username" required></label>
            <label>Rolle
                <select name="new_role">
                    <option value="moderator">🛡️ Moderator (Meldungen + Sperren)</option>
                    <option value="admin">👑 Admin (voller Zugriff)</option>
                </select>
            </label>
            <label>Passwort (min. 8 Zeichen) <input type="password" name="password" required></label>
            <button class="btn" type="submit">Konto anlegen</button>
        </form>
        <?php endif; ?>

        <div class="card glass">
            <h3>Team (<?= count($staff) ?>)</h3>
            <table class="admin-table">
                <thead><tr><th>Benutzername</th><th>Rolle</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($staff as $u): ?>
                    <tr>
                        <td><?= e($u['username']) ?><?= (int)$u['id'] === (int)$me['id'] ? ' <span class="muted small">(du)</span>' : '' ?></td>
                        <td><?= role_badge($u['role']) ?></td>
                        <td class="table-actions">
                            <?php if ($isAdmin && (int)$u['id'] !== (int)$me['id'] && $u['role'] === 'moderator'): ?>
                            <form method="post" class="inline" data-confirm="Zum Mitglied zurückstufen?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="demote">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">Zurückstufen</button>
                            </form>
                            <?php endif; ?>
                            <?php if ($isAdmin && (int)$u['id'] !== (int)$me['id']): ?>
                            <form method="post" class="inline" data-confirm="Team-Mitglied wirklich löschen?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">Löschen</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card glass">
        <h3>Mitglieder (<?= count($members) ?>)</h3>
        <p class="muted small">Admins und Moderatoren dürfen Mitglieder sperren und entsperren.</p>
        <table class="admin-table">
            <thead><tr><th>Mitglied</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td>
                        <?= e($m['display_name'] ?: $m['username']) ?><br>
                        <span class="muted small">@<?= e($m['username']) ?></span>
                    </td>
                    <td>
                        <?php if ($m['status'] === 'banned'): ?>
                            <span class="badge badge-red" title="<?= e($m['ban_reason'] ?? '') ?>">🔒 gesperrt</span>
                        <?php else: ?>
                            <span class="badge badge-green">aktiv</span>
                        <?php endif; ?>
                    </td>
                    <td class="table-actions">
                        <?php if ($m['status'] === 'banned'): ?>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="member_unban">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button class="btn btn-sm" type="submit">Entsperren</button>
                        </form>
                        <?php else: ?>
                        <a class="btn btn-ghost btn-sm" href="ban.php?id=<?= (int)$m['id'] ?>">Sperren</a>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                        <form method="post" class="inline" title="Verifizierungs-Haken umschalten">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="member_verify">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button class="btn btn-ghost btn-sm" type="submit"><?= !empty($m['is_verified']) ? '✔️' : '✔' ?></button>
                        </form>
                        <form method="post" class="inline" data-confirm="Shadow-Ban umschalten? Beiträge sind dann nur noch für sich selbst sichtbar." title="Shadow-Ban umschalten">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="member_shadow">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button class="btn btn-ghost btn-sm" type="submit"><?= !empty($m['shadow_banned']) ? '👻 an' : '👻' ?></button>
                        </form>
                        <form method="post" class="inline" title="Benutzergruppe">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="member_group">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <select name="group" onchange="this.form.submit()" title="Benutzergruppe" style="padding:.3rem .4rem;font-size:.8rem;margin-bottom:0;width:auto">
                                <option value="">👥 Gruppe…</option>
                                <?php foreach (user_group_list() as $gKey => [$gIcon, $gLabel]): ?>
                                <option value="<?= e($gKey) ?>" <?= ($m['user_group'] ?? '') === $gKey ? 'selected' : '' ?>><?= $gIcon ?> <?= e($gLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <form method="post" class="inline" data-confirm="Rolle wirklich ändern?" title="Rolle ändern">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="member_role">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <select name="group" onchange="this.form.submit()" title="Benutzergruppe" style="padding:.3rem .4rem;font-size:.8rem;margin-bottom:0;width:auto">
                                <option value="">👥 Keine Gruppe</option>
                                <?php foreach (user_group_list() as $gKey => [$gIcon, $gLabel]): ?>
                                <option value="<?= e($gKey) ?>" <?= ($m['user_group'] ?? '') === $gKey ? 'selected' : '' ?>><?= $gIcon ?> <?= e($gLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                                <?php foreach (role_list() as $rKey => [$rIcon, $rLabel]): ?>
                                <option value="<?= e($rKey) ?>" <?= $m['role'] === $rKey ? 'selected' : '' ?>><?= $rIcon ?> <?= e($rLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <form method="post" class="inline" data-confirm="Mitglied wirklich löschen?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit">Löschen</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$members): ?><tr><td colspan="3" class="muted">Noch keine Mitglieder registriert.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
