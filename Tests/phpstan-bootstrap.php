<?php

declare(strict_types=1);

/*
 * For PHPStan only: typo3/testing-framework defines this constant in the bootstrap of the functional tests,
 * with the path of the web root of the project and a trailing slash.
 */
if (!defined('ORIGINAL_ROOT')) {
    define('ORIGINAL_ROOT', dirname(__DIR__) . '/.Build/public/');
}
