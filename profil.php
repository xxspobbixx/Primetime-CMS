<?php
require_once __DIR__ . '/includes/functions.php';
$current = '';
$pageTitle = 'Profil';
require_member();
$me = current_member();

$username = trim((string)($_GET['u'] ?? ''));
$user = $username !== '' ? find_user_by_username($username) : null;

// Freundschafts-Aktionen (nur eingeloggt, nicht auf dem eigenen Profil)
if ($user && $me && (int)$me['id'] !== (int)$user['id'] && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'request' && friendship_status((int)$me['id'], (int)$user['id']) === null) {
        friend_request((int)$me['id'], (int)$user['id']);
        notify((int)$user['id'], 'friend', (int)$me['id'],
            ($me['display_name'] ?: $me['username']) . ' möchte dich als Freund:in hinzufügen.',
            SITE_URL . '/freunde.php');
        flash('success', 'Freundschaftsanfrage gesendet.');
    } elseif ($action === 'accept' && friendship_status((int)$me['id'], (int)$user['id']) === 'pending_in') {
        friend_accept((int)$me['id'], (int)$user['id']);
        notify((int)$user['id'], 'friend_accept', (int)$me['id'],
            ($me['display_name'] ?: $me['username']) . ' hat deine Freundschaftsanfrage angenommen.',
            SITE_URL . '/profil.php?u=' . urlencode($me['username']));
        flash('success', 'Ihr seid jetzt Freunde!');
    } elseif (($action === 'remove' || $action === 'decline')) {
        friend_remove((int)$me['id'], (int)$user['id']);
        flash('success', 'Erledigt.');
    }
    header('Location: ' . SITE_URL . '/profil.php?u=' . urlencode($user['username']));
    exit;
}

$status = ($user && $me && (int)$me['id'] !== (int)$user['id'])
    ? friendship_status((int)$me['id'], (int)$user['id'])
    : null;

// Admin: Gruppe/Rolle direkt im Profil vergeben
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_assign' && $me && $me['role'] === 'admin' && $user) {
    csrf_check();
    $g = trim((string)($_POST['user_group'] ?? ''));
    $r = trim((string)($_POST['role'] ?? ''));
    if ($g !== '' && !array_key_exists($g, user_group_list())) $g = '';
    if (!array_key_exists($r, role_list())) $r = 'member';
    if ((int)$user['id'] !== (int)$me['id'] || $r === 'admin') {
        db()->prepare('UPDATE users SET user_group = ?, role = ? WHERE id = ?')
            ->execute([$g !== '' ? $g : null, $r, $user['id']]);
        mod_log('Gruppe/Rolle im Profil vergeben', '@' . $user['username'] . ' → ' . ($g ?: '—') . '/' . $r);
        flash('success', 'Gruppe & Rolle aktualisiert.');
    }
    header('Location: ' . SITE_URL . '/profil.php?u=' . urlencode($user['username']));
    exit;
}

// Öffentliches Profil? (Eigene Ansicht + Admins dürfen immer sehen)
$canView = $user && ((int)$user['is_public'] === 1
    || ($me && ((int)$me['id'] === (int)$user['id'] || $me['role'] === 'admin')));

$customFields = profile_fields_active();
$customValues = $user ? get_profile_values((int)$user['id']) : [];
$gallery = [];
if ($user && $canView) {
    $stmt = db()->prepare("SELECT id, image, created_at FROM wall_posts WHERE user_id = ? AND image IS NOT NULL AND image != '' ORDER BY created_at DESC");
    $stmt->execute([$user['id']]);
    $gallery = $stmt->fetchAll();
    // Likes (like+love+fire+haha+wow) und Kommentare pro Bild zählen
    $galCounts = ['likes' => [], 'comments' => []];
    if ($gallery) {
        $ids = implode(',', array_map('intval', array_column($gallery, 'id')));
        foreach (db()->query("SELECT post_id, COUNT(*) AS c FROM wall_reactions WHERE post_id IN ($ids) GROUP BY post_id") as $r) {
            $galCounts['likes'][$r['post_id']] = (int)$r['c'];
        }
        foreach (db()->query("SELECT post_id, COUNT(*) AS c FROM wall_comments WHERE post_id IN ($ids) GROUP BY post_id") as $r) {
            $galCounts['comments'][$r['post_id']] = (int)$r['c'];
        }
    }
}

// Besucherliste (nur Eigentümer) + gemeinsame Freunde laden
$visitors = [];
$mutualFriends = [];
if ($user) {
    if ($me && (int)$me['id'] === (int)$user['id']) {
        $visitors = db()->query("SELECT v.created_at, u.username, u.display_name, u.avatar, u.avatar_color, u.name_color
            FROM profile_visits v JOIN users u ON u.id = v.visitor_id
            WHERE v.profile_id = " . (int)$user['id'] . " ORDER BY v.id DESC LIMIT 5")->fetchAll();
    }
    if ($me && (int)$me['id'] !== (int)$user['id']) {
        $mutualFriends = db()->query("SELECT u.username, u.display_name, u.avatar, u.avatar_color, u.name_color
            FROM friendships f1
            JOIN friendships f2 ON f2.friend_id = f1.friend_id AND f2.user_id = " . (int)$me['id'] . " AND f2.status = 'accepted'
            JOIN users u ON u.id = f1.friend_id
            WHERE f1.user_id = " . (int)$user['id'] . " AND f1.status = 'accepted' LIMIT 6")->fetchAll();
    }
}

include __DIR__ . '/includes/header.php';
?>
<?php if ($user && $canView && !empty($user['cover'])): ?>
<div class="profile-cover" style="height:220px;max-height:28vh;background-size:cover;background-position:center 40%;background-image:url('<?= SITE_URL ?>/uploads/avatars/<?= rawurlencode($user['cover']) ?>')"></div>
<?php endif; ?>
<section class="page-hero">
    <div class="container reveal">
        <?php if (!$user): ?>
            <h1>Profil nicht gefunden</h1>
            <p class="muted">Dieses Mitglied existiert nicht (mehr).</p>
            <p style="margin-top:1rem"><a class="btn" href="<?= SITE_URL ?>/mitglieder.php">Zur Mitgliederliste</a></p>
        <?php elseif (!$canView): ?>
            <h1>🔒 Privates Profil</h1>
            <p class="muted"><?= e($user['display_name'] ?: $user['username']) ?> hat das Profil auf privat gestellt.</p>
        <?php else: ?>
        <div class="profile-hero<?= !empty($user['profile_frame']) ? ' profile-frame-' . e($user['profile_frame']) : '' ?>">
            <?= avatar_html($user, 'avatar-lg') ?>
            <div>
                <h1<?= !empty($user['name_color']) ? ' style="color:' . e($user['name_color']) . '"' : '' ?>><?= e($user['display_name'] ?: $user['username']) ?>
                    <?php if (!empty($user['is_verified'])): ?><span class="badge badge-green" title="Verifiziertes Mitglied">✔ verifiziert</span><?php endif; ?>
                    <?= role_badge($user['role']) ?>
                    <?= user_group_badge($user['user_group'] ?? null) ?>
                <?php if (!empty($user['custom_status'])): ?>
                    <p style="font-size:.95rem;color:var(--accent-2);margin-top:.2rem">💬 <?= e($user['custom_status']) ?></p>
                <?php endif; ?>
                    <?php if (!empty($user['custom_title'])): ?><span class="profile-title-badge">👑 <?= e($user['custom_title']) ?></span><?php endif; ?>
                    <?php if ($status === 'friends'): ?><span class="badge badge-green">Freund</span><?php endif; ?>
                </h1>
                <p class="muted">@<?= e($user['username']) ?> · Mitglied seit <?= date('d.m.Y', strtotime($user['created_at'])) ?></p>
                <?php if ($me && (int)$me['id'] === (int)$user['id']): ?>
                    <div class="profile-actions">
                        <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/panel.php">Profil bearbeiten</a>
                    </div>
                <?php elseif ($me): ?>
                    <div class="profile-actions">
                        <?php if ($status === null): ?>
                            <form method="post"><?= csrf_field() ?><button class="btn btn-sm" name="action" value="request">➕ Freund:in hinzufügen</button></form>
                        <?php elseif ($status === 'pending_out'): ?>
                            <button class="btn btn-ghost btn-sm" disabled>⏳ Anfrage gesendet</button>
                        <?php elseif ($status === 'pending_in'): ?>
                            <form method="post"><?= csrf_field() ?><button class="btn btn-sm" name="action" value="accept">✔ Anfrage annehmen</button></form>
                            <form method="post"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" name="action" value="decline">Ablehnen</button></form>
                        <?php elseif ($status === 'friends'): ?>
                            <button class="btn btn-ghost btn-sm" disabled>✔ Ihr seid Freunde</button>
                            <form method="post" data-confirm="Freundschaft wirklich beenden?"><?= csrf_field() ?><button class="btn btn-danger btn-sm" name="action" value="remove">Entfernen</button></form>
                        <?php endif; ?>
                        <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/nachrichten.php?with=<?= urlencode($user['username']) ?>">✉ Nachricht senden</a>
                        <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/melden.php?u=<?= urlencode($user['username']) ?>">🚩 Melden</a>
                    </div>
                <?php else: ?>
                    <div class="profile-actions">
                        <a class="btn btn-sm" href="<?= SITE_URL ?>/login.php">Anmelden zum Interagieren</a>
                        <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/register.php">Registrieren</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    
        <?php if ($me && $me['role'] === 'admin' && (int)$me['id'] !== (int)$user['id']): ?>
        <div class="card glass" style="margin-top:1rem;padding:.9rem 1.1rem;font-size:.85rem;text-align:left">
            <strong>🛠️ Admin: Gruppe &amp; Rolle</strong>
            <form method="post" class="form-inline" style="margin-top:.5rem">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="admin_assign">
                <select name="user_group" style="padding:.4rem .5rem;font-size:.85rem;margin-bottom:0">
                    <option value="">👥 Keine Gruppe</option>
                    <?php foreach (user_group_list() as $gKey => [$gIcon, $gLabel]): ?>
                    <option value="<?= e($gKey) ?>" <?= ($user['user_group'] ?? '') === $gKey ? 'selected' : '' ?>><?= $gIcon ?> <?= e($gLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="role" style="padding:.4rem .5rem;font-size:.85rem;margin-bottom:0">
                    <?php foreach (role_list() as $rKey => [$rIcon, $rLabel, $rDesc]): ?>
                    <option value="<?= e($rKey) ?>" <?= $user['role'] === $rKey ? 'selected' : '' ?>><?= $rIcon ?> <?= e($rLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm" type="submit">Speichern</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($user && $canView): ?>
<section class="section">
    <div class="container">
        <div class="grid grid-2">
            <div class="card glass reveal">
                <h3>Über mich</h3>
                <?php
                    $uPoints = total_points((int)$user['id']);
                    $uLvl = level_progress($uPoints);
                    $uBadges = user_badges((int)$user['id']);
                ?>
                <div class="level-bar-wrap" title="Level <?= $uLvl['level'] ?> · <?= $uPoints ?> Punkte">
                    <div class="level-bar"><div class="level-fill" style="width:<?= $uLvl['pct'] ?>%"></div></div>
                    <span class="small">Level <?= $uLvl['level'] ?> · <?= $uPoints ?> Punkte · <?= rank_title(rank_points((int)$user['id'])) ?></span>
                </div>
                <?php if ($uBadges): ?>
                <div style="margin:.8rem 0">
                    <?php foreach ($uBadges as $bk => $bd): ?><span class="badge" title="<?= e($bd[2]) ?>"><?= $bd[0] ?> <?= e($bd[1]) ?></span> <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php
                // Selbstdiagnose (nur für den Eigentümer sichtbar)
                if ($me && (int)$me['id'] === (int)$user['id']):
                    $diagColor = trim((string)($user['name_color'] ?? ''));
                    $diagTitle = trim((string)($user['custom_title'] ?? ''));
                    $diagFrame = trim((string)($user['profile_frame'] ?? ''));
                    $diagCover = trim((string)($user['cover'] ?? ''));
                    $coverFile = $diagCover !== '' ? (APP_ROOT . '/uploads/avatars/' . $diagCover) : '';
                    $coverOk = $coverFile !== '' && is_file($coverFile);
                ?>
                <div class="card glass" style="margin-top:1rem;border-color:rgba(240,200,80,.4);font-size:.82rem">
                    <strong>🔧 Shop-Diagnose (nur für dich sichtbar)</strong>
                    <ul style="margin:.5rem 0 0 1.2rem;color:var(--muted)">
                        <li>Namensfarbe in DB: <?= $diagColor !== '' ? '<code style="color:' . e($diagColor) . '">' . e($diagColor) . '</code>' : '<span class="badge badge-red">LEER</span>' ?></li>
                        <li>Titel in DB: <?= $diagTitle !== '' ? e($diagTitle) : '<span class="badge badge-red">LEER</span>' ?></li>
                        <li>Rahmen in DB: <?= $diagFrame !== '' ? e($diagFrame) : '<span class="badge badge-red">LEER</span>' ?></li>
                        <li>Banner in DB: <?= $diagCover !== '' ? e($diagCover) : '<span class="badge badge-red">LEER</span>' ?>
                            <?= $diagCover !== '' ? ($coverOk ? ' · Datei ✅' : ' · Datei ❌ (fehlt auf Server!)') : '' ?></li>
                    </ul>
                    <p class="muted small" style="margin-top:.5rem">Wenn hier Werte stehen, aber nichts angezeigt wird: Browser-Cache leeren (Strg+Shift+Entf). Wenn hier LEER steht: Items im Shop erneut aktivieren.</p>
                </div>
                <?php endif; ?>
                <?php if (trim((string)$user['bio']) !== ''): ?>
                    <div class="prose"><p><?= nl2br(e($user['bio'])) ?></p></div>
                <?php else: ?>
                    <p class="muted">Noch keine Beschreibung vorhanden.</p>
                <?php endif; ?>
            </div>
            <div class="card glass reveal">
                <h3>Details</h3>
                <ul class="profile-fields">
                    <?php if (trim((string)$user['location']) !== ''): ?>
                        <li><span>📍 Wohnort</span><strong><?= e($user['location']) ?></strong></li><?php endif; ?>
                    <?php if (trim((string)$user['favorite_game']) !== ''): ?>
                        <li><span>🎮 Lieblingsspiel</span><strong><?= e($user['favorite_game']) ?></strong></li><?php endif; ?>
                    <?php if (trim((string)$user['discord_tag']) !== ''): ?>
                        <li><span>💬 Discord</span><strong><?= e($user['discord_tag']) ?></strong></li><?php endif; ?>
                    <?php if (trim((string)$user['website']) !== ''): ?>
                        <li><span>🌐 Website</span><a href="<?= e($user['website']) ?>" target="_blank" rel="noopener"><?= e($user['website']) ?></a></li><?php endif; ?>
                    <?php if (trim((string)$user['birthday']) !== ''): ?>
                        <li><span>🎂 Geburtstag</span><strong><?= date('d.m.Y', strtotime($user['birthday'])) ?></strong></li><?php endif; ?>
                    <?php foreach ($customFields as $f): $v = trim((string)($customValues[$f['id']] ?? '')); if ($v === '') continue; ?>
                    <li><span>🏷️ <?= e($f['label']) ?></span>
                        <?php if ($f['field_type'] === 'url'): ?>
                            <a href="<?= e($v) ?>" target="_blank" rel="noopener"><?= e($v) ?></a>
                        <?php else: ?>
                            <strong><?= e($v) ?></strong>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                    <li><span>📅 Mitglied seit</span><strong><?= date('d.m.Y', strtotime($user['created_at'])) ?></strong></li>
                    <li><span>👀 Profilaufrufe</span><strong><?= (int)($user['profile_views'] ?? 0) ?></strong></li>
                </ul>

                <?php if (!empty($visitors)): ?>
                <div style="margin-top:1rem;border-top:1px dashed var(--border);padding-top:.8rem">
                    <h4 style="font-size:.92rem;margin-bottom:.5rem">👀 Letzte Besucher <span class="muted small">(nur du siehst das)</span></h4>
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                        <?php foreach ($visitors as $v): ?>
                        <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($v['username']) ?>" title="@<?= e($v['username']) ?> · <?= date('d.m. H:i', strtotime($v['created_at'])) ?>" style="text-decoration:none">
                            <?= avatar_html($v) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($mutualFriends)): ?>
                <div style="margin-top:1rem;border-top:1px dashed var(--border);padding-top:.8rem">
                    <h4 style="font-size:.92rem;margin-bottom:.5rem">🤝 Gemeinsame Freunde (<?= count($mutualFriends) ?>)</h4>
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                        <?php foreach ($mutualFriends as $mf): ?>
                        <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($mf['username']) ?>" title="@<?= e($mf['username']) ?>" style="text-decoration:none">
                            <?= avatar_html($mf) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($gallery): ?>
        <div class="card glass reveal" style="margin-top:1.5rem">
            <div class="card-head">
                <h3>🖼️ Galerie (<?= count($gallery) ?>)</h3>
                <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/galerie.php?u=<?= urlencode($user['username']) ?>">Große Ansicht öffnen →</a>
            </div>
            <div class="gallery-grid">
                <?php foreach ($gallery as $img):
                    $likes = $galCounts['likes'][$img['id']] ?? 0;
                    $comms = $galCounts['comments'][$img['id']] ?? 0;
                ?>
                <a class="gal-cell" href="<?= SITE_URL ?>/galerie.php?u=<?= urlencode($user['username']) ?>&post=<?= (int)$img['id'] ?>" title="<?= date('d.m.Y', strtotime($img['created_at'])) ?>">
                    <img src="<?= SITE_URL ?>/uploads/wall/<?= rawurlencode($img['image']) ?>" alt="Bild von <?= e($user['display_name'] ?: $user['username']) ?>" loading="lazy">
                    <?php if ($likes > 0 || $comms > 0): ?>
                    <span class="gal-badges">
                        <?php if ($likes > 0): ?><span>❤ <?= $likes ?></span><?php endif; ?>
                        <?php if ($comms > 0): ?><span>💬 <?= $comms ?></span><?php endif; ?>
                    </span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
