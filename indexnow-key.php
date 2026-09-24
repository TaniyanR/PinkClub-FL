<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/indexnow.php';

header('Content-Type: text/plain; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');
if (!pcf_indexnow_enabled()) {
    http_response_code(404);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    echo pcf_indexnow_key();
}
