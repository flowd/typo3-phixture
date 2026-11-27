<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Factory;

use Flowd\Typo3Phixture\Site\SiteContext;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PageFactory extends RecordFactory
{
    protected const string TABLE = 'pages';

    /** @var list<RecordFactory> */
    private array $content = [];

    #[\Override]
    protected function defaults(): array
    {
        return [
            'title' => 'Page',
            'doktype' => PageRepository::DOKTYPE_DEFAULT,
            // DataHandler creates pages hidden unless told otherwise
            'hidden' => 0,
        ];
    }

    public function hidden(bool $hidden = true): static
    {
        return $this->set('hidden', (int)$hidden);
    }

    /**
     * Stores the page below the root page of the site.
     */
    public function in(SiteContext $context): static
    {
        if (!$context->isDefaultLanguage()) {
            throw new \LogicException('Translated pages are not implemented yet.', 1763560201);
        }

        return $this->under($context->rootPageId());
    }

    public function title(string $title): static
    {
        return $this->set('title', $title);
    }

    /**
     * Without a slug, DataHandler generates one from the title.
     */
    public function slug(string $slug): static
    {
        return $this->set('slug', $slug);
    }

    public function doktype(int $doktype): static
    {
        return $this->set('doktype', $doktype);
    }

    public function siteRoot(): static
    {
        return $this->set('is_siteroot', 1)->set('slug', '/');
    }

    /**
     * Records stored on this page, in this order.
     */
    public function with(RecordFactory ...$records): static
    {
        if ($this->isPersisted()) {
            throw new \LogicException('The page is already persisted: create the record with under($page) instead.', 1763560202);
        }
        array_push($this->content, ...array_values($records));

        return $this;
    }

    /**
     * Absolute frontend URL of the page, also of one the frontend does not show (hidden, not yet published):
     * the router gets the stored row. From the uid alone it would not find such a page and return the URL
     * of the site root instead, which renders with status 200.
     */
    public function url(): string
    {
        $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($this->uid());

        return (string)$site->getRouter()->generateUri($this->row());
    }

    #[\Override]
    public function attachedRecords(): array
    {
        return $this->content;
    }
}
