<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Functional\TestCase;

use Flowd\Typo3Phixture\Factory\PageFactory;
use Flowd\Typo3Phixture\Site\PageNode;
use Flowd\Typo3Phixture\Tests\Functional\FixtureTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;

/**
 * Two sites with declared page trees that share a storage folder.
 */
final class DeclaredPageTreeTest extends FixtureTestCase
{
    protected array $sites = ['main', 'second'];

    #[\Override]
    protected function pageNodes(string $siteIdentifier, int $rootPageId, array $pageIds): array
    {
        $storage = new PageNode('Records', $pageIds['storage.records'], PageRepository::DOKTYPE_SYSFOLDER);

        return match ($siteIdentifier) {
            'main' => [
                new PageNode('Main site', $rootPageId, children: [
                    new PageNode(
                        'Footer',
                        $pageIds['footer'],
                        PageRepository::DOKTYPE_SYSFOLDER,
                        values: ['nav_hide' => 1],
                        children: [
                            new PageNode('Imprint', 21, slug: '/legal/imprint'),
                            new PageNode('Privacy', 22),
                            // no uid: nothing names these pages
                            new PageNode('Terms', children: [
                                new PageNode('Archive'),
                            ]),
                        ],
                    ),
                ]),
                $storage,
            ],
            // no root page declared: it is added
            default => [$storage],
        };
    }

    #[Test]
    public function declaredPagesAreCreatedWithUidTypePlaceAndOrder(): void
    {
        $pages = [];
        $sorting = [];
        foreach ($this->rows('pages') as $row) {
            $pages[(int)$row['uid']] = [(int)$row['pid'], $row['title'], (int)$row['doktype']];
            $sorting[(int)$row['uid']] = (int)$row['sorting'];
        }

        self::assertSame(
            [
                1 => [0, 'Main site', PageRepository::DOKTYPE_DEFAULT],
                20 => [1, 'Footer', PageRepository::DOKTYPE_SYSFOLDER],
                21 => [20, 'Imprint', PageRepository::DOKTYPE_DEFAULT],
                22 => [20, 'Privacy', PageRepository::DOKTYPE_DEFAULT],
                30 => [0, 'Records', PageRepository::DOKTYPE_SYSFOLDER],
                100 => [0, 'second', PageRepository::DOKTYPE_DEFAULT],
            ],
            // the pages declared with a uid
            array_intersect_key($pages, array_flip([1, 20, 21, 22, 30, 100])),
        );
        // pages of one level keep the order of the declaration
        self::assertLessThan($sorting[30], $sorting[1]);
        self::assertLessThan($sorting[22], $sorting[21]);
    }

    #[Test]
    public function pageDeclaredWithoutUidIsCreatedAtItsPlaceWithAUidOfTheDatabase(): void
    {
        $pages = array_column($this->rows('pages'), null, 'title');
        self::assertCount(8, $pages);
        $terms = $pages['Terms'];
        $archive = $pages['Archive'];

        self::assertNotContains((int)$terms['uid'], [1, 20, 21, 22, 30, 100]);
        self::assertSame(20, (int)$terms['pid']);
        // below the page that declares it, which has no declared uid either
        self::assertSame((int)$terms['uid'], (int)$archive['pid']);
        // after the pages declared before it
        self::assertGreaterThan((int)$pages['Privacy']['sorting'], (int)$terms['sorting']);
        // only a declared uid makes a page one the page tree can hand out
        self::assertFalse($this->pageTree()->hasPage((int)$terms['uid']));
    }

    #[Test]
    public function declaredFieldsOfAPageAreWritten(): void
    {
        $imprint = $this->pageTree()->page(21);

        self::assertSame('/legal/imprint', $imprint->get('slug'));
        self::assertSame(1, (int)$this->pageTree()->page('footer')->get('nav_hide'));
        // generated from the title; a folder is no part of the slug
        self::assertSame('/privacy', $this->pageTree()->page(22)->get('slug'));
    }

    #[Test]
    public function rootPagesAreSiteRoots(): void
    {
        self::assertSame(1, (int)$this->pageTree('main')->rootPage()->get('is_siteroot'));
        self::assertSame('/', $this->pageTree('main')->rootPage()->get('slug'));
        self::assertSame(100, $this->pageTree('second')->rootPage()->uid());
        self::assertSame(1, (int)$this->pageTree('second')->rootPage()->get('is_siteroot'));
    }

    #[Test]
    public function sitesShareAPageDeclaredByBoth(): void
    {
        self::assertSame($this->pageTree('main')->page('storage.records'), $this->pageTree('second')->page('storage.records'));
        self::assertSame($this->pageTree('main')->page(21), $this->pageTree('second')->page(21));
        self::assertFalse($this->pageTree('second')->hasPage('footer'));
    }

    #[Test]
    public function eachSiteRendersItsPages(): void
    {
        $onMain = PageFactory::new()->in($this->site('main'))->title('On main')->slug('/page')->create();
        $onSecond = PageFactory::new()->in($this->site('second'))->title('On second')->slug('/page')->create();

        self::assertSame('https://main.phixture.test/page', $onMain->url());
        self::assertSame('https://second.phixture.test/page', $onSecond->url());
        self::assertSame('On main', self::textOf($this->visit($onMain)->querySelector('h1')));
        self::assertSame('On second', self::textOf($this->visit($onSecond)->querySelector('h1')));
    }

    #[Test]
    public function recordCanBeStoredOnAPageOfThePageTree(): void
    {
        $page = PageFactory::new()->under($this->pageTree()->page('footer'))->title('Contact')->create();

        self::assertSame(20, $page->pid());
        self::assertSame('https://main.phixture.test/contact', $page->url());
    }
}
