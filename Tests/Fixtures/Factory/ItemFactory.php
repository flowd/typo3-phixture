<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Fixtures\Factory;

use Flowd\Typo3Phixture\Factory\FileReferenceFactory;
use Flowd\Typo3Phixture\Factory\PageFactory;
use Flowd\Typo3Phixture\Factory\RecordFactory;

final class ItemFactory extends RecordFactory
{
    protected const string TABLE = 'tx_phixturefixture_item';

    #[\Override]
    protected function defaults(): array
    {
        return ['title' => 'Item'];
    }

    public function title(string $title): static
    {
        return $this->set('title', $title);
    }

    public function children(ChildFactory ...$children): static
    {
        return $this->set('children', array_values($children));
    }

    /**
     * Tags are static data of the fixture extension: named by uid.
     */
    public function tags(int ...$tagUids): static
    {
        return $this->set('tags', implode(',', $tagUids));
    }

    public function page(PageFactory $page): static
    {
        return $this->set('page', $page);
    }

    public function related(RecordFactory ...$records): static
    {
        return $this->set('related', array_values($records));
    }

    public function image(FileReferenceFactory ...$images): static
    {
        return $this->set('image', array_values($images));
    }

    public function document(FileReferenceFactory $document): static
    {
        return $this->set('document', $document);
    }
}
