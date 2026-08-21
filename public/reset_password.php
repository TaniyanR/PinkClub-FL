<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/partials/_helpers.php';

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
}

$token = trim((string)($_GET['token'] ?? ''));
$tokenIsWellFormed = preg_match('/^[a-f0-9]{64}$/', $token) === 1;
$reset = false;
if ($tokenIsWellFormed && db_table_exists('admin_password_resets')) {
    $stmt = db()->prepare('SELECT * FROM admin_password_resets WHERE token_hash=:h AND used_at IS NULL AND expires_at >= NOW() ORDER BY id DESC LIMIT 1');
    $stmt->execute([':h' => hash('sha256', $token)]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!is_array($reset)) {
    http_response_code(410);
    $pageTitle = '再設定リンクを利用できません';
    include __DIR__ . '/partials/login_header.php';
    echo '<div class="login-page"><section class="admin-card login-card"><h1>このリンクは利用できません</h1><p>有効期限が切れているか、すでに使用された可能性があります。</p><p><a href="' . e(public_url('forgot_password.php')) . '">新しい再設定メールを送る</a></p></section></div>';
    include __DIR__ . '/partials/login_footer.php';
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify((string)($_POST['_token'] ?? ''))) {
        $error = '画面の有効期限が切れました。もう一度お試しください。';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
        $adminUserId = (int)($reset['admin_user_id'] ?? 0);
        $adminStmt = db()->prepare('SELECT username FROM admins WHERE id=:id LIMIT 1');
        $adminStmt->execute([':id' => $adminUserId]);
        $loginId = (string)($adminStmt->fetchColumn() ?: '');
        $passwordError = auth_password_validation_error($password, $loginId);

        if ($passwordError !== null) {
            $error = $passwordError;
        } elseif ($password !== $passwordConfirm) {
            $error = '確認用パスワードが一致しません。';
        } else {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                $lockedStmt = $pdo->prepare('SELECT id, admin_user_id FROM admin_password_resets WHERE token_hash=:h AND used_at IS NULL AND expires_at >= NOW() LIMIT 1 FOR UPDATE');
                $lockedStmt->execute([':h' => hash('sha256', $token)]);
                $lockedReset = $lockedStmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($lockedReset) || (int)$lockedReset['admin_user_id'] !== $adminUserId) {
                    throw new RuntimeException('reset token already used or expired');
                }

                $pdo->prepare('UPDATE admins SET password_hash=:h, updated_at=NOW() WHERE id=:id LIMIT 1')
                    ->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $adminUserId]);
                $pdo->prepare('UPDATE admin_password_resets SET used_at=NOW() WHERE admin_user_id=:admin_user_id AND used_at IS NULL')
                    ->execute([':admin_user_id' => $adminUserId]);
                $pdo->commit();

                $_SESSION['forgot_password_success'] = 'パスワードを再設定しました。新しいパスワードでログインしてください。';
                app_redirect(login_url());
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'このリンクはすでに使用されたか、期限が切れました。新しい再設定メールを発行してください。';
            }
        }
    }
}

$pageTitle = 'パスワード再設定';
include __DIR__ . '/partials/login_header.php';
?>
<div class="login-page">
    <div class="login-headline"><span class="login-headline__item">PinkClub-FL</span><span class="login-headline__item">パスワード再設定</span></div>
    <?php if ($error !== '') : ?><div class="admin-card login-alert" role="alert"><p><?php echo e($error); ?></p></div><?php endif; ?>
    <form class="admin-card login-card" method="post" action="<?php echo e(public_url('reset_password.php') . '?token=' . rawurlencode($token)); ?>">
        <input type="hidden" name="_token" value="<?php echo e(csrf_token()); ?>">
        <label>新しいパスワード</label><input type="password" name="password" minlength="12" autocomplete="new-password" required>
        <label>新しいパスワード（確認）</label><input type="password" name="password_confirm" minlength="12" autocomplete="new-password" required>
        <p class="login-note">12文字以上で、「password」やログインIDと同じ文字列は使用できません。</p>
        <button type="submit">再設定する</button>
    </form>
</div>
<?php include __DIR__ . '/partials/login_footer.php';
