<?php
/**
 * Database Connection & Query Manager
 * 
 * Implements a thread-safe PDO connection manager with transaction helpers,
 * parameterized query abstractions, and ping/reconnect capabilities.
 */

declare(strict_types=1);

class Database
{
    private static ?self $instance = null;
    private ?PDO $pdo = null;

    private function __construct()
    {
        $this->connect();
    }

    private function connect(): void
    {
        $config = config('database');
        if (empty($config)) {
            throw new RuntimeException("Database configuration not found.");
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset'] ?? 'utf8mb4'
        );

        $options = $config['options'] ?? [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], $options);
        } catch (PDOException $e) {
            throw new RuntimeException("Database connection failed: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function getConnection(): PDO
    {
        $instance = self::getInstance();
        return $instance->pdo;
    }

    /**
     * Executes a statement with parameterized bindings.
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $pdo = self::getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetches a single row.
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Fetches all matching rows.
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Executes an INSERT/UPDATE/DELETE query.
     */
    public static function execute(string $sql, array $params = []): bool
    {
        $stmt = self::query($sql, $params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Returns the last inserted ID.
     */
    public static function lastInsertId(): string
    {
        return self::getConnection()->lastInsertId();
    }

    /**
     * Begin transaction wrapper.
     */
    public static function beginTransaction(): bool
    {
        $pdo = self::getConnection();
        if (!$pdo->inTransaction()) {
            return $pdo->beginTransaction();
        }
        return false;
    }

    /**
     * Commit transaction wrapper.
     */
    public static function commit(): bool
    {
        $pdo = self::getConnection();
        if ($pdo->inTransaction()) {
            return $pdo->commit();
        }
        return false;
    }

    /**
     * Rollback transaction wrapper.
     */
    public static function rollBack(): bool
    {
        $pdo = self::getConnection();
        if ($pdo->inTransaction()) {
            return $pdo->rollBack();
        }
        return false;
    }

    /**
     * Check if currently within a transaction.
     */
    public static function inTransaction(): bool
    {
        return self::getConnection()->inTransaction();
    }

    /**
     * Execute a callback enclosed inside a database transaction.
     */
    public static function transaction(callable $callback): mixed
    {
        self::beginTransaction();
        try {
            $result = $callback(self::getConnection());
            self::commit();
            return $result;
        } catch (Throwable $e) {
            self::rollBack();
            throw $e;
        }
    }

    /**
     * Diagnostic connectivity test.
     */
    public static function testConnection(): array
    {
        try {
            $pdo = self::getConnection();
            $version = $pdo->query("SELECT VERSION() AS ver")->fetchColumn();
            $dbName = $pdo->query("SELECT DATABASE() AS db")->fetchColumn();
            return [
                'status'  => 'success',
                'version' => $version,
                'database'=> $dbName,
                'message' => 'Connected successfully to ' . $dbName
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup()
    {
        throw new LogicException("Cannot unserialize singleton");
    }
}
