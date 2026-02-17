-- Fix for getDuration() bug where colon-format durations had reversed
-- hour/second indices and an operator precedence issue.
--
-- Since the original HH:MM:SS string is not stored, we cannot reverse
-- the calculation reliably. Instead, clear all durations so they are
-- repopulated correctly on the next feed fetch.
--
-- After running this, trigger a feed update:
--   php server/index.php
--
-- Works on both SQLite and MySQL.

UPDATE episodes SET duration = NULL WHERE duration IS NOT NULL;
