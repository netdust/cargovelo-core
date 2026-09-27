<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

DG\BypassFinals::enable();

define('ABSPATH', sys_get_temp_dir() . '/cargovelo-tests/');
define('CARGOVELO_DIR', dirname(__DIR__));
define('CARGOVELO_FILE', dirname(__DIR__) . '/cargovelo-core.php');
define('CARGOVELO_VERSION', 'test');

require_once __DIR__ . '/Stubs/wordpress-stubs.php';
require_once __DIR__ . '/Stubs/ntdst-stubs.php';
require_once __DIR__ . '/TestCase.php';
