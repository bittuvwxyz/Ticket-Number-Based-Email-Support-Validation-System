<?php
/**
 * Email Verification Endpoint
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpdesk-private/config/bootstrap.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/AuthService.php';

AuthService::startSecureSession();

$token = trim($_GET['token'] ?? '');
$result = null;

if (!empty($token)) {
    $result = AuthService::verifyEmail($token);
} else {
    $result = ['success' => false, 'error' => 'No verification token provided in request.'];
}

$pageTitle = "Email Verification";
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm border-0 text-center p-4">
            <div class="card-body">
                <?php if ($result['success']): ?>
                    <div class="avatar bg-success text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 56px; height: 56px;">
                        <i class="bi bi-check-lg fs-3"></i>
                    </div>
                    <h4 class="fw-bold text-success mb-2">Email Verified</h4>
                    <p class="text-secondary mb-4"><?= htmlspecialchars($result['message']) ?></p>
                    <a href="login.php" class="btn btn-primary px-4 py-2 fw-semibold">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Proceed to Login
                    </a>
                <?php else: ?>
                    <div class="avatar bg-danger text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 56px; height: 56px;">
                        <i class="bi bi-x-lg fs-3"></i>
                    </div>
                    <h4 class="fw-bold text-danger mb-2">Verification Failed</h4>
                    <p class="text-secondary mb-4"><?= htmlspecialchars($result['error']) ?></p>
                    <a href="login.php" class="btn btn-outline-secondary px-4 py-2">
                        Back to Login
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
