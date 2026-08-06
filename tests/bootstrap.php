<?php

$panelAutoload = dirname(__DIR__, 3).'/vendor/autoload.php';

if (!is_file($panelAutoload)) {
    throw new RuntimeException('Pelican Panel vendor/autoload.php was not found. Run tests from a plugin copied into a Panel checkout.');
}

$loader = require $panelAutoload;
$loader->addPsr4('Kazaminosuke\\ModManager\\', dirname(__DIR__).'/src/');
