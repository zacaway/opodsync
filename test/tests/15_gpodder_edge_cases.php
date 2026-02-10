<?php

use KD2\HTTP;
use KD2\Test;

// Unauthenticated API request → 401
$unauth = new HTTP;
$unauth->url_prefix = $url;
$unauth->http_options['timeout'] = 2;

$r = $unauth->GET('/api/2/devices/demo.json');
Test::equals(401, $r->status, 'unauthenticated request returns 401');

// Malformed JSON body → 400
$fp = fopen('php://temp', 'r+');
fwrite($fp, 'this is not json');
fseek($fp, 0);
$r = $http->PUT('/api/2/subscriptions/demo/test-device.json', $fp);
fclose($fp);
Test::equals(400, $r->status, 'malformed JSON returns 400');

// POST to devices with no device ID → 400
$r = $http->POST('/api/2/devices/demo.json', ['caption' => 'test'], HTTP::JSON);
Test::equals(400, $r->status, 'missing device ID returns 400');

// Unknown API path → 404
$r = $http->GET('/api/2/nonexistent/demo.json');
Test::equals(404, $r->status, 'unknown API path returns 404');

// Episodes with since parameter filtering
// First, post an episode action with a known timestamp
$r = $http->GET('/api/2/episodes/demo.json?since=0');
Test::equals(200, $r->status, $r);
$data = json_decode($r->body);
Test::assert(is_object($data), 'episodes response is object');
Test::assert(isset($data->actions), 'has actions array');
Test::assert(isset($data->timestamp), 'has timestamp');
$ts = $data->timestamp;

// With future since, should get no actions
$r = $http->GET('/api/2/episodes/demo.json?since=' . ($ts + 1));
Test::equals(200, $r->status, $r);
$data = json_decode($r->body);
Test::assert(count($data->actions) === 0, 'no actions after future timestamp');
