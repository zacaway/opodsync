<?php

use KD2\Test;

$kd2_path = __DIR__ . '/../../kd2fw/src/lib/KD2';

require $kd2_path . '/Test.php';

// Autoloader for OPodSync classes (no HTTP server needed)
spl_autoload_register(function ($class) {
	$class = str_replace('\\', '/', $class);
	$path = __DIR__ . '/../server/lib/' . $class . '.php';
	if (file_exists($path)) {
		require_once $path;
	}
});

echo "=== Unit Tests ===\n\n";

$list = glob(__DIR__ . '/unit/*.php');
natcasesort($list);

$passed = 0;
$failed = 0;

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

echo "\nUnit tests: $passed passed, $failed failed\n";

if ($failed > 0) {
	exit(1);
}
