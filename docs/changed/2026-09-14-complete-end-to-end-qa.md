# ns-didar Complete End-to-End QA

Audit date: 2026-09-14. This was a read-only audit of the current working tree. No implementation files were changed and no live Didar/API calls or new WordPress records were made.

## Executive Summary

Overall: **BLOCKED**

The local plugin source, registry, renderers, validation paths, settings-transfer logic, scheduled workers, and available JavaScript lifecycle tests passed deterministic checks. Full end-to-end verification is blocked because the local Didar API key is absent and the actual form pages require an authenticated browser session that could not be established. A browser fixture also reproduced a visible dropdown/time-picker color mismatch, which is recorded as BUG-02.

## Environment

- WordPress: 6.9.4
- Plugin version: runtime `1.7.4`; plugin header reports `1.7.3` (BUG-01)
- Git HEAD: `04640427a801e6cd87f1d316e4c07eefa6f71e49`
- Working tree: pre-existing dirty changes in 13 tracked implementation/test files and 2 untracked files; preserved unchanged
- Didar connectivity: blocked; local `didar_api_key` is absent, and no API request was executed
- Browser inspection: partial; actual public page loaded, but protected form DOM was unavailable without authentication; synthetic headless Chrome DOM/CSS checks were run

## Test Accounts / QA Marker

No credentials or passwords are included in this report. No controlled QA marker records were created. The requested marker count before the audit was zero.

## Form Matrix

| Form | Render | Submit | WP | Person | Deal | Main Case | Companion Cases | Edit/Sync |
|---|---|---|---|---|---|---|---|---|
| consultation | PASS: registry/renderer; browser form blocked | BLOCKED | BLOCKED for new QA record | BLOCKED | BLOCKED | N/A | N/A | SOURCE/SMOKE ONLY |
| complaint_suggestion | PASS: registry/renderer | BLOCKED | BLOCKED for new QA record | BLOCKED | BLOCKED | N/A | N/A | SOURCE/SMOKE ONLY |
| traveler_evaluation | PASS: registry/renderer | BLOCKED | BLOCKED for new QA record | BLOCKED | BLOCKED | N/A | N/A | SOURCE/SMOKE ONLY |
| visa_request | PASS: renderer, validation, conditions, companions | BLOCKED | BLOCKED for new QA record | BLOCKED | BLOCKED | SOURCE PASS; live blocked | SOURCE PASS; live blocked | SOURCE/SMOKE ONLY |
| embassy_appointment | PASS: renderer and validation | BLOCKED | BLOCKED for new QA record | BLOCKED | BLOCKED | BLOCKED: initial stage missing | SOURCE PASS; live blocked | SOURCE/SMOKE ONLY |

## Consultation

The registry and server renderer load all consultation fields, including `preferred_time` and `applicant_note`. The custom time-picker JavaScript test passed opening, hour/minute selection, Persian display, canonical value dispatch, outside-click close, Escape close, ordering, and single-digit labels. The actual authenticated form could not be inspected because the local login session was unavailable.

## Complaint / Suggestion

The registry and renderer load successfully. Its `applicant_note` mapping is retained by the settings-transfer preview/round-trip smoke. Actual submission, storage, and Deal verification were blocked by missing Didar configuration and unavailable authenticated form access.

## Traveler Evaluation

The registry loads 59 fields. All audited country consumers, including `passport_issuer_country`, `main_destination_country`, and `first_entry_country`, contain the canonical Iran option. Actual submission and CRM verification were blocked.

## Visa Request

The registry loads 48 fields. Birth-country default and preservation checks passed: a new field selects `iran`, an existing saved `germany` value remains selected, and a failed-validation `canada` value remains selected. Server-rendered history conditions passed for empty, yes, no, and edit-value cases. Companion field schema and Case mapping source checks passed. Actual submission and CRM verification were blocked.

## Embassy Appointment

The registry loads 27 fields. Birth-country default and validation checks passed with canonical `iran`. The Embassy Case configuration is incomplete because no initial stage is configured, so the source correctly leaves Case synchronization pending rather than inventing an ID. Actual submission and CRM verification were blocked.

## Companions

### Visa

The shared companion architecture is present. `companions_count`, stable `companion_uid`, family relation, numeric age, and independent `age_group` are represented. Existing companion Case reuse and create/update payload paths were verified by source inspection and deterministic smoke checks; no live Case was created or changed.

### Embassy

Embassy uses the same companion architecture and exposes the companion mapping. Live companion Case verification is blocked by the missing Embassy initial stage and absent Didar API key.

## Main Applicant Cases

Visa and Embassy main-applicant Case identity and synchronization paths are present. The stable main UID format is `main_<submission_id>`. Case creation omits `Id` when no existing Case is known, while updates reuse the stored identity. Visa configuration is structurally ready; Embassy is pending because its initial stage is missing. No live Case operation was performed.

## Didar Person

Person lookup/create/update and stored identity paths are present in source. Live Person creation, reuse, and duplicate testing were not executed because the API key is missing.

## Didar Deals

Deal payload and idempotent reuse paths are present in source. Existing stored Deal identity is used for updates and new create payloads do not include an empty `Id`. Live Deal creation, update, and duplicate testing were not executed.

## Didar Cases

Case create/update payload handling, stable identities, existing Visa companion Case lookup/reuse, pending state, and retry scheduling passed source inspection. Visa Case configuration validated as ready. Embassy validation returned incomplete with `stage_missing`. No live Case API calls were made.

## Duplicate Prevention

The source preserves stored Person/Deal/Case identities, uses submission/companion identifiers for Case reuse, avoids recreating existing Visa companion Cases, and omits `Id` from new Case create payloads. Runtime duplicate prevention against Didar could not be verified without live connectivity.

## Files

File/reference/event tables exist and their current schemas are valid. File-service source checks preserve ownership, reference, and authorization paths. Actual authenticated upload/download lifecycle testing was not executed because the form and account were unavailable.

## Profile Documents

The profile document field catalog and profile shortcode wiring are present. Actual profile upload and attachment persistence were blocked by unavailable authenticated browser access and the no-mutation audit boundary.

## Edit / Update Sync

Saved-value preservation, renderer behavior, sync identity reuse, and retry paths passed source or deterministic smoke checks. Actual editing of a stored submission and live update synchronization were not executed.

## Reverse Sync / Webhooks

The webhook handler rejected an unauthenticated empty JSON request with `didar_webhook_unauthorized` and HTTP 401. No live webhook was sent or accepted, and no external record was changed.

## Retry Queue

The recurring sync, user-sync, and temporary-upload cleanup workers are scheduled. Source retry/pending paths are present. A forced live failure and subsequent queue recovery were not executed because Didar connectivity is unavailable.

## History / Workflow

The event table schema is current, workflow configuration errors were zero for all five active forms, and workflow cache structures load. No new controlled submission was created to produce a live history trace.

## Shortcodes

All six supported shortcodes are registered: `didar_form`, `didar_form_access`, `didar_submissions`, `didar_submission_details`, `didar_submission_edit`, and `didar_profile_form`. Logged-out smoke calls returned safe notices or empty output as appropriate.

## PDF

mPDF is available. The current JavaScript download-flow test passed both modes, delegated-handler uniqueness, single-fetch double-click behavior, no invalid HTML downloads, and no page navigation. Actual authenticated PDF generation from a QA submission was blocked.

## Form Access / QR

Form-access and barcode logic are registered and safely guarded. No URL or barcode is configured for the five active forms in the local settings, so access-link/QR end-to-end tests could not be performed.

## Settings Persistence

The actual `Didar_Settings` API loaded the local option as an array with 27 top-level keys. The option was not changed during this audit. Field defaults, mappings, workflows, Case settings, and companion settings were structurally present.

## Export / Import

The official `Didar_Settings_Transfer` parser/preview smoke passed. Applicant-note mappings for all five forms, Case settings, and companion settings were retained; credentials and runtime state were excluded. The apply/write path was not invoked to preserve the local settings during this read-only audit.

## Security

No credentials, tokens, webhook secrets, customer values, or private file contents were printed. Capability checks, ownership-oriented source paths, nonce-protected form actions, and webhook authentication were reviewed. No live API, submission, sync, or destructive operation was executed.

## Configuration Gaps

- Local Didar API key is absent, blocking live Person, Deal, Case, webhook, and synchronization verification.
- Embassy Case configuration has no initial stage, so Embassy main/companion Case synchronization remains safely pending.
- Form access URL/barcode settings are absent for the five active forms, blocking access-link and QR runtime tests.

## Bugs Found

### BUG-01 — Plugin metadata version disagrees with runtime version

- severity: Low
- reproduction: `didar.php` declares plugin header version `1.7.3`, while the runtime `DIDAR_VERSION` constant is `1.7.4`.
- expected: WordPress metadata and runtime asset/version reporting identify the same release.
- actual: The two version sources disagree.
- evidence: Direct source inspection and WordPress bootstrap runtime check.
- likely code area: `didar.php` plugin header and version constant.

### BUG-02 — Browser-computed dropdown/time-picker colors do not match authored brand rules

- severity: Medium
- reproduction: Load the current `assets/css/frontend.css` in local headless Chrome with representative time-picker and searchable-select buttons, then inspect `getComputedStyle()` for closed, open, hover, active, normal-option, and selected-option states.
- expected: Normal controls/options render white; hover/open/active/selected states use the existing brand yellow rules.
- actual: The browser fixture rendered gray/light-neutral backgrounds for controls/options, and the interactive states did not consistently resolve to the declared brand yellow. The source also explicitly leaves selected time options white.
- evidence: Direct computed-style results from Chrome using the current plugin stylesheet; source selectors around the time-picker and searchable-select rules were matched but did not produce the required computed colors in the fixture.
- likely code area: `assets/css/frontend.css`, time-picker trigger/option rules and searchable-select option rules. The exact interaction between the stylesheet cascade and native button rendering needs a focused follow-up.

DO NOT FIX during this audit.

## Controlled QA Records Created

None. No WordPress submission, Didar Deal, Person, or Case was created.

## Tests Not Executed

- Authenticated browser submission for all five forms, because the local login did not establish a session and the protected pages rendered no plugin form DOM.
- Live Person, Deal, main Case, companion Case, update, reuse, duplicate-prevention, retry-recovery, and webhook tests, because the local Didar API key is absent.
- Actual upload/profile-document and generated-PDF QA from a new submission, because authenticated form access was unavailable and this audit made no new records.
- Live form-access URL/barcode and QR tests, because those settings are absent.
- The bundled PHPUnit command, because the available global PHPUnit is incompatible with PHP 8.2 (`each()` fatal) and the plugin has no bundled WordPress test library/configuration.

Unrelated host-plugin notices and warnings observed during WordPress bootstrap were external environment noise and were not attributed to ns-didar.

## Final Assessment

**CODE QUALITY:** PASS WITH ISSUES. The audited source paths are coherent and deterministic tests pass, with the version metadata mismatch and browser color defect recorded above.

**LOCAL FUNCTIONALITY:** PASS WITH ISSUES. Registry, rendering, validation, settings portability, lifecycle JavaScript, schema, shortcode, capability, and scheduling checks passed. Browser visual acceptance has BUG-02.

**LIVE DIDAR INTEGRATION:** BLOCKED. No local API key is configured, so no live request was attempted.

**PRODUCTION READINESS:** BLOCKED FOR ACCEPTANCE. Resolve the local configuration gaps and BUG-02, then rerun authenticated end-to-end and live CRM tests before release.

## Final Status

**BLOCKED — LIVE INTEGRATION COULD NOT BE VERIFIED**
