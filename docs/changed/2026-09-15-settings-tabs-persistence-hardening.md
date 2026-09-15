# Settings tabs and persistence hardening

Date: 2026-09-15

## Save bug root cause

The Settings API sanitizer rebuilt `didar_settings` from defaults on every save. Once the page was split into partial tab forms, fields absent from the active tab could therefore be reset to an empty value or a default. Several nested groups already merged with their saved values, but scalar settings, profile state, user mappings, form assignees, broker mappings, debug mode, file mode, and webhook controls did not follow one consistent absent-versus-empty rule.

## Persistence fix

`Didar_Admin::sanitize_didar_settings()` now records the top-level keys submitted by the active tab, sanitizes those values through the existing validators, and restores every absent top-level key from the current option. A submitted empty value remains present in the request and clears the corresponding valid setting. An absent value means that the active tab does not own it and the saved value is retained.

Unchecked General-tab checkboxes are handled explicitly because HTML omits unchecked controls from POST. The page-selection option also returns its current value when its array is absent from a partial tab save. File storage protection is synchronized only when the file mode is actually submitted.

The implementation keeps the single canonical `didar_settings` option and the existing `didar_page_settings` Settings API group. Case configuration continues to use its dedicated nonce-protected admin-post save handler while writing its namespaces into the same canonical option.

## Explicit empty handling

The sanitizer distinguishes `array_key_exists()` from an absent key for nested settings. Clearing a valid mapping, owner, form URL, profile mapping, category, pipeline, or stage no longer falls back to the old value merely because the submitted value is empty. Invalid submitted identifiers continue to use the existing validation behavior and retain the last valid value where that contract already applied.

### Case no-mapping selectors

Case explicit unmapping is represented by a persistent empty-string tombstone. A Case mapping key that has never been configured is absent. A rendered control with a scoped `_present` marker and an empty selection stores the known mapping key with value `""`; a valid selected Case Field ID replaces that value; an unrendered control preserves its saved state. This applies to Visa and Embassy companion, main-applicant, and system mapping groups. The form uses presence markers only for this intent model and does not use ambiguous duplicate same-name hidden mapping inputs. Invalid Case Field IDs still preserve the last valid value under the existing validation contract.

### Permanent Case unmap contract

The rendered Case selector uses `value=""` for `— بدون نگاشت —`. The local admin reproduction showed that the selector could be empty in the browser while the old Case Field ID returned after the Save redirect. The actual defect was in the dedicated Case sanitizer: it deleted the mapping key for a rendered blank selection. That erased the difference between intentionally unmapped and never configured, allowing a later default or legacy path to populate the field again.

The definitive representation is a three-state model: an absent key means never configured; a present key with `""` means an explicit administrative unmap; and a present key with a validated Case Field ID means mapped. Every Case selector has one native mapping select and one scoped `_present` marker. The marker identifies a rendered control, including a browser submission that omits an empty select, without becoming a second mapping value. There are no duplicate same-name hidden Case mapping fields.

The exact Embassy failure was `case_form_settings[embassy_appointment][main_field_mappings][full_name]`: a prior `Field_8785_0_261` mapping returned after selecting `— بدون نگاشت —`. It now persists as the authoritative `full_name => ''` tombstone. Canonical per-form Case settings take precedence over legacy Visa settings, including explicit blanks. Main-applicant payload mapping treats a present blank mapping group as authoritative; transfer normalization preserves blank mapping values; and Case payload construction omits those fields. A second save, export/import, and a valid later remap retain their respective intended state.

The focused tests cover clear, real-value replacement, A-to-B replacement, malformed Field ID protection, partial-tab preservation, raw option reload, export/import round trips, and omission from Case payloads. The local PHP smoke harness verifies the generated admin markup and Save/Reload paths without network or CRM calls.

## Case mapping cleanup

`field_mappings.companion_uid` was redundant. Case identity and lookup use `system_fields.companion_uid`, and the companion Registry column is internal. The redundant business mapping is no longer rendered and is removed from saved settings and imported/exported settings by centralized normalization. `system_fields.companion_uid` remains available and unchanged.

`case_role` had no effective runtime use. Although the temporary main-applicant row contained `case_role`, `Didar_Field_Mapper::case_fields()` requires a Registry definition and skipped the key. It was not used for Case identity, lookup, duplicate detection, retry, or webhook processing. The row value and configurable main mapping were removed, and legacy saved/imported values are normalized away.

Main-applicant and companion mappings may still reuse the same Didar Case custom field because they are serialized into separate Case payloads. Duplicate targets inside one payload remain invalid.

## Tab architecture

The existing Settings page now uses native WordPress `nav-tab-wrapper`, `nav-tab`, and `nav-tab-active` markup. The default tab is `general`.

- **عمومی (`general`)**: request detail/edit pages, access/list behavior, file download behavior, PDF button text, CRM credentials and defaults, webhook security, diagnostics, date engine information, Deal system fields, global metadata refresh, and settings transfer.
- **فرم‌ها (`forms`)**: form links and barcodes, field required/default/placeholder/mapping controls, default assignees, form workflows, Deal pipeline/stage/custom-field controls, broker-to-Didar user mappings, and public status mapping.
- **اطلاعات کاربری (`profile`)**: profile field states, Person native/custom mappings, profile document mappings, and the Person metadata refresh control.
- **Case ها (`cases`)**: Visa and Embassy Case pipeline/stage/category settings, main-applicant mappings, companion mappings, system mappings, validation notices, and Case metadata refresh.

Each Settings API form includes an internal active-tab marker. WordPress's settings referer keeps the administrator on the same tab after saving.

## Metadata refresh controls

The General tab adds a nonce-protected POST action named `didar_refresh_all_metadata`. It reuses `Didar_Workflow_Manager::refresh()` and `Didar_Case_Service::refresh()` to refresh users, Deal pipelines/stages/custom fields, Person metadata supplied by the shared custom-field catalog, and Case pipelines/stages/custom fields. It redirects to General with success, partial, or failure feedback and never prints raw API responses.

Specialized refresh links remain available:

- Forms refreshes workflow/Deal/user metadata and returns to Forms.
- Profile refreshes the shared Person/custom-field metadata and returns to Profile.
- Cases refreshes Case metadata and returns to Cases.

## Persistence regression coverage

`tests/smoke-settings-tabs.php` performs local, no-network Save/Reload checks with an exact option snapshot restored in `finally`. It covers a normal select change, explicit empty values, Forms/Profile/General cross-tab preservation, legacy Case cleanup, Visa main mapping persistence, Embassy mapping persistence, Case-save cross-group preservation, tab markup, and absence of the retired UI rows. WordPress integration tests add coverage for centralized normalization and settings export/import behavior.

The repository's installed legacy PHPUnit command cannot start on PHP 8 because its runner calls the removed `each()` function. The standalone smoke harness is compatible with the current local runtime and reports its own assertion count.

## Full Settings Persistence Audit

The Settings API and the dedicated Case handler were audited as complete save paths: rendered control, POST shape, sanitizer, normalization, option write, reload, rendering, portable export/import normalization, and legacy fallbacks. General controls passed for optional text/select clears, checkbox-off, debug mode, and preservation of Forms/Profile/Case settings. Forms controls passed for form URLs, Deal mappings, defaults, placeholders, assignees, public status, and per-form workflow values. Profile controls passed for states, Person mappings, and profile-document mappings. Case controls passed for Visa and Embassy pipeline/stage/category and companion, main-applicant, and system mappings.

Two additional defects were corrected. First, clearing a Forms workflow pipeline left no validated workflow for `array_replace()`, which preserved the old per-form workflow and allowed legacy workflow settings to remain effective. An explicitly blank submitted pipeline now stores an empty canonical per-form workflow entry, which blocks legacy fallback while keeping an unsubmitted form untouched. Second, broker-to-Didar mappings were rebuilt solely from rendered rows, so a mapping for a WordPress user not rendered on the current screen could be dropped. The sanitizer now begins with the saved map, removes only a rendered blank mapping, and changes only rendered valid mappings.

The unified field-default selectors now include the same ordered empty fallback used by other clearable selectors. A subsequent selected value still wins during PHP form parsing; an empty selection has a reliable explicit transport value.

## Files changed

- `includes/class-didar-admin.php`
- `includes/class-didar-settings.php`
- `includes/class-didar-settings-transfer.php`
- `includes/class-didar-companion-model.php`
- `tests/test-didar-case-settings-persistence.php`
- `tests/test-didar-settings-transfer.php`
- `tests/smoke-settings-tabs.php`
- `docs/changed/2026-09-15-settings-tabs-persistence-hardening.md`
