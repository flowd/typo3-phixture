<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Functional;

use Flowd\Typo3Phixture\TestCase\DomTestCase;

/**
 * Base of the functional tests: the fixture extension (tables, static data, a site set that renders a page)
 * and the fixture sites.
 */
abstract class FixtureTestCase extends DomTestCase
{
    protected array $testExtensionsToLoad = ['flowd/typo3-phixture-fixture'];

    protected array $sites = ['main'];

    protected string $pageIdSettingsPath = 'phixture.pageIds';

    #[\Override]
    protected function projectSitesPath(): string
    {
        return dirname(__DIR__) . '/Fixtures/Sites';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function rows(string $table, string $orderBy = 'uid'): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder->select('*')->from($table)->orderBy($orderBy)->executeQuery()->fetchAllAssociative();
    }
}
