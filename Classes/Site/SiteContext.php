<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Site;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * One site in one language: what a test runs against.
 */
final readonly class SiteContext
{
    public function __construct(
        public string $siteIdentifier,
        public int $languageId = 0,
    ) {}

    public function site(): Site
    {
        return GeneralUtility::makeInstance(SiteFinder::class)->getSiteByIdentifier($this->siteIdentifier);
    }

    public function language(): SiteLanguage
    {
        return $this->site()->getLanguageById($this->languageId);
    }

    public function isDefaultLanguage(): bool
    {
        return $this->languageId === $this->site()->getDefaultLanguage()->getLanguageId();
    }

    public function rootPageId(): int
    {
        return $this->site()->getRootPageId();
    }

    public function baseUrl(): string
    {
        return (string)$this->language()->getBase();
    }
}
