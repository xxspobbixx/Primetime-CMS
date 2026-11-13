<?php
require_once __DIR__ . '/functions.php';

/** Unterstützte OAuth-Anbieter mit Endpunkten und Scopes. */
function oauth_providers(): array
{
    return [
        'google' => [
            'name'     => 'Google',
            'icon'     => '🔵 G',
            'color'    => '#4285F4',
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url'=> 'https://oauth2.googleapis.com/token',
            'user_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scope'    => 'openid email profile',
        ],
        'github' => [
            'name'     => 'GitHub',
            'icon'     => '⚫',
            'color'    => '#24292e',
            'auth_url' => 'https://github.com/login/oauth/authorize',
            'token_url'=> 'https://github.com/login/oauth/access_token',
            'user_url' => 'https://api.github.com/user',
            'scope'    => 'read:user user:email',
        ],
        'discord' => [
            'name'     => 'Discord',
            'icon'     => '🎮',
            'color'    => '#5865F2',
            'auth_url' => 'https://discord.com/api/oauth2/authorize',
            'token_url'=> 'https://discord.com/api/oauth2/token',
            'user_url' => 'https://discord.com/api/users/@me',
            'scope'    => 'identify email',
        ],
    ];
}

/** Ist ein Provider im Admin-Panel aktiviert UND vollständig konfiguriert? */
function oauth_provider_enabled(string $key): bool
{
    if (!plugin_active('oauth')) return false;
    $id = trim(setting('plugin_oauth_' . $key . '_id'));
    $sec = trim(setting('plugin_oauth_' . $key . '_secret'));
    return $id !== '' && $sec !== '';
}

/** Alle aktivierten & konfigurierten Provider. */
function oauth_enabled_providers(): array
{
    $out = [];
    foreach (oauth_providers() as $key => $p) {
        if (oauth_provider_enabled($key)) $out[$key] = $p;
    }
    return $out;
}

/** cURL-POST (x-www-form-urlencoded) und JSON-Antwort decodiert zurückgeben. */
function oauth_http_post(string $url, array $fields, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_USERAGENT => 'VorstellungsCMS/1.0',
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $j = json_decode((string)$res, true);
    return ['code' => $code, 'json' => is_array($j) ? $j : [], 'raw' => (string)$res];
}

/** cURL-GET mit Bearer-Token, JSON decodiert. */
function oauth_http_get(string $url, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json', 'User-Agent: VorstellungsCMS/1.0'],
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode((string)$res, true) ?: []];
}

/**
 * Extrahiert aus Provider-Antworten ein normiertes Profil:
 * ['id' =>, 'email' =>, 'username' =>, 'name' =>, 'avatar_url' =>]
 */
function oauth_normalize_profile(string $provider, array $json, string $token): array
{
    $email = trim((string)($json['email'] ?? ''));
    if ($provider === 'github' && $email === '') {
        $em = oauth_http_get('https://api.github.com/user/emails', $token);
        foreach ($em['json'] as $row) {
            if (!empty($row['primary']) && !empty($row['verified'])) { $email = (string)$row['email']; break; }
        }
    }
    $avatar = '';
    if ($provider === 'google') {
        $avatar = (string)($json['picture'] ?? '');
        $uname = strtolower(preg_replace('/[^a-z0-9_.\-]/i', '', (string)($json['given_name'] ?? $json['name'] ?? 'user')));
    } elseif ($provider === 'github') {
        $avatar = (string)($json['avatar_url'] ?? '');
        $uname = (string)($json['login'] ?? 'user');
    } else { // discord
        $id = (string)($json['id'] ?? '');
        if (!empty($json['avatar']) && $id !== '') {
            $avatar = 'https://cdn.discordapp.com/avatars/' . $id . '/' . $json['avatar'] . '.png?size=256';
        }
        $uname = (string)($json['username'] ?? 'user');
    }
    return [
        'id'         => (string)($json['id'] ?? $json['sub'] ?? ''),
        'email'      => $email,
        'username'   => $uname !== '' ? $uname : 'user',
        'name'       => (string)($json['name'] ?? $json['login'] ?? $json['username'] ?? ''),
        'avatar_url' => $avatar,
    ];
}

/** Eindeutigen lokalen Benutzernamen erzeugen (Basis + Zahl falls vergeben). */
function oauth_unique_username(string $base): string
{
    $base = mb_substr(mb_strtolower(preg_replace('/[^a-zA-Z0-9_.\-]/', '', $base)), 0, 20);
    if ($base === '') $base = 'user';
    $name = $base;
    $i = 1;
    while (true) {
        $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$name]);
        if (!$stmt->fetch()) return $name;
        $name = $base . random_int(100, 999);
        if (++$i > 10) { $name = 'user' . random_int(10000, 99999); }
    }
}

/** Profilbild von URL herunterladen und als Avatar speichern. */
function oauth_save_avatar(string $url, int $userId): void
{
    if ($url === '' || !function_exists('curl_init')) return;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true]);
    $data = curl_exec($ch);
    curl_close($ch);
    if ($data === false || strlen($data) < 100 || strlen($data) > 4 * 1024 * 1024) return;
    $info = @getimagesizefromstring($data);
    if ($info === false) return;
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null;
    if ($ext === null) return;
    $dir = APP_ROOT . '/uploads/avatars';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (file_put_contents($dir . '/' . $file, $data)) {
        $stmt = db()->prepare('SELECT avatar FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        delete_avatar_file($stmt->fetchColumn());
        db()->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$file, $userId]);
    }
}
