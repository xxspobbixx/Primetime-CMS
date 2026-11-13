<?php
require_once __DIR__ . '/includes/functions.php';
$current = '';
$pageTitle = 'Kontakt';
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!empty($_POST['website'])) { // Honeypot
        $sent = true;
    } else {
        $name    = trim((string)($_POST['name'] ?? ''));
        $email   = trim((string)($_POST['email'] ?? ''));
        $subject = trim((string)($_POST['subject'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
            flash('error', 'Bitte Name, gültige E-Mail und Nachricht angeben.');
        } elseif (mb_strlen($message) > 5000) {
            flash('error', 'Nachricht zu lang (max. 5000 Zeichen).');
        } else {
            db()->prepare('INSERT INTO contact_messages (name, email, subject, message) VALUES (?, ?, ?, ?)')
                ->execute([$name, $email, $subject !== '' ? $subject : null, $message]);
            $adminMail = trim(setting('email'));
            if ($adminMail !== '' && filter_var($adminMail, FILTER_VALIDATE_EMAIL)) {
                @mail($adminMail, '[' . setting('site_name', 'Webseite') . '] Kontakt: ' . ($subject ?: 'Neue Nachricht'),
                    "Name: {$name}\nE-Mail: {$email}\n\n{$message}", 'From: noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            }
            $sent = true;
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal"><h1>✉️ Kontakt</h1>
<p class="muted">Fragen, Feedback oder Anliegen? Schreib uns!</p></div></section>
<section class="section"><div class="container narrow">
    <?php if ($sent): ?>
        <div class="card glass reveal"><div class="flash flash-success">✅ Nachricht gesendet! Wir melden uns so schnell wie möglich.</div>
        <a class="btn" href="<?= SITE_URL ?>/index.php">Zur Startseite</a></div>
    <?php else: ?>
    <form method="post" class="card glass form-stack reveal">
        <?= csrf_field() ?>
        <input type="text" name="website" class="hp-field" tabindex="-1" autocomplete="off">
        <div class="form-grid">
            <label>Dein Name <input type="text" name="name" required></label>
            <label>Deine E-Mail <input type="email" name="email" required></label>
        </div>
        <label>Betreff (optional) <input type="text" name="subject" maxlength="200"></label>
        <label>Nachricht <textarea name="message" rows="7" maxlength="5000" required></textarea></label>
        <button class="btn" type="submit">Nachricht absenden</button>
    </form>
    <?php endif; ?>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
