<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pluginlib.php';
require_once __DIR__ . '/oauth.php';

/* ---------- Ausgabe & Validierung ---------- */

function e(?string $str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function hexcolor(string $val, string $default): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $val) ? $val : $default;
}

function allowed_html(string $html): string
{
    return strip_tags($html, '<p><br><strong><b><em><i><u><a><ul><ol><li><h2><h3><h4><blockquote><code><pre><span><img><figure><figcaption>');
}

/* ---------- Einstellungen ---------- */

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $stmt = db()->query('SELECT setting_key, setting_value FROM settings');
            foreach ($stmt->fetchAll() as $row) {
                $cache[$row['setting_key']] = (string)$row['setting_value'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

/* ---------- Seiteninhalte ---------- */

function get_page(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM pages WHERE slug = ?');
    $stmt->execute([$slug]);
    $page = $stmt->fetch();
    return $page ?: null;
}

/* ---------- CSRF-Schutz ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Ungültiger Sicherheitstoken. Bitte Seite neu laden.');
    }
}

/* ---------- Authentifizierung (Admin-Panel) ---------- */

function is_logged_in(): bool
{
    return !empty($_SESSION['admin_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function current_admin(): ?array
{
    if (!is_logged_in()) return null;
    $stmt = db()->prepare('SELECT id, username, role FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    return $stmt->fetch() ?: null;
}

/* ---------- Rollen & Rechte ---------- */

/** Admin oder Moderator? (dürfen moderieren: sperren, fremde Inhalte löschen, Meldungen bearbeiten) */
function user_can_moderate(?array $user): bool
{
    return $user !== null && in_array($user['role'] ?? '', ['admin', 'moderator'], true);
}

/** Seite nur für Admins – Moderatoren werden zum Dashboard weitergeleitet. */
function require_admin_role(): void
{
    $a = current_admin();
    if (!$a || $a['role'] !== 'admin') {
        flash('error', 'Diese Bereiche stehen nur Admins zur Verfügung.');
        header('Location: index.php');
        exit;
    }
}

/* ---------- Selbstheilende Tabellen (Community-Features) ---------- */

/** Erstellt Feature-Tabellen automatisch, falls sie fehlen (schuetzt vor 500ern). */
function ensure_feature_tables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS groups_tbl (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT NULL,
            privacy ENUM('public','private') NOT NULL DEFAULT 'public',
            creator_id INT UNSIGNED NOT NULL,
            image VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS group_members (
            group_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'member',
            status ENUM('approved','pending') NOT NULL DEFAULT 'approved',
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS group_posts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            content TEXT NOT NULL,
            image VARCHAR(500) NULL,
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS group_comments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            post_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            content VARCHAR(500) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("ALTER TABLE forum_posts ADD COLUMN best_answer TINYINT(1) NOT NULL DEFAULT 0");
        db()->exec("CREATE TABLE IF NOT EXISTS forum_votes (
            post_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, vote TINYINT NOT NULL,
            PRIMARY KEY (post_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("ALTER TABLE forum_topics ADD COLUMN tags VARCHAR(255) NULL");
        db()->exec("ALTER TABLE wall_posts ADD COLUMN edited TINYINT(1) NOT NULL DEFAULT 0");
        db()->exec("CREATE TABLE IF NOT EXISTS wall_bookmarks (
            user_id INT UNSIGNED NOT NULL, post_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, post_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("ALTER TABLE users ADD COLUMN name_glow TINYINT(1) NOT NULL DEFAULT 0");
        db()->exec("ALTER TABLE users ADD COLUMN profile_song VARCHAR(500) NULL");
        db()->exec("CREATE TABLE IF NOT EXISTS blocked_users (
            user_id INT UNSIGNED NOT NULL, blocked_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, blocked_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("ALTER TABLE users ADD COLUMN profile_views INT NOT NULL DEFAULT 0");
        db()->exec("ALTER TABLE chat_messages ADD COLUMN reply_to_id INT UNSIGNED NULL");
        db()->exec("ALTER TABLE chat_messages ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0");
        db()->exec("ALTER TABLE chat_channels ADD COLUMN slow_mode INT NOT NULL DEFAULT 0");
        db()->exec("CREATE TABLE IF NOT EXISTS pm_reactions (
            message_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, emoji VARCHAR(10) NOT NULL,
            PRIMARY KEY (message_id, user_id, emoji)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("ALTER TABLE users ADD COLUMN streak_freeze INT NOT NULL DEFAULT 0");
        db()->exec("ALTER TABLE users ADD COLUMN custom_status VARCHAR(120) NULL");
        db()->exec("CREATE TABLE IF NOT EXISTS profile_visits (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            profile_id INT UNSIGNED NOT NULL,
            visitor_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("ALTER TABLE users ADD COLUMN quiet_start VARCHAR(5) NULL");
        db()->exec("ALTER TABLE users ADD COLUMN quiet_end VARCHAR(5) NULL");
        db()->exec("ALTER TABLE wall_posts ADD COLUMN anonymous TINYINT(1) NOT NULL DEFAULT 0");
        db()->exec("ALTER TABLE wall_posts ADD COLUMN spotify_id VARCHAR(100) NULL");
        db()->exec("CREATE TABLE IF NOT EXISTS wheel_spins (
            user_id INT UNSIGNED NOT NULL,
            spin_date DATE NOT NULL,
            prize VARCHAR(50) NOT NULL,
            points INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, spin_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("ALTER TABLE forum_topics ADD COLUMN solved TINYINT(1) NOT NULL DEFAULT 0");
        db()->exec("ALTER TABLE polls ADD COLUMN group_id INT UNSIGNED NULL");
        db()->exec("CREATE TABLE IF NOT EXISTS group_reports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id INT UNSIGNED NOT NULL,
            reporter_id INT UNSIGNED NOT NULL,
            reason VARCHAR(100) NOT NULL,
            details TEXT NOT NULL,
            status ENUM('open','resolved') NOT NULL DEFAULT 'open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* DB-Problem -> update.php Hinweis */ }
}

/* ---------- Blockierte Nutzer ---------- */

/** Ist $targetId fuer $userId blockiert (in eine Richtung)? */
function is_blocked(int $userId, int $targetId): bool
{
    static $cache = [];
    $key = $userId . ':' . $targetId;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $stmt = db()->prepare('SELECT 1 FROM blocked_users WHERE user_id = ? AND blocked_id = ?');
        $stmt->execute([$userId, $targetId]);
        return $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

/* ---------- Rollen-System ---------- */

/** Alle Berechtigungsgruppen der Seite. */
function role_list(): array
{
    return [
        'admin'     => ['👑', 'Admin', 'Voller Zugriff auf alles'],
        'moderator' => ['🛡️', 'Moderator', 'Meldungen bearbeiten, sperren, moderieren'],
        'member'    => ['👤', 'Mitglied', 'Normaler Community-Nutzer'],
        'vip'       => ['💎', 'VIP', 'Besonderer Dank – mit Badge'],
        'supporter' => ['🤝', 'Supporter', 'Engagierte Helfer – mit Badge'],
        'bot'       => ['🤖', 'Bot', 'Service-Konto (z. B. Discord-Bot)'],
    ];
}

/** Badge-HTML für eine Rolle (leer bei Mitglied/unknown). */
function role_badge(string $role): string
{
    $r = role_list()[$role] ?? null;
    if (!$r || $role === 'member') return '';
    return '<span class="badge">' . $r[0] . ' ' . e($r[1]) . '</span>';
}

/* ---------- Spoiler ---------- */

/** Wandelt ||Text|| in klickbare Spoiler-Blöcke um. */
function render_spoilers(string $text): string
{
    return preg_replace('/\|\|(.+?)\|\|/s',
        '<span class="spoiler"><span class="spoiler-head">⚠️ Spoiler <span class="spoiler-arrow">▶</span></span><span class="spoiler-body">$1</span></span>',
        $text);
}

/* ---------- Shop-Anzeige ---------- */

/** Inline-Style fuer gekaufte Namensfarbe (leer wenn keine). */
function name_color_style(?array $user): string
{
    $c = trim((string)($user['name_color'] ?? ''));
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) ? ' style="color:' . $c . '"' : '';
}

/* ---------- Punkte-Shop ---------- */

/** Alle Shop-Artikel (Festpreise in Punkten). */
function shop_items(): array
{
    return [
        'color_cyan'  => ['type' => 'color', 'name' => 'Name in Cyan',      'value' => '#22d3ee', 'cost' => 100],
        'color_gold'  => ['type' => 'color', 'name' => 'Name in Gold',      'value' => '#f0c850', 'cost' => 250],
        'color_pink'  => ['type' => 'color', 'name' => 'Name in Pink',      'value' => '#ff2ec4', 'cost' => 400],
        'color_red'   => ['type' => 'color', 'name' => 'Name in Rot',       'value' => '#ff6b6b', 'cost' => 300],
        'title_og'    => ['type' => 'title', 'name' => 'Titel „OG"',        'value' => 'OG',        'cost' => 500],
        'title_veteran' => ['type' => 'title', 'name' => 'Titel „Veteran"', 'value' => 'Veteran',   'cost' => 1000],
        'title_legende' => ['type' => 'title', 'name' => 'Titel „Legende"','value' => 'Legende',   'cost' => 2500],
        'color_green'  => ['type' => 'color', 'name' => 'Name in Grün',       'value' => '#2ecc71', 'cost' => 100],
        'color_purple' => ['type' => 'color', 'name' => 'Name in Lila',       'value' => '#a855f7', 'cost' => 150],
        'color_orange' => ['type' => 'color', 'name' => 'Name in Orange',     'value' => '#ff9f43', 'cost' => 200],
        'title_streamer' => ['type' => 'title', 'name' => 'Titel „Streamer"',   'value' => 'Streamer',  'cost' => 400],
        'title_supporter' => ['type' => 'title', 'name' => 'Titel „Supporter"','value' => 'Supporter', 'cost' => 600],
        'title_chaos'  => ['type' => 'title', 'name' => 'Titel „Chaos-Agent"',  'value' => 'Chaos-Agent', 'cost' => 750],
        'title_legende' => ['type' => 'title', 'name' => 'Titel „Legende"',    'value' => 'Legende',   'cost' => 2500],
        'frame_gold'  => ['type' => 'frame', 'name' => 'Goldener Profilrahmen', 'value' => 'gold', 'cost' => 800],
        'frame_neon'  => ['type' => 'frame', 'name' => 'Neon-Rahmen',           'value' => 'neon', 'cost' => 1200],
        'frame_fire'  => ['type' => 'frame', 'name' => 'Feuer-Rahmen',          'value' => 'fire', 'cost' => 2000],
        'frame_ice'   => ['type' => 'frame', 'name' => 'Eis-Rahmen',            'value' => 'ice',  'cost' => 1500],
        'frame_rainbow' => ['type' => 'frame', 'name' => 'Regenbogen-Rahmen',   'value' => 'rainbow', 'cost' => 3000],
        'frame_galaxy' => ['type' => 'frame', 'name' => 'Galaxie-Rahmen',       'value' => 'galaxy', 'cost' => 4000],
        'glow_name'   => ['type' => 'glow',  'name' => '✨ Leuchtender Name',     'value' => '1',    'cost' => 1500],
        'freeze_streak' => ['type' => 'freeze', 'name' => '❄️ Streak-Freezer',    'value' => '1',    'cost' => 150],
        'frame_pumpkin' => ['type' => 'frame', 'name' => '🎃 Kürbis-Rahmen (Saison)', 'value' => 'pumpkin', 'cost' => 500, 'season' => '10'],
        'frame_snow'  => ['type' => 'frame', 'name' => '❄️ Winter-Rahmen (Saison)', 'value' => 'snow',  'cost' => 500, 'season' => '12'],
    ];
}

/** Käufe eines Mitglieds: item_key => equipped. */
function user_shop_items(int $uid): array
{
    $stmt = db()->prepare('SELECT item_key, equipped FROM user_shop WHERE user_id = ?');
    $stmt->execute([$uid]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) $out[$r['item_key']] = (int)$r['equipped'];
    return $out;
}

/* ---------- Badge-Fortschritt ---------- */

/** Fortschritt je Badge: [aktuell, ziel] oder null wenn nicht messbar. */
function badge_progress(int $uid): array
{
    $p = [];
    $c = function (string $sql) use ($uid) {
        $s = db()->query(str_replace('{uid}', (string)$uid, $sql));
        return (int)$s->fetchColumn();
    };
    $p['first_post']   = [$c("SELECT COUNT(*) FROM wall_posts WHERE user_id = {uid}"), 1];
    $p['social']       = [$c("SELECT COUNT(*) FROM friendships WHERE user_id = {uid} AND status = 'accepted'"), 5];
    $p['chatty']       = [$c("SELECT COUNT(*) FROM messages WHERE sender_id = {uid}"), 50];
    $p['forum_pro']    = [$c("SELECT COUNT(*) FROM forum_topics WHERE user_id = {uid}"), 3];
    $p['liked']        = [$c("SELECT COUNT(*) FROM wall_reactions r JOIN wall_posts w ON w.id = r.post_id WHERE w.user_id = {uid} AND r.type = 'like'"), 25];
    $p['early']        = [$c("SELECT COUNT(DISTINCT DATE(created_at)) FROM wall_posts WHERE user_id = {uid} AND created_at > NOW() - INTERVAL 8 DAY"), 7];
    $p['photographer'] = [$c("SELECT COUNT(*) FROM wall_posts WHERE user_id = {uid} AND image IS NOT NULL AND image != ''"), 5];
    $p['voter']        = [$c("SELECT COUNT(*) FROM poll_votes WHERE user_id = {uid}"), 5];
    $p['winner']       = [$c("SELECT COUNT(*) FROM giveaways g JOIN giveaway_entries e ON e.giveaway_id = g.id WHERE g.winner_id = {uid}"), 1];
    $p['veteran']      = null; // zeitbasiert
    return $p;
}

/* ---------- Push (OneSignal) ---------- */

/** Sendet eine Push-Nachricht an ein Mitglied (Plugin push, OneSignal). */
function push_notify(int $userId, string $title, string $message, string $url = ''): void
{
    if (!plugin_active('push')) return;
    $appId = trim(setting('plugin_push_app_id'));
    $key   = trim(setting('plugin_push_rest_key'));
    if ($appId === '' || $key === '' || !function_exists('curl_init')) return;
    // Ruhezeiten des Empfaengers respektieren (HH:MM, z.B. 22:00-08:00)
    $qs = db()->prepare('SELECT quiet_start, quiet_end FROM users WHERE id = ?');
    $qs->execute([$userId]);
    $q = $qs->fetch();
    if ($q && $q['quiet_start'] !== null && $q['quiet_end'] !== null) {
        $now = (int)date('Hi');
        $s = (int)str_replace(':', '', $q['quiet_start']);
        $e = (int)str_replace(':', '', $q['quiet_end']);
        $inQuiet = ($s <= $e) ? ($now >= $s && $now < $e) : ($now >= $s || $now < $e);
        if ($inQuiet) return; // in Ruhezeit -> kein Push
    }
    $payload = [
        'app_id' => $appId,
        'include_external_user_ids' => [(string)$userId],
        'headings' => ['en' => $title, 'de' => $title],
        'contents' => ['en' => mb_substr($message, 0, 200), 'de' => mb_substr($message, 0, 200)],
    ];
    if ($url !== '') $payload['url'] = $url;
    $ch = curl_init('https://onesignal.com/api/v1/notifications');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'Authorization: Basic ' . $key],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/* ---------- Benutzergruppen (zusätzlich zu Rollen) ---------- */

function user_group_list(): array
{
    return [
        'vip'       => ['💎', 'VIP'],
        'verified'  => ['✔️', 'Verifiziert'],
        'team'      => ['🎽', 'Team'],
        'ehren'     => ['🎖️', 'Ehrenmitglied'],
        'creator'   => ['🎬', 'Content-Creator'],
        'eventteam' => ['🎪', 'Event-Team'],
        'sponsor'   => ['💰', 'Sponsor'],
    ];
}

/** Gruppen-Badge oder leer. */
function user_group_badge(?string $group): string
{
    if (!$group) return '';
    $g = user_group_list()[$group] ?? null;
    if (!$g) return '';
    return '<span class="badge badge-green">' . $g[0] . ' ' . e($g[1]) . '</span>';
}

/* ---------- Authentifizierung (Mitglieder) ---------- */

function is_member(): bool
{
    return !empty($_SESSION['member_id']);
}

function current_member(): ?array
{
    if (!is_member()) return null;
    $stmt = db()->prepare('SELECT id, username, email, display_name, bio, avatar_color, avatar,
                                  location, website, favorite_game, discord_tag, birthday,
                                  points, streak, last_bonus,
                                  role, status, ban_reason, is_verified, shadow_banned, referred_by, user_group,
                                  twofa_enabled, twofa_code, twofa_expires, last_digest,
                                  show_online, cover, forum_signature, notify_email, name_color, custom_title, profile_frame, is_public, created_at
                           FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['member_id']]);
    return $stmt->fetch() ?: null;
}

function require_member(): void
{
    if (!is_member()) {
        flash('error', 'Bitte zuerst anmelden.');
        header('Location: login.php');
        exit;
    }
    // Gesperrte Konten werden sofort abgemeldet
    $m = current_member();
    if ($m && $m['status'] === 'banned') {
        unset($_SESSION['member_id']);
        flash('error', 'Dieses Konto wurde gesperrt.'
            . ($m['ban_reason'] !== '' && $m['ban_reason'] !== null ? ' Grund: ' . $m['ban_reason'] : ''));
        header('Location: login.php');
        exit;
    }
}

function find_user_by_username(string $username): ?array
{
    $stmt = db()->prepare('SELECT id, username, email, display_name, bio, avatar_color, avatar,
                                  location, website, favorite_game, discord_tag, birthday,
                                  role, is_public, cover, name_color, custom_title, profile_frame, profile_song, custom_status, created_at
                           FROM users WHERE username = ?');
    $stmt->execute([$username]);
    return $stmt->fetch() ?: null;
}

function random_avatar_color(): string
{
    $palette = ['#6c5ce7', '#00cec9', '#e17055', '#fdcb6e', '#e84393', '#0984e3', '#00b894', '#d63031'];
    return $palette[array_rand($palette)];
}

/**
 * Rendert einen Avatar: hochgeladenes Profilbild oder Initialen-Kreis.
 */
function avatar_html(array $user, string $sizeClass = ''): string
{
    $avatar = trim((string)($user['avatar'] ?? ''));
    if ($avatar !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $avatar)) {
        $src = SITE_URL . '/uploads/avatars/' . rawurlencode($avatar);
        return '<img class="avatar ' . e($sizeClass) . '" src="' . e($src) . '" alt="Avatar von ' . e($user['display_name'] ?: $user['username']) . '">';
    }
    $name = trim((string)($user['display_name'] ?: $user['username']));
    if ($name === '') $name = '?';
    $initials = mb_strtoupper(mb_substr(preg_replace('/\s+/u', '', $name), 0, 2));
    $color = hexcolor((string)($user['avatar_color'] ?? ''), random_avatar_color());
    return '<span class="avatar ' . e($sizeClass) . '" style="background:' . $color . '">' . e($initials) . '</span>';
}

/**
 * Speichert einen hochgeladenen Avatar (max. 2 MB, JPG/PNG/GIF/WEBP).
 * Mit GD wird auf 256x256 verkleinert, sonst das Original gespeichert.
 */
function save_avatar_upload(array $file, int $userId): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload fehlgeschlagen (Fehlercode ' . (int)$file['error'] . ').');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Bild ist größer als 2 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new RuntimeException('Die Datei ist kein gültiges Bild.');
    }
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!isset($allowed[$info[2]])) {
        throw new RuntimeException('Nur JPG, PNG, GIF oder WEBP erlaubt.');
    }

    $dir = APP_ROOT . '/uploads/avatars';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    if (extension_loaded('gd')) {
        $src = null;
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($file['tmp_name']); break;
            case IMAGETYPE_PNG:  $src = imagecreatefrompng($file['tmp_name']); break;
            case IMAGETYPE_GIF:  $src = imagecreatefromgif($file['tmp_name']); break;
            case IMAGETYPE_WEBP: $src = imagecreatefromwebp($file['tmp_name']); break;
        }
        if (!$src) throw new RuntimeException('Bild konnte nicht verarbeitet werden.');
        $dst = imagecreatetruecolor(256, 256);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, 256, 256, $info[0], $info[1]);
        $filename = $userId . '_' . bin2hex(random_bytes(8)) . '.jpg';
        imagejpeg($dst, $dir . '/' . $filename, 85);
        imagedestroy($src);
        imagedestroy($dst);
    } else {
        $filename = $userId . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$info[2]];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
            throw new RuntimeException('Speichern der Datei fehlgeschlagen.');
        }
    }
    return $filename;
}

function delete_avatar_file(?string $filename): void
{
    $filename = trim((string)$filename);
    if ($filename !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $filename)) {
        $path = APP_ROOT . '/uploads/avatars/' . $filename;
        if (is_file($path)) unlink($path);
    }
}

/* ---------- Freunde-System ---------- */

/**
 * Gibt den Freundschaftsstatus zurück: friends | pending_out | pending_in | null
 */
function friendship_status(int $meId, int $otherId): ?string
{
    $stmt = db()->prepare('SELECT status FROM friendships WHERE user_id = ? AND friend_id = ?');
    $stmt->execute([$meId, $otherId]);
    $a = $stmt->fetch();
    $stmt->execute([$otherId, $meId]);
    $b = $stmt->fetch();
    if ($a && $a['status'] === 'accepted') return 'friends';
    if ($a && $a['status'] === 'pending')  return 'pending_out';
    if ($b && $b['status'] === 'pending')  return 'pending_in';
    return null;
}

function friend_request(int $fromId, int $toId): void
{
    db()->prepare('INSERT IGNORE INTO friendships (user_id, friend_id, status) VALUES (?, ?, ?)')
        ->execute([$fromId, $toId, 'pending']);
}

function friend_accept(int $meId, int $otherId): void
{
    db()->prepare("UPDATE friendships SET status = 'accepted' WHERE user_id = ? AND friend_id = ? AND status = 'pending'")
        ->execute([$otherId, $meId]);
    db()->prepare("INSERT IGNORE INTO friendships (user_id, friend_id, status) VALUES (?, ?, 'accepted')")
        ->execute([$meId, $otherId]);
}

function friend_remove(int $a, int $b): void
{
    db()->prepare('DELETE FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)')
        ->execute([$a, $b, $b, $a]);
}

/* ---------- Wall / Pinnwand ---------- */

/**
 * Extrahiert die Video-ID aus einem YouTube-Link
 * (unterstützt watch?v=, youtu.be, /embed/, /shorts/ und reinen ID-Eingabe).
 */
function youtube_video_id(string $url): ?string
{
    $url = trim($url);
    if ($url === '') return null;
    if (preg_match('#(?:youtube\.com/watch\?(?:.*&)?v=|youtu\.be/|youtube\.com/embed/|youtube\.com/shorts/)([A-Za-z0-9_-]{11})#', $url, $m)) {
        return $m[1];
    }
    if (preg_match('/^([A-Za-z0-9_-]{11})$/', $url)) return $url;
    return null;
}

/* ---------- Wall-Bilder ---------- */

/**
 * Speichert ein Wall-Bild (max. 5 MB, JPG/PNG/GIF/WEBP).
 * Mit GD wird auf max. 1200px Breite verkleinert (Seitenverhältnis bleibt).
 */
function save_wall_image(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload fehlgeschlagen (Fehlercode ' . (int)$file['error'] . ').');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Bild ist größer als 5 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new RuntimeException('Die Datei ist kein gültiges Bild.');
    }
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!isset($allowed[$info[2]])) {
        throw new RuntimeException('Nur JPG, PNG, GIF oder WEBP erlaubt.');
    }

    $dir = APP_ROOT . '/uploads/wall';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $token = bin2hex(random_bytes(8));

    if (extension_loaded('gd')) {
        $src = null;
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($file['tmp_name']); break;
            case IMAGETYPE_PNG:  $src = imagecreatefrompng($file['tmp_name']); break;
            case IMAGETYPE_GIF:  $src = imagecreatefromgif($file['tmp_name']); break;
            case IMAGETYPE_WEBP: $src = imagecreatefromwebp($file['tmp_name']); break;
        }
        if (!$src) throw new RuntimeException('Bild konnte nicht verarbeitet werden.');

        $maxW = 1200;
        $w = $info[0]; $h = $info[1];
        if ($w > $maxW) { $newW = $maxW; $newH = max(1, (int)round($h * $maxW / $w)); }
        else            { $newW = $w; $newH = $h; }

        $dst = imagecreatetruecolor($newW, $newH);
        if ($info[2] === IMAGETYPE_PNG || $info[2] === IMAGETYPE_GIF) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        $filename = $token . '.jpg';
        imagejpeg($dst, $dir . '/' . $filename, 85);
        imagedestroy($src);
        imagedestroy($dst);
    } else {
        $filename = $token . '.' . $allowed[$info[2]];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
            throw new RuntimeException('Speichern der Datei fehlgeschlagen.');
        }
    }
    return $filename;
}

/** Löscht eine hochgeladene Datei aus einem Upload-Unterordner. */
function delete_upload_file(?string $filename, string $subdir): void
{
    $filename = trim((string)$filename);
    if ($filename !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $filename)
        && preg_match('/^[A-Za-z0-9_-]+$/', $subdir)) {
        $path = APP_ROOT . '/uploads/' . $subdir . '/' . $filename;
        if (is_file($path)) unlink($path);
    }
}

/** Entfernt alle Wall-Daten eines Mitglieds inkl. Bilddateien. */
function delete_user_wall_posts(int $uid): void
{
    $stmt = db()->prepare('SELECT id, image FROM wall_posts WHERE user_id = ?');
    $stmt->execute([$uid]);
    foreach ($stmt->fetchAll() as $wp) {
        db()->prepare('DELETE FROM wall_reactions WHERE post_id = ?')->execute([$wp['id']]);
        db()->prepare('DELETE FROM wall_comments WHERE post_id = ?')->execute([$wp['id']]);
        db()->prepare('DELETE FROM wall_reports WHERE post_id = ?')->execute([$wp['id']]);
        if (!empty($wp['image'])) delete_upload_file($wp['image'], 'wall');
    }
    db()->prepare('DELETE FROM wall_posts WHERE user_id = ?')->execute([$uid]);
    db()->prepare('DELETE FROM wall_reactions WHERE user_id = ?')->execute([$uid]);
    db()->prepare('DELETE FROM wall_comments WHERE user_id = ?')->execute([$uid]);
    db()->prepare('DELETE FROM wall_reports WHERE reporter_id = ?')->execute([$uid]);
}

/* ---------- Benutzerdefinierte Profilfelder ---------- */

/** Aktive Profilfelder (Admin-definiert), sortiert. */
function profile_fields_active(): array
{
    try {
        return db()->query('SELECT * FROM profile_fields WHERE active = 1 ORDER BY display_order ASC, id ASC')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Gespeicherte Werte eines Mitglieds: field_id => value */
function get_profile_values(int $userId): array
{
    $stmt = db()->prepare('SELECT field_id, value FROM profile_values WHERE user_id = ?');
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int)$row['field_id']] = $row['value'];
    }
    return $out;
}

/** Prüft einen Wert gegen den Feldtyp (text, url, number, date). */
function validate_custom_field(string $type, string $value): bool
{
    switch ($type) {
        case 'url':    return $value === '' || filter_var($value, FILTER_VALIDATE_URL) !== false;
        case 'number': return $value === '' || is_numeric($value);
        case 'date':   return $value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
        default:       return mb_strlen($value) <= 500;
    }
}

/* ---------- E-Mail-Domain-Sperrliste (Wegwerf-E-Mails) ---------- */

/** true, wenn die Domain der E-Mail auf der Sperrliste steht. */
function email_domain_blocked(string $email): bool
{
    $at = strrpos($email, '@');
    if ($at === false) return false;
    $domain = strtolower(trim(substr($email, $at + 1)));
    if ($domain === '') return false;
    $stmt = db()->prepare('SELECT 1 FROM email_blocklist WHERE domain = ? AND active = 1 LIMIT 1');
    $stmt->execute([$domain]);
    return (bool)$stmt->fetchColumn();
}

/* ---------- Private Nachrichten ---------- */

function unread_message_count(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND read_at IS NULL');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/* ---------- Flash-Messages ---------- */

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function render_flash(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $out .= '<div class="flash flash-' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
    $_SESSION['flash'] = [];
    return $out;
}

/* ---------- Benachrichtigungen ---------- */

/** Erstellt eine Benachrichtigung (Actor = Auslöser, wird nie an sich selbst gesendet). */
function notify(int $userId, string $type, int $actorId, string $text, string $link = ''): void
{
    if ($userId === $actorId) return;
    db()->prepare('INSERT INTO notifications (user_id, actor_id, type, text, link) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $actorId, $type, $text, $link]);
}

function unread_notification_count(int $uid): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$uid]);
    return (int)$stmt->fetchColumn();
}

/* ---------- Online-Status / Aktivität ---------- */

/** Aktualisiert last_activity (gedrosselt auf max. alle 2 Minuten). */
function touch_activity(): void
{
    if (!is_member()) return;
    db()->exec('UPDATE users SET last_activity = NOW() WHERE id = ' . (int)$_SESSION['member_id']
        . ' AND (last_activity IS NULL OR last_activity < NOW() - INTERVAL 2 MINUTE)');
}

/** Wer ist gerade online? (nur wer es im Profil erlaubt hat) */
function online_users(): array
{
    try {
        return db()->query("SELECT username, display_name, avatar, avatar_color, role FROM users
                            WHERE show_online = 1 AND last_activity > NOW() - INTERVAL 5 MINUTE
                            ORDER BY display_name, username")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/* ---------- Ranking-System (Aktivität + Interaktionen) */
function rank_points(int $uid): int
{
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    $p  = 5 * (int)db()->query("SELECT COUNT(*) FROM wall_posts WHERE user_id = $uid")->fetchColumn();
    $p += 2 * (int)db()->query("SELECT COUNT(*) FROM wall_comments WHERE user_id = $uid")->fetchColumn();
    $p += 1 * (int)db()->query("SELECT COUNT(*) FROM wall_reactions WHERE user_id = $uid")->fetchColumn();
    $p += 10 * (int)db()->query("SELECT COUNT(*) FROM forum_topics WHERE user_id = $uid")->fetchColumn();
    $p += 5 * (int)db()->query("SELECT COUNT(*) FROM forum_posts WHERE user_id = $uid")->fetchColumn();
    $p += 1 * (int)db()->query("SELECT COUNT(*) FROM messages WHERE sender_id = $uid")->fetchColumn();
    return $cache[$uid] = $p;
}

function rank_title(int $points): string
{
    if ($points >= 1000) return '🏆 Legende';
    if ($points >= 400)  return '💎 Veteran';
    if ($points >= 150)  return '⭐ Aktivmitglied';
    if ($points >= 50)   return '🌟 Stammmitglied';
    return '🌱 Neuling';
}

/** Top-Mitglieder nach Punkten (ein Query). */
function rank_leaderboard(int $limit = 10): array
{
    try {
        return db()->query("SELECT u.id, u.username, u.display_name, u.avatar, u.avatar_color, u.role,
            (SELECT COUNT(*) FROM wall_posts w WHERE w.user_id = u.id) * 5 +
            (SELECT COUNT(*) FROM wall_comments c WHERE c.user_id = u.id) * 2 +
            (SELECT COUNT(*) FROM wall_reactions r WHERE r.user_id = u.id) +
            (SELECT COUNT(*) FROM forum_topics t WHERE t.user_id = u.id) * 10 +
            (SELECT COUNT(*) FROM forum_posts fp WHERE fp.user_id = u.id) * 5 +
            (SELECT COUNT(*) FROM messages m WHERE m.sender_id = u.id) AS points
            FROM users u ORDER BY points DESC LIMIT " . (int)$limit)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/* ---------- Chat-Helfer ---------- */

function chat_channel_mods(int $channelId): array
{
    $stmt = db()->prepare('SELECT user_id FROM chat_channel_mods WHERE channel_id = ?');
    $stmt->execute([$channelId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
}

/** Darf dieses Mitglied in diesem Channel moderieren (löschen)? */
function can_mod_channel(array $me, array $channel): bool
{
    if (in_array($me['role'], ['admin', 'moderator'], true)) return true;
    return in_array((int)$me['id'], chat_channel_mods((int)$channel['id']), true);
}

/* ---------- Zeitformatierung für Chats ---------- */

function chat_time(string $ts): string
{
    $t = strtotime($ts);
    if (date('Y-m-d', $t) === date('Y-m-d')) return 'Heute, ' . date('H:i', $t);
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'Gestern, ' . date('H:i', $t);
    return date('d.m.Y, H:i', $t);
}

/* ---------- Theme-System ---------- */

/** Verfügbare Themes mit kompletter Palette (einzige Quelle der Wahrheit). */
function theme_list(): array
{
    return [
        'dunkel' => [
            'label' => 'Dunkel', 'desc' => 'Das Standard-Dark-Design. Akzentfarben kommen aus den Einstellungen.',
            'scheme' => 'dark',
            'palette' => ['bg' => '#0b0f1a', 'bg2' => '#0f1524', 'bg3' => '#131b30', 'card' => 'rgba(255,255,255,.04)', 'cardhover' => 'rgba(255,255,255,.07)', 'border' => 'rgba(255,255,255,.09)', 'text' => '#e8ecf4', 'muted' => '#9aa4b8', 'headerbg' => 'rgba(11,15,26,.78)', 'input' => 'rgba(255,255,255,.05)', 'accent' => null, 'accent2' => null],
        ],
        'hell' => [
            'label' => 'Hell', 'desc' => 'Freundliches Light-Design für den Tag.',
            'scheme' => 'light',
            'palette' => ['bg' => '#f2f4fa', 'bg2' => '#e9edf6', 'bg3' => '#dde3f0', 'card' => 'rgba(255,255,255,.8)', 'cardhover' => 'rgba(255,255,255,.95)', 'border' => 'rgba(20,30,60,.12)', 'text' => '#1b2334', 'muted' => '#5a6478', 'headerbg' => 'rgba(255,255,255,.85)', 'input' => 'rgba(20,30,60,.05)', 'accent' => '#6c5ce7', 'accent2' => '#00cec9'],
        ],
        'mitternacht' => [
            'label' => 'Mitternacht', 'desc' => 'Tiefes Nachtblau – ruhig und elegant.',
            'scheme' => 'dark',
            'palette' => ['bg' => '#08111f', 'bg2' => '#0b1a30', 'bg3' => '#102544', 'card' => 'rgba(120,170,255,.05)', 'cardhover' => 'rgba(120,170,255,.09)', 'border' => 'rgba(120,170,255,.16)', 'text' => '#e3edff', 'muted' => '#8fa8cc', 'headerbg' => 'rgba(8,17,31,.82)', 'input' => 'rgba(120,170,255,.07)', 'accent' => '#3b82f6', 'accent2' => '#22d3ee'],
        ],
        'sonnenuntergang' => [
            'label' => 'Sonnenuntergang', 'desc' => 'Warmes Orange-Rot, gemütlich abends.',
            'scheme' => 'dark',
            'palette' => ['bg' => '#170f0c', 'bg2' => '#201410', 'bg3' => '#2c1b14', 'card' => 'rgba(255,150,80,.05)', 'cardhover' => 'rgba(255,150,80,.09)', 'border' => 'rgba(255,150,80,.16)', 'text' => '#f7ece3', 'muted' => '#c4a189', 'headerbg' => 'rgba(23,15,12,.82)', 'input' => 'rgba(255,150,80,.07)', 'accent' => '#ff7b3f', 'accent2' => '#ffb347'],
        ],
        'neon' => [
            'label' => 'Neon', 'desc' => 'Cyberpunk-Style mit grellen Akzenten.',
            'scheme' => 'dark',
            'palette' => ['bg' => '#0d0416', 'bg2' => '#150726', 'bg3' => '#1f0b36', 'card' => 'rgba(255,0,200,.05)', 'cardhover' => 'rgba(255,0,200,.1)', 'border' => 'rgba(255,0,200,.2)', 'text' => '#f3e8ff', 'muted' => '#b48fd4', 'headerbg' => 'rgba(13,4,22,.85)', 'input' => 'rgba(255,0,200,.07)', 'accent' => '#ff2ec4', 'accent2' => '#00f0ff'],
        ],
        'wald' => [
            'label' => 'Wald', 'desc' => 'Frisches Dunkelgrün – Natur-Look.',
            'scheme' => 'dark',
            'palette' => ['bg' => '#0a130e', 'bg2' => '#0f1c14', 'bg3' => '#15261b', 'card' => 'rgba(80,220,140,.05)', 'cardhover' => 'rgba(80,220,140,.09)', 'border' => 'rgba(80,220,140,.15)', 'text' => '#e6f5ec', 'muted' => '#8fb8a0', 'headerbg' => 'rgba(10,19,14,.82)', 'input' => 'rgba(80,220,140,.07)', 'accent' => '#22c55e', 'accent2' => '#a3e635'],
        ],
        // ---------- Saisonale Feiertags-Themes ----------
        'halloween' => [
            'label' => '🎃 Halloween', 'desc' => 'Kürbis-Orange & Hexen-Lila – gruselig schön.',
            'scheme' => 'dark', 'group' => 'saisonal',
            'palette' => ['bg' => '#120a18', 'bg2' => '#1a1020', 'bg3' => '#241430', 'card' => 'rgba(255,120,0,.05)', 'cardhover' => 'rgba(255,120,0,.1)', 'border' => 'rgba(255,120,0,.18)', 'text' => '#f5ead9', 'muted' => '#b09a80', 'headerbg' => 'rgba(18,10,24,.85)', 'input' => 'rgba(255,120,0,.07)', 'accent' => '#ff7518', 'accent2' => '#a855f7'],
        ],
        'nikolaus' => [
            'label' => '🎅 Nikolaus', 'desc' => 'Klassisches Rot mit warmem Creme – der 6. Dezember.',
            'scheme' => 'dark', 'group' => 'saisonal',
            'palette' => ['bg' => '#160d0d', 'bg2' => '#1f1212', 'bg3' => '#2c1919', 'card' => 'rgba(220,50,50,.06)', 'cardhover' => 'rgba(220,50,50,.1)', 'border' => 'rgba(220,50,50,.18)', 'text' => '#f7ecec', 'muted' => '#c09a9a', 'headerbg' => 'rgba(22,13,13,.85)', 'input' => 'rgba(220,50,50,.07)', 'accent' => '#d63031', 'accent2' => '#f5f0e1'],
        ],
        'weihnachten' => [
            'label' => '🎄 Weihnachten', 'desc' => 'Festliches Rot & Tannengrün mit winterlichem Glanz.',
            'scheme' => 'dark', 'group' => 'saisonal',
            'palette' => ['bg' => '#0f1510', 'bg2' => '#142013', 'bg3' => '#1d2f1a', 'card' => 'rgba(220,60,60,.05)', 'cardhover' => 'rgba(220,60,60,.1)', 'border' => 'rgba(220,60,60,.16)', 'text' => '#eef5ea', 'muted' => '#9db894', 'headerbg' => 'rgba(15,21,16,.85)', 'input' => 'rgba(220,60,60,.06)', 'accent' => '#d32f2f', 'accent2' => '#2e9e4f'],
        ],
        'silvester' => [
            'label' => '🎆 Silvester', 'desc' => 'Mitternachtsblau mit Gold – Für das neue Jahr.',
            'scheme' => 'dark', 'group' => 'saisonal',
            'palette' => ['bg' => '#0a0e1c', 'bg2' => '#0e1428', 'bg3' => '#162040', 'card' => 'rgba(240,200,80,.05)', 'cardhover' => 'rgba(240,200,80,.1)', 'border' => 'rgba(240,200,80,.18)', 'text' => '#f5efdc', 'muted' => '#b3a67e', 'headerbg' => 'rgba(10,14,28,.85)', 'input' => 'rgba(240,200,80,.07)', 'accent' => '#f0c850', 'accent2' => '#60a5fa'],
        ],
        'ostern' => [
            'label' => '🐣 Ostern', 'desc' => 'Zarte Pastelltöne in Rosa & Mint – frühlingshaft hell.',
            'scheme' => 'light', 'group' => 'saisonal',
            'palette' => ['bg' => '#fdf6f8', 'bg2' => '#f9ebf0', 'bg3' => '#f3dde6', 'card' => 'rgba(255,255,255,.85)', 'cardhover' => 'rgba(255,255,255,.98)', 'border' => 'rgba(180,120,150,.15)', 'text' => '#43303a', 'muted' => '#8f7280', 'headerbg' => 'rgba(253,246,248,.88)', 'input' => 'rgba(180,120,150,.07)', 'accent' => '#f472b6', 'accent2' => '#7fd3a0'],
        ],
    ];
}

/** Aktives Theme (validiert). */
function current_theme(): string
{
    $t = setting('site_theme', 'dunkel');
    return array_key_exists($t, theme_list()) ? $t : 'dunkel';
}

/** Generiert den CSS-Block mit allen Theme-Definitionen. */
function theme_css(): string
{
    static $css = null;
    if ($css !== null) return $css;
    $out = '';
    foreach (theme_list() as $key => $t) {
        $p = $t['palette'];
        $props = "--bg:{$p['bg']};--bg-2:{$p['bg2']};--bg-3:{$p['bg3']};--card:{$p['card']};--card-hover:{$p['cardhover']};--border:{$p['border']};--text:{$p['text']};--muted:{$p['muted']};--header-bg:{$p['headerbg']};--input:{$p['input']};color-scheme:{$t['scheme']};";
        if (!empty($p['accent'])) $props .= "--accent:{$p['accent']};--accent2:{$p['accent2']};";
        $out .= 'body[data-theme="' . $key . '"]{' . $props . '}';
    }
    return $css = $out;
}

/* ---------- Plugin-System ---------- */

/** Ist ein Plugin aktiv? (plugin_<name> = '1') */
function plugin_active(string $name): bool
{
    // Community-Seiten sind frisch installiert standardmäßig AN (passt zur Checkbox-Anzeige)
    $defaults = ['shoutbox' => '1', 'events' => '1', 'giveaway' => '1', 'contest' => '1',
                 'polls' => '1', 'resources' => '1', 'groups' => '1'];
    $default = $defaults[$name] ?? '0';
    return setting('plugin_' . $name, $default) === '1';
}

/** Sendet eine Nachricht an den konfigurierten Discord-Webhook (sofern Plugin aktiv). */
function discord_notify(string $title, string $text, int $color = 3066993): bool
{
    if (!plugin_active('discord')) return false;
    $url = trim(setting('plugin_discord_webhook'));
    if ($url === '' || !function_exists('curl_init')) return false;
    $payload = json_encode([
        'embeds' => [[
            'title' => $title,
            'description' => mb_substr($text, 0, 1900),
            'color' => $color,
            'footer' => ['text' => setting('site_name', 'Webseite') . ' · ' . date('d.m.Y H:i')],
        ]],
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $res !== false && $code >= 200 && $code < 300;
}

/**
 * Discord-Server-Widget (Plugin discord, Feld widget_id):
 * Liest Online-Anzahl & Einladungslink der Server-Widget-API (5-Min-Cache).
 * Voraussetzung: Widget ist in den Discord-Server-Einstellungen AKTIVIERT.
 */
function discord_widget(): ?array
{
    static $cache = null;
    if ($cache !== null) return $cache ?: null;
    $sid = trim(setting('plugin_discord_widget_id'));
    if ($sid === '' || !function_exists('curl_init')) { $cache = false; return null; }
    $raw = setting('plugin_discord_widget_cache');
    if ($raw !== '') {
        $c = json_decode($raw, true);
        if (is_array($c) && ($c['t'] ?? 0) > time() - 300 && is_array($c['d'] ?? null)) {
            return $cache = $c['d'];
        }
    }
    $ch = curl_init('https://discord.com/api/guilds/' . rawurlencode($sid) . '/widget.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'VorstellungsCMS/1.0']);
    $res = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$res, true);
    if (!is_array($j) || empty($j['id'])) { $cache = false; return null; } // Widget evtl. deaktiviert
    $d = [
        'name'   => (string)($j['name'] ?? 'Discord'),
        'online' => (int)($j['presence_count'] ?? 0),
        'invite' => (string)($j['instant_invite'] ?? ''),
    ];
    set_setting('plugin_discord_widget_cache', json_encode(['t' => time(), 'd' => $d]));
    return $cache = $d;
}

/** Schreibt einen Login-Eintrag in die Historie (Plugin login_history). */
function log_login(string $username, string $scope, bool $success): void
{
    if (!plugin_active('login_history')) return;
    try {
        db()->prepare('INSERT INTO login_log (username, scope, success, ip) VALUES (?, ?, ?, ?)')
            ->execute([$username, $scope, $success ? 1 : 0, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) { /* Tabelle fehlt -> Plugin ohne update.php */ }
}

/** reCAPTCHA-Token bei Google verifizieren. */
function recaptcha_verify(string $token): bool
{
    $secret = trim(setting('plugin_recaptcha_secret'));
    if ($secret === '') return true; // nicht konfiguriert -> nicht blockieren
    if ($token === '' || !function_exists('curl_init')) return false;
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]),
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string)$res, true);
    return !empty($data['success']);
}

/* ---------- Social Links (für Footer/Seiten) ---------- */

function social_links(): array
{
    $map = [
        'github'   => ['GitHub', setting('social_github')],
        'twitch'   => ['Twitch', setting('social_twitch')],
        'youtube'  => ['YouTube', setting('social_youtube')],
        'discord'  => ['Discord', setting('social_discord')],
        'x'        => ['X / Twitter', setting('social_x')],
        'instagram'=> ['Instagram', setting('social_instagram')],
        'email'    => ['E-Mail', setting('email') ? 'mailto:' . setting('email') : ''],
    ];
    $links = [];
    foreach ($map as $key => [$label, $url]) {
        if (trim((string)$url) !== '') $links[$key] = ['label' => $label, 'url' => $url];
    }
    return $links;
}

/* ---------- Plugin: Wartungsmodus (Hook) ---------- */
// Greift auf ALLEN Seiten, außer Admin-Login/Setup – Admins (eingeloggt) sind nie blockiert.
$maintActive = setting('plugin_maintenance', '0') === '1';
// Geplante Wartung: automatisch aktiv, wenn Start-Zeit erreicht
$maintStart = trim(setting('plugin_maintenance_start'));
if (!$maintActive && $maintStart !== '' && strtotime($maintStart) <= time()) {
    $maintActive = true;
}
if ($maintActive && !is_logged_in()) {
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!in_array($script, ['install.php', 'update.php', 'login.php'], true)) {
        $wmsg = trim(setting('plugin_maintenance_msg')) !== '' ? setting('plugin_maintenance_msg') : 'Wir sind bald wieder da – schau in Kürze vorbei!';
        http_response_code(503);
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Wartungsarbeiten</title>'
           . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0b0f1a;color:#e8ecf4;font-family:system-ui,sans-serif}'
           . '.box{text-align:center;padding:2rem;max-width:480px}h1{margin:.5rem 0;font-size:1.8rem}'
           . '.spin{font-size:3rem;display:inline-block;animation:s 2.5s linear infinite}@keyframes s{to{transform:rotate(360deg)}}'
           . 'p{color:#9aa4b8;line-height:1.6}</style></head><body><div class="box">'
           . '<span class="spin">🔧</span><h1>Wartungsarbeiten</h1><p>' . e($wmsg) . '</p>'
           . '</div></body></html>';
        exit;
    }
}

ensure_feature_tables();

/* ---------- IP-Sperren (Hook) ---------- */
try {
    // Tabelle auto-erstellen, falls sie fehlt (frische Installation ohne update.php)
    @db()->exec("CREATE TABLE IF NOT EXISTS ip_bans (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        reason VARCHAR(255) NULL,
        created_by VARCHAR(50) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Echte Client-IP auch hinter Proxy/CDN (nur erste vertrauenswürdige)
    $banIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $xff = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        $first = trim($xff[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) $banIp = $first;
    }
    // Eingeloggte Admins nie aussperren (sonst kein Zugriff zum Entsperren)
    if ($banIp !== '' && !is_logged_in()) {
        $stmt = db()->prepare('SELECT reason FROM ip_bans WHERE ip = ? LIMIT 1');
        $stmt->execute([$banIp]);
        $banReason = $stmt->fetchColumn();
        if ($banReason !== false && $banReason !== null) {
            http_response_code(403);
            echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Zugriff blockiert</title>'
               . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0b0f1a;color:#e8ecf4;'
               . 'font-family:system-ui,sans-serif}.box{text-align:center;padding:2rem;max-width:440px}'
               . 'h1{color:#e74c3c}</style></head><body><div class="box"><h1>⛔ Zugriff blockiert</h1>'
               . '<p>Deine IP-Adresse wurde gesperrt.</p>'
               . '<p style="color:#9aa4b8">' . e((string)$banReason) . '</p></div></body></html>';
            exit;
        }
    }
} catch (Throwable $e) { /* Tabelle fehlt -> update.php ausführen */ }
