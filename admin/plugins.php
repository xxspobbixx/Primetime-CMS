<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin_role();
$adminCurrent = 'plugins';

/* ---------- Backup-Download (vor jeder Ausgabe!) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup') {
    csrf_check();
    $pdo = db();
    $out = "-- VorstellungsCMS Backup vom " . date('Y-m-d H:i') . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch();
        $out .= "DROP TABLE IF EXISTS `$t`;\n" . $create['Create Table'] . ";\n\n";
        $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll();
        if ($rows) {
            $cols = array_keys($rows[0]);
            $out .= "INSERT INTO `$t` (`" . implode('`,`', $cols) . "`) VALUES\n";
            $vals = [];
            foreach ($rows as $r) {
                $vals[] = '(' . implode(',', array_map(function ($v) use ($pdo) {
                    return $v === null ? 'NULL' : $pdo->quote((string)$v);
                }, array_values($r))) . ')';
            }
            $out .= implode(",\n", $vals) . ";\n\n";
        }
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="backup-' . date('Ymd-His') . '.sql"');
    echo $out;
    exit;
}

// Upload-Limits für große Dateien hochsetzen (best effort, greift bei vielen Hostern)
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', '300');
@ini_set('memory_limit', '512M');

/* ---------- POST-Verarbeitung ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $plugin = (string)($_POST['plugin'] ?? '');

    $fields = [
        'maintenance'    => ['enabled' => 'bool', 'msg' => 'text'],
        'recaptcha'      => ['enabled' => 'bool', 'sitekey' => 'text', 'secret' => 'text'],
        'broadcast'      => ['enabled' => 'bool', 'msg' => 'text', 'speed' => 'text', 'color' => 'text'],
        'countdown'      => ['enabled' => 'bool', 'date' => 'text', 'label' => 'text'],
        'online_counter' => ['enabled' => 'bool'],
        'auto_backup'    => ['enabled' => 'bool', 'days' => 'text'],
        'snowfall'       => ['enabled' => 'bool', 'intensity' => 'text'],
        'discord'        => ['enabled' => 'bool', 'webhook' => 'text', 'widget_id' => 'text'],
        'backup'         => ['enabled' => 'bool'],
        'login_history'  => ['enabled' => 'bool'],
        'weekly_stats'   => ['enabled' => 'bool'],
        'wordfilter'     => ['enabled' => 'bool', 'words' => 'text'],
        'rate_limit'     => ['enabled' => 'bool', 'posts' => 'text', 'comments' => 'text', 'chat' => 'text'],
        'auto_mod'       => ['enabled' => 'bool', 'threshold' => 'text'],
        'referral'       => ['enabled' => 'bool', 'points' => 'text'],
        'snowfall'       => ['enabled' => 'bool', 'intensity' => 'text', 'type' => 'text'],
        'twitch_status'  => ['enabled' => 'bool', 'channel' => 'text'],
        'seo'            => ['enabled' => 'bool', 'description' => 'text', 'image' => 'text'],
        'visitor_stats'  => ['enabled' => 'bool'],
        'konfetti'       => ['enabled' => 'bool'],
        'darkmode_toggle'=> ['enabled' => 'bool'],
        'oauth'          => ['enabled' => 'bool', 'google_id' => 'text', 'google_secret' => 'text',
                             'github_id' => 'text', 'github_secret' => 'text',
                             'discord_id' => 'text', 'discord_secret' => 'text'],
        'custom_css'     => ['enabled' => 'bool', 'code' => 'text'],
    ];

    if ($action === 'plugin_save') {
        if (!isset($fields[$plugin])) {
            flash('error', 'Unbekanntes Plugin.');
        } else {
            foreach ($fields[$plugin] as $field => $type) {
                $key = 'plugin_' . $plugin . ($field === 'enabled' ? '' : '_' . $field);
                set_setting($key, $type === 'bool' ? (isset($_POST[$field]) ? '1' : '0') : trim((string)($_POST[$field] ?? '')));
            }
            flash('success', 'Plugin-Einstellungen gespeichert.');
        }
    } elseif ($action === 'resource_save') {
        $title = trim((string)($_POST['title'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        if ($title === '' || empty($_FILES['file']['name'])) {
            flash('error', 'Titel UND Datei sind Pflichtfelder.');
        } else {
            try {
                $f = save_resource_file($_FILES['file']);
                $img = null;
                if (!empty($_FILES['image']['name'])) {
                    try { $img = save_resource_image($_FILES['image']); }
                    catch (RuntimeException $ex) { /* Bild optional – Fehler ignorieren */ }
                }
                db()->prepare('INSERT INTO resources (title, description, file, original_name, image, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$title, $desc, $f['stored'], $f['original'], $img, (int)(current_admin()['id'] ?? 0)]);
                flash('success', 'Ressource „' . $title . '" hochgeladen.');
            } catch (RuntimeException $ex) {
                flash('error', $ex->getMessage());
            }
        }
    } elseif ($action === 'resource_toggle') {
        db()->prepare('UPDATE resources SET active = 1 - active WHERE id = ?')->execute([(int)$_POST['id']]);
    } elseif ($action === 'resource_delete') {
        $stmt = db()->prepare('SELECT * FROM resources WHERE id = ?');
        $stmt->execute([(int)$_POST['id']]);
        if ($r = $stmt->fetch()) {
            delete_resource_files($r);
            db()->prepare('DELETE FROM resources WHERE id = ?')->execute([(int)$r['id']]);
            flash('success', 'Ressource gelöscht.');
        }
    } elseif ($action === 'group_admin_delete') {
        $gid = (int)$_POST['id'];
        db()->prepare('DELETE FROM group_comments WHERE post_id IN (SELECT id FROM group_posts WHERE group_id = ?)')->execute([$gid]);
        $s = db()->prepare('SELECT image FROM group_posts WHERE group_id = ?');
        $s->execute([$gid]);
        foreach ($s->fetchAll() as $p) { if (!empty($p['image'])) delete_upload_file($p['image'], 'wall'); }
        db()->prepare('DELETE FROM group_posts WHERE group_id = ?')->execute([$gid]);
        db()->prepare('DELETE FROM group_members WHERE group_id = ?')->execute([$gid]);
        db()->prepare('DELETE FROM groups_tbl WHERE id = ?')->execute([$gid]);
        mod_log('Gruppe gelöscht (Admin)', 'Gruppe #' . $gid);
        flash('success', 'Gruppe gelöscht.');
    } elseif ($action === 'ip_add') {
        $ip = trim((string)($_POST['ip'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        $myIp = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            flash('error', 'Ungültige IP-Adresse.');
        } elseif ($ip === $myIp) {
            flash('error', 'Du kannst nicht deine eigene IP sperren! (Dein Kollege im selben Netz wäre dann auch gesperrt.)');
        } else {
            // Tabelle ggf. auto-erstellen (falls update.php nicht lief)
            try {
                db()->exec("CREATE TABLE IF NOT EXISTS ip_bans (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    ip VARCHAR(45) NOT NULL,
                    reason VARCHAR(255) NULL,
                    created_by VARCHAR(50) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Throwable $ignored) {}
            $adminName = (current_admin()['username'] ?? 'admin');
            db()->prepare('INSERT IGNORE INTO ip_bans (ip, reason, created_by) VALUES (?, ?, ?)')
                ->execute([$ip, $reason !== '' ? $reason : null, $adminName]);
            mod_log('IP gesperrt', $ip);
            flash('success', 'IP gesperrt: ' . $ip);
        }
    } elseif ($action === 'ip_delete') {
        db()->prepare('DELETE FROM ip_bans WHERE id = ?')->execute([(int)$_POST['id']]);
        mod_log('IP entsperrt', '#' . (int)$_POST['id']);
        flash('success', 'IP entsperrt.');
    } elseif ($action === 'discord_test') {
        $ok = discord_notify('✅ Webhook-Test',
            'Der Discord-Webhook von **' . setting('site_name', 'der Webseite') . '** funktioniert!',
            3066993);
        flash($ok ? 'success' : 'error',
            $ok ? 'Testnachricht gesendet – schau in deinen Discord-Server!' :
                  'Senden fehlgeschlagen. Bitte Webhook-URL prüfen – und ob dein Hoster cURL erlaubt.');
    } elseif ($action === 'plugin_pages') {
        foreach (['shoutbox', 'events', 'giveaway', 'contest', 'polls', 'resources', 'groups'] as $p) {
            set_setting('plugin_' . $p, isset($_POST[$p]) ? '1' : '0');
        }
        flash('success', 'Community-Seiten aktualisiert.');
    } elseif ($action === 'clear_log') {
        db()->query('DELETE FROM login_log');
        flash('success', 'Login-Historie geleert.');
    } elseif ($action === 'poll_create') {
        $q = trim((string)($_POST['question'] ?? ''));
        $opts = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($_POST['options'] ?? '')))));
        if ($q === '' || count($opts) < 2) {
            flash('error', 'Frage + mindestens 2 Antwortoptionen (eine pro Zeile) angeben.');
        } else {
            db()->query('UPDATE polls SET active = 0'); // nur eine aktive Umfrage
            $adminId = (int)(current_admin()['id'] ?? 0);
            db()->prepare('INSERT INTO polls (question, created_by) VALUES (?, ?)')->execute([$q, $adminId]);
            $pid = (int)db()->lastInsertId();
            $stmt = db()->prepare('INSERT INTO poll_options (poll_id, text, display_order) VALUES (?, ?, ?)');
            foreach (array_slice($opts, 0, 10) as $i => $opt) { $stmt->execute([$pid, mb_substr($opt, 0, 255), $i]); }
            flash('success', 'Umfrage erstellt und aktiviert.');
        }
    } elseif ($action === 'poll_toggle') {
        db()->query('UPDATE polls SET active = 0');
        db()->prepare('UPDATE polls SET active = 1 WHERE id = ?')->execute([(int)$_POST['id']]);
        flash('success', 'Umfrage aktiviert.');
    } elseif ($action === 'event_create') {
        $title = trim((string)($_POST['title'] ?? ''));
        $date = trim((string)($_POST['event_date'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        if ($title === '' || strtotime($date) === false) {
            flash('error', 'Titel und gültiges Datum angeben.');
        } else {
            $adminId = (int)(current_admin()['id'] ?? 0);
            db()->prepare('INSERT INTO events (title, event_date, description, created_by) VALUES (?, ?, ?, ?)')
                ->execute([$title, date('Y-m-d H:i:s', strtotime($date)), $desc, $adminId]);
            flash('success', 'Event erstellt.');
        }
    } elseif ($action === 'event_delete') {
        $id = (int)$_POST['id'];
        db()->prepare('DELETE FROM event_participants WHERE event_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
        flash('success', 'Event gelöscht.');
    } elseif ($action === 'giveaway_create') {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') { flash('error', 'Titel fehlt.'); }
        else {
            db()->prepare('INSERT INTO giveaways (title, created_by) VALUES (?, ?)')
                ->execute([$title, (int)(current_admin()['id'] ?? 0)]);
            flash('success', 'Gewinnspiel gestartet.');
        }
    } elseif ($action === 'giveaway_end') {
        $id = (int)$_POST['id'];
        $stmt = db()->prepare("SELECT user_id FROM giveaway_entries WHERE giveaway_id = ? ORDER BY RAND() LIMIT 1");
        $stmt->execute([$id]);
        $winner = (int)$stmt->fetchColumn();
        db()->prepare('UPDATE giveaways SET active = 0, winner_id = ? WHERE id = ?')->execute([$winner ?: null, $id]);
        if ($winner) {
            konfetti_for($winner);
            $wu = find_user_by_username(db()->query('SELECT username FROM users WHERE id = ' . $winner)->fetchColumn());
            discord_notify('🏆 Gewinnspiel beendet', 'Gewinner: **@' . ($wu['username'] ?? '?') . '**', 15844367);
            flash('success', 'Gewinner gezogen und per Konfetti benachrichtigt!');
        } else {
            flash('error', 'Keine Teilnehmer – Gewinnspiel nur geschlossen.');
        }
    } elseif ($action === 'contest_create') {
        $title = trim((string)($_POST['title'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        if ($title === '') { flash('error', 'Titel fehlt.'); }
        else {
            db()->query('UPDATE contests SET active = 0');
            db()->prepare('INSERT INTO contests (title, description) VALUES (?, ?)')->execute([$title, $desc]);
            flash('success', 'Wettbewerb erstellt & aktiviert.');
        }
    } elseif ($action === 'contest_end') {
        db()->query('UPDATE contests SET active = 0 WHERE id = ' . (int)$_POST['id']);
        flash('success', 'Wettbewerb beendet.');
    } elseif ($action === 'poll_delete') {
        $id = (int)$_POST['id'];
        db()->prepare('DELETE FROM poll_votes WHERE poll_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM poll_options WHERE poll_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM polls WHERE id = ?')->execute([$id]);
        flash('success', 'Umfrage gelöscht.');
    }
    header('Location: plugins.php');
    exit;
}

$v = fn(string $k) => e(setting($k));
$isOn = fn(string $k) => setting($k) === '1';

$loginLog = plugin_active('login_history')
    ? db()->query('SELECT * FROM login_log ORDER BY id DESC LIMIT 15')->fetchAll()
    : [];

// Daten für Verwaltungs-Sektionen
$adminEvents = db()->query("SELECT e.*, (SELECT COUNT(*) FROM event_participants p WHERE p.event_id = e.id) AS participants FROM events e ORDER BY e.event_date ASC LIMIT 5")->fetchAll();
$adminGiveaways = db()->query("SELECT g.*, (SELECT COUNT(*) FROM giveaway_entries e WHERE e.giveaway_id = g.id) AS entries FROM giveaways g ORDER BY g.id DESC LIMIT 5")->fetchAll();
$adminContests = db()->query('SELECT * FROM contests ORDER BY id DESC LIMIT 5')->fetchAll();
try {
    try {
    $ipBans = db()->query('SELECT * FROM ip_bans ORDER BY id DESC LIMIT 20')->fetchAll();
} catch (Throwable $e) {
    $ipBans = []; // Tabelle fehlt -> wird beim ersten Sperren auto-erstellt
}
} catch (Throwable $e) {
    $ipBans = []; // Tabelle fehlt -> wird beim ersten Sperren auto-erstellt
}
$adminResources = db()->query('SELECT * FROM resources ORDER BY id DESC LIMIT 20')->fetchAll();
$adminGroups = db()->query("SELECT g.*, u.username AS creator,
    (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id AND m.status = 'approved') AS members
    FROM groups_tbl g JOIN users u ON u.id = g.creator_id ORDER BY g.id DESC LIMIT 30")->fetchAll();

// Mitglieder-Wachstum (letzte 12 Wochen) für den Chart
$growth = [];
if (plugin_active('weekly_stats')) {
    foreach (db()->query("SELECT YEARWEEK(created_at, 3) AS yw, COUNT(*) AS c FROM users WHERE role = 'member' AND created_at > NOW() - INTERVAL 12 WEEK GROUP BY yw ORDER BY yw") as $gr) {
        $growth[] = $gr;
    }
    if (!$growth) $growth = [['yw' => date('oW'), 'c' => 0]];
    $maxGrowth = max(1, max(array_column($growth, 'c')));
}
// Content-Berichte: Top-Beiträge & Kommentatoren
$topPosts = db()->query("SELECT w.id, LEFT(w.content, 50) AS snippet,
    (SELECT COUNT(*) FROM wall_reactions r WHERE r.post_id = w.id) AS likes
    FROM wall_posts w ORDER BY likes DESC LIMIT 5")->fetchAll();
$topCommenters = db()->query("SELECT u.username, u.display_name, COUNT(*) AS c
    FROM wall_comments wc JOIN users u ON u.id = wc.user_id
    GROUP BY wc.user_id ORDER BY c DESC LIMIT 5")->fetchAll();
$polls = db()->query('SELECT p.*, (SELECT COUNT(*) FROM poll_votes v WHERE v.poll_id = p.id) AS votes FROM polls p ORDER BY p.id DESC LIMIT 5')->fetchAll();

include __DIR__ . '/_header.php';
?>
<h1>🧩 Plugins</h1>
<p class="muted">Optionale Zusatzfunktionen – aktivieren, konfigurieren, fertig.</p>

<!-- Zeile 1 -->
<div class="grid grid-2">

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="maintenance">
        <div class="card-head"><h3>🔧 Wartungsmodus</h3><span class="badge <?= $isOn('plugin_maintenance') ? 'badge-green' : '' ?>"><?= $isOn('plugin_maintenance') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Sperrt die Seite für Gäste &amp; Mitglieder – nur eingeloggte Admins können surfen.</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_maintenance') ? 'checked' : '' ?>> Aktivieren</label>
        <label>Hinweistext <input type="text" name="msg" value="<?= $v('plugin_maintenance_msg') ?>" placeholder="Wir sind bald wieder da!"></label>
        <label>Geplante Aktivierung (optional – ab dann automatisch an)
            <input type="datetime-local" name="start" value="<?= $v('plugin_maintenance_start') ?>">
        </label>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="recaptcha">
        <div class="card-head"><h3>🛡️ reCAPTCHA</h3><span class="badge <?= $isOn('plugin_recaptcha') ? 'badge-green' : '' ?>"><?= $isOn('plugin_recaptcha') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Bot-Schutz für die Registrierung. Keys: <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">google.com/recaptcha</a> (Typ „v2 Checkbox").</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_recaptcha') ? 'checked' : '' ?>> Bei Registrierung aktivieren</label>
        <label>Site-Key <input type="text" name="sitekey" class="mono" value="<?= $v('plugin_recaptcha_sitekey') ?>"></label>
        <label>Secret-Key <input type="password" name="secret" class="mono" value="<?= $v('plugin_recaptcha_secret') ?>"></label>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="broadcast">
        <div class="card-head"><h3>📢 Broadcast-Lauftext</h3><span class="badge <?= $isOn('plugin_broadcast') ? 'badge-green' : '' ?>"><?= $isOn('plugin_broadcast') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Lauftext unter dem Header. Mehrere Nachrichten: <strong>eine pro Zeile</strong>.</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_broadcast') ? 'checked' : '' ?>> Anzeigen</label>
        <label>Nachrichten <textarea name="msg" rows="3" placeholder="🎉 Stream heute 19 Uhr!&#10;🎃 Halloween-Event am 31.10."><?= $v('plugin_broadcast_msg') ?></textarea></label>
        <div class="form-grid">
            <label>Geschwindigkeit (5–120 s) <input type="number" name="speed" min="5" max="120" value="<?= e(setting('plugin_broadcast_speed', '22')) ?>"></label>
            <label>Farbe
                <select name="color">
                    <?php $cur = setting('plugin_broadcast_color', 'accent');
                    foreach (['accent' => 'Theme-Akzente', 'red' => '🔴 Rot', 'green' => '🟢 Grün', 'blue' => '🔵 Blau', 'orange' => '🟠 Orange', 'gold' => '🟡 Gold', 'dark' => '⚫ Dunkel'] as $k => $lbl): ?>
                        <option value="<?= e($k) ?>" <?= $cur === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="countdown">
        <div class="card-head"><h3>⏳ Countdown</h3><span class="badge <?= $isOn('plugin_countdown') ? 'badge-green' : '' ?>"><?= $isOn('plugin_countdown') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Live-Countdown unter dem Header – für Streams &amp; Events.</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_countdown') ? 'checked' : '' ?>> Anzeigen</label>
        <label>Ziel-Datum <input type="datetime-local" name="date" value="<?= $v('plugin_countdown_date') ?>"></label>
        <label>Beschriftung <input type="text" name="label" value="<?= $v('plugin_countdown_label') ?>" placeholder="Stream startet in"></label>
        <button class="btn" type="submit">Speichern</button>
    </form>
</div>

<!-- Zeile 2: Saison & Community -->
<h3 style="margin:1.8rem 0 1rem">🎉 Community &amp; Deko</h3>
<div class="grid grid-2">

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="snowfall">
        <div class="card-head"><h3>❄️ Schneefall</h3><span class="badge <?= $isOn('plugin_snowfall') ? 'badge-green' : '' ?>"><?= $isOn('plugin_snowfall') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Sanfte Schneeflocken über der gesamten Seite – perfekt zum Weihnachts-Theme.</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_snowfall') ? 'checked' : '' ?>> Schneefall aktivieren</label>
        <div class="form-grid">
            <label>Intensität
                <select name="intensity">
                    <?php $cur = setting('plugin_snowfall_intensity', 'mittel');
                    foreach (['leicht' => '❄️ Leicht', 'mittel' => '❄️❄️ Mittel', 'stark' => '❄️❄️❄️ Stark'] as $k => $lbl): ?>
                        <option value="<?= e($k) ?>" <?= $cur === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Effekt
                <select name="type">
                    <?php $curT = setting('plugin_snowfall_type', 'schnee');
                    foreach (['schnee' => '❄️ Schnee', 'blaetter' => '🍂 Herbstblätter', 'blueten' => '🌸 Blüten'] as $k => $lbl): ?>
                        <option value="<?= e($k) ?>" <?= $curT === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="discord">
        <div class="card-head"><h3>💬 Discord-Webhook</h3><span class="badge <?= $isOn('plugin_discord') ? 'badge-green' : '' ?>"><?= $isOn('plugin_discord') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Postet automatisch in deinen Discord-Server: neue Mitglieder &amp; Meldungen. Webhook-URL: Discord → Kanal → Einstellungen → Integrationen.</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_discord') ? 'checked' : '' ?>> Webhook aktivieren</label>
        <label>Webhook-URL <input type="url" name="webhook" class="mono" value="<?= $v('plugin_discord_webhook') ?>" placeholder="https://discord.com/api/webhooks/..."></label>
        <label>Server-ID für Widget <input type="text" name="widget_id" class="mono" value="<?= $v('plugin_discord_widget_id') ?>" placeholder="123456789012345678"></label>
        <p class="muted small">Widget zeigt „💬 Discord: X online" in der Fußzeile. Voraussetzung: Server-Einstellungen → Widget → <strong>aktiviert</strong>.</p>
        <div>
            <button class="btn" type="submit">Speichern</button>
        </div>
    </form>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="discord_test">
        <button class="btn btn-ghost btn-sm" type="submit">📤 Testnachricht an Discord senden</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="auto_backup">
        <div class="card-head"><h3>💾 Auto-Backup</h3><span class="badge <?= $isOn('plugin_auto_backup') ? 'badge-green' : '' ?>"><?= $isOn('plugin_auto_backup') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Erstellt automatisch ein SQL-Backup beim Admin-Besuch (sofern das Intervall erreicht ist). Backups liegen in <code>/backups</code> (max. 5 werden aufbewahrt).</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_auto_backup') ? 'checked' : '' ?>> Automatisches Backup aktivieren</label>
        <label>Intervall (Tage) <input type="number" name="days" min="1" max="30" value="<?= e(setting('plugin_auto_backup_days', '7')) ?>"></label>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="online_counter">
        <div class="card-head"><h3>🟢 Online-Zähler</h3><span class="badge <?= $isOn('plugin_online_counter') ? 'badge-green' : '' ?>"><?= $isOn('plugin_online_counter') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">„🟢 X Mitglieder online" in der Fußzeile.</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_online_counter') ? 'checked' : '' ?>> Anzeigen</label>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="weekly_stats">
        <div class="card-head"><h3>📊 Wochen-Statistik</h3><span class="badge <?= $isOn('plugin_weekly_stats') ? 'badge-green' : '' ?>"><?= $isOn('plugin_weekly_stats') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Zeigt auf dem Dashboard eine Aktivitäts-Übersicht der letzten 7 Tage.</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_weekly_stats') ? 'checked' : '' ?>> Statistik-Karte auf dem Dashboard</label>
        <button class="btn" type="submit">Speichern</button>
    </form>
</div>

<!-- Zeile 3: Tools -->
<h3 style="margin:1.8rem 0 1rem">🛠️ Tools &amp; Sicherheit</h3>
<div class="grid grid-2">

    <div class="card glass form-stack">
        <div class="card-head"><h3>💾 Backup</h3><span class="badge <?= $isOn('plugin_backup') ? 'badge-green' : '' ?>"><?= $isOn('plugin_backup') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Erstellt einen kompletten SQL-Export aller Tabellen (Daten + Struktur) als Download.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="plugin_save">
            <input type="hidden" name="plugin" value="backup">
            <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_backup') ? 'checked' : '' ?>> Backup-Funktion sichtbar machen</label>
            <button class="btn" type="submit">Speichern</button>
        </form>
        <?php if ($isOn('plugin_backup')): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="backup">
            <button class="btn" type="submit">📥 Backup jetzt herunterladen (.sql)</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card glass form-stack">
        <div class="card-head"><h3>🔐 Login-Historie</h3><span class="badge <?= $isOn('plugin_login_history') ? 'badge-green' : '' ?>"><?= $isOn('plugin_login_history') ? 'aktiv' : 'aus' ?></span></div>
        <form method="post" style="margin-bottom:1rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="plugin_save">
            <input type="hidden" name="plugin" value="login_history">
            <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_login_history') ? 'checked' : '' ?>> Logins aufzeichnen (Mitglieder &amp; Admins)</label>
            <button class="btn btn-sm" type="submit">Speichern</button>
        </form>
        <?php if (plugin_active('login_history')): ?>
            <?php if ($loginLog): ?>
            <table class="admin-table">
                <thead><tr><th>Zeit</th><th>Benutzer</th><th>Bereich</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($loginLog as $ll): ?>
                <tr>
                    <td class="small"><?= date('d.m. H:i', strtotime($ll['created_at'])) ?></td>
                    <td class="small"><?= e($ll['username']) ?></td>
                    <td><span class="badge <?= $ll['scope'] === 'admin' ? 'badge-red' : '' ?>"><?= e($ll['scope']) ?></span></td>
                    <td class="small mono"><?= e($ll['ip']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" class="inline" data-confirm="Historie wirklich leeren?" style="margin-top:.8rem">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clear_log">
                <button class="btn btn-ghost btn-sm" type="submit">Historie leeren</button>
            </form>
            <?php else: ?><p class="muted small">Noch keine Einträge.</p><?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Community-Werkzeuge -->
<h3 style="margin:1.8rem 0 1rem">🛡️ Moderation &amp; Wachstum</h3>
<div class="grid grid-2">

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="wordfilter">
        <div class="card-head"><h3>🤐 Wortfilter</h3><span class="badge <?= $isOn('plugin_wordfilter') ? 'badge-green' : '' ?>"><?= $isOn('plugin_wordfilter') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Wörter werden in Wall, Chat, Forum &amp; Shoutbox automatisch zensiert.</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_wordfilter') ? 'checked' : '' ?>> Filter aktivieren</label>
        <label>Blockierte Wörter (Komma oder Zeile getrennt)
            <textarea name="words" rows="3" class="mono" placeholder="schimpfwort1, schimpfwort2"><?= $v('plugin_wordfilter_words') ?></textarea>
        </label>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="rate_limit">
        <div class="card-head"><h3>⏱️ Rate-Limiting</h3><span class="badge <?= $isOn('plugin_rate_limit') ? 'badge-green' : '' ?>"><?= $isOn('plugin_rate_limit') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Spam-Schutz: max. Beiträge pro Minute pro Mitglied.</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_rate_limit') ? 'checked' : '' ?>> Limits aktivieren</label>
        <div class="form-grid">
            <label>Wall-Posts/Min <input type="number" name="posts" min="1" max="30" value="<?= e(setting('plugin_rate_limit_posts', '5')) ?>"></label>
            <label>Kommentare/Min <input type="number" name="comments" min="1" max="30" value="<?= e(setting('plugin_rate_limit_comments', '8')) ?>"></label>
            <label>Chat/Shoutbox/Min <input type="number" name="chat" min="1" max="30" value="<?= e(setting('plugin_rate_limit_chat', '10')) ?>"></label>
        </div>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="auto_mod">
        <div class="card-head"><h3>🛡️ Auto-Moderation</h3><span class="badge <?= $isOn('plugin_auto_mod') ? 'badge-green' : '' ?>"><?= $isOn('plugin_auto_mod') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Beiträge werden nach X Meldungen automatisch ausgeblendet (bis ein Mod eingreift).</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_auto_mod') ? 'checked' : '' ?>> Auto-Moderation aktivieren</label>
        <label>Schwelle (Meldungen) <input type="number" name="threshold" min="2" max="10" value="<?= e(setting('plugin_auto_mod_threshold', '3')) ?>"></label>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="referral">
        <div class="card-head"><h3>🤝 Empfehlungs-System</h3><span class="badge <?= $isOn('plugin_referral') ? 'badge-green' : '' ?>"><?= $isOn('plugin_referral') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Mitglieder werben Mitglieder: <code>?ref=Benutzername</code>-Links mit Punkt-Bonus.</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_referral') ? 'checked' : '' ?>> Empfehlungen aktivieren</label>
        <label>Bonus pro Werbung <input type="number" name="points" min="0" max="500" value="<?= e(setting('plugin_referral_points', '25')) ?>"></label>
        <button class="btn" type="submit">Speichern</button>
    </form>
</div>

<h3 style="margin:1.8rem 0 1rem">🌐 Sichtbarkeit &amp; Deko</h3>
<div class="grid grid-2">

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="twitch_status">
        <div class="card-head"><h3>🔴 Twitch-Live</h3><span class="badge <?= $isOn('plugin_twitch_status') ? 'badge-green' : '' ?>"><?= $isOn('plugin_twitch_status') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Zeigt eine rote „LIVE"-Leiste, wenn du streamst (manuelle Schaltung).</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_twitch_status') ? 'checked' : '' ?>> Live-Leiste anzeigen</label>
        <label>Twitch-Kanal <input type="text" name="channel" class="mono" value="<?= $v('plugin_twitch_channel') ?>" placeholder="deinkanal"></label>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="seo">
        <div class="card-head"><h3>🔍 SEO</h3><span class="badge <?= $isOn('plugin_seo') ? 'badge-green' : '' ?>"><?= $isOn('plugin_seo') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Meta-Beschreibung &amp; OpenGraph für Google/Discord-Vorschauen. RSS-Feed: <code>/rss.php</code></p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_seo') ? 'checked' : '' ?>> Meta-Tags ausgeben</label>
        <label>Beschreibung <input type="text" name="description" value="<?= $v('plugin_seo_description') ?>" maxlength="160"></label>
        <label>OG-Bild-URL (optional) <input type="url" name="image" value="<?= $v('plugin_seo_image') ?>"></label>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="konfetti">
        <div class="card-head"><h3>🎊 Konfetti</h3><span class="badge <?= $isOn('plugin_konfetti') ? 'badge-green' : '' ?>"><?= $isOn('plugin_konfetti') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Feuert bei Registrierung, Tagesbonus und Gewinnspiel-Gewinn.</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_konfetti') ? 'checked' : '' ?>> Konfetti aktivieren</label>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="visitor_stats">
        <div class="card-head"><h3>👀 Besucher-Statistik</h3><span class="badge <?= $isOn('plugin_visitor_stats') ? 'badge-green' : '' ?>"><?= $isOn('plugin_visitor_stats') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Zählt Seitenaufrufe (DSGVO-konform, keine IPs).</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_visitor_stats') ? 'checked' : '' ?>> Aufrufe zählen</label>
        <?php if ($isOn('plugin_visitor_stats')):
            $pvToday = (int)db()->query('SELECT COUNT(*) FROM page_views WHERE created_at > CURDATE()')->fetchColumn();
            $pvWeek  = (int)db()->query('SELECT COUNT(*) FROM page_views WHERE created_at > NOW() - INTERVAL 7 DAY')->fetchColumn(); ?>
            <p class="small">Heute: <strong><?= $pvToday ?></strong> · 7 Tage: <strong><?= $pvWeek ?></strong></p>
        <?php endif; ?>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="darkmode_toggle">
        <div class="card-head"><h3>🌓 Dark/Light-Umschalter</h3><span class="badge <?= $isOn('plugin_darkmode_toggle') ? 'badge-green' : '' ?>"><?= $isOn('plugin_darkmode_toggle') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Schwebender Button, mit dem Besucher selbst zwischen Hell/Dunkel wechseln (wird gespeichert).</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_darkmode_toggle') ? 'checked' : '' ?>> Umschalter anzeigen</label>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack" style="grid-column:1/-1">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="custom_css">
        <div class="card-head"><h3>🎨 Custom-CSS</h3><span class="badge <?= $isOn('plugin_custom_css') ? 'badge-green' : '' ?>"><?= $isOn('plugin_custom_css') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Eigenes CSS, das auf allen Seiten geladen wird (überschreibt Theme-Regeln).</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_custom_css') ? 'checked' : '' ?>> Eigenes CSS laden</label>
        <label>CSS-Code <textarea name="code" rows="6" class="mono" placeholder=".card { border-radius: 4px; }"><?= e(setting('plugin_custom_css_code')) ?></textarea></label>
        <button class="btn" type="submit">Speichern</button>
    </form>
</div>

<!-- Content-Verwaltung -->
<h3 style="margin:1.8rem 0 1rem">👥 Gruppen verwalten</h3>
<div class="card glass" style="margin-bottom:1.5rem">
    <p class="muted small" style="padding:0 0 .8rem">Alle Gruppen der Community – löschen bei Verstößen. Mitglieder verwalten sich über die Gruppen-Seite selbst.</p>
    <table class="admin-table">
        <thead><tr><th>Gruppe</th><th>Sichtbarkeit</th><th>Mitglieder</th><th>Gründer</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($adminGroups as $g): ?>
        <tr>
            <td><a href="../gruppe.php?id=<?= (int)$g['id'] ?>" target="_blank" style="color:var(--text)"><?= e($g['name']) ?></a></td>
            <td><span class="badge"><?= $g['privacy'] === 'private' ? '🔒 privat' : '🌍 öffentlich' ?></span></td>
            <td><?= (int)$g['members'] ?></td>
            <td class="small">@<?= e($g['creator']) ?></td>
            <td class="table-actions">
                <form method="post" class="inline" data-confirm="Gruppe wirklich löschen? Alle Beiträge gehen verloren.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="group_admin_delete">
                    <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit">Löschen</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$adminGroups): ?><tr><td colspan="5" class="muted">Noch keine Gruppen vorhanden.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<h3 style="margin:1.8rem 0 1rem">🎪 Events, Gewinnspiele &amp; Wettbewerbe</h3>
<div class="grid grid-2">

    <div class="card glass form-stack">
        <h3>📅 Events</h3>
        <form method="post" class="form-stack" style="margin-bottom:1rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="event_create">
            <input type="text" name="title" placeholder="Event-Titel" required>
            <input type="datetime-local" name="event_date" required>
            <input type="text" name="description" placeholder="Beschreibung (optional)">
            <button class="btn btn-sm" type="submit">Event erstellen</button>
        </form>
        <?php foreach ($adminEvents as $ev): ?>
        <div class="friend-row">
            <div class="friend-info"><strong><?= e(mb_strimwidth($ev['title'], 0, 30, '…')) ?></strong>
                <span class="muted small"><?= date('d.m.Y H:i', strtotime($ev['event_date'])) ?> · <?= (int)$ev['participants'] ?> Teilnehmer</span></div>
            <form method="post" class="inline" data-confirm="Event löschen?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="event_delete">
                <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">Löschen</button>
            </form>
        </div>
        <?php endforeach; ?>
        <?php if (!$adminEvents): ?><p class="muted small">Keine Events.</p><?php endif; ?>
    </div>

    <div class="card glass form-stack">
        <h3>🎁 Gewinnspiele</h3>
        <form method="post" class="form-inline" style="margin-bottom:1rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="giveaway_create">
            <input type="text" name="title" class="flex-grow" placeholder="Gewinnspiel-Titel" required>
            <button class="btn btn-sm" type="submit">Starten</button>
        </form>
        <?php foreach ($adminGiveaways as $g): ?>
        <div class="friend-row">
            <div class="friend-info"><strong><?= e(mb_strimwidth($g['title'], 0, 30, '…')) ?></strong>
                <span class="muted small"><?= (int)$g['entries'] ?> Teilnehmer <?= $g['winner_id'] ? '· 🏆 ' . e(db()->query('SELECT username FROM users WHERE id = ' . (int)$g['winner_id'])->fetchColumn() ?? '?') : '' ?></span></div>
            <?php if ($g['active']): ?>
            <form method="post" class="inline" data-confirm="Gewinnspiel beenden & Gewinner ziehen?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="giveaway_end">
                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                <button class="btn btn-sm" type="submit">🏆 Gewinner ziehen</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$adminGiveaways): ?><p class="muted small">Keine Gewinnspiele.</p><?php endif; ?>
    </div>

    <div class="card glass form-stack">
        <h3>🏆 Wettbewerbe</h3>
        <form method="post" class="form-stack" style="margin-bottom:1rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="contest_create">
            <input type="text" name="title" placeholder="Wettbewerbs-Titel" required>
            <input type="text" name="description" placeholder="Beschreibung / Thema">
            <button class="btn btn-sm" type="submit">Erstellen &amp; aktivieren</button>
        </form>
        <?php foreach ($adminContests as $ct): ?>
        <div class="friend-row">
            <div class="friend-info"><strong><?= e(mb_strimwidth($ct['title'], 0, 30, '…')) ?></strong>
                <?= $ct['active'] ? '<span class="badge badge-green">aktiv</span>' : '' ?></div>
            <?php if ($ct['active']): ?>
            <form method="post" class="inline" data-confirm="Wettbewerb beenden?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="contest_end">
                <input type="hidden" name="id" value="<?= (int)$ct['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Beenden</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$adminContests): ?><p class="muted small">Keine Wettbewerbe.</p><?php endif; ?>
    </div>

    <div class="card glass form-stack">
        <h3>📈 Wachstum &amp; Content-Berichte</h3>
        <?php if ($growth): ?>
        <p class="muted small">Mitglieder-Registrierungen (12 Wochen)</p>
        <div class="growth-chart">
            <?php foreach ($growth as $gr): $h = max(4, (int)round($gr['c'] / $maxGrowth * 100)); ?>
            <div class="growth-col" title="<?= (int)$gr['c'] ?> neue Mitglieder">
                <div class="growth-bar" style="height:<?= $h ?>%"></div>
                <span class="growth-label"><?= substr($gr['yw'], -2) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="grid grid-2" style="margin-top:1rem;gap:1rem">
            <div>
                <p class="muted small">❤️ Beliebteste Beiträge</p>
                <?php foreach ($topPosts as $tp): ?>
                <div class="small">„<?= e($tp['snippet']) ?>…" – <?= (int)$tp['likes'] ?> Likes</div>
                <?php endforeach; ?>
            </div>
            <div>
                <p class="muted small">💬 Top-Kommentatoren</p>
                <?php foreach ($topCommenters as $tc): ?>
                <div class="small"><?= e($tc['display_name'] ?: $tc['username']) ?> – <?= (int)$tc['c'] ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<form method="post" class="card glass form-stack" style="grid-column:1/-1">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="oauth">
        <div class="card-head"><h3>🔑 Social Login (Google, GitHub, Discord)</h3>
            <span class="badge <?= $isOn('plugin_oauth') ? 'badge-green' : '' ?>"><?= $isOn('plugin_oauth') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Mitglieder können sich über Google, GitHub oder Discord registrieren &amp; einloggen –
        <strong>kein Passwort nötig</strong>. Neue Kontos werden automatisch angelegt (oder per E-Mail mit bestehendem Konto verknüpft).</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_oauth') ? 'checked' : '' ?>> Social Login aktivieren</label>
        <div class="form-grid">
            <label>Google Client-ID <input type="text" name="google_id" class="mono" value="<?= $v('plugin_oauth_google_id') ?>"></label>
            <label>Google Client-Secret <input type="password" name="google_secret" class="mono" value="<?= $v('plugin_oauth_google_secret') ?>"></label>
            <label>GitHub Client-ID <input type="text" name="github_id" class="mono" value="<?= $v('plugin_oauth_github_id') ?>"></label>
            <label>GitHub Client-Secret <input type="password" name="github_secret" class="mono" value="<?= $v('plugin_oauth_github_secret') ?>"></label>
            <label>Discord Client-ID <input type="text" name="discord_id" class="mono" value="<?= $v('plugin_oauth_discord_id') ?>"></label>
            <label>Discord Client-Secret <input type="password" name="discord_secret" class="mono" value="<?= $v('plugin_oauth_discord_secret') ?>"></label>
        </div>
        <div class="flash flash-success" style="font-size:.85rem">
            <strong>Callback-URL</strong> (bei allen Anbietern identisch eintragen):<br>
            <code style="word-break:break-all"><?= e(SITE_URL) ?>/oauth-callback.php</code>
        </div>
        <p class="muted small">Schlüssel erhältst du kostenlos: Google → Google Cloud Console (OAuth-Client) · GitHub → Settings → Developer Settings → OAuth Apps · Discord → Developer Portal → OAuth2.</p>
        <button class="btn" type="submit">Speichern</button>
        </form>

    <div class="card glass form-stack">
        <h3>⛔ IP-Sperren (<?= count($ipBans ?? []) ?> aktiv)</h3>
        <form method="post" class="form-inline" style="margin-bottom:1rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ip_add">
            <input type="text" name="ip" class="mono" placeholder="z. B. 203.0.113.42" required>
            <input type="text" name="reason" class="flex-grow" placeholder="Grund (optional)">
            <button class="btn btn-sm" type="submit">Sperren</button>
        </form>
        <?php foreach ($ipBans as $b): ?>
        <div class="friend-row">
            <div class="friend-info"><code><?= e($b['ip']) ?></code>
                <span class="muted small"><?= e($b['reason'] ?? '') ?> · von <?= e($b['created_by'] ?? '?') ?></span></div>
            <form method="post" class="inline" data-confirm="IP entsperren?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="ip_delete">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Entsperren</button>
            </form>
        </div>
        <?php endforeach; ?>
        <?php if (!$ipBans): ?><p class="muted small">Keine gesperrten IPs.</p><?php endif; ?>
        <p class="muted small">⚠️ Eingeloggte Admins werden nie blockiert (Notfall-Zugang).</p>
    </div>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="push">
        <div class="card-head"><h3>🔔 Push-Benachrichtigungen</h3>
            <span class="badge <?= $isOn('plugin_push') ? 'badge-green' : '' ?>"><?= $isOn('plugin_push') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Echte Push-Nachrichten aufs Handy – auch wenn die Seite geschlossen ist. Kostenlos via <a href="https://onesignal.com" target="_blank" rel="noopener">OneSignal</a>.</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_push') ? 'checked' : '' ?>> Push aktivieren</label>
        <label>OneSignal App-ID <input type="text" name="app_id" class="mono" value="<?= $v('plugin_push_app_id') ?>"></label>
        <label>REST API Key <input type="password" name="rest_key" class="mono" value="<?= $v('plugin_push_rest_key') ?>"></label>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="stats_widget">
        <div class="card-head"><h3>📊 Statistik-Widget</h3>
            <span class="badge <?= $isOn('plugin_stats_widget') ? 'badge-green' : '' ?>"><?= $isOn('plugin_stats_widget') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Zeigt Gästen auf der Startseite: Mitglieder, Beiträge heute, online.</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_stats_widget') ? 'checked' : '' ?>> Widget anzeigen</label>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="birthday_widget">
        <div class="card-head"><h3>🎂 Geburtstags-Widget</h3>
            <span class="badge <?= $isOn('plugin_birthday_widget') ? 'badge-green' : '' ?>"><?= $isOn('plugin_birthday_widget') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Zeigt auf der Wall Geburtstage von heute – mit Konfetti!</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_birthday_widget') ? 'checked' : '' ?>> Widget anzeigen</label>
        <button class="btn" type="submit">Speichern</button>
    </form>

    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="plugin_save">
        <input type="hidden" name="plugin" value="weekly_report">
        <div class="card-head"><h3>📧 Wochen-Report</h3>
            <span class="badge <?= $isOn('plugin_weekly_report') ? 'badge-green' : '' ?>"><?= $isOn('plugin_weekly_report') ? 'aktiv' : 'aus' ?></span></div>
        <p class="muted small">Sendet dir 1× pro Woche die wichtigsten Zahlen an die Admin-E-Mail.</p>
        <label class="check"><input type="checkbox" name="enabled" <?= $isOn('plugin_weekly_report') ? 'checked' : '' ?>> Wochen-Report aktivieren</label>
        <button class="btn" type="submit">Speichern</button>
    </form>

<!-- Community-Seiten (Plugin-Schalter) -->
<h3 style="margin:1.8rem 0 1rem">📄 Community-Seiten</h3>
<p class="muted small" style="margin-bottom:1rem">Aktivierte Seiten erscheinen automatisch im Mitglieder-Menü. Deaktivierte Seiten sind auch per Direkt-URL nicht erreichbar.</p>
<form method="post" class="card glass form-stack" style="margin-bottom:1.5rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="plugin_pages">
    <div class="form-grid">
        <?php
        $pageDefs = [
            'shoutbox' => ['💬 Shoutbox', 'Kurze Nachrichten für alle'],
            'events'   => ['📅 Events', 'Event-Kalender mit Teilnahme'],
            'giveaway' => ['🎁 Gewinnspiel', 'Verlosungen mit Gewinner-Ziehung'],
            'contest'  => ['🏆 Wettbewerbe', 'Einsendungen & Community-Voting'],
            'polls'    => ['📊 Umfragen', 'Abstimmungen mit Live-Ergebnissen'],
            'resources'=> ['📦 Ressourcen', 'Downloads mit Bild & Beschreibung'],
            'groups'   => ['👥 Gruppen', 'Eigene Gruppen mit eigener Wall (öffentlich/privat)'],
        ];
        foreach ($pageDefs as $pKey => [$pLabel, $pDesc]): ?>
        <label class="check" style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.8rem 1rem">
            <input type="checkbox" name="<?= $pKey ?>" <?= setting('plugin_' . $pKey, '1') === '1' ? 'checked' : '' ?>>
            <span><strong><?= $pLabel ?></strong><br><span class="muted small"><?= $pDesc ?></span></span>
        </label>
        <?php endforeach; ?>
    </div>
    <div><button class="btn" type="submit">Speichern</button>
    <span class="muted small" style="margin-left:.8rem">Hinweis: Neu sind alle Seiten erstmal AUS – hake an, was du nutzen willst.</span></div>
</form>

<!-- Ressourcen -->
<h3 style="margin:1.8rem 0 1rem">📦 Ressourcen verwalten</h3>
<div class="grid grid-2">
    <form method="post" enctype="multipart/form-data" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="resource_save">
        <h3>Neue Ressource hochladen</h3>
        <label>Titel <input type="text" name="title" maxlength="200" required></label>
        <label>Beschreibung <textarea name="description" rows="3" maxlength="2000" placeholder="Was ist drin? Für wen ist es gedacht?"></textarea></label>
        <label>Datei * (alle Formate, kein Größenlimit)
            <input type="file" name="file" required>
        </label>
        <label>Vorschaubild (optional, JPG/PNG/GIF/WEBP, max. 5 MB)
            <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
        </label>
        <button class="btn" type="submit">Hochladen</button>
        <p class="muted small">Erscheint sofort auf der Seite „Ressourcen" (sofern aktiviert).</p>
    </form>

    <div class="card glass">
        <h3>Vorhandene Ressourcen (<?= count($adminResources) ?>)</h3>
        <?php if (!$adminResources): ?><p class="muted">Noch keine Ressourcen.</p><?php endif; ?>
        <?php foreach ($adminResources as $r): ?>
        <div class="friend-row">
            <?php if (!empty($r['image'])): ?>
                <img src="../uploads/resources/<?= rawurlencode($r['image']) ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
            <?php else: ?>
                <span style="font-size:1.4rem">📦</span>
            <?php endif; ?>
            <div class="friend-info">
                <strong><?= e(mb_strimwidth($r['title'], 0, 35, '…')) ?></strong>
                <span class="muted small"><?= (int)$r['downloads'] ?> Downloads · <?= e($r['original_name'] ?? '') ?></span>
            </div>
            <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resource_toggle">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="badge <?= $r['active'] ? 'badge-green' : 'badge-red' ?>" type="submit"><?= $r['active'] ? 'sichtbar' : 'verborgen' ?></button>
            </form>
            <form method="post" class="inline" data-confirm="Ressource inkl. Datei wirklich löschen?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resource_delete">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">Löschen</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Umfragen -->
<h3 style="margin:1.8rem 0 1rem">📊 Umfragen verwalten</h3>
<div class="grid grid-2">
    <form method="post" class="card glass form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="poll_create">
        <h3>Neue Umfrage</h3>
        <p class="muted small">Erscheint auf der Seite „Umfragen" für alle Mitglieder. Pro Mitglied 1 Stimme.</p>
        <label>Frage <input type="text" name="question" maxlength="255" required placeholder="Welches Game soll ich zocken?"></label>
        <label>Antwortoptionen (eine pro Zeile, max. 10)
            <textarea name="options" rows="4" required placeholder="Elden Ring&#10;GTA VI&#10;Minecraft"></textarea>
        </label>
        <button class="btn" type="submit">Umfrage erstellen &amp; aktivieren</button>
    </form>

    <div class="card glass">
        <h3>Letzte Umfragen</h3>
        <?php if (!$polls): ?><p class="muted">Noch keine Umfragen erstellt.</p><?php endif; ?>
        <?php foreach ($polls as $pl): ?>
        <div class="friend-row">
            <div class="friend-info">
                <strong><?= e(mb_strimwidth($pl['question'], 0, 40, '…')) ?></strong>
                <span class="muted small"><?= (int)$pl['votes'] ?> Stimme(n) <?= $pl['active'] ? '· <span class="badge badge-green">aktiv</span>' : '' ?></span>
            </div>
            <?php if (!$pl['active']): ?>
            <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="poll_toggle">
                <input type="hidden" name="id" value="<?= (int)$pl['id'] ?>">
                <button class="btn btn-sm" type="submit">Aktivieren</button>
            </form>
            <?php endif; ?>
            <form method="post" class="inline" data-confirm="Umfrage inkl. Stimmen wirklich löschen?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="poll_delete">
                <input type="hidden" name="id" value="<?= (int)$pl['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">Löschen</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php include __DIR__ . '/_footer.php'; ?>
