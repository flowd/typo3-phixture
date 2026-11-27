<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Functional\TestCase;

use Flowd\Typo3Phixture\Factory\PageFactory;
use Flowd\Typo3Phixture\Tests\Functional\FixtureTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A page id setting of one site names the root page of another: a link to the other site.
 */
final class RootPageNamedBySettingTest extends FixtureTestCase
{
    protected array $sites = ['main', 'second'];

    #[\Override]
    protected function pageIdsFrom(array $settings): array
    {
        // 100 is the root page of site "second"
        return [...parent::pageIdsFrom($settings), 'otherSite' => 100];
    }

    #[Test]
    public function rootPageOfAnotherSiteIsNotCreatedAsAPageOfTheSite(): void
    {
        $root = $this->pageTree('second')->rootPage();

        self::assertSame(100, $root->uid());
        self::assertSame(0, $root->pid());
        self::assertSame(1, (int)$root->get('is_siteroot'));
        self::assertSame('/', $root->get('slug'));
    }

    #[Test]
    public function otherSiteRendersItsPages(): void
    {
        $page = PageFactory::new()->in($this->site('second'))->title('On second')->slug('/page')->create();

        self::assertSame('https://second.phixture.test/page', $page->url());
        self::assertSame('On second', self::textOf($this->visit($page)->querySelector('h1')));
    }
}
