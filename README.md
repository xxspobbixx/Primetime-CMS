# Primetime – Community CMS

<p align="center">
  <img src="https://about.steel-assassins.de/Logos/banner-primetime.jpg" alt="Primetime – Community CMS · PHP / MySQL" width="100%">
</p>

**Primetime** ist eine moderne Community-Website auf **PHP & MySQL**: Wall, Forum, Gruppen, Nachrichten, Mitgliederbereich und ein vollständiges Admin-Panel – self-hosted, erweiterbar und für Gaming- und Spaß-Communities gedacht.

# <p>
  <a href="https://test.srv-src.de" target="_blank" rel="noopener">
    🔗 Live-Demo ansehen
  </a>
</p>

<p align="center">
  <img src="https://about.steel-assassins.de/Logos/hero-community.jpg" alt="Primetime Community Oberfläche" width="100%">
</p>

---

## Über das Projekt

Primetime bringt klassische Community-Funktionen in ein dunkles, responsives Design mit Themes, Plugin-System und Moderationswerkzeugen. Ideal für Clans, Creator-Communities, Freundeskreise oder kleine Netzwerke, die ihre Plattform **selbst hosten** wollen – ohne SaaS-Abhängigkeit.

> **Hinweis zu diesem Release:** Basis-Version **ohne** Extra-Integrationen wie ArrowChat-Floating-Bar, Clips/Highlights, 24h-Stories oder Squad-Suche (LFG). Der interne Channel-Chat gehört zur Basis.

---

## Features

### Öffentliche Website
- Startseite, Über-mich / Seiteninhalte (CMS)
- **News** mit Artikeln
- **Projekte**-Übersicht
- **Gaming**-Bereich
- Galerie, Kontakt, Impressum & Datenschutz
- **404-** und **500-Fehlerseiten**
- **XML-Sitemap** (`sitemap.php`) für Suchmaschinen
- Social-Links (Discord, Twitch, YouTube, …)

### Community & Mitglieder
- Registrierung & Login (Benutzername oder E-Mail)
- **E-Mail-Bestätigung** bei Registrierung (Fake-Accounts erschweren)
- Mitglieder-**Panel** (Profil, Avatar, Titelbild, Passwort, E-Mail, 2FA-Option)
- Öffentliche / eingeschränkte **Profile**
- **Freunde**-System (Anfragen, annehmen)
- **Badges**, Punkte, Streaks, Tagesbonus
- **Punkte-Shop** (Namensfarben, Titel, Rahmen, …)
- Online-Status, benutzerdefinierter Status
- DSGVO: **Datenexport** aus dem Panel

### Wall (Activity Feed)
- Beiträge mit Text und Bild
- Kommentare & Reaktionen
- Lesezeichen, Melden, Moderationsoptionen
- optional anonym / Spotify-Einbindung (je nach Konfiguration)

### Forum
- Kategorien, Themen, Antworten
- Tags, „gelöst“-Status
- Voting / beste Antwort (sofern aktiv)
- Moderationsrechte für Admin & Moderatoren

### Gruppen
- Öffentliche und private Gruppen
- Gruppenbeiträge, Kommentare, Mitgliedschaft
- Gruppen-Meldungen für Moderatoren

### Nachrichten & Chat
- **Private Nachrichten** (PN)
- Interner **Channel-Chat** (Kanäle, Slow-Mode, Pins – je nach Setup)
- Benachrichtigungen im System

### Weitere Module (Plugin-fähig)
- Shoutbox, Events, Umfragen
- Gewinnspiele / Contests
- Ressourcen-Downloads
- Discord-Webhook / Widget (optional)
- reCAPTCHA (optional)
- Push (OneSignal, optional)
- Login-Historie, Wartungsmodus, IP-Sperren

### Admin-Panel
- Dashboard mit Statistiken (u. a. Wall-Aktivität)
- Mitglieder, Rollen (Admin, Moderator, VIP, …)
- News, Seiten, Projekte
- Forum- & Chat-Verwaltung
- Meldungen, Ban, Cleanup, Mod-Log
- Themes (inkl. saisonaler Themes)
- Plugins ein-/ausschalten
- SMTP / E-Mail-Versand testen
- Sicherheits-Übersicht

### Design & Technik
- Responsives **Dark Theme** (hell und weitere Themes wählbar)
- Akzentfarben aus den Einstellungen
- Mobile Navigation
- CSRF-Schutz, Prepared Statements, Passwort-Hashing
- Session-Cookies (HttpOnly, SameSite)
- Optionale Security-Header
- Uploads ohne PHP-Ausführung (`.htaccess`)

---

## Tech-Stack

| Komponente | |
|------------|--|
| Backend | PHP 8.0+ (empfohlen 8.1 / 8.2) |
| Datenbank | MySQL 5.7+ / MariaDB 10.3+ |
| Frontend | HTML, CSS, Vanilla JS |
| Server | Apache empfohlen (`mod_rewrite`) |

**PHP-Extensions:** `pdo_mysql`, `mbstring`, `json`, idealerweise `gd` (Avatare/Titelbilder)

---

## Installation

1. Release herunterladen und auf den Webspace entpacken  
2. `config/config.example.php` nach `config/config.php` kopieren  
3. In `config.php` eintragen:
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - `SITE_URL` (ohne Slash am Ende, z. B. `https://example.com`)  
4. Ordner `uploads/` (inkl. Unterordner) beschreibbar machen (`755` / `775`)  
5. Im Browser `install.php` aufrufen und Installation abschließen  
6. **`install.php` danach löschen oder absichern**  
7. Optional: Admin → SMTP einrichten (Bestätigungsmails)

```bash
# Beispiel nach dem Klonen
cp config/config.example.php config/config.php
# config.php bearbeiten, dann install.php im Browser öffnen
