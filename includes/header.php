<?php
require_once __DIR__ . '/functions.php';

$siteName  = setting('site_name', 'Meine Webseite');
$ownerName = setting('owner_name', 'Max Mustermann');
$pageTitle = isset($pageTitle) ? $pageTitle . ' | ' . $siteName : $siteName . ' | ' . setting('tagline', 'Über mich, Gaming & Projekte');
$current   = $current ?? '';
$accent    = hexcolor(setting('accent_color', '#6c5ce7'), '#6c5ce7');
$accent2   = hexcolor(setting('accent_color_2', '#00cec9'), '#00cec9');
$member    = current_member();
touch_activity();
$siteTheme  = current_theme();

function nav_active(string $key, string $current): string
{
    return $key === $current ? ' class="active"' : '';
}
?>
<?php
// ---------- Plugin: Besucher-Statistik ----------
if (plugin_active('visitor_stats')) {
    try {
        $vp = substr((string)($_SERVER['REQUEST_URI'] ?? '/'), 0, 100);
        db()->prepare('INSERT INTO page_views (page) VALUES (?)')->execute([$vp]);
        db()->query('DELETE FROM page_views WHERE created_at < NOW() - INTERVAL 90 DAY');
    } catch (Throwable $e) { /* Tabelle fehlt */ }
}
// ---------- Plugin: SEO ----------
if (plugin_active('seo') && trim(setting('plugin_seo_description')) !== '') {
    $seoDesc = trim(setting('plugin_seo_description'));
    $seoImg  = trim(setting('plugin_seo_image'));
    echo '<meta name="description" content="' . e($seoDesc) . '">' . "
";
    echo '<meta property="og:title" content="' . e($siteName) . '">' . "
";
    echo '<meta property="og:description" content="' . e($seoDesc) . '">' . "
";
    echo '<meta property="og:type" content="website">' . "
";
    if ($seoImg !== '') echo '<meta property="og:image" content="' . e($seoImg) . '">' . "
";
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= e(setting('meta_description', setting('tagline', ''))) ?>">
    <title><?= e($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="manifest" href="<?= SITE_URL ?>/manifest.php">
    <meta name="theme-color" content="<?= e($accent) ?>">
    <link rel="apple-touch-icon" href="<?= SITE_URL ?>/assets/img/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <style>:root{--accent:<?= $accent ?>;--accent2:<?= $accent2 ?>;}
    <?= theme_css() ?>
    <?php if (plugin_active('custom_css')): ?><?= setting('plugin_custom_css_code') ?><?php endif; ?>
</style>
    <?= $extraHead ?? '' ?>
</head>
<body data-theme="<?= e($siteTheme) ?>">
<?php if (plugin_active('snowfall')):
    $snowCount = ['leicht' => 45, 'mittel' => 100, 'stark' => 190][setting('plugin_snowfall_intensity', 'mittel')] ?? 100;
    $particleType = setting('plugin_snowfall_type', 'schnee'); ?>
<canvas id="snowCanvas"></canvas>
<script>
(function () {
    var TYPE = '<?= e($particleType) ?>';
    var c = document.getElementById('snowCanvas'), x = c.getContext('2d'), P = [], N = <?= (int)$snowCount ?>;
    function resize() { c.width = innerWidth; c.height = innerHeight; }
    addEventListener('resize', resize); resize();
    function make() {
        var p = { x: Math.random() * c.width, y: Math.random() * c.height, s: Math.random() * 1.2 + .4, d: Math.random() * 1.5, rot: Math.random() * 6.3, rs: (Math.random() - .5) * .02 };
        if (TYPE === 'schnee') { p.r = Math.random() * 2.5 + .8; p.col = 'rgba(255,255,255,.85)'; p.shape = 'dot'; }
        else if (TYPE === 'blaetter') { p.r = Math.random() * 4 + 3; p.col = ['#e67e22', '#d35400', '#f39c12', '#c0392b'][Math.floor(Math.random() * 4)]; p.shape = 'leaf'; }
        else { p.r = Math.random() * 3 + 2; p.col = ['#ffb7d5', '#ffd1e3', '#fff0f6'][Math.floor(Math.random() * 3)]; p.shape = 'dot'; }
        return p;
    }
    for (var i = 0; i < N; i++) P.push(make());
    (function loop() {
        x.clearRect(0, 0, c.width, c.height);
        for (var i = 0; i < N; i++) {
            var f = P[i];
            f.y += f.s; f.x += Math.sin(f.y / 30) * f.d * .3 + (f.shape === 'leaf' ? .4 : 0); f.rot += f.rs;
            if (f.y > c.height + 10) { P[i] = make(); P[i].y = -10; continue; }
            x.save(); x.translate(f.x, f.y); x.rotate(f.rot); x.fillStyle = f.col;
            if (f.shape === 'leaf') { x.beginPath(); x.ellipse(0, 0, f.r, f.r * .5, 0, 0, 6.3); x.fill(); }
            else { x.beginPath(); x.arc(0, 0, f.r, 0, 7); x.fill(); }
            x.restore();
        }
        requestAnimationFrame(loop);
    })();
})();
</script>
<?php endif; ?>

<?php
// ---------- Plugin: Twitch-Live-Status ----------
if (plugin_active('twitch_status') && trim(setting('plugin_twitch_status_channel')) !== '') {
    $twitchCh = trim(setting('plugin_twitch_status_channel'));
    echo '<a class="twitch-live-bar" href="https://twitch.tv/' . e($twitchCh) . '" target="_blank" rel="noopener">🔴 LIVE – Jetzt auf Twitch: ' . e($twitchCh) . '!</a>';
}
// ---------- Plugin: Konfetti ----------
if (plugin_active('konfetti')):
?>
<script src="<?= SITE_URL ?>/assets/js/confetti.js"></script>
<?php
    if (!empty($_SESSION['confetti'])) { unset($_SESSION['confetti']); echo "<script>if(window.fireConfetti)fireConfetti();</script>"; }
    if ($member && setting('konfetti_uid_' . $member['id']) === '1') {
        set_setting('konfetti_uid_' . $member['id'], '0');
        echo "<script>if(window.fireConfetti)fireConfetti();</script>";
    }
    endif; ?>
<?php
// ---------- Plugin: Dark-Mode-Umschalter (Besucherwahl) ----------
if (plugin_active('darkmode_toggle')):
?>
<button type="button" id="themeToggle" class="theme-toggle" title="Hell/Dunkel wechseln">🌙</button>
<script>
(function () {
    var b = document.getElementById('themeToggle');
    function apply(mode) {
        if (mode === 'hell') { document.body.setAttribute('data-theme', 'hell'); b.textContent = '☀️'; }
        else { document.body.setAttribute('data-theme', '<?= e(current_theme()) ?>'); b.textContent = '🌙'; }
        try { localStorage.setItem('pref_theme', mode); } catch (e) {}
    }
    var saved = null;
    try { saved = localStorage.getItem('pref_theme'); } catch (e) {}
    if (saved) apply(saved);
    b.addEventListener('click', function () {
        apply(document.body.getAttribute('data-theme') === 'hell' ? '<?= e(current_theme()) ?>' : 'hell');
    });
})();
</script>
<?php endif; ?>
<header class="site-header">
    <div class="container header-inner">
        <a href="<?= SITE_URL ?>/<?= $member ? 'wall.php' : 'index.php' ?>" class="logo">
            <span class="logo-mark">&lt;/&gt;</span> <?= e($siteName) ?>
        </a>
        <div class="header-right">
        <?php if ($member): ?>
        <div class="notif-wrap">
            <button type="button" id="notifBell" class="notif-bell" aria-label="Benachrichtigungen" data-csrf="<?= csrf_token() ?>">🔔<span class="unread-badge" id="notifBadge" hidden></span></button>
            <div class="notif-panel" id="notifPanel" hidden>
                <div class="notif-head"><strong>Benachrichtigungen</strong><span class="muted small" id="notifCount"></span></div>
                <div id="notifList" class="notif-list"></div>
            </div>
        </div>
        <a href="<?= SITE_URL ?>/logout.php" class="notif-bell logout-btn logout-quick" title="Abmelden" aria-label="Abmelden">🚪</a>
        <?php endif; ?>
        <button class="nav-toggle" id="navToggle" aria-label="Menü öffnen">
            <span></span><span></span><span></span>
        </button>
        <nav class="main-nav" id="mainNav">
            <?php if (!$member): ?>
            <a href="<?= SITE_URL ?>/index.php"<?= nav_active('home', $current) ?>>Über mich</a>
            <a href="<?= SITE_URL ?>/news.php"<?= nav_active('news', $current) ?>>News</a>
            <a href="<?= SITE_URL ?>/projekte.php"<?= nav_active('projekte', $current) ?>>Projekte</a>
            <a href="<?= SITE_URL ?>/gaming.php"<?= nav_active('gaming', $current) ?>>Gaming &amp; Streams</a>
            <a href="<?= SITE_URL ?>/login.php"<?= nav_active('login', $current) ?>>Anmelden</a>
            <a class="btn btn-sm nav-cta" href="<?= SITE_URL ?>/register.php">Registrieren</a>
            <?php else: ?>
            <button type="button" id="searchToggle" class="nav-search-btn" title="Suchen">🔍</button>
            <div class="search-overlay" id="searchOverlay" hidden>
                <div class="search-box">
                    <input type="text" id="searchInput" placeholder="Mitglieder, Beiträge, Themen, Gruppen …" autocomplete="off">
                    <button type="button" id="searchClose" aria-label="Schließen">✕</button>
                    <div id="searchResults" class="search-results"></div>
                </div>
            </div>
            <a href="<?= SITE_URL ?>/wall.php"<?= nav_active('wall', $current) ?>>Wall</a>
            <a href="<?= SITE_URL ?>/chat.php"<?= nav_active('chat', $current) ?>>Chat</a>
            <a href="<?= SITE_URL ?>/forum.php"<?= nav_active('forum', $current) ?>>Forum</a>
            <a href="<?= SITE_URL ?>/gruppen.php"<?= nav_active('groups', $current) ?>>Gruppen</a>
            <a href="<?= SITE_URL ?>/mitglieder.php"<?= nav_active('members', $current) ?>>Mitglieder</a>
            <?php
            $moreKeys = ['polls', 'shoutbox', 'events', 'giveaway', 'contest', 'resources', 'shop', 'badges', 'neu', 'rad'];
            $moreCurrent = in_array($current, $moreKeys, true);
            ?>
            <details class="nav-more"<?= $moreCurrent ? ' open' : '' ?>>
                <summary>Mehr ▾</summary>
                <div class="nav-more-panel">
                    <a href="<?= SITE_URL ?>/rad.php"<?= nav_active('rad', $current) ?>>🎰 Glücksrad</a>
                    <a href="<?= SITE_URL ?>/neu.php"<?= nav_active('neu', $current) ?>>⚡ Was ist neu?</a>
                    <a href="<?= SITE_URL ?>/shop.php"<?= nav_active('shop', $current) ?>>🛒 Shop</a>
                    <a href="<?= SITE_URL ?>/badges.php"<?= nav_active('badges', $current) ?>>🏅 Badges</a>
                    <?php if (plugin_active('polls')): ?><a href="<?= SITE_URL ?>/umfragen.php"<?= nav_active('polls', $current) ?>>📊 Umfragen</a><?php endif; ?>
                    <?php if (plugin_active('shoutbox')): ?><a href="<?= SITE_URL ?>/shoutbox.php"<?= nav_active('shoutbox', $current) ?>>💬 Shoutbox</a><?php endif; ?>
                    <?php if (plugin_active('events')): ?><a href="<?= SITE_URL ?>/events.php"<?= nav_active('events', $current) ?>>📅 Events</a><?php endif; ?>
                    <?php if (plugin_active('giveaway')): ?><a href="<?= SITE_URL ?>/gewinnspiel.php"<?= nav_active('giveaway', $current) ?>>🎁 Gewinnspiel</a><?php endif; ?>
                    <?php if (plugin_active('contest')): ?><a href="<?= SITE_URL ?>/wettbewerbe.php"<?= nav_active('contest', $current) ?>>🏆 Wettbewerbe</a><?php endif; ?>
                    <?php if (plugin_active('resources')): ?><a href="<?= SITE_URL ?>/ressourcen.php"<?= nav_active('resources', $current) ?>>📦 Ressourcen</a><?php endif; ?>
                </div>
            </details>
            <span style="flex:1"></span>
            <a href="<?= SITE_URL ?>/nachrichten.php"<?= nav_active('messages', $current) ?> title="Nachrichten">✉<?php $unread = unread_message_count((int)$member['id']); if ($unread > 0): ?> <span class="unread-badge"><?= $unread ?></span><?php endif; ?></a>
            <a href="<?= SITE_URL ?>/panel.php" class="nav-user"<?= nav_active('panel', $current) ?> title="Mein Profil">👤 <?= e($member['display_name'] ?: $member['username']) ?></a>
            <a href="<?= SITE_URL ?>/logout.php" title="Abmelden" class="nav-logout">🚪</a>
            <?php endif; ?>
        </nav>
        </div><!-- /.header-right -->
    </div>
</header>
<?php if (plugin_active('broadcast') && trim(setting('plugin_broadcast_msg')) !== ''):
    $bMsgs = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', setting('plugin_broadcast_msg')))));
    $bSpeed = max(5, min(120, (int)setting('plugin_broadcast_speed', '22')));
    $bColors = [
        'accent' => '',   // Farbverlauf der Akzentfarben (Standard)
        'red'    => 'background:linear-gradient(120deg,#e74c3c,#c0392b)',
        'green'  => 'background:linear-gradient(120deg,#27ae60,#16a085)',
        'blue'   => 'background:linear-gradient(120deg,#2980b9,#6c5ce7)',
        'orange' => 'background:linear-gradient(120deg,#f39c12,#e67e22)',
        'gold'   => 'background:linear-gradient(120deg,#d4a017,#f0c850);color:#221a03',
        'dark'   => 'background:linear-gradient(120deg,#2c3e50,#1c2833)',
    ];
    $bColorKey = setting('plugin_broadcast_color', 'accent');
    $bStyle = $bColors[$bColorKey] ?? '';
    if ($bMsgs):
?>
<div class="broadcast-bar" role="status"<?= $bStyle !== '' ? ' style="' . e($bStyle) . '"' : '' ?>>
    <div class="broadcast-track" style="animation-duration:<?= $bSpeed ?>s">
        <?php for ($rep = 0; $rep < 3; $rep++): foreach ($bMsgs as $bm): ?>
            <span>📢 <?= e($bm) ?></span>
        <?php endforeach; endfor; ?>
    </div>
</div>
<?php endif; endif; ?>
<?php if (plugin_active('countdown') && trim(setting('plugin_countdown_date')) !== ''):
    $cdTs = strtotime(setting('plugin_countdown_date'));
    if ($cdTs): ?>
<div class="countdown-bar">⏳ <?= e(setting('plugin_countdown_label') !== '' ? setting('plugin_countdown_label') : 'Countdown') ?> · <strong class="cd-timer" data-target="<?= (int)$cdTs ?>">…</strong></div>
<script>
(function () {
    var el = document.querySelector('.cd-timer');
    if (!el) return;
    var t = parseInt(el.dataset.target, 10) * 1000;
    function f(n) { return String(n).padStart(2, '0'); }
    function tick() {
        var d = t - Date.now();
        if (d <= 0) { el.textContent = 'Jetzt live!'; return; }
        var s = Math.floor(d / 1000);
        el.textContent = Math.floor(s / 86400) + 'd ' + f(Math.floor(s % 86400 / 3600)) + ':' + f(Math.floor(s % 3600 / 60)) + ':' + f(s % 60);
    }
    tick();
    setInterval(tick, 1000);
})();
</script>
<?php endif; endif; ?>
<main>
<?= render_flash() ?>
