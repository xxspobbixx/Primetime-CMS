<?php
require_once __DIR__ . '/functions.php';

/* =========================================================
   Plugin-Bibliothek: Badges, Level, Punkte, Filter, Limits
   ========================================================= */

/* ---------- Punkte ---------- */

/** Addiert Punkte aufs Punktekonto eines Mitglieds. */
function add_points(int $userId, int $amount): void
{
    db()->prepare('UPDATE users SET points = points + ? WHERE id = ?')->execute([$amount, $userId]);
}

/** Gesamtpunkte = Konto-Punkte + Aktivitäts-Punkte (berechnet). */
function total_points(int $uid): int
{
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    try {
        $stmt = db()->prepare('SELECT points FROM users WHERE id = ?');
        $stmt->execute([$uid]);
        return $cache[$uid] = ((int)$stmt->fetchColumn()) + rank_points($uid);
    } catch (Throwable $e) {
        // Spalte 'points' fehlt -> update.php ausführen; bis dahin nur Aktivitäts-Punkte
        return $cache[$uid] = rank_points($uid);
    }
}

/** Level (1–20) aus Gesamtpunkten. */
function user_level(int $points): int
{
    return min(20, max(1, (int)floor(sqrt($points / 25)) + 1));
}

/** Fortschrittsbalken zum nächsten Level. */
function level_progress(int $points): array
{
    $lvl = user_level($points);
    $cur = 25 * ($lvl - 1) ** 2;
    $next = 25 * $lvl ** 2;
    $pct = $next > $cur ? (int)round(($points - $cur) / ($next - $cur) * 100) : 100;
    return ['level' => $lvl, 'pct' => max(0, min(100, $pct)), 'cur' => $cur, 'next' => $next];
}

/** Namensfarbe im Chat je nach Level. */
function level_color(int $points): string
{
    $lvl = user_level($points);
    if ($lvl >= 15) return '#ff2ec4';
    if ($lvl >= 10) return '#f0c850';
    if ($lvl >= 5)  return '#22d3ee';
    return '';
}

/* ---------- Badges ---------- */

function badge_defs(): array
{
    return [
        'first_post'   => ['📝', 'Erster Post', 'Veröffentliche deinen ersten Wall-Beitrag'],
        'social'       => ['🤝', 'Gesellig', 'Schließe 5 Freundschaften'],
        'chatty'       => ['💬', 'Schwätzer', 'Schreibe 50 Nachrichten'],
        'forum_pro'    => ['🗂️', 'Forum-Profi', 'Erstelle 3 Foren-Themen'],
        'liked'        => ['❤️', 'Beliebt', 'Erhalte 25 Likes auf deine Beiträge'],
        'early'        => ['🌅', 'Früher Vogel', 'War 7 Tage in Folge aktiv'],
        'veteran'      => ['🏅', 'Veteran', 'Seit 1 Jahr Mitglied'],
        'photographer' => ['📸', 'Fotograf', 'Teile 5 Bilder auf der Wall'],
        'winner'       => ['🏆', 'Gewinner', 'Gewinne ein Gewinnspiel'],
        'voter'        => ['🗳️', 'Wähler', 'Stimme in 5 Umfragen ab'],
    ];
}

/** Prüft & vergibt Badges für ein Mitglied (nach relevanten Aktionen aufrufen). */
function check_badges(int $uid): void
{
    static $done = false;
    if ($done) return;
    try {
        $u = db()->prepare('SELECT created_at FROM users WHERE id = ?');
        $u->execute([$uid]);
        $u = $u->fetch();
        if (!$u) return;
        $has = function (string $k) use ($uid) {
            $s = db()->prepare('SELECT 1 FROM user_badges WHERE user_id = ? AND badge_key = ?');
            $s->execute([$uid, $k]);
            return (bool)$s->fetchColumn();
        };
        $award = function (string $k) use ($uid, $has) {
            if ($has($k)) return;
            db()->prepare('INSERT IGNORE INTO user_badges (user_id, badge_key) VALUES (?, ?)')->execute([$uid, $k]);
            $d = badge_defs()[$k] ?? ['🏅', $k, ''];
            notify($uid, 'badge', $uid, 'Neues Badge: ' . $d[0] . ' ' . $d[1], SITE_URL . '/panel.php');
        };

        $c = function (string $sql) use ($uid) {
            $s = db()->query(str_replace('{uid}', (string)$uid, $sql));
            return (int)$s->fetchColumn();
        };
        if ($c("SELECT COUNT(*) FROM wall_posts WHERE user_id = {uid}") >= 1) $award('first_post');
        if ($c("SELECT COUNT(*) FROM friendships WHERE user_id = {uid} AND status = 'accepted'") >= 5) $award('social');
        if ($c("SELECT COUNT(*) FROM messages WHERE sender_id = {uid}") >= 50) $award('chatty');
        if ($c("SELECT COUNT(*) FROM forum_topics WHERE user_id = {uid}") >= 3) $award('forum_pro');
        if ($c("SELECT COUNT(*) FROM wall_reactions r JOIN wall_posts w ON w.id = r.post_id WHERE w.user_id = {uid} AND r.type = 'like'") >= 25) $award('liked');
        if ($c("SELECT COUNT(DISTINCT DATE(created_at)) FROM wall_posts WHERE user_id = {uid} AND created_at > NOW() - INTERVAL 8 DAY") >= 7) $award('early');
        if ($c("SELECT COUNT(*) FROM wall_posts WHERE user_id = {uid} AND image IS NOT NULL AND image != ''") >= 5) $award('photographer');
        if (strtotime($u['created_at']) < strtotime('-1 year')) $award('veteran');
        if ($c("SELECT COUNT(*) FROM poll_votes WHERE user_id = {uid}") >= 5) $award('voter');
        if ($c("SELECT COUNT(*) FROM giveaways g JOIN giveaway_entries e ON e.giveaway_id = g.id WHERE g.winner_id = {uid}") >= 1) $award('winner');
    } catch (Throwable $e) { /* Tabellen fehlen -> update.php ausführen */ }
    $done = true;
}

/** Badges eines Mitglieds für die Anzeige. */
function user_badges(int $uid): array
{
    try {
        $stmt = db()->prepare('SELECT badge_key FROM user_badges WHERE user_id = ? ORDER BY earned_at');
        $stmt->execute([$uid]);
        $defs = badge_defs();
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            if (isset($defs[$r['badge_key']])) $out[$r['badge_key']] = $defs[$r['badge_key']];
        }
        return $out;
    } catch (Throwable $e) {
        return []; // Tabelle fehlt -> update.php ausführen
    }
}

/* ---------- Wortfilter ---------- */

/** Filtert unerwünschte Wörter aus Texten (Plugin wordfilter). */
function filter_words(string $text): string
{
    if (!plugin_active('wordfilter')) return $text;
    $raw = trim((string)setting('plugin_wordfilter_words'));
    if ($raw === '') return $text;
    foreach (preg_split('/[\r\n,;]+/', $raw) as $w) {
        $w = trim($w);
        if ($w === '') continue;
        $text = str_ireplace($w, str_repeat('*', mb_strlen($w)), $text);
    }
    return $text;
}

/* ---------- Rate-Limit ---------- */

/** true, wenn das Mitglied die Rate-Limit-Grenze erreicht hat. */
function rate_limited(int $uid, string $table, int $maxPerMin): bool
{
    if (!plugin_active('rate_limit')) return false;
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM $table WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 MINUTE");
        $s->execute([$uid]);
        return (int)$s->fetchColumn() >= $maxPerMin;
    } catch (Throwable $e) { return false; }
}

/* ---------- Shadow-Ban ---------- */

/** true, wenn die Aktivität dieses Users für den Betrachter unsichtbar ist. */
function is_shadow_hidden(int $targetUserId, ?array $viewer): bool
{
    static $banned = null;
    if ($banned === null) {
        try {
            $banned = array_map('intval', db()->query('SELECT id FROM users WHERE shadow_banned = 1')->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            $banned = []; // Spalte fehlt -> update.php ausführen
        }
    }
    if (!in_array($targetUserId, $banned, true)) return false;
    // Shadow-Ban gilt nicht für sich selbst und Moderatoren
    if ($viewer && ((int)$viewer['id'] === $targetUserId || user_can_moderate($viewer))) return false;
    return true;
}

/* ---------- Auto-Moderation ---------- */

/** Blendet einen Wall-Beitrag automatisch aus, wenn die Meldungsschwelle erreicht ist. */
function auto_moderate_wall(int $postId): void
{
    if (!plugin_active('auto_mod')) return;
    $threshold = max(2, (int)setting('plugin_auto_mod_threshold', '3'));
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM wall_reports WHERE post_id = ? AND status = 'open'");
        $s->execute([$postId]);
        if ((int)$s->fetchColumn() >= $threshold) {
            db()->prepare('UPDATE wall_posts SET hidden = 1 WHERE id = ?')->execute([$postId]);
            discord_notify('🛡️ Auto-Moderation',
                'Ein Wall-Beitrag wurde nach ' . $threshold . ' Meldungen automatisch ausgeblendet (ID ' . $postId . ').',
                15105570);
        }
    } catch (Throwable $e) { /* Tabelle fehlt */ }
}

/* ---------- Statistik-Helfer ---------- */

function count_since(string $table, string $dateCol = 'created_at', int $days = 7): int
{
    try { return (int)db()->query("SELECT COUNT(*) FROM $table WHERE $dateCol > NOW() - INTERVAL $days DAY")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}


/** Konfetti für ein Mitglied beim nächsten Seitenaufruf (Plugin konfetti). */
function konfetti_for(int $userId): void
{
    if (!plugin_active('konfetti')) return;
    // Einfache Variante: Einstellung als "Postfach"
    set_setting('konfetti_uid_' . $userId, '1');
}


/** Mitglied des Monats (höchste Gesamtpunkte). */
function member_of_month(): ?array
{
    static $m = null;
    if ($m !== null) return $m ?: null;
    try {
        $row = db()->query("SELECT u.username, u.display_name, u.avatar, u.avatar_color,
            (SELECT COUNT(*) FROM wall_posts w WHERE w.user_id = u.id) * 5 +
            (SELECT COUNT(*) FROM wall_comments c WHERE c.user_id = u.id) * 2 +
            (SELECT COUNT(*) FROM forum_posts fp WHERE fp.user_id = u.id) * 5 + u.points AS pts
            FROM users u ORDER BY pts DESC LIMIT 1")->fetch();
        $m = $row ?: false;
    } catch (Throwable $e) { $m = false; }
    return $m ?: null;
}


/* ---------- Ressourcen-Uploads ---------- */

/** Speichert eine Resource-Datei (ALLE Formate, kein Größenlimit).
 *  Sicherheit: .htaccess im Upload-Ordner blockiert Script-Ausführung,
 *  Download läuft erzwungen als Anhang über download.php. */
function save_resource_file(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        // Fehlercode 1/2 = PHP-seitiges Upload-Limit überschritten (siehe Admin-Hinweis)
        if (($file['error'] ?? 0) == 1) throw new RuntimeException('Die Datei überschreitet das PHP-Serverlimit (upload_max_filesize).');
        if (($file['error'] ?? 0) == 2) throw new RuntimeException('Die Datei überschreitet das Formular-Limit.');
        throw new RuntimeException('Upload fehlgeschlagen (Fehlercode ' . (int)$file['error'] . ').');
    }
    if ($file['size'] === 0) throw new RuntimeException('Die Datei ist leer.');
    $orig = basename((string)$file['name']);
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $dir = APP_ROOT . '/uploads/resources';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    // Keine Original-Endung: Datei kann im Upload-Ordner nicht als Script ausgeführt werden
    $stored = bin2hex(random_bytes(10)) . '.dat';
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        throw new RuntimeException('Speichern der Datei fehlgeschlagen.');
    }
    return ['stored' => $stored, 'original' => $orig];
}

/** Speichert ein Vorschaubild (max. 5 MB, auf 800px Breite verkleinert). */
function save_resource_image(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Bild-Upload fehlgeschlagen (Fehlercode ' . (int)$file['error'] . ').');
    }
    if ($file['size'] > 5 * 1024 * 1024) throw new RuntimeException('Bild ist größer als 5 MB.');
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) throw new RuntimeException('Die Datei ist kein gültiges Bild.');
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!isset($allowed[$info[2]])) throw new RuntimeException('Nur JPG, PNG, GIF oder WEBP erlaubt.');

    $dir = APP_ROOT . '/uploads/resources';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $token = 'thumb_' . bin2hex(random_bytes(8));

    if (extension_loaded('gd')) {
        $src = null;
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($file['tmp_name']); break;
            case IMAGETYPE_PNG:  $src = imagecreatefrompng($file['tmp_name']); break;
            case IMAGETYPE_GIF:  $src = imagecreatefromgif($file['tmp_name']); break;
            case IMAGETYPE_WEBP: $src = imagecreatefromwebp($file['tmp_name']); break;
        }
        if (!$src) throw new RuntimeException('Bild konnte nicht verarbeitet werden.');
        $maxW = 800;
        $w = $info[0]; $h = $info[1];
        if ($w > $maxW) { $newW = $maxW; $newH = max(1, (int)round($h * $maxW / $w)); }
        else            { $newW = $w; $newH = $h; }
        $dst = imagecreatetruecolor($newW, $newH);
        if ($info[2] === IMAGETYPE_PNG || $info[2] === IMAGETYPE_GIF) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        $filename = $token . '.jpg';
        imagejpeg($dst, $dir . '/' . $filename, 85);
        imagedestroy($src);
        imagedestroy($dst);
    } else {
        $filename = $token . '.' . $allowed[$info[2]];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
            throw new RuntimeException('Speichern des Bildes fehlgeschlagen.');
        }
    }
    return $filename;
}

/** Löscht Datei + Bild einer Ressource (DB-Zeile separat). */
function delete_resource_files(array $r): void
{
    foreach (['file', 'image'] as $k) {
        $f = trim((string)($r[$k] ?? ''));
        if ($f !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $f)) {
            $p = APP_ROOT . '/uploads/resources/' . $f;
            if (is_file($p)) unlink($p);
        }
    }
}


/* ---------- Moderations-Log ---------- */

/** Schreibt eine Aktion in das Moderations-Log (Plugin mod_log). */
function mod_log(string $action, string $target = ''): void
{
    try {
        $mod = current_admin() ?: current_member();
        db()->prepare('INSERT INTO mod_log (mod_id, mod_name, action, target) VALUES (?, ?, ?, ?)')
            ->execute([$mod['id'] ?? null, $mod['username'] ?? '?', $action, mb_substr($target, 0, 250)]);
    } catch (Throwable $e) { /* Tabelle fehlt -> update.php ausführen */ }
}


/* ---------- Automatisches Backup ---------- */

/** Erstellt bei Bedarf ein automatisches SQL-Backup (Plugin auto_backup). */
function maybe_auto_backup(): void
{
    if (!plugin_active('auto_backup')) return;
    $interval = max(1, (int)setting('plugin_auto_backup_days', '7')) * 86400;
    $last = (int)setting('plugin_auto_backup_last', '0');
    if ($last > time() - $interval) return;

    try {
        $dir = APP_ROOT . '/backups';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        // Schutz des Backup-Ordners
        if (!is_file($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        $file = $dir . '/backup-' . date('Ymd-His') . '.sql';
        $pdo = db();
        $out = "-- Auto-Backup " . date('c') . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
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
        if (file_put_contents($file, $out)) {
            set_setting('plugin_auto_backup_last', (string)time());
            $files = glob($dir . '/*.sql') ?: [];
            sort($files);
            while (count($files) > 5) { @unlink(array_shift($files)); }
        }
    } catch (Throwable $e) { /* Backup darf nichts blockieren */ }
}


/* ---------- E-Mail-Benachrichtigungs-Digest ---------- */

/** Sendet beim Login ggf. eine Zusammenfassung neuer Benachrichtigungen (max. 1x/Tag). */
function maybe_send_digest(array $user): void
{
    if (empty($user['notify_email'])) return;
    if (!empty($user['last_digest']) && strtotime($user['last_digest']) > time() - 86400) return;
    $email = trim((string)$user['email']);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    $unread = unread_notification_count((int)$user['id']);
    $msgs = 0;
    try {
        $s = db()->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND read_at IS NULL');
        $s->execute([$user['id']]);
        $msgs = (int)$s->fetchColumn();
    } catch (Throwable $e) { /* Tabelle fehlt */ }
    if ($unread === 0 && $msgs === 0) {
        db()->prepare('UPDATE users SET last_digest = NOW() WHERE id = ?')->execute([$user['id']]);
        return;
    }

    $stmt = db()->prepare('SELECT text, link FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY id DESC LIMIT 5');
    $stmt->execute([$user['id']]);
    $lines = '';
    foreach ($stmt->fetchAll() as $n) {
        $lines .= '• ' . $n['text'] . ($n['link'] ? "\n  " . $n['link'] : '') . "\n";
    }

    $body = "Hallo " . ($user['display_name'] ?: $user['username']) . ",\n\n"
          . "du hast " . $unread . " neue Benachrichtigung(en) und " . $msgs . " ungelesene Nachricht(en) auf "
          . setting('site_name', 'der Webseite') . ":\n\n" . $lines . "\n"
          . "Direkt zur Seite: " . SITE_URL . "\n\n"
          . "Diese Zusammenfassung kommt maximal 1x pro Tag. Abbestellen: Profil-Einstellungen.";
    @mail($email, setting('site_name', 'Webseite') . ': Zusammenfassung (' . $unread . ' neu)', $body,
        'From: noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    db()->prepare('UPDATE users SET last_digest = NOW() WHERE id = ?')->execute([$user['id']]);
}
