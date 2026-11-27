<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Unit\Factory;

use Flowd\Typo3Phixture\Factory\PageFactory;
use Flowd\Typo3Phixture\Tests\Fixtures\Factory\ContentFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;

final class PageFactoryTest extends TestCase
{
    #[Test]
    public function isAVisibleStandardPageByDefault(): void
    {
        $page = PageFactory::new();

        self::assertSame('pages', $page->table());
        self::assertSame(['title' => 'Page', 'doktype' => PageRepository::DOKTYPE_DEFAULT, 'hidden' => 0], $page->values());
    }

    #[Test]
    public function settersFillTheFieldsOfThePage(): void
    {
        $page = PageFactory::new()
            ->title('Products')
            ->slug('/products')
            ->doktype(PageRepository::DOKTYPE_SYSFOLDER)
            ->hidden();

        self::assertSame(
            [
                'title' => 'Products',
                'doktype' => PageRepository::DOKTYPE_SYSFOLDER,
                'hidden' => 1,
                'slug' => '/products',
            ],
            $page->values(),
        );
    }

    #[Test]
    public function hiddenCanBeTakenBack(): void
    {
        self::assertSame(0, PageFactory::new()->hidden()->hidden(false)->values()['hidden']);
    }

    #[Test]
    public function siteRootIsTheRootOfASite(): void
    {
        $values = PageFactory::new()->siteRoot()->values();

        self::assertSame(1, $values['is_siteroot']);
        self::assertSame('/', $values['slug']);
    }

    #[Test]
    public function recordsOfThePageKeepTheOrderOfTheTest(): void
    {
        $first = ContentFactory::new();
        $second = ContentFactory::new();
        $third = ContentFactory::new();

        $page = PageFactory::new()->with($first, $second)->with($third);

        self::assertSame([$first, $second, $third], $page->attachedRecords());
    }

    #[Test]
    public function hasNoRecordsByDefault(): void
    {
        self::assertSame([], PageFactory::new()->attachedRecords());
    }

    #[Test]
    public function persistedPageTakesNoFurtherRecords(): void
    {
        $page = PageFactory::new();
        $page->markPersisted(3);

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1763560202);

        $page->with(ContentFactory::new());
    }
}
