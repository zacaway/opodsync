<?php

use KD2\HTTP;
use KD2\Test;

// POST add/remove subscriptions
$r = $http->POST('/api/2/subscriptions/demo/test-device.json', [
	'add' => ['https://example.com/feed1.xml', 'https://example.com/feed2.xml'],
	'remove' => [],
], HTTP::JSON);

Test::equals(200, $r->status, $r);
$data = json_decode($r->body);
Test::assert(is_object($data), 'response is JSON object');
Test::assert(isset($data->timestamp), 'response has timestamp');
$ts_after_add = $data->timestamp;

// GET changes since 0 — should include the feeds we just added
$r = $http->GET('/api/2/subscriptions/demo/test-device.json?since=0');
Test::equals(200, $r->status, $r);
$data = json_decode($r->body);
Test::assert(is_object($data), 'response is JSON object');
Test::assert(is_array($data->add), 'has add list');
Test::assert(in_array('https://example.com/feed1.xml', $data->add), 'feed1 in add list');
Test::assert(in_array('https://example.com/feed2.xml', $data->add), 'feed2 in add list');

// Remove one subscription
$r = $http->POST('/api/2/subscriptions/demo/test-device.json', [
	'add' => [],
	'remove' => ['https://example.com/feed2.xml'],
], HTTP::JSON);
Test::equals(200, $r->status, $r);
$data = json_decode($r->body);
$ts_after_remove = $data->timestamp;

// GET changes since the add timestamp — should show feed2 as removed
$r = $http->GET('/api/2/subscriptions/demo/test-device.json?since=' . $ts_after_add);
Test::equals(200, $r->status, $r);
$data = json_decode($r->body);
Test::assert(in_array('https://example.com/feed2.xml', $data->remove), 'feed2 in remove list');

// GET changes with future timestamp — should return empty lists
$r = $http->GET('/api/2/subscriptions/demo/test-device.json?since=' . ($ts_after_remove + 1));
Test::equals(200, $r->status, $r);
$data = json_decode($r->body);
Test::assert(empty($data->add), 'no adds after future timestamp');
Test::assert(empty($data->remove), 'no removes after future timestamp');
