# Didar integration tests

The workflow and form-definition test files are designed for the standard WordPress PHPUnit integration test suite. Load the Didar plugin in the suite bootstrap, then run:

```text
phpunit tests
```

The repository does not currently bundle WordPress test-library dependencies or a PHPUnit configuration, so the suite must be run from an existing WordPress test environment.

`test-didar-form-definitions.php` covers the private Didar file-record contract, max-file validation, owner forgery rejection, absence of Media Library attachment creation in the record flow, Secure-by-default resolution, and Secure/Direct URL switching without submission rewrites.

`test-didar-frontend-search.php` covers the shared admin/frontend request search clause, request ID and identifying-field matching, author ownership scoping, combined frontend search/type filters, fixed shortcode-type precedence, invalid type handling, pagination state, multiple-list query suffixes, `search`/`filter` UI attributes, and the submission-list first/last-name columns with Visa and legacy combined-name fallbacks.

`test-didar-schema-manager.php` covers the centralized custom-table registry, recreation of deleted file/event tables during a normal plugin health check, and periodic repair of incomplete table structures.
