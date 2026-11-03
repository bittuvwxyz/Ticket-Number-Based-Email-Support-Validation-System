<?php
/**
 * Customer Tickets Listing & Filter Page
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpdesk-private/config/bootstrap.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/AuthService.php';
require_once dirname(__DIR__) . '/helpdesk-private/services/TicketService.php';

$user = AuthService::requireAuth();
$userId = (int)$user['id'];

$statusFilter = $_GET['status'] ?? null;
if (!in_array($statusFilter, ['open', 'pending', 'closed', 'reopened', 'expired'], true)) {
    $statusFilter = null;
}

$tickets = TicketService::getCustomerTickets($userId, $statusFilter);
$stats = TicketService::getCustomerTicketStats($userId);

$pageTitle = "My Support Tickets";
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold mb-1">My Support Tickets</h3>
        <p class="text-muted mb-0">Review all active and historical support requests</p>
    </div>
    <div>
        <a href="create-ticket.php" class="btn btn-primary px-3 py-2 shadow-sm fw-semibold">
            <i class="bi bi-plus-lg me-1"></i> New Request
        </a>
    </div>
</div>

<!-- Filter Tabs -->
<ul class="nav nav-pills mb-3 gap-2">
    <li class="nav-item">
        <a class="nav-link <?= $statusFilter === null ? 'active' : 'bg-white text-secondary border' ?>" href="tickets.php">
            All (<?= $stats['all'] ?>)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $statusFilter === 'open' ? 'active' : 'bg-white text-secondary border' ?>" href="tickets.php?status=open">
            Open (<?= $stats['open'] ?>)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $statusFilter === 'pending' ? 'active' : 'bg-white text-secondary border' ?>" href="tickets.php?status=pending">
            Pending (<?= $stats['pending'] ?>)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $statusFilter === 'closed' ? 'active' : 'bg-white text-secondary border' ?>" href="tickets.php?status=closed">
            Closed (<?= $stats['closed'] ?>)
        </a>
    </li>
</ul>

<!-- Tickets Card -->
<div class="card border-0 shadow-sm">
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
                    <th>Last Activity</th>
                    <th class="text-end pe-3">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tickets)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
                            No tickets found matching this filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tickets as $ticket): ?>
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
                            <td class="small text-muted">
                                <?= !empty($ticket['last_reply_at']) ? date('M d, Y H:i', strtotime($ticket['last_reply_at'])) : '—' ?>
                            </td>
                            <td class="text-end pe-3">
                                <a href="ticket-view.php?id=<?= $ticket['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    View
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
