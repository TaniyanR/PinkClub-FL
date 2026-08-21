<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

header('X-Robots-Tag: noindex, nofollow', true);

function sample_images_decode_raw(mixed $value): array
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

function sample_images_normalize_url(string $value): string
{
    $url = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '') {
        return '';
    }
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }
    if (!preg_match('#^https?://#i', $url)) {
        return '';
    }
    return $url;
}

function sample_images_collect(mixed $value, array &$images): void
{
    if (is_string($value)) {
        $decoded = sample_images_decode_raw($value);
        if ($decoded !== []) {
            sample_images_collect($decoded, $images);
            return;
        }

        $candidate = sample_images_normalize_url($value);
        if ($candidate !== '') {
            $images[] = $candidate;
        }
        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $child) {
        sample_images_collect($child, $images);
    }
}

function sample_images_from_raw(array $raw): array
{
    if (!array_key_exists('sampleImageURL', $raw)) {
        return [];
    }

    $sampleRoot = $raw['sampleImageURL'];
    if (is_string($sampleRoot)) {
        $decoded = sample_images_decode_raw($sampleRoot);
        if ($decoded !== []) {
            $sampleRoot = $decoded;
        }
    }

    $images = [];
    if (is_array($sampleRoot)) {
        foreach (['sample_l', 'sample_s'] as $sizeKey) {
            if (!array_key_exists($sizeKey, $sampleRoot)) {
                continue;
            }
            $sizeImages = [];
            sample_images_collect($sampleRoot[$sizeKey], $sizeImages);
            if ($sizeImages !== []) {
                $images = $sizeImages;
                break;
            }
        }

        if ($images === []) {
            sample_images_collect($sampleRoot, $images);
        }
    } else {
        sample_images_collect($sampleRoot, $images);
    }

    return array_values(array_unique(array_filter($images)));
}

function sample_images_json_response(int $status, string $title, array $images, ?string $error = null): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');

    $payload = [
        'title' => $title,
        'images' => array_values($images),
    ];
    if ($error !== null) {
        $payload['error'] = $error;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$itemId = max(0, (int)get('item_id', 0));
$contentId = trim((string)get('content_id', ''));
$wantsJson = strtolower(trim((string)get('format', ''))) === 'json'
    || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

try {
    if ($itemId > 0) {
        $stmt = db()->prepare('SELECT id, content_id, title, raw_json FROM items WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $itemId]);
    } elseif ($contentId !== '') {
        $stmt = db()->prepare('SELECT id, content_id, title, raw_json FROM items WHERE content_id = :content_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':content_id' => $contentId]);
    } else {
        if ($wantsJson) {
            sample_images_json_response(404, '', [], 'not_found');
        }
        http_response_code(404);
        exit('商品が指定されていません。');
    }
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('sample_images.php query failed: ' . $e->getMessage());
    if ($wantsJson) {
        sample_images_json_response(500, '', [], 'server_error');
    }
    http_response_code(500);
    exit('サンプル画像を取得できませんでした。');
}

if (!is_array($item)) {
    if ($wantsJson) {
        sample_images_json_response(404, '', [], 'not_found');
    }
    http_response_code(404);
    exit('指定の商品が見つかりません。');
}

$title = trim((string)($item['title'] ?? ''));
$raw = sample_images_decode_raw((string)($item['raw_json'] ?? ''));
$images = $raw !== [] ? sample_images_from_raw($raw) : [];

if ($wantsJson) {
    sample_images_json_response(200, $title, $images);
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title !== '' ? $title . ' - サンプル画像' : 'サンプル画像') ?></title>
  <style>
    html,body{margin:0;min-height:100%;font-family:Arial,sans-serif;background:#f8f9fa;color:#222}
    .sample-page{max-width:1100px;margin:0 auto;padding:20px}
    .sample-page h1{font-size:20px;margin:0 0 16px}
    .sample-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
    .sample-grid img{display:block;width:100%;height:auto;max-height:85vh;object-fit:contain;background:#fff}
    .sample-empty{padding:24px;text-align:center;background:#fff;border:1px solid #ddd;border-radius:8px}
  </style>
</head>
<body>
  <main class="sample-page">
    <h1><?= e($title !== '' ? $title . ' のサンプル画像' : 'サンプル画像') ?></h1>
    <?php if ($images === []): ?>
      <p class="sample-empty">表示できるサンプル画像がありません。</p>
    <?php else: ?>
      <div class="sample-grid">
        <?php foreach ($images as $index => $image): ?>
          <img src="<?= e($image) ?>" alt="サンプル画像 <?= (int)$index + 1 ?>">
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
