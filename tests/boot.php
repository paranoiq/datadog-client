<?php

require __DIR__ . '/../vendor/nette/tester/Tester/bootstrap.php';
require __DIR__ . '/../vendor/autoload.php';

require __DIR__ . '/../src/DatadogClient.php';


Nette\Diagnostics\Debugger::$logDirectory = __DIR__ . '/output';

function d($v)
{
    echo \Tester\Dumper::toPhp($v) . "\n";
}
