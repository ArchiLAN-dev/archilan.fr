<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Remove stale SQLite test DB so each test run starts from a clean slate.
$testDb = dirname(__DIR__).'/var/test.db';
if (file_exists($testDb)) {
    unlink($testDb);
}
