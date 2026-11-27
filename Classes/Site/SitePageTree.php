<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Site;

use Flowd\Typo3Phixture\Factory\PageFactory;
use Flowd\Typo3Phixture\Persistence\Persister;

/**
 * The pages a site needs before any test data: the root page and the pages the test case declares for the
 * site, each with its type and place in the page tree, and its uid if the site configuration names it. All
 * pages of a site are written with one DataHandler run.
 *
 * The site settings do not create pages, they name them: page('footerNav') is the declared page with the
 * uid of that setting. A page declared without a uid exists, but cannot be found here.
 */
final class SitePageTree
{
    /** @var array<int, PageFactory> every page with a uid known so far, also the ones of other sites, by uid */
    private array $pagesByUid;

    /**
     * @param list<PageNode> $pages the declared pages of the top level of the page tree; the page with
     *                                  the uid of the root page is the site root, without one it is added
     * @param array<string, int> $pageIds setting name (below the page id settings path) => page uid
     * @param array<int, PageFactory> $existingPages pages the page trees of other sites already created, by uid
     */
    public function __construct(
        public readonly SiteContext $context,
        private readonly int $rootPageId,
        array $pages,
        private readonly array $pageIds = [],
        array $existingPages = [],
    ) {
        if (isset($existingPages[$rootPageId])) {
            // a page is only made a site root when it is created
            $isSiteRoot = $existingPages[$rootPageId]->values()['is_siteroot'] ?? 0;
            if (!is_numeric($isSiteRoot) || (int)$isSiteRoot !== 1) {
                throw new \LogicException(
                    sprintf(
                        'Page %d is the root page of site "%s", but the page tree of another site already created it as a page that is no site root. Do not declare it there.',
                        $rootPageId,
                        $context->siteIdentifier,
                    ),
                    1795600005,
                );
            }
        } elseif (!self::declares($pages, $rootPageId)) {
            array_unshift($pages, new PageNode($context->siteIdentifier, $rootPageId));
        }

        $tree = new PageTreeBuilder(
            $existingPages,
            static fn(PageNode $page): PageFactory => $page->uid === $rootPageId
                ? PageFactory::new()->siteRoot()
                : PageFactory::new(),
        );
        $tree->add($pages);
        $this->pagesByUid = $tree->pagesByUid();

        if ($tree->newPages() !== []) {
            (new Persister())->persist(...$tree->newPages());
        }
    }

    public function rootPage(): PageFactory
    {
        return $this->pagesByUid[$this->rootPageId];
    }

    /**
     * A declared page, by its uid or by the name of its page id setting below the page id settings path
     * ("footerNav", "storage.recipes").
     */
    public function page(int|string $uidOrSettingName): PageFactory
    {
        if (is_int($uidOrSettingName)) {
            return $this->pagesByUid[$uidOrSettingName] ?? throw new \OutOfBoundsException(
                sprintf('The page tree of site "%s" declares no page %d.', $this->context->siteIdentifier, $uidOrSettingName),
                1763560303,
            );
        }

        $uid = $this->pageIds[$uidOrSettingName] ?? throw new \OutOfBoundsException(
            sprintf('Site "%s" has no page id setting "%s" with a page uid.', $this->context->siteIdentifier, $uidOrSettingName),
            1763560301,
        );

        return $this->pagesByUid[$uid] ?? throw new \OutOfBoundsException(
            sprintf(
                'Setting "%s" of site "%s" names page %d, which the page tree does not declare.',
                $uidOrSettingName,
                $this->context->siteIdentifier,
                $uid,
            ),
            1763560304,
        );
    }

    public function hasPage(int|string $uidOrSettingName): bool
    {
        $uid = is_int($uidOrSettingName) ? $uidOrSettingName : ($this->pageIds[$uidOrSettingName] ?? 0);

        return isset($this->pagesByUid[$uid]);
    }

    /**
     * Every page known after this page tree, by uid: hand it to the page tree of the next site.
     *
     * @return array<int, PageFactory>
     */
    public function pagesByUid(): array
    {
        return $this->pagesByUid;
    }

    /**
     * @param list<PageNode> $pages
     */
    private static function declares(array $pages, int $uid): bool
    {
        foreach ($pages as $page) {
            if ($page->uid === $uid || self::declares($page->children, $uid)) {
                return true;
            }
        }

        return false;
    }
}
