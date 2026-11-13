<?php
require_once __DIR__ . '/includes/functions.php';
require_member();
$current = 'wall';
$pageTitle = 'Wall';
$me = current_member();
$canModerate = user_can_moderate($me);

$reactionTypes = [
    'like'    => '👍',
    'dislike' => '👎',
    'love'    => '❤️',
    'haha'    => '😂',
    'wow'     => '😮',
    'fire'    => '🔥',
];

/* ================= POST-Verarbeitung ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'post') {
        if (plugin_active('rate_limit') && rate_limited((int)$me['id'], 'wall_posts', (int)setting('plugin_rate_limit_posts', '5'))) {
            flash('error', 'Bitte etwas langsamer – Rate-Limit erreicht.');
        } else {
            $content    = filter_words(trim((string)($_POST['content'] ?? '')));
            $youtubeUrl = trim((string)($_POST['youtube'] ?? ''));
            $youtubeId  = $youtubeUrl !== '' ? youtube_video_id($youtubeUrl) : null;

            // Text ODER YouTube-Link reicht (Link-only-Posts erlaubt)
            if ($content === '' && $youtubeId === null) {
                flash('error', 'Bitte einen Text oder einen YouTube-Link eingeben.');
            } elseif ($content !== '' && mb_strlen($content) > 1000) {
                flash('error', 'Status ist zu lang (max. 1000 Zeichen).');
            } elseif ($youtubeUrl !== '' && $youtubeId === null) {
                flash('error', 'Der YouTube-Link konnte nicht erkannt werden.');
            } else {
                $image = null;
                if (!empty($_FILES['image']['name'])) {
                    try {
                        $image = save_wall_image($_FILES['image']);
                    } catch (RuntimeException $ex) {
                        flash('error', 'Bild: ' . $ex->getMessage());
                        header('Location: wall.php');
                        exit;
                    }
                }
                $anon = isset($_POST['anonymous']) ? 1 : 0;
                // Spotify-Link im Text erkennen
                $spotifyId = null;
                if (preg_match('#open\.spotify\.com/(track|playlist)/([A-Za-z0-9]+)#', $content, $sm)) {
                    $spotifyId = $sm[1] . ':' . $sm[2];
                }
                db()->prepare('INSERT INTO wall_posts (user_id, content, youtube_id, image, anonymous, spotify_id) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$me['id'], $content, $youtubeId, $image, $anon, $spotifyId]);

                // @Erwähnungen benachrichtigen
                if (preg_match_all('/@([A-Za-z0-9_.\-]{3,30})/', $content, $mm)) {
                    foreach (array_unique($mm[1]) as $mun) {
                        $mu = find_user_by_username($mun);
                        if ($mu) {
                            notify((int)$mu['id'], 'mention', (int)$me['id'],
                                ($me['display_name'] ?: $me['username']) . ' hat dich in einem Beitrag erwähnt.',
                                SITE_URL . '/wall.php');
                        }
                    }
                }
                check_badges((int)$me['id']);
                flash('success', 'Dein Beitrag wurde veröffentlicht!');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = db()->prepare('SELECT image FROM wall_posts WHERE id = ?' . ($canModerate ? '' : ' AND user_id = ?'));
        if ($canModerate) { $stmt->execute([$id]); } else { $stmt->execute([$id, $me['id']]); }
        $row = $stmt->fetch();
        if ($row) {
            db()->prepare('DELETE FROM wall_posts WHERE id = ?')->execute([$id]);
            if (!empty($row['image'])) delete_upload_file($row['image'], 'wall');
            mod_log('Wall-Beitrag gelöscht', 'Post #' . $id);
            flash('success', 'Beitrag gelöscht.');
        }
    } elseif ($action === 'edit_comment') {
        $cid = (int)$_POST['id'];
        $content = filter_words(trim((string)($_POST['content'] ?? '')));
        $stmt = db()->prepare('SELECT user_id, created_at FROM wall_comments WHERE id = ?');
        $stmt->execute([$cid]);
        $row = $stmt->fetch();
        if ($row && (int)$row['user_id'] === (int)$me['id'] && strtotime($row['created_at']) > time() - 900 && $content !== '' && mb_strlen($content) <= 500) {
            db()->prepare('UPDATE wall_comments SET content = ? WHERE id = ?')->execute([$content, $cid]);
            flash('success', 'Kommentar bearbeitet.');
        } else {
            flash('error', 'Bearbeiten nur 15 Min. nach dem Schreiben (eigene Kommentare).');
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $content = filter_words(trim((string)($_POST['content'] ?? '')));
        if ($content === '' || mb_strlen($content) > 1000) {
            flash('error', 'Beitrag leer oder zu lang.');
        } else {
            // Nur eigene Beitraege, max. 15 Min. nach Erstellung
            $stmt = db()->prepare('SELECT user_id, created_at FROM wall_posts WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row && (int)$row['user_id'] === (int)$me['id'] && strtotime($row['created_at']) > time() - 900) {
                db()->prepare('UPDATE wall_posts SET content = ?, edited = 1 WHERE id = ?')->execute([$content, $id]);
                flash('success', 'Beitrag bearbeitet.');
            } else {
                flash('error', 'Bearbeiten nur 15 Min. nach dem Posten möglich.');
            }
        }
    } elseif ($action === 'bookmark') {
        db()->prepare('INSERT IGNORE INTO wall_bookmarks (user_id, post_id) VALUES (?, ?)')
            ->execute([$meId, (int)$_POST['id']]);
        flash('success', 'Beitrag gespeichert! 🔖');
    } elseif ($action === 'unbookmark') {
        db()->prepare('DELETE FROM wall_bookmarks WHERE user_id = ? AND post_id = ?')
            ->execute([$meId, (int)$_POST['id']]);
        flash('success', 'Lesezeichen entfernt.');
    } elseif ($action === 'pin' && $canModerate) {
        $id = (int)$_POST['id'];
        db()->prepare('UPDATE wall_posts SET pinned = 1 - pinned WHERE id = ?')->execute([$id]);
        mod_log('Wall-Beitrag an/entpinnt', 'Post #' . $id);
        flash('success', 'Beitrag angepinnt/abgepinnt.');
    } elseif ($action === 'react') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $type   = (string)($_POST['type'] ?? '');
        if (isset($reactionTypes[$type])) {
            $stmt = db()->prepare('SELECT type FROM wall_reactions WHERE post_id = ? AND user_id = ?');
            $stmt->execute([$postId, $me['id']]);
            $existing = $stmt->fetchColumn();
            if ($existing === $type) {
                db()->prepare('DELETE FROM wall_reactions WHERE post_id = ? AND user_id = ?')->execute([$postId, $me['id']]);
            } elseif ($existing) {
                db()->prepare('UPDATE wall_reactions SET type = ?, created_at = NOW() WHERE post_id = ? AND user_id = ?')
                    ->execute([$type, $postId, $me['id']]);
            } else {
                db()->prepare('INSERT IGNORE INTO wall_reactions (post_id, user_id, type) VALUES (?, ?, ?)')
                    ->execute([$postId, $me['id'], $type]);
            }
        }
    } elseif ($action === 'comment') {
        $postId  = (int)($_POST['post_id'] ?? 0);
        $content = filter_words(trim((string)($_POST['content'] ?? '')));
        if ($content === '' || mb_strlen($content) > 500) {
            flash('error', 'Kommentar leer oder zu lang (max. 500 Zeichen).');
        } elseif (plugin_active('rate_limit') && rate_limited((int)$me['id'], 'wall_comments', (int)setting('plugin_rate_limit_comments', '8'))) {
            flash('error', 'Zu viele Kommentare in kurzer Zeit.');
        } else {
            $parentId = (int)($_POST['parent_id'] ?? 0);
            $parentOwner = 0;
            if ($parentId > 0) {
                // Eltern-Kommentar gehört zum selben Post?
                $ps = db()->prepare('SELECT user_id FROM wall_comments WHERE id = ? AND post_id = ?');
                $ps->execute([$parentId, $postId]);
                $parentOwner = (int)$ps->fetchColumn();
                if ($parentOwner === 0) $parentId = 0; // ungültig -> Top-Level
            }
            db()->prepare('INSERT INTO wall_comments (post_id, user_id, content, parent_id) VALUES (?, ?, ?, ?)')
                ->execute([$postId, $me['id'], $content, $parentId > 0 ? $parentId : null]);
            if ($parentOwner > 0 && $parentOwner !== (int)$me['id']) {
                notify($parentOwner, 'comment_reply', (int)$me['id'],
                    ($me['display_name'] ?: $me['username']) . ' hat auf deinen Kommentar geantwortet.',
                    SITE_URL . '/wall.php#post-' . $postId);
            }
            $stmt = db()->prepare('SELECT u.id FROM wall_posts w JOIN users u ON u.id = w.user_id WHERE w.id = ?');
            $stmt->execute([$postId]);
            $ownerId = (int)$stmt->fetchColumn();
            if ($ownerId > 0 && $ownerId !== (int)$me['id']) {
                notify($ownerId, 'wall_comment', (int)$me['id'],
                    ($me['display_name'] ?: $me['username']) . ' hat deinen Beitrag kommentiert.',
                    SITE_URL . '/wall.php#post-' . $postId);
            }
            check_badges((int)$me['id']);
        }
    } elseif ($action === 'delete_comment') {
        $id = (int)$_POST['id'];
        if ($canModerate) {
            db()->prepare('DELETE FROM wall_comments WHERE id = ?')->execute([$id]);
        } else {
            db()->prepare('DELETE FROM wall_comments WHERE id = ? AND user_id = ?')->execute([$id, $me['id']]);
        }
    } elseif ($action === 'report') {
        $postId    = (int)($_POST['post_id'] ?? 0);
        $reasonCat = (string)($_POST['reason'] ?? '');
        $details   = trim((string)($_POST['details'] ?? ''));
        $reasons   = ['Belästigung', 'Spam', 'Unangemessener Inhalt', 'Hassrede', 'Sonstiges'];

        if (!in_array($reasonCat, $reasons, true) || mb_strlen($details) < 5) {
            flash('error', 'Bitte Grund wählen und Beschreibung angeben (min. 5 Zeichen).');
        } else {
            $stmt = db()->prepare("SELECT id FROM wall_reports WHERE post_id = ? AND reporter_id = ? AND status = 'open'");
            $stmt->execute([$postId, $me['id']]);
            if ($stmt->fetch()) {
                flash('error', 'Du hast diesen Beitrag bereits gemeldet.');
            } else {
                db()->prepare('INSERT INTO wall_reports (post_id, reporter_id, reason, details) VALUES (?, ?, ?, ?)')
                    ->execute([$postId, $me['id'], $reasonCat, $details]);
                auto_moderate_wall($postId);
                discord_notify('🚩 Neue Meldung (Beitrag)',
                    '**@' . $me['username'] . '** hat einen Wall-Beitrag gemeldet. Grund: ' . $reasonCat,
                    15105570);
                flash('success', 'Beitrag gemeldet – danke für deinen Hinweis!');
            }
        }
    }
    header('Location: wall.php');
    exit;
}

/* ================= Quests (taeglich) ================= */
$questDefs = [
    'q_post'   => ['📝', 'Schreibe einen Beitrag', 1],
    'q_comment'=> ['💬', 'Schreibe 2 Kommentare', 2],
    'q_react'  => ['❤️', 'Reagiere 3x', 3],
];
$quests = [];
foreach ($questDefs as $qk => [$qi, $ql, $qt]) {
    $done = (int)setting("quest_{$qk}_" . $meId . '_' . date('Ymd'), '0');
    $quests[$qk] = ['icon' => $qi, 'label' => $ql, 'target' => $qt, 'done' => min($done, $qt), 'complete' => $done >= $qt];
}
// Quest-Fortschritt bei Aktionen erhoehen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qa = $_POST['action'] ?? '';
    $qMap = ['post' => 'q_post', 'comment' => 'q_comment', 'react' => 'q_react'];
    if (isset($qMap[$qa])) {
        $qk2 = $qMap[$qa];
        $key2 = "quest_{$qk2}_" . $meId . '_' . date('Ymd');
        $cur = (int)setting($key2, '0');
        if ($cur < $questDefs[$qk2][2]) {
            set_setting($key2, (string)($cur + 1));
            if ($cur + 1 >= $questDefs[$qk2][2]) {
                add_points($meId, 15);
                flash('success', '🎯 Quest abgeschlossen: ' . $questDefs[$qk2][1] . ' (+15 Punkte)');
            }
        }
    }
}

/* ================= Daten laden ================= */
$mom = member_of_month();

$meId = (int)$me['id'];

/* ---- Feed-Regler: Filter & Sortierung (merkt sich die Auswahl per Cookie) ---- */
$feedFilters = [
    'all'     => ['🌍', 'Alle'],
    'friends' => ['👥', 'Freunde'],
    'media'   => ['🎬', 'Mit Medien'],
    'mine'    => ['🙋', 'Meine'],
    'saved'   => ['🔖', 'Gespeichert'],
];
$feedSorts = ['new' => '🕒 Neueste', 'top' => '🔥 Beliebteste (30 Tage)'];

$cookieParts = explode(':', (string)($_COOKIE['wall_feed'] ?? ''));
$feed = (string)($_GET['feed'] ?? ($cookieParts[0] ?? 'all'));
$sort = (string)($_GET['sort'] ?? ($cookieParts[1] ?? 'new'));
if (!isset($feedFilters[$feed])) $feed = 'all';
if (!isset($feedSorts[$sort]))   $sort = 'new';
if (isset($_GET['feed']) || isset($_GET['sort'])) {
    setcookie('wall_feed', $feed . ':' . $sort, [
        'expires'  => time() + 60 * 60 * 24 * 30,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

$feedWhere = '';
switch ($feed) {
    case 'friends':
        // Anonyme Beiträge bleiben bewusst draußen, sonst ließe sich der Autor erraten.
        $feedWhere = " AND w.anonymous = 0 AND (w.user_id = $meId OR w.user_id IN
                       (SELECT friend_id FROM friendships WHERE user_id = $meId AND status = 'accepted'))";
        break;
    case 'media':
        $feedWhere = " AND ((w.image IS NOT NULL AND w.image <> '') OR (w.youtube_id IS NOT NULL AND w.youtube_id <> ''))";
        break;
    case 'mine':
        $feedWhere = " AND w.user_id = $meId";
        break;
    case 'saved':
        $feedWhere = " AND w.id IN (SELECT post_id FROM wall_bookmarks WHERE user_id = $meId)";
        break;
}
if ($sort === 'top') {
    $feedWhere .= " AND w.created_at > NOW() - INTERVAL 30 DAY";
    $feedOrder = "w.pinned DESC,
                  ((SELECT COUNT(*) FROM wall_reactions r WHERE r.post_id = w.id)
                   + 2 * (SELECT COUNT(*) FROM wall_comments c WHERE c.post_id = w.id)) DESC,
                  w.created_at DESC";
} else {
    $feedOrder = "w.pinned DESC, w.created_at DESC";
}

try {
    $posts = db()->query("SELECT w.*, u.username, u.display_name, u.avatar, u.avatar_color, u.role, u.name_color
                          FROM wall_posts w JOIN users u ON u.id = w.user_id
                          WHERE (w.hidden = 0 OR w.user_id = $meId
                                 OR $meId IN (SELECT id FROM users WHERE role IN ('admin','moderator')))
                          $feedWhere
                          ORDER BY $feedOrder LIMIT 50")->fetchAll();
} catch (Throwable $e) {
    // Fallback ohne hidden/pinned-Spalte
    $posts = db()->query("SELECT w.*, u.username, u.display_name, u.avatar, u.avatar_color, u.role
                          FROM wall_posts w JOIN users u ON u.id = w.user_id
                          ORDER BY w.created_at DESC LIMIT 50")->fetchAll();
}
$posts = array_values(array_filter($posts, function ($p) use ($me) {
    return !is_shadow_hidden((int)$p['user_id'], $me) && !is_blocked((int)$me['id'], (int)$p['user_id']);
}));
// Bookmark-Status für den aktuellen User
$myBookmarks = [];
if ($posts) {
    $ids = implode(',', array_map('intval', array_column($posts, 'id')));
    foreach (db()->query("SELECT post_id FROM wall_bookmarks WHERE user_id = $meId AND post_id IN ($ids)") as $bm) {
        $myBookmarks[$bm['post_id']] = true;
    }
}

$reactionMap = [];
$commentMap  = [];
$myReactions = [];
$postIds     = array_map('intval', array_column($posts, 'id'));

if ($postIds) {
    $in = implode(',', $postIds);
    foreach (db()->query("SELECT post_id, type, COUNT(*) AS cnt FROM wall_reactions WHERE post_id IN ($in) GROUP BY post_id, type") as $r) {
        $reactionMap[$r['post_id']][$r['type']] = ['count' => (int)$r['cnt'], 'mine' => false];
    }
    $stmt = db()->prepare("SELECT post_id, type FROM wall_reactions WHERE user_id = ? AND post_id IN ($in)");
    $stmt->execute([$meId]);
    foreach ($stmt->fetchAll() as $r) {
        $myReactions[$r['post_id']] = $r['type'];
        if (isset($reactionMap[$r['post_id']][$r['type']])) {
            $reactionMap[$r['post_id']][$r['type']]['mine'] = true;
        }
    }
    foreach (db()->query("SELECT c.*, u.username, u.display_name, u.avatar, u.avatar_color, u.name_color
                          FROM wall_comments c JOIN users u ON u.id = c.user_id
                          WHERE c.post_id IN ($in) ORDER BY c.created_at ASC") as $c) {
        $commentMap[$c['post_id']][] = $c;
    }
}

// Geburtstags-Widget
if (plugin_active('birthday_widget')):
    $bday = db()->query("SELECT username, display_name FROM users
        WHERE birthday IS NOT NULL AND MONTH(birthday) = MONTH(CURDATE()) AND DAY(birthday) = DAY(CURDATE())
        AND is_public = 1 LIMIT 5")->fetchAll();
    if ($bday):
        echo '<div class="container" style="margin-top:1rem"><div class="flash flash-success reveal">🎂 <strong>Geburtstag heute:</strong> ';
        $names = [];
        foreach ($bday as $b) { $names[] = '<a href="' . SITE_URL . '/profil.php?u=' . urlencode($b['username']) . '">' . e($b['display_name'] ?: $b['username']) . '</a>'; }
        echo implode(', ', $names) . ' – Alles Gute! 🎉</div></div>';
    endif;
endif;

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container reveal">
        <h1>📋 Wall</h1>
        <p class="muted">Was geht ab? Teile deinen Status, Videos und reagiere auf Beiträge.
        <?php if ($mom): ?>
            · 👑 Mitglied des Monats: <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($mom['username']) ?>" style="color:var(--text)"><strong><?= e($mom['display_name'] ?: $mom['username']) ?></strong></a>
        <?php endif; ?></p>
    </div>
</section>
<section class="section">
    <div class="container narrow">
        <form method="post" enctype="multipart/form-data" class="card glass form-stack reveal" style="margin-bottom:1.5rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="post">
            <div class="wall-compose">
                <?= avatar_html($me) ?>
                <div class="flex-grow">
                    <textarea name="content" rows="3" maxlength="1000" placeholder="Was machst du gerade? Text, YouTube-Link oder beides … (||Text|| = Spoiler)" required></textarea>
                    <input type="url" name="youtube" placeholder="YouTube-Link (optional): https://youtube.com/watch?v=…">
                    <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" style="font-size:.85rem;padding:.5rem">
                </div>
            </div>
            <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
                <button class="btn" type="submit">Posten</button>
                <label class="check" style="margin:0"><input type="checkbox" name="anonymous"> 🎭 Anonym posten</label>
            </div>
        </form>

        <div class="card glass reveal" style="margin-bottom:1.2rem">
            <h3>🎯 Tages-Quests <span class="muted small">(je +15 Punkte)</span></h3>
            <div style="display:flex;gap:1rem;flex-wrap:wrap">
                <?php foreach ($quests as $q): ?>
                <div style="flex:1;min-width:150px;<?= $q['complete'] ? 'opacity:.75' : '' ?>">
                    <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.3rem">
                        <span><?= $q['icon'] ?> <?= e($q['label']) ?></span>
                        <span><?= $q['complete'] ? '✅' : $q['done'] . '/' . $q['target'] ?></span>
                    </div>
                    <div class="poll-bar"><div class="poll-fill" style="width:<?= (int)($q['done'] / max(1, $q['target']) * 100) ?>%"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card glass reveal" style="margin-bottom:1.2rem">
            <h3>🏆 Top 5 dieser Woche</h3>
            <?php $topWeek = db()->query("SELECT u.username, u.display_name, u.avatar, u.avatar_color,
                ((SELECT COUNT(*) FROM wall_posts w WHERE w.user_id = u.id AND w.created_at > NOW() - INTERVAL 7 DAY) * 5 +
                 (SELECT COUNT(*) FROM wall_comments c WHERE c.user_id = u.id AND c.created_at > NOW() - INTERVAL 7 DAY) * 2 +
                 (SELECT COUNT(*) FROM forum_posts fp WHERE fp.user_id = u.id AND fp.created_at > NOW() - INTERVAL 7 DAY) * 5) AS pts
                FROM users u HAVING pts > 0 ORDER BY pts DESC LIMIT 5")->fetchAll(); ?>
            <div style="display:flex;gap:.8rem;flex-wrap:wrap">
                <?php $rank = 0; foreach ($topWeek as $tw): $rank++; ?>
                <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($tw['username']) ?>" style="display:flex;align-items:center;gap:.4rem;text-decoration:none;color:var(--text)">
                    <span class="muted small"><?= $rank ?>.</span><?= avatar_html($tw) ?>
                    <span class="small"><?= e($tw['display_name'] ?: $tw['username']) ?> <strong style="color:var(--accent-2)"><?= (int)$tw['pts'] ?></strong></span>
                </a>
                <?php endforeach; ?>
                <?php if (!$topWeek): ?><span class="muted small">Noch keine Aktivität diese Woche.</span><?php endif; ?>
            </div>
        </div>

        <div class="card glass reveal" style="margin-bottom:1.2rem">
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                <?php foreach ($feedFilters as $fk => $fv): ?>
                <a class="btn btn-sm <?= $feed === $fk ? '' : 'btn-ghost' ?>" href="?feed=<?= $fk ?>&amp;sort=<?= e($sort) ?>"><?= $fv[0] ?> <?= e($fv[1]) ?></a>
                <?php endforeach; ?>
            </div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-top:.6rem">
                <span class="muted small">Sortierung:</span>
                <?php foreach ($feedSorts as $sk => $sl): ?>
                <a class="btn btn-sm <?= $sort === $sk ? '' : 'btn-ghost' ?>" href="?feed=<?= e($feed) ?>&amp;sort=<?= $sk ?>"><?= e($sl) ?></a>
                <?php endforeach; ?>
            </div>
            <p class="muted small" style="margin:.6rem 0 0">Kein Algorithmus, keine Werbung – du entscheidest, was du siehst. Deine Auswahl wird gemerkt.</p>
        </div>

        <?php if (!$posts): ?>
            <div class="card glass reveal"><p class="muted"><?php
                $emptyMsg = [
                    'friends' => 'Von deinen Freunden gibt es hier noch nichts. Füge Freunde hinzu oder wechsle auf „Alle“.',
                    'media'   => 'Noch keine Beiträge mit Bild oder Video.',
                    'mine'    => 'Du hast noch nichts gepostet.',
                    'saved'   => 'Du hast noch keine Beiträge gespeichert.',
                ];
                echo e($emptyMsg[$feed] ?? 'Noch nichts los hier – schreib den ersten Beitrag! 🎉');
            ?></p></div>
        <?php endif; ?>

        <?php foreach ($posts as $post): ?>
        <article class="card glass wall-post reveal" id="post-<?= (int)$post['id'] ?>">
            <div class="wall-head">
                <?php if (!empty($post['anonymous'])): ?>
                <span class="avatar" style="background:#3a3f4b">🎭</span>
                <div>
                    <span class="wall-author" style="cursor:default">Anonym 🎭</span>
                <?php else: ?>
                <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($post['username']) ?>"><?= avatar_html($post) ?></a>
                <div>
                    <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($post['username']) ?>" class="wall-author"<?= name_color_style($post) ?>><?= e($post['display_name'] ?: $post['username']) ?></a>
                    <?= role_badge($post['role']) ?>
                <?php endif; ?>
                    <span class="muted small wall-time"><?= date('d.m.Y \u\m H:i', strtotime($post['created_at'])) ?></span>
                </div>
                <span style="flex:1"></span>
                <details class="wall-report">
                    <summary class="btn btn-ghost btn-sm" title="Beitrag melden">🚩</summary>
                    <form method="post" class="wall-report-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="report">
                        <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                        <select name="reason">
                            <option>Belästigung</option><option>Spam</option>
                            <option>Unangemessener Inhalt</option><option>Hassrede</option><option>Sonstiges</option>
                        </select>
                        <textarea name="details" rows="2" maxlength="2000" placeholder="Was ist das Problem? (min. 5 Zeichen)" required></textarea>
                        <button class="btn btn-danger btn-sm" type="submit">Meldung absenden</button>
                    </form>
                </details>
                <?php if (!empty($post['pinned'])): ?><span class="badge badge-green" title="Angepinnt">📌</span><?php endif; ?>
                <?php if (isset($myBookmarks[$post['id']])): ?><span class="badge" title="Gespeichert">🔖</span><?php endif; ?>
                <?php if ((int)$post['user_id'] === $meId): ?>
                <details class="wall-report">
                    <summary class="btn btn-ghost btn-sm" title="Bearbeiten">✎</summary>
                    <form method="post" class="wall-report-form" style="width:300px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                        <textarea name="content" rows="3" maxlength="1000" required><?= e($post['content']) ?></textarea>
                        <button class="btn btn-sm" type="submit">Speichern (15 Min.)</button>
                    </form>
                </details>
                <?php endif; ?>
                <form method="post" class="inline" title="<?= isset($myBookmarks[$post['id']]) ? 'Lesezeichen entfernen' : 'Speichern' ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= isset($myBookmarks[$post['id']]) ? 'unbookmark' : 'bookmark' ?>">
                    <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit"><?= isset($myBookmarks[$post['id']]) ? '🔖✕' : '🔖' ?></button>
                </form>
                <?php if ($canModerate): ?>
                <form method="post" class="inline" title="Anpinnen/Abpinnen">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="pin">
                    <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit"><?= !empty($post['pinned']) ? '📌✕' : '📌' ?></button>
                </form>
                <?php endif; ?>
                <?php if ((int)$post['user_id'] === $meId || $canModerate): ?>
                <form method="post" class="inline" data-confirm="Beitrag wirklich löschen?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit" title="Löschen">🗑</button>
                </form>
                <?php endif; ?>
            </div>

            <div class="wall-content"><?= render_spoilers(preg_replace('/#(\w+)/u', '<a href="' . SITE_URL . '/suche.php?q=%23$1" style="color:var(--accent-2)">#$1</a>', nl2br(e($post['content'])))) ?>
                <?= !empty($post['edited']) ? '<span class="muted small"> · ✎ bearbeitet</span>' : '' ?></div>

            <?php if ($post['youtube_id']): ?>
            <div class="yt-preview" data-id="<?= e($post['youtube_id']) ?>" title="Video abspielen">
                <img src="https://i.ytimg.com/vi/<?= e($post['youtube_id']) ?>/hqdefault.jpg" alt="YouTube-Video" loading="lazy">
                <button type="button" class="yt-play" aria-label="Video abspielen">▶</button>
            </div>
            <a class="yt-direct small" href="https://www.youtube.com/watch?v=<?= e($post['youtube_id']) ?>" target="_blank" rel="noopener">📺 Direkt auf YouTube öffnen</a>
            <?php endif; ?>
            <?php if (!empty($post['spotify_id'])):
                [$spType, $spId] = explode(':', $post['spotify_id'], 2); ?>
            <iframe src="https://open.spotify.com/embed/<?= e($spType) ?>/<?= e($spId) ?>" width="100%" height="80" frameborder="0" allow="encrypted-media" style="border-radius:12px;margin-top:.5rem" loading="lazy"></iframe>
            <?php endif; ?>

            <?php if (!empty($post['image'])): ?>
            <a href="<?= SITE_URL ?>/uploads/wall/<?= rawurlencode($post['image']) ?>" target="_blank" rel="noopener">
                <img class="wall-image" src="<?= SITE_URL ?>/uploads/wall/<?= rawurlencode($post['image']) ?>"
                     alt="Bild von <?= e($post['display_name'] ?: $post['username']) ?>" loading="lazy">
            </a>
            <?php endif; ?>

            <?php
            $shareUrl = SITE_URL . '/wall.php#post-' . (int)$post['id'];
            $shareTxt = mb_strimwidth($post['content'], 0, 80, '…');
            ?>
            <div class="share-row">
                <span class="muted small">Teilen:</span>
                <a class="share-btn" target="_blank" rel="noopener" title="Auf X teilen" href="https://twitter.com/intent/tweet?text=<?= urlencode($shareTxt) ?>&url=<?= urlencode($shareUrl) ?>">𝕏</a>
                <a class="share-btn" target="_blank" rel="noopener" title="Auf WhatsApp teilen" href="https://wa.me/?text=<?= urlencode($shareTxt . ' ' . $shareUrl) ?>">💬</a>
                <a class="share-btn" target="_blank" rel="noopener" title="Auf Facebook teilen" href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($shareUrl) ?>">f</a>
                <button type="button" class="share-btn" title="Link kopieren" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= e($shareUrl) ?>');this.textContent='✔';setTimeout(function(b){b.textContent='🔗'},1200,this)">🔗</button>
            </div>

            <div class="reaction-bar">
                <?php foreach ($reactionTypes as $type => $emoji): ?>
                <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="react">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <button type="submit" name="type" value="<?= $type ?>"
                            class="reaction-btn <?= ($myReactions[$post['id']] ?? null) === $type ? 'active' : '' ?>">
                        <?= $emoji ?>
                        <?php if (!empty($reactionMap[$post['id']][$type])): ?>
                            <span class="reaction-count"><?= $reactionMap[$post['id']][$type]['count'] ?></span>
                        <?php endif; ?>
                    </button>
                </form>
                <?php endforeach; ?>
            </div>

            <div class="comments">
                <?php
                $topComments = [];
                $replies = [];
                foreach ($commentMap[$post['id']] ?? [] as $c) {
                    if (!empty($c['parent_id'])) { $replies[$c['parent_id']][] = $c; }
                    else { $topComments[] = $c; }
                }
                $renderComment = function ($c, $isReply = false) use ($meId, $canModerate, $post, $replies, &$renderComment) { ?>
                <div class="comment <?= $isReply ? 'comment-reply' : '' ?>">
                    <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($c['username']) ?>"><?= avatar_html($c) ?></a>
                    <div class="comment-body">
                        <div class="comment-head">
                            <a href="<?= SITE_URL ?>/profil.php?u=<?= urlencode($c['username']) ?>" class="comment-author"<?= name_color_style($c) ?>><?= e($c['display_name'] ?: $c['username']) ?></a>
                            <span class="muted small"><?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></span>
                            <button type="button" class="comment-delete comment-reply-btn" title="Antworten" onclick="var f=this.nextElementSibling;f.style.display=f.style.display==='flex'?'none':'flex'">↩</button>
                            <form method="post" class="comment-reply-form" style="display:none">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="comment">
                                <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                                <input type="hidden" name="parent_id" value="<?= (int)$c['id'] ?>">
                                <input type="text" name="content" maxlength="500" placeholder="Antwort an <?= e($c['display_name'] ?: $c['username']) ?> …" required>
                                <button class="btn btn-sm" type="submit">➤</button>
                            </form>
                            <?php if ((int)$c['user_id'] === $meId): ?>
                            <details class="wall-report" style="margin-left:auto">
                                <summary class="comment-delete" style="position:static" title="Bearbeiten">✎</summary>
                                <form method="post" class="wall-report-form" style="width:260px">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="edit_comment">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <textarea name="content" rows="2" maxlength="500" required><?= e($c['content']) ?></textarea>
                                    <button class="btn btn-sm" type="submit">Speichern</button>
                                </form>
                            </details>
                            <?php endif; ?>
                            <?php if ((int)$c['user_id'] === $meId || $canModerate): ?>
                            <form method="post" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_comment">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="comment-delete" type="submit" title="Kommentar löschen">✕</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?= nl2br(e($c['content'])) ?>
                    </div>
                </div>
                <?php foreach ($replies[$c['id']] ?? [] as $rep) { $renderComment($rep, true); } };
                foreach ($topComments as $c) { $renderComment($c); }
                ?>
                <form method="post" class="comment-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="comment">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <input type="text" name="content" maxlength="500" placeholder="Kommentar schreiben …" required>
                    <button class="btn btn-sm" type="submit">➤</button>
                </form>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>
<!-- WALLBUILD:2026-11-01-v3 -->
<?php include __DIR__ . '/includes/footer.php'; ?>
