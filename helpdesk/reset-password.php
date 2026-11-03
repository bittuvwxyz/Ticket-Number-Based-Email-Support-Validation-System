<?php
/**
 * Password Reset Form Page
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpdesk-private/config/bootstrap.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/AuthService.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/SecurityService.php';

AuthService::startSecureSession();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = null;
$success = null;

if (empty($token)) {
    $error = "Missing password reset token.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!SecurityService::validateCsrfToken($submittedCsrf)) {
        $error = "Security token mismatch or expired. Please submit the form again.";
    } else {
        $newPassword = $_POST['password'] ?? '';
        $confirmPassword = $_POST['password_confirm'] ?? '';

        if (strlen($newPassword) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif ($newPassword !== $confirmPassword) {
            $error = "Password confirmation does not match.";
        } else {
            $result = AuthService::resetPassword($token, $newPassword);
            if ($result['success']) {
                $success = $result['message'];
            } else {
                $error = $result['error'];
            }
        }
    }
}

$pageTitle = "Choose New Password";
require_once __DIR__ . '/includes/header.php';
$csrfToken = SecurityService::generateCsrfToken();
?>

<div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="avatar bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 48px; height: 48px;">
                        <i class="bi bi-shield-lock fs-4"></i>
                    </div>
                    <h4 class="fw-bold">New Password</h4>
                    <p class="text-muted small">Enter and confirm your new secure password</p>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success py-2 small text-center" role="alert">
                        <i class="bi bi-check-circle-fill me-1"></i> <?= htmlspecialchars($success) ?>
                    </div>
                    <div class="text-center mt-3">
                        <a href="login.php" class="btn btn-primary w-100 py-2 fw-semibold">Sign In Now</a>
                    </div>
                <?php else: ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2 small" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($token)): ?>
                        <form method="POST" action="reset-password.php" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">New Password</label>
                                <input type="password" name="password" class="form-control" required placeholder="Minimum 8 characters" autofocus>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-semibold">Confirm New Password</label>
                                <input type="password" name="password_confirm" class="form-control" required placeholder="Re-type password">
                            </div>

                            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                                <i class="bi bi-check2-circle me-1"></i> Save New Password
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
