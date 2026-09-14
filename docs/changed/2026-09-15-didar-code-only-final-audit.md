# ns-didar Final Didar Code-Only Audit

Audit date: 2026-09-15

This document audits the current `ns-didar` working tree. It records code paths, local synthetic checks, and current configuration limits. It does not represent a production or remote CRM test.

## Scope

- live Didar calls: NONE / OUT OF SCOPE
- VPN/network: NONE / OUT OF SCOPE
- CRM mutations: NONE / OUT OF SCOPE

No endpoint was contacted, no form was submitted, and no WordPress submission, Person, Deal, or Case was created, changed, or deleted for this audit.

## Form → Deal Coverage

All five active forms enter the same `Didar_Sync_Manager::process_submission()` path. The Registry supplies the form definition, `Didar_Field_Mapper::deal_fields()` supplies configured Deal fields, and `Didar_Workflow_Manager::mapping()` supplies the per-form Pipeline and Stage.

| Form | Person | Deal | Pipeline | Mappings | Duplicate Guard | Result |
|---|---|---|---|---|---|---|
| consultation | WordPress owner Person resolution | one Deal per submission | per-form workflow mapping | Registry + settings mapper | local Deal ID, exact Submission ID lookup, conflict stop | CODE VERIFIED; remote read-back not performed |
| embassy_appointment | WordPress owner Person resolution | one Deal per submission | per-form workflow mapping | Registry + settings mapper | local Deal ID, exact Submission ID lookup, conflict stop | CODE VERIFIED; remote read-back not performed |
| traveler_evaluation | WordPress owner Person resolution | one Deal per submission | per-form workflow mapping | Registry + settings mapper | local Deal ID, exact Submission ID lookup, conflict stop | CODE VERIFIED; remote read-back not performed |
| complaint_suggestion | WordPress owner Person resolution | one Deal per submission | per-form workflow mapping | Registry + settings mapper | local Deal ID, exact Submission ID lookup, conflict stop | CODE VERIFIED; remote read-back not performed |
| visa_request | WordPress owner Person resolution | one Deal per submission | per-form workflow mapping | Registry + settings mapper | local Deal ID, exact Submission ID lookup, conflict stop | CODE VERIFIED; remote read-back not performed |

The Deal create path removes `Id` from a new create payload. Once a remote ID is returned it is persisted before the same Deal receives deferred custom fields. Updates carry the known ID. A failed exact lookup or an ambiguous match stops creation rather than guessing.

## Profile → Person

Profile saves, verified mobile metadata changes, registration, and profile document changes enter the Person-specific generation path. `Didar_Field_Mapper::person_payload()` derives native and custom Person data from the WordPress user/profile source. Request fields are kept as Deal snapshots and are not used to overwrite Person identity. `_didar_person_id` is reused when present; exact mobile lookup and duplicate recovery are used before creation when it is absent.

Profile document mappings use the same Person payload path. A configured document mapping contributes a resolved URL; the document file ID and local filesystem path do not enter the payload.

Result: CODE VERIFIED; focused profile mapping and prefill tests are present, but the PHP integration suite was not executable in the installed PHP 8.2 environment during this audit.

## Visa Cases

Visa Case work is owned by the parent submission generation and starts only after the parent Deal has a durable ID. Companion rows use the shared `Didar_Companion_Model`; stable companion UIDs use `cmp_<uuid>`. The main applicant uses `main_<submission_id>`.

`_didar_companion_cases` stores UID-to-Case links and status. An existing stored Case ID is reused. If it is missing, the code performs an exact lookup scoped by Deal ID, Submission ID, and companion UID when the configured system fields are available. Multiple matches stop creation. A new Case payload omits `Id`; an update includes the known Case ID.

`_didar_main_applicant_case` stores the main UID, Case ID, and status. The main Case follows the same exact lookup, create/update, response persistence, and pending retry behavior. Case configuration is read from the per-form settings object with the Visa legacy fallback preserved.

Result: CODE VERIFIED; the current handoff records Visa Case configuration as structurally ready. No remote Case read-back was performed.

## Embassy Cases

Embassy uses the same companion and main-applicant Case architecture, with an independent `case_form_settings[embassy_appointment]` configuration. Companion count, family relation, numeric age, age group, UIDs, system mappings, main-applicant mappings, Case payloads, and retry ownership are shared with Visa.

The current local configuration audit reports the Embassy Case pipeline/initial-stage configuration as incomplete. `validate_companion_case_configuration()` therefore leaves Embassy Case work pending and does not invent a Pipeline or Stage ID. The parent Deal can remain synchronized while Case work waits for valid configuration.

Result: CODE VERIFIED; production configuration and remote Case read-back remain unavailable by scope.

## Profile Documents

The centralized catalog contains five image-only profile document keys:

- `national_card_front`
- `national_card_back`
- `passport_main_page`
- `personal_photo`
- `birth_certificate_first_page`

The File Service enforces private record storage, ownership/reference checks, JPG/JPEG/PNG/WebP validation, and a 5 MB per-file limit. AJAX upload and removal are authenticated and nonce-protected. Profile documents synchronize through the existing Person path and do not create Deals or Cases.

Result: CODE VERIFIED; focused profile-document mapping tests are present. No file was uploaded during this audit.

## Upload References

Application files are represented by private File Service records and submission references. Mapping and serialization use readable values or a resolved URL according to the existing mode. Raw filesystem paths and binary contents are excluded from Deal, Person, Case, and PDF payloads.

The expressly approved direct-file behavior is isolated to the configured direct URL paths used by the PDF and Person synchronization features. Secure download authorization remains a separate route. No upload or URL mutation was executed in this audit.

## Generation Semantics

The current source creates a new generation for explicit business actions:

- frontend create/edit and owner/agent request updates
- workflow/status or assignment changes
- profile save and profile-document changes
- user registration and verified Digits mobile metadata writes
- explicit same-value saves

Internal state writes, IDs, locks, retry history, queue inspection, queue purge, and inbound webhook application do not create a new outbound generation. Duplicate business hooks in one request share the request-local generation cache.

Automatic retry callbacks reuse the expected generation. Manual Run Now uses the existing generation as a manual override and does not reset the automatic budget.

## Maximum 3 Automatic Executions

The exact code path is:

1. `Didar_Sync_Manager::begin_submission_generation()` or `begin_user_generation()` creates a UUID with `automatic_attempts = 0`.
2. `process_submission()` / `process_user()` reserves an automatic execution under the object lock; the reservation increments `automatic_attempts`.
3. `fail()` / `fail_user()` schedules another event only while the automatic count is below `MAX_AUTOMATIC_EXECUTIONS`, which is `3`.
4. The third automatic failure becomes `exhausted`; `generation_is_eligible()` rejects further automatic callbacks.
5. Stale callbacks, including repeated callbacks after exhaustion or success, return without another automatic execution.

STOP

The focused generation harness covers three automatic failures, repeated stale callbacks, success termination, manual behavior after exhaustion, duplicate scheduling, and concurrent locks. It could not be launched through the installed PHPUnit runner in this environment; the assertions were also inspected against the current source and are recorded here as source-review evidence for this audit.

## Successful Generation Termination

`success()` and `success_user()` mark the current generation `synced`, clear retry errors and next-run metadata, record the last successful time, and unschedule item-specific events. `generation_is_eligible()` accepts only pending/retry states below three automatic attempts, so a successful generation cannot be re-executed by a stale callback.

## Manual Run Now

The admin queue manager validates a single inventory item, rejects unknown or non-executable Case rows, checks the corresponding object lock, and dispatches only that item through `manual_sync()` or `manual_user_sync()`. Manual failures do not schedule a fourth automatic attempt. Case rows remain visible for diagnosis but have no independent worker.

## Same-Value Explicit Re-Save

An explicit business save calls the normal submission/profile generation entry point and creates a fresh UUID even when the payload fingerprint is unchanged. The fingerprint is an idempotency aid and does not suppress a deliberate second save. Internal meta writes do not use those entry points.

## Internal Save Protection

The source has no generic outbound `save_post` or arbitrary meta-update queue hook. Deal, Person, Case IDs, state, attempts, retry metadata, locks, and event history are written inside centralized sync paths. Those writes therefore do not recursively create a business generation.

## Webhook Echo Prevention

The REST webhook requires the configured route secret, or the explicitly enabled legacy header path, validates JSON and required metadata, applies a rate limit, and records event IDs for duplicate delivery suppression. Deal webhook application sets the static reverse-sync suppression flag while local snapshot fields are written. Person webhook application writes only the supported mapped profile date path. Webhook writes do not call the submission or Person generation entry points.

## Infinite Loop Audit

| Loop Path | Guard | Hard Cap | Evidence | Result |
|---|---|---|---|---|
| WordPress save → Deal → webhook → WordPress | reverse-sync suppression plus no generic save hook | 3 automatic executions per generation | source review; prior local webhook QA documented | CODE VERIFIED; remote trigger not executed |
| Deal/Person ID persistence → queue | IDs are written inside sync manager only | 3 automatic executions | internal-write path review | CODE VERIFIED |
| Case ID persistence → parent sync | Case has no independent queue; parent generation owns it | 3 automatic executions | Case path review and queue tests | CODE VERIFIED |
| retry state/history/lock write → new queue item | only explicit business actions call generation creation | 3 automatic executions | generation source review | CODE VERIFIED |
| duplicate cron event | `(object_id, generation_id)` event arguments plus eligibility check | 3 automatic executions | generation scheduling test source | CODE VERIFIED |
| concurrent callback | atomic option lock and reservation before API work | 3 automatic executions | lock test source | CODE VERIFIED |
| duplicate webhook delivery | persistent `didar_seen_webhooks` ledger | no new generation | webhook handler source | CODE VERIFIED with known ledger gap below |
| queue inspection/purge → execution | inspection is read-only; purge removes state/events only | no new execution | queue manager/purge source and synthetic test source | CODE VERIFIED |

## Queue Manager

`Didar_Sync_Manager::queue_inventory()` normalizes submission, Person, Case, and scheduled retry records into one local diagnostic view. It merges a scheduled event with its durable object row where possible, reports generation and attempt state, and does not execute work. The admin page is under `تشخیص و گزارش` and exposes item-specific Run Now and discard controls behind capability and nonce checks.

## Queue Delete / Purge

`purge_queue()` discovers queued submission/Person/Case state, unschedules item-specific one-time events, deletes retry state, marks queued Case links as `discarded` while retaining Case IDs, and deletes only stale locks. It does not call the API and does not delete submissions, users, Deal IDs, Person IDs, Case IDs, or event history. Recurring worker hooks remain scheduled. The focused purge harness uses synthetic local queue states and checks that discarded items are no longer eligible.

## Legacy Queue Safety

Legacy states without a generation ID are normalized to a stable `legacy_` generation. Known attempt counters are capped at three. A legacy queued state with no reliable counter is conservatively treated as exhausted instead of being replayed with a reset budget. Manual Run Now remains available.

## Concurrency

Submission and Person work use separate option locks with a 120-second TTL. Lock acquisition is atomic through `add_option`; expired locks can be reclaimed. The lock is acquired before attempt reservation and released in `finally` blocks. The source and focused queue/generation test fixtures cover duplicate event scheduling, active-lock rejection, and stale-lock handling.

## Local/Synthetic Tests

### Tests actually executed

- `node --check` for all five plugin JavaScript files: PASS.
- `node tests/test-didar-visa-history-lifecycle.js`: PASS (`all_pass: true`).
- `node tests/test-didar-consultation-time-picker.js`: PASS (`all_pass: true`).
- `C:\xampp\php\php.exe -l` for every PHP file in `includes`: PASS; no syntax errors.
- `git diff --check`: PASS; only existing line-ending normalization warnings were reported.

### Source-review-only assertions for this audit

- PHP Registry, mapping, settings-transfer, form-definition, profile, Case, queue, purge, and generation harnesses were inspected but not executed through PHPUnit.
- The standard `phpunit tests` command cannot start with the installed PHP 8.2 runtime because the bundled legacy PHPUnit runner calls the removed `each()` function.
- No browser visual QA, live WordPress form submission, remote Didar read-back, API call, VPN/network test, or CRM mutation was performed.

The current handoff records prior focused local/synthetic coverage for Registry/Iran defaults, companion Cases, profile documents, settings transfer, Visa history, queue management, purge, and generation semantics. This audit treats those records as documented evidence and does not relabel them as remote verification.

## Known Webhook Gaps

- **BUG-01: unsupported webhook events enter the seen-event ledger before support validation.** The handler calls `seen_webhook()` before checking `webhook_event_key()`. A later supported delivery reusing that event ID would be treated as a duplicate. This was previously observed in local QA and is intentionally documented here, not fixed in this audit.
- **BUG-02: non-array Deal `data.Fields` is acknowledged as an empty field set.** The handler currently normalizes it to an empty array instead of returning a malformed-payload error. This is a validation gap documented by prior local QA and is outside this code-only audit’s change scope.
- Deal and Person reverse-sync behavior is intentionally narrower than full bidirectional field synchronization: only the currently supported mapped paths are applied inbound, and structured fields are not parsed from readable text.

## Bugs Found

No new source defect was introduced or fixed during this audit. The two known webhook gaps above remain open. Current missing or stale Deal/Case mapping configuration is an operational readiness gap; the source safely stops or keeps work pending rather than inventing IDs.

## Files Changed

- [2026-09-15-didar-code-only-final-audit.md](2026-09-15-didar-code-only-final-audit.md) — this audit document only.

Existing working-tree changes in source, tests, and earlier documentation were preserved and not modified by this audit.

## Final Assessment

PARTIAL — CORE ARCHITECTURE VERIFIED BUT SOME PATHS LACK EXECUTABLE LOCAL COVERAGE

The current source shows centralized, duplicate-safe Person/Deal/Case synchronization, bounded generation retries, queue inspection/purge isolation, and webhook suppression paths. Focused JavaScript and PHP lint checks pass. Full PHP integration execution, authenticated browser workflows, remote Didar read-back, and production CRM E2E remain outside this audit and were not performed.
