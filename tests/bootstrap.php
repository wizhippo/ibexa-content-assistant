<?php

declare(strict_types=1);

// A standalone checkout uses its own dependencies. A consuming Ibexa application
// can supply its normal test bootstrap when running this bundle's integration suite.
$bootstrap = getenv('WIZHIPPO_TEST_BOOTSTRAP');
require $bootstrap !== false && $bootstrap !== '' ? $bootstrap : dirname(__DIR__).'/vendor/autoload.php';
