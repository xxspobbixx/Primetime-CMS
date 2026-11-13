<?php
require_once __DIR__ . '/includes/functions.php';
unset($_SESSION['member_id']);
flash('success', 'Du wurdest abgemeldet.');
header('Location: index.php');
exit;
