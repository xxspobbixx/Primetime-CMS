<?php
require_once __DIR__ . '/../includes/functions.php';

if (is_logged_in()) { header('Location: index.php'); exit; }

$error = '';

// Einfache Rate-Limiting: max. 5 Versuche pro 15 Minuten
$_SESSION['login_attempts'] = array_filter($_SESSION['login_attempts'] ?? [], fn($t) => $t > time() - 900);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (count($_SESSION['login_attempts']) >= 5) {
        $error = 'Zu viele Versuche. Bitte in 15 Minuten erneut probieren.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && in_array($user['role'], ['admin', 'moderator'], true) && password_verify($password, $user['password'])) {
            log_login($user['username'], 'admin', true);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['login_attempts'] = [];
            header('Location: index.php');
            exit;
        }
        $_SESSION['login_attempts'][] = time();
        $error = ($user && !in_array($user['role'], ['admin', 'moderator'], true))
            ? 'Dieses Konto hat keinen Zugang zum Moderations-Bereich.'
            : 'Benutzername oder Passwort falsch.';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin-Login</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="login-body">
    <form class="card glass login-card" method="post" action="">
        <div class="logo center-logo"><span class="logo-mark">&lt;/&gt;</span> Admin &amp; Moderation</div>
        <?= render_flash() ?>
        <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
        <?= csrf_field() ?>
        <label>Benutzername
            <input type="text" name="username" required autofocus>
        </label>
        <label>Passwort
            <input type="password" name="password" required>
        </label>
        <button class="btn full" type="submit">Anmelden</button>
        <p class="muted small center"><a href="../login.php">Mitglieder-Login →</a> · <a href="../index.php">Zurück zur Webseite</a></p>
    </form>
</body>
</html>
