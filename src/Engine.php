<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

/**
 * The storage engine a table declaration names.
 *
 * InnoDB is the only engine this package emits. The other cases exist so a
 * declaration that asks for one is refused by the emitter rather than silently
 * inheriting the server's default_storage_engine. The trap is specific:
 * \$wpdb->get_charset_collate() supplies the charset and the collation and
 * never the engine, so a CREATE TABLE without an explicit ENGINE clause gets
 * whatever the server is configured with. A table field's write is
 * transactional and MyISAM ignores a transaction silently.
 */
enum Engine: string
{
    case InnoDB = 'InnoDB';
    case MyISAM = 'MyISAM';
    case Memory = 'MEMORY';
}
