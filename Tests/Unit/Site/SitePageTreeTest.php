<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Unit\Site;

use Flowd\Typo3Phixture\Factory\PageFactory;
use Flowd\Typo3Phixture\Site\PageNode;
use Flowd\Typo3Phixture\Site\SiteContext;
use Flowd\Typo3Phixture\Site\SitePageTree;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;

/**
 * Finding the pages of a site page tree. Here every declared page already exists, as for the second site of a
 * test that shares its pages with the first: nothing is written. Creating pages is covered by the
 * functional tests.
 */
final class SitePageTreeTest extends TestCase
{
    private PageFactory $root;

    private PageFactory $footer;

    private PageFactory $storage;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = self::persistedPage(1, true);
        $this->footer = self::persistedPage(20);
        $this->storage = self::persistedPage(30);
    }

    #[Test]
    public function knowsItsSite(): void
    {
        self::assertSame('main', $this->pageTree()->context->siteIdentifier);
        self::assertSame(0, $this->pageTree()->context->languageId);
    }

    #[Test]
    public function rootPageIsThePageWithTheRootPageIdOfTheSite(): void
    {
        self::assertSame($this->root, $this->pageTree()->rootPage());
    }

    #[Test]
    public function findsAPageByUid(): void
    {
        self::assertSame($this->footer, $this->pageTree()->page(20));
        self::assertTrue($this->pageTree()->hasPage(20));
    }

    #[Test]
    public function findsAPageByTheNameOfItsSetting(): void
    {
        self::assertSame($this->footer, $this->pageTree()->page('footer'));
        self::assertSame($this->storage, $this->pageTree()->page('storage.records'));
        self::assertTrue($this->pageTree()->hasPage('storage.records'));
    }

    #[Test]
    public function pageThatIsNotDeclaredIsRejected(): void
    {
        self::assertFalse($this->pageTree()->hasPage(99));

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionCode(1763560303);

        $this->pageTree()->page(99);
    }

    #[Test]
    public function settingThatDoesNotExistIsRejected(): void
    {
        self::assertFalse($this->pageTree()->hasPage('unknown'));

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionCode(1763560301);

        $this->pageTree()->page('unknown');
    }

    #[Test]
    public function settingNamingAPageThatIsNotDeclaredIsRejected(): void
    {
        self::assertFalse($this->pageTree()->hasPage('search'));

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionCode(1763560304);

        $this->pageTree()->page('search');
    }

    #[Test]
    public function handsAllKnownPagesToTheNextPageTree(): void
    {
        self::assertSame(
            [1 => $this->root, 20 => $this->footer, 30 => $this->storage],
            $this->pageTree()->pagesByUid(),
        );
    }

    #[Test]
    public function declaredPageThatAlreadyExistsIsNotCreatedAgain(): void
    {
        $pageTree = new SitePageTree(
            new SiteContext('main'),
            1,
            [
                new PageNode('Main', 1, children: [new PageNode('Footer', 20)]),
                new PageNode('Records', 30, PageRepository::DOKTYPE_SYSFOLDER),
            ],
            [],
            [1 => $this->root, 20 => $this->footer, 30 => $this->storage],
        );

        self::assertSame($this->footer, $pageTree->page(20));
        self::assertSame($this->storage, $pageTree->page(30));
    }

    #[Test]
    public function rootPageAnotherSiteCreatedAsAPageThatIsNoSiteRootIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1795600005);

        // page 20 exists as a sub page of site "main"
        new SitePageTree(new SiteContext('second'), 20, [], [], [1 => $this->root, 20 => $this->footer]);
    }

    private function pageTree(): SitePageTree
    {
        return new SitePageTree(
            new SiteContext('main'),
            1,
            [],
            ['footer' => 20, 'storage.records' => 30, 'search' => 40],
            [1 => $this->root, 20 => $this->footer, 30 => $this->storage],
        );
    }

    private static function persistedPage(int $uid, bool $siteRoot = false): PageFactory
    {
        $page = PageFactory::new();
        if ($siteRoot) {
            $page->siteRoot();
        }
        $page->markPersisted($uid);

        return $page;
    }
}
