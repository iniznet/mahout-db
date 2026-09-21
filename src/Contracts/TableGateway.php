<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Contracts;

use Iniznet\Mahout\Db\GatewayQuery;
use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Db\Table;

/**
 * The typed table gateway: every read and write against a declared table, and
 * the transaction boundary.
 *
 * Identifiers are never parameters, so the gateway takes a declared Table and a
 * value object keyed by its columns, and reads every quoted identifier back
 * from the schema object. It never accepts a caller-supplied table or column
 * name as the text of a statement.
 *
 * The implementation is the single owner of START TRANSACTION, COMMIT and
 * ROLLBACK; this contract is the only public face of that boundary.
 */
interface TableGateway
{
    /**
     * Run work inside one transaction. A nested call joins the open
     * transaction rather than issuing START TRANSACTION again.
     *
     * @param \Closure(): void $work
     */
    public function transactional(\Closure $work): void;

    /**
     * @return list<Row>
     */
    public function select(GatewayQuery $query): array;

    public function insert(Row $row): void;

    public function upsert(Row $row): void;

    public function update(Row $values, GatewayQuery $query): void;

    public function delete(GatewayQuery $query): void;

    /**
     * Delete by primary key in one statement.
     *
     * @param list<Row> $rows
     */
    public function deleteMany(array $rows): void;

    /**
     * One keyset page of a table, ordered by its primary key.
     *
     * @return list<Row>
     */
    public function chunk(Table $table, ?Row $after, int $limit): array;
}
