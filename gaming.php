<?php
require_once __DIR__ . '/includes/functions.php';
$current = 'gaming';
$gaming = get_page('gaming');
$twitchChannel = trim(setting('twitch_channel', ''));
$parent = $_SERVER['HTTP_HOST'] ?? 'localhost';
include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container reveal">
        <h1>Gaming &amp; Live Streams</h1>
        <p class="muted">Live-Streams, Streamplan und Community-Links.</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if ($twitchChannel): ?>
        <div class="grid grid-2 stream-grid">
            <div class="card glass reveal">
                <div class="card-head">
                    <span class="live-dot" id="liveDot"></span>
                    <h3>Live-Player</h3>
                </div>
                <div class="embed-box">
                    <iframe
                        src="https://player.twitch.tv/?channel=<?= urlencode($twitchChannel) ?>&parent=<?= urlencode($parent) ?>"
                        allowfullscreen
                        title="Twitch Live Stream"></iframe>
                </div>
                <p class="muted small">Hinweis: Damit der Player online funktioniert, muss deine Domain in der
                <a href="https://dev.twitch.tv/console" target="_blank" rel="noopener">Twitch Developer Console</a> als Domain hinterlegt sein.</p>
            </div>
            <div class="card glass reveal">
                <div class="card-head"><h3>Live-Chat</h3></div>
                <div class="embed-box">
                    <iframe
                        src="https://www.twitch.tv/embed/<?= urlencode($twitchChannel) ?>/chat?parent=<?= urlencode($parent) ?>"
                        title="Twitch Chat"></iframe>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card glass reveal"><p>Der Twitch-Kanal wird in Kürze bekannt gegeben. Schau bald wieder vorbei!</p></div>
        <?php endif; ?>
    </div>
</section>

<section class="section section-alt">
    <div class="container split">
        <div class="reveal">
            <h2><?= e($gaming['title'] ?? 'Streamplan & Infos') ?></h2>
            <div class="prose"><?= nl2br(allowed_html($gaming['content'] ?? '')) ?></div>
        </div>
        <aside class="card glass reveal">
            <h3>Community &amp; Kanäle</h3>
            <div class="social-row vertical">
                <?php foreach (social_links() as $s): ?>
                    <a class="btn btn-ghost" href="<?= e($s['url']) ?>" target="_blank" rel="noopener"><?= e($s['label']) ?> →</a>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
