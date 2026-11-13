<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
ensure_feature_tables();
$current = 'rad';
$pageTitle = 'Glücksrad';
$me = current_member();
$meId = (int)$me['id'];

// 8 Gewinn-Segmente mit Gewichtung (Gesamt = 100)
$prizes = [
    ['label' => '5 Punkte',    'points' => 5,   'weight' => 22, 'color' => '#6c5ce7'],
    ['label' => '25 Punkte',   'points' => 25,  'weight' => 14, 'color' => '#00cec9'],
    ['label' => '10 Punkte',   'points' => 10,  'weight' => 20, 'color' => '#e17055'],
    ['label' => '50 Punkte',   'points' => 50,  'weight' => 10, 'color' => '#fdcb6e'],
    ['label' => '2 Punkte',    'points' => 2,   'weight' => 16, 'color' => '#636e72'],
    ['label' => '100 Punkte',  'points' => 100, 'weight' => 8,  'color' => '#e84393'],
    ['label' => '15 Punkte',   'points' => 15,  'weight' => 8,  'color' => '#0984e3'],
    ['label' => '💎 JACKPOT 250', 'points' => 250, 'weight' => 2, 'color' => '#f0c850'],
];

$alreadySpun = false;
$stmt = db()->prepare('SELECT prize, points FROM wheel_spins WHERE user_id = ? AND spin_date = CURDATE()');
$stmt->execute([$meId]);
$today = $stmt->fetch();

// ---------- AJAX: Drehen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'spin') {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_check_safe()) { echo json_encode(['error' => 'Ungültiger Token']); exit; }
    if ($today) { echo json_encode(['error' => 'Heute bereits gedreht!']); exit; }

    // Gewichtete Zufallsauswahl
    $total = array_sum(array_column($prizes, 'weight'));
    $rand = random_int(1, $total);
    $acc = 0; $winIdx = 0;
    foreach ($prizes as $i => $p) {
        $acc += $p['weight'];
        if ($rand <= $acc) { $winIdx = $i; break; }
    }
    $win = $prizes[$winIdx];
    add_points($meId, $win['points']);
    db()->prepare('INSERT INTO wheel_spins (user_id, spin_date, prize, points) VALUES (?, CURDATE(), ?, ?)')
        ->execute([$meId, $win['label'], $win['points']]);
    if ($win['points'] >= 100 && plugin_active('konfetti')) { $_SESSION['confetti'] = true; }
    echo json_encode(['segment' => $winIdx, 'label' => $win['label'], 'points' => $win['points']]);
    exit;
}

// Sichere CSRF-Prüfung ohne exit (für AJAX)
function csrf_check_safe(): bool
{
    return hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '');
}

// Letzte Gewinne
$history = db()->query("SELECT prize, points, created_at FROM wheel_spins WHERE user_id = $meId ORDER BY spin_date DESC LIMIT 7")->fetchAll();

// Wheel-CSS: conic-gradient aus den Segmentfarben
$gradParts = [];
$segAngle = 360 / count($prizes);
foreach ($prizes as $i => $p) {
    $gradParts[] = $p['color'] . ' ' . ($i * $segAngle) . 'deg ' . (($i + 1) * $segAngle) . 'deg';
}
$wheelGradient = implode(', ', $gradParts);

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal">
    <h1>🎰 Glücksrad</h1>
    <p class="muted">1× pro Tag drehen – jedes Segment gewinnt!</p></div></section>
<section class="section"><div class="container narrow">
    <?php if ($today): ?>
        <div class="card glass reveal" style="text-align:center">
            <p style="font-size:1.3rem;margin-bottom:.4rem">🎁 Heute bereits gedreht!</p>
            <p class="muted">Dein Gewinn: <strong><?= e($today['prize']) ?></strong> (+<?= (int)$today['points'] ?> Punkte)</p>
            <p class="muted small">Morgen geht's weiter – bis dahin: Tagesbonus nicht vergessen! 🎁</p>
        </div>
    <?php else: ?>
        <div class="card glass reveal" style="text-align:center;padding:2rem 1.5rem">
            <div class="wheel-stage">
                <div class="wheel-pointer"></div>
                <div class="wheel" id="wheel" style="background: conic-gradient(<?= e($wheelGradient) ?>)">
                    <?php foreach ($prizes as $i => $p): ?>
                    <span class="wheel-label" style="transform: rotate(<?= (int)($i * $segAngle + $segAngle / 2) ?>deg)"><?= e($p['label']) ?></span>
                    <?php endforeach; ?>
                    <div class="wheel-hub"></div>
                </div>
            </div>
            <button class="btn" id="spinBtn" style="font-size:1.1rem;padding:.8rem 2.4rem">🎰 DREHEN!</button>
            <div id="spinResult" style="margin-top:1rem;font-size:1.15rem;min-height:1.6em"></div>
        </div>
    <?php endif; ?>

    <?php if ($history): ?>
    <div class="card glass reveal" style="margin-top:1.5rem">
        <h3>📜 Deine letzten Drehs</h3>
        <?php foreach ($history as $h): ?>
        <div class="friend-row">
            <span style="font-size:1.2rem"><?= $h['points'] >= 100 ? '💎' : ($h['points'] >= 25 ? '🎉' : '🎯') ?></span>
            <div class="friend-info"><strong><?= e($h['prize']) ?></strong>
                <span class="muted small"><?= date('d.m.Y', strtotime($h['created_at'])) ?></span></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div></section>
<script>
(function () {
    var btn = document.getElementById('spinBtn');
    if (!btn) return;
    var wheel = document.getElementById('wheel');
    var result = document.getElementById('spinResult');
    var segAngle = 45; // 360 / 8
    btn.addEventListener('click', function () {
        btn.disabled = true;
        result.textContent = '…';
        fetch('rad.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=spin&csrf=<?= csrf_token() ?>'
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.error) { result.textContent = d.error; btn.disabled = false; return; }
            // Segment-Ziel: Mitte des Segments oben (Zeiger bei 0°)
            var target = 360 * 6 + (360 - (d.segment * segAngle + segAngle / 2));
            wheel.style.transition = 'transform 4.5s cubic-bezier(0.12, 0.8, 0.08, 1)';
            wheel.style.transform = 'rotate(' + target + 'deg)';
            setTimeout(function () {
                result.innerHTML = '🎉 <strong>' + d.label + '</strong> gewonnen!';
                setTimeout(function () { location.reload(); }, 1600);
            }, 4600);
        }).catch(function () { result.textContent = 'Fehler – bitte erneut versuchen.'; btn.disabled = false; });
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
