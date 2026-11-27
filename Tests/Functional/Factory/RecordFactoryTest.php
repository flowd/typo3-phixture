<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Functional\Factory;

use Flowd\Typo3Phixture\Factory\PageFactory;
use Flowd\Typo3Phixture\Tests\Fixtures\Factory\ChildFactory;
use Flowd\Typo3Phixture\Tests\Fixtures\Factory\ContentFactory;
use Flowd\Typo3Phixture\Tests\Fixtures\Factory\ItemFactory;
use Flowd\Typo3Phixture\Tests\Functional\FixtureTestCase;
use PHPUnit\Framework\Attributes\Test;

final class RecordFactoryTest extends FixtureTestCase
{
    #[Test]
    public function createStoresTheRecordAndMakesTheFactoryItsHandle(): void
    {
        $content = ContentFactory::new()->under($this->pageTree()->rootPage())->header('Hello')->create();

        self::assertTrue($content->isPersisted());
        self::assertGreaterThan(0, $content->uid());
        self::assertSame(1, $content->pid());
        self::assertSame('Hello', $content->get('header'));
        self::assertSame('text', $content->row()['CType']);
        self::assertNull($content->get('no_field_of_the_table'));
    }

    #[Test]
    public function createTwiceStoresOneRecord(): void
    {
        $content = ContentFactory::new()->under(1)->create();
        $uid = $content->uid();

        $content->create();

        self::assertSame($uid, $content->uid());
        self::assertCount(1, $this->rows('tt_content'));
    }

    #[Test]
    public function withUidStoresTheRecordWithThatUid(): void
    {
        $page = PageFactory::new()->in($this->site())->withUid(4711)->create();

        self::assertSame(4711, $page->uid());
        self::assertSame(1, $page->pid());
    }

    #[Test]
    public function rowHasWhatDataHandlerGenerates(): void
    {
        $page = PageFactory::new()->in($this->site())->title('Our products')->create();

        self::assertSame('/our-products', $page->get('slug'));
    }

    #[Test]
    public function updateChangesTheStoredRecord(): void
    {
        $content = ContentFactory::new()->under(1)->header('Before')->create();
        self::assertSame('Before', $content->get('header'));

        $content->update(['header' => 'After']);

        self::assertSame('After', $content->get('header'));
        self::assertSame('After', $this->rows('tt_content')[0]['header']);
    }

    #[Test]
    public function updateStoresTheNewRecordsOfARelation(): void
    {
        $page = PageFactory::new()->in($this->site())->create();
        $item = ItemFactory::new()->under($page)->create();
        $child = ChildFactory::titled('Added later');

        $item->update(['children' => [$child]]);

        self::assertSame(1, (int)$item->get('children'));
        self::assertSame($item->uid(), (int)$child->get('item'));
        self::assertSame($page->uid(), $child->pid());
    }

    #[Test]
    public function rowOfARecordThatVanishedIsReported(): void
    {
        $content = ContentFactory::new()->under(1)->create();
        $this->getConnectionPool()->getConnectionForTable('tt_content')->delete('tt_content', ['uid' => $content->uid()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1763560002);

        $content->row();
    }
}
