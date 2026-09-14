# ns-didar Local End-to-End QA

Audit date: 2026-09-14. This audit targeted the local site only. The dedicated local QA account was used for one controlled request per active form with synthetic QA data. No production site was opened, no existing QA record was deleted, and no implementation file was changed.

## Environment

- Local site: `http://localhost/visa/`
- Local user panel: `http://localhost/visa/login/`
- WordPress: 6.9.4
- Plugin runtime: `1.7.4`
- Git HEAD: `04640427a801e6cd87f1d316e4c07eefa6f71e49`
- QA account: test account (password omitted); WordPress user ID `100`
- QA marker: `QA-LOCAL-NSDIDAR-2026-09-14`
- Browser: Chrome through `browser-runtime-inspector` workflow
- Local Didar settings: credentials configured; endpoint resolves to the real Didar service; no credentials are included here
- Local Embassy Case configuration: validator reported `ready` with a pipeline and initial stage configured; optional category was absent
- Scope boundary: local QA only; no production testing, code fixes, settings changes, or live-object deletion

## Real Object Trace

The following IDs are real objects created or resolved by the authorized local QA run. They are synthetic test records.

| Form | WordPress submission | Shared Person | Deal | Main Case | Initial companion Cases | Result |
|---|---:|---|---|---|---|---|
| consultation | `26820` | `f7bf6b55-a300-412d-bac4-72466dd57dc5` | `5a94b6d4-4d61-4cc8-9e3f-52b6b0e52bb5` | N/A | N/A | PASS WITH ISSUES |
| complaint_suggestion | `26821` | `f7bf6b55-a300-412d-bac4-72466dd57dc5` | `c1a39cd0-813f-4cb4-b672-c873f3c5fb72` | N/A | N/A | PASS WITH ISSUES |
| traveler_evaluation | `26822` | `f7bf6b55-a300-412d-bac4-72466dd57dc5` | `5c191b7a-6a16-4429-88df-b905b8f91c40` | N/A | N/A | PASS WITH ISSUES |
| visa_request | `26823` | `f7bf6b55-a300-412d-bac4-72466dd57dc5` | `37ecd202-f0f4-4acd-86f7-7075dd67967c` | `78b6b0fd-e98a-4a6f-8111-b32a82899b6d` | `f298dda3-33ca-4291-9ee3-c30720bf356c`, `2682c748-6f72-4aec-8ebd-56e332493458` | PASS WITH ISSUES |
| embassy_appointment | `26824` | `f7bf6b55-a300-412d-bac4-72466dd57dc5` | `af0047bb-5037-4e53-80ae-dec1d549e25c` | `1844ebb5-4210-4e9a-b7de-cd6f2afdabd8` | `e246b31e-0ce9-494d-8ae5-db072c488020`, `e09abf1c-70cb-4fa3-be13-bf61350fd672` | PASS WITH ISSUES |

During the run, read-only Didar lookups confirmed the Person and each Deal after synchronization. Case searches confirmed three Cases for the initial Visa request and three Cases for the initial Embassy request, all linked to their expected Deal. A later Visa add-companion test produced the fourth Visa Case `fa72e3af-fa54-4a66-8114-bc9c2281fd38`.

## Consultation

- The real consultation form rendered and submitted successfully as WordPress submission `26820`.
- The preferred-time picker opened, used a left-to-right numeric grid, showed Persian single-digit hours, and wrote the canonical hidden value `14:30` while displaying the selected time in Persian digits.
- The initial closed trigger was white and the open trigger was brand yellow. After selection and close, the computed trigger background was `rgb(255,254,251)` rather than exact white; this is recorded as a minor visual defect.
- The saved preferred date and time, applicant note, Person reference, Deal reference, and synced state were present locally.
- Direct read-only Didar verification found the shared Person and consultation Deal.

## Complaint

- The complaint/suggestion form submitted successfully as WordPress submission `26821`.
- The local record contains the synthetic date, identity, mobile, subject, message, and applicant note values.
- The record has a Person reference, Deal reference, and `synced` state with no recorded sync error.
- Direct read-only Didar verification found the shared Person and complaint/suggestion Deal.

## Traveler Evaluation

- The first synthetic passport value was rejected by the live form validation rule; no record was created by that invalid attempt.
- The corrected value matching the required one-letter/eight-digit format was accepted and submitted as WordPress submission `26822`.
- Representative identity, passport, address, employment, travel, host, and financial fields were persisted locally.
- Person and Deal references were present and the local sync state was `synced`.
- Direct read-only Didar verification found the shared Person and traveler Deal.
- The QA marker and applicant note were not confirmed in the final saved traveler snapshot. This is an evidence gap requiring a focused follow-up; it did not prevent the controlled form submission or Deal sync.

## Visa

- The Visa request submitted successfully as WordPress submission `26823`.
- Rejection history and previous Schengen history were submitted with their dependent fields visible under the Yes branches.
- `birth_country` saved as canonical `iran`.
- `companions_count` was set to `3` while two companion rows were initially saved, proving that the count is independent of the row count.
- The record initially synchronized one main-applicant Case and two companion Cases. After adding a third row, the existing Case IDs remained unchanged and exactly one new Case was created.
- A main applicant field edit succeeded without changing the Deal, main Case, companion UIDs, or existing companion Case IDs.
- A companion phone edit succeeded and the updated value was observed in the same Didar companion Case.
- The saved uploaded file reference, applicant note, history, and edit links were visible on the detail page.

## Embassy

- The Embassy appointment form submitted successfully as WordPress submission `26824`.
- `birth_country` saved as canonical `iran` and `companions_count` saved as `3` with two companion rows.
- The initial Embassy Case configuration validated as ready at runtime, so one main-applicant Case and two companion Cases synchronized successfully.
- The local Deal and Case state completed as `synced` after the initial asynchronous sync period.
- Direct read-only Didar verification found the shared Person, Embassy Deal, and three Cases linked to that Deal.

## Companion Tests

- Visa and Embassy used the shared companion architecture.
- Stable `cmp_...` UIDs were generated and persisted for all companion rows.
- The Visa companion A and B UIDs and Case IDs were unchanged across a main-applicant edit and a companion edit.
- Adding Visa companion C generated one new stable UID and one new Case while preserving A and B.
- Family relations were mapped and readable: child/`فرزند`, brother/`برادر`, and sister for the added Visa row.
- Numeric age remained separate from `age_group`. The deliberately mismatched Visa companion A value (age `10`, age group adult) remained distinct in the saved record and Didar Case.
- Companion occupation and passport fields were present in the direct Case field inspection.

## Manual companions_count

PASS. Visa was saved with `companions_count = 3` and two initial companion rows. Didar initially contained one main Case plus two companion Cases. Adding a third row left the count at `3`, preserved the existing two Cases, and resulted in one additional companion Case. Embassy independently showed the same count/row distinction at initial submission.

## Independent age_group

PASS. Visa companion A used numeric age `10` with manually selected `age_group = adult`; the saved and direct Didar Case values retained both values independently. Companion B used age `16` with `age_group = teenager`.

## Person Sync

PASS WITH ISSUES. All five local submissions resolved to the same Didar Person ID `f7bf6b55-a300-412d-bac4-72466dd57dc5`. The first local submission had no stored Person ID before synchronization; after it was resolved, the following four submissions reused the same stored identity. The exact remote create-versus-match decision was not isolated as a separate experiment.

## Deal Sync

PASS. Each form has one stored Deal ID, the local sync state is `synced`, and read-only Didar verification found all five Deals. Visa edits reused the original Deal ID `37ecd202-f0f4-4acd-86f7-7075dd67967c`.

## Main Applicant Cases

PASS. Visa main Case `78b6b0fd-e98a-4a6f-8111-b32a82899b6d` and Embassy main Case `1844ebb5-4210-4e9a-b7de-cd6f2afdabd8` were created, linked to the correct Deals, and locally marked `synced`. The Visa main Case identity remained stable after edits.

## Companion Cases

PASS. Visa initially created companion Cases `f298dda3-33ca-4291-9ee3-c30720bf356c` and `2682c748-6f72-4aec-8ebd-56e332493458`; adding C created `fa72e3af-fa54-4a66-8114-bc9c2281fd38`. Embassy created `e246b31e-0ce9-494d-8ae5-db072c488020` and `e09abf1c-70cb-4fa3-be13-bf61350fd672`. Direct Case searches confirmed the expected Deal links.

## Duplicate Prevention

PASS. Updating the Visa main applicant and companion reused the same Deal, main Case, companion UIDs, and companion Case IDs. Adding a row preserved the existing two companion Case identities and created only the new row's Case. No duplicate Case was observed in the read-only Deal searches.

## Uploads

PASS WITH ISSUES. One harmless synthetic PNG was uploaded to the Visa `personal_photo` field. The browser showed the upload success state, the local `wp_didar_files` row was finalized with the correct submission, form, field, owner, MIME type, and positive size, and no matching WordPress Media Library attachment existed. The detail page displayed the uploaded filename and download control. Multi-file limits and a full authenticated download response were not executed.

## PDF

FAIL. The real local detail page exposed both PDF modes and the button click stayed on the same page without opening a new tab. However, both authenticated requests to the PDF endpoint returned HTTP `204` with an empty body, no `Content-Type`, and no `Content-Disposition`; no PDF download event occurred. The output content and the direct file-link behavior therefore could not be verified. The likely code path is the PDF response handler around `class-didar-pdf-service.php` and the admin-post route. No PDF code was changed during this audit.

## Request List / Detail / Edit

PASS WITH ISSUES. The authenticated request list showed all five QA submissions (`26820` through `26824`) with the correct form labels, owner, status, and detail links. The Visa detail page displayed the saved conditional fields, companion rows, note, uploaded filename, history, and edit/PDF controls. Visa main-field and companion-field edits both returned the expected success message and preserved stable CRM identities.

## History

PASS WITH ISSUES. The local event log contained `request_created` for each QA request. Visa additionally contained `file_added` and `submission_data_updated` events after the upload/edit lifecycle. The browser detail view displayed creation and file history. No reverse-sync webhook or forced retry event was generated.

## Bugs Found

### BUG-LOCAL-01 — Form pages emit JavaScript initialization errors

- classification: CODE BUG / runtime defect
- evidence: actual browser page errors on all five local form pages
- observed errors: `ReferenceError: libphonenumber is not defined` and `TypeError: Failed to fetch`
- impact: the forms remained usable for this run, but initialization is not clean and dependent client behavior may be unreliable
- likely areas: frontend dependency loading and the shared JavaScript initialization path

### BUG-LOCAL-02 — PDF endpoint returns an empty 204 response

- classification: CODE BUG / feature unavailable
- evidence: actual authenticated local Visa detail request in both PDF modes returned `204`, zero bytes, no content type, and no download event
- expected: a PDF response with the selected file-inclusion mode
- impact: PDF acceptance, including file-link verification, is blocked
- likely areas: PDF response generation/dispatch around `includes/class-didar-pdf-service.php` and the admin-post handler

### BUG-LOCAL-03 — Consultation time-picker trigger is slightly off-white after close

- classification: CODE BUG / visual defect
- evidence: actual browser computed style was pure white on initial closed render, brand yellow while open, and `rgb(255,254,251)` after selection and close
- expected: the closed trigger returns to the normal white background
- impact: minor visual inconsistency; picker interaction and canonical value behavior passed

### QA-EVIDENCE-01 — Traveler marker/note not confirmed

- classification: QA evidence gap; code ownership not established
- evidence: the final saved traveler snapshot contained no QA marker in top-level field values and no saved shared-note value
- impact: the traveler request is traceable by its real WordPress and Didar IDs, but the requested marker was not confirmed in that record

## Configuration Gaps

- No blocking local configuration gap was found for Person, Deal, Visa Case, or Embassy Case synchronization during the successful run.
- Embassy had no optional Case category configured, but the live validator reported the configuration ready and the Case sync completed.
- The follow-up read-only CRM probe performed while preparing this report returned the sanitized `didar_http_error` for Person, Deal, and Case reads. Earlier reads immediately after each synchronization succeeded; this later transport failure is recorded as an external verification limitation and no mutation was attempted.

## QA Records Created

- WordPress submissions: `26820`, `26821`, `26822`, `26823`, `26824`
- Didar Person: `f7bf6b55-a300-412d-bac4-72466dd57dc5`
- Didar Deals: five, listed in Real Object Trace
- Didar Cases: Visa main plus three companion Cases after the add-row test; Embassy main plus two companion Cases
- No QA record was deleted or bulk-updated.

## Tests Not Executed

- Companion removal behavior and remote Case deletion/archive were not executed; no QA record was deleted.
- Forced Didar outage, retry recovery, reverse-sync webhook, and duplicate creation under concurrent failure were not executed.
- Multi-file upload limits and additional non-profile file fields were not executed.
- A successful PDF body, PDF metadata, or rendered PDF file-link check could not be executed because the endpoint returned empty `204` responses.
- Full field-by-field Deal mapping was not exhaustively inspected; object existence, representative saved values, and the relevant Case mappings were verified.
- The current follow-up CRM read-only probe was transport-blocked with sanitized `didar_http_error`; no write operation was performed by that probe.

## Final Assessment

- Forms: PASS WITH ISSUES — all five real local forms submitted and persisted.
- WordPress persistence: PASS WITH ISSUES — five QA submissions, list/detail/edit lifecycle, notes, history, and the tested upload persisted.
- Person sync: PASS — one shared Person identity was resolved and reused.
- Deal sync: PASS — five Deals synchronized and Visa updates reused the original Deal.
- Main applicant Cases: PASS — Visa and Embassy Cases were created and reused without duplicates.
- Companion Cases: PASS — stable UIDs, independent age group, add-row behavior, and existing Case reuse passed.
- Uploads: PASS WITH ISSUES — private file-table persistence passed; broader file/download coverage remains unexecuted.
- PDF: FAIL — both local modes returned empty `204` responses.
- Browser runtime: PASS WITH ISSUES — forms worked, but repeated initialization errors were observed.

## Final Status

**PASS WITH ISSUES — LOCAL CORE FLOW VERIFIED**
