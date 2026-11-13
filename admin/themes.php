<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin_role();
$adminCurrent = 'themes';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $key = (string)($_POST['theme'] ?? '');
    if (array_key_exists($key, theme_list())) {
        set_setting('site_theme', $key);
        flash('success', 'Theme „' . theme_list()[$key]['label'] . '" aktiviert.');
    } else {
        flash('error', 'Unbekanntes Theme.');
    }
    header('Location: themes.php');
    exit;
}

$active = current_theme();
$themes = theme_list();
$standardKeys  = ['dunkel', 'hell', 'mitternacht', 'sonnenuntergang', 'neon', 'wald'];
$saisonalKeys  = ['halloween', 'nikolaus', 'weihnachten', 'silvester', 'ostern'];
$render_theme_card = function (string $key, array $t, bool $isActive) use ($defAccent, $defAccent2) {
    $p = $t['palette'];
    $acc  = $p['accent'] ?? $defAccent;
    $acc2 = $p['accent2'] ?? $defAccent2; ?>
    <div class="card glass theme-card <?= $isActive ? 'active' : '' ?>">
        <div class="theme-info">
            <div class="theme-preview">
                <div class="theme-preview-bar" style="background:<?= e($p['headerbg']) ?>"></div>
                <div class="theme-preview-body" style="background:<?= e($p['bg']) ?>">
                    <div class="tp-card" style="background:<?= e($p['card']) ?>;border-color:<?= e($p['border']) ?>">
                        <div class="tp-line short" style="background:<?= e($p['text']) ?>;opacity:.85"></div>
                        <div class="tp-line long" style="background:<?= e($p['muted']) ?>"></div>
                        <div class="tp-btn" style="background:linear-gradient(120deg,<?= e($acc) ?>,<?= e($acc2) ?>)"></div>
                    </div>
                    <div class="tp-card" style="background:<?= e($p['card']) ?>;border-color:<?= e($p['border']) ?>;margin-bottom:0">
                        <div class="tp-line long" style="background:<?= e($p['muted']) ?>"></div>
                    </div>
                </div>
            </div>
            <h4 style="margin-top:.8rem"><?= e($t['label']) ?> <?php if ($isActive): ?><span class="badge badge-green">aktiv</span><?php endif; ?></h4>
            <p class="muted small"><?= e($t['desc']) ?></p>
            <?php if (!$isActive): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="theme" value="<?= e($key) ?>">
                <button class="btn btn-sm" type="submit">Aktivieren</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
<?php };
// Fallback-Akzente für Vorschau (wenn Theme eigene Akzente hat, diese nutzen)
$defAccent  = hexcolor(setting('accent_color', '#6c5ce7'), '#6c5ce7');
$defAccent2 = hexcolor(setting('accent_color_2', '#00cec9'), '#00cec9');

include __DIR__ . '/_header.php';
?>
<h1>🎨 Themes</h1>
<p class="muted">Wähle das Design der gesamten Webseite (inkl. Admin-Panel). Die Änderung ist sofort für alle Besucher sichtbar.</p>

<h3 style="margin-bottom:1rem">Standard-Themes</h3>
<div class="theme-grid">
    <?php foreach ($standardKeys as $key): if (isset($themes[$key])) $render_theme_card($key, $themes[$key], $key === $active); endforeach; ?>
</div>

<h3 style="margin:1.8rem 0 1rem">🎉 Saisonale Feiertags-Themes</h3>
<p class="muted small" style="margin-bottom:1rem">Perfekt für Festtage – jederzeit aktivierbar und wieder deaktivierbar.</p>
<div class="theme-grid">
    <?php foreach ($saisonalKeys as $key): if (isset($themes[$key])) $render_theme_card($key, $themes[$key], $key === $active); endforeach; ?>
</div></div>

<div class="card glass" style="margin-top:1.5rem">
    <h3>Hinweis zu Akzentfarben</h3>
    <p class="muted small">Jedes Theme definiert eigene Akzentfarben. Beim Theme „Dunkel" greifen stattdessen die Akzentfarben aus den
 <a href="settings.php">Einstellungen</a>. Saisonale Themes kannst du jederzeit aktivieren und nach dem Fest wieder auf ein Standard-Theme zurückstellen.</p>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
