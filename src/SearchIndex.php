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
 */
final readonly class SearchIndex
{
    private const string NAME = 'howdah_search';

    private function __construct(
        private Identifier $table,
        private Index $index,
    ) {
    }

    public static function onPosts(string $prefix): self
    {
        return new self(
            Identifier::prefixed($prefix, 'posts'),
            Index::fullText(
                self::NAME,
                IndexColumn::of('post_title'),
                IndexColumn::of('post_excerpt'),
                IndexColumn::of('post_content'),
            ),
        );
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
}
