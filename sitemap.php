<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/xml; charset=utf-8');
$urls = [
    ['loc' => SITE_URL . '/index.php',        'priority' => '1.0'],
    ['loc' => SITE_URL . '/news.php',         'priority' => '0.8'],
    ['loc' => SITE_URL . '/login.php',        'priority' => '0.5'],
    ['loc' => SITE_URL . '/register.php',     'priority' => '0.5'],
    ['loc' => SITE_URL . '/impressum.php',    'priority' => '0.3'],
    ['loc' => SITE_URL . '/datenschutz.php',  'priority' => '0.3'],
    ['loc' => SITE_URL . '/projekte.php',     'priority' => '0.7'],
];
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url><loc>' . e($u['loc']) . '</loc><priority>' . $u['priority'] . '</priority></url>' . "\n";
}
echo '</urlset>';
