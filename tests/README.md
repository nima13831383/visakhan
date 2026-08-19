# Didar workflow tests

`test-didar-workflow.php` is designed for the standard WordPress PHPUnit integration test suite. Load the Didar plugin in the suite bootstrap, then run:

```text
phpunit tests/test-didar-workflow.php
```

The repository does not currently bundle WordPress test-library dependencies or a PHPUnit configuration, so the suite must be run from an existing WordPress test environment.
