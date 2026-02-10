<?php

use KD2\Test;
use OPodSync\Utils;

// Plain text passthrough (gets nl2br applied)
$result = Utils::format_description('Hello world');
Test::assert(str_contains($result, 'Hello world'), 'Plain text preserved');

// HTML tag stripping
$result = Utils::format_description('<p>Hello</p><p>World</p>');
Test::assert(!str_contains($result, '<p>'), 'p tags stripped');
Test::assert(str_contains($result, 'Hello'), 'Text preserved after stripping');
Test::assert(str_contains($result, 'World'), 'Text preserved after stripping');

// Link conversion to markdown then back to anchor
$result = Utils::format_description('<a href="http://example.com">Example</a>');
Test::assert(str_contains($result, '<a href="http://example.com">Example</a>'), 'Link converted to anchor tag');

// Link where text matches URL — kept as plain URL then auto-linked
$result = Utils::format_description('<a href="http://example.com">http://example.com</a>');
Test::assert(str_contains($result, 'http://example.com'), 'URL preserved');

// Script tag stripping
$result = Utils::format_description('<script>alert("xss")</script>Safe text');
Test::assert(!str_contains($result, '<script>'), 'Script tags removed');
Test::assert(!str_contains($result, 'alert'), 'Script content removed');
Test::assert(str_contains($result, 'Safe text'), 'Safe text preserved');

// Newline normalization (3+ newlines collapsed to 2)
$result = Utils::format_description("Line1\n\n\n\nLine2");
// After nl2br, should not have excessive breaks
Test::assert(str_contains($result, 'Line1'), 'Line1 present');
Test::assert(str_contains($result, 'Line2'), 'Line2 present');

// Bare URL auto-linking
$result = Utils::format_description('Visit http://example.com/page for info');
Test::assert(str_contains($result, '<a href="http://example.com/page">http://example.com/page</a>'), 'Bare URL auto-linked');

// HTML entities are escaped
$result = Utils::format_description('Use <b>bold</b> & "quotes"');
Test::assert(str_contains($result, '&amp;'), 'Ampersand escaped');
Test::assert(str_contains($result, '&quot;'), 'Quotes escaped');
Test::assert(!str_contains($result, '<b>'), 'Bold tags stripped');
