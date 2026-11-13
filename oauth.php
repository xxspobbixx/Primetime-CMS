<?php
require_once __DIR__ . '/includes/oauth.php';

$provider = (string)($_GET['provider'] ?? '');
if (!isset(oauth_providers()[$provider]) || !oauth_provider_enabled($provider)) {
    flash('error', 'Dieser Anbieter ist nicht aktiviert.');
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}
if (!function_exists('curl_init')) {
    flash('error', 'Dein Server unterstützt keine ausgehenden Verbindungen (cURL fehlt).');
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

$cfg   = oauth_providers()[$provider];
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state']   = $state;
$_SESSION['oauth_provider'] = $provider;

$params = [
    'client_id'     => trim(setting('plugin_oauth_' . $provider . '_id')),
    'redirect_uri'  => SITE_URL . '/oauth-callback.php',
    'response_type' => 'code',
    'scope'         => $cfg['scope'],
    'state'         => $state,
];
if ($provider === 'discord') $params['prompt'] = 'consent';

header('Location: ' . $cfg['auth_url'] . '?' . http_build_query($params));
exit;
