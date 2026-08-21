<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Legacy compatibility: fixed pages and old external registrations may still
// point at feed.php. Keep that URL working while making feed-60.php canonical.
header('X-Robots-Tag: noindex, follow');
header('Location: ' . public_url('feed-60.php'), true, 301);
exit;
