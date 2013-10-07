<?php

require __DIR__ . '/boot.php';

use Tester\Assert;
use DataDog\DatadogClient;


$c = new DatadogClient(123, 456);

Assert::same('', $c->serializeTags(array()));

Assert::same('tag1:123,tag2:456,tag3', $c->serializeTags(array('tag1' => 123, 'tag2' => 456, 'tag3' => TRUE,)));

$c->setTags(array('tag2' => 456, 'tag3' => TRUE));
Assert::same('tag1:123,tag2:456,tag3', $c->serializeTags(array('tag1' => 123,)));
$c->setTags(array());

Assert::throws(function () use ($c) {
    $c->serializeTags(array('#tag' => 123));
}, 'DataDog\\DatadogClientException');



Assert::same(array(), $c->preparePackets(array()));

Assert::same(array('stat:1|c'), $c->preparePackets(array('stat' => '1|c')));

Assert::same(array('stat:1|c|#tag:val'), $c->preparePackets(array('stat' => '1|c'), 1, array('tag' => 'val')));

Assert::same(array('stat:123|ms'), $c->timing('stat', 123));

Assert::same(array('stat:123|g'), $c->gauge('stat', 123));

Assert::same(array('stat:123|s'), $c->set('stat', 123));

Assert::same(array('stat:1|c'), $c->increment('stat'));

Assert::same(array('stat:-1|c'), $c->decrement('stat'));

Assert::same(array('a:1|c', 'b:1|c'), $c->updateStats(array('a','b')));
