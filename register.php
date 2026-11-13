<?php
require_once __DIR__ . '/includes/functions.php';
$current = 'register';
$pageTitle = 'Registrieren';

$errors = [];
$old = ['username' => '', 'email' => '', 'display_name' => '', 'bio' => ''];

// Empfehlungs-Plugin: ?ref=Benutzername als Cookie merken (30 Tage)
if (plugin_active('referral') && isset($_GET['ref'])) {
    $refName = trim((string)$_GET['ref']);
    if (preg_match('/^[A-Za-z0-9_.\-]{3,30}$/', $refName)) {
        setcookie('ref_user', $refName, time() + 2592000, '/', '', !empty($_SERVER['HTTPS']), true);
        $_COOKIE['ref_user'] = $refName;
    }
}

if (is_member()) { header('Location: wall.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Honeypot: ausgefüllt = Bot -> still erfolgreich tun
    if (!empty($_POST['website'])) {
        flash('success', 'Registrierung erfolgreich! Du kannst dich jetzt anmelden.');
        header('Location: login.php');
        exit;
    }

    if (setting('registration_open', '1') !== '1') {
        flash('error', 'Die Registrierung ist derzeit geschlossen.');
        header('Location: login.php');
        exit;
    }

    $old['username']     = trim((string)($_POST['username'] ?? ''));
    $old['email']        = trim((string)($_POST['email'] ?? ''));
    $old['display_name'] = trim((string)($_POST['display_name'] ?? ''));
    $old['bio']          = trim((string)($_POST['bio'] ?? ''));
    $password            = (string)($_POST['password'] ?? '');
    $password2           = (string)($_POST['password2'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.\-]{3,30}$/', $old['username'])) {
        $errors[] = 'Benutzername: 3–30 Zeichen, nur Buchstaben, Zahlen und _.- erlaubt.';
    }
    if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    }
    if (mb_strlen($password) < 8) {
        $errors[] = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    }
    if ($password !== $password2) {
        $errors[] = 'Die Passwörter stimmen nicht überein.';
    }
    if (mb_strlen($old['bio']) > 500) {
        $errors[] = 'Die Kurzbeschreibung darf maximal 500 Zeichen lang sein.';
    }

    // reCAPTCHA (falls Plugin aktiv & konfiguriert)
    if (plugin_active('recaptcha') && trim(setting('plugin_recaptcha_sitekey')) !== '') {
        $token = (string)($_POST['g-recaptcha-response'] ?? '');
        if ($token === '' || !recaptcha_verify($token)) {
            $errors[] = 'Bitte das reCAPTCHA bestätigen (Bot-Schutz).';
        }
    }

    // Wegwerf-E-Mail-Domains sperren (sofern aktiv)
    if (!$errors && setting('email_blocklist_active', '1') === '1' && email_domain_blocked($old['email'])) {
        $errors[] = 'Anmeldungen mit dieser E-Mail-Domain sind nicht erlaubt.';
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$old['username']]);
        if ($stmt->fetch()) $errors[] = 'Dieser Benutzername ist bereits vergeben.';

        $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$old['email']]);
        if ($stmt->fetch()) $errors[] = 'Diese E-Mail-Adresse ist bereits registriert.';
    }

    if (!$errors) {
        db()->prepare('INSERT INTO users (username, email, password, display_name, bio, avatar_color, role, is_public)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $old['username'],
                $old['email'],
                password_hash($password, PASSWORD_DEFAULT),
                $old['display_name'] !== '' ? $old['display_name'] : $old['username'],
                $old['bio'],
                random_avatar_color(),
                'member',
                1,
            ]);
        discord_notify('🎉 Neues Mitglied',
            '**' . ($old['display_name'] ?: $old['username']) . '** (@' . $old['username'] . ') hat sich gerade registriert.',
            3066993);
        // Empfehlung zuordnen + Werber belohnen (absturzsicher)
        $newId = (int)db()->lastInsertId();
        try {
        $refId = null;
        if (plugin_active('referral') && !empty($_COOKIE['ref_user'])) {
            $ref = find_user_by_username((string)$_COOKIE['ref_user']);
            if ($ref && (int)$ref['id'] !== $newId) {
                $refId = (int)$ref['id'];
                add_points($refId, (int)setting('plugin_referral_points', '25'));
                notify($refId, 'referral', $newId,
                    ($old['display_name'] ?: $old['username']) . ' ist über deinen Empfehlungslink beigetreten! +' . (int)setting('plugin_referral_points', '25') . ' Punkte',
                    SITE_URL . '/panel.php');
            }
        }
        if ($refId) {
            db()->prepare('UPDATE users SET referred_by = ? WHERE id = ?')->execute([$refId, $newId]);
        }
        } catch (Throwable $e) { /* Spalte fehlt -> update.php ausführen */ }
        if (plugin_active('konfetti')) { $_SESSION['confetti'] = true; }
        // Direkt einloggen
        session_regenerate_id(true);
        $_SESSION['member_id'] = (int)db()->lastInsertId();
        flash('success', 'Willkommen an Bord, ' . ($old['display_name'] ?: $old['username']) . '!');
        header('Location: wall.php');
        exit;
    }
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container reveal"><h1>Registrieren</h1>
    <p class="muted">Werde Mitglied der Community – mit Profil, Panel und Platz in der Mitgliederliste.</p></div>
</section>
<section class="section">
    <div class="container narrow">
        <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post" class="card glass form-stack reveal">
            <?= csrf_field() ?>
            <input type="text" name="website" class="hp-field" tabindex="-1" autocomplete="off" aria-hidden="true">
            <div class="form-grid">
                <label>Benutzername *
                    <input type="text" name="username" value="<?= e($old['username']) ?>" required>
                </label>
                <label>E-Mail *
                    <input type="email" name="email" value="<?= e($old['email']) ?>" required>
                </label>
                <label class="span-2">Anzeigename (optional, öffentlich sichtbar)
                    <input type="text" name="display_name" value="<?= e($old['display_name']) ?>" maxlength="100">
                </label>
                <label class="span-2">Kurzbeschreibung (optional, max. 500 Zeichen)
                    <textarea name="bio" rows="3" maxlength="500"><?= e($old['bio']) ?></textarea>
                </label>
                <label>Passwort * (min. 8 Zeichen)
                    <input type="password" name="password" required>
                </label>
                <label>Passwort wiederholen *
                    <input type="password" name="password2" required>
                </label>
            </div>
            <?php if (plugin_active('recaptcha') && trim(setting('plugin_recaptcha_sitekey')) !== ''): ?>
            <div class="g-recaptcha" data-sitekey="<?= e(setting('plugin_recaptcha_sitekey')) ?>"></div>
            <script src="https://www.google.com/recaptcha/api.js" async defer></script>
            <?php endif; ?>
            <button class="btn" type="submit">Konto erstellen</button>
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
    <p class="muted small">Bereits Mitglied? <a href="<?= SITE_URL ?>/login.php">Jetzt anmelden</a></p>
        </form>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
