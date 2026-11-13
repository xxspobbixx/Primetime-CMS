<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
if (!plugin_active('polls')) {
    flash('error', 'Diese Seite ist derzeit deaktiviert.');
    header('Location: ' . SITE_URL . '/wall.php');
    exit;
}
$current = 'polls';
$pageTitle = 'Umfragen';
$me = current_member();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $optionId = (int)($_POST['option_id'] ?? 0);
    $stmt = db()->prepare('SELECT poll_id FROM poll_options WHERE id = ?');
    $stmt->execute([$optionId]);
    $pollId = (int)$stmt->fetchColumn();
    if ($pollId > 0) {
        // 1 Stimme pro Mitglied (INSERT IGNORE durch UNIQUE-Key)
        db()->prepare('INSERT IGNORE INTO poll_votes (poll_id, option_id, user_id) VALUES (?, ?, ?)')
            ->execute([$pollId, $optionId, $me['id']]);
        flash('success', 'Danke für deine Stimme!');
    }
    header('Location: umfragen.php');
    exit;
}

$poll = db()->query('SELECT * FROM polls WHERE active = 1 ORDER BY id DESC LIMIT 1')->fetch();
$options = [];
$totalVotes = 0;
$myOption = null;
$counts = [];
if ($poll) {
    $stmt = db()->prepare('SELECT * FROM poll_options WHERE poll_id = ? ORDER BY display_order, id');
    $stmt->execute([$poll['id']]);
    $options = $stmt->fetchAll();
    $totalVotes = (int)db()->query('SELECT COUNT(*) FROM poll_votes WHERE poll_id = ' . (int)$poll['id'])->fetchColumn();
    foreach (db()->query('SELECT option_id, COUNT(*) AS c FROM poll_votes WHERE poll_id = ' . (int)$poll['id'] . ' GROUP BY option_id') as $r) {
        $counts[$r['option_id']] = (int)$r['c'];
    }
    $stmt = db()->prepare('SELECT option_id FROM poll_votes WHERE poll_id = ? AND user_id = ?');
    $stmt->execute([$poll['id'], $me['id']]);
    $myOption = (int)$stmt->fetchColumn();
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container reveal"><h1>📊 Umfragen</h1>
    <p class="muted">Community-Abstimmungen – deine Stimme zählt!</p></div>
</section>
<section class="section">
    <div class="container narrow">
        <?php if (!$poll): ?>
            <div class="card glass reveal"><p class="muted">Aktuell läuft keine Umfrage – schau bald wieder vorbei!</p></div>
        <?php else: ?>
        <div class="card glass reveal">
            <h2 style="font-size:1.35rem;margin-bottom:1.2rem"><?= e($poll['question']) ?></h2>
            <p class="muted small" style="margin-bottom:1.2rem"><?= $totalVotes ?> Stimme(n) abgegeben</p>

            <?php if ($myOption === 0): ?>
                <!-- Abstimm-Modus -->
                <form method="post" class="form-stack">
                    <?= csrf_field() ?>
                    <?php foreach ($options as $opt): ?>
                    <label class="check poll-option">
                        <input type="radio" name="option_id" value="<?= (int)$opt['id'] ?>" required>
                        <span><?= e($opt['text']) ?></span>
                    </label>
                    <?php endforeach; ?>
                    <button class="btn" type="submit">Abstimmen</button>
                </form>
            <?php else: ?>
                <!-- Ergebnis-Modus -->
                <div class="poll-results">
                    <?php foreach ($options as $opt):
                        $c = $counts[$opt['id']] ?? 0;
                        $pct = $totalVotes > 0 ? round($c / $totalVotes * 100) : 0;
                        $mine = $myOption === (int)$opt['id'];
                    ?>
                    <div class="poll-row">
                        <div class="poll-row-head">
                            <span><?= e($opt['text']) ?> <?= $mine ? '<span class="badge badge-green">deine Stimme</span>' : '' ?></span>
                            <strong><?= $pct ?>%</strong>
                        </div>
                        <div class="poll-bar"><div class="poll-fill<?= $mine ? ' mine' : '' ?>" style="width:<?= max($pct, 2) ?>%"></div></div>
                        <span class="muted small"><?= $c ?> Stimme(n)</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="muted small">Du hast bereits abgestimmt – das Ergebnis ist live.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
