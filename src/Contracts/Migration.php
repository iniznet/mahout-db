<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db\Contracts;

use Iniznet\Mahout\Db\Exception\MigrationIrreversible;

/**
 * One migration.
 *
 * Both directions are mandatory: the interface has no default, so a class that
 * cannot be reversed cannot be registered. The interface carries one member
 * beyond name(), up() and down(): the refusal is declared, not discovered.
 * --rollback has to refuse a whole batch *before the first statement runs*, and
 * it cannot learn that by calling down() and watching it throw -- by then the
 * batch is half rolled back. So the reason is declared, and down() throws it.
 */
interface Migration
{
    /** The migration's identity in the ledger. Unique across the registered set. */
    public function name(): string;

    /** Apply the change. */
    public function up(): void;

    /**
     * Reverse the change.
     *
     * @throws MigrationIrreversible when irregularReason() is not null, before
     *                               executing any statement
     */
    public function down(): void;

    /**
     * The reason down() cannot restore the prior state, or null when it can.
     *
     * A migration that loses information declares it here and throws it from
     * down(); the two must agree, and a test asserts that they do.
     */
    public function irreversibleReason(): ?string;
}
