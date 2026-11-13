<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin_role();
$adminCurrent = 'emailblock';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_setting') {
        set_setting('email_blocklist_active', isset($_POST['active']) ? '1' : '0');
        flash('success', 'Einstellung gespeichert.');
    } elseif ($action === 'add') {
        // Einzeln oder mehrere Zeilen (Bulk)
        $raw = trim((string)($_POST['domains'] ?? ''));
        $lines = preg_split('/[\s,;]+/u', $raw);
        $added = 0; $skipped = [];
        $stmt = db()->prepare('INSERT IGNORE INTO email_blocklist (domain) VALUES (?)');
        foreach ($lines as $line) {
            $domain = strtolower(trim($line));
            $domain = ltrim($domain, '@');
            if ($domain === '') continue;
            if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
                $skipped[] = $line;
                continue;
            }
            $stmt->execute([$domain]);
            $added += $stmt->rowCount();
        }
        flash('success', "$added Domain(s) hinzugefügt." . ($skipped ? ' Übersprungen: ' . implode(', ', array_slice($skipped, 0, 5)) : ''));
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM email_blocklist WHERE id = ?')->execute([(int)$_POST['id']]);
        flash('success', 'Domain entfernt.');
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE email_blocklist SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['id']]);
    } elseif ($action === 'clear') {
        db()->query('DELETE FROM email_blocklist');
        flash('success', 'Sperrliste geleert.');
    }
    header('Location: emailblock.php');
    exit;
}

$domains = db()->query('SELECT * FROM email_blocklist ORDER BY domain ASC')->fetchAll();
$active = setting('email_blocklist_active', '1') === '1';

include __DIR__ . '/_header.php';
?>
<h1>E-Mail-Sperrliste</h1>
<p class="muted">Registrierungen mit Wegwerf-E-Mail-Domains blockieren (z. B. tempmail.com). Die Prüfung erfolgt bei der Registrierung.</p>

<div class="card glass" style="margin-bottom:1.5rem">
    <form method="post" class="form-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="toggle_setting">
        <label class="check" style="margin:0">
            <input type="checkbox" name="active" <?= $active ? 'checked' : '' ?> onchange="this.form.submit()">
            Sperrliste bei Registrierung aktiv
        </label>
    </form>
</div>

<div class="grid grid-2">
    <div class="card glass form-stack">
        <h3>Domains hinzufügen</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <label>Eine oder mehrere Domains (durch Leerzeichen, Komma oder Zeilenumbruch getrennt)
                <textarea name="domains" rows="5" class="mono" placeholder="tempmail.com&#10;10minutemail.com&#10;wegwerf-email.de"></textarea>
            </label>
            <button class="btn" type="submit">Hinzufügen</button>
        </form>
    </div>

    <div class="card glass">
        <div class="card-head">
            <h3>Gesperrte Domains (<?= count($domains) ?>)</h3>
            <form method="post" class="inline" data-confirm="Wirklich die gesamte Liste leeren?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clear">
                <button class="btn btn-ghost btn-sm" type="submit">Liste leeren</button>
            </form>
        </div>
        <table class="admin-table">
            <thead><tr><th>Domain</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($domains as $d): ?>
                <tr>
                    <td class="mono"><?= e($d['domain']) ?></td>
                    <td>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                            <button class="badge <?= $d['active'] ? 'badge-green' : 'badge-red' ?>" type="submit"><?= $d['active'] ? 'gesperrt' : 'ausgesetzt' ?></button>
                        </form>
                    </td>
                    <td class="table-actions">
                        <form method="post" class="inline" data-confirm="Domain wirklich entfernen?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                            <button class="btn btn-danger btn-sm" type="submit">Löschen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$domains): ?><tr><td colspan="3" class="muted">Liste ist leer.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
