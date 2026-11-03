<?php
/**
 * Forgot Password Request Page
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpdesk-private/config/bootstrap.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/AuthService.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/SecurityService.php';

AuthService::startSecureSession();

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!SecurityService::validateCsrfToken($submittedCsrf)) {
        $error = "Invalid or expired session token. Please try again.";
    } else {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            $result = AuthService::initiatePasswordReset($email);
            $message = $result['message'];
        }
    }
}

$pageTitle = "Reset Password";
require_once __DIR__ . '/includes/header.php';
$csrfToken = SecurityService::generateCsrfToken();
?>

<div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="avatar bg-warning text-dark rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 48px; height: 48px;">
                        <i class="bi bi-key-fill fs-4"></i>
                    </div>
                    <h4 class="fw-bold">Forgot Password</h4>
                    <p class="text-muted small">Enter your email to receive recovery instructions</p>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-success py-2 small" role="alert">
                        <i class="bi bi-check-circle-fill me-1"></i> <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger py-2 small" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if (!$message): ?>
                    <form method="POST" action="forgot-password.php" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Account Email</label>
                            <input type="email" name="email" class="form-control" required placeholder="name@example.com" autofocus>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                            <i class="bi bi-send me-1"></i> Send Reset Link
                        </button>
                    </form>
                <?php endif; ?>

                <div class="text-center mt-4 pt-2 border-top small text-muted">
                    Remember your credentials? <a href="login.php" class="text-decoration-none fw-semibold">Return to login</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
