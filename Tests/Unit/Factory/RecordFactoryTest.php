<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Unit\Factory;

use Flowd\Typo3Phixture\Factory\PageFactory;
use Flowd\Typo3Phixture\Tests\Fixtures\Factory\ChildFactory;
use Flowd\Typo3Phixture\Tests\Fixtures\Factory\ContentFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordFactoryTest extends TestCase
{
    #[Test]
    public function startsWithTheDefaultsOfItsType(): void
    {
        self::assertSame(['CType' => 'text', 'colPos' => 0, 'header' => 'Content'], ContentFactory::new()->values());
    }

    #[Test]
    public function hasNoDefaultsUnlessItsTypeDeclaresThem(): void
    {
        self::assertSame([], ChildFactory::new()->values());
    }

    #[Test]
    public function namesItsTable(): void
    {
        self::assertSame('tt_content', ContentFactory::new()->table());
    }

    #[Test]
    public function newCreatesANewInstanceWithEveryCall(): void
    {
        // that it is an instance of the called type is what the return type "static" says
        $first = ContentFactory::new();
        $second = ContentFactory::new();

        self::assertNotSame($first, $second);
    }

    #[Test]
    public function setOverridesDefaultsAndAddsFields(): void
    {
        $content = ContentFactory::new()->set('header', 'Hello')->set('subheader', 'World');

        self::assertSame(
            ['CType' => 'text', 'colPos' => 0, 'header' => 'Hello', 'subheader' => 'World'],
            $content->values(),
        );
    }

    #[Test]
    public function setKeepsFactoriesAsValues(): void
    {
        $child = ChildFactory::titled('Child');
        $content = ContentFactory::new()->set('relation', [$child]);

        self::assertSame([$child], $content->values()['relation']);
    }

    #[Test]
    public function hasNoPageUntilItIsGivenOne(): void
    {
        self::assertNull(ContentFactory::new()->storagePage());
    }

    #[Test]
    public function underTakesAPageUid(): void
    {
        self::assertSame(12, ContentFactory::new()->under(12)->storagePage());
    }

    #[Test]
    public function underTakesAPageFactory(): void
    {
        $page = PageFactory::new();

        self::assertSame($page, ContentFactory::new()->under($page)->storagePage());
    }

    #[Test]
    public function underRejectsARecordThatIsNoPage(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1795600001);

        ContentFactory::new()->under(ChildFactory::titled('No page'));
    }

    #[Test]
    public function inheritsThePageOfTheReferencingRecord(): void
    {
        $content = ContentFactory::new();
        $content->inheritPage(12);

        self::assertSame(12, $content->storagePage());
    }

    #[Test]
    public function ownPageWinsOverAnInheritedOne(): void
    {
        $content = ContentFactory::new()->under(5);
        $content->inheritPage(12);

        self::assertSame(5, $content->storagePage());
    }

    #[Test]
    public function hasNoSuggestedUidByDefault(): void
    {
        self::assertNull(ContentFactory::new()->suggestedUid());
    }

    #[Test]
    public function withUidSuggestsTheUid(): void
    {
        self::assertSame(42, ContentFactory::new()->withUid(42)->suggestedUid());
    }

    #[Test]
    public function hasNoAttachedRecords(): void
    {
        self::assertSame([], ContentFactory::new()->attachedRecords());
    }

    #[Test]
    public function isNotPersistedAtFirst(): void
    {
        self::assertFalse(ContentFactory::new()->isPersisted());
    }

    #[Test]
    public function uidOfARecordThatIsNotPersistedIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1763560001);

        ContentFactory::new()->uid();
    }

    #[Test]
    public function markPersistedMakesItTheHandleOfTheRecord(): void
    {
        $content = ContentFactory::new();
        $content->markPersisted(7);

        self::assertTrue($content->isPersisted());
        self::assertSame(7, $content->uid());
    }

    #[Test]
    public function createDoesNothingForAPersistedRecord(): void
    {
        $content = ContentFactory::new();
        $content->markPersisted(7);

        self::assertSame($content, $content->create());
        self::assertSame(7, $content->uid());
    }

    /**
     * @return iterable<string, array{\Closure(ContentFactory): mixed}>
     */
    public static function changesOfAPersistedRecord(): iterable
    {
        yield 'set' => [static fn(ContentFactory $content): mixed => $content->set('header', 'Changed')];
        yield 'under' => [static fn(ContentFactory $content): mixed => $content->under(3)];
        yield 'withUid' => [static fn(ContentFactory $content): mixed => $content->withUid(3)];
    }

    /**
     * @param \Closure(ContentFactory): mixed $change
     */
    #[Test]
    #[DataProvider('changesOfAPersistedRecord')]
    public function persistedRecordCannotBeChangedThroughItsSetters(\Closure $change): void
    {
        $content = ContentFactory::new();
        $content->markPersisted(7);

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1763560003);

        $change($content);
    }
}
