<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Site;

use Flowd\Typo3Phixture\Factory\PageFactory;

/**
 * Turns declared pages into page factories: one factory per page, stored below the factory of the page
 * that declares it as a child. Nothing is written; hand newPages() to the persister.
 *
 * A page with a uid is created once: declared again (by the page tree of another site, or twice in one
 * tree), it stands for the page that exists, and its children go below that page. A page without a uid is
 * a new page every time.
 */
final class PageTreeBuilder
{
    /** @var array<int, PageFactory> */
    private array $pagesByUid;

    /** @var list<PageFactory> */
    private array $newPages = [];

    /** @var \Closure(PageNode): PageFactory */
    private readonly \Closure $newFactory;

    /**
     * @param array<int, PageFactory> $existingPages pages an earlier builder created, by uid
     * @param (\Closure(PageNode): PageFactory)|null $newFactory returns the factory a declared page is
     *        built with: one of the project for a page type (with its records), or one with values the
     *        declaration does not have. Parent, uid, title, type, slug and values of the declaration are set
     *        on it afterwards. Without it, every page is a PageFactory.
     */
    public function __construct(array $existingPages = [], ?\Closure $newFactory = null)
    {
        $this->pagesByUid = $existingPages;
        $this->newFactory = $newFactory ?? static fn(PageNode $page): PageFactory => PageFactory::new();
    }

    /**
     * @param list<PageNode> $pages pages of one level, in the order of the page tree
     * @param PageFactory|int $parent the page they are stored below: a factory or a page uid, 0 for the
     *                                top level of the page tree
     * @return list<PageFactory> the factories of $pages, in that order
     */
    public function add(array $pages, PageFactory|int $parent = 0): array
    {
        $factories = [];
        foreach ($pages as $page) {
            $factory = $page->uid === null ? null : ($this->pagesByUid[$page->uid] ?? null);
            $factory ??= $this->declare($page, $parent);
            $factories[] = $factory;
            $this->add($page->children, $factory);
        }

        return $factories;
    }

    /**
     * The pages this builder made a factory for, a page before its sub pages: what the persister has to
     * write. The existing pages it was given are not among them.
     *
     * @return list<PageFactory>
     */
    public function newPages(): array
    {
        return $this->newPages;
    }

    /**
     * Every page with a uid known so far, the existing ones included.
     *
     * @return array<int, PageFactory>
     */
    public function pagesByUid(): array
    {
        return $this->pagesByUid;
    }

    private function declare(PageNode $page, PageFactory|int $parent): PageFactory
    {
        $factory = ($this->newFactory)($page)
            ->under($parent)
            ->title($page->title)
            ->doktype($page->doktype);
        if ($page->uid !== null) {
            $factory->withUid($page->uid);
            $this->pagesByUid[$page->uid] = $factory;
        }
        if ($page->slug !== null) {
            $factory->slug($page->slug);
        }
        foreach ($page->values as $field => $value) {
            $factory->set($field, $value);
        }
        $this->newPages[] = $factory;

        return $factory;
    }
}
