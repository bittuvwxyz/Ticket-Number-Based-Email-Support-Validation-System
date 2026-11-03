<?php
/**
 * Shared Application Header Layout
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpdesk-private/config/bootstrap.php';
require_once dirname(__DIR__, 2) . '/helpdesk-private/services/AuthService.php';
require_once dirname(__DIR__, 2) . '/helpdesk-private/services/SecurityService.php';

AuthService::startSecureSession();
$currentUser = AuthService::getCurrentUser();
$appName = config('app.name', 'Secure Support Desk');
$appUrl = config('app.url', 'http://localhost/helpdesk');
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . " - " . htmlspecialchars($appName) : htmlspecialchars($appName) ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0d6efd;
            --sidebar-bg: #1e293b;
            --body-bg: #f8fafc;
        }
        body {
            background-color: var(--body-bg);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #334155;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .main-content {
            flex: 1 0 auto;
        }
        .navbar-brand {
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .card {
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05), 0 1px 2px 0 rgba(0, 0, 0, 0.03);
        }
        .badge-status-open { background-color: #0d6efd; color: #fff; }
        .badge-status-pending { background-color: #f59e0b; color: #fff; }
        .badge-status-closed { background-color: #64748b; color: #fff; }
        .badge-status-reopened { background-color: #8b5cf6; color: #fff; }
        .badge-status-expired { background-color: #ef4444; color: #fff; }
        .priority-urgent { color: #dc2626; font-weight: 600; }
        .priority-high { color: #ea580c; font-weight: 600; }
        .priority-medium { color: #2563eb; }
        .priority-low { color: #64748b; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow-sm py-2">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="<?= $currentUser ? "{$appUrl}/dashboard.php" : "{$appUrl}/login.php" ?>">
            <i class="bi bi-shield-check text-primary fs-4 me-2"></i>
            <span><?= htmlspecialchars($appName) ?></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <?php if ($currentUser): ?>
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-3">
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPage === 'dashboard.php' ? 'active fw-semibold' : '' ?>" href="<?= $appUrl ?>/dashboard.php">
                            <i class="bi bi-speedometer2 me-1"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPage === 'tickets.php' ? 'active fw-semibold' : '' ?>" href="<?= $appUrl ?>/tickets.php">
                            <i class="bi bi-ticket-detailed me-1"></i> My Tickets
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPage === 'create-ticket.php' ? 'active fw-semibold' : '' ?>" href="<?= $appUrl ?>/create-ticket.php">
                            <i class="bi bi-plus-circle me-1"></i> New Request
                        </a>
                    </li>
                </ul>

                <div class="d-flex align-items-center gap-3">
                    <span class="text-light small">
                        <i class="bi bi-person-circle me-1 text-primary"></i>
                        <?= htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']) ?>
                    </span>
                    <a href="<?= $appUrl ?>/logout.php" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-box-arrow-right me-1"></i> Logout
                    </a>
                </div>
            <?php else: ?>
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPage === 'login.php' ? 'active' : '' ?>" href="<?= $appUrl ?>/login.php">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Log In
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-primary btn-sm ms-lg-2 px-3" href="<?= $appUrl ?>/register.php">
                            <i class="bi bi-person-plus me-1"></i> Register
                        </a>
                    </li>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>

<main class="main-content py-4">
    <div class="container">
        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($_SESSION['flash_success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($_SESSION['flash_error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>
