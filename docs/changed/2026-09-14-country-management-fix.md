# Country management fix — 2026-09-14

## Result

The current `ns-didar` source did not contain a countries admin page, a country-management option, or an Add Country handler. Form definitions called the 65-entry `Didar_Reference_Data::countries()` array directly, so an Add Country request had no persistence boundary.

The country catalog is now managed from the existing ns-didar submissions submenu through the dedicated `کشورها` page (`didar-countries`). It uses a separate `didar_country_catalog` option and does not alter CRM settings, submissions, user data, or remote Didar state.

## Behavior

- The first catalog read seeds the managed option from the previous built-in catalog. The seed is idempotent and keeps the established keys, including `iran`, `germany`, and `canada`.
- `iran` is permanently kept as `iran => ایران`, remains the default for the existing birth-country fields, and cannot be disabled.
- Keys are immutable after creation. New keys use lowercase ASCII letters, digits, hyphens, and underscores; duplicate keys are rejected.
- Editing changes only the Persian label. Disabling archives a country for new selections without deleting its record or historical value.
- Active countries are returned by `Didar_Country_Catalog::get_countries()`. Disabled countries remain available through the `archived_options` map for edit/detail/readable/PDF rendering.
- Unknown historical values still render with a readable stored-value fallback.

## Consumers audited

The Embassy Appointment, Traveler Evaluation, and Visa Request registry fields now use the managed active catalog. This includes birth country, nationality, passport issuer, destination, first entry, rejection embassy, and previous Schengen country. Searchable and multi-select metadata is preserved. The previous Traveler Evaluation values `iranian` and `foreign` remain recognized as historical legacy values while new nationality selections use the managed country keys.

## Admin security

The page and both admin-post handlers require `didar_manage_settings`. Save and toggle requests use separate WordPress nonces. Labels and keys are sanitized and validated server-side; output is escaped. There is no destructive delete action.

## QA

- PHP lint passed for the changed PHP files.
- Focused WordPress bootstrap QA passed: seed, Iran invariant, add, duplicate rejection, edit, disable/archive, consumer propagation, protected Iran, and cleanup of the temporary `qa_country_test` key. The catalog was restored to 65 active entries.
- The bundled PHPUnit launcher could not run under the installed PHP because its old PHPUnit code calls the removed `each()` function. Regression coverage was still added in `tests/test-didar-country-catalog.php` for a compatible WordPress PHPUnit environment.
- A read-only request to the protected local admin URL returned a redirect, confirming authentication is required.

## Acceptance limitation

The required authenticated browser session was not available to `browser-runtime-inspector`: no connected CDP session was exposed and the available Node environment had no usable Playwright package. No credentials were available, so the Add Country page, authenticated request payload, browser response, console, and frontend browser select could not be verified in a real authenticated browser. Source and bootstrap QA therefore do not replace that final browser acceptance step.
