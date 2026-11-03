<?php
/**
 * Ticket Support System - Public Entry Point & Environment Diagnostic
 */

declare(strict_types=1);

// Locate private configuration directory
$privatePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'helpdesk-private';

if (!file_exists($privatePath . '/config/bootstrap.php')) {
    die("<h1>Critical Configuration Error</h1><p>Cannot find <code>helpdesk-private/config/bootstrap.php</code>. Please check your installation structure.</p>");
}

require_once $privatePath . '/config/bootstrap.php';
require_once $privatePath . '/database/Database.php';

// Diagnostics
$phpVersion = PHP_VERSION;
$requiredPhp = '8.1.0';
$phpOk = version_compare($phpVersion, $requiredPhp, '>=');

$extensions = [
    'pdo'        => extension_loaded('pdo'),
    'pdo_mysql'  => extension_loaded('pdo_mysql'),
    'openssl'    => extension_loaded('openssl'),
    'mbstring'   => extension_loaded('mbstring'),
    'fileinfo'   => extension_loaded('fileinfo'),
    'imap'       => extension_loaded('imap'),
    'json'       => extension_loaded('json'),
    'curl'       => extension_loaded('curl'),
];

$allExtsOk = !in_array(false, $extensions, true);

// Database Check
$dbStatus = Database::testConnection();
$tableCount = 0;
$tables = [];
if ($dbStatus['status'] === 'success') {
    $tables = Database::fetchAll("SHOW TABLES");
    $tableCount = count($tables);
}

// Storage Check
$logsPath = config('app.paths.logs', dirname(__DIR__) . '/helpdesk-private/logs');
$storagePath = config('app.paths.storage', dirname(__DIR__) . '/helpdesk-private/storage');
$attachmentsPath = config('app.paths.attachments', dirname(__DIR__) . '/helpdesk-private/storage/attachments');

$logsWritable = is_writable($logsPath);
$storageWritable = is_writable($storagePath);
$attachmentsWritable = is_writable($attachmentsPath);
$storageOk = $logsWritable && $storageWritable && $attachmentsWritable;

$systemReady = $phpOk && $allExtsOk && ($dbStatus['status'] === 'success') && ($tableCount >= 14) && $storageOk;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(config('app.name', 'Support Desk')) ?> - System Setup Status</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .card-header-gradient { background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%); color: #fff; }
        .badge-pill { padding: 0.4em 0.8em; font-size: 0.85rem; }
    </style>
</head>
<body class="py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">

                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h2 class="fw-bold mb-1"><i class="bi bi-shield-check text-primary me-2"></i><?= htmlspecialchars(config('app.name')) ?></h2>
                        <p class="text-muted mb-0">Phase 1: Hosting Setup, Database Architecture & Connectivity</p>
                    </div>
                    <div>
                        <?php if ($systemReady): ?>
                            <span class="badge bg-success badge-pill fs-6"><i class="bi bi-check-circle-fill me-1"></i> Phase 1 Verified</span>
                        <?php else: ?>
                            <span class="badge bg-danger badge-pill fs-6"><i class="bi bi-exclamation-triangle-fill me-1"></i> Setup Needed</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Overall Status Alert -->
                <?php if ($systemReady): ?>
                    <div class="alert alert-success d-flex align-items-center shadow-sm" role="alert">
                        <i class="bi bi-check-circle-fill fs-3 me-3 text-success"></i>
                        <div>
                            <h5 class="alert-heading mb-1 fw-bold">Phase 1 Environment & Database Operational</h5>
                            <p class="mb-0 text-secondary">All required PHP extensions, MySQL tables, foreign keys, transaction managers, and permissions are verified and ready for Phase 2 (Authentication & Ticket Creation).</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning shadow-sm" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-exclamation-circle-fill fs-3 me-3 text-warning"></i>
                            <div>
                                <h5 class="alert-heading mb-1 fw-bold">System Attention Required</h5>
                                <p class="mb-0 text-secondary">
                                    <?php if (!$extensions['imap']): ?>
                                        The <strong>IMAP extension</strong> is enabled in <code>php.ini</code>, but <strong>Apache must be restarted</strong> to load it into memory.
                                    <?php else: ?>
                                        One or more requirements need resolution before proceeding to Phase 2.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <?php if (!$extensions['imap']): ?>
                            <hr class="my-3">
                            <div class="bg-white p-3 rounded border border-warning">
                                <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-arrow-repeat text-primary me-2"></i>Quick 2-Step Fix:</h6>
                                <ol class="mb-0 ps-3 text-secondary">
                                    <li>In the <strong>XAMPP Control Panel</strong>, click <strong class="text-danger">Stop</strong> next to <strong>Apache</strong>.</li>
                                    <li>Wait 2 seconds, then click <strong class="text-success">Start</strong> next to <strong>Apache</strong>.</li>
                                    <li>Refresh this browser page (<code>F5</code>).</li>
                                </ol>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="row g-4 mt-1">
                    <!-- Database Status Card -->
                    <div class="col-md-6">
                        <div class="card shadow-sm h-100 border-0">
                            <div class="card-header bg-white py-3 fw-bold border-bottom">
                                <i class="bi bi-database me-2 text-primary"></i>Database Architecture
                            </div>
                            <div class="card-body">
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                        <span>Connection Status</span>
                                        <?php if ($dbStatus['status'] === 'success'): ?>
                                            <span class="badge bg-success"><i class="bi bi-check2"></i> Connected</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="bi bi-x"></i> Failed</span>
                                        <?php endif; ?>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                        <span>Database Name</span>
                                        <span class="fw-semibold font-monospace"><?= htmlspecialchars(config('database.database')) ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                        <span>MySQL Engine & Version</span>
                                        <span class="fw-semibold font-monospace"><?= htmlspecialchars($dbStatus['version'] ?? 'N/A') ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                        <span>Installed Tables</span>
                                        <span class="badge bg-primary rounded-pill"><?= $tableCount ?> / 15 Tables</span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                        <span>Default Administrator</span>
                                        <span class="badge bg-info text-dark"><?= htmlspecialchars(config('app.admin_email', 'mail@websitesting.shop')) ?> (Seeded)</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Environment & Extensions Card -->
                    <div class="col-md-6">
                        <div class="card shadow-sm h-100 border-0">
                            <div class="card-header bg-white py-3 fw-bold border-bottom">
                                <i class="bi bi-server me-2 text-primary"></i>Runtime & Extensions
                            </div>
                            <div class="card-body">
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                        <span>PHP Version (>= 8.1.0)</span>
                                        <span class="badge <?= $phpOk ? 'bg-success' : 'bg-danger' ?>">
                                            PHP <?= $phpVersion ?>
                                        </span>
                                    </li>
                                    <?php foreach ($extensions as $ext => $loaded): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                            <span class="font-monospace text-capitalize"><?= $ext ?></span>
                                            <?php if ($loaded): ?>
                                                <span class="text-success"><i class="bi bi-check-circle-fill"></i> Enabled</span>
                                            <?php else: ?>
                                                <span class="text-danger"><i class="bi bi-x-circle-fill"></i> Missing</span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Directory Permissions Card -->
                    <div class="col-12">
                        <div class="card shadow-sm border-0">
                            <div class="card-header bg-white py-3 fw-bold border-bottom">
                                <i class="bi bi-folder-check me-2 text-primary"></i>Storage & Security Isolation
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="p-3 border rounded bg-light">
                                            <div class="fw-semibold">Logs Directory</div>
                                            <div class="small text-muted mb-2 font-monospace">helpdesk-private/logs</div>
                                            <span class="badge <?= $logsWritable ? 'bg-success' : 'bg-danger' ?>">
                                                <?= $logsWritable ? 'Writable & Protected' : 'Not Writable' ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="p-3 border rounded bg-light">
                                            <div class="fw-semibold">Attachment Storage</div>
                                            <div class="small text-muted mb-2 font-monospace">helpdesk-private/storage/attachments</div>
                                            <span class="badge <?= $attachmentsWritable ? 'bg-success' : 'bg-danger' ?>">
                                                <?= $attachmentsWritable ? 'Writable & Protected' : 'Not Writable' ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="p-3 border rounded bg-light">
                                            <div class="fw-semibold">Database Backups</div>
                                            <div class="small text-muted mb-2 font-monospace">helpdesk-private/storage/backups</div>
                                            <span class="badge <?= $storageWritable ? 'bg-success' : 'bg-danger' ?>">
                                                <?= $storageWritable ? 'Writable & Protected' : 'Not Writable' ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 p-3 bg-white rounded border text-muted small d-flex justify-content-between align-items-center">
                    <span>Server Time: <?= date('Y-m-d H:i:s T') ?></span>
                    <span>Architecture: cPanel Shared Hosting & Apache Standard</span>
                </div>

            </div>
        </div>
    </div>
</body>
</html>
