<?php
require_once __DIR__ . '/includes/oauth.php';

$provider = (string)($_SESSION['oauth_provider'] ?? '');
$stateOk  = hash_equals((string)($_SESSION['oauth_state'] ?? ''), (string)($_GET['state'] ?? ''));
unset($_SESSION['oauth_state'], $_SESSION['oauth_provider']);

$fail = function (string $msg) {
    flash('error', $msg);
    header('Location: ' . SITE_URL . '/login.php');
    exit;
};

if ($provider === '' || !isset(oauth_providers()[$provider]) || !$stateOk) {
    $fail('Login abgebrochen oder ungültige Sitzung. Bitte erneut versuchen.');
}

if (isset($_GET['error'])) {
    $fail('Der Anbieter hat den Zugriff abgelehnt (' . ($_GET['error_description'] ?? $_GET['error']) . ').');
}

$code = (string)($_GET['code'] ?? '');
if ($code === '') $fail('Kein Autorisierungscode erhalten.');

$cfg = oauth_providers()[$provider];

// 1) Code gegen Token tauschen
$tok = oauth_http_post($cfg['token_url'], [
    'client_id'     => trim(setting('plugin_oauth_' . $provider . '_id')),
    'client_secret' => trim(setting('plugin_oauth_' . $provider . '_secret')),
    'code'          => $code,
    'grant_type'    => 'authorization_code',
    'redirect_uri'  => SITE_URL . '/oauth-callback.php',
]);
$accessToken = (string)($tok['json']['access_token'] ?? '');
if ($accessToken === '') {
    $fail('Token-Austausch fehlgeschlagen (HTTP ' . $tok['code'] . '). Bitte Client-ID/Secret im Admin-Panel prüfen.');
}

// 2) Profil laden
$me   = oauth_http_get($cfg['user_url'], $accessToken);
$prof = oauth_normalize_profile($provider, $me['json'], $accessToken);
if ($prof['id'] === '') $fail('Profil konnte nicht geladen werden (HTTP ' . $me['code'] . ').');

// 3) Wegwerf-Mails sperren (falls E-Mail vorhanden)
if ($prof['email'] !== '' && setting('email_blocklist_active', '1') === '1' && email_domain_blocked($prof['email'])) {
    $fail('Diese E-Mail-Domain ist nicht erlaubt.');
}

// 4) Bekanntes OAuth-Konto?
$stmt = db()->prepare('SELECT user_id FROM oauth_accounts WHERE provider = ? AND provider_id = ?');
$stmt->execute([$provider, $prof['id']]);
$knownUserId = (int)$stmt->fetchColumn();

// 5) Per E-Mail bekannt?
if ($knownUserId === 0 && $prof['email'] !== '') {
    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$prof['email']]);
 $knownUserId = (int)$stmt->fetchColumn();
    if ($knownUserId > 0) {
        db()->prepare('INSERT IGNORE INTO oauth_accounts (user_id, provider, provider_id) VALUES (?, ?, ?)')
            ->execute([$knownUserId, $provider, $prof['id']]);
    }
}

// 6) Neues Konto anlegen (nur wenn Registrierung offen)
if ($knownUserId === 0) {
    if (setting('registration_open', '1') !== '1') {
        $fail('Die Registrierung ist derzeit geschlossen.');
    }
    $username = oauth_unique_username($prof['username']);
    $email    = $prof['email'] !== '' ? $prof['email'] : null;
    if ($email !== null) {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) $email = null; // E-Mail-Kollision -> Konto ohne E-Mail anlegen
    }
    db()->prepare('INSERT INTO users (username, email, password, display_name, avatar_color, role) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([
            $username,
            $email,
            password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), // zufällig, Reset nötig für Passwort-Login
            $prof['name'] !== '' ? $prof['name'] : $username,
            random_avatar_color(),
            'member',
        ]);
    $knownUserId = (int)db()->lastInsertId();
    db()->prepare('INSERT IGNORE INTO oauth_accounts (user_id, provider, provider_id) VALUES (?, ?, ?)')
        ->execute([$knownUserId, $provider, $prof['id']]);

    if (plugin_active('konfetti')) { $_SESSION['confetti'] = true; }
    discord_notify('🎉 Neues Mitglied (via ' . oauth_providers()[$provider]['name'] . ')',
        '**' . $username . '** ist über ' . oauth_providers()[$provider]['name'] . ' beigetreten.',
        3066993);
}

// 7) Avatar übernehmen
if ($prof['avatar_url'] !== '') {
    oauth_save_avatar($prof['avatar_url'], $knownUserId);
}

// 8) Einloggen
session_regenerate_id(true);
$_SESSION['member_id'] = $knownUserId;
flash('success', 'Willkommen, ' . oauth_providers()[$provider]['name'] . '-Login erfolgreich!');
header('Location: ' . SITE_URL . '/wall.php');
exit;
