<?php
// Redirect to add.php which handles both add and edit
$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }
header('Location: add.php?id=' . $id);
exit;
