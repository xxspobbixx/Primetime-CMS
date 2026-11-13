<?php
require_once __DIR__ . '/includes/functions.php';
$current = 'login';
$pageTitle = 'Passwort vergessen';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $login = trim((string)($_POST['login'] ?? ''));
    $stmt = db()->prepare('SELECT id, username, email FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$login, $login]);
    $u = $stmt->fetch();
    // Immer Erfolg zeigen (kein User-Enumeration)
    if ($u && trim((string)$u['email']) !== '' && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
        $token = bin2hex(random_bytes(32));
        db()->prepare('INSERT INTO password_resets (user_id, token, expires) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))
                       ON DUPLICATE KEY UPDATE token = VALUES(token), expires = VALUES(expires)')
            ->execute([$u['id'], $token]);
        $link = SITE_URL . '/passwort-reset.php?token=' . $token;
        $body = "Hallo " . $u['username'] . ",\n\nklicke auf diesen Link, um dein Passwort zurückzusetzen (gültig 1 Stunde):\n" . $link . "\n\nFalls du das nicht warst, ignoriere diese E-Mail.";
        @mail($u['email'], setting('site_name', 'Webseite') . ': Passwort zurücksetzen', $body, 'From: noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }
    $done = true;
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal"><h1>🔑 Passwort vergessen</h1></div></section>
<section class="section"><div class="container narrow">
    <?php if ($done): ?>
    <div class="card glass reveal"><div class="flash flash-success">Falls ein Konto mit diesen Daten existiert, wurde eine E-Mail mit Reset-Link gesendet (gültig 1 Stunde).</div>
    <a class="btn" href="<?= SITE_URL ?>/login.php">Zum Login</a></div>
    <?php else: ?>
    <form method="post" class="card glass form-stack reveal">
        <?= csrf_field() ?>
        <label>Benutzername oder E-Mail <input type="text" name="login" required autofocus></label>
        <button class="btn" type="submit">Reset-Link senden</button>
        <p class="muted small"><a href="<?= SITE_URL ?>/login.php">← Zurück zum Login</a></p>
    </form>
    <?php endif; ?>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
