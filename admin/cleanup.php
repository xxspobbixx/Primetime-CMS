<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin_role();
$adminCurrent = 'cleanup';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $days = max(1, (int)($_POST['days'] ?? 90));

    if ($action === 'purge') {
        $what = (string)($_POST['what'] ?? '');
        $n = 0;
        if ($what === 'messages') {
            $n = db()->exec("DELETE FROM messages WHERE created_at < NOW() - INTERVAL $days DAY");
        } elseif ($what === 'chat') {
            $n = db()->exec("DELETE FROM chat_messages WHERE created_at < NOW() - INTERVAL $days DAY");
        } elseif ($what === 'notifications') {
            $n = db()->exec("DELETE FROM notifications WHERE created_at < NOW() - INTERVAL $days DAY");
        } elseif ($what === 'news_items_old') {
            // nichts - Platzhalter
        }
        mod_log('Bereinigung', $what . ' älter als ' . $days . ' Tage (' . $n . ' gelöscht)');
        flash('success', "$n Einträge gelöscht (älter als $days Tage).");
    } elseif ($action === 'delete_inactive') {
        // Mitglieder ohne Login seit X Tagen (anhand last_activity)
        $stmt = db()->query("SELECT id, username FROM users WHERE role = 'member' AND (last_activity IS NULL OR last_activity < NOW() - INTERVAL $days DAY) AND created_at < NOW() - INTERVAL $days DAY LIMIT 50");
        $users = $stmt->fetchAll();
        $count = 0;
        foreach ($users as $u) {
            delete_user_wall_posts((int)$u['id']);
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([(int)$u['id']]);
            $count++;
        }
        mod_log('Inaktive Konten gelöscht', $count . ' Konten (' . $days . ' Tage)');
        flash('success', "$count inaktive Konten gelöscht.");
    } elseif ($action === 'export_users') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mitglieder.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Benutzername', 'E-Mail', 'Rolle', 'Gruppe', 'Punkte', 'Registriert'], ';');
        foreach (db()->query('SELECT id, username, email, role, user_group, points, created_at FROM users ORDER BY id') as $row) {
            fputcsv($out, [$row['id'], $row['username'], $row['email'], $row['role'], $row['user_group'], $row['points'], $row['created_at']], ';');
        }
        fclose($out);
        exit;
    } elseif ($action === 'export_topics') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="forum-themen.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Titel', 'Tags', 'Kategorie-ID', 'Erstellt'], ';');
        foreach (db()->query('SELECT id, title, tags, category_id, created_at FROM forum_topics ORDER BY id') as $row) {
            fputcsv($out, [$row['id'], $row['title'], $row['tags'], $row['category_id'], $row['created_at']], ';');
        }
        fclose($out);
        exit;
    }
    header('Location: cleanup.php');
    exit;
}

$inactiveCount = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'member' AND (last_activity IS NULL OR last_activity < NOW() - INTERVAL 90 DAY) AND created_at < NOW() - INTERVAL 90 DAY")->fetchColumn();

include __DIR__ . '/_header.php';
?>
<h1>🧹 Bereinigung &amp; Export</h1>
<p class="muted">Alte Daten löschen, inaktive Konten aufräumen, Inhalte exportieren.</p>

<div class="grid grid-2">
    <div class="card glass form-stack">
        <h3>🗑️ Alte Daten löschen</h3>
        <form method="post" data-confirm="Wirklich löschen? Das kann nicht rückgängig gemacht werden!">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="purge">
            <label>Was löschen?
                <select name="what">
                    <option value="messages">Private Nachrichten</option>
                    <option value="chat">Chat-Nachrichten</option>
                    <option value="notifications">Benachrichtigungen</option>
                </select>
            </label>
            <label>Älter als (Tage) <input type="number" name="days" value="90" min="7" max="3650"></label>
            <button class="btn btn-danger btn-sm" type="submit">Löschen</button>
        </form>
    </div>

    <div class="card glass form-stack">
        <h3>😴 Inaktive Konten (<?= $inactiveCount ?>)</h3>
        <p class="muted small">Mitglieder ohne Aktivität seit X Tagen – werden inkl. aller Daten gelöscht (max. 50 pro Lauf).</p>
        <form method="post" data-confirm="Wirklich X inaktive Konten löschen?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_inactive">
            <label>Inaktiv seit (Tagen) <input type="number" name="days" value="90" min="30" max="3650"></label>
            <button class="btn btn-danger btn-sm" type="submit"><?= $inactiveCount ?> Konton löschen</button>
        </form>
    </div>

    <div class="card glass form-stack">
        <h3>📥 Export</h3>
        <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="export_users">
            <button class="btn btn-ghost btn-sm" type="submit">👥 Mitglieder (CSV)</button>
        </form>
        <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="export_topics">
            <button class="btn btn-ghost btn-sm" type="submit">🗂️ Forum-Themen (CSV)</button>
        </form>
    </div>

    <div class="card glass form-stack">
        <h3>🛡️ Team-Übersicht (heute aktiv)</h3>
        <?php
        $team = db()->query("SELECT username, role, last_activity, show_online FROM users WHERE role IN ('admin','moderator') ORDER BY role = 'moderator', username")->fetchAll();
        foreach ($team as $t):
            $online = $t['show_online'] && $t['last_activity'] && strtotime($t['last_activity']) > time() - 300;
        ?>
        <div class="friend-row">
            <div class="friend-info"><strong>@<?= e($t['username']) ?></strong>
                <span class="muted small"><?= role_badge($t['role']) ?></span></div>
            <span class="badge <?= $online ? 'badge-green' : '' ?>"><?= $online ? '🟢 online' : ($t['last_activity'] ? 'zuletzt ' . date('d.m. H:i', strtotime($t['last_activity'])) : '—') ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
