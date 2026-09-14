# ns-didar Live Acceptance QA

Audit date: 2026-09-14. This report covers the requested production acceptance gate and its authorized retry with the dedicated QA account. The audit stopped at authentication, as required by the supplied QA brief. No production data was changed.

## Executive Result

**BLOCKED — LIVE ACCEPTANCE COULD NOT BE COMPLETED**

The production site was reachable and returned a real WordPress login page, but the single authorized login attempt with the dedicated QA account failed with the site’s password error. Because an authenticated session was unavailable, no production form was submitted and no WordPress, Didar Person, Deal, or Case IDs could be produced or verified.

## Environment

- WordPress: production login page reachable; dedicated QA account authentication failed with the site’s incorrect-password response
- plugin version: current source still has header `1.7.3` and runtime constant `1.7.4`
- Git HEAD: `04640427a801e6cd87f1d316e4c07eefa6f71e49`
- working tree: pre-existing dirty changes preserved; this QA added only this report
- production URL: `https://visakhan.com/`
- Didar access: not exercised; live form QA stopped before any CRM operation
- browser Skill: `browser-runtime-inspector` applied with headless Chrome; production login page inspected

## QA User

No credentials are included.

- WP User ID: **BLOCKED — login failed**
- Didar Person ID: **BLOCKED**
- Person created/reused: **BLOCKED**

## Real Object Trace

| Form | WP Submission ID | Person ID | Deal ID | Main Case ID | Companion Case IDs | Result |
|---|---|---|---|---|---|---|
| consultation | BLOCKED | BLOCKED | BLOCKED | N/A | N/A | AUTHENTICATION BLOCKED |
| complaint_suggestion | BLOCKED | BLOCKED | BLOCKED | N/A | N/A | AUTHENTICATION BLOCKED |
| traveler_evaluation | BLOCKED | BLOCKED | BLOCKED | N/A | N/A | AUTHENTICATION BLOCKED |
| visa_request | BLOCKED | BLOCKED | BLOCKED | BLOCKED | BLOCKED | AUTHENTICATION BLOCKED |
| embassy_appointment | BLOCKED | BLOCKED | BLOCKED | BLOCKED | BLOCKED | AUTHENTICATION BLOCKED |

No fake or substitute IDs are reported.

## Consultation

### Browser

**AUTHENTICATION BLOCKED.** The production login page was reachable, but no authenticated form page was opened. The preferred-time picker was not tested on production.

### WordPress

**BLOCKED.** No submission was created.

### Didar Deal

**BLOCKED.** No live sync or Deal lookup was performed.

### Evidence

The production login page returned HTTP 200 and a real WordPress login form. One login attempt with the dedicated QA account returned the site’s password-error state and no authenticated session. The password is intentionally omitted.

## Complaint / Suggestion

**AUTHENTICATION BLOCKED.** No production form render, submission, WordPress persistence, Person, or Deal verification was executed.

## Traveler Evaluation

**AUTHENTICATION BLOCKED.** No production form render, submission, WordPress persistence, Person, or Deal verification was executed.

## Visa Request

### Submission

**AUTHENTICATION BLOCKED.** No controlled Visa request was created.

### Deal

**BLOCKED.** No live Deal was created or inspected.

### Main Applicant Case

**BLOCKED.** No live main-applicant Case was created or inspected.

### Companion A

**BLOCKED.** No companion row or Case was created or inspected.

### Companion B

**BLOCKED.** No companion row or Case was created or inspected.

### Manual companions_count test

**TEST NOT EXECUTED.** The authenticated form was unavailable.

### Independent age_group test

**TEST NOT EXECUTED.** The authenticated form was unavailable.

## Embassy Appointment

### Submission

**AUTHENTICATION BLOCKED.** No controlled Embassy request was created.

### Deal

**BLOCKED.** No live Deal was created or inspected.

### Case Configuration

**TEST NOT EXECUTED.** The production settings screen was not reached. The previous local audit found the local Embassy initial Case stage missing; that local finding was not treated as production evidence.

### Main Applicant Case

**BLOCKED.** No live Case was created or inspected.

### Companion Cases

**BLOCKED.** No companion rows or Cases were created or inspected.

## Person Reuse

**BLOCKED.** No authenticated submission was available, so Person creation/reuse and repeated-submission behavior could not be tested.

## Deal Duplicate Prevention

- original Deal ID: **BLOCKED**
- after update: **BLOCKED**
- duplicate count: **BLOCKED**

The required update/re-sync action was not reached.

## Case Duplicate Prevention

- original Main Case: **BLOCKED**
- after update: **BLOCKED**
- original Companion Cases: **BLOCKED**
- after update: **BLOCKED**

The required Visa submission and Case update were not reached.

## Files

**TEST NOT EXECUTED.** No authenticated upload field was available. No production files were uploaded and no file references were changed.

## PDF

**TEST NOT EXECUTED.** No authenticated QA submission was available for either PDF mode. No production PDF was generated.

## Request List / Detail / Edit

**AUTHENTICATION BLOCKED.** The QA account could not reach the user panel, request list, detail, or edit screens.

## History

**TEST NOT EXECUTED.** No production request was created, edited, or synced.

## Reverse Sync

**TEST NOT EXECUTED.** No authenticated production session or safe controlled Didar object was available. No webhook or reverse-sync operation was performed.

## Retry

**TEST NOT EXECUTED.** No production retry operation was attempted and credentials were not deliberately disturbed.

## Configuration Gaps

No production configuration gap was assessed because authentication failed before the production settings or form runtime could be inspected.

## Bugs Found

### BUG-01 — Plugin metadata version disagrees with runtime version

- severity: low
- form/object: plugin release metadata
- WP ID: N/A
- Didar ID: N/A
- steps: inspect `didar.php` plugin header and `DIDAR_VERSION`
- expected: WordPress plugin metadata and runtime version identify the same release
- actual: header is `1.7.3`; runtime constant is `1.7.4`
- evidence: current source inspection at the audited Git HEAD
- likely code path: `didar.php` plugin header and version constant

This is a source finding carried forward as requested. It was not a live production behavior test.

No live form or CRM bug could be classified because the authentication gate prevented execution.

## QA Records Created

### WordPress

None.

### Didar Person

None.

### Didar Deals

None.

### Didar Cases

None.

## Tests Not Executed

- All five production form submissions: **AUTHENTICATION BLOCKED**.
- WordPress submission persistence and ownership checks for new QA records: **AUTHENTICATION BLOCKED**.
- Didar Person, Deal, main Case, and companion Case creation or direct verification: **AUTHENTICATION BLOCKED**.
- Deal and Case duplicate-prevention update tests: **AUTHENTICATION BLOCKED**.
- Companion edit, add, and removal behavior: **AUTHENTICATION BLOCKED**.
- File upload, multi-file limits, secure access, and edit reload: **AUTHENTICATION BLOCKED**.
- PDF with-files/without-files output and download behavior on a real production request: **AUTHENTICATION BLOCKED**.
- Request list/detail/edit, event history, reverse sync, and retry: **AUTHENTICATION BLOCKED**.
- Production Embassy Case configuration inspection: **AUTHENTICATION BLOCKED**.

The supplied production login page itself was inspected. One login attempt was made using the dedicated QA credentials supplied for this task; it failed with the site’s password error. No brute force or repeated attempts were made. No local or synthetic result is substituted for live acceptance.

## Final Production Assessment

### Forms

BLOCKED — authenticated production form access unavailable.

### WordPress Persistence

BLOCKED — no production submission was created.

### Person Sync

BLOCKED — no live object was created or verified.

### Deal Sync

BLOCKED — no live object was created or verified.

### Companion Cases

BLOCKED — no authenticated Visa or Embassy request was available.

### Main Applicant Cases

BLOCKED — no authenticated Visa or Embassy request was available.

### Duplicate Prevention

BLOCKED — no controlled object reached the update/re-sync steps.

### File Handling

BLOCKED — no authenticated upload was available.

### PDF

BLOCKED — no authenticated production submission was available.

### Reverse Sync

BLOCKED — no safe controlled production object was available.

## Final Status

**BLOCKED — LIVE ACCEPTANCE COULD NOT BE COMPLETED**
