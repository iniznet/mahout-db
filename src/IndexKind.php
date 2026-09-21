<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

/**
 * The four index kinds the DDL policy emits.
 *
 * FullText exists because the indexed search path is an ALTER TABLE ADD
 * FULLTEXT KEY on wp_posts, whose column list must be exactly the columns the
 * MATCH expression names. It is declared here so the clause is emitted, never
 * hand-built.
 */
enum IndexKind: string
{
    case Primary = 'PRIMARY KEY';
    case Unique = 'UNIQUE KEY';
    case Key = 'KEY';
    case FullText = 'FULLTEXT KEY';
}
