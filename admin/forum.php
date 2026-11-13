<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin_role();
$adminCurrent = 'forum';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $parent = (int)($_POST['parent_id'] ?? 0);
        if ($name === '') { flash('error', 'Name fehlt.'); }
        else {
            // Eltern darf selbst kein Unterforum sein (max. Tiefe 2)
            if ($parent > 0) {
                $ps = db()->prepare('SELECT parent_id FROM forum_categories WHERE id = ?');
                $ps->execute([$parent]);
                if ((int)$ps->fetchColumn() > 0) $parent = 0;
            }
            db()->prepare('INSERT INTO forum_categories (name, description, parent_id) VALUES (?, ?, ?)')
                ->execute([$name, $desc, $parent > 0 ? $parent : null]);
            flash('success', 'Forum angelegt.');
        }
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE forum_categories SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['id']]);
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = db()->prepare('SELECT id FROM forum_topics WHERE category_id = ? LIMIT 1');
        $stmt->execute([$id]);
        if ($stmt->fetch()) {
            flash('error', 'Kategorie enthält noch Themen – bitte erst Themen löschen.');
        } else {
            db()->prepare('DELETE FROM forum_categories WHERE id = ?')->execute([$id]);
            flash('success', 'Kategorie gelöscht.');
        }
    }
    header('Location: forum.php');
    exit;
}

$cats = db()->query("SELECT c.*, (SELECT COUNT(*) FROM forum_topics t WHERE t.category_id = c.id) AS topic_count,
    (SELECT name FROM forum_categories p WHERE p.id = c.parent_id) AS parent_name
    FROM forum_categories c ORDER BY c.parent_id IS NOT NULL, c.parent_id, c.display_order, c.id")->fetchAll();
include __DIR__ . '/_header.php';
?>
<h1>🗂️ Forum-Verwaltung</h1>
<div class="card glass">
    <form method="post" class="form-inline" style="margin-bottom:1rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <select name="parent_id">
            <option value="0">Als Kategorie (oberste Ebene)</option>
            <?php foreach ($cats as $c): if ($c['parent_id']) continue; ?>
            <option value="<?= (int)$c['id'] ?>">Unterforum von: <?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="name" placeholder="Forum-Name" required>
        <input type="text" name="description" class="flex-grow" placeholder="Beschreibung (optional)">
        <button class="btn" type="submit">Anlegen</button>
    </form>
    <table class="admin-table">
        <thead><tr><th>Name</th><th>Beschreibung</th><th>Themen</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cats as $c): ?>
        <tr>
            <td><?= $c['parent_name'] ? '<span class="muted small">' . e($c['parent_name']) . ' →</span> ' : '' ?><?= e($c['name']) ?></td>
            <td class="small muted"><?= e($c['description']) ?></td>
            <td><?= (int)$c['topic_count'] ?></td>
            <td>
                <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <button class="badge <?= $c['active'] ? 'badge-green' : 'badge-red' ?>" type="submit"><?= $c['active'] ? 'aktiv' : 'aus' ?></button>
                </form>
            </td>
            <td class="table-actions">
                <form method="post" class="inline" data-confirm="Kategorie wirklich löschen?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit">Löschen</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
