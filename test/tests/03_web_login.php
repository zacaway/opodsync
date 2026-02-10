<?php

use KD2\Test;
use KD2\HTTP;

// Helper: fetch login page and extract CSRF token
function getLoginCSRF($http) {
	$r = $http->GET('/login.php');
	Test::equals(200, $r->status, 'login page loads');

	$doc = dom($r->body);
	$csrf = $doc->querySelector('input[name="csrf_token"]');
	return $csrf->getAttribute('value');
}

// Reset HTTP state for fresh session
$http = new HTTP;
$http->url_prefix = $url;
$http->http_options['timeout'] = 2;

// Wrong password → error
$csrf = getLoginCSRF($http);
$r = $http->POST('/login.php', [
	'login' => 'demo',
	'password' => 'wrongpassword',
	'csrf_token' => $csrf,
]);
Test::equals(401, $r->status, 'wrong password returns 401');
Test::assert(str_contains($r->body, 'Invalid username/password'), 'wrong password error message');

// Nonexistent user → error
$csrf = getLoginCSRF($http);
$r = $http->POST('/login.php', [
	'login' => 'nonexistent_user',
	'password' => 'somepassword',
	'csrf_token' => $csrf,
]);
Test::equals(401, $r->status, 'nonexistent user returns 401');
Test::assert(str_contains($r->body, 'Invalid username/password'), 'nonexistent user error message');

// Missing CSRF → error
$r = $http->POST('/login.php', [
	'login' => 'demo',
	'password' => 'demodemo',
]);
Test::equals(200, $r->status);
Test::assert(str_contains($r->body, 'Invalid form token'), 'missing CSRF token error');

// Successful login with CSRF (follows redirect to index)
$csrf = getLoginCSRF($http);
$r = $http->POST('/login.php', [
	'login' => 'demo',
	'password' => 'demodemo',
	'csrf_token' => $csrf,
]);
Test::equals(200, $r->status, 'successful login returns 200 after redirect');
Test::assert(str_contains($r->body, 'Logged in as demo'), 'shows logged-in page');
