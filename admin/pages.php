<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin_role();
$adminCurrent = 'pages';

// Verfügbare Seiten-Slugs
$allowed = [
    'about'   => 'Über mich (Startseite)',
    'gaming'  => 'Gaming & Streams',
    'imprint' => 'Impressum',
    'privacy' => 'Datenschutz',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $slug = $_POST['slug'] ?? '';
    if (isset($allowed[$slug])) {
        $title   = trim((string)($_POST['title'] ?? ''));
        $content = allowed_html((string)($_POST['content'] ?? ''));
        db()->prepare('INSERT INTO pages (slug, title, content) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content), updated_at = NOW()')
            ->execute([$slug, $title, $content]);
        flash('success', 'Seite „' . $allowed[$slug] . '“ gespeichert.');
    }
    header('Location: pages.php' . (isset($allowed[$slug]) ? '?slug=' . urlencode($slug) : ''));
    exit;
}

$editSlug = $_GET['slug'] ?? 'about';
if (!isset($allowed[$editSlug])) $editSlug = 'about';
$page = get_page($editSlug) ?: ['title' => $allowed[$editSlug], 'content' => ''];

include __DIR__ . '/_header.php';
?>
<h1>Seiten bearbeiten</h1>
<p class="muted">Inhalte der statischen Seiten – einfache HTML-Tags wie &lt;strong&gt;, &lt;a&gt; oder &lt;ul&gt; sind erlaubt.</p>

<div class="filter-row">
    <?php foreach ($allowed as $slug => $label): ?>
        <a class="pill <?= $slug === $editSlug ? 'pill-active' : '' ?>" href="?slug=<?= urlencode($slug) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<form method="post" class="card glass form-stack">
    <?= csrf_field() ?>
    <input type="hidden" name="slug" value="<?= e($editSlug) ?>">
    <label>Titel
        <input type="text" name="title" value="<?= e($page['title']) ?>" required>
    </label>
    <label>Inhalt
        <textarea name="content" rows="14" class="mono"><?= e($page['content']) ?></textarea>
    </label>
    <div>
        <button class="btn" type="submit">Speichern</button>
        <a class="btn btn-ghost" href="../<?= $editSlug === 'about' ? 'index' : ($editSlug === 'gaming' ? 'gaming' : ($editSlug === 'imprint' ? 'impressum' : 'datenschutz')) ?>.php" target="_blank">Vorschau</a>
    </div>
</form>
<?php include __DIR__ . '/_footer.php'; ?>
