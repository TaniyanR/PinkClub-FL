<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/rate_limit.php';
require_once __DIR__ . '/partials/_helpers.php';

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
}

$message = '';
$messageType = 'success';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!rate_limit_allow('password_reset', 3, 900)) {
        http_response_code(429);
        $message = '短時間に複数回の申請がありました。15分ほど待ってから、もう一度お試しください。';
        $messageType = 'error';
    } elseif (!csrf_verify((string)($_POST['_token'] ?? ''))) {
        $message = '画面の有効期限が切れました。ページを再読み込みして、もう一度お試しください。';
        $messageType = 'error';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'メールアドレスの形式を確認してください。';
            $messageType = 'error';
        } else {
            $mailAttempted = false;
            $mailSent = false;
            $fromEmailForLog = 'noreply@localhost';
            $registeredEmail = setting_admin_email('');

            if ($registeredEmail !== '' && hash_equals($registeredEmail, $email)) {
                $configuredAdminId = (int)site_setting_get('auth.admin_user_id', '0');
                $configuredLoginId = trim(site_setting_get('auth.login_id', ''));
                if ($configuredAdminId > 0) {
                    $stmt = db()->prepare('SELECT id, username FROM admins WHERE id=:id LIMIT 1');
                    $stmt->execute([':id' => $configuredAdminId]);
                } elseif ($configuredLoginId !== '') {
                    $stmt = db()->prepare('SELECT id, username FROM admins WHERE username=:username LIMIT 1');
                    $stmt->execute([':username' => $configuredLoginId]);
                } else {
                    $stmt = db()->query("SELECT id, username FROM admins ORDER BY (username = 'admin') ASC, id ASC LIMIT 1");
                }

                $admin = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
                if (is_array($admin) && db_table_exists('admin_password_resets')) {
                    $token = bin2hex(random_bytes(32));
                    $tokenStored = false;
                    $pdo = db();
                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare('UPDATE admin_password_resets SET used_at=NOW() WHERE admin_user_id=:admin_user_id AND used_at IS NULL')
                            ->execute([':admin_user_id' => (int)$admin['id']]);
                        $pdo->prepare('INSERT INTO admin_password_resets(admin_user_id,token_hash,expires_at) VALUES (:admin_user_id,:token_hash,DATE_ADD(NOW(), INTERVAL 1 HOUR))')
                            ->execute([
                                ':admin_user_id' => (int)$admin['id'],
                                ':token_hash' => hash('sha256', $token),
                            ]);
                        $pdo->commit();
                        $tokenStored = true;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('password reset token creation failed: ' . $e->getMessage());
                    }

                    if ($tokenStored) {
                        $resetUrl = public_url('reset_password.php') . '?token=' . rawurlencode($token);
                        $body = "管理者パスワード再設定の申請を受け付けました。\n\n"
                            . "ログインID: " . (string)$admin['username'] . "\n"
                            . "再設定URL: " . $resetUrl . "\n\n"
                            . "このURLの有効期限は1時間で、一度使用すると無効になります。\n"
                            . "申請した覚えがない場合は、このメールを破棄してください。";
                        $host = (string)(parse_url(app_url(), PHP_URL_HOST) ?: 'localhost');
                        $host = preg_replace('/[^a-z0-9.-]/i', '', $host) ?: 'localhost';
                        $host = preg_replace('/^www\./i', '', $host) ?: 'localhost';
                        $fromEmailForLog = 'noreply@' . $host;
                        $subject = '[PinkClub-FL] パスワード再設定';
                        $encodedSubject = function_exists('mb_encode_mimeheader')
                            ? mb_encode_mimeheader($subject, 'UTF-8')
                            : $subject;
                        $headers = "From: PinkClub-FL <{$fromEmailForLog}>\r\nContent-Type: text/plain; charset=UTF-8";
                        $mailAttempted = true;
                        $mailSent = @mail($email, $encodedSubject, $body, $headers);
                    }
                }
            }

            if ($mailAttempted) {
                try {
                    db()->prepare('INSERT INTO mail_logs(direction,from_name,from_email,to_email,subject,body,status,last_error,created_at,updated_at) VALUES ("out",NULL,:from,:to,:subj,:body,:status,:err,NOW(),NOW())')
                        ->execute([
                            ':from' => $fromEmailForLog,
                            ':to' => $email,
                            ':subj' => 'Password Reset',
                            ':body' => 'パスワード再設定メールを送信しました。再設定URLはログへ保存していません。',
                            ':status' => $mailSent ? 'sent' : 'failed',
                            ':err' => $mailSent ? null : 'mail() returned false',
                        ]);
                } catch (Throwable) {
                }
            }

            // アカウントの存在やメール送信結果を第三者へ知らせない。
            $message = '入力情報を受け付けました。登録情報と一致する場合は、数分以内に再設定メールが届きます。';
            $messageType = 'success';
        }
    }
}

$faviconPath = trim(site_setting_get('site.favicon_path', ''));
$faviconUrl = $faviconPath !== '' ? public_url($faviconPath) : '';
$faviconType = strtolower((string)pathinfo($faviconPath, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/x-icon';

?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>パスワード再設定メール | PinkClub-FL</title>
  <?php if ($faviconUrl !== ''): ?>
    <link rel="icon" href="<?= e($faviconUrl) ?>" sizes="any" type="<?= e($faviconType) ?>">
    <link rel="shortcut icon" href="<?= e($faviconUrl) ?>" type="<?= e($faviconType) ?>">
    <link rel="apple-touch-icon" href="<?= e($faviconUrl) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="login-page">
  <main class="login-wrap">
    <section class="login-card" aria-labelledby="forgot-password-title">
      <h1 id="forgot-password-title" class="login-title">PinkClub-FL</h1>
      <p class="login-subtitle">パスワード再設定メール</p>

      <?php if ($message !== '') : ?><p class="<?= $messageType === 'error' ? 'flash error' : 'flash success' ?>" role="<?= $messageType === 'error' ? 'alert' : 'status' ?>"><?php echo e($message); ?></p><?php endif; ?>
      <form method="post" class="login-form">
        <input type="hidden" name="_token" value="<?php echo e(csrf_token()); ?>">
        <label class="login-label">
          登録メールアドレス
          <input class="login-input" name="email" type="email" autocomplete="email" required>
        </label>
        <button class="login-button" type="submit">再設定メールを送る</button>
      </form>

      <p class="login-note">再設定URLは1時間有効で、一度使用すると無効になります。</p>
      <p class="login-note"><a href="login0718.php">ログインへ戻る</a></p>
    </section>
  </main>
</body>
</html>
