<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin_role();
$adminCurrent = 'projects';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $title       = trim((string)($_POST['title'] ?? ''));
        $repo        = trim((string)($_POST['github_repo'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $order       = (int)($_POST['display_order'] ?? 0);
        $active      = isset($_POST['active']) ? 1 : 0;

        if ($repo !== '' && !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            flash('error', 'GitHub-Repo muss im Format „owner/repo“ angegeben werden.');
        } elseif ($id > 0) {
            db()->prepare('UPDATE projects SET title=?, github_repo=?, description=?, display_order=?, active=? WHERE id=?')
                ->execute([$title, $repo, $description, $order, $active, $id]);
            flash('success', 'Projekt aktualisiert.');
        } else {
            db()->prepare('INSERT INTO projects (title, github_repo, description, display_order, active) VALUES (?,?,?,?,?)')
                ->execute([$title, $repo, $description, $order, $active]);
            flash('success', 'Projekt hinzugefügt.');
        }
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM projects WHERE id = ?')->execute([(int)$_POST['id']]);
        flash('success', 'Projekt gelöscht.');
    }
    header('Location: projects.php');
    exit;
}

$projects = db()->query('SELECT * FROM projects ORDER BY display_order ASC, id DESC')->fetchAll();
$edit = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM projects WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

include __DIR__ . '/_header.php';
?>
<h1>Projekte</h1>
<p class="muted">GitHub-Repos im Format <code>owner/repo</code> eintragen – die Karten auf der Webseite füllen sich automatisch mit Live-Daten.</p>

<div class="grid grid-2">
    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <h3><?= $edit ? 'Projekt bearbeiten' : 'Neues Projekt' ?></h3>
        <label>Titel <input type="text" name="title" value="<?= e($edit['title'] ?? '') ?>" required></label>
        <label>GitHub-Repo (owner/repo) <input type="text" name="github_repo" class="mono" value="<?= e($edit['github_repo'] ?? '') ?>" placeholder="octocat/Hello-World" required></label>
        <label>Beschreibung (Fallback, wenn API nicht erreichbar) <textarea name="description" rows="3"><?= e($edit['description'] ?? '') ?></textarea></label>
        <label>Reihenfolge <input type="number" name="display_order" value="<?= (int)($edit['display_order'] ?? 0) ?>"></label>
        <label class="check"><input type="checkbox" name="active" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>> Auf Webseite anzeigen</label>
        <div>
            <button class="btn" type="submit"><?= $edit ? 'Speichern' : 'Hinzufügen' ?></button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="projects.php">Abbrechen</a><?php endif; ?>
        </div>
    </form>

    <div class="card glass">
        <h3>Vorhandene Projekte</h3>
        <table class="admin-table">
            <thead><tr><th>Titel</th><th>Repo</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($projects as $p): ?>
                <tr>
                    <td><?= e($p['title']) ?></td>
                    <td class="mono small"><?= e($p['github_repo']) ?></td>
                    <td><?= $p['active'] ? '<span class="badge badge-green">aktiv</span>' : '<span class="badge">versteckt</span>' ?></td>
                    <td class="table-actions">
                        <a class="btn btn-ghost btn-sm" href="?edit=<?= (int)$p['id'] ?>">Bearbeiten</a>
                        <form method="post" class="inline" data-confirm="Projekt wirklich löschen?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit">Löschen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$projects): ?><tr><td colspan="4" class="muted">Noch keine Projekte.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
