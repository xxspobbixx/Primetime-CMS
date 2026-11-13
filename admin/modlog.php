<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
$adminCurrent = 'modlog';

$entries = db()->query('SELECT * FROM mod_log ORDER BY id DESC LIMIT 100')->fetchAll();

include __DIR__ . '/_header.php';
?>
<h1>🛡️ Moderations-Log</h1>
<p class="muted">Wer hat wann was moderiert? Letzte 100 Einträge.</p>
<div class="card glass">
    <table class="admin-table">
        <thead><tr><th>Zeit</th><th>Moderator</th><th>Aktion</th><th>Ziel</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $e): ?>
        <tr>
            <td class="small"><?= date('d.m.Y H:i', strtotime($e['created_at'])) ?></td>
            <td class="small">@<?= e($e['mod_name']) ?></td>
            <td><span class="badge badge-red"><?= e($e['action']) ?></span></td>
            <td class="small"><?= e($e['target']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$entries): ?><tr><td colspan="4" class="muted">Noch keine Einträge.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
