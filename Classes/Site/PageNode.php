<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Site;

use TYPO3\CMS\Core\Domain\Repository\PageRepository;

/**
 * The declaration of one page of a page tree: its title, type and place, and the uid the site configuration
 * expects, if it expects one. The page tree of a site is declared with it (the pages that have to exist before
 * any test data), and so is any other page tree PageTreeBuilder turns into page factories.
 *
 * The place is the nesting: a page is stored below the page that lists it in $children. The pages a test
 * case returns from pageNodes() are stored at the top level of the page tree.
 */
final readonly class PageNode
{
    /**
     * @param int|null $uid the uid the page is stored with; without one the database assigns it, and the page
     *                      cannot be found by uid or setting name (SitePageTree::page())
     * @param string|null $slug without one, DataHandler generates it from the title
     * @param array<string, mixed> $values any other field of the page ("nav_hide", "shortcut", "url")
     * @param list<self> $children the pages below this one, in the order of the page tree
     */
    public function __construct(
        public string $title,
        public ?int $uid = null,
        public int $doktype = PageRepository::DOKTYPE_DEFAULT,
        public ?string $slug = null,
        public array $values = [],
        public array $children = [],
    ) {
        if ($uid !== null && $uid <= 0) {
            throw new \InvalidArgumentException(
                sprintf('Page "%s" is declared with uid %d: a uid is above 0, or left out.', $title, $uid),
                1763900001,
            );
        }
    }
}
