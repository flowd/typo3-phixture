<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Unit\Site;

use Flowd\Typo3Phixture\Site\PageNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;

final class PageNodeTest extends TestCase
{
    #[Test]
    public function isAStandardPageWithoutUidSlugValuesAndChildrenByDefault(): void
    {
        $page = new PageNode('Footer');

        self::assertSame('Footer', $page->title);
        self::assertNull($page->uid);
        self::assertSame(PageRepository::DOKTYPE_DEFAULT, $page->doktype);
        self::assertNull($page->slug);
        self::assertSame([], $page->values);
        self::assertSame([], $page->children);
    }

    #[Test]
    public function keepsWhatItIsGiven(): void
    {
        $child = new PageNode('Imprint', 13, slug: '/imprint');
        $page = new PageNode(
            'Footer',
            12,
            PageRepository::DOKTYPE_SYSFOLDER,
            '/footer',
            ['nav_hide' => 1],
            [$child],
        );

        self::assertSame(12, $page->uid);
        self::assertSame(PageRepository::DOKTYPE_SYSFOLDER, $page->doktype);
        self::assertSame('/footer', $page->slug);
        self::assertSame(['nav_hide' => 1], $page->values);
        self::assertSame([$child], $page->children);
        self::assertSame('/imprint', $child->slug);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidUids(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[Test]
    #[DataProvider('invalidUids')]
    public function aUidIsAboveZero(int $uid): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1763900001);

        new PageNode('Footer', $uid);
    }
}
