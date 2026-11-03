<?php
/**
 * Create Support Ticket Form
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpdesk-private/config/bootstrap.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/AuthService.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/TicketService.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/SecurityService.php';

$user = AuthService::requireAuth();
$userId = (int)$user['id'];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!SecurityService::validateCsrfToken($submittedCsrf)) {
        $error = "Session validation failed. Please submit the form again.";
    } else {
        $subject = trim($_POST['subject'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = $_POST['priority'] ?? 'medium';
        $category = $_POST['category'] ?? 'General';

        $result = TicketService::createTicket($userId, $subject, $description, $priority, $category);

        if ($result['success']) {
            $_SESSION['flash_success'] = $result['message'];
            header("Location: ticket-view.php?id=" . $result['ticket_id']);
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

$pageTitle = "Create Support Request";
require_once __DIR__ . '/includes/header.php';
$csrfToken = SecurityService::generateCsrfToken();
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex align-items-center mb-3">
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm me-3">
                <i class="bi bi-arrow-left"></i> Dashboard
            </a>
            <h4 class="fw-bold mb-0">Submit a New Support Request</h4>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small shadow-sm" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5">
                <form method="POST" action="create-ticket.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Subject <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="form-control" required placeholder="Brief summary of your inquiry" value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>" autofocus>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Category <span class="text-danger">*</span></label>
                            <select name="category" class="form-select" required>
                                <option value="General" <?= (($_POST['category'] ?? '') === 'General') ? 'selected' : '' ?>>General Inquiry</option>
                                <option value="Technical" <?= (($_POST['category'] ?? '') === 'Technical') ? 'selected' : '' ?>>Technical Support</option>
                                <option value="Billing" <?= (($_POST['category'] ?? '') === 'Billing') ? 'selected' : '' ?>>Billing & Payments</option>
                                <option value="Account" <?= (($_POST['category'] ?? '') === 'Account') ? 'selected' : '' ?>>Account & Security</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Priority <span class="text-danger">*</span></label>
                            <select name="priority" class="form-select" required>
                                <option value="low" <?= (($_POST['priority'] ?? '') === 'low') ? 'selected' : '' ?>>Low</option>
                                <option value="medium" <?= (($_POST['priority'] ?? 'medium') === 'medium') ? 'selected' : '' ?>>Medium (Normal)</option>
                                <option value="high" <?= (($_POST['priority'] ?? '') === 'high') ? 'selected' : '' ?>>High</option>
                                <option value="urgent" <?= (($_POST['priority'] ?? '') === 'urgent') ? 'selected' : '' ?>>Urgent</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-semibold">Detailed Description <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control" rows="6" required placeholder="Please provide complete details, steps to reproduce, or any relevant context..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                        <div class="form-text small">Once created, you will receive an email confirmation and can reply directly via email or through this portal.</div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="dashboard.php" class="btn btn-outline-secondary px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4 fw-semibold">
                            <i class="bi bi-send me-1"></i> Submit Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
