<?php
/**
 * Clips API – Likes, Kommentare, Feed
 */
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

function jout($d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE) ?: '{"error":"json"}';
    exit;
}

function ensure_clip_tables(): void {
    static $done = false;
    if ($done) return;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS clips (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(120) NOT NULL,
            description TEXT NULL,
            file VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NULL,
            mime VARCHAR(80) NULL,
            size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
            game_tag VARCHAR(60) NULL,
            views INT UNSIGNED NOT NULL DEFAULT 0,
            featured TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id),
            KEY idx_active (active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS clip_likes (
            clip_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (clip_id, user_id),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS clip_comments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            clip_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            content VARCHAR(500) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_clip (clip_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
    $done = true;
}

if (!is_member()) {
    http_response_code(401);
    jout(['error' => 'Nicht angemeldet']);
}

$me = current_member();
ensure_clip_tables();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ---- Feed ----
if ($action === 'feed') {
    $after = max(0, (int)($_GET['after'] ?? 0));
    $sql = "
        SELECT c.*, u.username, u.display_name, u.avatar, u.avatar_color,
               (SELECT COUNT(*) FROM clip_likes l WHERE l.clip_id = c.id) AS likes,
               (SELECT COUNT(*) FROM clip_comments cm WHERE cm.clip_id = c.id) AS comments,
               (SELECT 1 FROM clip_likes l2 WHERE l2.clip_id = c.id AND l2.user_id = ?) AS liked
        FROM clips c
        JOIN users u ON u.id = c.user_id
        WHERE c.active = 1
    ";
    $params = [$me['id']];
    if ($after > 0) {
        $sql .= ' AND c.id < ?';
        $params[] = $after;
    }
    $sql .= ' ORDER BY c.featured DESC, c.id DESC LIMIT 15';
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }
    $out = [];
    foreach ($rows as $c) {
        $out[] = [
            'id' => (int)$c['id'],
            'title' => $c['title'],
            'description' => $c['description'] ?? '',
            'game_tag' => $c['game_tag'] ?? '',
            'views' => (int)$c['views'],
            'likes' => (int)$c['likes'],
            'comments' => (int)$c['comments'],
            'liked' => !empty($c['liked']),
            'featured' => !empty($c['featured']),
            'url' => SITE_URL . '/uploads/clips/' . rawurlencode($c['file']),
            'user' => [
                'id' => (int)$c['user_id'],
                'username' => $c['username'],
                'display_name' => $c['display_name'] ?: $c['username'],
                'avatar' => $c['avatar'],
                'avatar_color' => $c['avatar_color'] ?: '#6c5ce7',
            ],
            'created_at' => $c['created_at'],
        ];
    }
    jout(['clips' => $out, 'next_after' => $out ? end($out)['id'] : 0]);
}

// ---- Like toggle ----
if ($action === 'like' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        jout(['error' => 'CSRF']);
    }
    $clipId = (int)($_POST['clip_id'] ?? 0);
    $stmt = db()->prepare('SELECT id, user_id FROM clips WHERE id = ? AND active = 1');
    $stmt->execute([$clipId]);
    $clip = $stmt->fetch();
    if (!$clip) jout(['error' => 'Clip nicht gefunden']);

    $chk = db()->prepare('SELECT 1 FROM clip_likes WHERE clip_id = ? AND user_id = ?');
    $chk->execute([$clipId, $me['id']]);
    if ($chk->fetchColumn()) {
        db()->prepare('DELETE FROM clip_likes WHERE clip_id = ? AND user_id = ?')->execute([$clipId, $me['id']]);
        $liked = false;
    } else {
        db()->prepare('INSERT IGNORE INTO clip_likes (clip_id, user_id) VALUES (?, ?)')->execute([$clipId, $me['id']]);
        $liked = true;
        if ((int)$clip['user_id'] !== (int)$me['id'] && function_exists('notify')) {
            notify((int)$clip['user_id'], 'clip_like', (int)$me['id'],
                ($me['display_name'] ?: $me['username']) . ' gefällt dein Clip',
                SITE_URL . '/clip.php?id=' . $clipId);
        }
    }
    $cnt = (int)db()->prepare('SELECT COUNT(*) FROM clip_likes WHERE clip_id = ?')->execute([$clipId]) ?: 0;
    $cstmt = db()->prepare('SELECT COUNT(*) FROM clip_likes WHERE clip_id = ?');
    $cstmt->execute([$clipId]);
    $cnt = (int)$cstmt->fetchColumn();
    jout(['ok' => true, 'liked' => $liked, 'likes' => $cnt]);
}

// ---- Comments list ----
if ($action === 'comments') {
    $clipId = (int)($_GET['clip_id'] ?? 0);
    $stmt = db()->prepare("
        SELECT cm.id, cm.content, cm.created_at, u.username, u.display_name, u.avatar, u.avatar_color
        FROM clip_comments cm
        JOIN users u ON u.id = cm.user_id
        WHERE cm.clip_id = ?
        ORDER BY cm.id DESC
        LIMIT 50
    ");
    $stmt->execute([$clipId]);
    $list = [];
    foreach ($stmt->fetchAll() as $r) {
        $list[] = [
            'id' => (int)$r['id'],
            'content' => $r['content'],
            'time' => date('d.m. H:i', strtotime($r['created_at'])),
            'user' => [
                'username' => $r['username'],
                'display_name' => $r['display_name'] ?: $r['username'],
                'avatar' => $r['avatar'],
                'avatar_color' => $r['avatar_color'] ?: '#6c5ce7',
            ],
        ];
    }
    jout(['comments' => $list]);
}

// ---- Add comment ----
if ($action === 'comment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        jout(['error' => 'CSRF']);
    }
    $clipId = (int)($_POST['clip_id'] ?? 0);
    $text = trim((string)($_POST['content'] ?? ''));
    if ($text === '' || mb_strlen($text) > 500) {
        jout(['error' => 'Kommentar ungültig (1–500 Zeichen)']);
    }
    $stmt = db()->prepare('SELECT id, user_id FROM clips WHERE id = ? AND active = 1');
    $stmt->execute([$clipId]);
    $clip = $stmt->fetch();
    if (!$clip) jout(['error' => 'Clip nicht gefunden']);

    db()->prepare('INSERT INTO clip_comments (clip_id, user_id, content) VALUES (?, ?, ?)')
        ->execute([$clipId, $me['id'], $text]);
    $cid = (int)db()->lastInsertId();

    if ((int)$clip['user_id'] !== (int)$me['id'] && function_exists('notify')) {
        notify((int)$clip['user_id'], 'clip_comment', (int)$me['id'],
            ($me['display_name'] ?: $me['username']) . ' hat deinen Clip kommentiert',
            SITE_URL . '/clip.php?id=' . $clipId);
    }

    $cstmt = db()->prepare('SELECT COUNT(*) FROM clip_comments WHERE clip_id = ?');
    $cstmt->execute([$clipId]);
    jout([
        'ok' => true,
        'comments' => (int)$cstmt->fetchColumn(),
        'comment' => [
            'id' => $cid,
            'content' => $text,
            'time' => date('d.m. H:i'),
            'user' => [
                'username' => $me['username'],
                'display_name' => $me['display_name'] ?: $me['username'],
                'avatar' => $me['avatar'] ?? null,
                'avatar_color' => $me['avatar_color'] ?? '#6c5ce7',
            ],
        ],
    ]);
}

// ---- View ping ----
if ($action === 'view' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $clipId = (int)($_POST['clip_id'] ?? 0);
    if ($clipId > 0) {
        try {
            db()->prepare('UPDATE clips SET views = views + 1 WHERE id = ? AND active = 1')->execute([$clipId]);
        } catch (Throwable $e) {}
    }
    jout(['ok' => true]);
}

jout(['error' => 'Unbekannte Aktion']);
