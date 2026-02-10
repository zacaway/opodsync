.PHONY : server test test-unit test-integration

server:
	php -S localhost:8080 -t server server/index.php

test: test-unit test-integration

test-unit:
	php test/unit.php

test-integration:
	php test/start.php
