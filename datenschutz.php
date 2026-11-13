<?php
require_once __DIR__ . '/includes/functions.php';
$current = '';
$page = get_page('privacy') ?: ['title' => 'Datenschutzerklärung', 'content' => "Dieser Platzhaltertext kann im Admin-Panel unter „Seiten“ bearbeitet werden.\n\n1. Verantwortlicher\n2. Erhebung und Verarbeitung von Daten\n3. Cookies\n4. Externe Inhalte (Twitch, GitHub, YouTube)\n5. Deine Rechte (DSGVO)"];
$pageTitle = $page['title'];
include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal"><h1><?= e($page['title']) ?></h1></div></section>
<section class="section"><div class="container"><div class="card glass prose reveal"><?= nl2br(allowed_html($page['content'])) ?></div></div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
