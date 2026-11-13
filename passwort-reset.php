<?php
require_once __DIR__ . '/includes/functions.php';
$current = 'login';
$pageTitle = 'Neues Passwort';
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';

$stmt = db()->prepare('SELECT user_id FROM password_resets WHERE token = ? AND expires > NOW()');
$stmt->execute([$token]);
$uid = (int)$stmt->fetchColumn();

if ($uid === 0) {
    $error = 'Dieser Link ist ungültig oder abgelaufen.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pw = (string)($_POST['password'] ?? '');
    if (mb_strlen($pw) < 8) {
        $error = 'Passwort muss mindestens 8 Zeichen haben.';
    } else {
        db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $uid]);
        db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$uid]);
        flash('success', 'Passwort geändert – du kannst dich jetzt anmelden!');
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal"><h1>🔑 Neues Passwort</h1></div></section>
<section class="section"><div class="container narrow">
    <?php if ($error): ?><div class="flash flash-error reveal"><?= e($error) ?></div><?php endif; ?>
    <?php if ($uid > 0): ?>
    <form method="post" class="card glass form-stack reveal">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label>Neues Passwort (min. 8 Zeichen) <input type="password" name="password" required autofocus></label>
        <button class="btn" type="submit">Passwort speichern</button>
    </form>
    <?php endif; ?>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
