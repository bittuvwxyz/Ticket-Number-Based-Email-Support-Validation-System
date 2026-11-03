<?php
/**
 * Customer & Staff Login Page
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpdesk-private/config/bootstrap.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/AuthService.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/SecurityService.php';

AuthService::startSecureSession();

// Redirect if already authenticated
if (AuthService::getCurrentUser()) {
    header("Location: " . config('app.url') . "/dashboard.php");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!SecurityService::validateCsrfToken($submittedCsrf)) {
        $error = "Security token mismatch or expired. Please reload and try again.";
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = "Please provide both email address and password.";
        } else {
            $result = AuthService::login($email, $password);
            if ($result['success']) {
                $user = $result['user'];
                if ($user['role'] === 'admin') {
                    // Admin redirect
                    header("Location: " . config('app.url') . "/dashboard.php");
                } else {
                    header("Location: " . config('app.url') . "/dashboard.php");
                }
                exit;
            } else {
                $error = $result['error'];
            }
        }
    }
}

$pageTitle = "Sign In";
require_once __DIR__ . '/includes/header.php';
$csrfToken = SecurityService::generateCsrfToken();
?>

<div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="avatar bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 48px; height: 48px;">
                        <i class="bi bi-box-arrow-in-right fs-4"></i>
                    </div>
                    <h4 class="fw-bold">Sign In</h4>
                    <p class="text-muted small">Access your tickets and conversations</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger py-2 small" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Email Address</label>
                        <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="name@example.com" autofocus>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label small fw-semibold mb-0">Password</label>
                            <a href="forgot-password.php" class="text-decoration-none small text-primary">Forgot password?</a>
                        </div>
                        <input type="password" name="password" class="form-control mt-1" required placeholder="Enter password">
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                    </button>
                </form>

                <div class="text-center mt-4 pt-2 border-top small text-muted">
                    New customer? <a href="register.php" class="text-decoration-none fw-semibold">Create account</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
