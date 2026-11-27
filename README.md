# phixture

Acceptance tests for TYPO3 13.4 and 14 on top of `typo3/testing-framework`: a test builds its data with
object-oriented factories that write through the TYPO3 DataHandler, renders a page through the full frontend
stack in-process and asserts on the DOM. No CSV fixtures, no raw SQL for content.

State: early. Pages, content records, relations between factories, files and a declared page tree per site
work; translations, a site/language matrix and a browser tier are not built.

## Install

```bash
composer require --dev flowd/typo3-phixture
```

| | Versions |
| --- | --- |
| TYPO3 | 13.4, 14.3 |
| PHP | 8.3, 8.4, 8.5, 8.6 |
| PHPUnit | 11.5, 12.5, 13.4 |

Needs `ext-dom` and `ext-gd` and a database for the functional test databases of `typo3/testing-framework`.

## Use

1. A base class for the project: the extensions the site needs, its sites (directories in `config/sites/`),
   the settings path below which the site settings name page uids, and the pages that have to exist before
   any test data.

   ```php
   use Flowd\Typo3Phixture\Site\PageNode;
   use TYPO3\CMS\Core\Domain\Repository\PageRepository;

   abstract class SiteTestCase extends \Flowd\Typo3Phixture\TestCase\DomTestCase
   {
       protected array $coreExtensionsToLoad = ['seo'];
       protected array $testExtensionsToLoad = ['vendor/site-package'];
       protected array $sites = ['main'];
       protected string $pageIdSettingsPath = 'vendor.pageIds';

       #[\Override]
       protected function pageNodes(string $siteIdentifier, int $rootPageId, array $pageIds): array
       {
           return [
               new PageNode('Main', uid: $rootPageId, children: [
                   new PageNode(
                       'Footer',
                       uid: $pageIds['footerNav'],
                       doktype: PageRepository::DOKTYPE_SYSFOLDER,
                       children: [
                           new PageNode('Imprint', uid: 31, slug: '/imprint'),
                           // no uid: nothing names this page
                           new PageNode('Privacy'),
                       ],
                   ),
               ]),
               // outside the site: a storage folder several sites share
               new PageNode('Records', uid: 18, doktype: PageRepository::DOKTYPE_SYSFOLDER),
           ];
       }
   }
   ```

   The nesting is the page tree. Without `pageNodes()` the tree is the root page and one standard
   page below it for every page id setting. `$this->pageTree()->page('footerNav')` or `->page(31)` returns
   the page factory of a declared page.

   A page needs a uid only where something names it: a site setting, the site configuration or the test.
   Without one the database assigns it, and `pageTree()` cannot hand the page out. Keep declared uids clear
   of the ones the database assigns: an assigned uid is the highest uid stored so far plus one, and a page
   declared with exactly that uid further down the tree can no longer be stored.

2. A factory per record type of the project:

   ```php
   final class TextFactory extends \Flowd\Typo3Phixture\Factory\RecordFactory
   {
       protected const string TABLE = 'tt_content';

       #[\Override]
       protected function defaults(): array
       {
           return ['CType' => 'text', 'colPos' => 0, 'header' => 'Text'];
       }

       public function header(string $header): static
       {
           return $this->set('header', $header);
       }

       public function image(\Flowd\Typo3Phixture\Factory\ImageFactory $image): static
       {
           return $this->set('image', $image);
       }
   }
   ```

3. Tests:

   ```php
   final class TextTest extends SiteTestCase
   {
       #[Test]
       public function rendersHeadline(): void
       {
           $text = TextFactory::new()->header('Hello');
           $page = PageFactory::new()->in($this->site())->title('Vector')->with($text)->create();

           $dom = $this->visit($page);

           self::assertSame('Hello', self::textOf($dom->querySelector('#c' . $text->uid() . ' h2')));
       }
   }
   ```

4. A PHPUnit configuration like the one for functional tests (bootstrap
   `vendor/typo3/testing-framework/Resources/Core/Build/FunctionalTestsBootstrap.php`, `TYPO3_CONTEXT=Testing`)
   and the `typo3Database*` environment variables of `typo3/testing-framework`.

## What it does

| Class | Role |
| --- | --- |
| `Factory\RecordFactory` | describes one record and stores it with `create()`; afterwards the same object reads and changes the stored record: `uid()`, `row()`, `get()`, `update()` |
| `Factory\PageFactory` | pages: `in($site)`, `under($page)`, `with(...$records)`, `url()` |
| `Factory\FileReferenceFactory` | base of the file references: adds the file through the file abstraction layer, DataHandler writes the reference |
| `Factory\ImageFactory` | a file reference to a generated placeholder image |
| `Factory\FileFactory` | a file reference to a copy of a file of the project (`FileFactory::fromPath()`), such as a PDF |
| `Persistence\Persister` | writes the whole graph of factories, or several graphs, with one DataHandler run |
| `Site\PageNode` | declaration of one page: title, uid (optional), type, slug, further fields, sub pages |
| `Site\PageTreeBuilder` | turns declared pages into page factories, below a page or at the top level of the page tree |
| `Site\SitePageTree` | creates the declared pages of a site with one DataHandler run; finds them by uid or setting name |
| `Site\SiteContext` | a site in a language |
| `TestCase\DomTestCase` | admin backend user, the project's real site configuration, the declared pages of its sites, `visit()` |

- The instance of a factory is the identity of its record: referenced twice, persisted once.
- Declared pages are not tied to a test: `PageTreeBuilder` builds the factories of any declared tree, for
  example to seed a development database with the page tree of the project. Its callback picks the factory
  of a page, such as one of the project for a page type, with the records of that page:

  ```php
  $tree = new PageTreeBuilder(newFactory: static fn(PageNode $page): PageFactory => match ($page->doktype) {
      ProductPageFactory::DOKTYPE => ProductPageFactory::new()->with(TextFactory::new()),
      default => PageFactory::new(),
  });
  $tree->add($declaredPages, $rootPageId);
  (new Persister())->persist(...$tree->newPages());
  ```
- A relation field takes factories; the persister writes the syntax TCA asks for.
- A record without `under()` is stored on the page of the record that references it.
- Records of a page keep the order of the test, also across several `create()` calls: a record created later
  is stored below the ones the page already has.
- `update()` stores factories in its values that are not persisted yet, on the page of the updated record.
- `DomTestCase` links `config/sites/` of the project into the test instance. TYPO3 loads the sites as in the
  project (imports, placeholders, base variants of the Testing context); hosts are the ones configured there.
  A test must not write site configuration: it would change the project. The site configuration TYPO3 writes
  on its own for a new root page ("autogenerated-...") is switched off.
- The test instances of `typo3/testing-framework` are below the web root (`public/typo3temp/var/tests/`), with
  the link to `config/sites/` and the files of the tests. Run the tests where that directory is not served to
  others: locally or in CI, not on a host that is reachable from outside.
- `visit()` returns a `Dom\HtmlNode` (the `querySelector()` API of PHP 8.4's `Dom\HTMLDocument`, for PHP 8.3); on a status other than 200 the failure shows the response and the
  pages in the database.
- Isolation is the one of `typo3/testing-framework`: an instance and a database per test class, emptied before
  every test.
- Static data of the loaded extensions (`ext_tables_static+adt.sql`) is imported before every test. It is the one
  thing written without DataHandler: tables of static data can be read-only in TCA, which DataHandler skips.
- A file is named by its name and folder: two file factories with the same name are one file with one set of
  metadata. Giving that name another content or other metadata values in the same test is an error.
- Image processing follows `config/system/settings.php` of the project (`GFX`) when the test class does not
  configure it.

## Development

```bash
composer install
composer check            # lint, coding guidelines, PHPStan, unit and functional tests
composer fix              # apply the coding guidelines
composer test:unit
composer test:functional  # SQLite by default, see phpunit.xml.dist
```

`Tests/Unit` covers what runs without TYPO3: the DOM wrapper, the factories as builders, the data map the
persister hands to DataHandler (with a DataHandler double), the factories of declared pages and the lookup of
declared pages.
`Tests/Functional` covers what only TYPO3 can answer, with the fixture extension in
`Tests/Fixtures/Packages/phixture_fixture` and the sites in `Tests/Fixtures/Sites`: what DataHandler stores for
each relation type, files, the declared page tree, static data, site configuration and frontend rendering.

## License

GPL-2.0-or-later, see `LICENSE`.
