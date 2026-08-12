<?php
declare(strict_types=1);

$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
chdir($projectRoot);

require_once __DIR__ . '/../lib/scheduler.php';
require_once __DIR__ . '/../lib/app_features.php';
require_once __DIR__ . '/../lib/access_analytics.php';
require_once __DIR__ . '/../lib/resource_maintenance.php';


/** @return resource|null */
function auto_import_lock()
{
    $lockDirectory = dirname(__DIR__) . '/storage/locks';
    if (!is_dir($lockDirectory) && !@mkdir($lockDirectory, 0775, true) && !is_dir($lockDirectory)) {
        throw new RuntimeException('cronロック用ディレクトリを作成できません');
    }
    $handle = @fopen($lockDirectory . '/auto-import.lock', 'c');
    if (!is_resource($handle)) {
        throw new RuntimeException('cronロックファイルを開けません');
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }
    return $handle;
}
function auto_import_config_diagnostics(): string
{
    $configPath = realpath(__DIR__ . '/../config.local.php') ?: (__DIR__ . '/../config.local.php');
    $exists = is_file($configPath) ? 'yes' : 'no';
    $readable = is_readable($configPath) ? 'yes' : 'no';
    $cwd = getcwd() ?: '';

    return sprintf('cwd=%s config.local.php=%s exists=%s readable=%s', $cwd, $configPath, $exists, $readable);
}

function main(): int
{
    $lockHandle = auto_import_lock();
    if (!is_resource($lockHandle)) {
        echo '[' . date('Y-m-d H:i:s') . " auto_import skipped: another process is running\n";
        return 0;
    }

    try {
        $result = maybe_run_scheduled_jobs();
        rss_widget_bootstrap();
        rss_refresh_stale_sources(2, 1800, 2);
        analytics_maybe_cleanup_old_logs(730, 2000, true);
        pcf_resource_cleanup(db(), 500);
        $status = (string)($result['status'] ?? 'unknown');
        $syncedCount = (int)($result['synced_count'] ?? 0);
        $message = trim((string)($result['message'] ?? ''));
        echo '[' . date('Y-m-d H:i:s') . "] maybe_run_scheduled_jobs() status={$status} synced={$syncedCount}";
        if ($message !== '') {
            echo " message={$message}";
        }
        echo "\n";
        return 0;
    } catch (Throwable $e) {
        $diagnostics = auto_import_config_diagnostics();
        error_log('[auto_import] ' . $e->getMessage() . ' [' . $diagnostics . ']');
        fwrite(STDERR, $e->getMessage() . ' [' . $diagnostics . ']' . "\n");
        return 1;
    } finally {
        @flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(main());
}
