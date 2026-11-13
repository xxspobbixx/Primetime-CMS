<?php $socials = social_links(); ?>
</main>
<footer class="site-footer">
    <div class="container footer-grid">
        <div>
            <div class="logo"><span class="logo-mark">&lt;/&gt;</span> <?= e(setting('site_name', 'Meine Webseite')) ?></div>
            <p class="muted"><?= e(setting('footer_text', setting('tagline', ''))) ?></p>
        </div>
        <div>
            <h4>Seiten</h4>
            <a href="<?= SITE_URL ?>/index.php">Über mich</a>
            <a href="<?= SITE_URL ?>/gaming.php">Gaming &amp; Streams</a>
            <a href="<?= SITE_URL ?>/projekte.php">Projekte</a>
            <a href="<?= SITE_URL ?>/news.php">News</a>
            <?php if (!empty($member)): ?>
            <a href="<?= SITE_URL ?>/mitglieder.php">Mitglieder</a>
            <a href="<?= SITE_URL ?>/wall.php">Wall</a>
            <?php endif; ?>
        </div>
        <div>
            <h4>Rechtliches</h4>
            <a href="<?= SITE_URL ?>/impressum.php">Impressum</a>
            <a href="<?= SITE_URL ?>/datenschutz.php">Datenschutz</a>
            <a href="<?= SITE_URL ?>/kontakt.php">Kontakt</a>
            <a href="<?= SITE_URL ?>/admin/login.php">Admin</a>
        </div>
        <?php if ($socials): ?>
        <div>
            <h4>Folgen</h4>
            <div class="social-row">
                <?php foreach ($socials as $s): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e($s['url']) ?>" target="_blank" rel="noopener"><?= e($s['label']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="container footer-bottom">
        <span>&copy; <?= date('Y') ?> <?= e(setting('owner_name', '')) ?> &middot; Alle Rechte vorbehalten.
        <?php if (plugin_active('online_counter')): ?> · 🟢 <?= count(online_users()) ?> Mitglieder online<?php endif; ?>
        <?php $dw = plugin_active('discord') ? discord_widget() : null;
        if ($dw && $dw['invite'] !== ''): ?>
            · <a href="<?= e($dw['invite']) ?>" target="_blank" rel="noopener" style="color:var(--muted)">💬 Discord: <?= (int)$dw['online'] ?> online</a>
        <?php endif; ?></span>
    </div>
</footer>
<script src="<?= SITE_URL ?>/assets/js/main.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/main.js') ?>"></script>
<?php /* PWA: Service Worker + Cookie-Consent */ ?>
<script>
if ('serviceWorker' in navigator) navigator.serviceWorker.register('<?= SITE_URL ?>/sw.js').catch(function(){});
(function () {
    try {
        if (localStorage.getItem('cookie_ok')) return;
        var b = document.createElement('div');
        b.className = 'cookie-bar';
        b.innerHTML = '<span>🍪 Diese Seite nutzt technisch notwendige Cookies &amp; Sessions. Mit der Nutzung stimmst du zu.</span>'
            + '<a href="<?= SITE_URL ?>/datenschutz.php">Mehr</a>'
            + '<button class="btn btn-sm" id="cookieOk">OK</button>';
        document.body.appendChild(b);
        document.getElementById('cookieOk').addEventListener('click', function () {
            localStorage.setItem('cookie_ok', '1');
            b.remove();
        });
    } catch (e) {}
})();
</script>
</body>
</html>
