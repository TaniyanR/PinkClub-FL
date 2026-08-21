<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: private, no-store, max-age=0');

function vr_affiliate_decode_raw(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value)) {
        return [];
    }

    $current = trim($value);
    for ($i = 0; $i < 2; $i++) {
        if ($current === '') {
            return [];
        }
        $decoded = json_decode($current, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (!is_string($decoded)) {
            return [];
        }
        $current = trim($decoded);
    }

    return [];
}

function vr_affiliate_normalize_url(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }

    $candidate = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (str_starts_with($candidate, '//')) {
        $candidate = 'https:' . $candidate;
    }

    return filter_var($candidate, FILTER_VALIDATE_URL) !== false ? $candidate : '';
}

function vr_affiliate_find_raw_url(array $value): string
{
    foreach (['affiliateURL', 'affiliate_url'] as $key) {
        if (!array_key_exists($key, $value)) {
            continue;
        }
        $candidate = vr_affiliate_normalize_url($value[$key]);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    foreach ($value as $child) {
        if (!is_array($child)) {
            continue;
        }
        $candidate = vr_affiliate_find_raw_url($child);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function vr_affiliate_allowed_host(string $url): bool
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    return $host === 'dmm.co.jp'
        || $host === 'www.dmm.co.jp'
        || $host === 'video.dmm.co.jp'
        || $host === 'dmm.com'
        || $host === 'www.dmm.com'
        || $host === 'fanza.co.jp'
        || $host === 'www.fanza.co.jp'
        || str_ends_with($host, '.dmm.co.jp')
        || str_ends_with($host, '.dmm.com')
        || str_ends_with($host, '.fanza.co.jp');
}

$itemId = max(0, (int)($_GET['id'] ?? 0));
if ($itemId <= 0) {
    http_response_code(404);
    exit;
}

try {
    $stmt = db()->prepare('SELECT affiliate_url, raw_json FROM items WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('vr_affiliate.php query failed: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

if (!is_array($item)) {
    http_response_code(404);
    exit;
}

$affiliateUrl = vr_affiliate_normalize_url((string)($item['affiliate_url'] ?? ''));
if ($affiliateUrl === '') {
    $raw = vr_affiliate_decode_raw((string)($item['raw_json'] ?? ''));
    if ($raw !== []) {
        $affiliateUrl = vr_affiliate_find_raw_url($raw);
    }
}

if ($affiliateUrl === '' || !vr_affiliate_allowed_host($affiliateUrl)) {
    http_response_code(404);
    exit;
}

header('Location: ' . $affiliateUrl, true, 302);
exit;
