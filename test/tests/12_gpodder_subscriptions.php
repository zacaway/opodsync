<?php

use KD2\HTTP;
use KD2\Test;

$fp1 = fopen('php://temp', 'r+');
fwrite($fp1, 'invalid');
fseek($fp1, 0);

$r = $http->PUT('/subscriptions/demo/test-device.opml', $fp1);
Test::equals(501, $r->status, $r);
fclose($fp1);

$fp2 = fopen('php://temp', 'r+');
fwrite($fp2, 'invalid');
fseek($fp2, 0);

$r = $http->PUT('/subscriptions/demo/test-device.json', $fp2);
Test::equals(400, $r->status, $r);
fclose($fp2);

$r = $http->PUT('/subscriptions/demo/test-device.json', __DIR__ . '/../subscriptions.json');
Test::equals(200, $r->status, $r);

$r = $http->GET('/subscriptions/demo/test-device.opml');

Test::equals(200, $r->status, $r);
Test::assert(str_contains($r->body, 'xmlUrl="https://april.org/lav.xml"'));

