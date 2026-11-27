<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Fixtures\Factory;

use Flowd\Typo3Phixture\Factory\RecordFactory;

final class ContentFactory extends RecordFactory
{
    protected const string TABLE = 'tt_content';

    #[\Override]
    protected function defaults(): array
    {
        return ['CType' => 'text', 'colPos' => 0, 'header' => 'Content'];
    }

    public function header(string $header): static
    {
        return $this->set('header', $header);
    }

    public function bodytext(string $bodytext): static
    {
        return $this->set('bodytext', $bodytext);
    }
}
