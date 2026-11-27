<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Unit\Factory;

use Flowd\Typo3Phixture\Factory\FileFactory;
use Flowd\Typo3Phixture\Factory\ImageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileFactoryTest extends TestCase
{
    #[Test]
    public function fileThatDoesNotExistIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1763990101);

        FileFactory::fromPath(__DIR__ . '/does-not-exist.pdf');
    }

    #[Test]
    public function directoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1763990101);

        FileFactory::fromPath(__DIR__);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatAreNoFileName(): iterable
    {
        yield 'empty' => [''];
        yield 'parent directory' => ['..'];
        yield 'path' => ['documents/terms.pdf'];
        yield 'path out of the directory' => ['../../terms.pdf'];
        yield 'path with backslashes' => ['..\\terms.pdf'];
    }

    #[Test]
    #[DataProvider('namesThatAreNoFileName')]
    public function nameThatIsNoFileNameIsRejected(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1795600002);

        FileFactory::fromPath(__FILE__, $name);
    }

    #[Test]
    public function isAFileReference(): void
    {
        self::assertSame('sys_file_reference', FileFactory::fromPath(__FILE__)->table());
        self::assertSame('sys_file_reference', ImageFactory::placeholder()->table());
    }

    #[Test]
    public function settersFillTheFieldsOfTheReference(): void
    {
        $image = ImageFactory::placeholder(40, 30)->alternative('Alternative')->title('Title')->description('Description');

        self::assertSame(
            ['alternative' => 'Alternative', 'title' => 'Title', 'description' => 'Description'],
            $image->values(),
        );
    }

    #[Test]
    public function fileMetadataAndFolderAreNoFieldsOfTheReference(): void
    {
        $image = ImageFactory::placeholder()->fileMetadata(['title' => 'Title of the file'])->inFolder('images/');

        self::assertSame([], $image->values());
    }
}
