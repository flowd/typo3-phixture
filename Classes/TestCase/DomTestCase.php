<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\TestCase;

use Flowd\Typo3Phixture\Dom\HtmlNode;
use Flowd\Typo3Phixture\Factory\PageFactory;
use Flowd\Typo3Phixture\Site\PageNode;
use Flowd\Typo3Phixture\Site\SiteContext;
use Flowd\Typo3Phixture\Site\SitePageTree;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Filesystem\Filesystem;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\Hooks\CreateSiteConfiguration;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Composer\ComposerPackageManager;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3\TestingFramework\Core\Testbase;

/**
 * Acceptance tests without a browser: the test builds its data with factories, renders a page through the
 * full frontend stack in-process and asserts on the DOM.
 *
 * Every test starts with an empty database plus the static data of the extensions and the declared pages
 * of its sites. Instance and database are the ones of typo3/testing-framework: one of each per test class,
 * separate from the development instance.
 */
abstract class DomTestCase extends FunctionalTestCase
{
    /**
     * Identifiers of the sites (directories in config/sites) the tests of this class create the declared
     * pages for. The first one is the default of site() and pageTree(). The other sites of the project exist
     * as well, without pages.
     *
     * @var list<string>
     */
    protected array $sites = [];

    /**
     * Site settings path below which every integer setting is a page uid ("vendor.pageIds").
     */
    protected string $pageIdSettingsPath = '';

    /** @var array<string, SitePageTree> */
    private array $pageTrees = [];

    /** @var list<int> the root pages of all sites in $sites */
    private array $rootPageIds = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->useImageProcessingOfProject();

        if ($this->sites === []) {
            throw new \LogicException(static::class . ' must name its sites in $sites.', 1763560401);
        }
        $this->linkProjectSites();

        parent::setUp();

        $this->keepTypo3FromWritingSiteConfiguration();
        $this->keepSiteWriterOutOfTheProject();
        // before the backend user: static data replaces the content of its tables
        $this->importStaticData();
        $this->setUpAdminBackendUser();

        $siteFinder = $this->get(SiteFinder::class);

        $this->rootPageIds = array_map(
            static fn(string $identifier): int => $siteFinder->getSiteByIdentifier($identifier)->getRootPageId(),
            $this->sites,
        );
        $this->pageTrees = [];
        $pagesByUid = [];
        foreach ($this->sites as $identifier) {
            $site = $siteFinder->getSiteByIdentifier($identifier);
            $rootPageId = $site->getRootPageId();
            $pageIds = $this->pageIdsFrom($site->getSettings()->getAll());
            $this->pageTrees[$identifier] = new SitePageTree(
                new SiteContext($identifier),
                $rootPageId,
                $this->pageNodes($identifier, $rootPageId, $pageIds),
                $pageIds,
                $pagesByUid,
            );
            $pagesByUid = $this->pageTrees[$identifier]->pagesByUid();
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->pageTrees = [];
        parent::tearDown();
    }

    /**
     * A site in its default language.
     */
    protected function site(?string $identifier = null): SiteContext
    {
        return $this->pageTree($identifier)->context;
    }

    protected function pageTree(?string $identifier = null): SitePageTree
    {
        $identifier ??= $this->sites[0];

        return $this->pageTrees[$identifier] ?? throw new \OutOfBoundsException(
            sprintf('Site "%s" is not listed in %s::$sites.', $identifier, static::class),
            1763560402,
        );
    }

    /**
     * Renders a page and returns its DOM. Fails on any status but 200; use visitResponse() to test error
     * pages and redirects.
     */
    protected function visit(PageFactory|string $page): HtmlNode
    {
        $url = is_string($page) ? $page : $page->url();
        $response = $this->visitResponse($url);
        if ($response->getStatusCode() !== 200) {
            self::fail(sprintf(
                "Status %d %s for %s\n\nResponse: %s\n\nPages in the database:\n%s",
                $response->getStatusCode(),
                $response->getReasonPhrase(),
                $url,
                mb_substr(trim((string)preg_replace('/\s+/', ' ', strip_tags((string)$response->getBody()))), 0, 600),
                $this->describePages(),
            ));
        }

        return HtmlNode::fromHtml((string)$response->getBody());
    }

    /**
     * @param array<string, string> $headers request headers, such as "Accept" for a request a script would send
     */
    protected function visitResponse(PageFactory|string $page, array $headers = []): ResponseInterface
    {
        $request = new InternalRequest(is_string($page) ? $page : $page->url());
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->executeFrontendSubRequest($request);
    }

    /**
     * The trimmed text of an element with whitespace runs collapsed, as a reader sees it.
     */
    protected static function textOf(?HtmlNode $element): string
    {
        self::assertNotNull($element, 'Element not found.');

        return trim((string)preg_replace('/\s+/', ' ', $element->textContent));
    }

    /**
     * The fields that decide whether the frontend can reach a page, for the message of a failed visit().
     */
    private function describePages(): string
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'pid', 'title', 'slug', 'doktype', 'is_siteroot', 'hidden', 'deleted', 'starttime', 'endtime', 'fe_group', 'sys_language_uid', 't3ver_wsid', 't3ver_state')
            ->from('pages')
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return implode("\n", array_map(static fn(array $row): string => (string)json_encode($row), $rows));
    }

    /**
     * Imports the static data of the loaded extensions ("ext_tables_static+adt.sql"), as TYPO3 does when an
     * extension is set up. The testing framework only creates the schema.
     *
     * This is the one place that writes without DataHandler: static data is part of an extension like its
     * schema, not data of a test, and its tables can be read-only in TCA, which DataHandler skips.
     */
    private function importStaticData(): void
    {
        $sqlReader = GeneralUtility::makeInstance(SqlReader::class);
        $statements = [];
        foreach ($this->get(PackageManager::class)->getActivePackages() as $package) {
            $file = $package->getPackagePath() . 'ext_tables_static+adt.sql';
            if (is_file($file)) {
                array_push($statements, ...$sqlReader->getStatementArray((string)file_get_contents($file)));
            }
        }
        if ($statements === []) {
            return;
        }

        // truncate: the import must give the same uids in every test. One import for all extensions: a
        // table is emptied once, also when several extensions bring rows for it.
        $results = GeneralUtility::makeInstance(SchemaMigrator::class)->importStaticData($statements, true);
        $errors = array_filter($results, static fn(string $error): bool => $error !== '');
        if ($errors !== []) {
            throw new \RuntimeException(
                sprintf("Static data could not be imported:\n- %s", implode("\n- ", $errors)),
                1764000101,
            );
        }

        // rows with explicit uids do not move the sequence of a table on PostgreSQL
        $tables = [];
        foreach ($statements as $statement) {
            if (preg_match('/^INSERT\s+INTO\s+`?(\w+)`?/i', $statement, $matches) === 1) {
                $tables[$matches[1]] = true;
            }
        }
        foreach (array_keys($tables) as $table) {
            Testbase::resetTableSequences($this->getConnectionPool()->getConnectionForTable($table), $table);
        }
    }

    /**
     * TYPO3 writes a site configuration ("autogenerated-...") for every new page at the top level of the
     * page tree, or with "is_siteroot", that is no root page of a site yet. Here that would be a write into
     * config/sites of the project, through the link of linkProjectSites().
     */
    private function keepTypo3FromWritingSiteConfiguration(): void
    {
        $hooks = $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'] ?? [];
        if (!is_array($hooks)) {
            return;
        }
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'] = array_filter(
            $hooks,
            static fn(mixed $hook): bool => $hook !== CreateSiteConfiguration::class,
        );
    }

    /**
     * Whatever else writes site configuration (the SiteWriter of TYPO3: site settings, the site module, code
     * of the project) would write into config/sites of the project as well. The container gets a SiteWriter
     * for a directory that cannot exist, below a file: every write fails, and nothing reaches the project.
     *
     * A SiteWriter with another directory, not a subclass that throws: the class is readonly in one TYPO3
     * version and not in another, and a subclass has to match.
     */
    private function keepSiteWriterOutOfTheProject(): void
    {
        $container = $this->getContainer();
        if (!$container instanceof Container) {
            throw new \LogicException('The container of the test instance cannot replace a service.', 1795600006);
        }
        // the name shows in the message of a failed write
        $notADirectory = static::getInstancePath() . '/typo3temp/var/site-configuration-is-read-only-in-tests';
        if (!is_file($notADirectory) && !touch($notADirectory)) {
            throw new \RuntimeException(sprintf('File "%s" could not be created.', $notADirectory), 1795600007);
        }
        $container->set(SiteWriter::class, new SiteWriter(
            $notADirectory . '/sites',
            $this->get(EventDispatcherInterface::class),
            $this->get(YamlFileLoader::class),
        ));
    }

    /**
     * DataHandler only writes for a backend user; the factories need an admin.
     */
    private function setUpAdminBackendUser(): void
    {
        // without a uid: the database assigns it, which keeps the sequence of the table right on PostgreSQL
        $connection = $this->getConnectionPool()->getConnectionForTable('be_users');
        $connection->insert('be_users', [
            'pid' => 0,
            'username' => 'phixture',
            'password' => '',
            'admin' => 1,
        ]);
        $backendUser = $this->setUpBackendUser((int)$connection->lastInsertId());
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * Links config/sites of the project into the test instance, the way of typo3/testing-framework for a
     * site configuration that is given and not written by the test. TYPO3 loads the sites as in the project:
     * imports, placeholders (also the processors the extensions register), base variants of the Testing
     * context. All sites of the project exist, with the hosts the project configures.
     *
     * The link has to be registered under exactly this destination: tearDown() of the testing framework
     * deletes "typo3conf/sites" recursively unless it is listed as a linked path.
     *
     * Nothing may write site configuration in a test: it would write into the project. What TYPO3 writes on
     * its own for a new root page is switched off, see keepTypo3FromWritingSiteConfiguration(); its SiteWriter
     * fails, see keepSiteWriterOutOfTheProject().
     */
    private function linkProjectSites(): void
    {
        $sitesPath = $this->projectSitesPath();
        foreach ($this->sites as $identifier) {
            if (!is_file($sitesPath . '/' . $identifier . '/config.yaml')) {
                throw new \RuntimeException(sprintf('No site configuration in %s/%s.', $sitesPath, $identifier), 1763560403);
            }
        }
        if (in_array('typo3conf/sites', $this->pathsToLinkInTestInstance, true)) {
            return;
        }
        // the testing framework takes the source of a link relative to the instance
        $source = rtrim((new Filesystem())->makePathRelative($sitesPath, static::getInstancePath()), '/');
        $this->pathsToLinkInTestInstance[$source] = 'typo3conf/sites';
    }

    /**
     * Image processing as in the project: the testing framework defaults to GraphicsMagick, which renders
     * differently and is often not installed. A test class that configures GFX itself keeps its values.
     */
    private function useImageProcessingOfProject(): void
    {
        $settingsFile = $this->projectSystemSettingsPath();
        if (isset($this->configurationToUseInTestInstance['GFX']) || !is_file($settingsFile)) {
            return;
        }
        $settings = require $settingsFile;
        if (is_array($settings) && is_array($settings['GFX'] ?? null)) {
            $this->configurationToUseInTestInstance['GFX'] = $settings['GFX'];
        }
    }

    /**
     * The system settings of the project. Override it when they are not in config/system/settings.php.
     */
    protected function projectSystemSettingsPath(): string
    {
        return $this->projectPath() . '/config/system/settings.php';
    }

    /**
     * The directory of the project with one directory per site, and what their files import.
     */
    protected function projectSitesPath(): string
    {
        return $this->projectPath() . '/config/sites';
    }

    /**
     * The directory with the composer.json of the project, where TYPO3 has its "config" directory. Not taken
     * from the web root: that is "public" in one project and ".Build/public" in another. Not taken from
     * Environment either: in a functional test it describes the test instance (getConfigPath() is the
     * "typo3conf" of the instance), and it is not initialized before the first setUp(). The testing framework
     * knows the project from Composer, as it does for the vendor directory and the extensions of a test.
     */
    private function projectPath(): string
    {
        return (new ComposerPackageManager())->getRootPath();
    }

    /**
     * The pages a site needs before any test data, as a tree from the top level of the page tree: the root
     * page of the site with its sub pages, and pages outside the site such as shared storage folders.
     *
     * Override it to declare the pages of the project with their type and place. A page needs a uid only
     * where something names it: a site setting, the site configuration or the test. Without an override,
     * the tree is the root page and one standard page directly below it for every page id setting. A
     * setting that names the root page of a site in $sites creates no page: that page is the root of its
     * own site.
     *
     * @param array<string, int> $pageIds setting name => page uid, from pageIdsFrom()
     * @return list<PageNode>
     */
    protected function pageNodes(string $siteIdentifier, int $rootPageId, array $pageIds): array
    {
        $children = [];
        foreach ($pageIds as $name => $uid) {
            // a setting of 0 means "not configured"; several settings can name the same page
            if ($uid <= 0 || $uid === $rootPageId || isset($children[$uid]) || in_array($uid, $this->rootPageIds, true)) {
                continue;
            }
            // settings pages are containers; keep them out of the main navigation
            $children[$uid] = new PageNode($name, $uid, values: ['nav_hide' => 1]);
        }

        return [new PageNode($siteIdentifier, $rootPageId, children: array_values($children))];
    }

    /**
     * The page uids the settings of the site name: every integer below $pageIdSettingsPath. SitePageTree
     * finds its pages by these names. Override it when the branch also holds integers that are no page uid,
     * or page uids that are no integer.
     *
     * @param array<string, mixed> $settings
     * @return array<string, int>
     */
    protected function pageIdsFrom(array $settings): array
    {
        if ($this->pageIdSettingsPath === '') {
            return [];
        }
        $branch = $settings;
        foreach (explode('.', $this->pageIdSettingsPath) as $segment) {
            $branch = is_array($branch) ? ($branch[$segment] ?? []) : [];
        }

        $pageIds = [];
        $flatten = static function (array $values, string $prefix) use (&$flatten, &$pageIds): void {
            foreach ($values as $name => $value) {
                if (is_array($value)) {
                    $flatten($value, $prefix . $name . '.');
                } elseif (is_int($value)) {
                    $pageIds[$prefix . $name] = $value;
                }
            }
        };
        $flatten(is_array($branch) ? $branch : [], '');

        return $pageIds;
    }
}
