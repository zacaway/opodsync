<?php

use KD2\Test;
use KD2\HTTP;
use KD2\HTMLDocument;

$kd2_path = __DIR__ . '/../../kd2fw/src/lib/KD2';

require $kd2_path . '/Test.php';
require $kd2_path . '/HTTP.php';
require $kd2_path . '/HTMLDocument.php';

echo "=== Integration Tests ===\n\n";

$server = 'localhost:8099';
$url = 'http://' . $server;

$data_root = __DIR__ . '/data';

if (file_exists($data_root)) {
	passthru('rm -rf ' . escapeshellarg($data_root));
}

mkdir($data_root);
file_put_contents($data_root . '/config.local.php', "<?php namespace OPodSync;\nconst ENABLE_SUBSCRIPTIONS = true;\n");

$root = realpath(__DIR__ . '/../server');

$server_log = $data_root . '/server.log';

$cmd = sprintf(
	'DATA_ROOT=%s php -S %s -d variables_order=EGPCS -t %s %s > %s 2>&1 & echo $!',
	escapeshellarg($data_root),
	escapeshellarg($server),
	escapeshellarg($root),
	escapeshellarg($root . '/index.php'),
	escapeshellarg($server_log)
);

$pid = trim(shell_exec($cmd));

// Wait for server to be ready (up to 5 seconds)
$ready = false;
for ($i = 0; $i < 50; $i++) {
	$fp = @fsockopen('localhost', 8099, $errno, $errstr, 0.1);
	if ($fp) {
		fclose($fp);
		$ready = true;
		break;
	}
	usleep(100000); // 100ms
}

if (!$ready) {
	echo "ERROR: Server failed to start (PID: $pid)\n";
	if (file_exists($server_log)) {
		echo file_get_contents($server_log);
	}
	exit(1);
}

declare(ticks = 1);

pcntl_signal(SIGINT, function() use ($pid) {
	shell_exec('kill ' . $pid);
	exit;
});

$http = new HTTP;
$http->url_prefix = $url;
$http->http_options['timeout'] = 2;
$list = glob(__DIR__ . '/tests/*.php');
natcasesort($list);

$passed = 0;
$failed = 0;

try {
	foreach ($list as $file) {
		$name = basename($file, '.php');
		echo "  [$name] ";

		try {
			require $file;
			echo "OK\n";
			$passed++;
		}
		catch (\Exception $e) {
			echo "FAIL\n";
			echo "    " . $e->getMessage() . "\n";
			echo "    at " . $e->getFile() . ':' . $e->getLine() . "\n";
			$failed++;
		}
	}
}
finally {
	shell_exec('kill ' . $pid);
}

echo "\nIntegration tests: $passed passed, $failed failed\n";

if ($failed > 0) {
	exit(1);
}

function dom(string $html) {
	$doc = new HTMLDocument;
	$doc->loadHTML($html);
	return $doc;
}
