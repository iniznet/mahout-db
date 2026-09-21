<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Tests\Fixtures;

use Iniznet\Mahout\Db\Column;
use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Exception\MigrationIrreversible;
use Iniznet\Mahout\Db\Identifier;

/**
 * A migration that loses information: it drops a column, and the values that
 * column held cannot be reconstructed from the new shape alone.
 *
 * It declares that through irreversibleReason() and throws it from down(),
 * because a reversal has to refuse the whole batch before the first statement
 * runs and cannot learn that by watching down() throw.
 *
 * @internal
 */
final readonly class DropsTheDecimalColumn implements Migration
{
    private const string REASON = 'the dropped column cannot be reconstructed from the new shape alone';

    public function __construct(
        private SqlConnection $connection,
        private DdlEmitter $emitter,
        private Identifier $table,
    ) {
    }

    public function name(): string
    {
        return 'fixture/0004_drop_decimal_column';
    }

    public function up(): void
    {
        $this->connection->execute(
            $this->emitter->dropColumn($this->table, Column::decimal('value_dec', 20, 6)->nullable()),
        );
    }

    public function down(): void
    {
        $reason = $this->irreversibleReason();

        if (null !== $reason) {
            throw MigrationIrreversible::because($this->name(), $reason);
        }
    }

    public function irreversibleReason(): ?string
    {
        return self::REASON;
    }
}
