<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Fixtures\Factory;

use Flowd\Typo3Phixture\Factory\RecordFactory;

/**
 * A factory for a table TCA declares read-only: DataHandler does not write it.
 */
final class TagFactory extends RecordFactory
{
    protected const string TABLE = 'tx_phixturefixture_tag';
}
