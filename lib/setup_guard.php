<?php
declare(strict_types=1);

function setup_guard_marker_path(): string
{
    return __DIR__ . '/../storage/install/installed.lock';
}

function setup_guard_marker_exists(): bool
{
    return is_file(setup_guard_marker_path());
}

function setup_guard_mark_installed(): bool
{
    $path = setup_guard_marker_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log('[setup] unable to create installed-state directory');
        return false;
    }

    $payload = "PinkClub installed\n" . date('c') . "\n";
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
        error_log('[setup] unable to write installed-state marker');
        return false;
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        error_log('[setup] unable to publish installed-state marker');
        return false;
    }
    return true;
}

function setup_guard_log_has_completion_evidence(): bool
{
    if (!function_exists('installer_log_file_path')) {
        return false;
    }
    $path = installer_log_file_path();
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }
    $size = @filesize($path);
    if (!is_int($size) || $size <= 0) {
        return false;
    }
    $readSize = min($size, 65536);
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return false;
    }
    if ($size > $readSize) {
        @fseek($handle, -$readSize, SEEK_END);
    }
    $tail = (string)@fread($handle, $readSize);
    fclose($handle);
    return str_contains($tail, 'step=completed status=ok');
}

function setup_guard_is_known_installed(): bool
{
    if (setup_guard_marker_exists()) {
        return true;
    }

    if (setup_guard_log_has_completion_evidence()) {
        setup_guard_mark_installed();
        return true;
    }

    try {
        $status = installer_status();
        if (($status['completed'] ?? false) === true) {
            setup_guard_mark_installed();
            return true;
        }
    } catch (Throwable $e) {
        error_log('[setup] installed-state DB check unavailable');
    }

    return false;
}

function setup_guard_enforce_for_setup_page(): void
{
    if (!headers_sent()) {
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Robots-Tag: noindex, nofollow', true);
    }

    if (!setup_guard_is_known_installed()) {
        return;
    }

    if (function_exists('app_redirect')) {
        app_redirect(LOGIN_PATH);
    }
    header('Location: ' . LOGIN_PATH, true, 302);
    exit;
}
