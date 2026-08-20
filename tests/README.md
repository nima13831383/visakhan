# Didar integration tests

The workflow and form-definition test files are designed for the standard WordPress PHPUnit integration test suite. Load the Didar plugin in the suite bootstrap, then run:

```text
phpunit tests
```

The repository does not currently bundle WordPress test-library dependencies or a PHPUnit configuration, so the suite must be run from an existing WordPress test environment.
