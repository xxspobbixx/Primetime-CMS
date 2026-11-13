<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
$current = '';
$pageTitle = 'Mitglied melden';
$me = current_member();

$username = trim((string)($_GET['u'] ?? ''));
$user = $username !== '' ? find_user_by_username($username) : null;

$reasons = ['Belästigung', 'Spam', 'Unangemessener Inhalt', 'Fake-Profil', 'Sonstiges'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['u'] ?? ''));
    $user = $username !== '' ? find_user_by_username($username) : null;
    $reasonCat = (string)($_POST['reason'] ?? '');
    $details   = trim((string)($_POST['details'] ?? ''));

    if (!$user) {
        flash('error', 'Mitglied nicht gefunden.');
    } elseif ((int)$user['id'] === (int)$me['id']) {
        flash('error', 'Du kannst dich nicht selbst melden.');
    } elseif (!in_array($reasonCat, $reasons, true) || mb_strlen($details) < 5) {
        flash('error', 'Bitte Grund wählen und Beschreibung angeben (min. 5 Zeichen).');
    } else {
        // Nur eine offene Meldung pro Melder+Gemeldetem
        $stmt = db()->prepare("SELECT id FROM reports WHERE reporter_id = ? AND reported_id = ? AND status = 'open'");
        $stmt->execute([$me['id'], $user['id']]);
        if ($stmt->fetch()) {
            flash('error', 'Es liegt bereits eine offene Meldung zu diesem Mitglied vor.');
        } else {
            db()->prepare('INSERT INTO reports (reporter_id, reported_id, reason, details) VALUES (?, ?, ?, ?)')
                ->execute([$me['id'], $user['id'], $reasonCat, $details]);
            flash('success', 'Danke! Die Meldung wurde an die Moderation übermittelt.');
            header('Location: ' . SITE_URL . '/profil.php?u=' . urlencode($user['username']));
            exit;
        }
    }
    header('Location: melden.php?u=' . urlencode($username));
    exit;
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal"><h1>Mitglied melden</h1></div></section>
<section class="section">
    <div class="container narrow">
        <?php if (!$user): ?>
            <div class="card glass reveal"><p class="muted">Mitglied nicht gefunden.</p></div>
        <?php elseif ((int)$user['id'] === (int)$me['id']): ?>
            <div class="card glass reveal"><p class="muted">Du kannst dich nicht selbst melden.</p></div>
        <?php else: ?>
        <form method="post" class="card glass form-stack reveal">
            <?= csrf_field() ?>
            <input type="hidden" name="u" value="<?= e($user['username']) ?>">
            <p>Du meldest: <strong><?= e($user['display_name'] ?: $user['username']) ?></strong> (@<?= e($user['username']) ?>)</p>
            <label>Grund
                <select name="reason">
                    <?php foreach ($reasons as $r): ?><option value="<?= e($r) ?>"><?= e($r) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Beschreibung (was ist passiert?)
                <textarea name="details" rows="5" required minlength="5" maxlength="2000"></textarea>
            </label>
            <div>
                <button class="btn btn-danger" type="submit">Meldung absenden</button>
                <a class="btn btn-ghost" href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($user['username']) ?>">Abbrechen</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
