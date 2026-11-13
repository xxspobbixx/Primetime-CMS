<?php
require_once __DIR__ . '/includes/functions.php';
$current = 'login';
$pageTitle = 'Anmelden';

if (is_member()) { header('Location: wall.php'); exit; }

$error = '';

// Rate-Limiting: max. 5 Versuche pro 15 Minuten
$_SESSION['member_attempts'] = array_filter($_SESSION['member_attempts'] ?? [], fn($t) => $t > time() - 900);

// 2FA: ausstehenden Code bestätigen
if (isset($_SESSION['twofa_pending']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['twofa_code'])) {
    csrf_check();
    $stmt = db()->prepare('SELECT twofa_code, twofa_expires FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['twofa_pending']]);
    $row = $stmt->fetch();
    if ($row && $row['twofa_code'] === trim((string)$_POST['twofa_code']) && strtotime($row['twofa_expires']) > time()) {
        db()->prepare('UPDATE users SET twofa_code = NULL WHERE id = ?')->execute([$_SESSION['twofa_pending']]);
        $_SESSION['member_id'] = (int)$_SESSION['twofa_pending'];
        unset($_SESSION['twofa_pending']);
        session_regenerate_id(true);
        flash('success', 'Willkommen zurück!');
        header('Location: wall.php');
        exit;
    }
    $error = 'Code ungültig oder abgelaufen.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['twofa_code'])) {
    csrf_check();
    if (count($_SESSION['member_attempts']) >= 5) {
        $error = 'Zu viele Versuche. Bitte in 15 Minuten erneut probieren.';
    } else {
        $login    = trim((string)($_POST['login'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();
        if ($user && $user['status'] === 'banned') {
            $error = 'Dieses Konto wurde gesperrt.'
                . (!empty($user['ban_reason']) ? ' Grund: ' . $user['ban_reason'] : '');
        } elseif ($user && password_verify($password, $user['password'])) {
            log_login($user['username'], 'member', true);
            // 2FA aktiv? -> Code per E-Mail senden
            if (!empty($user['twofa_enabled']) && trim((string)$user['email']) !== '') {
                $code = (string)random_int(100000, 999999);
                db()->prepare('UPDATE users SET twofa_code = ?, twofa_expires = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?')
                    ->execute([$code, $user['id']]);
                @mail($user['email'], setting('site_name', 'Webseite') . ': Login-Code',
                    "Dein Login-Code: $code\n(gültig 10 Minuten)", 'From: noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
                $_SESSION['twofa_pending'] = (int)$user['id'];
            } else {
                session_regenerate_id(true);
                $_SESSION['member_id'] = $user['id'];
                $_SESSION['member_attempts'] = [];
                maybe_send_digest($user);
                // Freunde ueber Geburtstag benachrichtigen (1x/Tag)
                $bKey = 'bday_notified_' . date('Ymd');
                if (empty($_SESSION[$bKey])) {
                    $_SESSION[$bKey] = true;
                    $friends = db()->query("SELECT u.id FROM friendships f JOIN users u ON u.id = f.friend_id
                        WHERE f.user_id = " . (int)$user['id'] . " AND f.status = 'accepted'
                        AND MONTH(u.birthday) = MONTH(CURDATE()) AND DAY(u.birthday) = DAY(CURDATE())");
                    foreach ($friends->fetchAll() as $bf) {
                        notify((int)$user['id'], 'birthday', (int)$bf['id'],
                            '🎂 Dein:e Freund:in hat heute Geburtstag! Schreib etwas Nettes auf die Wall!', '');
                    }
                }
                flash('success', 'Willkommen zurück!');
                header('Location: wall.php');
                exit;
            }
        }
        log_login($login, 'member', false);
        $_SESSION['member_attempts'][] = time();
        $error = 'Zugangsdaten falsch.';
    }
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container reveal"><h1>Anmelden</h1></div>
</section>
<section class="section">
    <div class="container narrow">
        <?php if ($error): ?><div class="flash flash-error reveal"><?= e($error) ?></div><?php endif; ?>
        <?php if (isset($_SESSION['twofa_pending'])): ?>
        <form method="post" class="card glass form-stack reveal">
            <?= csrf_field() ?>
            <div class="flash flash-success">📧 Ein 6-stelliger Code wurde an deine E-Mail gesendet (gültig 10 Minuten).</div>
            <label>Login-Code
                <input type="text" name="twofa_code" maxlength="6" pattern="\d{6}" required autofocus autocomplete="one-time-code">
            </label>
            <button class="btn" type="submit">Code bestätigen</button>
        </form>
        <?php else: ?>
        <form method="post" class="card glass form-stack reveal">
            <?= csrf_field() ?>
            <label>Benutzername oder E-Mail
                <input type="text" name="login" required autofocus>
            </label>
            <label>Passwort
                <input type="password" name="password" required>
            </label>
            <button class="btn" type="submit">Anmelden</button>
            <?php $oa = function_exists('oauth_enabled_providers') ? oauth_enabled_providers() : []; ?>
    <?php if ($oa): ?>
    <div class="oauth-box">
        <p class="muted small center">— oder fortfahren mit —</p>
        <div class="oauth-btns">
            <?php foreach ($oa as $key => $cfg): ?>
            <a class="oauth-btn" style="background:<?= e($cfg['color']) ?>" href="<?= SITE_URL ?>/oauth.php?provider=<?= urlencode($key) ?>">
                <span class="oauth-icon"><?= $cfg['icon'] ?></span> <?= e($cfg['name']) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <p class="muted small">Noch kein Konto? <a href="<?= SITE_URL ?>/register.php">Jetzt registrieren</a><br>
            <a href="<?= SITE_URL ?>/passwort-vergessen.php">🔑 Passwort vergessen?</a></p>
        </form>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
