<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin_role();
$adminCurrent = 'fields';
$me = current_admin();

$types = ['text' => 'Text', 'url' => 'Link (URL)', 'number' => 'Zahl', 'date' => 'Datum'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $label   = trim((string)($_POST['label'] ?? ''));
        $type    = (string)($_POST['field_type'] ?? 'text');
        $order   = (int)($_POST['display_order'] ?? 0);
        $required = isset($_POST['required']) ? 1 : 0;
        $active  = isset($_POST['active']) ? 1 : 0;

        if ($label === '' || mb_strlen($label) > 100) {
            flash('error', 'Bitte eine Bezeichnung angeben (max. 100 Zeichen).');
        } elseif (!isset($types[$type])) {
            flash('error', 'Ungültiger Feldtyp.');
        } elseif ($id > 0) {
            db()->prepare('UPDATE profile_fields SET label=?, field_type=?, required=?, display_order=?, active=? WHERE id=?')
                ->execute([$label, $type, $required, $order, $active, $id]);
            flash('success', 'Feld aktualisiert.');
        } else {
            db()->prepare('INSERT INTO profile_fields (label, field_type, required, display_order, active) VALUES (?,?,?,?,?)')
                ->execute([$label, $type, $required, $order, $active]);
            flash('success', 'Feld angelegt.');
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        db()->prepare('DELETE FROM profile_values WHERE field_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM profile_fields WHERE id = ?')->execute([$id]);
        flash('success', 'Feld und alle gespeicherten Werte gelöscht.');
    }
    header('Location: fields.php');
    exit;
}

$fields = db()->query('SELECT f.*, (SELECT COUNT(*) FROM profile_values v WHERE v.field_id = f.id AND v.value != "") AS used
                       FROM profile_fields f ORDER BY f.display_order ASC, f.id ASC')->fetchAll();
$edit = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM profile_fields WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

include __DIR__ . '/_header.php';
?>
<h1>Profilfelder</h1>
<p class="muted">Eigene Felder definieren (z. B. „Steam-Name", „Lieblings-Genre") – Mitglieder füllen sie im Panel aus,
sichtbar sind sie auf der öffentlichen Profilseite.</p>

<div class="grid grid-2">
    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <h3><?= $edit ? 'Feld bearbeiten' : 'Neues Feld' ?></h3>
        <label>Bezeichnung <input type="text" name="label" value="<?= e($edit['label'] ?? '') ?>" placeholder="z. B. Steam-Name" required maxlength="100"></label>
        <label>Feldtyp
            <select name="field_type">
                <?php foreach ($types as $key => $label): ?>
                    <option value="<?= $key ?>" <?= ($edit['field_type'] ?? 'text') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Reihenfolge <input type="number" name="display_order" value="<?= (int)($edit['display_order'] ?? 0) ?>"></label>
        <label class="check"><input type="checkbox" name="required" <?= ($edit['required'] ?? 0) ? 'checked' : '' ?>> Pflichtfeld</label>
        <?php if ($edit): ?>
        <label class="check"><input type="checkbox" name="active" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>> Aktiv</label>
        <?php endif; ?>
        <div>
            <button class="btn" type="submit"><?= $edit ? 'Speichern' : 'Anlegen' ?></button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="fields.php">Abbrechen</a><?php endif; ?>
        </div>
    </form>

    <div class="card glass">
        <h3>Vorhandene Felder (<?= count($fields) ?>)</h3>
        <table class="admin-table">
            <thead><tr><th>Bezeichnung</th><th>Typ</th><th>Ausgefüllt</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($fields as $f): ?>
                <tr>
                    <td><?= e($f['label']) ?><?= $f['required'] ? ' <span class="badge badge-red">Pflicht</span>' : '' ?></td>
                    <td><span class="badge"><?= e($types[$f['field_type']] ?? $f['field_type']) ?></span></td>
                    <td><?= (int)$f['used'] ?>×</td>
                    <td><?= $f['active'] ? '<span class="badge badge-green">aktiv</span>' : '<span class="badge">aus</span>' ?></td>
                    <td class="table-actions">
                        <a class="btn btn-ghost btn-sm" href="?edit=<?= (int)$f['id'] ?>">Bearbeiten</a>
                        <form method="post" class="inline" data-confirm="Feld inkl. aller Werte wirklich löschen?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit">Löschen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$fields): ?><tr><td colspan="5" class="muted">Noch keine Felder angelegt.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
