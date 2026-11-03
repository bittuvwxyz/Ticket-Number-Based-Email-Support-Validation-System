<?php
/**
 * Customer Registration Page
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpdesk-private/config/bootstrap.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/AuthService.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/SecurityService.php';

AuthService::startSecureSession();

// Redirect if already logged in
if (AuthService::getCurrentUser()) {
    header("Location: " . config('app.url') . "/dashboard.php");
    exit;
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!SecurityService::validateCsrfToken($submittedCsrf)) {
        $error = "Security token mismatch or expired. Please submit the form again.";
    } else {
        $result = AuthService::register($_POST);
        if ($result['success']) {
            $_SESSION['flash_success'] = $result['message'];
            header("Location: " . config('app.url') . "/login.php");
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

$pageTitle = "Create Customer Account";
require_once __DIR__ . '/includes/header.php';
$csrfToken = SecurityService::generateCsrfToken();
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="avatar bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 48px; height: 48px;">
                        <i class="bi bi-person-plus fs-4"></i>
                    </div>
                    <h4 class="fw-bold">Create an Account</h4>
                    <p class="text-muted small">Submit and track your support tickets securely</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger py-2 small" role="alert">
                        <i class="bi bi-exclamation-circle me-1"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="register.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" required value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" placeholder="John">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control" required value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" placeholder="Doe">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="name@example.com">
                        <div class="form-text small">Your email will be used for tokenized ticket reply authorization.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Phone Number <span class="text-muted">(Optional)</span></label>
                        <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="+1 (555) 000-0000">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Password <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" required placeholder="Minimum 8 characters">
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" name="password_confirm" class="form-control" required placeholder="Re-type your password">
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                        <i class="bi bi-check-lg me-1"></i> Complete Registration
                    </button>
                </form>

                <div class="text-center mt-4 pt-2 border-top small text-muted">
                    Already registered? <a href="login.php" class="text-decoration-none fw-semibold">Sign in here</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
