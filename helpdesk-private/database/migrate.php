<?php
/**
 * CLI Database Migration Runner
 * 
 * Usage: php migrate.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Migrator.php';

echo "============================================================\n";
echo " Helpdesk System Database Migration Runner\n";
echo "============================================================\n\n";

try {
    $connTest = Database::testConnection();
    if ($connTest['status'] !== 'success') {
        echo "[ERROR] Database connection failed: " . $connTest['message'] . "\n";
        exit(1);
    }

    echo "[OK] Connected to database: " . $connTest['database'] . " (MySQL/MariaDB " . $connTest['version'] . ")\n\n";

    $migrator = new Migrator();
    $pending = $migrator->getPendingMigrations();

    if (empty($pending)) {
        echo "[INFO] No pending migrations. All tables are up to date.\n";
        exit(0);
    }

    echo "Found " . count($pending) . " pending migration(s):\n";
    foreach ($pending as $p) {
        echo " - " . $p . "\n";
    }
    echo "\nExecuting migrations...\n";

    $result = $migrator->run();

    if ($result['success']) {
        echo "\n[SUCCESS] " . $result['message'] . "\n";
        foreach ($result['applied'] as $applied) {
            echo "  ✔ Applied: " . $applied . "\n";
        }
    } else {
        echo "\n[FAILED] " . $result['message'] . "\n";
        foreach ($result['errors'] as $err) {
            echo "  ✖ Error: " . $err . "\n";
        }
        exit(1);
    }

} catch (Throwable $e) {
    echo "\n[CRITICAL EXCEPTION] " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nMigration process completed successfully.\n";
