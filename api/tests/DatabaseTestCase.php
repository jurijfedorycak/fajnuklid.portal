<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

abstract class DatabaseTestCase extends TestCase
{
    protected MockObject&PDO $pdoMock;
    protected MockObject&PDOStatement $stmtMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdoMock = $this->createMock(PDO::class);
        $this->stmtMock = $this->createMock(PDOStatement::class);
    }

    /**
     * Create a repository instance with mocked PDO connection using reflection.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function createRepositoryWithMockedPdo(string $class): object
    {
        $reflection = new ReflectionClass($class);
        $repository = $reflection->newInstanceWithoutConstructor();

        $property = $reflection->getProperty('db');
        $property->setAccessible(true);
        $property->setValue($repository, $this->pdoMock);

        return $repository;
    }

    /**
     * Setup mock for a simple query that returns a single row.
     */
    protected function setupFetchMock(array|false $result): void
    {
        $this->stmtMock->method('fetch')->willReturn($result);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
    }

    /**
     * Setup mock for a query that returns multiple rows.
     */
    protected function setupFetchAllMock(array $results): void
    {
        $this->stmtMock->method('fetchAll')->willReturn($results);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
    }

    /**
     * Setup mock for a query without prepared statement (direct query).
     */
    protected function setupQueryMock(array $results): void
    {
        $this->stmtMock->method('fetchAll')->willReturn($results);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);
    }

    /**
     * Setup mock for a query that returns a single column value.
     */
    protected function setupFetchColumnMock(mixed $value): void
    {
        $this->stmtMock->method('fetchColumn')->willReturn($value);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
    }

    /**
     * Setup mock for an insert operation.
     */
    protected function setupInsertMock(int $lastInsertId): void
    {
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->pdoMock->method('lastInsertId')->willReturn((string) $lastInsertId);
    }

    /**
     * Setup mock for an update/delete operation.
     */
    protected function setupRowCountMock(int $rowCount): void
    {
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('rowCount')->willReturn($rowCount);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
    }

    /**
     * Setup mock for transaction operations.
     */
    protected function setupTransactionMock(bool $shouldCommit = true): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);

        if ($shouldCommit) {
            $this->pdoMock->method('commit')->willReturn(true);
        } else {
            $this->pdoMock->method('rollBack')->willReturn(true);
        }
    }

    /**
     * Create a fresh statement mock for complex test scenarios.
     */
    protected function createStatementMock(): MockObject&PDOStatement
    {
        return $this->createMock(PDOStatement::class);
    }
}
