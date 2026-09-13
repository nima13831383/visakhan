# ns-didar — Complete Feature Inventory

**Snapshot:** 2026-09-14
**Scope:** `C:\xampp\htdocs\visa\wp-content\plugins\ns-didar`
**Inventory target:** the current working tree, not only `HEAD`
**Canonical current-state reference:** `docs/PROJECT-HANDOFF.md` (last verified 2026-09-13)
**Source of truth for forms and reference data:** `Didar_Form_Registry` and `Didar_Reference_Data`

This is a code inventory, not a product requirements document. A feature is listed when it is implemented or exposed by the current source. Where the current code, current handoff, and historical documents disagree, the discrepancy is recorded explicitly near the end of this document.

## 1. Executive summary

`ns-didar` is a fixed-form, Persian/RTL WordPress request-management plugin with a private file subsystem, role/capability-based operations, frontend request/profile views, WordPress-backed workflow data, and asynchronous WordPress ↔ Didar CRM synchronization.

The current source contains:

- 5 active fixed forms: `consultation`, `embassy_appointment`, `traveler_evaluation`, `complaint_suggestion`, and `visa_request`.
- 148 active top-level registry fields across those forms.
- 153 form-to-CRM mapping fields when the separately supported `applicant_note` field is counted once per form.
- 112 unique active field keys across the five forms.
- 6 active file fields in form definitions, plus 5 reusable profile-document definitions.
- 3 repeater definitions: two in Embassy Appointment and one in Visa Request.
- 2 derived companion-count fields and 7 conditional Visa-history fields.
- One private `didar_submission` custom post type; companions are rows inside a parent submission, not separate submissions.
- 34 PHP class files under `includes/`, 8 plugin assets, and 20 PHP/JavaScript test files.
- 6 plugin shortcodes, 5 authenticated AJAX actions, 2 custom REST webhook routes, and 2 plugin-owned admin pages.
- Four public/reference statuses and configurable per-form internal CRM workflows.
- Person, Deal, and optional companion/main-applicant Case synchronization with exact-match safeguards and durable retries.
- Append-only submission events plus a separate redacted diagnostic log table.

The plugin is presently in a partly uncommitted upgrade state. The working tree contains the current companion, PDF, profile, searchable-select, settings-transfer, and webhook work; these changes must not be confused with the historical `HEAD` snapshot.

## 2. Inventory method and evidence rules

The audit used the following evidence hierarchy:

1. Current PHP/JavaScript source and tests.
2. `docs/PROJECT-HANDOFF.md` for the current upgrade state and known operational constraints.
3. `Agents.md` and the documentation index for safety rules and historical-document status.
4. Older numbered documents only to identify historical behavior or documentation drift.

Read-only checks performed:

- Enumerated the plugin source, assets, tests, hooks, shortcodes, options, metadata, REST routes, and API client paths.
- Counted the current registry definitions with a static data-class inspection; no WordPress bootstrap or external API call was used.
- Ran PHP syntax validation against all 34 files in `includes/`; all passed.
- Preserved all pre-existing working-tree changes. During the initial inventory audit, the only file added was this document.

The inventory does not claim live browser, PHPUnit, WordPress integration, or CRM verification. The repository test README says the tests require an existing WordPress PHPUnit environment, and the handoff records the current global PHPUnit/PHP 8.2 incompatibility and unavailable local browser QA.

## 3. Architecture and foundation

### 3.1 Bootstrap and service composition

Evidence: `didar.php`; `includes/class-didar-plugin.php` (`Didar_Plugin`).

Features:

- WordPress plugin bootstrap with plugin constants, activation/deactivation hooks, class loading, and optional Composer autoloading.
- Singleton service container-style orchestrator exposing the registry, renderer, validator, submission service, event log, logger, settings, file service, search service, sync manager, Case service, and PDF service.
- Text-domain loading on `plugins_loaded`.
- CPT registration on `init`.
- Access-control version upgrade and schema repair checks during normal bootstrap.
- Runtime worker scheduling during normal bootstrap.
- Activation installs roles/capabilities, registers the CPT, installs/verifies tables, upgrades the diagnostic logger, synchronizes private-storage protection, and schedules workers. Activation stops with an admin-facing failure if required schema setup fails.
- Deactivation clears the plugin’s scheduled backfill, cleanup, submission-sync, and user-sync hooks. It does not delete roles, submissions, options, files, or tables.

### 3.2 Custom post type

Evidence: `includes/class-didar-post-type.php` (`Didar_Post_Type::register`).

The submission model is a deliberately private WordPress CPT:

- Post type: `didar_submission`.
- `public`, `publicly_queryable`, archive, rewrite, query var, REST exposure, admin bar, and navigation exposure are disabled.
- It has a WordPress admin UI and appears under its own admin menu.
- No post supports and no custom taxonomy are registered.
- Capability type is `didar_submission` / `didar_submissions` with `map_meta_cap` enabled.
- Search exclusion is enabled at the CPT level; the plugin adds its own controlled admin/frontend search path.

### 3.3 Schema management

Evidence: `includes/class-didar-schema-manager.php` (`maybe_repair`, `install`, `verify`).

- Schema state option: `didar_schema_state`.
- Last-error option: `didar_schema_last_error`.
- Schema version: `1.0.0`.
- Automatic repair detects missing tables, stale versions, and failed/incomplete verification.
- Full checks are throttled; after an error, retries are held for five minutes.
- Required plugin tables are the event table, file table, and file-reference table. The diagnostic logger maintains its own table/version.
- Admin notices are restricted to users who can manage plugin settings.

## 4. Roles, identities, capabilities, and access control

Evidence: `includes/class-didar-access-control.php`; `includes/class-didar-user-identity.php`; `includes/class-didar-submission-service.php`.

### 4.1 WordPress roles

The plugin provisions two stable WordPress roles:

| Role | Label | Effective behavior |
|---|---|---|
| `didar_colleague` | همکار | Own assigned/request scope, workflow visibility permitted by caps, optional own internal history; no broad WordPress administration. |
| `didar_broker` | کارگزار | Operational Didar administrator profile: receives the plugin’s administrator capability set, including all submission and workflow operations, but not unrelated broad WordPress caps. |

Role installation is versioned with option `didar_access_version` (`1.2.0`) and is idempotent. The access layer removes protected normal WordPress capabilities before applying the allowed Didar capability set.

### 4.2 Capability groups

The colleague role receives `read`, `didar_colleague_access`, `didar_view_own_internal_workflow`, and `didar_view_own_request_history`.

The broker role receives the administrator capability set below in addition to `read`; the set is deliberately composed of plugin/CPT capabilities rather than unrestricted WordPress administration.

Submission/CPT capabilities include:

`read_didar_submission`, `read_private_didar_submissions`, `edit_didar_submission`, `edit_didar_submissions`, `edit_others_didar_submissions`, `edit_private_didar_submissions`, `edit_published_didar_submissions`, `publish_didar_submissions`, `delete_didar_submission`, `delete_didar_submissions`, `delete_others_didar_submissions`, `delete_private_didar_submissions`, `delete_published_didar_submissions`, and `create_didar_submissions`.

Operational workflow capabilities include:

`didar_view_requests`, `didar_view_request`, `didar_edit_requests`, `didar_change_public_status`, `didar_edit_public_notes`, `didar_view_internal_workflow`, `didar_change_internal_status`, `didar_add_internal_notes`, `didar_assign_requests`, `didar_receive_requests`, `didar_view_request_history`, `didar_view_all_requests`, `didar_change_request_owner`, and `didar_manage_settings`.

### 4.3 Access rules

- `can_edit_request()` requires the submission capability checks and WordPress `edit_post()` success.
- `can_access_didar_admin()` is based on `didar_view_requests`, not merely a role name.
- Users without `didar_view_all_requests` are scoped to submissions authored by the current user.
- Customers can view their own published submissions and public workflow data; they cannot view internal workflow or internal notes.
- Colleagues can view own internal workflow/history only when the relevant capability and `colleague_can_view_internal_history` setting allow it.
- Operators with the necessary caps can view/edit according to request scope and `edit_post()`.
- Owner changes require `didar_change_request_owner`.
- Frontend customers, colleagues, and owners may edit until the public status is `completed`; operators require the operational edit capabilities.
- Admin request routes and admin menu pages are restricted. AJAX requests are handled separately so legitimate plugin AJAX is not redirected by the general admin gate.
- A Nader theme admin gate is removed for users with valid Didar admin access; non-Didar admin pages and WooCommerce admin surfaces are restricted for the plugin’s operator users.

### 4.4 Display identity model

`Didar_User_Identity` classifies the current WordPress user for display/audit purposes as `admin`, `agent`, `coworker`, or `customer`. This is separate from the two provisioned WordPress role names and prevents display logic from depending on a single role slug.

## 5. Active form catalog

Evidence: `includes/class-didar-form-registry.php` (`build_forms`, `fields`, `supports_applicant_note`); `includes/class-didar-reference-data.php`.

The plugin is a fixed-form system. It is not a generic form builder. The registry is authoritative, and legacy definitions retained in the file are inactive historical data; they are not rendered, validated, or saved as active form fields.

### 5.1 Form-level counts

| Form type | Sections | Registry fields | Renderable | Internal | Derived | Conditional | File fields | Repeaters | Searchable | Registry-required | Legacy fields |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| `consultation` | 2 | 8 | 8 | 0 | 0 | 0 | 0 | 0 | 0 | 4 | 5 |
| `embassy_appointment` | 4 | 27 | 25 | 2 | 1 | 0 | 2 | 2 | 5 | 10 | 0 |
| `traveler_evaluation` | 8 | 59 | 59 | 0 | 0 | 0 | 0 | 0 | 3 | 0 | 0 |
| `complaint_suggestion` | 1 | 6 | 6 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| `visa_request` | 8 | 48 | 48 | 0 | 1 | 7 | 4 | 1 | 8 | 0 | 1 |
| **Total** | **23** | **148** | **146** | **2** | **2** | **7** | **6** | **3** | **16** | **14** | **6** |

The five forms add one supported `applicant_note` mapping field each, for 153 mapping fields in total. These five note fields are outside the 148 top-level active registry field count. Across all active definitions there are 112 unique field keys.

### 5.2 Consultation (`consultation`)

Sections: main information and preferred appointment time.

Fields:

- `first_name` — required text.
- `last_name` — required text.
- `input_3` — required mobile/phone field.
- `email` — optional email.
- `input_5` — required subject; historical choice values include tourism (`torist`), education (`tahsil`), work (`kari`), property (`tamlik`), investment (`sarmae`), and real estate (`melk`).
- `description` — optional textarea.
- `preferred_date` — optional date.
- `preferred_time` — optional time with 60-second step.

Retained inactive legacy fields are `input_1`, `input_4`, `input_6`, `input_7`, and `input_8`.

### 5.3 Embassy Appointment (`embassy_appointment`)

Sections: request, personal information, documents, and companions.

Fields:

- `twitter` — internal honeypot; never a normal user-facing data field.
- Request: `request_for`, `country`, `service_type`, `profession`, `appointment_date`, `urgency`.
- Personal: `first_name`, `last_name`, `mobile`, `email`, `current_nationality`, `passport_number`, `birth_country`, `birth_province`, `birth_city`, `birth_place`, `gender`, `passport_issue_place`, `father_name`, `mother_name`, `birth_date`.
- `personal_details` — internal repeater/operational detail.
- Documents: `personal_photo`, `passport_main_page`.
- `companions_count` — derived from meaningful companion rows.
- `companions` — companion repeater, maximum 20 rows.

Passport numbers use the semantic rule of one letter plus eight digits. Iran geography fields use dependent province/city catalogs; foreign birth countries use `birth_place` rather than Iranian province/city data.

### 5.4 Traveler Evaluation (`traveler_evaluation`)

Sections: identity, passport, family/address, employment, travel purpose, Schengen history, invitation, and funding.

Fields:

- Identity: `evaluation_date`, `first_name`, `last_name`, `mobile`, `email`, `mother_name`, `father_name`, `former_names`, `nationality`, `birth_date`, `birth_place`, `gender`, `marital_status`, `children_count`, `children_ages`, `national_id`.
- Passport: `passport_type`, `passport_number`, `passport_issue_date`, `passport_expiry_date`, `passport_issuer_country`.
- Family/address: `relation`, `home_address`, `postal_code`, `home_phone`, `secondary_email`, `other_residency_passport`.
- Employment: `current_job`, `work_address`, `employer_name`, `work_postal_code`, `employer_phone`, `employer_email`.
- Travel purpose: `travel_purpose`, `travel_purpose_details`, `main_destination_country`, `first_entry_country`, `requested_entries`.
- Schengen: `first_entry`, `first_entry_date`, `first_exit`, `first_exit_date`, `previous_fingerprints`, `previous_visa_number`, `last_visa_valid_from`, `last_visa_valid_to`, `final_country_entry_permit_issuer`, `entry_permit_valid_from`, `entry_permit_valid_to`.
- Invitation: `has_invitation`, `host_or_hotel_name`, `host_or_hotel_address`, `host_or_hotel_phone`, `host_or_hotel_email`.
- Funding: `travel_funding`, `self_funding_methods`, `sponsor_funding_methods`, `financial_accounts`, `rial_balance`, `foreign_currency_balance`, `property_deeds_count`.

The current registry does not mark these fields required by default; effective requirements can be changed by settings overrides.

### 5.5 Complaint/Suggestion (`complaint_suggestion`)

One main section:

`date`, `first_name`, `last_name`, `mobile`, `subject`, and `message`.

The current registry does not mark these fields required by default.

### 5.6 Visa Request (`visa_request`)

Sections: identity, academic, travel/documents, documents, financial, employment, history, and companions.

Fields:

- Identity: `request_for`, `first_name`, `last_name`, `birth_surname`, `birth_date`, `birth_province`, `birth_city`, `birth_place`, `birth_country`, `current_nationality`, `birth_nationality`, `national_id`, `marital_status`, `mobile`, `email`, `residential_address`, `postal_code`.
- Academic: `academic_level`, `invitation_type`.
- Travel: `passport_number`, `passport_expiry`, `passport_issuer_country`, `travel_destination`.
- Documents: `personal_photo`, `passport_main_page`, `round_trip_ticket`, `other_documents`.
- Financial: `account_balance`, `six_month_turnover`, `has_foreign_currency_account`, `has_property_deed`, `passive_income`.
- Employment: `employment_type`, `occupation`, `workplace_name`, `job_title`, `employment_documents`.
- History: `has_rejection`, `rejection_embassy`, `rejection_date`, `has_previous_schengen`, `previous_schengen_country`, `previous_schengen_date`, `estimated_travel_date`, `estimated_travel_end_date`, `schengen_exit_place`.
- Companions: `companions_count` and `companions`.

Conditional behavior:

- `has_rejection` controls `rejection_embassy` and `rejection_date`.
- `has_previous_schengen` controls `previous_schengen_country` and `previous_schengen_date`.
- `estimated_travel_date`, `estimated_travel_end_date`, and `schengen_exit_place` are part of the Visa history lifecycle and are shown/validated by the current conditional rules.
- Empty/no parent values hide and clear their dependent values in the frontend lifecycle. Server validation skips inactive conditional fields while edit behavior preserves inactive stored values where the service explicitly requires historical preservation.
- The end travel date cannot precede the start date.

The retained inactive legacy field is `full_name`.

### 5.7 Shared `applicant_note`

`Didar_Form_Registry::supports_applicant_note()` supports all five active forms. The note is added to mapping fields and rendered separately by the frontend/admin submission views. It is stored as `_didar_shared_note` and synchronized through the configured mapping path. The Settings API sanitizer uses this registry capability, so it preserves valid applicant-note mappings for every supported form.

## 6. Reference catalogs and common field semantics

Evidence: `includes/class-didar-reference-data.php`; `includes/class-didar-date-service.php`; `includes/class-didar-validator.php`; `includes/class-didar-field-renderer.php`.

### 6.1 Catalogs

- Countries: 65 entries, with Iran first.
- Provinces: 31 entries.
- Cities: 1,531 entries, with province-dependent lookup.
- Request-for choices: self / other.
- Service types: 7.
- Family relations: 10.
- Age groups: infant, child, teenager, adult, elderly.
- Occupations: 201 entries including `other`.
- Academic levels: 7.
- Yes/no choices: 2.
- Public statuses: `pending_review`, `initial_approval`, `needs_correction`, `completed`.

Catalogs can be filtered by the extension points `didar_{dataset}_for_{form_type}` for countries, cities, occupations, and academic levels. No other current custom filter extension points were found.

### 6.2 Dates and digits

- Frontend date display/input is Jalali/Persian `YYYY/MM/DD`.
- Local canonical storage is Gregorian ISO `YYYY-MM-DD`.
- Didar-readable serialization uses Jalali values.
- Persian and Arabic digits are normalized.
- `Didar_Date_Service` uses `IntlCalendar` when available and an internal fallback algorithm otherwise; no external date service is required.

### 6.3 Renderer behavior

The renderer supports:

- Sections and field definitions.
- Internal fields and honeypots with different visibility rules.
- Text, email, textarea, date, time, radio, select, checkbox, number, file, searchable select, multi-select, and repeaters.
- Semantic attributes, placeholders, settings-backed defaults, conditional/dependent attributes, and accessible labels/descriptions.
- Hidden canonical ISO dates beside visible Jalali inputs.
- Multiple-time add/remove controls with uniqueness and limits.
- Companion/repeater row rendering and file details.
- Profile autofill for Embassy/Visa when `request_for` is self; submitted/saved values win over defaults.
- Main applicant/companion presentation for detail and PDF views.

## 7. Companion model

Evidence: `includes/class-didar-companion-model.php`; `includes/class-didar-submission-service.php`; `includes/class-didar-sync-manager.php`.

The companion subsystem supports Embassy Appointment and Visa Request only.

### 7.1 Row model

The 14 companion columns are:

`companion_uid`, `full_name`, `family_relation`, `age`, derived `age_group`, `occupation`, `national_id`, `passport_number`, `email`, `phone`, `personal_photo`, `passport_main_page`, `round_trip_ticket`, and `other_documents`.

Behavior:

- Maximum 20 companion rows per submission.
- Ages are constrained to 0–130.
- Age groups are derived using infant 0–1, child 2–12, teenager 13–17, adult 18–64, and elderly 65+.
- Meaningful-row counting ignores the UID and derived age group.
- Stable generated UIDs use `cmp_<uuid>` and are preserved through edits/reordering.
- A stable main-applicant Case UID uses `main_<submission_id>`.
- The main applicant row is derived from submission/user values and can include `case_role=main_applicant`.
- `companions_count` is derived and is not a user-authoritative value.
- Local removal marks the remote Case relationship removed in local state; it does not delete a remote Case.

## 8. Submission lifecycle and storage model

Evidence: `includes/class-didar-submission-service.php`; `includes/class-didar-admin.php`; `includes/class-didar-event-log.php`.

### 8.1 Creation

`Didar_Submission_Service::create()`:

1. Validates the form type and submitted data.
2. Enforces the requested owner unless the actor has owner-change capability.
3. Computes public/internal defaults from registry and workflow configuration.
4. Inserts a published `didar_submission` post.
5. Stores canonical form and workflow metadata.
6. Finalizes temporary file records.
7. Writes the `request_created` event and diagnostic log.
8. Fires `didar_submission_created` for asynchronous sync.

The title is generated from the form label and timestamp. The source user is stored separately from the WordPress post author.

### 8.2 Local metadata

The submission’s key post meta includes:

- `_didar_form_type` — immutable active form type.
- `_didar_created_by_user_id` — creating user.
- `_didar_fields` — sanitized canonical field data.
- `_didar_shared_note` — applicant note where supported.
- `_didar_status` — compatibility/public status alias.
- `_didar_public_status`, `_didar_public_note` — customer-visible workflow snapshot.
- `_didar_internal_status`, `_didar_internal_note` — operator-only workflow snapshot.
- `_didar_assigned_user_id` — WordPress assignee.
- `_didar_last_updated_at` — event-derived meaningful update timestamp.
- `_didar_deal_id`, `_didar_person_id`, `_didar_sync_state` — Didar synchronization state.
- `_didar_companion_cases`, `_didar_main_applicant_case`, `_didar_case_sync_state` — Case synchronization state.

### 8.3 Update and edit behavior

- Form type cannot be changed after creation.
- Existing inactive fields are preserved during edits when the service’s historical-preservation path applies.
- Form data, note, public status/note, internal status/note, assignee, owner, and files are updated through separate capability-checked paths.
- Changes create typed events and trigger centralized synchronization after canonical local persistence.
- Invalid frontend values are preserved for redisplay by the edit shortcode; only validated data reaches the canonical update path.
- WordPress trash/delete is guarded and audited. Remote deletion is not attempted.

### 8.4 Webhook-originated local creation

`create_from_didar()` can create a local submission from an authenticated/verified inbound Deal when explicit system mappings provide a valid form type and WordPress user ID. It does not guess from titles, phone numbers, or unverified labels.

## 9. Public shortcodes and frontend feature surface

Evidence: `includes/class-didar-shortcodes.php`; `includes/class-didar-user-profile.php`; `assets/js/*`; `assets/css/*`.

The plugin registers 6 shortcodes:

| Shortcode | Feature |
|---|---|
| `[didar_form]` | Authenticated fixed-form submission UI and POST handling. |
| `[didar_form_access]` | Form link and optional barcode/QR-style access display. |
| `[didar_submissions]` | Scoped submission list, search, form/status filters, pagination, and actions. |
| `[didar_submission_details]` | Read-only detail view, timeline, status, owner, stage progress, PDF controls. |
| `[didar_submission_edit]` | Authenticated owner/operator edit view and update flow. |
| `[didar_profile_form]` | User profile fields and reusable private profile-document management. |

### 9.1 `[didar_form]`

- Requires login.
- Accepts a valid registry form type.
- Uses form-specific nonce `didar_submit_form_{type}`.
- Uses a per-user/request token transient to prevent duplicate POST replay for one hour.
- Validates and creates a submission, then displays the local submission ID on success.
- Renders the five-form `applicant_note` separately when supported.
- Uses the file AJAX lifecycle before final submission persistence.

### 9.2 `[didar_submissions]`

- Requires login.
- Accepts type/search/filter attributes with fixed form-type precedence.
- Uses GET variables `didar_search`, `didar_type`, and `didar_page`; repeated shortcode instances receive a `_2` suffix for controls.
- Applies scoped SQL selection, search, pagination, and status/form filters.
- Displays ID, applicant name, last update, form, owner/role, date, status, and actions.
- Frontend page size is controlled by `frontend_requests_per_page`, bounded to 1–100.
- Invalid type produces an empty/invalid state rather than falling through to all records.

### 9.3 Details/edit views

Details can include:

- Form sections and historical legacy values.
- Public status/note and applicant note.
- Internal workflow when authorized.
- Event timeline when authorized.
- Owner identity and stage progress.
- Last meaningful update.
- Edit link, with editing disabled for completed requests.
- PDF modal controls for “with files” and “without files”.

Edit POST behavior uses `update_submission`, a page ID, request-specific nonce `didar_edit_submission_{id}`, scope/capability checks, validation, a safe return URL, transient success messaging, and redirect after persistence.

### 9.4 `[didar_form_access]`

Supports form, mode, link, QR/barcode, and custom text attributes. URLs and image attachments are sanitized and validated; target-blank links include `noopener`. Empty or invalid access data produces no unsafe output.

### 9.5 Profile form

`Didar_User_Profile::shortcode()` provides:

- Login-gated editing of first name, last name, display name, gender, birth date, national ID, and email.
- Read-only mobile by policy; a submitted forged change is rejected/ignored.
- Birth date stored canonically in `_didar_birth_date`.
- National ID digit normalization.
- Email format and uniqueness checks.
- Optional native WordPress profile image upload, separate from private Didar documents, stored in `profile_image`.
- Profile-document AJAX management for reusable document keys.
- User sync queued after profile save; a successful local save is not rolled back solely because external sync fails.

## 10. Frontend and admin JavaScript behavior

Evidence: `assets/js/form-input-rules.js`, `frontend.js`, `admin.js`, `jalali-datepicker.js`, and `user-profile.js`.

### 10.1 Shared input rules

- Persian/Arabic digit normalization.
- Live national-ID and passport sanitization.
- Jalali date validation and date-range validation.
- Image preview and object-URL cleanup.
- Upload states: waiting, uploading, success, failed, invalid; retry controls.
- Sequential upload behavior for multi-file fields.
- Submit blocking while uploads are pending.
- Searchable select enhancement with RTL combobox/listbox semantics, single/multiple modes, clear action, chips, keyboard navigation, and ARIA attributes.
- Iran province→city dependent filtering.
- `request_for` self/other profile prefill, clearing, and value precedence.
- Conditional Visa history hide/clear behavior.
- Foreign birth-place toggle.
- MutationObserver enhancement for dynamically added controls.
- Focus on the first validation error.

### 10.2 Repeaters and companions

`frontend.js` and `admin.js` add/remove/reindex rows, recalculate derived companion counts and age groups, run nested validation, and keep generated UIDs stable.

### 10.3 Dates and PDF

- `jalali-datepicker.js` provides Persian month/day selection, year/month selectors, clearing, hidden ISO canonical values, and an Intl/fallback display path.
- PDF modal includes focus return and focus trapping, fetches a PDF blob, checks content type, and triggers download.

### 10.4 Admin dynamic controls

- Admin repeater add/remove/reindex.
- Sequential admin uploads, retry, and remove.
- Barcode media selection limited to JPEG/PNG/WebP.
- Case pipeline→stage dependency.
- Pipeline-filtered custom field controls.
- Workflow status rows, default selection, ordering, and removal.
- Dynamic registry fields through authenticated `didar_get_form_fields`.
- Form submission blocking during upload.

## 11. Private file and document subsystem

Evidence: `includes/class-didar-file-service.php`; `includes/class-didar-profile-document-catalog.php`; `includes/class-didar-form-access.php`.

### 11.1 Submission file rules

- Private storage directory: `didar-private` below the WordPress uploads area.
- No WordPress Media Library attachment is created for submission documents.
- Active form upload fields accept actual image MIME content only: JPG, JPEG, PNG, and WebP.
- Maximum size is 5 MB per file, further bounded by WordPress upload limits.
- Active upload fields allow at most 2 files per field.
- Profile document definitions allow 1 file each.
- Original filenames are sanitized and truncated; stored names are unpredictable.
- Upload records begin as temporary, then become final only after canonical submission persistence.
- Replacements remove/relink the previous reference through the file service.
- Abandoned temporary files are cleaned without generating a submission event.

### 11.2 Database model

`didar_files` records file ID, original/stored names, relative path, MIME/extension, size, owner, submission/form/field identity, status, and timestamps.

`didar_file_references` records the relationship between a file and submission/form/field, with a unique submission/file/field relationship.

The file schema version is `1.2.0`, with schema/version options and automatic protection synchronization.

### 11.3 Access and downloads

- Secure download uses login, request-specific nonce `didar_download_file_{id}`, final-file state, submission authorization, attachment headers, `nosniff`, and CSP headers.
- PDF-file download rechecks the submission/file relationship and authorization.
- Profile-document download/removal is restricted to the profile owner or authorized administrators.
- Deleting a submission removes its local file references/files; profile documents are retained while independently referenced.
- Direct mode is an explicit setting; secure mode is the default.
- Secure mode writes `index.php`, Apache `.htaccess`, and IIS `web.config` protection files. Nginx requires deployment-side equivalent protection.

### 11.4 Profile documents

Reusable profile-document keys:

`national_card_front`, `national_card_back`, `passport_main_page`, `personal_photo`, and `birth_certificate_first_page`.

Each is a single private image document with a 5 MB maximum.

### 11.5 PDF behavior and caveat

`Didar_Pdf_Service` generates an A4 RTL/Persian PDF with mPDF, uses a temporary output directory, validates the resulting `%PDF` signature, and deletes temporary output in a `finally` path.

- “Without files” omits binary file content.
- “With files” uses validated direct HTTP/HTTPS references for file refs.
- Internal IDs and internal-only fields are excluded.
- Values are serialized with choice labels, repeater output, Jalali date display, and bounded depth/item counts.

Current code caveat: `Didar_Pdf_Service::file_view()` calls `Didar_File_Service::get_direct_url_for_reference()` even when the configured download mode is secure. The URL is membership/readability-validated, but secure storage protection may make that direct URL inaccessible to mPDF or to the resulting document. This is documented here as observed behavior; this inventory does not modify it.

## 12. Status, workflow, notes, and event history

Evidence: `includes/class-didar-reference-data.php`; `includes/class-didar-submission-service.php`; `includes/class-didar-workflow-manager.php`; `includes/class-didar-event-log.php`.

### 12.1 Public status

The current public/reference status allowlist is:

| Key | Meaning |
|---|---|
| `pending_review` | Request is awaiting review. |
| `initial_approval` | Initial approval state. |
| `needs_correction` | Customer correction is required. |
| `completed` | Public workflow is complete and frontend editing is disabled. |

Public status, public note, internal status, internal note, and assignee can be changed independently with capability checks. `_didar_status` and `_didar_admin_note` remain compatibility aliases.

### 12.2 Internal workflow

`Didar_Workflow_Manager` supports per-form pipeline/workflow configuration, ordered statuses, labels, stage IDs, exactly one default status, reverse mapping, pipeline lookup, stale metadata indicators, and configuration validation. It falls back to legacy default-pipeline/status-stage settings only when permitted by the current code.

Stage-progress UI uses the configured internal workflow for authorized operator views. It treats `cancelled`, `canceled`, `rejected`, `failed`, and `closed_lost` as terminal codes if encountered, even though they are not current reference-status keys.

### 12.3 Append-only event log

`{$wpdb->prefix}didar_events` stores event ID, submission ID, event type, actor, old/new values, JSON metadata, and UTC creation time. It has submission, event, actor, and timestamp indexes.

Current event types include:

`request_created`, public/internal status changes, public/internal note changes, assigned/reassigned/removed, submission data updated, applicant note, file add/replace/remove, owner changed, trashed/deleted, webhook received, and Didar sync failure.

Meaningful request events update `_didar_last_updated_at`; diagnostic and sync-failure events are intentionally excluded unless a webhook records a meaningful request change. A batched `didar_backfill_last_updated` worker fills missing timestamps. Event history is read newest-first, capped by a default limit of 100, and is not purged or rewritten by the plugin.

## 13. Didar CRM integration

Evidence: `includes/class-didar-api-client.php`; `includes/class-didar-field-mapper.php`; `includes/class-didar-sync-manager.php`; `includes/class-didar-workflow-manager.php`; `includes/class-didar-custom-field-catalog.php`.

### 13.1 API client

Base URL: `https://app.didar.me`.

Configuration is checked through `didar_settings[didar_api_key]`. Requests use `wp_remote_post`, a 20-second timeout, JSON bodies, an `apikey` query argument, non-2xx/WP error handling, JSON validation, and application-error handling.

The client exposes these distinct API paths:

| Path | Client method/use |
|---|---|
| `/api/pipeline/list/0` | Connection test and Deal pipeline metadata. |
| `/api/contact/PersonSearch` | Exact/criteria Person search. |
| `/api/contact/save` | Person create/update. |
| `/api/contact/getbyphonenumber` | Person mobile lookup. |
| `/api/contact/GetContactDetail` | Person detail retrieval. |
| `/api/deal/search_v2` | Deal resolution. |
| `/api/deal/save_v2` | Deal create/update. |
| `/api/pipeline/list/1` | Case pipeline metadata. |
| `/api/User/List` | Didar-user catalog. |
| `/api/customfield/GetCustomfieldList` | Custom-field metadata. |
| `/api/Case/search` | Case resolution. |
| `/api/Case/Save_v2` | Case create/update. |
| `/api/activity/save` | Note/activity client method. |

`save_note()` exists in the API client, but the current source scan found no caller outside the API class; no active note/activity sync flow is therefore evidenced.

### 13.2 Person synchronization

- WordPress user → Didar Person is the identity foundation.
- The stored mapping is `_didar_person_id` user meta and, where needed, submission meta.
- The synchronizer verifies stored IDs, then uses exact normalized mobile lookup when necessary.
- Ambiguous matches become a conflict; the system does not guess.
- Duplicate/recovery paths can link an exact existing Person or create/update a Person.
- Profile fields are mapped from the canonical WordPress user/profile catalog; inbound profile overwrite is intentionally narrow.
- New users and Digits phone metadata changes queue Person sync.

### 13.3 Deal synchronization

- One local submission maps to one Didar Deal.
- Stored Deal ID is used only after validation.
- If no valid stored ID exists, the plugin resolves by the configured exact WordPress submission-ID custom field.
- Multiple matches stop with a conflict; title, phone, user, or fuzzy inference is not used.
- A Deal is created with native fields first; the returned Deal ID is persisted before custom fields are updated.
- Updates reuse the same Deal.
- The payload can include form type, WordPress submission ID, WordPress user ID, configured custom fields, public status, and mapped owner.
- Owner selection uses the assigned WordPress user’s configured Didar UserId when available, otherwise the configured default owner.
- Structured values are serialized as bounded readable text; date values are represented in the Didar-readable Jalali form.

### 13.4 Mapping and catalogs

`Didar_Field_Mapper` supports mapping targets:

- `person_native`
- `person_custom`
- `deal_native`
- `deal_custom`

Deal custom fields are accepted only when metadata identifies a Deal field and the field is available in the selected pipeline. `Didar_Custom_Field_Catalog` caches normalized ID/key/title/type/control/deletion/excluded-pipeline/required-stage/view-option metadata. Workflow, field, and user refresh failures retain the last good cache and log the failure.

### 13.5 Synchronization state and failure behavior

- Submission state is `_didar_sync_state`.
- Per-submission locks use `didar_submission_sync_lock_{post_id}` with a 120-second TTL.
- State records attempts, trace ID, last attempt, last success, last error, and Deal ID.
- Pending failures retry with increasing delay capped at one hour and stop after 10 attempts.
- Permanent identity/configuration errors stop rather than retry indefinitely.
- Every sync operation receives a trace ID and redacted diagnostic context.
- Local canonical persistence completes before asynchronous external work is queued.

## 14. Case and companion CRM integration

Evidence: `includes/class-didar-case-service.php`; `includes/class-didar-companion-model.php`; `includes/class-didar-sync-manager.php`; `includes/class-didar-admin.php`.

Case sync is supported for Visa Request and Embassy Appointment.

### 14.1 Configuration

- Primary option: `case_form_settings[$form_type]`.
- Legacy Visa fallback: `visa_companion_case_settings`.
- Each form can configure Case pipeline, initial stage, category, system fields, companion field mappings, and main-applicant mappings.
- Configuration readiness is reported as ready, stale, or incomplete.
- Duplicate system/mapping fields are detected.
- Case metadata is refreshed from Case pipelines and custom fields and is cached with last-good retention.

### 14.2 Synchronization model

1. The parent Deal must be durable before Case processing begins.
2. The main applicant Case is resolved/created first with `main_<submission_id>`.
3. Each meaningful companion row is resolved/created with its stable `cmp_<uuid>`.
4. Exact resolution uses the parent Deal plus configured Case system fields.
5. Case create omits `Id`; update includes `Id`.
6. Initial Case status is `InProgress`; optional category and configured field mappings are applied.
7. IDs and per-row states are stored in `_didar_main_applicant_case` and `_didar_companion_cases`.
8. If Case configuration is incomplete, Deal sync can remain successful while Case state remains pending.
9. Local companion removal marks the local link removed; remote Case deletion/archive is not attempted.

The 2026-09-13 handoff audit reported Visa pipeline/stage/system configuration present but Visa companion family-relation/age-group/main mappings missing, and Embassy Case configuration missing. The inventory intentionally does not reproduce or invent CRM IDs.

## 15. Webhooks and inbound synchronization

Evidence: `includes/class-didar-sync-manager.php` (`register_webhook_route`, `receive_webhook`, `apply_person_webhook`, `apply_deal_webhook`).

### 15.1 Routes

- `POST /wp-json/didar/v1/webhook/{64-hex-secret}` — current secret-path route.
- `POST /wp-json/didar/v1/webhook` — legacy header route, only when `didar_webhook_legacy_enabled` is enabled.
- Legacy authentication header: `x-didar-webhook-token`.
- The route permission callback is public, but the handler authenticates the secret/header before processing.

### 15.2 Validation and controls

- Requires JSON content type.
- Requires `meta.id`, `meta.entityId`, and `meta.entityTitle`.
- Rate limit: 120 requests per IP per 60 seconds.
- Deduplicates the most recent 500 webhook event IDs in option `didar_seen_webhooks`.
- Supports Deal and Person created/updated events only.
- Unknown/unsupported entity/action combinations return an unsupported response without local mutation.

### 15.3 Person webhook behavior

Only a linked WordPress user’s explicitly mapped `birth_date` is updated. The date is normalized/validated into canonical local storage. No mapping means no profile write; invalid dates preserve the existing local value; unmapped Persons are logged and ignored.

### 15.4 Deal webhook behavior

- Resolves local submissions by stored Deal ID or explicit local submission-ID system field.
- A remote deletion flag is logged as unsupported; the local submission is not deleted.
- Inbound Deal creation requires action type 1 plus mapped valid form type and WordPress user ID.
- Created Deals can create a local submission and link Deal/Person IDs.
- Updates can apply local snapshots for mapped scalar fields, public/internal workflow state, assignment, and event history under sync suppression.
- Structured field text is not parsed back into local structured arrays.
- Inbound Deal updates do not overwrite the WordPress profile.

## 16. Admin UI and operations

Evidence: `includes/class-didar-admin.php`; `includes/class-didar-access-control.php`; `assets/js/admin.js`.

### 16.1 Screens

The plugin has 2 plugin-owned admin pages beneath the submission CPT:

1. `didar-page-settings` — settings, per-form workflows, Case settings, and transfer controls.
2. `didar-diagnostics` — diagnostic logs, sync status, and operational information.

It also owns the normal WordPress CPT list/editor screens:

- `edit.php?post_type=didar_submission` — submission list, filters, assignment views, custom columns.
- `post.php?post={id}&action=edit` — submission editor and meta boxes.

Assigned-to-me and unassigned are list views/filter states, not separate admin pages.

### 16.2 Settings surface

The settings page exposes:

- Submission page IDs and page behavior.
- Form access links/barcodes.
- Frontend page size.
- Colleague internal-history visibility.
- Profile field states.
- Profile→Didar Person mappings.
- File download mode.
- PDF labels.
- Field required overrides.
- Field placeholders and profile/literal defaults.
- Form-to-CRM field mappings.
- API key, default owner, default pipeline, and debug logging.
- Webhook secret, rotation, and legacy-header toggle.
- System custom-field IDs for form type, WordPress submission ID, WordPress user ID, and public status.
- Per-form Deal workflow status/stage mappings.
- WordPress-to-Didar user mappings.
- Per-form default assignees.
- Didar metadata refresh controls.
- Independent per-form Case settings.

### 16.3 Settings transfer

`Didar_Settings_Transfer` implements the `ns-didar-settings` format, schema 1, with a 1 MB maximum input size.

Features:

- JSON export with plugin version, timestamp, informational site URL, and `api_credentials_included=false`.
- Preview before apply.
- Merge and replace modes.
- Allowlisted portable settings only.
- Unknown form/mapping preservation warnings where designed.
- WordPress-user descriptor export and login/email-based import resolution.
- Warnings for unresolved/stale Didar user mappings.
- Up to 5 portable backups in `didar_settings_import_backups`.
- Read-after-write verification.
- Automatic rollback when verification fails.
- Trace data covering parse, normalization, proposal, write, and verification.

The intended portable configuration categories are workflows, Case settings, field mappings, placeholders/defaults, form access, user mappings, owners/assignees, system-field mappings, required/profile states, history visibility, pagination, file mode, debug mode, and PDF labels. Credentials, webhook secret, runtime caches, live entity IDs, submissions, and files are excluded from export.

### 16.4 Submission editor

Meta boxes include:

- Form type.
- Form fields.
- Applicant note where supported.
- Customer/public workflow.
- Internal workflow.
- Activity/history.
- Ownership.
- Didar sync status.

The list screen adds submission ID, form type, user, public status, internal status, assignee, date, and last-updated columns, plus form/status/assignment filters, search, ordering, and manual sync row actions.

### 16.5 Diagnostics

Diagnostics provide redacted logs, level/form/operation/trace/local-ID filters, sync state visibility, pending counts, next scheduled run information, manual sync, pipeline/custom-field/user refresh, log clearing, and connection-test controls. Clearing logs is admin/capability protected.

## 17. AJAX, admin-post, REST, and extension points

### 17.1 Authenticated AJAX actions

Evidence: `includes/class-didar-ajax.php`.

All current plugin AJAX actions are authenticated `wp_ajax_` actions; no `wp_ajax_nopriv_` handlers are registered:

- `didar_upload_file`
- `didar_remove_file`
- `didar_upload_profile_document`
- `didar_remove_profile_document`
- `didar_get_form_fields`

They require login, nonces, scope/ownership checks, and file/registry validation as appropriate.

### 17.2 Admin-post actions

Administrative operations:

- `didar_test_connection`
- `didar_manual_sync`
- `didar_clear_logs`
- `didar_refresh_pipelines`
- `didar_settings_export`
- `didar_settings_import_preview`
- `didar_settings_import_apply`
- `didar_save_case_settings`
- `didar_rotate_webhook_secret`

File/PDF operations:

- `didar_download_file`
- `didar_download_pdf_file`
- `didar_download_pdf`

Unauthenticated variants explicitly deny secure file/PDF access.

### 17.3 Custom actions

The plugin publishes:

- `didar_submission_created`
- `didar_submission_updated`
- `didar_submission_workflow_changed`

These are used to queue centralized synchronization after local canonical persistence.

### 17.4 Custom filters

The only current plugin-defined extension family is the reference-data filter pattern:

`didar_{dataset}_for_{form_type}`

for the datasets supported by `Didar_Reference_Data::for_form()`.

### 17.5 WordPress hook registration inventory

In addition to the explicit AJAX/admin-post/custom hooks above, the current source registers these WordPress lifecycle and screen hooks:

- Bootstrap/lifecycle: `plugins_loaded`, `init`, `admin_init`, `admin_menu`, `admin_enqueue_scripts`, `admin_notices`, `network_admin_notices`, `wp_enqueue_scripts`, and `template_redirect`.
- CPT editor/list: `add_meta_boxes_didar_submission`, `save_post_didar_submission`, `manage_didar_submission_posts_columns`, `manage_edit-didar_submission_sortable_columns`, `manage_didar_submission_posts_custom_column`, `restrict_manage_posts`, `pre_get_posts`, `posts_clauses`, `post_row_actions`, `views_edit-didar_submission`, `option_page_capability_didar_page_settings`, and `redirect_post_location`.
- User/profile lifecycle: `user_register`, `register_new_user`, `added_user_meta`, and `updated_user_meta`.
- Deletion safety/audit: `pre_delete_post`, `pre_trash_post`, `transition_post_status`, and `before_delete_post`.
- CRM/webhook/worker lifecycle: `rest_api_init`, `cron_schedules`, `didar_process_sync`, `didar_process_user_sync`, `didar_cleanup_temporary_uploads`, `didar_backfill_last_updated`, `didar_submission_created`, `didar_submission_updated`, and `didar_submission_workflow_changed`.
- Settings/file coupling: `update_option_didar_settings`.

The list above reflects concrete registrations in `Didar_Plugin`, `Didar_Access_Control`, `Didar_Admin`, `Didar_Shortcodes`, `Didar_User_Profile`, `Didar_File_Service`, `Didar_Event_Log`, `Didar_Sync_Manager`, and `Didar_Ajax`. Dynamic registrations such as `admin_post_{action}`, `admin_post_nopriv_{action}`, and `add_meta_boxes_{post_type}` are listed with their resolved plugin values in the neighboring sections.

## 18. Background workers and scheduled behavior

Evidence: `includes/class-didar-plugin.php`; `includes/class-didar-sync-manager.php`; `includes/class-didar-event-log.php`; `includes/class-didar-file-service.php`.

| Hook | Schedule/trigger | Work |
|---|---|---|
| `didar_process_sync` | Every 5 minutes plus per-submission single events | Sweep/process up to 10 pending submission syncs; Person/Deal/Case orchestration. |
| `didar_process_user_sync` | Every 5 minutes plus per-user single events | Sweep/process up to 10 pending WordPress user→Person syncs. |
| `didar_cleanup_temporary_uploads` | Daily | Remove temporary file records/files older than 24 hours. |
| `didar_backfill_last_updated` | Batched one-off | Backfill meaningful event-derived last-updated timestamps, max 250 per batch. |

Additional behavior:

- Custom schedule name: `didar_every_five_minutes`.
- Queue operations avoid duplicate scheduled events.
- Durable post/user meta state is the source of truth if a single event is missed.
- `spawn_cron` is requested after queueing.
- Submission sync has a 120-second lock.
- User sync retries are capped at 10 attempts.
- Submission sync retry delay is bounded between one minute and one hour.

## 19. Security, privacy, and defensive behavior

Evidence: `includes/class-didar-access-control.php`; `class-didar-validator.php`; `class-didar-file-service.php`; `class-didar-logger.php`; `class-didar-sync-manager.php`; `class-didar-ajax.php`; `class-didar-pdf-service.php`.

- Private CPT is excluded from public queries and REST by default.
- Frontend and admin mutations use nonces.
- Capability and ownership/scope checks are performed server-side.
- Anonymous AJAX/file/PDF access is denied.
- Form honeypot is validated server-side.
- Input is definition-driven and allowlisted for choices, nested repeaters, files, dates, emails, national IDs, passport numbers, and numeric values.
- Conditional fields are validated according to active parent state.
- Province/city relationships are validated server-side.
- Files use private records, finalization states, MIME inspection, extension checks, size limits, unpredictable names, authorization, and response hardening.
- PDF file references are validated for membership/readability.
- Webhooks use secret-path or optional legacy-header authentication, JSON checks, required metadata, IP rate limiting, and event deduplication.
- External identity resolution is exact; the plugin does not invent CRM IDs or use fuzzy title/phone guesses for Deal linking.
- Remote deletion is not attempted for local trash/delete or inbound deletion flags.
- Logs redact API keys, tokens, secrets, auth/cookies, passwords, credentials, file content, applicant data, and shared notes; structured contact payloads are summarized rather than copied.
- Diagnostic logs cap message/context size at 5,000 units/characters as implemented by the logger.
- PDF output omits internal IDs/internal metadata and removes temporary output.
- Settings import has a format/schema/size allowlist and rollback verification.

## 20. Search, filters, and list queries

Evidence: `includes/class-didar-request-search.php`; `includes/class-didar-shortcodes.php`; `includes/class-didar-admin.php`.

### 20.1 Request search

- Query variable: `didar_request_search`.
- Maximum search term length: 100.
- `posts_search` extension supports post title, serialized `_didar_fields` values, exact numeric submission ID, and `#ID` style lookup.
- Search is applied to scoped request lists rather than exposing the CPT publicly.

### 20.2 Frontend list filters

`[didar_submissions]` supports form type, status, search, and pagination. Scope is applied before display. Multiple instances use suffixed GET variables to avoid collisions.

### 20.3 Admin list filters

The CPT list supports form type, public status, internal status/assignment states, assigned-to-me, unassigned, search, custom columns, last-updated ordering, and request row actions.

## 21. Configuration, options, metadata, and persistence index

This is an index of notable plugin-owned persistence keys. It is not a dump of live values.

### 21.1 Primary option and configuration groups

- `didar_settings` — API, workflow, mapping, profile, frontend, file, PDF, webhook, debug, user/owner, and operational settings.
- `didar_submission_pages` — configured frontend page IDs and submission-page behavior.
- `didar_form_access` — form access URLs, link text, and barcode references.
- `didar_form_workflows` — per-form Deal pipeline/status/stage definitions.
- `case_form_settings` — per-form Case configuration.
- `visa_companion_case_settings` — legacy Visa Case configuration fallback.
- `didar_field_mappings` — form field → Person/Deal mapping configuration.
- `didar_form_field_defaults` — profile-source and literal choice defaults.
- `didar_form_field_placeholders` — UI placeholders.
- `field_required_overrides` — per-form required-state overrides.
- `profile_field_states` — editable/readonly/disabled profile policy.
- `didar_user_person_mappings` — profile-to-Person mapping policy.
- `didar_broker_user_map` — local eligible operator → Didar UserId mapping.
- `didar_form_default_assignees` — per-form default WordPress assignee mapping.
- `didar_default_owner_id`, `didar_default_pipeline_id` — default CRM owner/pipeline settings.
- `didar_system_form_type_field_id`, `didar_system_submission_id_field_id`, `didar_system_user_id_field_id`, `didar_public_status_field_id` — configured CRM system custom-field keys.
- `colleague_can_view_internal_history` — colleague history policy.
- `frontend_requests_per_page` — frontend page size, bounded 1–100.
- `file_download_mode` — `secure` or `direct`, default `secure`.
- `didar_debug_logging` — `off`, `errors`, or `verbose`.
- `pdf_settings` — with-files/without-files labels.

### 21.2 Runtime/cache options

- `didar_schema_state`, `didar_schema_last_error`.
- `didar_file_schema_version`, `didar_file_schema_verified_version`.
- `didar_event_schema_version`, `didar_event_schema_verified_version`, and backfill state.
- `didar_diagnostic_log_schema`.
- `didar_access_version`.
- `didar_deal_pipeline_cache`.
- `didar_case_pipeline_cache` and `didar_case_custom_field_cache`.
- `didar_custom_field_cache`.
- `didar_user_cache`.
- `didar_seen_webhooks`.
- `didar_settings_import_backups` and preview options prefixed `didar_settings_import_preview_`.
- Per-submission lock options prefixed `didar_submission_sync_lock_`.

### 21.3 User metadata

- `_didar_person_id` — Didar Person mapping.
- `_didar_person_sync_state` — queued/retry state.
- `_didar_birth_date` — canonical profile birth date.
- `_didar_national_id` — normalized profile national ID.
- `_didar_profile_documents` — reusable private profile document references.
- `profile_image` — optional native WordPress attachment ID.
- Native/third-party source values such as `first_name`, `last_name`, `nickname`, `gender`, `digits_phone`, `digits_phone_no`, and `digt_countrycode` are read according to the profile/mapper rules.

## 22. Complete PHP class inventory

| File/class | Primary responsibility |
|---|---|
| `class-didar-plugin.php` / `Didar_Plugin` | Bootstrap, dependency wiring, activation/deactivation, worker scheduling. |
| `class-didar-post-type.php` / `Didar_Post_Type` | Private submission CPT registration. |
| `class-didar-schema-manager.php` / `Didar_Schema_Manager` | Schema installation, verification, repair, notices. |
| `class-didar-access-control.php` / `Didar_Access_Control` | Roles, capabilities, admin restrictions, scope gates. |
| `class-didar-form-registry.php` / `Didar_Form_Registry` | Active fixed-form definitions and supported mapping fields. |
| `class-didar-reference-data.php` / `Didar_Reference_Data` | Countries, geography, choices, occupations, statuses, extension filters. |
| `class-didar-validator.php` / `Didar_Validator` | Definition-driven server validation and sanitization. |
| `class-didar-field-renderer.php` / `Didar_Field_Renderer` | Frontend/admin field, section, repeater, searchable, date, and file rendering. |
| `class-didar-submission-service.php` / `Didar_Submission_Service` | Create, update, access, workflow, notes, ownership, events, local lifecycle. |
| `class-didar-shortcodes.php` / `Didar_Shortcodes` | Five public submission/form shortcodes and template redirects. |
| `class-didar-user-profile.php` / `Didar_User_Profile` | Profile shortcode, profile updates, profile image/doc UI. |
| `class-didar-user-profile-value-catalog.php` / `Didar_User_Profile_Value_Catalog` | Profile source catalog and canonical keys. |
| `class-didar-profile-document-catalog.php` / `Didar_Profile_Document_Catalog` | Five reusable private profile-document definitions. |
| `class-didar-form-access.php` / `Didar_Form_Access` | Form access-link/barcode settings and validation. |
| `class-didar-companion-model.php` / `Didar_Companion_Model` | Companion row normalization, UID, age-group, count, main-applicant model. |
| `class-didar-date-service.php` / `Didar_Date_Service` | Jalali/Gregorian conversion and digit normalization. |
| `class-didar-readable-value-serializer.php` / `Didar_Readable_Value_Serializer` | Choice/repeater/file/date readable values for CRM/PDF. |
| `class-didar-file-service.php` / `Didar_File_Service` | Private file schema, upload, finalization, authorization, downloads, cleanup. |
| `class-didar-pdf-service.php` / `Didar_Pdf_Service` | Authorized RTL PDF generation and download. |
| `class-didar-event-log.php` / `Didar_Event_Log` | Append-only event history and last-updated backfill. |
| `class-didar-logger.php` / `Didar_Logger` | Redacted diagnostic logging, filtering, clearing, trace context. |
| `class-didar-api-client.php` / `Didar_Api_Client` | Didar HTTP client and endpoint wrappers. |
| `class-didar-field-mapper.php` / `Didar_Field_Mapper` | Person, Deal, and Case payload mapping. |
| `class-didar-custom-field-catalog.php` / `Didar_Custom_Field_Catalog` | Deal custom-field metadata/cache/availability. |
| `class-didar-user-catalog.php` / `Didar_User_Catalog` | Didar User metadata/cache. |
| `class-didar-user-identity.php` / `Didar_User_Identity` | Admin/agent/coworker/customer display identity. |
| `class-didar-workflow-manager.php` / `Didar_Workflow_Manager` | Deal workflow cache, per-form statuses/stages, validation. |
| `class-didar-case-service.php` / `Didar_Case_Service` | Case metadata, configuration readiness, Case API wrappers. |
| `class-didar-sync-manager.php` / `Didar_Sync_Manager` | Queues, workers, Person/Deal/Case sync, webhooks, deletion guards. |
| `class-didar-settings.php` / `Didar_Settings` | Central settings/defaults/secret/date/file/PDF/profile policy. |
| `class-didar-settings-transfer.php` / `Didar_Settings_Transfer` | Portable export, preview, merge/replace, verify, rollback. |
| `class-didar-request-search.php` / `Didar_Request_Search` | Controlled serialized-field/CPT search. |
| `class-didar-ajax.php` / `Didar_Ajax` | Authenticated upload/remove/dynamic-field AJAX handlers. |
| `class-didar-admin.php` / `Didar_Admin` | Admin pages, settings, meta boxes, list UI, operations, diagnostics. |

## 23. Documentation-vs-code discrepancies and implementation caveats

These are evidence-backed observations from the current tree. They are listed so future maintenance does not mistake an intended state for a currently guaranteed one.

### 23.1 Applicant-note settings sanitizer — fixed

`Didar_Admin::sanitize_didar_settings()` now iterates the authoritative Form Registry and handles `applicant_note` only when `Didar_Form_Registry::supports_applicant_note()` returns true. Valid Deal custom-field validation and pipeline-availability checks are unchanged. Consultation and Complaint/Suggestion mappings now persist alongside Embassy Appointment, Traveler Evaluation, and Visa Request; unknown form types do not gain support.

### 23.2 Settings-transfer export for `didar_system_user_id_field_id` — fixed

`didar_system_user_id_field_id` is included in `portable_option_keys()`, matching the existing defaults, normalization, merge/replace proposal, and read-after-write verification paths. A normal export/import round trip now retains this system-field mapping without expanding the allowlist to credentials, secrets, runtime caches, or entity IDs.

### 23.3 Plugin header/constant version mismatch — KNOWN / ACCEPTED — NO FIX REQUESTED

`didar.php` declares plugin header version `1.7.3` while `DIDAR_VERSION` is `1.7.4`. This discrepancy is accepted by the user for the current state. No version value, migration, activation, schema, or release behavior was changed for it in this work.

### 23.4 PDF-with-files vs secure storage — compatibility caveat

The PDF “with files” path uses direct file URLs even when the configured file mode is secure. This may make file embedding fail under secure protection. It is a behavior/configuration compatibility caveat, not a claim that the underlying file authorization checks are absent.

### 23.5 Historical documentation drift

The current `docs/README.md` identifies these as stale/superseded:

- `docs/03-forms-and-submissions.md` incorrectly limits `applicant_note` to three forms.
- `docs/12-settings.md` lacks the current per-form Case settings model.
- `docs/20-developer-guide.md` has superseded workflow/default guidance.
- `docs/21-companion-cases.md` describes the older Visa-only Case model; current source supports Visa, Embassy, and main-applicant Cases.

`FINAL-DIDAR-AUDIT-SOURCE-OF-TRUTH.md` is historical (2026-09-01), while `docs/PROJECT-HANDOFF.md` is the current handoff. The current handoff’s statement that the old three-form applicant-note stripping was removed now matches the Settings API sanitizer.

### 23.6 Test/runtime verification boundary

All 34 plugin PHP class files pass syntax validation. The repository contains 20 PHP/JavaScript test files, but the test README requires an external WordPress PHPUnit environment. The current handoff records that full global PHPUnit is incompatible with PHP 8.2 because of legacy `each()` usage and that local browser QA was unavailable. This document therefore reports source/static evidence, not a claim of complete runtime QA.

## 24. Historical implementation timeline

Evidence: plugin Git history and dated documentation in `docs/changed/`.

| Date | Evidence/milestone |
|---|---|
| 2026-08-19 | Event-sourcing foundation appears in history. |
| 2026-08-20 | Initial file-upload and search/filter work appears. |
| 2026-08-21 | First and second operational versions; upload safety and database repair work appear. |
| 2026-08-22 | Didar synchronization stage 2 begins. |
| 2026-08-23 | Custom-field synchronization fixes and Person-detail work. |
| 2026-08-24 | Per-form pipeline/stage configuration begins. |
| 2026-08-25 | Custom-field and user synchronization automation. |
| 2026-08-26 | Webhook/import-settings/profile-edit work appears. |
| 2026-08-27 | Production/deployed v1 milestones and profile edit enhancement. |
| 2026-08-28 | Documentation batch added. |
| 2026-08-29 | Jalali datepicker, profile prefill, and Didar settings work added. |
| 2026-08-30 | Further corrections appear before the final implementation snapshot. |
| 2026-09-01 | Pre-QA modifications; historical audit source-of-truth date. |
| 2026-09-02 | `HEAD` commit `b5f127d` (`final implemention v1`). |
| 2026-09-13 | Current handoff refresh documents the partly uncommitted Phase 2–8 upgrade state, companion Cases, files, PDF, searchable selects, Visa conditional lifecycle, and settings transfer. |
| 2026-09-14 | Initial complete source inventory created as documentation only; subsequent targeted settings fixes updated the applicant-note Settings API sanitizer, portable system-user-ID export allowlist, focused tests, and this inventory. |

## 25. Master feature matrix

| Feature area | Current implementation | Primary evidence |
|---|---|---|
| Bootstrap | Plugin constants, loader, activation/deactivation, service wiring | `didar.php`; `Didar_Plugin` |
| Submission entity | Private `didar_submission` CPT | `Didar_Post_Type` |
| Database safety | Versioned install/verify/repair | `Didar_Schema_Manager` |
| Roles | Colleague and broker roles | `Didar_Access_Control` |
| Capabilities | Submission, workflow, owner, settings gates | `Didar_Access_Control`; `Didar_Submission_Service` |
| Fixed forms | Five registry-driven forms | `Didar_Form_Registry` |
| Applicant note | Registry, mapping, and render support for all five active forms; Settings API sanitizer follows the registry capability | `Didar_Form_Registry`; `Didar_Admin`; `Didar_Settings_Transfer` |
| Reference data | Countries, Iran geography, choices, occupations, statuses | `Didar_Reference_Data` |
| Validation | Definition-driven server validation | `Didar_Validator` |
| Rendering | RTL sections, fields, repeaters, files, searchable inputs | `Didar_Field_Renderer` |
| Jalali dates | Persian UI, Gregorian canonical storage, fallback engine | `Didar_Date_Service`; datepicker |
| Companions | Visa/Embassy nested rows, stable UIDs, age groups | `Didar_Companion_Model` |
| Profile | Editable/readonly profile policy, image, private docs | `Didar_User_Profile`; profile catalog |
| Submission lifecycle | Create/update/access/notes/ownership/events | `Didar_Submission_Service` |
| Frontend forms | Login-gated submit/list/detail/edit flows | `Didar_Shortcodes` |
| Admin UI | Settings, diagnostics, editor boxes, list filters | `Didar_Admin` |
| File storage | Private DB-backed records and references | `Didar_File_Service` |
| File security | MIME/size/nonce/capability/finalization/cleanup | `Didar_File_Service` |
| PDF | RTL Persian A4 PDF with optional file view | `Didar_Pdf_Service` |
| Public workflow | Four reference statuses | Registry/reference/service |
| Internal workflow | Per-form CRM pipeline/stage/status mappings | `Didar_Workflow_Manager` |
| Event history | Append-only audit/event table | `Didar_Event_Log` |
| Diagnostics | Redacted levels, filters, trace IDs | `Didar_Logger` |
| Person sync | Exact identity mapping and queued user sync | `Didar_Sync_Manager`; `Didar_Field_Mapper` |
| Deal sync | One submission → one exact-resolved Deal | `Didar_Sync_Manager` |
| Case sync | Main-applicant and companion Cases for Visa/Embassy | `Didar_Case_Service`; `Didar_Sync_Manager` |
| Inbound webhooks | Secret path, optional legacy header, Deal/Person apply | `Didar_Sync_Manager` |
| Background work | 5-minute sync, daily cleanup, backfill, retry/locks | `Didar_Sync_Manager`; `Didar_File_Service`; `Didar_Event_Log` |
| Search | Scoped title/meta/exact-ID search | `Didar_Request_Search` |
| Settings transfer | Preview, merge/replace, backup, verify/rollback | `Didar_Settings_Transfer` |
| AJAX | Authenticated uploads, profile docs, dynamic fields | `Didar_Ajax` |
| Extensibility | Reference-data filters and custom actions | `Didar_Reference_Data`; plugin hooks |

## 26. Final inventory counts

| Count | Value |
|---|---:|
| Active forms | 5 |
| Active registry top-level fields | 148 |
| Mapping fields including one applicant note per form | 153 |
| Unique active field keys | 112 |
| Active file field definitions | 6 |
| Repeater definitions | 3 |
| Conditional Visa fields | 7 |
| Derived companion-count fields | 2 |
| Companion model columns | 14 |
| Reference public statuses | 4 |
| PHP class files under `includes/` | 34 |
| Plugin assets | 8 (5 JS, 3 CSS) |
| PHP/JavaScript test files | 20 |
| Plugin shortcodes | 6 |
| Plugin-owned admin pages | 2 |
| CPT admin screens | 2 (list/editor) |
| Authenticated AJAX actions | 5 |
| Custom REST webhook routes | 2 |
| Distinct documented Didar API paths | 13 |
| Current custom action hooks | 3 |
| Reference-data filter families | 4 dataset families |

## 27. Change boundary

### Initial inventory audit

The initial inventory audit added `docs/FULL-FEATURE-INVENTORY.md` as documentation only. That audit did not modify runtime PHP, JavaScript, CSS, tests, configuration, database data, WordPress submissions, files, CRM entities, webhooks, or credentials.

### Subsequent targeted fixes

Later targeted fixes modified `includes/class-didar-admin.php`, `includes/class-didar-settings-transfer.php`, their focused regression tests, and this inventory:

- The Settings API sanitizer now persists valid `applicant_note` mappings for every form supported by `Didar_Form_Registry::supports_applicant_note()`.
- Portable Settings Transfer now exports and restores `didar_system_user_id_field_id` through its existing merge, replace, verification, backup, and rollback paths.

Those fixes did modify runtime PHP and test source; this document does not claim otherwise. They did not modify WordPress submission data, WordPress users, CRM Person/Deal/Case data, credentials, webhook secrets, or production settings. No production CRM calls were required. This follow-up consistency cleanup modifies documentation only.
