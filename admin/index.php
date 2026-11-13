<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
$adminCurrent = 'dashboard';

$stats = [
    'projects' => (int)db()->query('SELECT COUNT(*) FROM projects')->fetchColumn(),
    'articles' => (int)db()->query('SELECT COUNT(*) FROM news_articles')->fetchColumn(),
    'members'  => (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'member'")->fetchColumn(),
    'pages'    => (int)db()->query('SELECT COUNT(*) FROM pages')->fetchColumn(),
];

// Plugin: Automatisches Backup
maybe_auto_backup();

// Plugin: Wöchentlicher Report an Admin-E-Mail
if (plugin_active('weekly_report')) {
    $last = (int)setting('plugin_weekly_report_last', '0');
    if ($last < time() - 7 * 86400) {
        set_setting('plugin_weekly_report_last', (string)time());
        $adminMail = trim(setting('email'));
        if ($adminMail !== '' && filter_var($adminMail, FILTER_VALIDATE_EMAIL)) {
            $w  = "created_at > NOW() - INTERVAL 7 DAY";
            $nm = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'member' AND $w")->fetchColumn();
            $nw = (int)db()->query("SELECT COUNT(*) FROM wall_posts WHERE $w")->fetchColumn();
            $nc = (int)db()->query("SELECT COUNT(*) FROM wall_comments WHERE $w")->fetchColumn();
            $nt = (int)db()->query("SELECT COUNT(*) FROM forum_topics WHERE $w")->fetchColumn();
            $np = (int)db()->query("SELECT COUNT(*) FROM forum_posts WHERE $w")->fetchColumn();
            $nmsg = (int)db()->query("SELECT COUNT(*) FROM messages WHERE $w")->fetchColumn();
            $rep  = "Neue Mitglieder: $nm
Wall-Beiträge: $nw
Kommentare: $nc
Forum-Themen: $nt
Forum-Antworten: $np
Nachrichten: $nmsg";
            @mail($adminMail, setting('site_name', 'Webseite') . ': Wochen-Report',
                "Hallo,

hier die Zahlen der letzten 7 Tage:

$rep

Viele Grüße,
die Webseite",
                'From: noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        }
    }
}

// Dashboard 2.0: 14-Tage Aktivitäts-Chart
$activity = [];
foreach (db()->query("SELECT DATE(created_at) d, COUNT(*) c FROM wall_posts
                      WHERE created_at > NOW() - INTERVAL 14 DAY GROUP BY DATE(created_at)") as $r) {
    $activity[$r['d']] = (int)$r['c'];
}
$chartDays = []; $chartMax = 1;
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $c = $activity[$d] ?? 0;
    $chartMax = max($chartMax, $c);
    $chartDays[] = ['label' => date('d.m', strtotime($d)), 'count' => $c];
}

// Plugin: Wochen-Statistik
$weekStats = [];
if (plugin_active('weekly_stats')) {
    $w = "created_at > NOW() - INTERVAL 7 DAY";
    $weekStats = [
        'Mitglieder'  => (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'member' AND $w")->fetchColumn(),
        'Wall-Posts'  => (int)db()->query("SELECT COUNT(*) FROM wall_posts WHERE $w")->fetchColumn(),
        'Kommentare'  => (int)db()->query("SELECT COUNT(*) FROM wall_comments WHERE $w")->fetchColumn(),
        'Themen'      => (int)db()->query("SELECT COUNT(*) FROM forum_topics WHERE $w")->fetchColumn(),
        'Antworten'   => (int)db()->query("SELECT COUNT(*) FROM forum_posts WHERE $w")->fetchColumn(),
        'Nachrichten' => (int)db()->query("SELECT COUNT(*) FROM messages WHERE $w")->fetchColumn(),
    ];
}

include __DIR__ . '/_header.php';
?>
<h1>Dashboard</h1>
<?php if ($chartDays): ?>
<div class="card glass" style="margin-bottom:1.5rem">
    <h3>📈 Wall-Aktivität (14 Tage)</h3>
    <div class="growth-chart" style="height:110px">
        <?php foreach ($chartDays as $cd): $h = max(4, (int)round($cd['count'] / $chartMax * 100)); ?>
        <div class="growth-col" title="<?= $cd['count'] ?> Beiträge am <?= e($cd['label']) ?>">
            <div class="growth-bar" style="height:<?= $h ?>%"></div>
            <span class="growth-label"><?= date('d', strtotime(str_replace('.', '-', $cd['label']) . '-' . date('Y'))) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<p class="muted">Willkommen zurück! Hier der Überblick über deine Webseite.</p>

<div class="grid grid-4 stats-grid">
    <a class="card glass stat-card" href="projects.php"><span class="stat-num"><?= $stats['projects'] ?></span><span>Projekte</span></a>
    <a class="card glass stat-card" href="news.php"><span class="stat-num"><?= $stats['articles'] ?></span><span>News-Artikel</span></a>
    <a class="card glass stat-card" href="users.php"><span class="stat-num"><?= $stats['members'] ?></span><span>Mitglieder</span></a>
    <a class="card glass stat-card" href="pages.php"><span class="stat-num"><?= $stats['pages'] ?></span><span>Seiten</span></a>
</div>

<?php if ($weekStats): ?>
<div class="card glass" style="margin-bottom:1.5rem">
    <h3>📊 Wochen-Statistik (letzte 7 Tage)</h3>
    <div class="grid grid-3" style="gap:.8rem">
        <?php foreach ($weekStats as $label => $num): ?>
        <div style="text-align:center;padding:.6rem;background:var(--card);border-radius:var(--radius-sm);border:1px solid var(--border)">
            <span class="stat-num" style="font-size:1.5rem"><?= $num ?></span>
            <span class="muted small"><?= e($label) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card glass">
        <h3>System</h3>
        <ul class="fact-list">
            <li><span>PHP-Version</span><strong><?= e(PHP_VERSION) ?></strong></li>
            <li><span>Letzter RSS-Abruf</span><strong><?= $lastFetch ? e(date('d.m.Y H:i', strtotime($lastFetch))) : 'noch nie' ?></strong></li>
            <li><span>News-System</span><strong>eigene Artikel im Admin-Panel</strong></li>
        </ul>
    </div>
    <div class="card glass">
        <h3>Schnellzugriff</h3>
        <div class="social-row vertical">
            <?php if (($admin['role'] ?? '') === 'admin'): ?>
            <a class="btn btn-ghost" href="settings.php">Einstellungen bearbeiten</a>
            <a class="btn btn-ghost" href="pages.php?slug=about">Über-mich-Seite</a>
            <a class="btn btn-ghost" href="pages.php?slug=imprint">✍️ Impressum bearbeiten</a>
            <a class="btn btn-ghost" href="pages.php?slug=privacy">✍️ Datenschutz bearbeiten</a>
            <?php endif; ?>
            <a class="btn btn-ghost" href="reports.php">🚩 Meldungen bearbeiten</a>
            <a class="btn btn-ghost" href="users.php">👤 Benutzer verwalten</a>
        </div>
    </div>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
