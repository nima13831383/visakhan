# ns-didar Admin Settings Persistence Audit

**Date:** 2026-09-14
**Environment:** local WordPress site at `http://localhost/visa/`
**Scope:** admin settings persistence only. No Didar request, form submission, Person, Deal, Case, or CRM object was created.

## Result

The Visa main applicant Case mappings now persist through the real local admin form using the six configured Case-field targets:

```text
full_name       → Field_8785_0_261
occupation      → Field_8785_0_263
national_id     → Field_8785_0_264
passport_number → Field_8785_0_265
email           → Field_8785_0_266
phone           → Field_8785_0_267
```

They use the same canonical per-form namespace at every boundary:

```text
case_form_settings[visa_request]
→ didar_case_settings[case_form_settings][visa_request]
→ Didar_Admin::prepare_case_settings_save()
→ Didar_Admin::sanitize_case_form_config()
→ didar_settings.case_form_settings.visa_request
```

The legacy `visa_companion_case_settings` object remains a mirrored Visa compatibility value. Embassy remains independently stored under `case_form_settings.embassy_appointment`.

## Root cause

The dedicated Case UI and the Case save path had drifted apart. The Visa main-applicant controls were rendered from the canonical `case_form_settings[visa_request]` model, while the save path still treated the legacy `visa_companion_case_settings` object as the Visa input owner. The broad settings sanitizer also rebuilt Case data from partial input, so a partial or incorrectly namespaced payload could drop main mappings and other Case mappings.

The remaining persistence defect was a second boundary: `sanitize_case_form_config()` built one global duplicate-target set from companion mappings, main-applicant mappings, and system mappings. Because the real Visa main-applicant targets are intentionally reused by the companion Case, that global set discarded each main mapping as a duplicate during save. Runtime validation used the same incorrect global scope.

The fix makes the canonical Visa object the owner, accepts the legacy object only as a compatibility fallback, sanitizes Visa and Embassy independently, mirrors the sanitized Visa object to the legacy key, and merges only posted mapping keys. Duplicate validation is now scoped to the payload that will be built: companion mappings plus system mappings, or main-applicant mappings plus system mappings. Existing valid mappings are retained when an invalid or duplicate target is posted; an explicit blank still removes that mapping. An unchanged legacy duplicate between a business mapping and its corresponding system mapping is also preserved, so a no-op save cannot delete an existing Case configuration while the uniqueness guard remains active for new same-payload conflicts.

## Why the previous QA was a false pass

The earlier browser pass used one available cached Case-field target per main-applicant field instead of the real six configured targets. It proved that the controls could submit and reload nonempty values, but it did not prove that the production mappings could coexist with the companion mappings. The corrected acceptance pass posted the real targets together, reloaded the form twice, and verified that each selected value remained present. `case_role` remains blank because the current local Case-field catalog has no dedicated valid target for it; no ID was invented.

The audit also found an incomplete working-tree country-catalog wiring change: the registry used an undefined archived-country option variable, and the catalog class was not loaded before reference data. The missing variable assignment and plugin include were completed so CLI/runtime checks load the current source without a fatal error. The country data itself was not redesigned or reverted.

## Settings forms and boundaries

| Form | Action | Owner and save path | Named controls | Successful controls in FormData | Disabled | Nested forms |
|---|---|---|---:|---:|---:|---:|
| Global settings | `options.php`, `option_page=didar_page_settings` | WordPress Settings API; `sanitize_page_settings()` and `sanitize_didar_settings()` | 891 | 873 unique names | 0 | 0 |
| Case settings | `admin-post.php?action=didar_save_case_settings` | `save_case_settings()` → `prepare_case_settings_save()` | 58 | 56 unique names | 0 | 0 |
| Export | `admin-post.php?action=didar_settings_export` | Read-only export service | 3 | 3 | 0 | 0 |
| Import preview | `admin-post.php?action=didar_settings_import_preview` | Parse/preview service; no option write | 6 | 5 | 0 | 0 |
| Import apply | `admin-post.php?action=didar_settings_import_apply` | Conditional form shown after a valid preview; transfer service writes `didar_settings` | Conditional | Conditional | 0 | 0 |

The six named-control difference in the global form is expected form metadata, submit controls, and duplicate radio names. Duplicate names are the shared `file_download_mode` radio group and one workflow default radio group per active form. The page also contains duplicate generic `_wpnonce` IDs and IDs from unrelated consent/admin-menu overlays; no plugin-owned settings control ID is duplicated. Every successful editable control name in the rendered global and Case forms was present in FormData.

## Global settings matrix

The global form owns the following controls. Repeated field rows are listed by their exact name templates and complete current registry key lists.

| Section | Label/control | Input name | Storage path | Sanitizer / reload renderer |
|---|---|---|---|---|
| Submission pages | Details page; edit page | `didar_submission_pages[details_page_id]`; `didar_submission_pages[edit_page_id]` | `didar_submission_pages.details_page_id`; `didar_submission_pages.edit_page_id` | `sanitize_page_settings()` / page selectors |
| Form access | Form URL and barcode URL, five active forms | `didar_settings[didar_form_access][<form>][url]`; `[barcode]` | `didar_settings.didar_form_access.<form>.url`; `.barcode` | `sanitize_form_access_settings()` / form-access renderer |
| Form access | Media attachment state | `didar_settings[didar_form_access][<form>][barcode_attachment_id]` | Not persisted; used to resolve and persist the barcode URL | `sanitize_form_access_settings()` |
| Behavior | Colleague internal-history access | `didar_settings[colleague_can_view_internal_history]` | `didar_settings.colleague_can_view_internal_history` | `sanitize_didar_settings()` / checkbox |
| Behavior | Requests per page | `didar_settings[frontend_requests_per_page]` | `didar_settings.frontend_requests_per_page` | `sanitize_didar_settings()` clamps to 1–100 / number input |
| Files | File download mode | `didar_settings[file_download_mode]` | `didar_settings.file_download_mode` | `sanitize_didar_settings()` / radio group |
| PDF | Print with files text; print without files text | `didar_settings[pdf_settings][print_with_files_text]`; `[print_without_files_text]` | `didar_settings.pdf_settings.*` | `Didar_Settings::normalize_pdf_settings()` / text inputs |
| Profile | Field state | `didar_settings[profile_field_states][<field>]` | `didar_settings.profile_field_states.<field>` | `sanitize_didar_settings()` / nine state selects |
| Profile | User-to-Person mappings | `didar_settings[didar_user_person_mappings][<property>]` | `didar_settings.didar_user_person_mappings.<property>` | `sanitize_didar_settings()` / five text inputs |
| Connection | API key | `didar_settings[didar_api_key]` | `didar_settings.didar_api_key` | Blank input preserves the saved credential / password input |
| Connection | Default Didar owner | `didar_settings[didar_default_owner_id]` | `didar_settings.didar_default_owner_id` | `normalize_didar_user_mapping()` / select |
| Connection | Default Deal pipeline | `didar_settings[didar_default_pipeline_id]` | `didar_settings.didar_default_pipeline_id` | `sanitize_didar_settings()` / text input |
| Connection | Legacy webhook switch | `didar_settings[didar_webhook_legacy_enabled]` | `didar_settings.didar_webhook_legacy_enabled` | `sanitize_didar_settings()` / checkbox |
| Connection | Debug logging | `didar_settings[didar_debug_logging]` | `didar_settings.didar_debug_logging` | `sanitize_didar_settings()` / select |
| Connection | Deal system field: form type; submission ID; WordPress user ID | `didar_settings[didar_system_form_type_field_id]`; `[didar_system_submission_id_field_id]`; `[didar_system_user_id_field_id]` | Matching `didar_settings` keys | Cached Deal-field validation / select controls |
| Workflow | Public status field | `didar_settings[didar_public_status_field_id]` | `didar_settings.didar_public_status_field_id` | Cached Deal-field validation / text input |
| Workflow | Per-form pipeline | `didar_settings[didar_form_workflows][<form>][pipeline_id]` | `didar_settings.didar_form_workflows.<form>.pipeline_id` | `validate_workflows()` / select |
| Workflow | Status label, key, stage, default, order | `didar_settings[didar_form_workflows][<form>][statuses][<index>][label|key|stage_id|is_default|order]` | `didar_settings.didar_form_workflows.<form>.statuses` | `validate_workflows()` / dynamic workflow rows |
| Workflow | Default status radio | `didar_settings[didar_form_workflows][<form>][default]` | Normalized status `is_default` values | `validate_workflows()` and hidden mirror |
| Workflow | WordPress-to-Didar user map | `didar_settings[didar_broker_user_map][<wp_user_id>]` | `didar_settings.didar_broker_user_map.<wp_user_id>` | `normalize_didar_user_mapping()` / select |
| Assignment | Default assignee, five active forms | `didar_settings[didar_form_default_assignees][<form>]` | `didar_settings.didar_form_default_assignees.<form>` | Eligibility validation / select |

Read-only connection controls are intentionally excluded from the editable matrix: the generated webhook URL, webhook secret description, and date-engine status have no editable value in the global POST. The webhook rotate action has its own nonce-protected admin-post handler and is separate from settings save.

## Repeated form-field matrix

The rendered global form exposes these editable field settings for each active form. Each listed field has a mapping target/field pair, a default-value control, and a required toggle; placeholder controls are present only where the field supports one. Internal and honeypot fields are excluded from the editable inventory.

| Form | Editable field count | Exact field keys |
|---|---:|---|
| consultation | 9 | `first_name`, `last_name`, `input_3`, `email`, `input_5`, `description`, `preferred_date`, `preferred_time`, `applicant_note` |
| embassy_appointment | 26 | `request_for`, `country`, `service_type`, `profession`, `appointment_date`, `urgency`, `first_name`, `last_name`, `mobile`, `email`, `current_nationality`, `passport_number`, `birth_country`, `birth_province`, `birth_city`, `birth_place`, `gender`, `passport_issue_place`, `father_name`, `mother_name`, `birth_date`, `personal_photo`, `passport_main_page`, `companions_count`, `companions`, `applicant_note` |
| traveler_evaluation | 60 | `evaluation_date`, `first_name`, `last_name`, `mobile`, `email`, `mother_name`, `father_name`, `former_names`, `nationality`, `birth_date`, `birth_place`, `gender`, `marital_status`, `children_count`, `children_ages`, `national_id`, `passport_type`, `passport_number`, `passport_issue_date`, `passport_expiry_date`, `passport_issuer_country`, `eu_family_relation`, `home_address`, `postal_code`, `home_phone`, `secondary_email`, `other_residency_passport`, `current_job`, `work_address`, `employer_name`, `work_postal_code`, `employer_phone`, `employer_email`, `travel_purpose`, `travel_purpose_details`, `main_destination_country`, `first_entry_country`, `requested_entries`, `schengen_first_entry`, `schengen_first_exit`, `previous_fingerprints`, `previous_visa_number`, `last_visa_valid_from`, `last_visa_valid_to`, `final_country_entry_permit_issuer`, `entry_permit_valid_from`, `entry_permit_valid_to`, `has_invitation`, `host_or_hotel_name`, `host_or_hotel_address`, `host_or_hotel_phone`, `host_or_hotel_email`, `travel_funding`, `self_funding_methods`, `sponsor_funding_methods`, `financial_accounts`, `rial_balance`, `foreign_currency_balance`, `property_deeds_count`, `applicant_note` |
| complaint_suggestion | 7 | `date`, `first_name`, `last_name`, `mobile`, `subject`, `message`, `applicant_note` |
| visa_request | 49 | `request_for`, `first_name`, `last_name`, `birth_surname`, `birth_date`, `birth_province`, `birth_city`, `birth_place`, `birth_country`, `current_nationality`, `birth_nationality`, `national_id`, `marital_status`, `mobile`, `email`, `residential_address`, `postal_code`, `academic_level`, `invitation_type`, `passport_number`, `passport_expiry`, `passport_issuer_country`, `travel_destination`, `personal_photo`, `passport_main_page`, `round_trip_ticket`, `other_documents`, `account_balance`, `six_month_turnover`, `has_foreign_currency_account`, `has_property_deed`, `passive_income`, `employment_type`, `occupation`, `workplace_name`, `job_title`, `employment_documents`, `has_rejection`, `rejection_embassy`, `rejection_date`, `has_previous_schengen`, `previous_schengen_country`, `previous_schengen_date`, `estimated_travel_date`, `estimated_travel_end_date`, `schengen_exit_place`, `companions_count`, `companions`, `applicant_note` |

The rendered counts were: 151 required controls, 302 mapping controls representing 151 logical mapping rows, 151 default controls, and 98 placeholder controls. The registry retains internal fields such as the Embassy honeypot metadata, but they are not editable settings controls.

## Case settings matrix

The Case form is a separate admin-post form with 58 named controls and 56 successful unique names. It has independent pipeline, stage, category, companion mapping, system mapping, and main-applicant mapping groups for Visa and Embassy. The Visa main-applicant group contains exactly:

```text
full_name, occupation, national_id, passport_number, email, phone, case_role
```

The exact Case input templates are:

| Form area | Exact input names | Storage path | Save handler / reload renderer |
|---|---|---|---|
| Visa pipeline/stage/category | `didar_case_settings[case_form_settings][visa_request][pipeline_id]`; `[initial_stage_id]`; `[category_id]` | `didar_settings.case_form_settings.visa_request.pipeline_id|initial_stage_id|category_id` plus the mirrored legacy Visa object | `save_case_settings()` → `prepare_case_settings_save()` → `sanitize_case_form_config()` / `render_case_companion_settings()` |
| Visa companion mappings | `didar_case_settings[case_form_settings][visa_request][field_mappings][<companion_key>]` for `companion_uid`, `full_name`, `family_relation`, `age`, `age_group`, `occupation`, `national_id`, `passport_number`, `email`, `phone`, `personal_photo`, `passport_main_page`, `round_trip_ticket`, `other_documents` | `didar_settings.case_form_settings.visa_request.field_mappings.<companion_key>` | Same Case sanitizer / Visa Case block |
| Visa main-applicant mappings | `didar_case_settings[case_form_settings][visa_request][main_field_mappings][full_name|occupation|national_id|passport_number|email|phone|case_role]` | `didar_settings.case_form_settings.visa_request.main_field_mappings.<key>` plus the mirrored legacy Visa object | Same Case sanitizer / Visa main-applicant table |
| Visa system mappings | `didar_case_settings[case_form_settings][visa_request][system_fields][submission_id|companion_uid|form_type]` | `didar_settings.case_form_settings.visa_request.system_fields.<key>` plus the mirrored legacy Visa object | Same Case sanitizer / Visa system table |
| Embassy pipeline/stage/category | `didar_case_settings[case_form_settings][embassy_appointment][pipeline_id]`; `[initial_stage_id]`; `[category_id]` | `didar_settings.case_form_settings.embassy_appointment.*` | Same Case save/sanitizer / Embassy Case block |
| Embassy companion mappings | `didar_case_settings[case_form_settings][embassy_appointment][field_mappings][<companion_key>]` using the same 14 keys above | `didar_settings.case_form_settings.embassy_appointment.field_mappings.<companion_key>` | Same Case sanitizer / Embassy Case block |
| Embassy main-applicant mappings | `didar_case_settings[case_form_settings][embassy_appointment][main_field_mappings][full_name|occupation|national_id|passport_number|email|phone|case_role]` | `didar_settings.case_form_settings.embassy_appointment.main_field_mappings.<key>` | Same Case sanitizer / Embassy main-applicant table |
| Embassy system mappings | `didar_case_settings[case_form_settings][embassy_appointment][system_fields][submission_id|companion_uid|form_type]` | `didar_settings.case_form_settings.embassy_appointment.system_fields.<key>` | Same Case sanitizer / Embassy system table |

The angle-bracket suffixes above are placeholders for the literal keys shown in the row; they are not submitted as literal bracket characters.

Companion mappings, Case pipeline/stage/category values, and the existing Visa compatibility object are sanitized by the Case-specific path. A Case save preserves unrelated global settings. A checkbox is validated with normal HTML semantics: unchecked controls are absent from POST and are normalized to false; falsy numeric input is clamped by the existing numeric sanitizer.

## Applicant-note and merge rules

`applicant_note` is an editable Deal mapping on all five active forms. The global sanitizer now preserves the current per-form mapping when a partial settings request omits that control, removes it only for an explicit blank, and accepts a valid posted target through the existing cached-field validation path. Case data follows the same partial merge rule for posted mapping keys, so omitted mappings are not erased by a scoped save.

## Audit findings and regression coverage

The browser inventory found no nested forms, disabled editable controls, or multiple-select settings controls. No plugin-owned settings IDs were duplicated. The global and Case FormData checks found zero missing successful names. The six configured Visa main-applicant mappings passed one real local browser POST/save/reload #1/reload #2 cycle together using the exact cached local Case-field targets listed above; values were not invented or sent to Didar. The main-applicant and companion groups were allowed to reuse those targets because they produce separate Case payloads. A Visa companion no-op save preserved all 14 companion and all 3 system mappings, including the existing duplicate `companion_uid` target.

The local transfer smoke exported settings, previewed them, and applied the payload without an API call. It reported five applicant-note mappings, preserved the Case groups, and excluded runtime-only IDs/caches. Existing transfer tests cover the canonical Visa and Embassy Case groups, all five applicant-note mappings, Case round-trip behavior, and runtime metadata exclusions. Case persistence tests cover Visa/Embassy scoped saves, global-group preservation, partial/falsy inputs, and applicant-note preservation/removal.

## Validation and limitations

| Check | Result |
|---|---|
| PHP lint | Pass for all changed PHP and regression-test files |
| JavaScript syntax | Pass for `assets/js/admin.js` and `assets/js/form-input-rules.js`; neither changed in this task |
| `git diff --check` | Pass |
| Direct runtime smoke | Pass: plugin boot, Settings API load, Case sanitizer, falsy checkbox/numeric behavior, applicant-note partial merge |
| Browser settings inventory | Pass: local authenticated admin page, global/Case boundaries, FormData coverage, no-op Embassy save, global save, Case save preservation |
| Browser Visa main mappings | Pass: six real targets posted together and remained selected after save, reload #1, and reload #2; `case_role` remained intentionally unmapped |
| Cross-group target reuse | Pass: main and companion mappings may reuse real targets; same-payload duplicate and system collisions remain rejected |
| Case payload construction | Pass: main and companion serializers place same-target source values into their separate Case payloads |
| PHPUnit | Blocked by the repository's PHP 8-incompatible bundled PHPUnit calling removed `each()`; no plugin assertion ran |
| Didar/API activity | None |

The PHPUnit limitation is environmental. The same sanitizer and transfer paths were exercised through the plugin's local PHP runtime, and the settings UI was verified through the actual browser page.

## Settings restoration

Before the corrected browser acceptance, the serialized `didar_settings` option was saved to a temporary exact snapshot. The test posted the real mapping values, verified save/reload behavior, and restored the exact original option in a `finally` guard. The final local option was checked after restoration; no test mapping values were left behind. No existing submission or CRM object was changed.
