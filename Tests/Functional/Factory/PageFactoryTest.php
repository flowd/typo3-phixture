<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Functional\Factory;

use Flowd\Typo3Phixture\Factory\PageFactory;
use Flowd\Typo3Phixture\Persistence\Persister;
use Flowd\Typo3Phixture\Site\SiteContext;
use Flowd\Typo3Phixture\Tests\Fixtures\Factory\ContentFactory;
use Flowd\Typo3Phixture\Tests\Functional\FixtureTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;

final class PageFactoryTest extends FixtureTestCase
{
    #[Test]
    public function inStoresThePageBelowTheRootPageOfTheSite(): void
    {
        $page = PageFactory::new()->in($this->site())->create();

        self::assertSame(1, $page->pid());
        self::assertSame(0, (int)$page->get('hidden'));
        self::assertSame(PageRepository::DOKTYPE_DEFAULT, (int)$page->get('doktype'));
    }

    #[Test]
    public function translatedPagesAreNotImplemented(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1763560201);

        PageFactory::new()->in(new SiteContext('main', 1));
    }

    #[Test]
    public function underStoresThePageBelowAnotherNewPage(): void
    {
        $parent = PageFactory::new()->in($this->site())->title('Parent');
        $child = PageFactory::new()->under($parent)->title('Child')->create();

        self::assertTrue($parent->isPersisted());
        self::assertSame($parent->uid(), $child->pid());
        self::assertSame('/parent/child', $child->get('slug'));
    }

    #[Test]
    public function urlIsTheAbsoluteFrontendUrl(): void
    {
        $page = PageFactory::new()->in($this->site())->slug('/products')->create();

        self::assertSame('https://main.phixture.test/products', $page->url());
    }

    #[Test]
    public function recordsOfThePageAreStoredOnItInTheOrderOfTheTest(): void
    {
        $first = ContentFactory::new()->header('First');
        $second = ContentFactory::new()->header('Second');
        $third = ContentFactory::new()->header('Third');

        $page = PageFactory::new()->in($this->site())->with($first, $second, $third)->create();

        $rows = $this->rows('tt_content', 'sorting');
        self::assertSame(['First', 'Second', 'Third'], array_column($rows, 'header'));
        self::assertSame([$page->uid(), $page->uid(), $page->uid()], array_map(intval(...), array_column($rows, 'pid')));
        self::assertSame($page->uid(), $second->pid());
    }

    #[Test]
    public function recordsCreatedOneAfterAnotherKeepTheOrderOfTheTest(): void
    {
        $page = PageFactory::new()->in($this->site())->with(ContentFactory::new()->header('First'))->create();

        ContentFactory::new()->under($page)->header('Second')->create();
        ContentFactory::new()->under($page->uid())->header('Third')->create();
        (new Persister())->persist(
            ContentFactory::new()->under($page)->header('Fourth'),
            ContentFactory::new()->under($page)->header('Fifth'),
        );

        self::assertSame(['First', 'Second', 'Third', 'Fourth', 'Fifth'], array_column($this->rows('tt_content', 'sorting'), 'header'));
    }

    #[Test]
    public function pagesCreatedOneAfterAnotherKeepTheOrderOfTheTest(): void
    {
        $first = PageFactory::new()->in($this->site())->title('First')->create();
        $second = PageFactory::new()->in($this->site())->title('Second')->create();

        // below the declared pages of the site, in the order of the test
        self::assertLessThan((int)$first->get('sorting'), (int)$this->pageTree()->page('storage.records')->get('sorting'));
        self::assertLessThan((int)$second->get('sorting'), (int)$first->get('sorting'));
        self::assertSame(1, $second->pid());
    }

    #[Test]
    public function urlOfAPageTheFrontendDoesNotShowIsItsOwnUrl(): void
    {
        $page = PageFactory::new()->in($this->site())->slug('/not-published')->hidden()->create();

        // not the URL of the site root, which the router returns for a page it does not find
        self::assertSame('https://main.phixture.test/not-published', $page->url());
    }

    #[Test]
    public function hiddenPageIsStoredHidden(): void
    {
        $page = PageFactory::new()->in($this->site())->hidden()->create();

        self::assertSame(1, (int)$page->get('hidden'));
    }
}
