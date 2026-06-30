<?php

declare(strict_types=1);

namespace App\Factory;

use Cake\Database\Connection;
use Cake\Database\Expression\QueryExpression;
use Cake\Database\Query\DeleteQuery;
use Cake\Database\Query\InsertQuery;
use Cake\Database\Query\SelectQuery;
use Cake\Database\Query\UpdateQuery;

/**
 * Creates CakePHP query-builder queries bound to the application connection.
 *
 * Uses the non-deprecated, type-specific query methods (selectQuery / insertQuery /
 * updateQuery / deleteQuery) introduced in cakephp/database 4.5.
 */
final class QueryFactory
{
    public function __construct(private Connection $connection)
    {
    }

    public function newSelect(string $table): SelectQuery
    {
        return $this->connection->selectQuery()->from($table);
    }

    /**
     * Start a select query without a preset table (use ->from([alias => table])).
     */
    public function newSelectQuery(): SelectQuery
    {
        return $this->connection->selectQuery();
    }

    /**
     * @param array<int, string> $columns
     */
    public function newInsert(string $table, array $columns): InsertQuery
    {
        return $this->connection->insertQuery()->insert($columns)->into($table);
    }

    public function newUpdate(string $table): UpdateQuery
    {
        return $this->connection->updateQuery()->update($table);
    }

    public function newDelete(string $table): DeleteQuery
    {
        return $this->connection->deleteQuery()->delete($table);
    }

    /**
     * Create a raw SQL expression, e.g. for window functions.
     */
    public function expr(string $sql): QueryExpression
    {
        return $this->connection->selectQuery()->newExpr($sql);
    }
}
