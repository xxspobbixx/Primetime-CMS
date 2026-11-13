<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
$adminCurrent = 'diagnose';

$checks = [];
function check(string $label, callable $fn): void
{
    global $checks;
    try {
        $fn();
        $checks[] = ['label' => $label, 'ok' => true];
    } catch (Throwable $e) {
        $checks[] = ['label' => $label, 'ok' => false, 'err' => $e->getMessage()];
    }
}

// Pflicht-Tabellen (Kern + Plugins)
$tables = ['settings','users','pages','news_articles','projects','friendships','messages','pm_hidden','reports',
    'wall_posts','wall_reactions','wall_comments','wall_reports','profile_fields','profile_values','email_blocklist',
    'notifications','chat_channels','chat_messages','chat_channel_mods','chat_reports','forum_categories','forum_topics','forum_posts',
    'user_badges','events','event_participants','giveaways','giveaway_entries','contests','contest_entries','contest_votes',
    'shoutbox','password_resets','page_views','polls','poll_options','poll_votes','login_log'];
foreach ($tables as $t) {
    check("Tabelle: $t", function () use ($t) {
        if (!db()->query("SHOW TABLES LIKE '$t'")->fetch()) throw new RuntimeException('fehlt – update.php ausführen!');
    });
}

// Pflicht-Spalten
$cols = ['users' => ['points','streak','last_bonus','is_verified','shadow_banned','referred_by','twofa_enabled','show_online','last_activity','status'],
         'wall_posts' => ['hidden','image']];
foreach ($cols as $table => $list) {
    foreach ($list as $c) {
        check("Spalte: $table.$c", function () use ($table, $c) {
            $found = db()->query("SHOW COLUMNS FROM `$table` LIKE '$c'")->fetch();
            if (!$found) throw new RuntimeException('fehlt – update.php ausführen!');
        });
    }
}

// Kritische Live-Queries (exakt die, die zuletzt 500er verursacht haben)
check('Query: Wall-Posts (mit hidden)', function () {
    db()->query('SELECT w.id FROM wall_posts w WHERE w.hidden = 0 LIMIT 1')->fetchAll();
});
check('Query: member_of_month', function () {
    member_of_month();
});
check('Query: total_points', function () {
    $u = db()->query('SELECT id FROM users LIMIT 1')->fetch();
    if ($u) total_points((int)$u['id']);
});
check('Query: user_badges', function () {
    db()->query('SELECT badge_key FROM user_badges LIMIT 1')->fetchAll();
});
check('Query: Shadow-Ban-Liste', function () {
    db()->query('SELECT id FROM users WHERE shadow_banned = 1 LIMIT 1')->fetchAll();
});
check('Funktion: avatar_html', function () {
    avatar_html(['username' => 'test', 'display_name' => 'Test']);
});
check('Funktion: pluginlib geladen (add_points)', function () {
    if (!function_exists('add_points')) throw new RuntimeException('pluginlib.php nicht eingebunden!');
});

$okCount = count(array_filter($checks, fn($c) => $c['ok']));

// ---------- Community-Seiten: DB-Werte + Datei-Check ----------
$pagesCheck = [];
foreach (['shoutbox', 'events', 'giveaway', 'contest', 'polls', 'resources'] as $p) {
    $pagesCheck[$p] = [
        'db'     => setting('plugin_' . $p, '(nicht gesetzt = an)'),
        'active' => plugin_active($p),
    ];
}
$headerHasGating = strpos(file_get_contents(__DIR__ . '/../includes/header.php'), "plugin_active('shoutbox')") !== false;

include __DIR__ . '/_header.php';
?>
<h1>🔍 Diagnose</h1>
<p class="muted">Selbsttest aller Tabellen, Spalten und kritischen Abfragen. Bei „fehlt" → <strong>update.php</strong> im Browser aufrufen und danach löschen.</p>

<div class="card glass" style="margin-bottom:1.5rem">
    <div class="card-head"><h3>Ergebnis</h3><span class="badge <?= $okCount === count($checks) ? 'badge-green' : 'badge-red' ?>"><?= $okCount ?>/<?= count($checks) ?> OK</span></div>
    <?php if ($okCount === count($checks)): ?>
        <div class="flash flash-success">✅ Alles in Ordnung – alle Tabellen und Spalten vorhanden.</div>
    <?php else: ?>
        <div class="flash flash-error">⚠️ <?= count($checks) - $okCount ?> Problem(e) gefunden. Betroffene Stellen:</div>
    <?php endif; ?>
    <ul class="prose small" style="columns:2">
        <?php foreach ($checks as $c): ?>
        <li><?= $c['ok'] ? '✅' : '❌' ?> <?= e($c['label']) ?>
            <?php if (!$c['ok']): ?><br><span class="muted"><?= e($c['err']) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="card glass" style="margin-bottom:1.5rem">
    <h3>📄 Community-Seiten (Menü-Steuerung)</h3>
    <p class="muted small">header.php auf dem Server prüft die Plugins:
        <?= $headerHasGating ? '<span class="badge badge-green">JA ✅</span>' : '<span class="badge badge-red">NEIN ❌ – bitte aktuelle includes/header.php hochladen!</span>' ?></p>
    <table class="admin-table">
        <thead><tr><th>Seite</th><th>Gespeicherter Wert (DB)</th><th>plugin_active()</th><th>Im Menü?</th></tr></thead>
        <tbody>
        <?php foreach ($pagesCheck as $pKey => $pc): ?>
            <tr>
                <td><?= e($pKey) ?></td>
                <td class="mono"><?= e($pc['db']) ?></td>
                <td><?= $pc['active'] ? '<span class="badge badge-green">ja</span>' : '<span class="badge badge-red">nein</span>' ?></td>
                <td><?= $pc['active'] ? '✅ erscheint' : '🚫 versteckt' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted small" style="margin-top:.8rem">Wenn „Im Menü?" überall „✅ erscheint", obwohl der Wert „0" ist: header.php ist alt (oben prüfen).
    Wenn der DB-Wert „1" bleibt, obwohl du abgeschaltet hast: Speichern im Admin-Panel funktioniert nicht (CSRF?).</p>
</div>

<div class="card glass">
    <h3>System</h3>
    <ul class="fact-list">
        <li><span>PHP-Version</span><strong><?= e(PHP_VERSION) ?></strong></li>
        <li><span>DB-Name</span><strong><?= e(DB_NAME) ?></strong></li>
        <li><span>Site-URL</span><strong><?= e(SITE_URL) ?></strong></li>
        <li><span>Aktives Theme</span><strong><?= e(current_theme()) ?></strong></li>
        <li><span>Fehleranzeige (?debug=1)</span><strong>auf jeder Seite nutzbar</strong></li>
    </ul>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
