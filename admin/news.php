<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin_role();
$adminCurrent = 'news';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id        = (int)($_POST['id'] ?? 0);
        $title     = trim((string)($_POST['title'] ?? ''));
        $content   = allowed_html((string)($_POST['content'] ?? ''));
        $published = isset($_POST['published']) ? 1 : 0;

        if ($title === '') {
            flash('error', 'Bitte einen Titel eingeben.');
        } elseif ($id > 0) {
            $pubAt = trim((string)($_POST['publish_at'] ?? ''));
            $pubAt = $pubAt !== '' ? date('Y-m-d H:i:s', strtotime($pubAt)) : null;
            db()->prepare('UPDATE news_articles SET title = ?, content = ?, published = ?, publish_at = ? WHERE id = ?')
                ->execute([$title, $content, $published, $pubAt, $id]);
            flash('success', 'News-Artikel aktualisiert.');
        } else {
            $pubAt = trim((string)($_POST['publish_at'] ?? ''));
            $pubAt = $pubAt !== '' ? date('Y-m-d H:i:s', strtotime($pubAt)) : null;
            db()->prepare('INSERT INTO news_articles (title, content, published, publish_at) VALUES (?, ?, ?, ?)')
                ->execute([$title, $content, $published, $pubAt]);
            flash('success', 'News-Artikel veröffentlicht.');
        }
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM news_articles WHERE id = ?')->execute([(int)$_POST['id']]);
        flash('success', 'Artikel gelöscht.');
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE news_articles SET published = 1 - published WHERE id = ?')->execute([(int)$_POST['id']]);
        flash('success', 'Status geändert.');
    }
    header('Location: news.php');
    exit;
}

$articles = db()->query('SELECT * FROM news_articles ORDER BY created_at DESC')->fetchAll();
$edit = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM news_articles WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

include __DIR__ . '/_header.php';
?>
<h1>📰 News-Verwaltung</h1>
<p class="muted">Schreibe eigene News-Artikel – sie erscheinen automatisch auf der News-Seite.
Erlaubte HTML-Tags: &lt;strong&gt;, &lt;a&gt;, &lt;ul&gt;, &lt;h3&gt;, &lt;img&gt;, &lt;p&gt; etc.</p>

<div class="grid grid-2">
    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <h3><?= $edit ? 'Artikel bearbeiten' : 'Neuer Artikel' ?></h3>
        <label>Titel
            <input type="text" name="title" value="<?= e($edit['title'] ?? '') ?>" maxlength="255" required autofocus>
        </label>
        <label>Inhalt (HTML möglich)
            <textarea name="content" rows="12" class="mono"><?= e($edit['content'] ?? '') ?></textarea>
        </label>
        <label class="check">
            <input type="checkbox" name="published" <?= ($edit['published'] ?? 1) ? 'checked' : '' ?>>
            Veröffentlicht (auf der News-Seite sichtbar)
        </label>
        <label>Geplante Veröffentlichung (optional – leer = sofort)
            <input type="datetime-local" name="publish_at" value="<?= e($edit['publish_at'] ?? '') ?>">
        </label>
        <p class="muted small">📅 Geplante Artikel erscheinen automatisch zum eingestellten Zeitpunkt.</p>
        <div>
            <button class="btn" type="submit"><?= $edit ? 'Speichern' : 'Veröffentlichen' ?></button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="news.php">Abbrechen</a><?php endif; ?>
        </div>
    </form>

    <div class="card glass">
        <h3>Vorhandene Artikel (<?= count($articles) ?>)</h3>
        <table class="admin-table">
            <thead><tr><th>Titel</th><th>Datum</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($articles as $a): ?>
                <tr>
                    <td>
                        <a href="../news.php#article-<?= (int)$a['id'] ?>" target="_blank" style="color:var(--text)"><?= e(mb_strimwidth($a['title'], 0, 45, '…')) ?></a>
                        <?php if (!empty($a['publish_at']) && strtotime($a['publish_at']) > time()): ?>
                            <span class="badge">📅 geplant <?= date('d.m. H:i', strtotime($a['publish_at'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= date('d.m.Y', strtotime($a['created_at'])) ?></td>
                    <td>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button class="badge <?= $a['published'] ? 'badge-green' : 'badge-red' ?>" type="submit"><?= $a['published'] ? 'online' : 'Entwurf' ?></button>
                        </form>
                    </td>
                    <td class="table-actions">
                        <a class="btn btn-ghost btn-sm" href="?edit=<?= (int)$a['id'] ?>">Bearbeiten</a>
                        <form method="post" class="inline" data-confirm="Artikel wirklich löschen?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit">Löschen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$articles): ?><tr><td colspan="4" class="muted">Noch keine Artikel geschrieben.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
