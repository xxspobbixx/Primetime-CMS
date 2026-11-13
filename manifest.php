<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
    'name'             => setting('site_name', 'Webseite'),
    'short_name'       => mb_substr(setting('site_name', 'Webseite'), 0, 12),
    'start_url'        => SITE_URL . '/index.php',
    'scope'            => SITE_URL . '/',
    'display'          => 'standalone',
    'background_color' => '#0b0f1a',
    'theme_color'      => '#6c5ce7',
    'description'      => setting('tagline', ''),
    'icons' => [
        ['src' => SITE_URL . '/assets/img/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => SITE_URL . '/assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => SITE_URL . '/assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
