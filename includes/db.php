<?php
require_once __DIR__ . '/../config/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            exit('<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Datenbank-Fehler</title>
                <style>body{font-family:sans-serif;background:#0b0f1a;color:#e8ecf4;display:grid;place-items:center;min-height:100vh;margin:0}
                .box{max-width:520px;padding:2rem;border:1px solid rgba(255,255,255,.1);border-radius:12px;background:rgba(255,255,255,.04)}
                code{background:rgba(255,255,255,.1);padding:.15rem .4rem;border-radius:6px}</style></head>
                <body><div class="box"><h1>⚠️ Datenbankverbindung fehlgeschlagen</h1>
                <p>Die Webseite kann sich nicht mit der MySQL-Datenbank verbinden.</p>
                <p><strong>Häufigste Ursache:</strong> Beim Hochladen/Entpacken wurde die
                <code>config/config.php</code> mit den Platzhalter-Zugangsdaten überschrieben.</p>
                <p>Bitte in der <code>config/config.php</code> die Werte für
                <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code> und
                <code>DB_PASS</code> erneut eintragen.</p>
                <p style="color:#9aa4b8;font-size:.85rem">Fehlerdetails: ' . htmlspecialchars($e->getMessage()) . '</p>
                </div></body></html>');
        }
    }
    return $pdo;
}
