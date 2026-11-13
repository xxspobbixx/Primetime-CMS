<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
$current = 'shop';
$pageTitle = 'Punkte-Shop';
$me = current_member();
$meId = (int)$me['id'];

// Frische Punkte direkt aus der DB
$stmt = db()->prepare('SELECT points FROM users WHERE id = ?');
$stmt->execute([$meId]);
$myPoints = (int)$stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $key = (string)($_POST['item'] ?? '');
    $items = shop_items();
    if (!isset($items[$key])) {
        flash('error', 'Artikel nicht gefunden.');
    } else {
        $item = $items[$key];
        if ($action === 'buy' && $item['type'] === 'freeze') {
            // Verbrauchsartikel: sofort +1 Freezer, kein user_shop-Eintrag
            $upd = db()->prepare('UPDATE users SET points = points - ?, streak_freeze = streak_freeze + 1 WHERE id = ? AND points >= ?');
            $upd->execute([$item['cost'], $meId, $item['cost']]);
            if ($upd->rowCount() === 0) {
                flash('error', 'Nicht genug Punkte!');
            } else {
                flash('success', '❄️ Streak-Freezer gekauft! Schützt einmal vor Streak-Verlust.');
            }
            header('Location: shop.php');
            exit;
        } elseif ($action === 'buy') {
            // Punkte-Abzug atomar (nur wenn genug da)
            $upd = db()->prepare('UPDATE users SET points = points - ? WHERE id = ? AND points >= ?');
            $upd->execute([$item['cost'], $meId, $item['cost']]);
            if ($upd->rowCount() === 0) {
                flash('error', 'Nicht genug Punkte! (Benötigt: ' . $item['cost'] . ')');
            } else {
                db()->prepare('INSERT INTO user_shop (user_id, item_key, equipped) VALUES (?, ?, 1)
                               ON DUPLICATE KEY UPDATE equipped = 1')
                    ->execute([$meId, $key]);
                apply_shop_item($meId, $item);
                if (plugin_active('konfetti')) { $_SESSION['confetti'] = true; }
                flash('success', 'Gekauft &amp; aktiviert: ' . $item['name'] . ' 🎉');
            }
        } elseif ($action === 'gift') {
            $to = find_user_by_username(trim((string)($_POST['to'] ?? '')));
            if (!$to || (int)$to['id'] === $meId) {
                flash('error', 'Empfänger nicht gefunden.');
            } else {
                $upd = db()->prepare('UPDATE users SET points = points - ? WHERE id = ? AND points >= ?');
                $upd->execute([$item['cost'], $meId, $item['cost']]);
                if ($upd->rowCount() === 0) {
                    flash('error', 'Nicht genug Punkte!');
                } else {
                    db()->prepare('INSERT INTO user_shop (user_id, item_key, equipped) VALUES (?, ?, 1)
                                   ON DUPLICATE KEY UPDATE equipped = 1')->execute([(int)$to['id'], $key]);
                    apply_shop_item((int)$to['id'], $item);
                    notify((int)$to['id'], 'gift', $meId,
                        ($me['display_name'] ?: $me['username']) . ' hat dir „' . $item['name'] . '" geschenkt! 🎁',
                        SITE_URL . '/profil.php?u=' . urlencode($to['username']));
                    flash('success', 'Geschenkt an @' . $to['username'] . '! 🎁');
                }
            }
        } elseif ($action === 'refund') {
            $stmt = db()->prepare('SELECT purchased_at FROM user_shop WHERE user_id = ? AND item_key = ?');
            $stmt->execute([$meId, $key]);
            $row = $stmt->fetch();
            if (!$row) { flash('error', 'Item nicht im Besitz.'); }
            elseif (strtotime($row['purchased_at']) < time() - 600) { flash('error', 'Rückgabe nur innerhalb von 10 Minuten.'); }
            else {
                db()->prepare('DELETE FROM user_shop WHERE user_id = ? AND item_key = ?')->execute([$meId, $key]);
                apply_shop_item($meId, $item, true);
                add_points($meId, $item['cost']);
                flash('success', 'Zurückgegeben: +' . $item['cost'] . ' Punkte.');
            }
        } elseif ($action === 'toggle') {
            $owned = user_shop_items($meId);
            if (!isset($owned[$key])) {
                flash('error', 'Artikel nicht im Besitz.');
            } else {
                $new = $owned[$key] ? 0 : 1;
                db()->prepare('UPDATE user_shop SET equipped = ? WHERE user_id = ? AND item_key = ?')
                    ->execute([$new, $meId, $key]);
                if ($new) { apply_shop_item($meId, $item); flash('success', 'Aktiviert: ' . $item['name']); }
                else { apply_shop_item($meId, $item, true); flash('success', 'Deaktiviert: ' . $item['name']); }
            }
        }
    }
    header('Location: shop.php');
    exit;
}

/** Trägt ein Shop-Item in die User-Spalten ein (oder entfernt es). */
function apply_shop_item(int $uid, array $item, bool $remove = false): void
{
    $col = ['color' => 'name_color', 'title' => 'custom_title', 'frame' => 'profile_frame'][$item['type']] ?? null;
    if ($col === null) return;
    db()->prepare("UPDATE users SET $col = ? WHERE id = ?")->execute([$remove ? null : $item['value'], $uid]);
}

$owned = user_shop_items($meId);
$byType = ['color' => [], 'title' => [], 'frame' => [], 'glow' => []];
foreach (shop_items() as $key => $it) {
    if (!empty($it['season']) && date('n') !== $it['season']) continue;
    $byType[$it['type']][$key] = $it;
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal">
    <h1>🛒 Punkte-Shop</h1>
    <p class="muted">Gib deine hart verdienten Punkte für Extras aus! Dein Kontostand: <strong>⭐ <?= $myPoints ?> Punkte</strong></p></div></section>
<section class="section"><div class="container">
    <?php
    $sections = ['color' => ['🎨 Namensfarben', 'Färbe deinen Namen auf der ganzen Seite.'],
                 'title' => ['👑 Titel', 'Exklusiver Titel unter deinem Namen.'],
                 'frame' => ['✨ Profilrahmen', 'Dein Profil im besonderen Glanz.'],
                 'glow'  => ['💫 Effekte', 'Besondere Hervorhebungen.']];
    $byType['freeze'] = []; // Verbrauchsartikel werden separat behandelt
    foreach ($sections as $type => [$headline, $desc]):
    ?>
    <h3 style="margin-bottom:1rem"><?= $headline ?></h3>
    <p class="muted small" style="margin-bottom:1rem"><?= $desc ?></p>
    <div class="grid grid-3" style="margin-bottom:2rem">
        <?php foreach ($byType[$type] as $key => $it):
            $has = isset($owned[$key]);
            $eq = $has && $owned[$key];
        ?>
        <div class="card glass reveal shop-card">
            <div class="shop-preview">
                <?php if ($type === 'color'): ?>
                    <span style="color:<?= e($it['value']) ?>;font-size:1.3rem;font-weight:700">Dein Name</span>
                <?php elseif ($type === 'glow'): ?>
                    <span class="glow-demo" style="color:var(--text)">✨ Dein Name</span>
                <?php elseif ($type === 'title'): ?>
                    <span style="font-size:1.2rem;font-weight:700">Dein Name</span><br>
                    <span class="badge badge-green">👑 <?= e($it['value']) ?></span>
                <?php else: ?>
                    <span class="profile-frame-demo frame-<?= e($it['value']) ?>">Profil</span>
                <?php endif; ?>
            </div>
            <h4 style="margin:.7rem 0 .2rem"><?= e($it['name']) ?></h4>
            <p class="small" style="color:var(--accent-2);font-weight:700">⭐ <?= $it['cost'] ?> Punkte</p>
            <?php if (!$has): ?>
                <form method="post" data-confirm="<?= $it['cost'] ?> Punkte ausgeben?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="buy">
                    <input type="hidden" name="item" value="<?= e($key) ?>">
                    <button class="btn btn-sm" type="submit" <?= $myPoints < $it['cost'] ? 'disabled title="Nicht genug Punkte"' : '' ?>>Kaufen</button>
                </form>
            <?php else: ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="item" value="<?= e($key) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit"><?= $eq ? 'Deaktivieren' : 'Aktivieren' ?></button>
                </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <div class="card glass reveal">
        <h3>💡 Wie komme ich an Punkte?</h3>
        <ul class="prose small">
            <li>🎁 Täglicher Login-Bonus (mit Streak bis +17)</li>
            <li>📋 Wall-Beitrag: +5 · 💬 Kommentar: +2 · ❤️ Reaktion: +1</li>
            <li>🗂️ Foren-Thema: +10 · Antwort: +5 · ✉️ Nachricht: +1</li>
            <li>🤝 Freunde werben: +25 pro geworbenem Mitglied</li>
        </ul>
    </div>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
