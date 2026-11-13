<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin_role();
$adminCurrent = 'mail';
$me = current_admin();

$sent = 0; $total = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    csrf_check();
    $subject = trim((string)($_POST['subject'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $target  = (string)($_POST['target'] ?? 'all');

    if ($subject === '' || $message === '') {
        flash('error', 'Betreff und Nachricht erforderlich.');
    } else {
        if ($target === 'role') {
            $stmt = db()->prepare('SELECT email, username FROM users WHERE role = ? AND email IS NOT NULL AND email != ""');
            $stmt->execute([(string)($_POST['role'] ?? 'member')]);
        } elseif ($target === 'group') {
            $stmt = db()->prepare('SELECT email, username FROM users WHERE user_group = ? AND email IS NOT NULL AND email != ""');
            $stmt->execute([(string)($_POST['group'] ?? 'vip')]);
        } else {
            $stmt = db()->query('SELECT email, username FROM users WHERE email IS NOT NULL AND email != ""');
        }
        $recipients = $stmt->fetchAll();
        $total = count($recipients);
        $from = 'From: ' . setting('site_name', 'Webseite') . ' <noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\nContent-Type: text/plain; charset=utf-8";
        foreach (array_slice($recipients, 0, 150) as $r) {
            $body = "Hallo " . $r['username'] . ",\n\n" . $message . "\n\n— " . setting('site_name', '') . "\n" . SITE_URL;
            if (@mail($r['email'], '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $from)) $sent++;
        }
        mod_log('Massen-E-Mail versendet', $subject . ' (' . $sent . '/' . $total . ')');
        flash($sent ? 'success' : 'error', "$sent von $total E-Mails versendet." . ($total > 150 ? ' (max. 150 pro Lauf)' : ''));
    }
    header('Location: mail.php');
    exit;
}

include __DIR__ . '/_header.php';
?>
<h1>📬 Massen-E-Mail</h1>
<p class="muted">An alle Mitglieder oder gefiltert nach Rolle/Gruppe. Max. 150 pro Lauf (Timeout-Schutz).</p>
<form method="post" class="card glass form-stack" style="max-width:640px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="send">
    <label>Empfänger
        <select name="target">
            <option value="all">Alle Mitglieder mit E-Mail</option>
            <option value="role">Nach Rolle…</option>
            <option value="group">Nach Benutzergruppe…</option>
        </select>
    </label>
    <div class="form-grid">
        <label>Rolle
            <select name="role"><?php foreach (role_list() as $k => $r): ?><option value="<?= e($k) ?>"><?= $r[0] ?> <?= e($r[1]) ?></option><?php endforeach; ?></select>
        </label>
        <label>Benutzergruppe
            <select name="group"><?php foreach (user_group_list() as $k => $g): ?><option value="<?= e($k) ?>"><?= $g[0] ?> <?= e($g[1]) ?></option><?php endforeach; ?></select>
        </label>
    </div>
    <label>Betreff <input type="text" name="subject" required></label>
    <label>Nachricht <textarea name="message" rows="8" required placeholder="Hallo {username}, …"></textarea></label>
    <p class="muted small">Hinweis: Der Begrüßungsteil mit dem Benutzernamen wird automatisch vorangestellt.</p>
    <button class="btn" type="submit" data-confirm-send>E-Mail senden</button>
</form>
<script>
document.querySelector('[data-confirm-send]').addEventListener('click', function (e) {
    if (!confirm('Wirklich an alle ausgewählten Empfänger senden?')) e.preventDefault();
});
</script>
<?php include __DIR__ . '/_footer.php'; ?>
