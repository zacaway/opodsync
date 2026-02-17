---
name: test-all
description: Run unit tests + integration tests for both SQLite and MySQL backends
disable-model-invocation: true
---

Run the full test suite for OPodSync across all backends. Run each step sequentially and report results:

1. **Unit tests**: `nix develop --command php test/unit.php`
2. **SQLite integration tests**: `nix develop --command php test/start.php`
3. **MySQL integration tests**: `nix develop --command bash -c 'dev-mysql-test'`

After all steps complete, print a summary table showing pass/fail status for each suite.
If any suite fails, show the failure details but continue running the remaining suites.
