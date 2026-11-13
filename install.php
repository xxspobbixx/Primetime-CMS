<?php
/**
 * AUTARKE Installation v2 – mit Config-Assistent.
 * - Liest config/config.php ein (Diagnose, falls es Probleme gibt)
 * - Kann die config/config.php SELBST schreiben (Formular)
 * Aufruf: install.php          – normal
 *         install.php?debug=1 – zeigt Fehlermeldungen
 * Nach erfolgreicher Installation diese Datei LOESCHEN!
 */

if (isset($_GET['debug'])) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$step        = (int)($_GET['step'] ?? 0);
$configFound = is_file(__DIR__ . '/config/config.php');
$configError = null;

// ---------- Config einlesen ----------
if ($configFound) {
    try { require_once __DIR__ . '/config/config.php'; }
    catch (Throwable $ex) { $configError = $ex->getMessage(); }
}
foreach (['DB_HOST' => 'localhost', 'DB_NAME' => '', 'DB_USER' => 'root', 'DB_PASS' => '', 'DB_CHARSET' => 'utf8mb4'] as $k => $v) {
    if (!defined($k)) define($k, $v);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $pdo;
}

$error = '';
$notes = [];

// ---------- Config-Assistent: Formular wurde abgeschickt ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['save_config'] ?? '') === '1') {
    $host = trim((string)($_POST['db_host'] ?? 'localhost'));
    $name = trim((string)($_POST['db_name'] ?? ''));
    $user = trim((string)($_POST['db_user'] ?? ''));
    $pass = (string)($_POST['db_pass'] ?? '');

    if ($name === '') {
        $error = 'Bitte einen Datenbanknamen angeben.';
    } else {
        // Verbindung mit den neuen Daten testen
        try {
            new PDO('mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4', $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $ex) {
            $error = 'Verbindung mit diesen Daten fehlgeschlagen: ' . e($ex->getMessage());
        }
        if (!$error) {
            // SITE_URL aus aktuellem Request ableiten
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
            $siteUrl = $base . ($dir === '' ? '' : $dir);

            $config = "<?php\n"
                . "define('DB_HOST', " . var_export($host, true) . ");\n"
                . "define('DB_NAME', " . var_export($name, true) . ");\n"
                . "define('DB_USER', " . var_export($user, true) . ");\n"
                . "define('DB_PASS', " . var_export($pass, true) . ");\n"
                . "define('DB_CHARSET', 'utf8mb4');\n"
                . "define('SITE_URL', " . var_export($siteUrl, true) . ");\n"
                . "define('APP_ROOT', dirname(__DIR__));\n\n"
                . "define('DEBUG', false);\n"
                . "if (DEBUG || isset(\$_GET['debug'])) {\n    ini_set('display_errors', '1');\n    ini_set('display_startup_errors', '1');\n    error_reporting(E_ALL);\n}\n\n"
                . "if (session_status() === PHP_SESSION_NONE) {\n"
                . "    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax',"
                . " 'secure' => !empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off']);\n"
                . "    session_start();\n}\n"
                . "date_default_timezone_set('Europe/Berlin');\n";

            $ok = @file_put_contents(__DIR__ . '/config/config.php', $config);
            if ($ok === false) {
                $error = 'Die Datei config/config.php konnte nicht geschrieben werden.'
                       . ' Bitte per FTP/Panel prüfen, ob der Ordner config beschreibbar ist (CHMOD 755 oder 777),'
                       . ' oder die Datei manuell mit folgendem Inhalt befüllen:<br><pre style="text-align:left;overflow:auto">'
                       . e($config) . '</pre>';
            } else {
                header('Location: install.php?step=0&saved=1');
                exit;
            }
        }
    }
}

// ---------- Diagnose anzeigen (immer auf Schritt 0) ----------
$diag = [
    'config_datei_gefunden' => $configFound ? 'JA' : 'NEIN',
    'config_fehler'         => $configError,
    'DB_HOST_gelesen'       => defined('DB_HOST') ? DB_HOST : '(nicht definiert)',
    'DB_NAME_gelesen'       => defined('DB_NAME') ? DB_NAME : '(nicht definiert)',
    'DB_USER_gelesen'       => defined('DB_USER') ? DB_USER : '(nicht definiert)',
];

/* ---------- Schema (v1.8) ---------- */
$schema = [
    "CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(100) NOT NULL PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL UNIQUE,
        email VARCHAR(255) NULL, password VARCHAR(255) NOT NULL,
        display_name VARCHAR(100) NULL, bio TEXT NULL,
        avatar_color VARCHAR(7) NOT NULL DEFAULT '#6c5ce7', avatar VARCHAR(500) NULL,
        location VARCHAR(100) NULL, website VARCHAR(255) NULL,
        favorite_game VARCHAR(100) NULL, discord_tag VARCHAR(50) NULL, birthday DATE NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'member',
        status ENUM('active','banned') NOT NULL DEFAULT 'active', ban_reason VARCHAR(255) NULL,
        last_activity DATETIME NULL, show_online TINYINT(1) NOT NULL DEFAULT 1,
        points INT NOT NULL DEFAULT 0, streak INT NOT NULL DEFAULT 0, last_bonus DATE NULL,
        is_verified TINYINT(1) NOT NULL DEFAULT 0, shadow_banned TINYINT(1) NOT NULL DEFAULT 0,
        referred_by INT UNSIGNED NULL,
        user_group VARCHAR(30) NULL,
        name_color VARCHAR(20) NULL,
        custom_title VARCHAR(50) NULL,
        profile_frame VARCHAR(30) NULL,
        cover VARCHAR(500) NULL,
        forum_signature VARCHAR(500) NULL,
        notify_email TINYINT(1) NOT NULL DEFAULT 0,
        last_digest DATETIME NULL,
        twofa_enabled TINYINT(1) NOT NULL DEFAULT 0, twofa_code VARCHAR(6) NULL, twofa_expires DATETIME NULL,
        is_public TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_users_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS pages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(50) NOT NULL UNIQUE,
        title VARCHAR(255) NOT NULL, content TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS news_articles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL, content TEXT NOT NULL,
        published TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS polls (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        question VARCHAR(255) NOT NULL,
        created_by INT UNSIGNED NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS poll_options (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        poll_id INT UNSIGNED NOT NULL,
        text VARCHAR(255) NOT NULL,
        display_order INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS poll_votes (
        poll_id INT UNSIGNED NOT NULL,
        option_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_poll_vote (poll_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS login_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        scope VARCHAR(10) NOT NULL DEFAULT 'member',
        success TINYINT(1) NOT NULL DEFAULT 1,
        ip VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS user_badges (
        user_id INT UNSIGNED NOT NULL,
        badge_key VARCHAR(50) NOT NULL,
        earned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, badge_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS events (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        event_date DATETIME NOT NULL,
        description TEXT NULL,
        created_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS event_participants (
        event_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (event_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS giveaways (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        winner_id INT UNSIGNED NULL,
        created_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS giveaway_entries (
        giveaway_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (giveaway_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS contests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS contest_entries (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        contest_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        entry_text VARCHAR(500) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS contest_votes (
        contest_id INT UNSIGNED NOT NULL,
        entry_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (contest_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS shoutbox (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        message VARCHAR(300) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS password_resets (
        user_id INT UNSIGNED NOT NULL,
        token VARCHAR(64) NOT NULL,
        expires DATETIME NOT NULL,
        PRIMARY KEY (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS page_views (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        page VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pv_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS oauth_accounts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        provider VARCHAR(20) NOT NULL,
        provider_id VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_oauth (provider, provider_id),
        KEY idx_oauth_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS resources (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        file VARCHAR(500) NOT NULL,
        original_name VARCHAR(255) NULL,
        image VARCHAR(500) NULL,
        downloads INT UNSIGNED NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS contact_messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(255) NOT NULL,
        subject VARCHAR(200) NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS mod_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        mod_id INT UNSIGNED NULL,
        mod_name VARCHAR(50) NOT NULL,
        action VARCHAR(100) NOT NULL,
        target VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_modlog_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS groups_tbl (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT NULL,
        privacy ENUM('public','private') NOT NULL DEFAULT 'public',
        creator_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS group_members (
        group_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'member',
        status ENUM('approved','pending') NOT NULL DEFAULT 'approved',
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (group_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS group_posts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        group_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        content TEXT NOT NULL,
        image VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS group_comments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        content VARCHAR(500) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS group_reports (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        group_id INT UNSIGNED NOT NULL,
        reporter_id INT UNSIGNED NOT NULL,
        reason VARCHAR(100) NOT NULL,
        details TEXT NOT NULL,
        status ENUM('open','resolved') NOT NULL DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS ip_bans (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        reason VARCHAR(255) NULL,
        created_by VARCHAR(50) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS projects (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL,
        github_repo VARCHAR(255) NOT NULL, description TEXT,
        display_order INT NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS friendships (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, friend_id INT UNSIGNED NOT NULL,
        status ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_friendship (user_id, friend_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sender_id INT UNSIGNED NOT NULL, receiver_id INT UNSIGNED NOT NULL,
        message TEXT NOT NULL, read_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sender (sender_id), KEY idx_receiver (receiver_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS pm_hidden (
        user_id INT UNSIGNED NOT NULL, partner_id INT UNSIGNED NOT NULL, PRIMARY KEY (user_id, partner_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS reports (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reporter_id INT UNSIGNED NOT NULL, reported_id INT UNSIGNED NOT NULL,
        reason VARCHAR(100) NOT NULL, details TEXT NOT NULL,
        status ENUM('open','resolved') NOT NULL DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, resolved_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS wall_posts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, content TEXT NOT NULL,
        youtube_id VARCHAR(11) NULL, image VARCHAR(500) NULL, hidden TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY idx_wall_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS wall_reactions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
        type VARCHAR(20) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_reaction (post_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS wall_comments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
        content VARCHAR(500) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_comment_post (post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS wall_reports (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_id INT UNSIGNED NOT NULL, reporter_id INT UNSIGNED NOT NULL,
        reason VARCHAR(100) NOT NULL, details TEXT NOT NULL,
        status ENUM('open','resolved') NOT NULL DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, resolved_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS profile_fields (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, label VARCHAR(100) NOT NULL,
        field_type ENUM('text','url','number','date') NOT NULL DEFAULT 'text',
        required TINYINT(1) NOT NULL DEFAULT 0, display_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS profile_values (
        user_id INT UNSIGNED NOT NULL, field_id INT UNSIGNED NOT NULL, value TEXT,
        PRIMARY KEY (user_id, field_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS email_blocklist (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(100) NOT NULL UNIQUE,
        active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, actor_id INT UNSIGNED NULL,
        type VARCHAR(30) NOT NULL, text VARCHAR(255) NOT NULL, link VARCHAR(255) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_notif_user (user_id, is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS chat_channels (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(255) NULL, display_order INT NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS chat_messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, channel_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
        message VARCHAR(1000) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_chat_channel (channel_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS chat_channel_mods (
        channel_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, PRIMARY KEY (channel_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS chat_reports (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, message_id INT UNSIGNED NOT NULL, reporter_id INT UNSIGNED NOT NULL,
        reason VARCHAR(100) NOT NULL, details TEXT NULL,
        status ENUM('open','resolved') NOT NULL DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS forum_categories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, description VARCHAR(255) NULL,
        parent_id INT UNSIGNED NULL,
        display_order INT NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS forum_topics (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, category_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
        title VARCHAR(200) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_post_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS forum_posts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, topic_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
        content TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

/* ---------- Schritt 0: Check ---------- */
$showConfigForm = false;
if ($step === 0) {
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        $error = 'PHP ' . PHP_VERSION . ' ist zu alt – mindestens PHP 7.4 wird benoetigt.';
    }
    if ($configError) {
        $error = 'Die config/config.php laesst sich nicht einlesen: ' . e($configError)
               . '<br><br>Am einfachsten: unten die Zugangsdaten erneut eintragen – der Assistent schreibt die Datei neu.';
        $showConfigForm = true;
    }
    if (DB_NAME === '' && !$error) {
        $error = 'In der config/config.php ist kein Datenbankname hinterlegt (DB_NAME ist leer).'
               . '<br><br>Bitte unten die Zugangsdaten eintragen – der Assistent schreibt die Datei korrekt.';
        $showConfigForm = true;
    }
    if (!$error) {
        try { db(); $notes[] = 'DB-Verbindung OK'; }
        catch (Throwable $e) {
            $error = 'DB-Verbindung fehlgeschlagen: ' . e($e->getMessage());
            $showConfigForm = true;
        }
    }
}

/* ---------- Schritt 1: Tabellen ---------- */
if ($step === 1 && !$error) {
    try {
        $pdo = db();
        $failed = [];
        foreach ($schema as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) { $failed[] = $e->getMessage(); }
        }
        if ($failed) {
            $error = count($failed) . ' Statement(s) fehlgeschlagen: ' . e($failed[0]);
        } else {
            // Bei BESTEHENDEN Tabellen fehlende Spalten ergänzen (mehrfach aufrufbar sicher)
            $alters = [
                "ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL",
                "ALTER TABLE users ADD COLUMN display_name VARCHAR(100) NULL",
                "ALTER TABLE users ADD COLUMN bio TEXT NULL",
                "ALTER TABLE users ADD COLUMN avatar_color VARCHAR(7) NOT NULL DEFAULT '#6c5ce7'",
                "ALTER TABLE users ADD COLUMN avatar VARCHAR(500) NULL",
                "ALTER TABLE users ADD COLUMN location VARCHAR(100) NULL",
                "ALTER TABLE users ADD COLUMN website VARCHAR(255) NULL",
                "ALTER TABLE users ADD COLUMN favorite_game VARCHAR(100) NULL",
                "ALTER TABLE users ADD COLUMN discord_tag VARCHAR(50) NULL",
                "ALTER TABLE users ADD COLUMN birthday DATE NULL",
                "ALTER TABLE users ADD COLUMN status ENUM('active','banned') NOT NULL DEFAULT 'active'",
                "ALTER TABLE users ADD COLUMN ban_reason VARCHAR(255) NULL",
                "ALTER TABLE users ADD COLUMN last_activity DATETIME NULL",
                "ALTER TABLE users ADD COLUMN show_online TINYINT(1) NOT NULL DEFAULT 1",
                "ALTER TABLE users ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1",
                "ALTER TABLE wall_posts ADD COLUMN image VARCHAR(500) NULL",
                "UPDATE users SET display_name = username WHERE display_name IS NULL OR display_name = ''",
            ];
            foreach ($alters as $a) {
                try { $pdo->exec($a); } catch (PDOException $ignored) { /* Spalte existiert bereits */ }
            }
            $defaults = [
                ['about', 'Über mich', "Hallo! Hier erzählst du im Admin-Panel unter „Seiten“ etwas über dich.\n\nErzähle, was du machst und was Besucher hier erwartet."],
                ['gaming', 'Streamplan & Infos', "Mein Streamplan:\n\nMontag – Freitag ab 19 Uhr\nSamstag – Community-Tag\nSonntag – frei / spontan"],
                ['imprint', 'Impressum', "Angaben gemäß § 5 TMG\n\nMax Mustermann\nMusterstraße 1\n12345 Musterstadt\n\nE-Mail: mail@example.com\n\nBitte im Admin-Panel anpassen."],
                ['privacy', 'Datenschutzerklärung', "Diese Datenschutzerklärung bitte im Admin-Panel durch deine eigene Erklärung ersetzen.\n\n1. Verantwortlicher\n2. Datenverarbeitung\n3. Cookies\n4. Externe Inhalte\n5. Deine Rechte (DSGVO)"],
            ];
            $stmt = $pdo->prepare('INSERT IGNORE INTO pages (slug, title, content) VALUES (?, ?, ?)');
            foreach ($defaults as $d) { $stmt->execute($d); }
            $pdo->exec("INSERT IGNORE INTO chat_channels (id, name, slug, description, display_order) VALUES
                (1, 'Allgemein', 'allgemein', 'Plaudere über alles Mögliche', 1),
                (2, 'Gaming', 'gaming', 'Games, Streams und Zocken', 2),
                (3, 'Technik', 'technik', 'Hardware, Software, Projekte', 3)");
            $pdo->exec("INSERT IGNORE INTO forum_categories (id, name, description, display_order) VALUES
                (1, 'Allgemein', 'Alles rund um die Community', 1),
                (2, 'Gaming', 'Spiele-Diskussionen, Tipps & Tricks', 2),
                (3, 'Feedback', 'Verbesserungsvorschläge & Feedback', 3)");
            header('Location: install.php?step=2');
            exit;
        }
    } catch (Throwable $e) {
        $error = 'Datenbank-Fehler: ' . e($e->getMessage());
    }
}

/* ---------- Schritt 2: Admin ---------- */
$adminExists = false;
try { $adminExists = (int)db()->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','moderator')")->fetchColumn() > 0; }
catch (Throwable $e) { }

if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['save_config'] ?? '') !== '1' && !$error) {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || strlen($password) < 8) {
        $error = 'Benutzername fehlt oder Passwort zu kurz (min. 8 Zeichen).';
    } else {
        try {
            db()->prepare('INSERT INTO users (username, email, password, role, display_name) VALUES (?, NULL, ?, ?, ?)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'admin', $username]);
            header('Location: install.php?step=3');
            exit;
        } catch (PDOException $e) {
            $error = 'Admin konnte nicht angelegt werden: ' . e($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body">
    <div class="card glass login-card" style="max-width:560px">
        <div class="logo center-logo"><span class="logo-mark">&lt;/&gt;</span> Installation</div>
        <p class="muted small center"><a href="?debug=1">Debug-Modus</a> · <a href="?step=0">Schritt 1</a></p>
        <?php if ($error): ?><div class="flash flash-error"><?= $error ?></div><?php endif; ?>
        <?php if (isset($_GET['saved'])): ?><div class="flash flash-success">✅ config/config.php wurde erfolgreich geschrieben.</div><?php endif; ?>

        <?php if ($step === 0): ?>
            <h3>Schritt 1: Umgebungs-Check</h3>
            <details style="margin-bottom:1rem">
                <summary class="muted small" style="cursor:pointer">🔍 Diagnose: Was liest das Installations-Skript?</summary>
                <ul class="prose small" style="margin-top:.5rem">
                    <?php foreach ($diag as $k => $v): ?>
                        <li><strong><?= e($k) ?>:</strong> <?= e((string)$v) ?></li>
                    <?php endforeach; ?>
                    <li><strong>PHP:</strong> <?= e(PHP_VERSION) ?></li>
                    <li><strong>pdo_mysql:</strong> <?= extension_loaded('pdo_mysql') ? '✅' : '❌ fehlt!' ?></li>
                </ul>
            </details>
            <?php if ($notes): ?><div class="flash flash-success"><?= e(implode(' · ', $notes)) ?></div><?php endif; ?>

            <?php if ($showConfigForm): ?>
                <h3>🔧 Config-Assistent</h3>
                <p class="muted small">Trage hier deine Datenbank-Zugangsdaten ein – die Datei config/config.php wird automatisch korrekt geschrieben.</p>
                <form method="post" class="form-stack">
                    <input type="hidden" name="save_config" value="1">
                    <label>Datenbank-Host <input type="text" name="db_host" value="<?= e(defined('DB_HOST') ? DB_HOST : 'localhost') ?>" required></label>
                    <label>Datenbank-Name <input type="text" name="db_name" value="<?= e(defined('DB_NAME') ? DB_NAME : '') ?>" required></label>
                    <label>Benutzer <input type="text" name="db_user" value="<?= e(defined('DB_USER') ? DB_USER : '') ?>" required></label>
                    <label>Passwort <input type="password" name="db_pass" value=""></label>
                    <button class="btn full" type="submit">Verbindung testen &amp; Config speichern</button>
                </form>
            <?php elseif (!$error): ?>
                <p class="flash flash-success">✅ Alles bereit (PHP <?= e(PHP_VERSION) ?>, DB-Verbindung OK)</p>
                <a class="btn full" href="?step=1">Weiter: Tabellen anlegen →</a>
            <?php endif; ?>

        <?php elseif ($step === 1): ?>
            <p>Tabellen werden angelegt …</p>
            <a class="btn full" href="?step=2">Weiter</a>

        <?php elseif ($step === 2): ?>
            <?php if ($adminExists): ?>
                <div class="flash flash-success">Es existiert bereits ein Admin-Konto.</div>
                <a class="btn full" href="admin/login.php">Zum Admin-Login</a>
            <?php else: ?>
                <p>✅ Tabellen angelegt. Jetzt das Admin-Konto erstellen:</p>
                <form method="post" class="form-stack">
                    <label>Benutzername <input type="text" name="username" required autofocus></label>
                    <label>Passwort (min. 8 Zeichen) <input type="password" name="password" required></label>
                    <button class="btn full" type="submit">Admin anlegen</button>
                </form>
            <?php endif; ?>

        <?php else: ?>
            <div class="flash flash-success">🎉 Installation abgeschlossen!</div>
            <ol class="prose">
                <li><strong>install.php jetzt vom Server löschen!</strong></li>
                <li><a href="admin/login.php">Zum Admin-Panel</a> – Einstellungen vornehmen, erste News schreiben.</li>
            </ol>
            <a class="btn full" href="index.php">Zur Webseite</a>
        <?php endif; ?>
    </div>
</body>
</html>
