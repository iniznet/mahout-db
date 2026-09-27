<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Db;

/**
 * The FULLTEXT index on core's posts table.
 *
 * The column list is declared once, here, because MATCH must name the index's
 * columns exactly; a different list raises error 1191. The index is the only
 * schema change this package makes outside its own tables, it adds no column,
 * and it is reversible.
 *
 * The name is composed from the entity and the role, never from the project that
 * happened to ship first. A library that mints a site artefact's name from a
 * starter's identity hands every other consumer a Duplicate key name fatal that
 * consumer cannot avoid from its own configuration.
 */
final readonly class SearchIndex
{
    private const string ENTITY = 'posts';

    /**
     * The index's name: the package's namespace, the entity, the role. Never a
     * project's identity, and never longer than a prefix could stretch it.
     *
     * Public because {@see MatchClause} names the index its clause is built for,
     * and a second literal there would be a fact that can drift from this one.
     */
    public const string NAME = 'mahout_posts_search';

    /**
     * The names this package has previously given the index.
     *
     * History, not a namespace to avoid: RenameSearchIndex adopts a covering
     * index that carries one of these and refuses to touch any other name, so a
     * neighbour's index over the same columns is left as its owner named it.
     *
     * @var list<string>
     */
    private const array LEGACY_NAMES = ['howdah_search'];

    private function __construct(
        private Identifier $table,
        private Index $index,
    ) {
    }

    public static function onPosts(string $prefix): self
    {
        return new self(
            Identifier::prefixed($prefix, self::ENTITY),
            Index::fullText(
                self::NAME,
                IndexColumn::of('post_title'),
                IndexColumn::of('post_excerpt'),
                IndexColumn::of('post_content'),
            ),
        );
    }

    /**
     * @return list<string>
     */
    public static function legacyNames(): array
    {
        return self::LEGACY_NAMES;
    }

    public function table(): Identifier
    {
        return $this->table;
    }

    public function index(): Index
    {
        return $this->index;
    }

    /**
     * The name this declaration carries, which on a site that has been through
     * RenameSearchIndex is the name the index actually has.
     */
    public function name(): string
    {
        return $this->index->name->value;
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->index->columnNames();
    }

    public function add(DdlEmitter $emitter): string
    {
        return $emitter->addIndex($this->table, $this->index);
    }

    public function drop(DdlEmitter $emitter): string
    {
        return $emitter->dropIndex($this->table, $this->index);
    }

    /**
     * The statement that adopts an index this package created under an earlier
     * name. Renaming is the whole of it: the column list is what MATCH() selects
     * by, so the name carries no behaviour and no rebuild follows.
     */
    public function renameFrom(string $previous, DdlEmitter $emitter): string
    {
        return $emitter->renameIndex($this->table, Identifier::fromString($previous), $this->index);
    }
}
