<?php
require __DIR__ . '/_lib.php';

/**
 * Serves a single .webp image out of the BeReal export. The path-guard regex
 * forbids anything outside Photos/{post,profile,realmoji}/<basename>.webp, and
 * realpath() ensures the resolved file is still inside the Photos/ subtree.
 */

$p = (string)($_GET['p'] ?? '');
$p = ltrim($p, '/');

if (!preg_match('#^Photos/(post|profile|realmoji)/[A-Za-z0-9_\-]+\.webp$#', $p)) {
    http_response_code(400);
    exit('bad path');
}

$root = realpath(data_root() . '/Photos');
$abs  = realpath(data_root() . '/' . $p);
if ($root === false || $abs === false
    || strncmp($abs, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0
    || !is_file($abs)) {
    http_response_code(404);
    exit('not found');
}

header('Content-Type: image/webp');
header('Content-Length: ' . filesize($abs));
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($abs);
