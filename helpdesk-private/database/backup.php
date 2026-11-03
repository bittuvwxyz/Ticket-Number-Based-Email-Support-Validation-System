<?php
/**
 * Database Backup Utility
 * 
 * Generates structured SQL backup dumps of the helpdesk database.
 * Usage: php backup.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/Database.php';

class DatabaseBackup
{
    private PDO $pdo;
    private string $backupDir;

    public function __construct(?string $backupDir = null)
    {
        $this->pdo = Database::getConnection();
        $this->backupDir = $backupDir ?? config('app.paths.backups', dirname(__DIR__) . '/storage/backups');
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0750, true);
        }
    }

    /**
     * Creates a complete SQL backup file.
     */
    public function backup(): string
    {
        $dbName = config('database.database');
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "backup_{$dbName}_{$timestamp}.sql";
        $filepath = $this->backupDir . DIRECTORY_SEPARATOR . $filename;

        $handle = fopen($filepath, 'w');
        if ($handle === false) {
            throw new RuntimeException("Cannot open backup file for writing: {$filepath}");
        }

        // Header comments
        fwrite($handle, "-- ========================================================\n");
        fwrite($handle, "-- Database Backup: {$dbName}\n");
        fwrite($handle, "-- Generated At: " . date('Y-m-d H:i:s T') . "\n");
        fwrite($handle, "-- ========================================================\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n");
        fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
        fwrite($handle, "SET NAMES utf8mb4;\n\n");

        // Fetch all tables
        $tables = $this->pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            // Drop & Create Table Structure
            fwrite($handle, "-- --------------------------------------------------------\n");
            fwrite($handle, "-- Table structure for table `{$table}`\n");
            fwrite($handle, "-- --------------------------------------------------------\n");
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");

            $createRow = $this->pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
            fwrite($handle, $createRow[1] . ";\n\n");

            // Dump data
            $rows = $this->pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                fwrite($handle, "-- Dumping data for table `{$table}`\n");
                foreach ($rows as $row) {
                    $cols = array_map(fn($c) => "`{$c}`", array_keys($row));
                    $vals = array_map(function ($val) {
                        if ($val === null) {
                            return 'NULL';
                        }
                        return $this->pdo->quote((string)$val);
                    }, array_values($row));

                    $insertSql = sprintf(
                        "INSERT INTO `%s` (%s) VALUES (%s);\n",
                        $table,
                        implode(', ', $cols),
                        implode(', ', $vals)
                    );
                    fwrite($handle, $insertSql);
                }
                fwrite($handle, "\n");
            }
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($handle);

        return $filepath;
    }
}

// CLI Execution support
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "Starting database backup...\n";
    try {
        $backupService = new DatabaseBackup();
        $file = $backupService->backup();
        echo "[SUCCESS] Backup written to: {$file} (" . round(filesize($file) / 1024, 2) . " KB)\n";
    } catch (Throwable $e) {
        echo "[ERROR] Backup failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
