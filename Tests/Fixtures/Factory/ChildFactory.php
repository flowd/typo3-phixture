<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Fixtures\Factory;

use Flowd\Typo3Phixture\Factory\RecordFactory;

final class ChildFactory extends RecordFactory
{
    protected const string TABLE = 'tx_phixturefixture_child';

    public static function titled(string $title): static
    {
        return self::new()->set('title', $title);
    }
}
