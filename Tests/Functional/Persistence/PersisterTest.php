<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Functional\Persistence;

use Flowd\Typo3Phixture\Factory\PageFactory;
use Flowd\Typo3Phixture\Persistence\Persister;
use Flowd\Typo3Phixture\Tests\Fixtures\Factory\ChildFactory;
use Flowd\Typo3Phixture\Tests\Fixtures\Factory\ContentFactory;
use Flowd\Typo3Phixture\Tests\Fixtures\Factory\ItemFactory;
use Flowd\Typo3Phixture\Tests\Fixtures\Factory\TagFactory;
use Flowd\Typo3Phixture\Tests\Functional\FixtureTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * What DataHandler makes of the graphs: the relation types of TCA, each through a field of the fixture
 * table "item".
 */
final class PersisterTest extends FixtureTestCase
{
    #[Test]
    public function inlineChildrenGetTheirParentAndItsPage(): void
    {
        $first = ChildFactory::titled('First');
        $second = ChildFactory::titled('Second');
        $page = PageFactory::new()->in($this->site());

        $item = ItemFactory::new()->under($page)->children($first, $second)->create();

        self::assertSame(2, (int)$item->get('children'));
        self::assertSame($item->uid(), (int)$first->get('item'));
        self::assertSame($item->uid(), (int)$second->get('item'));
        self::assertSame($page->uid(), $first->pid());
        self::assertSame($page->uid(), $second->pid());
    }

    #[Test]
    public function selectWithMmTableWritesTheMmRows(): void
    {
        $item = ItemFactory::new()->under(1)->tags(2, 1)->create();

        self::assertSame(2, (int)$item->get('tags'));
        $relations = $this->rows('tx_phixturefixture_item_tag_mm', 'sorting');
        self::assertSame([$item->uid(), $item->uid()], array_map(intval(...), array_column($relations, 'uid_local')));
        self::assertSame([2, 1], array_map(intval(...), array_column($relations, 'uid_foreign')));
    }

    #[Test]
    public function groupOnOneTableStoresPlainUids(): void
    {
        $page = PageFactory::new()->in($this->site());

        $item = ItemFactory::new()->under(1)->page($page)->create();

        self::assertSame((string)$page->uid(), (string)$item->get('page'));
    }

    #[Test]
    public function groupOnSeveralTablesStoresUidsWithTheirTable(): void
    {
        $page = PageFactory::new()->in($this->site());
        $child = ChildFactory::titled('Related');

        $item = ItemFactory::new()->under(1)->related($page, $child)->create();

        self::assertSame(
            sprintf('pages_%d,tx_phixturefixture_child_%d', $page->uid(), $child->uid()),
            $item->get('related'),
        );
    }

    #[Test]
    public function recordReferencedFromTwoRecordsIsStoredOnce(): void
    {
        $shared = ChildFactory::titled('Shared');
        $first = ItemFactory::new()->under(1)->related($shared);
        $second = ItemFactory::new()->under(1)->related($shared);

        (new Persister())->persist($first, $second);

        self::assertCount(1, $this->rows('tx_phixturefixture_child'));
        self::assertSame('tx_phixturefixture_child_' . $shared->uid(), $first->get('related'));
        self::assertSame('tx_phixturefixture_child_' . $shared->uid(), $second->get('related'));
    }

    #[Test]
    public function severalGraphsAreWrittenWithOneRun(): void
    {
        $first = PageFactory::new()->in($this->site())->title('First')->with(ContentFactory::new());
        $second = PageFactory::new()->in($this->site())->title('Second')->with(ContentFactory::new());

        (new Persister())->persist($first, $second);

        self::assertTrue($first->isPersisted());
        self::assertTrue($second->isPersisted());
        self::assertCount(2, $this->rows('tt_content'));
        // the order of the roots is the order in the page tree
        self::assertLessThan((int)$second->get('sorting'), (int)$first->get('sorting'));
    }

    #[Test]
    public function relationToAPersistedRecordUsesItsUid(): void
    {
        $child = ChildFactory::titled('Stored')->under(1)->create();

        $item = ItemFactory::new()->under(1)->related($child)->create();

        self::assertSame('tx_phixturefixture_child_' . $child->uid(), $item->get('related'));
        self::assertCount(1, $this->rows('tx_phixturefixture_child'));
    }

    #[Test]
    public function tableDataHandlerDoesNotWriteIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1763560101);

        TagFactory::new()->under(0)->set('title', 'Third tag')->create();
    }

    #[Test]
    public function errorOfDataHandlerIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1763560103);

        // content is not allowed at the top level of the page tree
        ContentFactory::new()->under(0)->create();
    }

    #[Test]
    public function writesNothingWithoutAnAdmin(): void
    {
        unset($GLOBALS['BE_USER']);

        try {
            ContentFactory::new()->under(1)->create();
            self::fail('Expected the persister to refuse.');
        } catch (\LogicException $exception) {
            self::assertSame(1763560106, $exception->getCode());
        }
        self::assertSame([], $this->rows('tt_content'));
    }
}
