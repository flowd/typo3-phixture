<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Factory;

use Flowd\Typo3Phixture\Persistence\Persister;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Builds one record and, after create(), is the handle to the stored record.
 *
 * The instance is the identity of the record: the same instance referenced from several places is persisted
 * once. Relation setters take factories, never uids or comma lists.
 */
abstract class RecordFactory
{
    protected const string TABLE = '';

    /** @var array<string, mixed> */
    private array $values = [];

    private self|int|null $page = null;

    private ?int $suggestedUid = null;

    private ?int $uid = null;

    /** @var array<string, mixed>|null */
    private ?array $row = null;

    final protected function __construct()
    {
        $this->values = $this->defaults();
    }

    public static function new(): static
    {
        return new static();
    }

    /**
     * Field values that make a record without any setter call valid and renderable.
     *
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [];
    }

    /**
     * Sets any field. A relation takes a factory or a list of factories.
     */
    public function set(string $field, mixed $value): static
    {
        $this->assertNotPersisted();
        $this->values[$field] = $value;

        return $this;
    }

    /**
     * The page the record is stored on: a page factory (persisted or not) or a page uid.
     */
    public function under(self|int $page): static
    {
        $this->assertNotPersisted();
        if ($page instanceof self && $page->table() !== 'pages') {
            throw new \LogicException(
                sprintf('under() takes a page, not a record of table "%s".', $page->table()),
                1795600001,
            );
        }
        $this->page = $page;

        return $this;
    }

    /**
     * Stores the record with exactly this uid (DataHandler::$suggestedInsertUids, admin only).
     */
    public function withUid(int $uid): static
    {
        $this->assertNotPersisted();
        $this->suggestedUid = $uid;

        return $this;
    }

    /**
     * Persists the whole graph reachable from this factory in one DataHandler run.
     */
    public function create(): static
    {
        if ($this->uid === null) {
            (new Persister())->persist($this);
        }

        return $this;
    }

    public function isPersisted(): bool
    {
        return $this->uid !== null;
    }

    public function table(): string
    {
        return static::TABLE;
    }

    public function uid(): int
    {
        return $this->uid ?? throw new \LogicException(
            sprintf('Record of table "%s" is not persisted yet, call create() first.', static::TABLE),
            1763560001,
        );
    }

    public function pid(): int
    {
        $pid = $this->get('pid');

        return is_numeric($pid) ? (int)$pid : 0;
    }

    /**
     * The stored row, read from the database on first access. Use it for values DataHandler generates
     * (slug, sorting, language fields): they can differ from what the factory was given.
     *
     * @return array<string, mixed>
     */
    public function row(): array
    {
        return $this->row ??= BackendUtility::getRecord(static::TABLE, $this->uid()) ?? throw new \RuntimeException(
            sprintf('Record %s:%d not found.', static::TABLE, $this->uid()),
            1763560002,
        );
    }

    public function get(string $field): mixed
    {
        return $this->row()[$field] ?? null;
    }

    /**
     * Changes the stored record through DataHandler.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): static
    {
        (new Persister())->update($this, $values);
        $this->row = null;

        return $this;
    }

    /**
     * @internal for the Persister
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * The page the record is stored on. Not named page(): that is a likely name for the setter of a field.
     *
     * @internal for the Persister
     */
    public function storagePage(): self|int|null
    {
        return $this->page;
    }

    /**
     * @internal for the Persister
     */
    public function suggestedUid(): ?int
    {
        return $this->suggestedUid;
    }

    /**
     * Records that are not referenced by a field but belong to this record, such as the content of a page.
     *
     * @internal for the Persister
     * @return list<self>
     */
    public function attachedRecords(): array
    {
        return [];
    }

    /**
     * Called once before the graph is written, for work DataHandler cannot do, such as adding a file to
     * a storage.
     *
     * @internal for the Persister
     */
    public function beforePersist(): void {}

    /**
     * @internal for the Persister
     */
    public function inheritPage(self|int $page): void
    {
        $this->page ??= $page;
    }

    /**
     * @internal for the Persister
     */
    public function markPersisted(int $uid): void
    {
        $this->uid = $uid;
        $this->row = null;
    }

    private function assertNotPersisted(): void
    {
        if ($this->uid !== null) {
            throw new \LogicException(
                sprintf('Record %s:%d is already persisted, use update() to change it.', static::TABLE, $this->uid),
                1763560003,
            );
        }
    }
}
