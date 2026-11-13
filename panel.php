<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
$current = 'panel';
$pageTitle = 'Mein Panel';
$member = current_member();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'export_data') {
        // DSGVO: Alle eigenen Daten als JSON exportieren
        $uid = (int)$member['id'];
        $data = [
            'profil' => $member,
            'wall_posts' => db()->query("SELECT * FROM wall_posts WHERE user_id = $uid")->fetchAll(),
            'wall_comments' => db()->query("SELECT * FROM wall_comments WHERE user_id = $uid")->fetchAll(),
            'wall_reactions' => db()->query("SELECT * FROM wall_reactions WHERE user_id = $uid")->fetchAll(),
            'forum_topics' => db()->query("SELECT * FROM forum_topics WHERE user_id = $uid")->fetchAll(),
            'forum_posts' => db()->query("SELECT * FROM forum_posts WHERE user_id = $uid")->fetchAll(),
            'nachrichten_gesendet' => db()->query("SELECT id, receiver_id, message, created_at FROM messages WHERE sender_id = $uid")->fetchAll(),
            'freunde' => db()->query("SELECT friend_id, status FROM friendships WHERE user_id = $uid")->fetchAll(),
            'badges' => db()->query("SELECT badge_key, earned_at FROM user_badges WHERE user_id = $uid")->fetchAll(),
            'exportiert_am' => date('c'),
        ];
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="meine-daten.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    } elseif ($action === 'cover') {
        if (!empty($_FILES['cover']['name'])) {
            try {
                $old = $member['cover'] ?? null;
                // Breites Titelbild: 1600x500 erlauben, GD verkleinert auf max. 1600 Breite
                $file = $_FILES['cover'];
                if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload fehlgeschlagen.');
                if ($file['size'] > 5 * 1024 * 1024) throw new RuntimeException('Bild größer als 5 MB.');
                $info = @getimagesize($file['tmp_name']);
                if ($info === false) throw new RuntimeException('Kein gültiges Bild.');
                $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
                if (!isset($allowed[$info[2]])) throw new RuntimeException('Nur JPG, PNG oder WEBP.');
                $dir = APP_ROOT . '/uploads/avatars';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $token = 'cover_' . $member['id'] . '_' . bin2hex(random_bytes(6));
                if (extension_loaded('gd')) {
                    $src = null;
                    switch ($info[2]) {
                        case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($file['tmp_name']); break;
                        case IMAGETYPE_PNG:  $src = imagecreatefrompng($file['tmp_name']); break;
                        case IMAGETYPE_WEBP: $src = imagecreatefromwebp($file['tmp_name']); break;
                    }
                    if (!$src) throw new RuntimeException('Verarbeitung fehlgeschlagen.');
                    $maxW = 1600;
                    $w = $info[0]; $h = $info[1];
                    if ($w > $maxW) { $newW = $maxW; $newH = max(1, (int)round($h * $maxW / $w)); }
                    else { $newW = $w; $newH = $h; }
                    $dst = imagecreatetruecolor($newW, $newH);
                    if ($info[2] === IMAGETYPE_PNG) { imagealphablending($dst, false); imagesavealpha($dst, true); }
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
                    $filename = $token . '.jpg';
                    imagejpeg($dst, $dir . '/' . $filename, 85);
                    imagedestroy($src); imagedestroy($dst);
                } else {
                    $filename = $token . '.' . $allowed[$info[2]];
                    move_uploaded_file($file['tmp_name'], $dir . '/' . $filename);
                }
                db()->prepare('UPDATE users SET cover = ? WHERE id = ?')->execute([$filename, $member['id']]);
                if ($old && $old !== $filename) delete_avatar_file($old);
                flash('success', 'Titelbild aktualisiert.');
            } catch (RuntimeException $ex) {
                flash('error', 'Titelbild: ' . $ex->getMessage());
            }
        }
        header('Location: panel.php');
        exit;
    } elseif ($action === 'cover_delete') {
        delete_avatar_file($member['cover'] ?? null);
        db()->prepare('UPDATE users SET cover = NULL WHERE id = ?')->execute([$member['id']]);
        flash('success', 'Titelbild entfernt.');
        header('Location: panel.php');
        exit;
    } elseif ($action === 'custom_status') {
        $status = trim((string)($_POST['status'] ?? ''));
        if (mb_strlen($status) > 120) {
            flash('error', 'Status max. 120 Zeichen.');
        } else {
            db()->prepare('UPDATE users SET custom_status = ? WHERE id = ?')->execute([$status !== '' ? $status : null, $member['id']]);
            flash('success', 'Status aktualisiert! 💬');
        }
        header('Location: panel.php');
        exit;
    } elseif ($action === 'profile_song') {
        $url = trim((string)($_POST['song_url'] ?? ''));
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            flash('error', 'Bitte eine gültige URL (YouTube oder Spotify) angeben.');
        } else {
            db()->prepare('UPDATE users SET profile_song = ? WHERE id = ?')->execute([$url !== '' ? $url : null, $member['id']]);
            flash('success', 'Profil-Song aktualisiert. 🎵');
        }
        header('Location: panel.php');
        exit;
    } elseif ($action === 'extra_settings') {
        $sig = trim((string)($_POST['forum_signature'] ?? ''));
        if (mb_strlen($sig) > 300) {
            flash('error', 'Signatur max. 300 Zeichen.');
        } else {
            db()->prepare('UPDATE users SET forum_signature = ?, notify_email = ?, quiet_start = ?, quiet_end = ? WHERE id = ?')
                ->execute([$sig, isset($_POST['notify_email']) ? 1 : 0,
                    ($_POST['quiet_start'] ?? '') !== '' ? $_POST['quiet_start'] : null,
                    ($_POST['quiet_end'] ?? '') !== '' ? $_POST['quiet_end'] : null,
                    $member['id']]);
            flash('success', 'Einstellungen gespeichert.');
        }
        header('Location: panel.php');
        exit;
    } elseif ($action === 'daily_bonus') {
        $today = date('Y-m-d');
        $uid = (int)$member['id'];

        // Wert DIREKT aus der DB lesen (unabhängig von Session/SELECT-Stand)
        $stmt = db()->prepare('SELECT last_bonus, streak FROM users WHERE id = ?');
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        $lastBonus = (string)($row['last_bonus'] ?? '');

        if ($lastBonus === $today) {
            flash('error', 'Tagesbonus heute bereits abgeholt.');
        } else {
            $isStreakDay = ($lastBonus === date('Y-m-d', strtotime('-1 day')));
            $streak = $isStreakDay ? (int)($row['streak'] ?? 0) + 1 : 1;
            $freezeNote = '';
            if (!$isStreakDay && (int)($row['streak'] ?? 0) > 0 && (int)$member['streak_freeze'] > 0) {
                // Streak wuerde verfallen -> Freezer verbrauchen
                db()->prepare('UPDATE users SET streak_freeze = streak_freeze - 1 WHERE id = ?')->execute([$uid]);
                $streak = (int)$row['streak'] + 1;
                $freezeNote = ' (❄️ Freezer eingesetzt!)';
            }
            $bonus  = 10 + min($streak, 7); // bis zu +7 Streak-Bonus

            // ATOMAR: Nur updaten, wenn noch nicht gezogen – Doppelklicks haben keine Chance
            $upd = db()->prepare('UPDATE users SET last_bonus = ?, streak = ?, points = points + ?
                                  WHERE id = ? AND (last_bonus IS NULL OR last_bonus < ?)');
            $upd->execute([$today, $streak, $bonus, $uid, $today]);

            if ($upd->rowCount() > 0) {
                if (plugin_active('konfetti')) { $_SESSION['confetti'] = true; }
                flash('success', "🎁 Tagesbonus: +$bonus Punkte (Streak: {$streak} Tag(e))$freezeNote!");
            } else {
                flash('error', 'Tagesbonus heute bereits abgeholt.');
            }
        }
        header('Location: panel.php');
        exit;
    } elseif ($action === 'toggle_2fa') {
        $enable = !((int)$member['twofa_enabled'] === 1);
        if ($enable && trim((string)$member['email']) === '') {
            flash('error', 'Bitte erst eine E-Mail-Adresse im Profil hinterlegen.');
        } else {
            db()->prepare('UPDATE users SET twofa_enabled = ? WHERE id = ?')->execute([$enable ? 1 : 0, $member['id']]);
            flash('success', $enable ? '2FA aktiviert – ab jetzt Code per E-Mail beim Login.' : '2FA deaktiviert.');
        }
        header('Location: panel.php');
        exit;
    } elseif ($action === 'profile') {
        $displayName  = trim((string)($_POST['display_name'] ?? ''));
        $bio          = trim((string)($_POST['bio'] ?? ''));
        $location     = trim((string)($_POST['location'] ?? ''));
        $website      = trim((string)($_POST['website'] ?? ''));
        $favoriteGame = trim((string)($_POST['favorite_game'] ?? ''));
        $discordTag   = trim((string)($_POST['discord_tag'] ?? ''));
        $birthday     = trim((string)($_POST['birthday'] ?? ''));
        $color        = hexcolor(trim((string)($_POST['avatar_color'] ?? '')), '#6c5ce7');
        $isPublic     = isset($_POST['is_public']) ? 1 : 0;
        $showOnline   = isset($_POST['show_online']) ? 1 : 0;

        $err = null;
        if (mb_strlen($displayName) > 100)            $err = 'Anzeigename zu lang (max. 100 Zeichen).';
        elseif (mb_strlen($bio) > 500)                $err = 'Kurzbeschreibung zu lang (max. 500 Zeichen).';
        elseif (mb_strlen($location) > 100)           $err = 'Ort zu lang (max. 100 Zeichen).';
        elseif (mb_strlen($favoriteGame) > 100)       $err = 'Lieblingsspiel zu lang (max. 100 Zeichen).';
        elseif (mb_strlen($discordTag) > 50)          $err = 'Discord-Tag zu lang (max. 50 Zeichen).';
        elseif ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) $err = 'Bitte eine gültige Website-URL angeben (inkl. https://).';
        elseif ($birthday !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) $err = 'Ungültiges Geburtsdatum.';
        elseif ($birthday !== '' && strtotime($birthday) > time()) $err = 'Das Geburtsdatum liegt in der Zukunft.';

        if ($err) {
            flash('error', $err);
        } else {
            db()->prepare('UPDATE users SET display_name=?, bio=?, location=?, website=?, favorite_game=?,
                                          discord_tag=?, birthday=?, avatar_color=?, is_public=?, show_online=? WHERE id=?')
                ->execute([
                    $displayName !== '' ? $displayName : $member['username'],
                    $bio, $location, $website, $favoriteGame,
                    $discordTag, $birthday !== '' ? $birthday : null,
                    $color, $isPublic, $showOnline, $member['id'],
                ]);
            // Benutzerdefinierte Profilfelder speichern
            $fields = profile_fields_active();
            $stmt = db()->prepare('INSERT INTO profile_values (user_id, field_id, value) VALUES (?, ?, ?)
                                   ON DUPLICATE KEY UPDATE value = VALUES(value)');
            foreach ($fields as $f) {
                $val = trim((string)($_POST['field_' . $f['id']] ?? ''));
                if ($val !== '' && !validate_custom_field($f['field_type'], $val)) {
                    flash('error', 'Ungültiger Wert im Feld „' . $f['label'] . '".');
                    header('Location: panel.php');
                    exit;
                }
                $stmt->execute([$member['id'], $f['id'], $val]);
            }
            flash('success', 'Profil gespeichert.');
        }
    } elseif ($action === 'avatar') {
        if (!empty($_FILES['avatar']['name'])) {
            try {
                $old = $member['avatar'];
                $filename = save_avatar_upload($_FILES['avatar'], (int)$member['id']);
                db()->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$filename, $member['id']]);
                if ($old && $old !== $filename) delete_avatar_file($old);
                flash('success', 'Profilbild aktualisiert.');
            } catch (RuntimeException $ex) {
                flash('error', $ex->getMessage());
            }
        }
    } elseif ($action === 'avatar_delete') {
        delete_avatar_file($member['avatar']);
        db()->prepare('UPDATE users SET avatar = NULL WHERE id = ?')->execute([$member['id']]);
        flash('success', 'Profilbild entfernt.');
    } elseif ($action === 'password') {
        $currentPw = (string)($_POST['current_password'] ?? '');
        $newPw     = (string)($_POST['new_password'] ?? '');
        $stmt = db()->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$member['id']]);
        if (!password_verify($currentPw, (string)$stmt->fetchColumn())) {
            flash('error', 'Aktuelles Passwort ist falsch.');
        } elseif (mb_strlen($newPw) < 8) {
            flash('error', 'Neues Passwort muss mindestens 8 Zeichen haben.');
        } else {
            db()->prepare('UPDATE users SET password = ? WHERE id = ?')
                ->execute([password_hash($newPw, PASSWORD_DEFAULT), $member['id']]);
            flash('success', 'Passwort geändert.');
        }
    } elseif ($action === 'delete') {
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $stmt = db()->prepare('SELECT password, role, avatar FROM users WHERE id = ?');
        $stmt->execute([$member['id']]);
        $row = $stmt->fetch();
        if (!password_verify($confirm, $row['password'])) {
            flash('error', 'Passwort falsch – Konto wurde nicht gelöscht.');
        } elseif ($row['role'] === 'admin') {
            flash('error', 'Admin-Konten können sich nicht selbst löschen.');
        } else {
            // Alle Community-Daten des Kontos bereinigen (inkl. Wall)
            db()->prepare('DELETE FROM friendships WHERE user_id = ? OR friend_id = ?')->execute([$member['id'], $member['id']]);
            db()->prepare('DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?')->execute([$member['id'], $member['id']]);
            db()->prepare('DELETE FROM reports WHERE reporter_id = ? OR reported_id = ?')->execute([$member['id'], $member['id']]);
            delete_user_wall_posts((int)$member['id']);
            delete_avatar_file($row['avatar']);
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$member['id']]);
            unset($_SESSION['member_id']);
            flash('success', 'Dein Konto wurde gelöscht. Schade, dass du gehst!');
            header('Location: index.php');
            exit;
        }
    }
    header('Location: panel.php');
    exit;
}

$member = current_member(); // frische Daten nach Speichern
$customFields = profile_fields_active();
$customValues = get_profile_values((int)$member['id']);
check_badges((int)$member['id']);
$myBadges = user_badges((int)$member['id']);
$myPoints = total_points((int)$member['id']);
$myLevel = level_progress($myPoints);
// Bonus-Status direkt aus der DB (unabhängig vom SELECT-Stand)
$bonusStmt = db()->prepare('SELECT last_bonus, streak FROM users WHERE id = ?');
$bonusStmt->execute([(int)$member['id']]);
$bonusRow = $bonusStmt->fetch();
$bonusToday = ((string)($bonusRow['last_bonus'] ?? '')) === date('Y-m-d');
$bonusStreak = (int)($bonusRow['streak'] ?? 0);
$refCount = 0;
try { $refCount = (int)db()->query('SELECT COUNT(*) FROM users WHERE referred_by = ' . (int)$member['id'])->fetchColumn(); } catch (Throwable $e) {}
$friendCount = 0;
try {
    $stmt = db()->prepare("SELECT COUNT(*) FROM friendships WHERE user_id = ? AND status = 'accepted'");
    $stmt->execute([$member['id']]);
    $friendCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) { /* vor Migration */ }

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container reveal">
        <div class="panel-hero">
            <?= avatar_html($member, 'avatar-lg') ?>
            <div>
                <h1><?= e($member['display_name'] ?: $member['username']) ?></h1>
                <p class="muted">@<?= e($member['username']) ?> · Mitglied seit <?= date('d.m.Y', strtotime($member['created_at'])) ?> · <a href="<?= SITE_URL ?>/freunde.php"><?= $friendCount ?> Freunde</a>
                <?php if ($myBadges): ?><br><?php foreach ($myBadges as $bk => $bd): ?><span class="badge" title="<?= e($bd[2]) ?>"><?= $bd[0] ?> <?= e($bd[1]) ?></span> <?php endforeach; ?><?php endif; ?></p>
            <div class="level-bar-wrap" title="Level <?= $myLevel['level'] ?> · <?= $myPoints ?> Punkte">
                <div class="level-bar"><div class="level-fill" style="width:<?= $myLevel['pct'] ?>%"></div></div>
                <span class="small">Level <?= $myLevel['level'] ?> · <?= $myPoints ?> Punkte (<?= $myLevel['pct'] ?>%)</span>
            </div>
                <div class="profile-actions">
                    <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($member['username']) ?>">Mein öffentliches Profil</a>
                    <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/galerie.php?u=<?= urlencode($member['username']) ?>">Meine Galerie</a>
                    <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/shop.php">🛒 Punkte-Shop</a>
                    <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/badges.php">🏅 Meine Badges</a>
                    <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/rad.php">🎰 Glücksrad</a>
                    <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/freunde.php">Freunde verwalten</a>
                    <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/nachrichten.php">Nachrichten</a>
                </div>
            </div>
        </div>
    </div>
</section>
<section class="section">
    <div class="container">
        <div class="grid grid-2">
            <div class="form-stack">
                <form method="post" class="card glass form-stack reveal">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile">
                    <h3>Mein Profil</h3>
                    <label>Anzeigename
                        <input type="text" name="display_name" value="<?= e($member['display_name']) ?>" maxlength="100">
                    </label>
                    <label>Kurzbeschreibung (max. 500 Zeichen)
                        <textarea name="bio" rows="3" maxlength="500"><?= e($member['bio']) ?></textarea>
                    </label>
                    <div class="form-grid">
                        <label>Wohnort <input type="text" name="location" value="<?= e($member['location']) ?>" maxlength="100"></label>
                        <label>Geburtstag <input type="date" name="birthday" value="<?= e($member['birthday']) ?>"></label>
                        <label class="span-2">Website <input type="url" name="website" value="<?= e($member['website']) ?>" placeholder="https://…"></label>
                        <label>Lieblingsspiel <input type="text" name="favorite_game" value="<?= e($member['favorite_game']) ?>" maxlength="100"></label>
                        <label>Discord-Tag <input type="text" name="discord_tag" value="<?= e($member['discord_tag']) ?>" placeholder="name#1234"></label>
                        <?php foreach ($customFields as $f): $fv = (string)($customValues[$f['id']] ?? ''); ?>
                        <label><?= e($f['label']) ?>
                            <?php if ($f['field_type'] === 'url'): ?>
                                <input type="url" name="field_<?= (int)$f['id'] ?>" value="<?= e($fv) ?>" placeholder="https://…">
                            <?php elseif ($f['field_type'] === 'number'): ?>
                                <input type="number" name="field_<?= (int)$f['id'] ?>" value="<?= e($fv) ?>">
                            <?php elseif ($f['field_type'] === 'date'): ?>
                                <input type="date" name="field_<?= (int)$f['id'] ?>" value="<?= e($fv) ?>">
                            <?php else: ?>
                                <input type="text" name="field_<?= (int)$f['id'] ?>" value="<?= e($fv) ?>" maxlength="500">
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="avatar-picker">
                        <label>Profilfarbe (Fallback ohne Bild)
                            <input type="color" id="avatarColor" name="avatar_color" value="<?= e($member['avatar_color']) ?>">
                        </label>
                        <span id="avatarPreview"><?= avatar_html($member) ?></span>
                    </div>
                    <label class="check">
                        <input type="checkbox" name="is_public" <?= $member['is_public'] ? 'checked' : '' ?>>
                        Öffentliches Profil (Mitgliederliste &amp; Profilseite)
                    </label>
                    <label class="check">
                        <input type="checkbox" name="show_online" <?= $member['show_online'] ? 'checked' : '' ?>>
                        🟢 In Chat-Online-Listen als „online" anzeigen
                    </label>
                    <button class="btn" type="submit">Profil speichern</button>
                </form>

                <div class="card glass form-stack reveal">
                    <h3>Profilbild</h3>
                    <form method="post" enctype="multipart/form-data" class="form-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="avatar">
                        <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" required>
                        <button class="btn btn-sm" type="submit">Hochladen</button>
                    </form>
                    <p class="muted small">JPG, PNG, GIF oder WEBP · max. 2 MB · wird auf 256×256 verkleinert.</p>
                    <?php if (trim((string)$member['avatar']) !== ''): ?>
                    <form method="post" data-confirm="Profilbild wirklich entfernen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="avatar_delete">
                        <button class="btn btn-ghost btn-sm" type="submit">Profilbild entfernen</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-stack">
                <div class="card glass form-stack reveal">
                    <h3>🎁 Tagesbonus</h3>
                    <?php if ($bonusToday): ?>
                        <p class="muted">Bereits abgeholt – Streak: <strong><?= (int)$bonusStreak ?> Tag(e)</strong>. Morgen wieder!</p>
                    <p class="muted small">❄️ Freezer verfügbar: <strong><?= (int)$member['streak_freeze'] ?></strong></p>
                    <?php else: ?>
                        <p class="muted small">Täglich abholen – der Streak bringt bis zu +7 Extra-Punkte.</p>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="daily_bonus">
                            <button class="btn" type="submit">Bonus abholen (+10 Punkte)</button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="card glass form-stack reveal">
                    <h3>🔐 Zwei-Faktor-Auth (2FA)</h3>
                    <p class="muted small">Zusätzlicher 6-stelliger Code per E-Mail bei jedem Login.</p>
                    <p><span class="badge <?= $member['twofa_enabled'] ? 'badge-green' : 'badge-red' ?>"><?= $member['twofa_enabled'] ? 'aktiviert' : 'deaktiviert' ?></span></p>
                    <form method="post" data-confirm="<?= $member['twofa_enabled'] ? '2FA wirklich deaktivieren?' : '' ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_2fa">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= $member['twofa_enabled'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
                    </form>
                </div>

                <?php if (plugin_active('referral')): ?>
                <div class="card glass form-stack reveal">
                    <h3>🤝 Mitglieder werben</h3>
                    <p class="muted small">Für jeden, der über deinen Link beitritt: <strong>+<?= (int)setting('plugin_referral_points', '25') ?> Punkte</strong>. Bisher geworben: <?= $refCount ?></p>
                    <input type="text" readonly class="mono" value="<?= SITE_URL ?>/register.php?ref=<?= urlencode($member['username']) ?>" onclick="this.select()" style="font-size:.8rem">
                </div>
                <?php endif; ?>

                <div class="card glass form-stack reveal">
                    <h3>🖼️ Titelbild (Profil-Hintergrund)</h3>
                    <form method="post" enctype="multipart/form-data" class="form-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cover">
                        <input type="file" name="cover" accept="image/jpeg,image/png,image/webp" required>
                        <button class="btn btn-sm" type="submit">Hochladen</button>
                    </form>
                    <p class="muted small">Empfohlen: 1600×500 px · JPG/PNG/WEBP, max. 5 MB.<br>
            📍 <strong>Sichtbar auf deiner Profilseite</strong> (<?= SITE_URL ?>/profil.php?u=<?= urlencode($member['username']) ?>)</p>
                    <?php if (!empty($member['cover'])): ?>
                    <form method="post" data-confirm="Titelbild entfernen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cover_delete">
                        <button class="btn btn-ghost btn-sm" type="submit">Titelbild entfernen</button>
                    </form>
                    <?php endif; ?>
                </div>

                <form method="post" class="card glass form-stack reveal">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="custom_status">
                    <h3>💬 Mein Status</h3>
                    <input type="text" name="status" maxlength="120" value="<?= e($member['custom_status'] ?? '') ?>" placeholder="z. B. Bin grad am Zocken 🎮">
                    <button class="btn btn-sm" type="submit">Status setzen</button>
                </form>

                <form method="post" class="card glass form-stack reveal">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile_song">
                    <h3>🎵 Profil-Song</h3>
                    <p class="muted small">YouTube- oder Spotify-Link – erscheint als Player auf deinem Profil.</p>
                    <input type="url" name="song_url" value="<?= e($member['profile_song'] ?? '') ?>" placeholder="https://youtube.com/watch?v=… oder https://open.spotify.com/track/…">
                    <button class="btn btn-sm" type="submit">Speichern</button>
                </form>

                <form method="post" class="card glass form-stack reveal">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="extra_settings">
                    <h3>✍️ Signatur &amp; Benachrichtigungen</h3>
                    <label>Forum-Signatur (unter deinen Antworten, max. 300 Zeichen)
                        <textarea name="forum_signature" rows="2" maxlength="300"><?= e($member['forum_signature']) ?></textarea>
                    </label>
                    <label class="check">
                        <input type="checkbox" name="notify_email" <?= !empty($member['notify_email']) ? 'checked' : '' ?>>
                        📧 Tägliche E-Mail-Zusammenfassung bei neuen Benachrichtigungen
                    </label>
                    <div class="form-grid">
                        <label>🔕 Push-Ruhezeit von <input type="time" name="quiet_start" value="<?= e($member['quiet_start'] ?? '') ?>"></label>
                        <label>bis <input type="time" name="quiet_end" value="<?= e($member['quiet_end'] ?? '') ?>"></label>
                    </div>
                    <p class="muted small">In dieser Zeit werden keine Push-Nachrichten gesendet (leer = immer erreichbar).</p>
                    <button class="btn" type="submit">Speichern</button>
                </form>

                <div class="card glass form-stack reveal">
                    <h3>🔖 Gespeicherte Beiträge</h3>
                    <?php
                    $bmPosts = db()->query("SELECT w.id, LEFT(w.content, 60) AS snippet, w.created_at
                        FROM wall_bookmarks b JOIN wall_posts w ON w.id = b.post_id
                        WHERE b.user_id = " . (int)$member['id'] . " ORDER BY b.created_at DESC LIMIT 5")->fetchAll();
                    if ($bmPosts):
                        foreach ($bmPosts as $bp): ?>
                    <a class="topic-row" href="<?= SITE_URL ?>/wall.php#post-<?= (int)$bp['id'] ?>">
                        <div class="topic-meta"><strong><?= e($bp['snippet']) ?>…</strong>
                        <span class="muted small"><?= date('d.m.Y', strtotime($bp['created_at'])) ?></span></div>
                    </a>
                    <?php endforeach;
                    else: ?><p class="muted small">Noch keine gespeicherten Beiträge – auf der Wall ein Lesezeichen setzen! 🔖</p><?php endif; ?>
                </div>

                <form method="post" class="card glass form-stack reveal" data-confirm="Alle deine Daten als JSON-Datei herunterladen?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="export_data">
                    <h3>🔐 Meine Daten (DSGVO)</h3>
                    <p class="muted small">Lade eine vollständige Kopie aller deiner gespeicherten Daten herunter (Profil, Beiträge, Nachrichten …).</p>
                    <button class="btn btn-ghost btn-sm" type="submit">📥 Daten exportieren (JSON)</button>
                </form>

                <form method="post" class="card glass form-stack reveal">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">
                    <h3>Passwort ändern</h3>
                    <label>Aktuelles Passwort <input type="password" name="current_password" required></label>
                    <label>Neues Passwort (min. 8 Zeichen) <input type="password" name="new_password" required></label>
                    <button class="btn" type="submit">Passwort ändern</button>
                </form>

                <form method="post" class="card glass form-stack reveal" data-confirm="Konto wirklich endgültig löschen?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <h3>Konto löschen</h3>
                    <p class="muted small">Dein Profil, Freundschaften, Nachrichten und Meldungen werden unwiderruflich entfernt. Zum Bestätigen dein Passwort eingeben.</p>
                    <label>Passwort <input type="password" name="confirm_password" required></label>
                    <button class="btn btn-danger" type="submit">Konto endgültig löschen</button>
                </form>
            </div>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
