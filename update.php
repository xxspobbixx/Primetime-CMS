<?php
/**
 * Update-Skript: erweitert bestehende Installationen um das Mitglieder-System.
 * Einfach im Browser aufrufen, nachdem die alte Version läuft.
 * Anschließend diese Datei VOM SERVER LÖSCHEN!
 */
// functions.php lädt db.php + config -> db() verfügbar
require_once __DIR__ . '/includes/functions.php';

$migrations = [
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS location VARCHAR(100) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS website VARCHAR(255) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS favorite_game VARCHAR(100) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS discord_tag VARCHAR(50) NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS birthday DATE NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar VARCHAR(500) NULL",
    "CREATE TABLE IF NOT EXISTS friendships (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        friend_id INT UNSIGNED NOT NULL,
        status ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_friendship (user_id, friend_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sender_id INT UNSIGNED NOT NULL,
        receiver_id INT UNSIGNED NOT NULL,
        message TEXT NOT NULL,
        read_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sender (sender_id),
        KEY idx_receiver (receiver_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS wall_posts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        content TEXT NOT NULL,
        youtube_id VARCHAR(11) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_wall_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS wall_reactions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        type VARCHAR(20) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_reaction (post_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS wall_comments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        content VARCHAR(500) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_comment_post (post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS wall_reports (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id INT UNSIGNED NOT NULL,
        reporter_id INT UNSIGNED NOT NULL,
        reason VARCHAR(100) NOT NULL,
        details TEXT NOT NULL,
        status ENUM('open','resolved') NOT NULL DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE users ADD COLUMN status ENUM('active','banned') NOT NULL DEFAULT 'active' AFTER role",
    "ALTER TABLE users ADD COLUMN ban_reason VARCHAR(255) NULL AFTER status",
    "CREATE TABLE IF NOT EXISTS profile_fields (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(100) NOT NULL,
        field_type ENUM('text','url','number','date') NOT NULL DEFAULT 'text',
        required TINYINT(1) NOT NULL DEFAULT 0,
        display_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS profile_values (
        user_id INT UNSIGNED NOT NULL,
        field_id INT UNSIGNED NOT NULL,
        value TEXT,
        PRIMARY KEY (user_id, field_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS email_blocklist (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain VARCHAR(100) NOT NULL UNIQUE,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE wall_posts ADD COLUMN image VARCHAR(500) NULL",
    "ALTER TABLE users ADD COLUMN last_activity DATETIME NULL",
    "ALTER TABLE users ADD COLUMN show_online TINYINT(1) NOT NULL DEFAULT 1",
    "CREATE TABLE IF NOT EXISTS notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        actor_id INT UNSIGNED NULL,
        type VARCHAR(30) NOT NULL,
        text VARCHAR(255) NOT NULL,
        link VARCHAR(255) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_notif_user (user_id, is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS pm_hidden (
        user_id INT UNSIGNED NOT NULL,
        partner_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, partner_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS chat_channels (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(255) NULL,
        display_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS chat_messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        channel_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        message VARCHAR(1000) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_chat_channel (channel_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS chat_channel_mods (
        channel_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (channel_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS chat_reports (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        message_id INT UNSIGNED NOT NULL,
        reporter_id INT UNSIGNED NOT NULL,
        reason VARCHAR(100) NOT NULL,
        details TEXT NULL,
        status ENUM('open','resolved') NOT NULL DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS forum_categories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description VARCHAR(255) NULL,
        display_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS forum_topics (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        category_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(200) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_post_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS forum_posts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        topic_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "INSERT IGNORE INTO chat_channels (id, name, slug, description, display_order) VALUES
        (1, 'Allgemein', 'allgemein', 'Plaudere über alles Mögliche', 1),
        (2, 'Gaming', 'gaming', 'Games, Streams und Zocken', 2),
        (3, 'Technik', 'technik', 'Hardware, Software, Projekte', 3)",
    "INSERT IGNORE INTO forum_categories (id, name, description, display_order) VALUES
        (1, 'Allgemein', 'Alles rund um die Community', 1),
        (2, 'Gaming', 'Spiele-Diskussionen, Tipps & Tricks', 2),
        (3, 'Feedback', 'Verbesserungsvorschläge & Feedback', 3)",
    "ALTER TABLE rss_feeds ADD COLUMN last_error VARCHAR(500) NULL",
    "CREATE TABLE IF NOT EXISTS news_articles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        published TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "DROP TABLE IF EXISTS news_items",
    "DROP TABLE IF EXISTS rss_feeds",
    "ALTER TABLE users ADD COLUMN status ENUM('active','banned') NOT NULL DEFAULT 'active'",
    "ALTER TABLE users ADD COLUMN last_activity DATETIME NULL",
    "ALTER TABLE users ADD COLUMN show_online TINYINT(1) NOT NULL DEFAULT 1",
    "ALTER TABLE users ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1",
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
    "ALTER TABLE users ADD COLUMN points INT NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN streak INT NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN last_bonus DATE NULL",
    "ALTER TABLE users ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN shadow_banned TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN referred_by INT UNSIGNED NULL",
    "ALTER TABLE users ADD COLUMN twofa_enabled TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN twofa_code VARCHAR(6) NULL",
    "ALTER TABLE users ADD COLUMN twofa_expires DATETIME NULL",
    "ALTER TABLE wall_posts ADD COLUMN hidden TINYINT(1) NOT NULL DEFAULT 0",
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
    "ALTER TABLE users ADD COLUMN cover VARCHAR(500) NULL",
    "ALTER TABLE users ADD COLUMN forum_signature VARCHAR(500) NULL",
    "ALTER TABLE users ADD COLUMN notify_email TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN last_digest DATETIME NULL",
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
    "ALTER TABLE forum_categories ADD COLUMN parent_id INT UNSIGNED NULL",
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
    "ALTER TABLE users ADD COLUMN name_color VARCHAR(20) NULL",
    "ALTER TABLE users ADD COLUMN custom_title VARCHAR(50) NULL",
    "ALTER TABLE users ADD COLUMN profile_frame VARCHAR(30) NULL",
    "ALTER TABLE wall_posts ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE forum_topics ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE group_posts ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE wall_comments ADD COLUMN parent_id INT UNSIGNED NULL",
    "ALTER TABLE events ADD COLUMN group_id INT UNSIGNED NULL",
    "ALTER TABLE chat_messages ADD COLUMN edited TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE messages ADD COLUMN attachment VARCHAR(500) NULL",
    "ALTER TABLE news_articles ADD COLUMN publish_at DATETIME NULL",
    "CREATE TABLE IF NOT EXISTS user_shop (
        user_id INT UNSIGNED NOT NULL,
        item_key VARCHAR(50) NOT NULL,
        equipped TINYINT(1) NOT NULL DEFAULT 0,
        purchased_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, item_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS news_comments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        article_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        content VARCHAR(1000) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS topic_subscriptions (
        topic_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (topic_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS chat_reactions (
        message_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        emoji VARCHAR(10) NOT NULL,
        PRIMARY KEY (message_id, user_id, emoji)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS ip_bans (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        reason VARCHAR(255) NULL,
        created_by VARCHAR(50) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE users ADD COLUMN user_group VARCHAR(30) NULL",
    "ALTER TABLE groups_tbl ADD COLUMN image VARCHAR(500) NULL",
    "CREATE TABLE IF NOT EXISTS wheel_spins (
        user_id INT UNSIGNED NOT NULL,
        spin_date DATE NOT NULL,
        prize VARCHAR(50) NOT NULL,
        points INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, spin_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE forum_topics ADD COLUMN solved TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE polls ADD COLUMN group_id INT UNSIGNED NULL",
    "ALTER TABLE forum_posts ADD COLUMN best_answer TINYINT(1) NOT NULL DEFAULT 0",
    "CREATE TABLE IF NOT EXISTS forum_votes (
        post_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        vote TINYINT NOT NULL,
        PRIMARY KEY (post_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE forum_topics ADD COLUMN tags VARCHAR(255) NULL",
    "ALTER TABLE wall_posts ADD COLUMN edited TINYINT(1) NOT NULL DEFAULT 0",
    "CREATE TABLE IF NOT EXISTS wall_bookmarks (
        user_id INT UNSIGNED NOT NULL,
        post_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE users ADD COLUMN name_glow TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN profile_song VARCHAR(500) NULL",
    "CREATE TABLE IF NOT EXISTS blocked_users (
        user_id INT UNSIGNED NOT NULL,
        blocked_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, blocked_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE users ADD COLUMN profile_views INT NOT NULL DEFAULT 0",
    "ALTER TABLE chat_messages ADD COLUMN reply_to_id INT UNSIGNED NULL",
    "ALTER TABLE chat_messages ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE chat_channels ADD COLUMN slow_mode INT NOT NULL DEFAULT 0",
    "CREATE TABLE IF NOT EXISTS pm_reactions (
        message_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        emoji VARCHAR(10) NOT NULL,
        PRIMARY KEY (message_id, user_id, emoji)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE users ADD COLUMN streak_freeze INT NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN quiet_start VARCHAR(5) NULL",
    "ALTER TABLE users ADD COLUMN quiet_end VARCHAR(5) NULL",
    "ALTER TABLE wall_posts ADD COLUMN anonymous TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE users ADD COLUMN custom_status VARCHAR(120) NULL",
    "CREATE TABLE IF NOT EXISTS profile_visits (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        profile_id INT UNSIGNED NOT NULL,
        visitor_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE wall_posts ADD COLUMN spotify_id VARCHAR(100) NULL",
    "CREATE TABLE IF NOT EXISTS reports (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        reporter_id INT UNSIGNED NOT NULL,
        reported_id INT UNSIGNED NOT NULL,
        reason VARCHAR(100) NOT NULL,
        details TEXT NOT NULL,
        status ENUM('open','resolved') NOT NULL DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

// Vorab-Check: fehlen zentrale Spalten?
$preflight = [];
try {
    $cols = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['points', 'is_verified', 'shadow_banned', 'twofa_enabled'] as $c) {
        if (!in_array($c, $cols, true)) $preflight[] = "Spalte users.$c fehlt";
    }
} catch (Throwable $e) { /* users-Tabelle existiert nicht -> CREATE läuft unten */ }

$log = [];
if ($preflight) $log[] = ['ok' => false, 'sql' => 'Preflight', 'note' => implode(', ', $preflight) . ' -> wird jetzt ergänzt'];
try {
    $pdo = db();
    foreach ($migrations as $sql) {
        try {
            $pdo->exec($sql);
            $log[] = ['ok' => true, 'sql' => $sql];
        } catch (PDOException $e) {
            // z. B. "Duplicate column" bei erneutem Aufruf -> ignorieren
            $log[] = ['ok' => false, 'sql' => $sql, 'note' => $e->getMessage()];
        }
    }
    // Sicherstellen, dass bestehende Konten Admins bleiben
    $pdo->exec("UPDATE users SET role = 'admin' WHERE role NOT IN ('admin', 'member') OR role IS NULL");
} catch (Throwable $e) {
    exit('Datenbank-Fehler: ' . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body">
    <div class="card glass login-card">
        <div class="logo center-logo"><span class="logo-mark">&lt;/&gt;</span> Update</div>
        <div class="flash flash-success">✅ Datenbank-Migration ausgeführt.</div>
        <ul class="prose">
            <?php foreach ($log as $entry): ?>
                <li><?= $entry['ok'] ? '✔' : '–' ?> <code><?= htmlspecialchars($entry['sql']) ?></code>
                    <?php if (!$entry['ok']): ?><br><span class="muted small"><?= htmlspecialchars($entry['note']) ?></span><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="muted small"><strong>Diese Datei jetzt löschen!</strong> Danach stehen dir Registrierung, Login, Mitglieder-Panel und Mitgliederliste zur Verfügung.</p>
        <a class="btn full" href="index.php">Zur Webseite</a>
    </div>
</body>
</html>
