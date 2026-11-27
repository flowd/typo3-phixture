<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Unit\Persistence;

use Flowd\Typo3Phixture\Factory\PageFactory;
use Flowd\Typo3Phixture\Persistence\Persister;
use Flowd\Typo3Phixture\Tests\Fixtures\Factory\ChildFactory;
use Flowd\Typo3Phixture\Tests\Fixtures\Factory\ContentFactory;
use Flowd\Typo3Phixture\Tests\Fixtures\Factory\ItemFactory;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The data map the persister hands to DataHandler, with DataHandler replaced by a double. What DataHandler
 * makes of it is covered by the functional tests.
 */
final class PersisterTest extends UnitTestCase
{
    /** @var list<array<string, array<int|string, array<string, mixed>>>> the data maps of all DataHandler runs */
    private array $dataMaps = [];

    /** @var list<array<string, true>> */
    private array $suggestedUids = [];

    #[\Override]
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function needsABackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1763560106);

        (new Persister())->persist(PageFactory::new()->under(0));
    }

    #[Test]
    public function needsAnAdmin(): void
    {
        $this->logInBackendUser(false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1763560106);

        (new Persister())->updateRecord('pages', 1, ['title' => 'Changed']);
    }

    #[Test]
    public function recordWithoutPageIsRejected(): void
    {
        $this->logInBackendUser();

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1763560104);

        (new Persister())->persist(ContentFactory::new());
    }

    #[Test]
    public function persistedRootsNeedNoDataHandlerRun(): void
    {
        $this->logInBackendUser();
        $page = PageFactory::new();
        $page->markPersisted(4);

        (new Persister())->persist($page);

        self::assertSame([], $this->dataMaps);
    }

    #[Test]
    public function writesARecordWithItsValuesAndPage(): void
    {
        $this->logInBackendUser();
        $this->expectDataHandlerRun();
        $page = PageFactory::new()->under(3)->title('Products');

        (new Persister())->persist($page);

        self::assertCount(1, $this->dataMaps);
        self::assertSame(['pages'], array_keys($this->dataMaps[0]));
        self::assertSame(
            [['pid' => 3, 'title' => 'Products', 'doktype' => PageRepository::DOKTYPE_DEFAULT, 'hidden' => 0]],
            array_values($this->dataMaps[0]['pages']),
        );
        self::assertSame([[]], $this->suggestedUids);
        self::assertTrue($page->isPersisted());
        self::assertSame(1001, $page->uid());
    }

    #[Test]
    public function suggestsTheRequestedUid(): void
    {
        $this->logInBackendUser();
        $this->expectDataHandlerRun(static fn(array $row): int => (int)($row['uid'] ?? 0));
        $page = PageFactory::new()->under(0)->withUid(42);

        (new Persister())->persist($page);

        self::assertSame(42, array_values($this->dataMaps[0]['pages'])[0]['uid']);
        self::assertSame([['pages:42' => true]], $this->suggestedUids);
        self::assertSame(42, $page->uid());
    }

    #[Test]
    public function otherUidThanTheRequestedOneIsAnError(): void
    {
        $this->logInBackendUser();
        $this->expectDataHandlerRun();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1763560102);

        (new Persister())->persist(PageFactory::new()->under(0)->withUid(42));
    }

    #[Test]
    public function recordsOfAPageAreStoredOnItInTheOrderOfTheTest(): void
    {
        $this->logInBackendUser();
        $this->expectDataHandlerRun();
        $first = ContentFactory::new()->header('First');
        $second = ContentFactory::new()->header('Second');
        $page = PageFactory::new()->under(1)->with($first, $second);

        (new Persister())->persist($page);

        $pagePlaceholder = array_key_first($this->dataMaps[0]['pages']);
        self::assertIsString($pagePlaceholder);
        self::assertStringStartsWith('NEW', $pagePlaceholder);
        // DataHandler inserts at the top of a page: the last record of the test is written first
        self::assertSame(
            [
                ['pid' => $pagePlaceholder, 'header' => 'Second'],
                ['pid' => $pagePlaceholder, 'header' => 'First'],
            ],
            array_map(
                static fn(array $row): array => ['pid' => $row['pid'], 'header' => $row['header']],
                array_values($this->dataMaps[0]['tt_content']),
            ),
        );
        self::assertTrue($first->isPersisted());
        self::assertTrue($second->isPersisted());
    }

    #[Test]
    public function pagesAreWrittenParentFirst(): void
    {
        $this->logInBackendUser();
        $this->expectDataHandlerRun();
        $parent = PageFactory::new()->under(1)->title('Parent');
        $child = PageFactory::new()->under($parent)->title('Child');
        $grandchild = PageFactory::new()->under($child)->title('Grandchild');

        // the deepest page first: the order of the roots must not matter
        (new Persister())->persist($grandchild, $child, $parent);

        $rows = $this->dataMaps[0]['pages'];
        self::assertSame(['Parent', 'Child', 'Grandchild'], array_column($rows, 'title'));
        $placeholders = array_keys($rows);
        self::assertSame(1, $rows[$placeholders[0]]['pid']);
        self::assertSame($placeholders[0], $rows[$placeholders[1]]['pid']);
        self::assertSame($placeholders[1], $rows[$placeholders[2]]['pid']);
    }

    #[Test]
    public function recordUnderAPersistedPageGetsItsUid(): void
    {
        $this->logInBackendUser();
        $this->expectDataHandlerRun();
        $page = PageFactory::new();
        $page->markPersisted(8);

        (new Persister())->persist(ContentFactory::new()->under($page));

        self::assertSame(['tt_content'], array_keys($this->dataMaps[0]));
        self::assertSame(8, array_values($this->dataMaps[0]['tt_content'])[0]['pid']);
    }

    #[Test]
    public function relationIsWrittenAsListOfPlaceholdersAndTheRelatedRecordInheritsThePage(): void
    {
        $this->logInBackendUser();
        $this->expectDataHandlerRun();
        $this->expectSchemaLookups(1);
        $first = ChildFactory::titled('First');
        $second = ChildFactory::titled('Second');
        $item = ItemFactory::new()->under(5)->children($first, $second);

        (new Persister())->persist($item);

        $children = $this->dataMaps[0]['tx_phixturefixture_child'];
        self::assertSame([5, 5], array_column($children, 'pid'));
        self::assertSame(['Second', 'First'], array_column($children, 'title'));
        $placeholders = array_keys($children);
        self::assertSame(
            $placeholders[1] . ',' . $placeholders[0],
            array_values($this->dataMaps[0]['tx_phixturefixture_item'])[0]['children'],
        );
    }

    #[Test]
    public function pageIsInheritedWhateverTheOrderOfTheRoots(): void
    {
        $this->logInBackendUser();
        $this->expectDataHandlerRun();
        $this->expectSchemaLookups(2);
        $child = ChildFactory::titled('Child');
        $item = ItemFactory::new()->children($child);
        $owner = ItemFactory::new()->under(5)->related($item);

        // the item is collected before the record it gets its page from
        (new Persister())->persist($item, $owner);

        self::assertSame([5, 5], array_column($this->dataMaps[0]['tx_phixturefixture_item'], 'pid'));
        self::assertSame([5], array_column($this->dataMaps[0]['tx_phixturefixture_child'], 'pid'));
    }

    #[Test]
    public function recordReferencedTwiceIsWrittenOnce(): void
    {
        $this->logInBackendUser();
        $this->expectDataHandlerRun();
        $this->expectSchemaLookups(2);
        $shared = ChildFactory::titled('Shared');
        $first = ItemFactory::new()->under(5)->children($shared);
        $second = ItemFactory::new()->under(5)->related($shared);

        (new Persister())->persist($first, $second);

        self::assertCount(1, $this->dataMaps[0]['tx_phixturefixture_child']);
        self::assertCount(2, $this->dataMaps[0]['tx_phixturefixture_item']);
    }

    #[Test]
    public function persistedRecordIsReferencedByItsUid(): void
    {
        $this->logInBackendUser();
        $this->expectDataHandlerRun();
        $this->expectSchemaLookups(1);
        $child = ChildFactory::titled('Stored');
        $child->markPersisted(77);

        (new Persister())->persist(ItemFactory::new()->under(5)->children($child));

        self::assertSame(['tx_phixturefixture_item'], array_keys($this->dataMaps[0]));
        self::assertSame('77', array_values($this->dataMaps[0]['tx_phixturefixture_item'])[0]['children']);
    }

    #[Test]
    public function relationMixingFactoriesWithOtherValuesThanUidsIsRejected(): void
    {
        $this->logInBackendUser();
        $this->expectSchemaLookups(1);
        $item = ItemFactory::new()->under(5)->set('related', [ChildFactory::titled('Child'), ['no' => 'uid']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1763560105);

        (new Persister())->persist($item);
    }

    #[Test]
    public function errorsOfDataHandlerAreReported(): void
    {
        $this->logInBackendUser();
        $this->expectDataHandlerRun(errors: ['Attempt to insert record on page 3 denied']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1763560103);
        $this->expectExceptionMessageMatches('/Attempt to insert record on page 3 denied/');

        (new Persister())->persist(PageFactory::new()->under(3));
    }

    #[Test]
    public function recordDataHandlerDidNotCreateIsReported(): void
    {
        $this->logInBackendUser();
        // a table DataHandler skips without an error, such as a read-only one
        $this->expectDataHandlerRun(static fn(): ?int => null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1763560101);

        (new Persister())->persist(PageFactory::new()->under(3));
    }

    #[Test]
    public function updateWritesTheValuesOfThePersistedRecord(): void
    {
        $this->logInBackendUser();
        $this->expectDataHandlerRun();
        $page = PageFactory::new();
        $page->markPersisted(9);

        (new Persister())->update($page, ['title' => 'Changed']);

        self::assertSame([['pages' => [9 => ['title' => 'Changed']]]], $this->dataMaps);
    }

    #[Test]
    public function updateStoresNewRecordsOfTheValuesFirstOnThePageOfTheRecord(): void
    {
        $this->logInBackendUser();
        $this->expectDataHandlerRun();
        $this->expectDataHandlerRun();
        $this->expectSchemaLookups(1);
        $item = ItemFactory::new()->under(5);
        $item->markPersisted(9);
        $child = ChildFactory::titled('New');

        (new Persister())->update($item, ['children' => [$child]]);

        self::assertCount(2, $this->dataMaps);
        self::assertSame(
            [['pid' => 5, 'title' => 'New']],
            array_values($this->dataMaps[0]['tx_phixturefixture_child']),
        );
        self::assertSame(1001, $child->uid());
        self::assertSame(['tx_phixturefixture_item' => [9 => ['children' => '1001']]], $this->dataMaps[1]);
    }

    #[Test]
    public function updateRecordWritesARecordWithoutFactory(): void
    {
        $this->logInBackendUser();
        $this->expectDataHandlerRun();

        (new Persister())->updateRecord('sys_file_metadata', 4, ['title' => 'Title']);

        self::assertSame([['sys_file_metadata' => [4 => ['title' => 'Title']]]], $this->dataMaps);
    }

    private function logInBackendUser(bool $admin = true): void
    {
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn($admin);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    /**
     * Queues a DataHandler double for one run. It records the data map and answers every new record with
     * a uid: 1001, 1002, ... in the order of the data map, or what $uidOf returns for the row.
     *
     * @param (\Closure(array<string, mixed>): ?int)|null $uidOf
     * @param list<non-empty-string> $errors
     */
    private function expectDataHandlerRun(?\Closure $uidOf = null, array $errors = []): void
    {
        $dataHandler = self::createStub(DataHandler::class);
        $dataHandler->errorLog = [];
        $dataHandler->substNEWwithIDs = [];
        /** @var array<string, array<int|string, array<string, mixed>>> $started the data map of this run */
        $started = [];
        $dataHandler->method('start')->willReturnCallback(function (array $dataMap) use ($dataHandler, &$started): void {
            $started = $dataMap;
            $this->dataMaps[] = $dataMap;
            $this->suggestedUids[] = $dataHandler->suggestedInsertUids;
        });
        $dataHandler->method('process_datamap')->willReturnCallback(static function () use ($dataHandler, $uidOf, $errors, &$started): void {
            $nextUid = 1001;
            foreach ($started as $rows) {
                foreach ($rows as $id => $row) {
                    if (!is_string($id)) {
                        continue;
                    }
                    $uid = $uidOf === null ? $nextUid++ : $uidOf($row);
                    if ($uid !== null) {
                        $dataHandler->substNEWwithIDs[$id] = $uid;
                    }
                }
            }
            $dataHandler->errorLog = $errors;
        });
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);
    }

    /**
     * The persister asks TCA once per relation field whether references need a table prefix. Here no
     * table has a schema: plain references.
     */
    private function expectSchemaLookups(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $schemaFactory = self::createStub(TcaSchemaFactory::class);
            $schemaFactory->method('has')->willReturn(false);
            GeneralUtility::addInstance(TcaSchemaFactory::class, $schemaFactory);
        }
    }
}
