<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Persistence;

use Flowd\Typo3Phixture\Factory\RecordFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes a graph of factories with one DataHandler run, so TYPO3 itself produces slugs, sorting, MM rows,
 * relation columns and the reference index.
 */
final class Persister
{
    /**
     * Several roots are written with the same run: graphs that do not reference each other, such as the
     * declared pages of a site.
     */
    public function persist(RecordFactory ...$roots): void
    {
        $this->assertAdminBackendUser();

        /** @var \SplObjectStorage<RecordFactory, string> $placeholders */
        $placeholders = new \SplObjectStorage();
        foreach ($roots as $root) {
            $this->collect($root, $placeholders);
        }
        $this->inheritPages($placeholders);
        foreach ($placeholders as $factory) {
            $factory->beforePersist();
        }

        $dataMap = [];
        $suggestedUids = [];
        $insertPositions = [];
        foreach ($this->inInsertOrder($placeholders) as $factory) {
            $placeholder = $placeholders[$factory];
            $row = ['pid' => $this->pidOf($factory, $placeholders, $insertPositions)];
            foreach ($factory->values() as $field => $value) {
                $row[$field] = $this->serialize($factory->table(), $field, $value, $placeholders);
            }
            if ($factory->suggestedUid() !== null) {
                $row['uid'] = $factory->suggestedUid();
                $suggestedUids[$factory->table() . ':' . $factory->suggestedUid()] = true;
            }
            $dataMap[$factory->table()][$placeholder] = $row;
        }
        if ($dataMap === []) {
            return;
        }

        $dataHandler = $this->run($dataMap, $suggestedUids);

        foreach ($placeholders as $factory) {
            $placeholder = $placeholders[$factory];
            $uid = $dataHandler->substNEWwithIDs[$placeholder] ?? null;
            // DataHandler stores the uid of a new record as integer
            if (!is_int($uid)) {
                throw new \RuntimeException(
                    sprintf('DataHandler did not create the "%s" record %s.', $factory->table(), $placeholder),
                    1763560101,
                );
            }
            if ($factory->suggestedUid() !== null && $uid !== $factory->suggestedUid()) {
                throw new \RuntimeException(
                    sprintf('Record of table "%s" got uid %d instead of the requested %d.', $factory->table(), $uid, $factory->suggestedUid()),
                    1763560102,
                );
            }
            $factory->markPersisted($uid);
        }
    }

    /**
     * Factories in the values that are not persisted yet are stored first, on the page of the updated record.
     *
     * @param array<string, mixed> $values
     */
    public function update(RecordFactory $factory, array $values): void
    {
        $this->assertAdminBackendUser();

        $new = [];
        $page = $factory->table() === 'pages' ? $factory : $factory->storagePage();
        foreach ($this->factoriesIn($values) as $linkedFactory) {
            if ($linkedFactory->isPersisted()) {
                continue;
            }
            if ($page !== null) {
                $linkedFactory->inheritPage($page);
            }
            $new[] = $linkedFactory;
        }
        if ($new !== []) {
            $this->persist(...$new);
        }

        /** @var \SplObjectStorage<RecordFactory, string> $none */
        $none = new \SplObjectStorage();
        $row = [];
        foreach ($values as $field => $value) {
            $row[$field] = $this->serialize($factory->table(), $field, $value, $none);
        }
        $this->run([$factory->table() => [$factory->uid() => $row]], []);
    }

    /**
     * Changes a record no factory stands for, such as the metadata TYPO3 creates when it indexes a file.
     *
     * @param array<string, mixed> $values
     */
    public function updateRecord(string $table, int $uid, array $values): void
    {
        $this->assertAdminBackendUser();
        $this->run([$table => [$uid => $values]], []);
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $dataMap
     * @param array<string, true> $suggestedUids
     */
    private function run(array $dataMap, array $suggestedUids): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->suggestedInsertUids = $suggestedUids;
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            throw new \RuntimeException(
                "DataHandler reported errors:\n- " . implode("\n- ", array_map(strval(...), $dataHandler->errorLog)),
                1763560103,
            );
        }

        return $dataHandler;
    }

    /**
     * Walks the graph and gives every factory that is not persisted yet a NEW placeholder.
     *
     * @param \SplObjectStorage<RecordFactory, string> $placeholders
     */
    private function collect(RecordFactory $factory, \SplObjectStorage $placeholders): void
    {
        if ($factory->isPersisted() || $placeholders->offsetExists($factory)) {
            return;
        }
        $placeholders[$factory] = 'NEW' . bin2hex(random_bytes(8));

        $page = $factory->storagePage();
        if ($page instanceof RecordFactory) {
            $this->collect($page, $placeholders);
        }
        foreach ($this->linkedTo($factory) as $linkedFactory) {
            $this->collect($linkedFactory, $placeholders);
        }
    }

    /**
     * Records without an own page inherit it from the record that references them: a page's content and
     * relations are stored on that page, the relations of any other record next to it.
     *
     * Done for the whole graph after it is collected, and repeated until nothing changes: a record can get
     * its page from a record that inherits its own page only later, whatever the order of the roots.
     *
     * @param \SplObjectStorage<RecordFactory, string> $placeholders
     */
    private function inheritPages(\SplObjectStorage $placeholders): void
    {
        $factories = iterator_to_array($placeholders, false);
        do {
            $changed = false;
            foreach ($factories as $factory) {
                $page = $factory->table() === 'pages' ? $factory : $factory->storagePage();
                if ($page === null) {
                    continue;
                }
                foreach ($this->linkedTo($factory) as $linkedFactory) {
                    if (!$linkedFactory->isPersisted() && $linkedFactory->storagePage() === null) {
                        $linkedFactory->inheritPage($page);
                        $changed = true;
                    }
                }
            }
        } while ($changed);
    }

    /**
     * The records a record brings along: the ones attached to it and the ones its fields reference.
     *
     * @return list<RecordFactory>
     */
    private function linkedTo(RecordFactory $factory): array
    {
        return [...$factory->attachedRecords(), ...$this->factoriesIn($factory->values())];
    }

    /**
     * @param array<string, mixed> $values
     * @return list<RecordFactory>
     */
    private function factoriesIn(array $values): array
    {
        $factories = [];
        foreach ($values as $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item instanceof RecordFactory) {
                    $factories[] = $item;
                }
            }
        }

        return $factories;
    }

    /**
     * DataHandler needs a parent page stored before its sub pages and records (it handles the pages table
     * first), and it inserts every record at the same place: the top of a new page, or directly after the
     * last record of an existing one (insertPositionOn()). So: pages by depth, and everything that shares
     * a level in reverse order, which makes the stored sorting match the order in the test.
     *
     * @param \SplObjectStorage<RecordFactory, string> $placeholders
     * @return list<RecordFactory>
     */
    private function inInsertOrder(\SplObjectStorage $placeholders): array
    {
        $factories = array_reverse(iterator_to_array($placeholders, false));
        $depth = static function (RecordFactory $factory) use ($placeholders): int {
            $depth = 0;
            $page = $factory->storagePage();
            while ($page instanceof RecordFactory && $placeholders->offsetExists($page)) {
                $depth++;
                $page = $page->storagePage();
            }

            return $depth;
        };
        // usort is stable: equal depth keeps the reversed order
        usort($factories, static fn(RecordFactory $a, RecordFactory $b): int => $depth($a) <=> $depth($b));

        return $factories;
    }

    /**
     * @param \SplObjectStorage<RecordFactory, string> $placeholders
     * @param array<string, array<int, int>> $insertPositions table => page uid => pid for DataHandler
     */
    private function pidOf(RecordFactory $factory, \SplObjectStorage $placeholders, array &$insertPositions): int|string
    {
        $page = $factory->storagePage();
        if ($page === null) {
            throw new \LogicException(
                sprintf('Record of table "%s" has no page: call under() or in().', $factory->table()),
                1763560104,
            );
        }
        if ($page instanceof RecordFactory && !$page->isPersisted()) {
            return $placeholders[$page];
        }
        $pageUid = is_int($page) ? $page : $page->uid();

        return $insertPositions[$factory->table()][$pageUid] ??= $this->insertPositionOn($factory->table(), $pageUid);
    }

    /**
     * A page that exists can already hold records of the table, from an earlier run. DataHandler would
     * insert above them; "after the last one" (its negative uid as pid) keeps the order of the test also
     * across several create() calls. The records of one run all get this position, in reverse order: each
     * is inserted directly after that last record, in front of the ones written before it.
     */
    private function insertPositionOn(string $table, int $pageUid): int
    {
        $sortByField = $GLOBALS['TCA'][$table]['ctrl']['sortby'] ?? null;
        if (!is_string($sortByField) || $sortByField === '') {
            return $pageUid;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $lastUid = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)))
            ->orderBy($sortByField, 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($lastUid) ? -(int)$lastUid : $pageUid;
    }

    /**
     * Relation syntax comes from TCA: a comma list of uids / placeholders, prefixed with the table name for
     * group fields that allow more than one table.
     *
     * @param \SplObjectStorage<RecordFactory, string> $placeholders
     */
    private function serialize(string $table, string $field, mixed $value, \SplObjectStorage $placeholders): mixed
    {
        $items = is_array($value) ? $value : [$value];
        if (array_filter($items, static fn(mixed $item): bool => $item instanceof RecordFactory) === []) {
            return $value;
        }

        $withTablePrefix = $this->needsTablePrefix($table, $field);
        $references = [];
        foreach ($items as $item) {
            if (!$item instanceof RecordFactory) {
                $references[] = is_scalar($item) ? (string)$item : throw new \InvalidArgumentException(
                    sprintf('Field %s.%s mixes factories with a value that is no uid.', $table, $field),
                    1763560105,
                );
                continue;
            }
            $reference = $item->isPersisted() ? (string)$item->uid() : $placeholders[$item];
            $references[] = $withTablePrefix ? $item->table() . '_' . $reference : $reference;
        }

        return implode(',', $references);
    }

    private function needsTablePrefix(string $table, string $field): bool
    {
        $schemaFactory = GeneralUtility::makeInstance(TcaSchemaFactory::class);
        if (!$schemaFactory->has($table) || !$schemaFactory->get($table)->hasField($field)) {
            return false;
        }
        $configuration = $schemaFactory->get($table)->getField($field)->getConfiguration();
        if (($configuration['type'] ?? '') !== 'group') {
            return false;
        }
        $allowed = GeneralUtility::trimExplode(',', (string)($configuration['allowed'] ?? ''), true);

        return count($allowed) !== 1 || $allowed[0] === '*';
    }

    private function assertAdminBackendUser(): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !$backendUser->isAdmin()) {
            throw new \LogicException(
                'Factories write through DataHandler and need an admin backend user in $GLOBALS[\'BE_USER\'].',
                1763560106,
            );
        }
    }
}
