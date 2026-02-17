<?php

use KD2\Test;
use OPodSync\Feed;

$feed = new Feed('http://example.com/feed.xml');

// --- getTagValue ---

// Basic extraction
Test::strictlyEquals('Hello', $feed->getTagValue('<title>Hello</title>', 'title'));

// CDATA content
Test::strictlyEquals('CDATA content', $feed->getTagValue('<title><![CDATA[CDATA content]]></title>', 'title'));

// HTML entities
Test::strictlyEquals('Rock & Roll', $feed->getTagValue('<title>Rock &amp; Roll</title>', 'title'));

// Nested sub-tag extraction
Test::strictlyEquals('http://example.com', $feed->getTagValue('<image><url>http://example.com</url></image>', 'image', 'url'));

// Empty tag returns null
Test::strictlyEquals(null, $feed->getTagValue('<title></title>', 'title'));

// Whitespace-only returns null
Test::strictlyEquals(null, $feed->getTagValue('<title>   </title>', 'title'));

// Missing tag returns null
Test::strictlyEquals(null, $feed->getTagValue('<body>no title here</body>', 'title'));

// Tag with attributes
Test::strictlyEquals('Content', $feed->getTagValue('<title lang="en">Content</title>', 'title'));

// Multiline content
Test::strictlyEquals("Line 1\nLine 2", $feed->getTagValue("<description>Line 1\nLine 2</description>", 'description'));

// --- getTagAttribute ---

// URL extraction from double-quoted attribute
Test::strictlyEquals('http://example.com/ep.mp3', $feed->getTagAttribute('<enclosure url="http://example.com/ep.mp3" type="audio/mpeg" />', 'enclosure', 'url'));

// Single-quoted attribute
Test::strictlyEquals('http://example.com/ep.mp3', $feed->getTagAttribute("<enclosure url='http://example.com/ep.mp3' />", 'enclosure', 'url'));

// Missing attribute returns null
Test::strictlyEquals(null, $feed->getTagAttribute('<enclosure type="audio/mpeg" />', 'enclosure', 'url'));

// Missing tag returns null
Test::strictlyEquals(null, $feed->getTagAttribute('<item>nothing</item>', 'enclosure', 'url'));

// itunes:image href
Test::strictlyEquals('http://example.com/img.jpg', $feed->getTagAttribute('<itunes:image href="http://example.com/img.jpg" />', 'itunes:image', 'href'));

// URL-encoded attribute value is decoded
Test::strictlyEquals('http://example.com/path with spaces', $feed->getTagAttribute('<enclosure url="http://example.com/path%20with%20spaces" />', 'enclosure', 'url'));

// --- getDuration ---
// getDuration is protected, use Test::invoke

// null input
Test::strictlyEquals(null, Test::invoke($feed, 'getDuration', [null]));

// Empty string
Test::strictlyEquals(null, Test::invoke($feed, 'getDuration', ['']));

// Integer format (seconds) — above threshold
Test::strictlyEquals(3600, Test::invoke($feed, 'getDuration', ['3600']));

// Integer format — below 20s threshold returns null
Test::strictlyEquals(null, Test::invoke($feed, 'getDuration', ['15']));

// Exactly 20 returns null (threshold is <= 20)
Test::strictlyEquals(null, Test::invoke($feed, 'getDuration', ['20']));

// 21 seconds passes
Test::strictlyEquals(21, Test::invoke($feed, 'getDuration', ['21']));

// MM:SS format
// "1:30" = 1 min 30 sec = 90 seconds
Test::strictlyEquals(90, Test::invoke($feed, 'getDuration', ['1:30']));

// HH:MM:SS format
// "1:30:00" = 1 hour 30 min = 5400 seconds
Test::strictlyEquals(5400, Test::invoke($feed, 'getDuration', ['1:30:00']));

// Short colon format: "0:10" = 10 seconds, under threshold → null
Test::strictlyEquals(null, Test::invoke($feed, 'getDuration', ['0:10']));
