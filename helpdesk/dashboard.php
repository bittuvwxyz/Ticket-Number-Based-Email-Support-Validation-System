<?php
/**
 * Customer Support Dashboard
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpdesk-private/config/bootstrap.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/AuthService.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/TicketService.php';

$user = AuthService::requireAuth();
$userId = (int)$user['id'];

$stats = TicketService::getCustomerTicketStats($userId);
$recentTickets = TicketService::getCustomerTickets($userId, null, 10);

$pageTitle = "Dashboard";
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold mb-1">Welcome, <?= htmlspecialchars($user['first_name']) ?>!</h3>
        <p class="text-muted mb-0">Track and manage your support conversations and requests</p>
    </div>
    <div>
        <a href="create-ticket.php" class="btn btn-primary px-3 py-2 shadow-sm fw-semibold">
            <i class="bi bi-plus-lg me-1"></i> Submit New Request
        </a>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="avatar bg-primary-subtle text-primary rounded-3 p-3 me-3">
                    <i class="bi bi-ticket-perforated fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small fw-medium">Total Tickets</div>
                    <h3 class="fw-bold mb-0"><?= $stats['all'] ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="avatar bg-info-subtle text-info rounded-3 p-3 me-3">
                    <i class="bi bi-clock-history fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small fw-medium">Open Tickets</div>
                    <h3 class="fw-bold mb-0"><?= $stats['open'] ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="avatar bg-warning-subtle text-warning rounded-3 p-3 me-3">
                    <i class="bi bi-hourglass-split fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small fw-medium">Pending Response</div>
                    <h3 class="fw-bold mb-0"><?= $stats['pending'] ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="avatar bg-success-subtle text-success rounded-3 p-3 me-3">
                    <i class="bi bi-check-circle fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small fw-medium">Resolved / Closed</div>
                    <h3 class="fw-bold mb-0"><?= $stats['closed'] ?></h3>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Tickets Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0"><i class="bi bi-list-task text-primary me-2"></i>Recent Support Requests</h5>
        <a href="tickets.php" class="btn btn-sm btn-outline-secondary">View All</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light small text-uppercase">
                <tr>
                    <th class="ps-3">Ticket #</th>
                    <th>Subject</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th class="text-end pe-3">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentTickets)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                            You have no support tickets yet.<br>
                            <a href="create-ticket.php" class="btn btn-sm btn-primary mt-2">Create your first ticket</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentTickets as $ticket): ?>
                        <tr>
                            <td class="ps-3 font-monospace fw-bold text-primary">
                                <?= htmlspecialchars($ticket['ticket_number']) ?>
                            </td>
                            <td>
                                <a href="ticket-view.php?id=<?= $ticket['id'] ?>" class="text-decoration-none fw-semibold text-dark">
                                    <?= htmlspecialchars($ticket['subject']) ?>
                                </a>
                            </td>
                            <td><span class="badge bg-light text-secondary border"><?= htmlspecialchars($ticket['category']) ?></span></td>
                            <td>
                                <span class="priority-<?= htmlspecialchars($ticket['priority']) ?>">
                                    <?= ucfirst(htmlspecialchars($ticket['priority'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-status-<?= htmlspecialchars($ticket['status']) ?> rounded-pill px-2 py-1">
                                    <?= ucfirst(htmlspecialchars($ticket['status'])) ?>
                                </span>
                            </td>
                            <td class="small text-muted"><?= date('M d, Y H:i', strtotime($ticket['created_at'])) ?></td>
                            <td class="text-end pe-3">
                                <a href="ticket-view.php?id=<?= $ticket['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    View <i class="bi bi-arrow-right"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
