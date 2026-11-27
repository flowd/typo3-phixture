<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Unit\Site;

use Flowd\Typo3Phixture\Factory\PageFactory;
use Flowd\Typo3Phixture\Site\PageNode;
use Flowd\Typo3Phixture\Site\PageTreeBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;

/**
 * Declared pages as page factories. Nothing is written here: storing them is covered by the functional
 * tests of the site page tree.
 */
final class PageTreeBuilderTest extends TestCase
{
    #[Test]
    public function buildsAFactoryPerPageBelowTheFactoryOfItsParent(): void
    {
        $tree = new PageTreeBuilder();

        $factories = $tree->add([
            new PageNode('Products', children: [
                new PageNode('Drones', children: [new PageNode('Vector')]),
                new PageNode('Software'),
            ]),
            new PageNode('Company'),
        ]);

        // the factories of the level that was added
        self::assertSame(['Products', 'Company'], self::titlesOf($factories));
        // a page before its sub pages, a level in the order of the declaration
        $pages = $tree->newPages();
        self::assertSame(['Products', 'Drones', 'Vector', 'Software', 'Company'], self::titlesOf($pages));
        self::assertSame(0, $pages[0]->storagePage());
        self::assertSame($pages[0], $pages[1]->storagePage());
        self::assertSame($pages[1], $pages[2]->storagePage());
        self::assertSame($pages[0], $pages[3]->storagePage());
        self::assertSame(0, $pages[4]->storagePage());
    }

    #[Test]
    public function storesTheTopLevelBelowTheGivenParent(): void
    {
        $parent = PageFactory::new();
        $tree = new PageTreeBuilder();

        $belowUid = $tree->add([new PageNode('Below a uid')], 12);
        $belowFactory = $tree->add([new PageNode('Below a factory')], $parent);

        self::assertSame(12, $belowUid[0]->storagePage());
        self::assertSame($parent, $belowFactory[0]->storagePage());
        // the parent is no page of the builder
        self::assertSame(['Below a uid', 'Below a factory'], self::titlesOf($tree->newPages()));
    }

    #[Test]
    public function setsWhatThePageDeclares(): void
    {
        $tree = new PageTreeBuilder();

        [$page] = $tree->add([
            new PageNode('Footer', 20, PageRepository::DOKTYPE_SYSFOLDER, '/footer', ['nav_hide' => 1]),
        ]);

        self::assertSame(20, $page->suggestedUid());
        self::assertSame(
            ['title' => 'Footer', 'doktype' => PageRepository::DOKTYPE_SYSFOLDER, 'hidden' => 0, 'slug' => '/footer', 'nav_hide' => 1],
            $page->values(),
        );
        self::assertSame([20 => $page], $tree->pagesByUid());
    }

    #[Test]
    public function pageWithoutUidLeavesTheUidToTheDatabase(): void
    {
        $tree = new PageTreeBuilder();

        [$page] = $tree->add([new PageNode('Careers')]);

        self::assertNull($page->suggestedUid());
        self::assertArrayNotHasKey('slug', $page->values());
        self::assertSame([], $tree->pagesByUid());
        self::assertSame([$page], $tree->newPages());
    }

    #[Test]
    public function pagesWithoutUidAreNeverTheSamePage(): void
    {
        $tree = new PageTreeBuilder();

        $factories = $tree->add([new PageNode('Overview'), new PageNode('Overview')]);

        self::assertNotSame($factories[0], $factories[1]);
        self::assertCount(2, $tree->newPages());
    }

    #[Test]
    public function pageWithAUidThatExistsIsNotBuiltAgain(): void
    {
        $storage = PageFactory::new();
        $storage->markPersisted(30);
        $tree = new PageTreeBuilder([30 => $storage]);

        $factories = $tree->add([
            new PageNode('Records', 30, PageRepository::DOKTYPE_SYSFOLDER, children: [
                new PageNode('Archive'),
            ]),
        ]);

        self::assertSame([$storage], $factories);
        // its declared sub pages go below the page that exists
        self::assertSame(['Archive'], self::titlesOf($tree->newPages()));
        self::assertSame($storage, $tree->newPages()[0]->storagePage());
        self::assertSame([30 => $storage], $tree->pagesByUid());
    }

    #[Test]
    public function pageDeclaredTwiceWithTheSameUidIsOnePage(): void
    {
        $tree = new PageTreeBuilder();

        $first = $tree->add([new PageNode('Records', 30)]);
        $second = $tree->add([new PageNode('Records', 30)], 12);

        self::assertSame($first, $second);
        self::assertSame($first, $tree->newPages());
        // where it was declared first
        self::assertSame(0, $first[0]->storagePage());
    }

    #[Test]
    public function buildsThePageWithTheFactoryOfTheProject(): void
    {
        $declared = [];
        $tree = new PageTreeBuilder(newFactory: static function (PageNode $page) use (&$declared): PageFactory {
            $declared[] = $page->title;

            return PageFactory::new()->siteRoot()->set('nav_hide', 1);
        });

        [$page] = $tree->add([new PageNode('Main', 1, slug: '/main')]);

        self::assertSame(['Main'], $declared);
        // the declaration is set on what the callback returns, and wins
        self::assertSame(
            ['title' => 'Main', 'doktype' => PageRepository::DOKTYPE_DEFAULT, 'hidden' => 0, 'is_siteroot' => 1, 'slug' => '/main', 'nav_hide' => 1],
            $page->values(),
        );
    }

    #[Test]
    public function doesNotAskForAFactoryOfAPageThatExists(): void
    {
        $root = PageFactory::new();
        $root->markPersisted(1);
        $tree = new PageTreeBuilder(
            [1 => $root],
            static fn(PageNode $page): PageFactory => self::fail(sprintf('Page "%s" exists.', $page->title)),
        );

        self::assertSame([$root], $tree->add([new PageNode('Main', 1)]));
        self::assertSame([], $tree->newPages());
    }

    /**
     * @param list<PageFactory> $pages
     * @return list<mixed>
     */
    private static function titlesOf(array $pages): array
    {
        return array_map(static fn(PageFactory $page): mixed => $page->values()['title'] ?? null, $pages);
    }
}
