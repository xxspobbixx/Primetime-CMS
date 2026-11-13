<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
$admin = current_admin();
$adminCurrent = $adminCurrent ?? '';
$adminRole = $admin['role'] ?? 'member';

// Menüeinträge: [key, href, label, erlaubte Rollen]
$navItems = [
    ['dashboard', 'index.php', '📊 Dashboard', ['admin', 'moderator']],
    ['chat',      'chat.php', '💬 Chat-Moderation', ['admin', 'moderator']],
    ['users',     'users.php', '👤 Benutzer', ['admin', 'moderator']],
    ['reports',   'reports.php', '🚩 Meldungen', ['admin', 'moderator']],
    ['settings',  'settings.php', '⚙️ Einstellungen', ['admin']],
    ['themes',    'themes.php', '🎨 Themes', ['admin']],
    ['plugins',   'plugins.php', '🧩 Plugins', ['admin']],
    ['diagnose',  'diagnose.php', '🔍 Diagnose', ['admin', 'moderator']],
    ['kontakt',   'kontakt.php', '✉️ Kontakt', ['admin', 'moderator']],
    ['modlog',    'modlog.php', '🛡️ Mod-Log', ['admin', 'moderator']],
    ['mail',      'mail.php', '📬 Massen-E-Mail', ['admin']],
    ['cleanup',   'cleanup.php', '🧹 Bereinigung', ['admin']],
    ['pages',     'pages.php', '📝 Seiten', ['admin']],
    ['projects',  'projects.php', '💻 Projekte', ['admin']],
    ['news',      'news.php', '📰 News', ['admin']],
    ['fields',    'fields.php', '🏷️ Profilfelder', ['admin']],
    ['emailblock','emailblock.php', '📧 E-Mail-Sperrliste', ['admin']],
    ['forum',     'forum.php', '🗂️ Forum', ['admin']],
];
function admin_nav(string $key, string $cur): string { return $key === $cur ? ' class="active"' : ''; }
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $adminRole === 'moderator' ? 'Moderation' : 'Admin' ?> | <?= e(setting('site_name', 'Webseite')) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style><?= theme_css() ?></style>
</head>
<body class="admin-body" data-theme="<?= e(current_theme()) ?>">
<div class="admin-layout">
    <aside class="admin-sidebar" id="adminSidebar">
        <a class="logo" href="index.php"><span class="logo-mark">&lt;/&gt;</span> <?= $adminRole === 'moderator' ? 'Moderation' : 'Admin' ?></a>
        <nav>
            <?php foreach ($navItems as [$key, $href, $label, $roles]): ?>
                <?php if (in_array($adminRole, $roles, true)): ?>
                    <a href="<?= $href ?>"<?= admin_nav($key, $adminCurrent) ?>><?= $label ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-bottom">
            <a href="../index.php" target="_blank">↗ Seite ansehen</a>
            <a href="logout.php">🚪 Abmelden</a>
        </div>
    </aside>
    <div class="admin-main">
        <header class="admin-topbar">
            <button class="nav-toggle" id="adminToggle" aria-label="Menü"><span></span><span></span><span></span></button>
            <span class="muted">Angemeldet als <strong><?= e($admin['username'] ?? '') ?></strong>
                <span class="badge <?= $adminRole === 'admin' ? 'badge-red' : 'badge-green' ?>"><?= e($adminRole) ?></span></span>
        </header>
        <div class="admin-content">
            <?= render_flash() ?>
