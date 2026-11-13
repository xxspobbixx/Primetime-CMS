<?php
require_once __DIR__ . '/includes/functions.php';
// Eingeloggte Benutzer starten direkt auf der Wall
if (is_member()) {
    header('Location: ' . SITE_URL . '/wall.php');
    exit;
}
$current = 'home';
$about = get_page('about');
include __DIR__ . '/includes/header.php';
?>
<section class="hero">
    <div class="hero-bg"></div>
    <div class="container hero-inner reveal">
        <span class="pill">Willkommen auf meiner Seite</span>
        <h1><?= e(setting('owner_name', 'Max Mustermann')) ?></h1>
        <p class="tagline"><?= e(setting('tagline', 'Gamer · Entwickler · Creator')) ?></p>
        <div class="hero-cta">
            <a class="btn" href="<?= SITE_URL ?>/projekte.php">Meine Projekte</a>
            <?php if (setting('social_twitch')): ?>
                <a class="btn btn-ghost" href="<?= e(setting('social_twitch')) ?>" target="_blank" rel="noopener">Twitch folgen</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if (plugin_active('stats_widget')):
    $stMembers = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn();
    $stPosts   = (int)db()->query('SELECT COUNT(*) FROM wall_posts WHERE created_at > CURDATE()')->fetchColumn();
    $stOnline  = count(online_users());
?>
<div class="container" style="margin-top:1.5rem">
    <div class="stat-strip reveal">
        <div class="stat-item"><span class="stat-num"><?= $stMembers ?></span><span>Mitglieder</span></div>
        <div class="stat-item"><span class="stat-num"><?= $stPosts ?></span><span>Beiträge heute</span></div>
        <div class="stat-item"><span class="stat-num"><?= $stOnline ?></span><span>Jetzt online</span></div>
        <div class="stat-item"><span class="stat-num"><?= count(rank_leaderboard(1)) ? (int)rank_leaderboard(1)[0]['points'] : 0 ?></span><span>Top-Punkte</span></div>
    </div>
</div>
<?php endif; ?>

<section class="section">
    <div class="container split">
        <div class="reveal">
            <h2><?= e($about['title'] ?? 'Über mich') ?></h2>
            <div class="prose">
                <?= nl2br(allowed_html($about['content'] ?? '')) ?>
            </div>
        </div>
        <aside class="card glass reveal">
            <h3>Kurz &amp; knapp</h3>
            <ul class="fact-list">
                <li><span>Name</span><strong><?= e(setting('owner_name', '')) ?></strong></li>
                <li><span>Fokus</span><strong><?= e(setting('about_focus', 'Gaming & Entwicklung')) ?></strong></li>
                <li><span>E-Mail</span><strong><?= e(setting('email', '–')) ?></strong></li>
            </ul>
            <div class="social-row">
                <?php foreach (social_links() as $s): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e($s['url']) ?>" target="_blank" rel="noopener"><?= e($s['label']) ?></a>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <h2 class="section-title reveal">Entdecke mehr</h2>
        <div class="grid grid-3">
            <a class="card card-link reveal" href="<?= SITE_URL ?>/gaming.php">
                <div class="card-icon">🎮</div>
                <h3>Gaming &amp; Live Streams</h3>
                <p>Streamplan, Highlights und alle Links zu meinen Kanälen.</p>
                <span class="card-more">Zur Seite →</span>
            </a>
            <a class="card card-link reveal" href="<?= SITE_URL ?>/projekte.php">
                <div class="card-icon">💻</div>
                <h3>Projekte</h3>
                <p>Meine GitHub-Projekte mit Live-Daten direkt von der API.</p>
                <span class="card-more">Zur Seite →</span>
            </a>
            <a class="card card-link reveal" href="<?= SITE_URL ?>/news.php">
                <div class="card-icon">📰</div>
                <h3>News</h3>
                <p>Die neuesten Schlagzeilen aus meinen eingebundenen RSS-Feeds.</p>
                <span class="card-more">Zur Seite →</span>
            </a>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
