<?php
/**
 * Zentrale Konfiguration
 * ----------------------
 * HIER DEINE ZUGANGSDATEN EINTRAGEN:
 * Achte darauf, dass jede Zeile mit ); endet!
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'DEINE_DATENBANK');
define('DB_USER', 'DEIN_BENUTZER');
define('DB_PASS', 'DEIN_PASSWORT');
define('DB_CHARSET', 'utf8mb4');

// Basis-URL der Seite (ohne Slash am Ende), z. B. https://meine-seite.de
define('SITE_URL', 'https://DEINE-DOMAIN.de');

define('APP_ROOT', dirname(__DIR__));

// Debug-Modus: Fehlermeldungen direkt anzeigen (im Betrieb auf false lassen!)
// Zusätzlich auf JEDER Seite per ?debug=1 aktivierbar, ohne Datei zu ändern.
define('DEBUG', false);
if (DEBUG || isset($_GET['debug'])) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// Sichere Session starten
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

date_default_timezone_set('Europe/Berlin');
