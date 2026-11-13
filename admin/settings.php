<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin_role();
$adminCurrent = 'settings';

$keys = [
    'site_name', 'owner_name', 'tagline', 'meta_description', 'footer_text', 'email',
    'about_focus', 'twitch_channel', 'news_per_page',
    'social_github', 'social_twitch', 'social_youtube', 'social_discord', 'social_x', 'social_instagram',
    'registration_open',
    'accent_color', 'accent_color_2',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($keys as $key) {
        $val = trim((string)($_POST[$key] ?? ''));
        if ($key === 'registration_open') $val = isset($_POST['registration_open']) ? '1' : '0';
        if (in_array($key, ['accent_color', 'accent_color_2'], true)) {
            $val = hexcolor($val, $key === 'accent_color' ? '#6c5ce7' : '#00cec9');
        }
        if ($key === 'news_per_page') $val = (string)max(1, min(50, (int)$val));
        set_setting($key, $val);
    }
    flash('success', 'Einstellungen gespeichert.');
    header('Location: settings.php');
    exit;
}

$val = fn(string $k) => e(setting($k));
include __DIR__ . '/_header.php';
?>
<h1>Einstellungen</h1>
<p class="muted">Hier steuerst du den gesamten Inhalt und das Design der Webseite.</p>

<form method="post" class="form-stack">
    <?= csrf_field() ?>

    <div class="card glass">
        <h3>Allgemein</h3>
        <div class="form-grid">
            <label>Name der Seite <input type="text" name="site_name" value="<?= $val('site_name') ?>"></label>
            <label>Dein Name <input type="text" name="owner_name" value="<?= $val('owner_name') ?>"></label>
            <label class="span-2">Tagline / Untertitel <input type="text" name="tagline" value="<?= $val('tagline') ?>" placeholder="Gamer · Entwickler · Creator"></label>
            <label class="span-2">Meta-Beschreibung (SEO) <input type="text" name="meta_description" value="<?= $val('meta_description') ?>"></label>
            <label class="span-2">Fußzeilen-Text <input type="text" name="footer_text" value="<?= $val('footer_text') ?>"></label>
            <label>E-Mail <input type="email" name="email" value="<?= $val('email') ?>"></label>
            <label>Fokus (Kurzinfo) <input type="text" name="about_focus" value="<?= $val('about_focus') ?>"></label>
            <label>Twitch-Kanal (nur Name, z. B. „musterstreamer“) <input type="text" name="twitch_channel" value="<?= $val('twitch_channel') ?>"></label>
            <label class="check"><input type="checkbox" name="registration_open" value="1" <?= setting('registration_open', 'rss_cache_minutes', '1') === '1' ? 'checked' : '' ?>> Registrierung für neue Mitglieder offen</label>
            <label>News pro Seite <input type="number" name="news_per_page" min="1" max="50" value="<?= $val('news_per_page') ?: '9' ?>"></label>

        </div>
    </div>

    <div class="card glass">
        <h3>Social Links</h3>
        <div class="form-grid">
            <label>GitHub-Profil <input type="url" name="social_github" value="<?= $val('social_github') ?>" placeholder="https://github.com/…"></label>
            <label>Twitch <input type="url" name="social_twitch" value="<?= $val('social_twitch') ?>"></label>
            <label>YouTube <input type="url" name="social_youtube" value="<?= $val('social_youtube') ?>"></label>
            <label>Discord (Einladungslink) <input type="url" name="social_discord" value="<?= $val('social_discord') ?>"></label>
            <label>X / Twitter <input type="url" name="social_x" value="<?= $val('social_x') ?>"></label>
            <label>Instagram <input type="url" name="social_instagram" value="<?= $val('social_instagram') ?>"></label>
        </div>
    </div>

    <div class="card glass">
        <h3>Design</h3>
        <div class="form-grid">
            <label>Akzentfarbe <input type="color" name="accent_color" value="<?= $val('accent_color') ?: '#6c5ce7' ?>"></label>
            <label>Zweitfarbe (Verlauf) <input type="color" name="accent_color_2" value="<?= $val('accent_color_2') ?: '#00cec9' ?>"></label>
        </div>
    </div>

    <button class="btn" type="submit">Einstellungen speichern</button>
</form>
<?php include __DIR__ . '/_footer.php'; ?>
