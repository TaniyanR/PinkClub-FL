<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

const PCF_SOCIAL_IMAGE_MAX_BYTES = 12582912; // 12 MiB
const PCF_SOCIAL_IMAGE_TTL = 259200; // 3 days
const PCF_SOCIAL_IMAGE_ERROR_TTL = 300; // 5 minutes
const PCF_SOCIAL_IMAGE_MAX_REDIRECTS = 3;

header('X-Content-Type-Options: nosniff', true);
header('Referrer-Policy: no-referrer', true);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

function pcf_social_image_allowed_host(string $host): bool
{
    $host = strtolower(rtrim(trim($host), '.'));
    if ($host === '') {
        return false;
    }
    foreach (['dmm.co.jp', 'dmm.com', 'fanza.co.jp'] as $root) {
        if ($host === $root || str_ends_with($host, '.' . $root)) {
            return true;
        }
    }
    return false;
}

function pcf_social_image_public_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP) !== false
        && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function pcf_social_image_resolve_public_ips(string $host): array
{
    $ips = [];
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            $ip = trim((string)($record['ip'] ?? $record['ipv6'] ?? ''));
            if ($ip !== '' && pcf_social_image_public_ip($ip)) {
                $ips[$ip] = true;
            }
        }
    }
    if ($ips === []) {
        $fallback = @gethostbynamel($host);
        if (is_array($fallback)) {
            foreach ($fallback as $ip) {
                $ip = trim((string)$ip);
                if ($ip !== '' && pcf_social_image_public_ip($ip)) {
                    $ips[$ip] = true;
                }
            }
        }
    }
    return array_keys($ips);
}

function pcf_social_image_normalize_url(string $value): string
{
    $url = trim($value);
    if ($url === '') {
        return '';
    }
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return '';
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($scheme, ['http', 'https'], true)
        || !pcf_social_image_allowed_host($host)
        || !in_array($port, [80, 443], true)
        || isset($parts['user'])
        || isset($parts['pass'])) {
        return '';
    }
    return $scheme === 'http' ? 'https://' . substr($url, 7) : $url;
}

function pcf_social_image_collect_urls(mixed $value, array &$urls): void
{
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return;
        }
        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                pcf_social_image_collect_urls($decoded, $urls);
                return;
            }
        }
        foreach (preg_split('/[\r\n,|\s]+/', $trimmed) ?: [] as $part) {
            $url = pcf_social_image_normalize_url((string)$part);
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return;
    }
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $child) {
        pcf_social_image_collect_urls($child, $urls);
    }
}

function pcf_social_image_candidates(array $item): array
{
    $urls = [];
    foreach (['image_large', 'full_package_url', 'main_image_url', 'image_url', 'image_small', 'image_list'] as $key) {
        if (array_key_exists($key, $item)) {
            pcf_social_image_collect_urls($item[$key], $urls);
        }
    }
    $raw = $item['raw_json'] ?? null;
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach (['imageURL', 'packageImage', 'image', 'images', 'sampleImageURL'] as $key) {
                if (array_key_exists($key, $decoded)) {
                    pcf_social_image_collect_urls($decoded[$key], $urls);
                }
            }
        }
    }
    return array_values(array_unique($urls));
}

function pcf_social_image_detect_type(string $bytes, string $reportedType): string
{
    $reportedType = strtolower(trim(explode(';', $reportedType, 2)[0] ?? ''));
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = strtolower((string)@finfo_buffer($finfo, $bytes));
            @finfo_close($finfo);
            if (in_array($detected, $allowed, true)) {
                return $detected;
            }
        }
    }
    return in_array($reportedType, $allowed, true) ? $reportedType : '';
}

function pcf_social_image_redirect_url(string $currentUrl, string $location): string
{
    $location = trim($location);
    if ($location === '') {
        return '';
    }
    if (str_starts_with($location, '//')) {
        return pcf_social_image_normalize_url('https:' . $location);
    }
    if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
        return pcf_social_image_normalize_url($location);
    }
    $current = parse_url($currentUrl);
    if (!is_array($current) || empty($current['host'])) {
        return '';
    }
    $base = 'https://' . $current['host'];
    if (str_starts_with($location, '/')) {
        return pcf_social_image_normalize_url($base . $location);
    }
    $path = (string)($current['path'] ?? '/');
    $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
    if ($directory === '.' || $directory === '/') {
        $directory = '';
    }
    return pcf_social_image_normalize_url($base . $directory . '/' . $location);
}

function pcf_social_image_fetch_once(string $url): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }
    $host = strtolower((string)($parts['host'] ?? ''));
    if (strtolower((string)($parts['scheme'] ?? '')) !== 'https' || !pcf_social_image_allowed_host($host)) {
        return null;
    }
    $ips = pcf_social_image_resolve_public_ips($host);
    if ($ips === []) {
        return null;
    }
    foreach ($ips as $ip) {
        $body = '';
        $contentType = '';
        $location = '';
        $tooLarge = false;
        $ch = curl_init($url);
        if ($ch === false) {
            continue;
        }
        $resolveIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PinkClub-FL-SocialCard/1.1; +https://pinkclub-fl.com/)',
            CURLOPT_REFERER => 'https://www.dmm.co.jp/',
            CURLOPT_HTTPHEADER => [
                'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language: ja,en-US;q=0.7,en;q=0.3',
            ],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$host . ':443:' . $resolveIp],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$contentType, &$location): int {
                if (stripos($header, 'Content-Type:') === 0) {
                    $contentType = trim(substr($header, strlen('Content-Type:')));
                } elseif (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, strlen('Location:')));
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > PCF_SOCIAL_IMAGE_MAX_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok === false || $tooLarge) {
            continue;
        }
        if ($status >= 300 && $status < 400 && $location !== '') {
            return ['redirect' => $location];
        }
        if ($status < 200 || $status >= 300 || $body === '') {
            continue;
        }
        $type = pcf_social_image_detect_type($body, $contentType);
        if ($type !== '') {
            return ['bytes' => $body, 'type' => $type];
        }
    }
    return null;
}

function pcf_social_image_fetch(string $url): ?array
{
    $currentUrl = pcf_social_image_normalize_url($url);
    if ($currentUrl === '') {
        return null;
    }
    $visited = [];
    for ($hop = 0; $hop <= PCF_SOCIAL_IMAGE_MAX_REDIRECTS; $hop++) {
        if (isset($visited[$currentUrl])) {
            return null;
        }
        $visited[$currentUrl] = true;
        $result = pcf_social_image_fetch_once($currentUrl);
        if (!is_array($result)) {
            return null;
        }
        if (isset($result['bytes'], $result['type'])) {
            return $result;
        }
        $location = trim((string)($result['redirect'] ?? ''));
        if ($location === '' || $hop >= PCF_SOCIAL_IMAGE_MAX_REDIRECTS) {
            return null;
        }
        $currentUrl = pcf_social_image_redirect_url($currentUrl, $location);
        if ($currentUrl === '') {
            return null;
        }
    }
    return null;
}

function pcf_social_image_extension(string $type): string
{
    return match ($type) {
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => 'jpg',
    };
}

function pcf_social_image_serve(string $path, string $type, bool $headOnly): void
{
    $size = @filesize($path);
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
    header('ETag: "' . sha1((string)@filemtime($path) . '|' . (string)$size) . '"');
    if (is_int($size) && $size >= 0) {
        header('Content-Length: ' . $size);
    }
    if (!$headOnly) {
        readfile($path);
    }
    exit;
}

function pcf_social_image_try_site_fallback(bool $headOnly): void
{
    $logoPath = '';
    try {
        if (function_exists('setting')) {
            $logoPath = trim((string)setting('site.logo_path', ''));
            if ($logoPath === '') {
                $logoPath = trim((string)setting('site_logo', ''));
            }
        }
    } catch (Throwable) {
        $logoPath = '';
    }
    if ($logoPath !== '') {
        $relative = ltrim($logoPath, '/');
        foreach ([dirname(__DIR__) . '/' . $relative, dirname(__DIR__) . '/public/' . $relative] as $candidate) {
            if (!is_file($candidate) || !is_readable($candidate)) {
                continue;
            }
            $bytes = @file_get_contents($candidate);
            if (!is_string($bytes) || $bytes === '') {
                continue;
            }
            $type = pcf_social_image_detect_type($bytes, '');
            if ($type !== '') {
                pcf_social_image_serve($candidate, $type, $headOnly);
            }
        }
    }
    http_response_code(404);
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_int($id) || $id <= 0) {
    http_response_code(404);
    exit;
}

try {
    $stmt = db()->prepare('SELECT * FROM items WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable) {
    error_log('[social-image] item lookup failed');
    $item = false;
}
if (!is_array($item)) {
    http_response_code(404);
    exit;
}

$cacheDir = dirname(__DIR__) . '/storage/cache/social-images';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
    pcf_social_image_try_site_fallback($method === 'HEAD');
}

$base = $cacheDir . '/' . $id;
$metaPath = $base . '.json';
$errorPath = $base . '.error';
$headOnly = $method === 'HEAD';

$meta = null;
if (is_file($metaPath)) {
    $decoded = json_decode((string)@file_get_contents($metaPath), true);
    if (is_array($decoded)) {
        $meta = $decoded;
    }
}
if (is_array($meta)) {
    $cachedFile = $cacheDir . '/' . basename((string)($meta['file'] ?? ''));
    $cachedType = trim((string)($meta['type'] ?? ''));
    if (is_file($cachedFile) && $cachedType !== '' && (time() - (int)@filemtime($cachedFile)) < PCF_SOCIAL_IMAGE_TTL) {
        pcf_social_image_serve($cachedFile, $cachedType, $headOnly);
    }
}

if (is_file($errorPath) && (time() - (int)@filemtime($errorPath)) < PCF_SOCIAL_IMAGE_ERROR_TTL) {
    pcf_social_image_try_site_fallback($headOnly);
}

$lockPath = $base . '.lock';
$lock = @fopen($lockPath, 'c');
if (is_resource($lock)) {
    @flock($lock, LOCK_EX);
}

if (is_file($metaPath)) {
    $decoded = json_decode((string)@file_get_contents($metaPath), true);
    if (is_array($decoded)) {
        $cachedFile = $cacheDir . '/' . basename((string)($decoded['file'] ?? ''));
        $cachedType = trim((string)($decoded['type'] ?? ''));
        if (is_file($cachedFile) && $cachedType !== '') {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
            pcf_social_image_serve($cachedFile, $cachedType, $headOnly);
        }
    }
}

$fetched = null;
foreach (pcf_social_image_candidates($item) as $candidate) {
    $fetched = pcf_social_image_fetch($candidate);
    if (is_array($fetched)) {
        break;
    }
}

if (!is_array($fetched)) {
    @touch($errorPath);
    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
    pcf_social_image_try_site_fallback($headOnly);
}

$type = (string)$fetched['type'];
$bytes = (string)$fetched['bytes'];
$ext = pcf_social_image_extension($type);
$fileName = $id . '.' . $ext;
$cachePath = $cacheDir . '/' . $fileName;
try {
    $suffix = bin2hex(random_bytes(4));
} catch (Throwable) {
    $suffix = str_replace('.', '', uniqid('', true));
}
$tmpPath = $cachePath . '.tmp-' . $suffix;
$stored = @file_put_contents($tmpPath, $bytes, LOCK_EX) !== false && @rename($tmpPath, $cachePath);
if (!$stored) {
    @unlink($tmpPath);
    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
    pcf_social_image_try_site_fallback($headOnly);
}
@chmod($cachePath, 0644);
@unlink($errorPath);
@file_put_contents($metaPath, json_encode([
    'file' => $fileName,
    'type' => $type,
    'updated_at' => time(),
], JSON_UNESCAPED_SLASHES), LOCK_EX);

if (is_resource($lock)) {
    @flock($lock, LOCK_UN);
    fclose($lock);
}

pcf_social_image_serve($cachePath, $type, $headOnly);
