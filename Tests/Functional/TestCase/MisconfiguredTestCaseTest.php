<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Functional\TestCase;

use Flowd\Typo3Phixture\TestCase\DomTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Mistakes in a test class are reported before an instance is built.
 */
final class MisconfiguredTestCaseTest extends TestCase
{
    #[Test]
    public function testCaseWithoutSitesIsRejected(): void
    {
        $testCase = new class ('setUpForTest') extends DomTestCase {
            public function setUpForTest(): void
            {
                $this->setUp();
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1763560401);

        $testCase->setUpForTest();
    }

    #[Test]
    public function siteWithoutConfigurationInTheProjectIsRejected(): void
    {
        $testCase = new class ('setUpForTest') extends DomTestCase {
            protected array $sites = ['unknown'];

            public function setUpForTest(): void
            {
                $this->setUp();
            }

            #[\Override]
            protected function projectSitesPath(): string
            {
                return dirname(__DIR__, 2) . '/Fixtures/Sites';
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1763560403);

        $testCase->setUpForTest();
    }
}
