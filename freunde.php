<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
$current = 'panel';
$pageTitle = 'Meine Freunde';
$me = current_member();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $otherName = trim((string)($_POST['username'] ?? ''));
    $other = $otherName !== '' ? find_user_by_username($otherName) : null;

    if (!$other || (int)$other['id'] === (int)$me['id']) {
        flash('error', 'Mitglied nicht gefunden.');
    } elseif ($action === 'request' && friendship_status((int)$me['id'], (int)$other['id']) === null) {
        friend_request((int)$me['id'], (int)$other['id']);
        notify((int)$other['id'], 'friend', (int)$me['id'],
            ($me['display_name'] ?: $me['username']) . ' möchte dich als Freund:in hinzufügen.',
            SITE_URL . '/freunde.php');
        flash('success', 'Anfrage an ' . ($other['display_name'] ?: $other['username']) . ' gesendet.');
    } elseif ($action === 'accept' && friendship_status((int)$me['id'], (int)$other['id']) === 'pending_in') {
        friend_accept((int)$me['id'], (int)$other['id']);
        flash('success', 'Anfrage angenommen – ihr seid jetzt Freunde!');
    } elseif ($action === 'decline' || $action === 'remove') {
        friend_remove((int)$me['id'], (int)$other['id']);
        flash('success', 'Erledigt.');
    }
    header('Location: freunde.php');
    exit;
}

$friends = db()->prepare("SELECT u.* FROM friendships f JOIN users u ON u.id = f.friend_id
                          WHERE f.user_id = ? AND f.status = 'accepted' ORDER BY u.display_name, u.username");
$friends->execute([$me['id']]);
$friends = $friends->fetchAll();

$incoming = db()->prepare("SELECT u.*, fr.created_at AS requested_at FROM friendships fr JOIN users u ON u.id = fr.user_id
                           WHERE fr.friend_id = ? AND fr.status = 'pending' ORDER BY fr.created_at DESC");
$incoming->execute([$me['id']]);
$incoming = $incoming->fetchAll();

$outgoing = db()->prepare("SELECT u.*, fr.created_at AS requested_at FROM friendships fr JOIN users u ON u.id = fr.friend_id
                           WHERE fr.user_id = ? AND fr.status = 'pending' ORDER BY fr.created_at DESC");
$outgoing->execute([$me['id']]);
$outgoing = $outgoing->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container reveal"><h1>Meine Freunde</h1>
    <p class="muted"><?= count($friends) ?> Freund<?= count($friends) === 1 ? '' : 'e' ?> · <?= count($incoming) ?> offene Anfrage(n)</p></div>
</section>
<section class="section">
    <div class="container">
        <div class="card glass reveal" style="margin-bottom:1.5rem">
            <form method="post" class="form-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="request">
                <input type="text" name="username" class="flex-grow" placeholder="Benutzername für Freundschaftsanfrage …" required>
                <button class="btn" type="submit">Anfrage senden</button>
            </form>
        </div>

        <div class="grid grid-2">
            <div class="card glass reveal">
                <h3>Freunde (<?= count($friends) ?>)</h3>
                <?php if (!$friends): ?><p class="muted">Noch keine Freunde – sende deine erste Anfrage!</p><?php endif; ?>
                <?php foreach ($friends as $f): ?>
                <div class="friend-row">
                    <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($f['username']) ?>"><?= avatar_html($f) ?></a>
                    <div class="friend-info">
                        <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($f['username']) ?>"><?= e($f['display_name'] ?: $f['username']) ?></a>
                        <span class="muted small">@<?= e($f['username']) ?><?= $f['is_public'] ? '' : ' · 🔒 privat' ?></span>
                    </div>
                    <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/nachrichten.php?with=<?= urlencode($f['username']) ?>">✉</a>
                    <form method="post" class="inline" data-confirm="Freundschaft wirklich beenden?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="username" value="<?= e($f['username']) ?>">
                        <button class="btn btn-danger btn-sm" type="submit">✕</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="form-stack">
                <div class="card glass reveal">
                    <h3>Eingegangene Anfragen (<?= count($incoming) ?>)</h3>
                    <?php if (!$incoming): ?><p class="muted">Keine offenen Anfragen.</p><?php endif; ?>
                    <?php foreach ($incoming as $f): ?>
                    <div class="friend-row">
                        <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($f['username']) ?>"><?= avatar_html($f) ?></a>
                        <div class="friend-info">
                            <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($f['username']) ?>"><?= e($f['display_name'] ?: $f['username']) ?></a>
                            <span class="muted small">seit <?= date('d.m.Y', strtotime($f['requested_at'])) ?></span>
                        </div>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="accept">
                            <input type="hidden" name="username" value="<?= e($f['username']) ?>">
                            <button class="btn btn-sm" type="submit">✔ Annehmen</button>
                        </form>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="decline">
                            <input type="hidden" name="username" value="<?= e($f['username']) ?>">
                            <button class="btn btn-ghost btn-sm" type="submit">Ablehnen</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="card glass reveal">
                    <h3>Gesendete Anfragen (<?= count($outgoing) ?>)</h3>
                    <?php if (!$outgoing): ?><p class="muted">Keine ausstehenden Anfragen.</p><?php endif; ?>
                    <?php foreach ($outgoing as $f): ?>
                    <div class="friend-row">
                        <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($f['username']) ?>"><?= avatar_html($f) ?></a>
                        <div class="friend-info">
                            <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($f['username']) ?>"><?= e($f['display_name'] ?: $f['username']) ?></a>
                            <span class="muted small">wartet seit <?= date('d.m.Y', strtotime($f['requested_at'])) ?></span>
                        </div>
                        <form method="post" class="inline" data-confirm="Anfrage wirklich zurückziehen?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="decline">
                            <input type="hidden" name="username" value="<?= e($f['username']) ?>">
                            <button class="btn btn-ghost btn-sm" type="submit">Zurückziehen</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
