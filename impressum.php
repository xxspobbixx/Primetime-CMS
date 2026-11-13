<?php
require_once __DIR__ . '/includes/functions.php';
$current = '';
$page = get_page('imprint') ?: ['title' => 'Impressum', 'content' => "Angaben gemäß § 5 TMG\n\nMax Mustermann\nMusterstraße 1\n12345 Musterstadt\n\nE-Mail: mail@example.com\n\nDieser Platzhaltertext kann im Admin-Panel unter „Seiten“ bearbeitet werden."];
$pageTitle = $page['title'];
include __DIR__ . '/includes/header.php';
?>
<section class="page-hero"><div class="container reveal"><h1><?= e($page['title']) ?></h1></div></section>
<section class="section"><div class="container"><div class="card glass prose reveal"><?= nl2br(allowed_html($page['content'])) ?></div></div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
