<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/rss+xml; charset=utf-8');
$items = db()->query("SELECT title, content, created_at FROM news_articles WHERE published = 1 ORDER BY created_at DESC LIMIT 20")->fetchAll();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"><channel>
<title><?= e(setting('site_name', 'News')) ?></title>
<link><?= e(SITE_URL) ?></link>
<description><?= e(setting('tagline', '')) ?></description>
<?php foreach ($items as $it): ?>
<item>
<title><?= e($it['title']) ?></title>
<description><?= e(mb_strimwidth(strip_tags($it['content']), 0, 300, '…')) ?></description>
<link><?= e(SITE_URL) ?>/news.php</link>
<pubDate><?= date(DATE_RSS, strtotime($it['created_at'])) ?></pubDate>
</item>
<?php endforeach; ?>
</channel></rss>
