<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
$current = 'members';
$pageTitle = 'Mitglieder';

$q = trim((string)($_GET['q'] ?? ''));
$members = [];
$total = 0;
try {
    $sql = "SELECT username, display_name, bio, avatar, avatar_color, role, name_color, created_at
            FROM users WHERE is_public = 1";
    $params = [];
    if ($q !== '') {
        $sql .= " AND (username LIKE ? OR display_name LIKE ? OR bio LIKE ?)";
        $like = "%$q%";
        $params = [$like, $like, $like];
    }
    $sql .= " ORDER BY created_at ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll();
    $total = count($members);
} catch (Throwable $e) { /* vor Installation */ }

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container reveal">
        <h1>Mitglieder</h1>
        <p class="muted"><?= $total ?> Mitglied<?= $total === 1 ? '' : 'er' ?> in der Community.</p>
    </div>
</section>
<section class="section">
    <div class="container">
        <form method="get" class="search-bar reveal">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Mitglied suchen …">
            <button class="btn" type="submit">Suchen</button>
            <?php if ($q !== ''): ?><a class="btn btn-ghost" href="<?= SITE_URL ?>/mitglieder.php">Zurücksetzen</a><?php endif; ?>
        </form>

        <?php if (!$members): ?>
            <div class="card glass reveal"><p><?= $q !== '' ? 'Keine Mitglieder gefunden.' : 'Noch keine Mitglieder – <a href="' . SITE_URL . '/register.php">werde die erste Person!</a>' ?></p></div>
        <?php else: ?>
        <div class="grid grid-3">
            <?php foreach ($members as $m): ?>
            <a class="card glass member-card reveal" href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($m['username']) ?>">
                <?= avatar_html($m, 'avatar-md') ?>
                <div class="member-info">
                    <h3<?= name_color_style($m) ?>><?= e($memberName = $m['display_name'] ?: $m['username']) ?>
                        <?= role_badge($m['role']) ?>
                        <?= user_group_badge($m['user_group'] ?? null) ?>
                    </h3>
                    <span class="muted small">@<?= e($m['username']) ?> · seit <?= date('d.m.Y', strtotime($m['created_at'])) ?></span>
                    <?php if (trim((string)$m['bio']) !== ''): ?>
                        <p class="muted small"><?= e(mb_strimwidth($m['bio'], 0, 120, '…')) ?></p>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
