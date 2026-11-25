<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

/**
 * Единственная точка доступа к PDO в приложении (без Doctrine).
 * DSN берётся из env: DATABASE_DSN / DATABASE_USER / DATABASE_PASSWORD.
 */
final class ConnectionProvider
{
    private ?\PDO $pdo = null;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
    ) {}

    /** Чтение ресурса (GET). */
    public function get(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new \PDO($this->dsn, $this->user, $this->password, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return $this->pdo;
    }

    /** @template T @param callable(\PDO):T $fn @return T */
    public function transactional(callable $fn): mixed
    {
        $pdo = $this->get();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
