<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Contracts;

use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Db\Table;

/**
 * A declared table whose rows can outlive the posts they reference.
 *
 * The implementation knows what the table's rows mean: which equality selects
 * every row for one post, which post a row references, and which rows are
 * tombstones an in-flight erasure still owns.
 */
interface OrphanSource
{
    public function table(): Table;

    /**
     * An equality selecting every row that references one post. It must be a
     * leading prefix of the declared primary key or carry a LIMIT.
     */
    public function keyForPost(int $postId): Row;

    /**
     * The subset of the given post ids that still exist, from one query.
     *
     * @param list<int> $postIds
     *
     * @return list<int>
     */
    public function existingPosts(array $postIds): array;

    public function postOf(Row $row): int;

    public function isTombstoned(Row $row): bool;
}
