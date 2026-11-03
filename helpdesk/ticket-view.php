<?php
/**
 * Ticket Details & Conversation View
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpdesk-private/config/bootstrap.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/AuthService.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/TicketService.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/SecurityService.php';

$user = AuthService::requireAuth();
$userId = (int)$user['id'];
$ticketId = (int)($_GET['id'] ?? 0);

if ($ticketId <= 0) {
    header("Location: tickets.php");
    exit;
}

// Ensure ownership or admin role
$isStaff = in_array($user['role'], ['admin', 'agent'], true);
$ticket = TicketService::getTicketById($ticketId, $isStaff ? null : $userId);

if (!$ticket) {
    header("Location: tickets.php");
    exit;
}

$error = null;

// Handle customer reply submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reply') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!SecurityService::validateCsrfToken($submittedCsrf)) {
        $error = "Session validation failed. Please try again.";
    } else {
        $replyBody = trim($_POST['reply_body'] ?? '');
        if (empty($replyBody)) {
            $error = "Message content cannot be blank.";
        } else {
            $senderType = $isStaff ? 'agent' : 'customer';
            $senderName = "{$user['first_name']} {$user['last_name']}";

            TicketService::addMessage(
                $ticketId,
                $userId,
                $senderType,
                $user['email'],
                $senderName,
                $replyBody,
                false,
                'web'
            );

            // Re-open ticket if closed and policy permits
            if ($ticket['status'] === 'closed') {
                Database::query("UPDATE `tickets` SET `status` = 'reopened' WHERE `id` = :id", ['id' => $ticketId]);
            }

            $_SESSION['flash_success'] = "Your reply has been posted successfully.";
            header("Location: ticket-view.php?id={$ticketId}");
            exit;
        }
    }
}

$messages = TicketService::getTicketMessages($ticketId, $isStaff);
$pageTitle = "Ticket " . $ticket['ticket_number'];
require_once __DIR__ . '/includes/header.php';
$csrfToken = SecurityService::generateCsrfToken();
?>

<!-- Breadcrumb & Top Bar -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="tickets.php" class="text-decoration-none">Tickets</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($ticket['ticket_number']) ?></li>
            </ol>
        </nav>
        <h4 class="fw-bold mb-0">
            <?= htmlspecialchars($ticket['subject']) ?>
        </h4>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge badge-status-<?= htmlspecialchars($ticket['status']) ?> px-3 py-2 rounded-pill fs-6">
            <?= ucfirst(htmlspecialchars($ticket['status'])) ?>
        </span>
        <a href="tickets.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> All Tickets
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger py-2 small shadow-sm" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Conversation Timeline -->
    <div class="col-lg-8">

        <!-- Email Routing Information Banner -->
        <div class="alert alert-info border-info-subtle shadow-sm py-2 px-3 small d-flex align-items-center" role="alert">
            <i class="bi bi-envelope-at text-info fs-5 me-2"></i>
            <div>
                <strong>Email Support Integration Active:</strong> You can reply directly to emails sent from this desk. Our validation engine accepts authorized replies and automatically threads them here.
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 fw-bold border-bottom">
                <i class="bi bi-chat-left-text text-primary me-2"></i>Conversation History
            </div>
            <div class="card-body p-4">
                <div class="timeline">
                    <?php foreach ($messages as $idx => $msg): ?>
                        <?php 
                            $isCustomer = ($msg['sender_type'] === 'customer');
                            $bubbleBg = $isCustomer ? 'bg-light' : 'bg-primary-subtle';
                            $badgeColor = $isCustomer ? 'bg-secondary' : 'bg-primary';
                        ?>
                        <div class="d-flex mb-4 gap-3">
                            <div class="avatar <?= $badgeColor ?> text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 42px; height: 42px;">
                                <i class="bi <?= $isCustomer ? 'bi-person' : 'bi-headset' ?> fs-5"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="card border <?= $isCustomer ? 'border-light-subtle' : 'border-primary-subtle' ?>">
                                    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center small">
                                        <div>
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($msg['sender_name'] ?: $msg['sender_email']) ?></span>
                                            <span class="badge <?= $badgeColor ?> ms-1" style="font-size: 0.7rem;">
                                                <?= ucfirst($msg['sender_type']) ?>
                                            </span>
                                            <span class="text-muted ms-2">(via <?= htmlspecialchars($msg['source']) ?>)</span>
                                        </div>
                                        <span class="text-muted"><?= date('M d, Y H:i', strtotime($msg['created_at'])) ?></span>
                                    </div>
                                    <div class="card-body py-3">
                                        <div style="white-space: pre-wrap; font-size: 0.95rem; line-height: 1.6;"><?= htmlspecialchars($msg['body_plain']) ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Reply Composer -->
        <?php if ($ticket['status'] !== 'closed' || config('app.ticket.allow_closed_replies', false)): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 fw-bold border-bottom">
                    <i class="bi bi-reply-fill text-primary me-2"></i>Post a Reply
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="ticket-view.php?id=<?= $ticketId ?>">
                        <input type="hidden" name="action" value="reply">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="mb-3">
                            <textarea name="reply_body" class="form-control" rows="4" required placeholder="Type your response or update here..."></textarea>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary px-4 fw-semibold">
                                <i class="bi bi-send-fill me-1"></i> Send Reply
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-secondary text-center py-3 mb-0" role="alert">
                <i class="bi bi-lock-fill me-1"></i> This ticket is currently closed. If you need further assistance, please submit a new support request.
            </div>
        <?php endif; ?>

    </div>

    <!-- Ticket Sidebar Metadata -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 fw-bold border-bottom">
                <i class="bi bi-info-circle text-primary me-2"></i>Ticket Information
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted">Ticket Number</span>
                        <span class="font-monospace fw-bold text-primary"><?= htmlspecialchars($ticket['ticket_number']) ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted">Current Status</span>
                        <span class="badge badge-status-<?= htmlspecialchars($ticket['status']) ?> rounded-pill">
                            <?= ucfirst(htmlspecialchars($ticket['status'])) ?>
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted">Priority</span>
                        <span class="priority-<?= htmlspecialchars($ticket['priority']) ?> fw-semibold">
                            <?= ucfirst(htmlspecialchars($ticket['priority'])) ?>
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted">Category</span>
                        <span class="fw-semibold"><?= htmlspecialchars($ticket['category']) ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted">Owner Email</span>
                        <span class="fw-semibold text-truncate" style="max-width: 180px;"><?= htmlspecialchars($ticket['customer_email']) ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted">Created</span>
                        <span><?= date('M d, Y H:i', strtotime($ticket['created_at'])) ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted">Last Updated</span>
                        <span><?= date('M d, Y H:i', strtotime($ticket['updated_at'])) ?></span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Security & Validation Note -->
        <div class="card border-0 shadow-sm bg-light">
            <div class="card-body small">
                <h6 class="fw-bold mb-2"><i class="bi bi-shield-check text-success me-1"></i>Security Guardrails</h6>
                <ul class="mb-0 ps-3 text-secondary">
                    <li>Only emails sent from <strong><?= htmlspecialchars($ticket['customer_email']) ?></strong> will be accepted for this ticket.</li>
                    <li>Each ticket is bound to a cryptographically secure reply token.</li>
                    <li>Unrecognized senders and tampered tokens are rejected or quarantined automatically.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
