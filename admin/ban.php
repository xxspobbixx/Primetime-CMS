<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
$me = current_admin();
$adminCurrent = 'users';

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT id, username, display_name, status FROM users WHERE id = ? AND role = 'member'");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user || (int)$user['id'] === (int)$me['id']) {
    flash('error', 'Mitglied nicht gefunden.');
    header('Location: users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $reason = trim((string)($_POST['reason'] ?? ''));
    if (mb_strlen($reason) > 255) {
        flash('error', 'Grund zu lang (max. 255 Zeichen).');
    } else {
        db()->prepare("UPDATE users SET status = 'banned', ban_reason = ? WHERE id = ? AND role = 'member'")
            ->execute([$reason !== '' ? $reason : null, $id]);
        mod_log('Mitglied gesperrt', ($user['display_name'] ?: $user['username']));
        flash('success', ($user['display_name'] ?: $user['username']) . ' wurde gesperrt.');
        header('Location: users.php');
        exit;
    }
}

include __DIR__ . '/_header.php';
?>
<h1>Mitglied sperren</h1>
<div class="card glass" style="max-width:560px">
    <p>Du sperrst: <strong><?= e($user['display_name'] ?: $user['username']) ?></strong> (@<?= e($user['username']) ?>)</p>
    <p class="muted small">Gesperrte Mitglieder können sich nicht mehr anmelden und werden bei aktiver Sitzung sofort abgemeldet.
    Alle Daten bleiben erhalten, du kannst das Konto jederzeit wieder entsperren.</p>
    <form method="post" class="form-stack" data-confirm="Mitglied wirklich sperren?">
        <?= csrf_field() ?>
        <label>Sperrgrund (optional, wird dem Mitglied angezeigt)
            <input type="text" name="reason" maxlength="255" placeholder="z. B. Verstoß gegen die Community-Richtlinien">
        </label>
        <div>
            <button class="btn btn-danger" type="submit">🔒 Jetzt sperren</button>
            <a class="btn btn-ghost" href="users.php">Abbrechen</a>
        </div>
    </form>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
