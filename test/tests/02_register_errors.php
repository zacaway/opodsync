<?php

use KD2\Test;

// Helper: fetch register page and extract CSRF token + CAPTCHA code
function getRegisterForm($http) {
	$r = $http->GET('/register.php');
	Test::equals(200, $r->status, 'register page loads');

	$doc = dom($r->body);
	$csrf = $doc->querySelector('input[name="csrf_token"]');
	$codes = $doc->querySelectorAll('label[for="captcha"] i');

	$code = '';
	foreach ($codes as $c) {
		$code .= $c->textContent;
	}

	return [
		'csrf_token' => $csrf->getAttribute('value'),
		'captcha' => $code,
	];
}

// Duplicate username (user "demo" was created in 01_register)
$form_data = getRegisterForm($http);
$form = [
	'login' => 'demo',
	'password' => 'newpassword123',
	'captcha' => $form_data['captcha'],
	'csrf_token' => $form_data['csrf_token'],
];
$r = $http->POST('/register.php', $form);
Test::equals(200, $r->status);
Test::assert(str_contains($r->body, 'Username already exists'), 'duplicate username error');

// Short password
$form_data = getRegisterForm($http);
$form = [
	'login' => 'newuser',
	'password' => 'short',
	'captcha' => $form_data['captcha'],
	'csrf_token' => $form_data['csrf_token'],
];
$r = $http->POST('/register.php', $form);
Test::equals(200, $r->status);
Test::assert(str_contains($r->body, 'Password is too short'), 'short password error');

// Invalid username (special chars)
$form_data = getRegisterForm($http);
$form = [
	'login' => 'bad user!@#',
	'password' => 'validpassword',
	'captcha' => $form_data['captcha'],
	'csrf_token' => $form_data['csrf_token'],
];
$r = $http->POST('/register.php', $form);
Test::equals(200, $r->status);
Test::assert(str_contains($r->body, 'Invalid username'), 'invalid username error');

// Wrong CAPTCHA — increment each digit by 1 to guarantee it differs
$form_data = getRegisterForm($http);
$wrong_captcha = '';
for ($i = 0; $i < strlen($form_data['captcha']); $i++) {
	$wrong_captcha .= (string)(((int)$form_data['captcha'][$i] + 1) % 10);
}
$form = [
	'login' => 'newuser2',
	'password' => 'validpassword',
	'captcha' => $wrong_captcha,
	'csrf_token' => $form_data['csrf_token'],
];
$r = $http->POST('/register.php', $form);
Test::equals(200, $r->status);
Test::assert(str_contains($r->body, 'Invalid captcha'), 'wrong captcha error');

// Missing CSRF token
$form_data = getRegisterForm($http);
$form = [
	'login' => 'newuser3',
	'password' => 'validpassword',
	'captcha' => $form_data['captcha'],
];
$r = $http->POST('/register.php', $form);
Test::equals(200, $r->status);
Test::assert(str_contains($r->body, 'Invalid form token'), 'missing CSRF token error');

// Invalid CSRF token
$form_data = getRegisterForm($http);
$form = [
	'login' => 'newuser4',
	'password' => 'validpassword',
	'captcha' => $form_data['captcha'],
	'csrf_token' => 'totally-invalid-token',
];
$r = $http->POST('/register.php', $form);
Test::equals(200, $r->status);
Test::assert(str_contains($r->body, 'Invalid form token'), 'invalid CSRF token error');
