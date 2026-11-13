<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
$current = 'badges';
$pageTitle = 'Badges';
$me = current_member();

check_badges((int)$me['id']);
$defs = badge_defs();
$earned = user_badges((int)$me['id']);
$progress = badge_progress((int)$me['id']);

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal">
    <h1>🏅 Badges</h1>
    <p class="muted">Deine Trophäen-Sammlung – <?= count($earned) ?> von <?= count($defs) ?> freigeschaltet.</p></div></section>
<section class="section"><div class="container">
    <div class="grid grid-3">
        <?php foreach ($defs as $key => [$icon, $label, $desc]):
            $has = isset($earned[$key]);
            $prog = $progress[$key] ?? null;
        ?>
        <div class="card glass reveal badge-card <?= $has ? 'badge-earned' : 'badge-locked' ?>">
            <div class="badge-big"><?= $icon ?></div>
            <h3><?= e($label) ?></h3>
            <p class="muted small"><?= e($desc) ?></p>
            <?php if ($has): ?>
                <span class="badge badge-green">✔ Freigeschaltet</span>
            <?php elseif ($prog !== null):
                $pct = min(100, (int)round($prog[0] / max(1, $prog[1]) * 100)); ?>
                <div class="poll-bar" style="margin:.4rem 0"><div class="poll-fill" style="width:<?= max(3, $pct) ?>%"></div></div>
                <span class="muted small"><?= $prog[0] ?>/<?= $prog[1] ?> · <?= $pct ?>%</span>
            <?php else: ?>
                <span class="muted small">🕐 Kommt mit der Zeit…</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
