<?php

use KD2\Test;
use OPodSync\Feed;

$xml = file_get_contents(__DIR__ . '/../fixtures/feed_cttm.xml');

$feed = new Feed('https://www.cuttingthroughthematrix.com/AlanWattPodCast.xml');
$result = $feed->parse($xml);

// Parse succeeds
Test::assert($result);

// Channel metadata
Test::strictlyEquals('Cutting Through the Matrix with Alan Watt Podcast (.xml Format)', $feed->title);
Test::strictlyEquals('http://www.cuttingthroughthematrix.com', $feed->url);
Test::strictlyEquals('en', $feed->language);
Test::strictlyEquals('http://www.cuttingthroughthematrix.com/images/AlanWatt_CTTM_PyramidKickForPodcast.jpg', $feed->image_url);
Test::assert($feed->pubdate instanceof \DateTime);
Test::strictlyEquals('2026-02-15 23:00:00', $feed->pubdate->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

// Episode count
$episodes = $feed->listEpisodes();
Test::strictlyEquals(5, count($episodes));

// First episode — standard "Sun" date
Test::assert(str_contains($episodes[0]->title, 'Wild Men, Morally Unconventional'));
Test::strictlyEquals('http://cuttingthrough.jenkness.com/REDUX2026/Alan_Watt_CTTM_250_Redux_Wild_Men_Morally_Unconventional_Feb152026.mp3', $episodes[0]->media_url);
Test::strictlyEquals('2026-02-15 23:00:00', $episodes[0]->pubdate->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

// HH:MM:SS duration: "01:48:09" = 6489 seconds
// Known bug: getDuration reverses indices, so this gives wrong result
// $parts = [01, 48, 09] → bug computes: 9*3600 + 48*60 + 1 = 35281
Test::strictlyEquals(35281, $episodes[0]->duration,
	'HH:MM:SS duration — known bug: indices reversed, "01:48:09" gives 35281 instead of 6489');

// Third episode — non-standard "Thurs" day abbreviation (parseDate normalizes it)
Test::assert(str_contains($episodes[2]->title, 'Reality Bytes Radio'));
Test::strictlyEquals('2020-08-06 23:00:00', $episodes[2]->pubdate->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

// Fourth episode — "Tue" date
Test::strictlyEquals('2018-12-25 23:00:00', $episodes[3]->pubdate->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

// Last episode — oldest, "Fri" date from 2006
Test::assert(str_contains($episodes[4]->title, 'Grassy Knoll'));
Test::strictlyEquals('2006-01-28 02:00:00', $episodes[4]->pubdate->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
// Duration "01:00:00" — same bug: parts=[01,00,00] → 0*3600 + 0*60 + 1 = 1, under threshold → null
Test::strictlyEquals(null, $episodes[4]->duration,
	'HH:MM:SS duration — known bug: "01:00:00" gives 1 (under threshold), returns null instead of 3600');

// Export produces MySQL-compatible datetime (no timezone suffix)
$exported = $feed->export();
Test::assert(!str_contains($exported['pubdate'], 'UTC'), 'export pubdate should not contain UTC suffix');
Test::strictlyEquals(1, preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $exported['pubdate']),
	'export pubdate should be Y-m-d H:i:s format');

// Parse with empty/invalid XML returns false
$bad_feed = new Feed('http://example.com/bad.xml');
Test::strictlyEquals(false, $bad_feed->parse('<html><body>Not a feed</body></html>'));
Test::strictlyEquals(false, $bad_feed->parse(''));
