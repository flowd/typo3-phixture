<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Functional\Factory;

use Flowd\Typo3Phixture\Factory\FileFactory;
use Flowd\Typo3Phixture\Factory\FileReferenceFactory;
use Flowd\Typo3Phixture\Factory\ImageFactory;
use Flowd\Typo3Phixture\Tests\Fixtures\Factory\ItemFactory;
use Flowd\Typo3Phixture\Tests\Functional\FixtureTestCase;
use PHPUnit\Framework\Attributes\Test;

final class FileReferenceFactoryTest extends FixtureTestCase
{
    private const string DOCUMENT = __DIR__ . '/../../Fixtures/Files/document.txt';

    #[Test]
    public function placeholderImageIsAddedToTheStorageAndReferenced(): void
    {
        $image = ImageFactory::placeholder(40, 30)->alternative('Alternative')->title('Title')->description('Description');

        $item = ItemFactory::new()->under(1)->image($image)->create();

        self::assertSame(1, (int)$item->get('image'));
        self::assertSame('tx_phixturefixture_item', $image->get('tablenames'));
        self::assertSame('image', $image->get('fieldname'));
        self::assertSame($item->uid(), (int)$image->get('uid_foreign'));
        self::assertSame('Alternative', $image->get('alternative'));
        self::assertSame('Title', $image->get('title'));
        self::assertSame('Description', $image->get('description'));

        $file = $this->fileOf($image);
        self::assertSame('/phixture/placeholder-40x30.png', $file['identifier']);
        self::assertSame('image/png', $file['mime_type']);
        self::assertSame([40, 30], $this->dimensionsOf($file));
    }

    #[Test]
    public function referencesKeepTheOrderOfTheTest(): void
    {
        $first = ImageFactory::placeholder(10, 10);
        $second = ImageFactory::placeholder(20, 20);

        $item = ItemFactory::new()->under(1)->image($first, $second)->create();

        $references = $this->rows('sys_file_reference', 'sorting_foreign');
        self::assertSame(2, (int)$item->get('image'));
        self::assertSame([$first->uid(), $second->uid()], array_map(intval(...), array_column($references, 'uid')));
    }

    #[Test]
    public function sameImageIsTheSameFile(): void
    {
        $first = ImageFactory::placeholder(40, 30);
        $second = ImageFactory::placeholder(40, 30);

        ItemFactory::new()->under(1)->image($first, $second)->create();

        self::assertSame((int)$first->get('uid_local'), (int)$second->get('uid_local'));
        self::assertCount(1, $this->rows('sys_file'));
    }

    #[Test]
    public function folderOfTheFileCanBeChosen(): void
    {
        $inFolder = ImageFactory::placeholder(10, 10)->inFolder('/products/');
        $inRoot = ImageFactory::placeholder(20, 20)->inFolder('');

        ItemFactory::new()->under(1)->image($inFolder, $inRoot)->create();

        self::assertSame('/products/placeholder-10x10.png', $this->fileOf($inFolder)['identifier']);
        self::assertSame('/placeholder-20x20.png', $this->fileOf($inRoot)['identifier']);
    }

    #[Test]
    public function metadataOfTheFileIsWritten(): void
    {
        $image = ImageFactory::placeholder(40, 30)->fileMetadata(['title' => 'Title of the file', 'alternative' => 'Alternative of the file']);

        ItemFactory::new()->under(1)->image($image)->create();

        $metadata = $this->rows('sys_file_metadata');
        self::assertCount(1, $metadata);
        self::assertSame('Title of the file', $metadata[0]['title']);
        self::assertSame('Alternative of the file', $metadata[0]['alternative']);
        self::assertSame((int)$image->get('uid_local'), (int)$metadata[0]['file']);
    }

    #[Test]
    public function sameFileCanGetTheSameMetadataTwice(): void
    {
        $first = ImageFactory::placeholder(40, 30)->fileMetadata(['alternative' => 'Cat']);
        $second = ImageFactory::placeholder(40, 30)->fileMetadata(['alternative' => 'Cat', 'title' => 'A cat']);

        ItemFactory::new()->under(1)->image($first, $second)->create();

        $metadata = $this->rows('sys_file_metadata');
        self::assertCount(1, $metadata);
        self::assertSame('Cat', $metadata[0]['alternative']);
        self::assertSame('A cat', $metadata[0]['title']);
    }

    #[Test]
    public function sameFileCannotGetOtherMetadata(): void
    {
        $first = ImageFactory::placeholder(40, 30)->fileMetadata(['alternative' => 'Cat']);
        $second = ImageFactory::placeholder(40, 30)->fileMetadata(['alternative' => 'Dog']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1795600004);

        ItemFactory::new()->under(1)->image($first, $second)->create();
    }

    #[Test]
    public function sameNameCannotStandForAnotherFile(): void
    {
        ItemFactory::new()->under(1)->document(FileFactory::fromPath(self::DOCUMENT, 'terms.txt'))->create();

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1795600003);

        ItemFactory::new()->under(1)->document(FileFactory::fromPath(__FILE__, 'terms.txt'))->create();
    }

    #[Test]
    public function temporaryFileOfAnImportIsRemoved(): void
    {
        ItemFactory::new()->under(1)->image(ImageFactory::placeholder(40, 30))->create();
        try {
            ItemFactory::new()->under(1)->document(FileFactory::fromPath(self::DOCUMENT, 'placeholder-40x30.png'))->create();
            self::fail('Expected the import of another file with the same name to fail.');
        } catch (\LogicException) {
        }

        self::assertSame([], glob($this->instancePath . '/typo3temp/var/transient/*-placeholder-40x30.png'));
    }

    #[Test]
    public function fileOfTheProjectIsCopiedIntoTheStorage(): void
    {
        $document = FileFactory::fromPath(self::DOCUMENT);

        $item = ItemFactory::new()->under(1)->document($document)->create();

        self::assertSame(1, (int)$item->get('document'));
        self::assertSame('/phixture/document.txt', $this->fileOf($document)['identifier']);
        self::assertFileExists(self::DOCUMENT);
        self::assertStringEqualsFile($this->instancePath . '/fileadmin/phixture/document.txt', (string)file_get_contents(self::DOCUMENT));
    }

    #[Test]
    public function fileOfTheProjectCanGetAnotherName(): void
    {
        $document = FileFactory::fromPath(self::DOCUMENT, 'terms.txt');

        ItemFactory::new()->under(1)->document($document)->create();

        self::assertSame('/phixture/terms.txt', $this->fileOf($document)['identifier']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fileOf(FileReferenceFactory $reference): array
    {
        foreach ($this->rows('sys_file') as $file) {
            if ((int)$file['uid'] === (int)$reference->get('uid_local')) {
                return $file;
            }
        }
        self::fail('The reference points to no file.');
    }

    /**
     * @param array<string, mixed> $file
     * @return array{int, int}
     */
    private function dimensionsOf(array $file): array
    {
        foreach ($this->rows('sys_file_metadata') as $metadata) {
            if ((int)$metadata['file'] === (int)$file['uid']) {
                return [(int)$metadata['width'], (int)$metadata['height']];
            }
        }
        self::fail('The file has no metadata.');
    }
}
