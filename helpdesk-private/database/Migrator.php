<?php
/**
 * Database Migration Engine
 * 
 * Tracks, validates, and executes numbered database migrations sequentially.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/Database.php';

class Migrator
{
    private PDO $pdo;
    private string $migrationsDir;

    public function __construct(?string $migrationsDir = null)
    {
        $this->pdo = Database::getConnection();
        $this->migrationsDir = $migrationsDir ?? __DIR__ . '/migrations';
        $this->ensureMigrationTable();
    }

    /**
     * Creates the migrations tracking table if not present.
     */
    private function ensureMigrationTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `migrations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(191) NOT NULL UNIQUE,
            `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->pdo->exec($sql);
    }

    /**
     * Returns a list of all migrations that have already been applied.
     */
    public function getAppliedMigrations(): array
    {
        $stmt = $this->pdo->query("SELECT `migration` FROM `migrations` ORDER BY `id` ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Returns an array of pending migration filenames.
     */
    public function getPendingMigrations(): array
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }

        $allFiles = scandir($this->migrationsDir);
        $migrationFiles = [];

        foreach ($allFiles as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                $migrationFiles[] = $file;
            }
        }

        sort($migrationFiles);
        $applied = $this->getAppliedMigrations();

        return array_values(array_diff($migrationFiles, $applied));
    }

    /**
     * Executes all pending migrations.
     * 
     * @return array [ 'success' => bool, 'applied' => string[], 'errors' => string[] ]
     */
    public function run(): array
    {
        $pending = $this->getPendingMigrations();
        $applied = [];
        $errors = [];

        if (empty($pending)) {
            return [
                'success' => true,
                'applied' => [],
                'message' => 'No pending migrations found. Database is up to date.'
            ];
        }

        foreach ($pending as $migrationFile) {
            $filePath = $this->migrationsDir . DIRECTORY_SEPARATOR . $migrationFile;
            $sqlContent = file_get_contents($filePath);

            if ($sqlContent === false) {
                $errors[] = "Failed to read migration file: {$migrationFile}";
                break;
            }

            try {
                // Execute migration SQL queries
                $this->pdo->exec($sqlContent);

                // Record into migrations table
                $stmt = $this->pdo->prepare("INSERT INTO `migrations` (`migration`) VALUES (:migration)");
                $stmt->execute(['migration' => $migrationFile]);

                $applied[] = $migrationFile;
            } catch (Throwable $e) {
                $errors[] = "Error in {$migrationFile}: " . $e->getMessage();
                break; // Stop at first failure to preserve consistency
            }
        }

        return [
            'success' => empty($errors),
            'applied' => $applied,
            'errors'  => $errors,
            'message' => empty($errors) 
                ? sprintf("Successfully executed %d migration(s).", count($applied))
                : sprintf("Migration stopped due to errors after %d migration(s).", count($applied))
        ];
    }
}
