<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Fixtures;

use Iniznet\Mahout\Db\Contracts\OrphanSource;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Db\Table;

/**
 * An OrphanSource over the orphan fixture table, checked against core's posts.
 *
 * @internal
 */
final readonly class FixtureOrphanSource implements OrphanSource
{
    public function __construct(
        private SqlConnection $connection,
        private Table $table,
    ) {
    }

    public function table(): Table
    {
        return $this->table;
    }

    public function keyForPost(int $postId): Row
    {
        return Row::of($this->table, ['post_id' => $postId]);
    }

    public function existingPosts(array $postIds): array
    {
        if ([] === $postIds) {
            return [];
        }

        $placeholders = \implode(', ', \array_fill(0, \count($postIds), '%d'));
        $rows = $this->connection->rowsPrepared(
            'SELECT ID FROM '.$this->connection->prefix().'posts WHERE ID IN ('.$placeholders.')',
            ...$postIds,
        );

        return \array_map(intval(...), \array_column($rows, 'ID'));
    }

    public function postOf(Row $row): int
    {
        return (int) $row->value('post_id');
    }

    public function isTombstoned(Row $row): bool
    {
        return 1 === (int) $row->value('tombstoned');
    }
}
