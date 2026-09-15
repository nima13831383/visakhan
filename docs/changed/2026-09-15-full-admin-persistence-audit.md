# Full Admin Persistence Audit — 2026-09-15

## Scope and method

This audit covers ns-didar-owned local persistence only. It did not call Didar, execute an executable queue worker, submit a production form, create a remote object, or delete remote data.

The executable check is [`tests/smoke-full-admin-persistence-audit.php`](../../tests/smoke-full-admin-persistence-audit.php). It creates only local synthetic posts/pages and local metadata, snapshots options/user metadata/event rows, restores them in `finally`, and reports its assertion total. The existing [`tests/smoke-settings-tabs.php`](../../tests/smoke-settings-tabs.php) remains the focused settings/Cases transfer regression suite.

## Admin/save inventory

| Admin area | Save action | Storage | Capability | Nonce | Partial/full semantics | Audit result |
| --- | --- | --- | --- | --- | --- | --- |
| Main settings: عمومی | Settings API → `sanitize_didar_settings()` | `didar_settings` option | `didar_manage_settings` | WordPress Settings API | Partial tab save; absent top-level settings preserved | PASS |
| Main settings: فرم‌ها | Settings API → `sanitize_didar_settings()` | `didar_settings` option | `didar_manage_settings` | WordPress Settings API | Partial tab and nested form controls preserve absent entries; present empty mapping clears | FIXED/PASS |
| Main settings: اطلاعات کاربری | Settings API → `sanitize_didar_settings()` | `didar_settings` option | `didar_manage_settings` | WordPress Settings API | Partial tab and nested profile states preserve absent entries | FIXED/PASS |
| Main settings: Case ها | `admin_post_didar_save_case_settings` | `didar_settings` option | `didar_manage_settings` | `didar_save_case_settings` | Scoped Case save; absent is preserved, `''` is explicit no-mapping | FIXED/PASS |
| Request detail/edit page settings | `sanitize_page_settings()` | `didar_page_settings` option | `didar_manage_settings` | WordPress Settings API | Absent selector preserved; present `0` clears | FIXED/PASS |
| Field required/optional controls | `sanitize_didar_settings()` | `field_required_overrides` | `didar_manage_settings` | WordPress Settings API | Per-form/per-field partial update | PASS |
| Default request assignee | `sanitize_didar_settings()` | `didar_form_default_assignees` | `didar_manage_settings` | WordPress Settings API | Per-form partial update; present `0` unassigns | FIXED/PASS |
| Broker/User mapping | `sanitize_didar_settings()` | `didar_broker_user_map` | `didar_manage_settings` | WordPress Settings API | Absent entry preserved; present empty entry unmaps | PASS |
| Form workflow/status-stage mapping | `sanitize_didar_settings()` | `didar_form_workflows` | `didar_manage_settings` | WordPress Settings API | Explicit empty pipeline/status pair is a canonical opt-out | PASS; transfer fix added |
| Country catalog | `admin_post_didar_country_save`, `admin_post_didar_country_toggle` | country option/catalog | `didar_manage_settings` | action-specific nonce | Complete record save/toggle | Source-audited PASS |
| Submission edit in wp-admin | `save_post_didar_submission` → `Didar_Submission_Service::update()` | CPT, `_didar_fields`, `_didar_shared_note`, workflow post meta, events | object edit + granular workflow caps | `didar_admin_save_submission_{id}` | Validated full field payload; workflow values are separately capability-gated | FIXED/PASS |
| Frontend authorized request edit | `Didar_Shortcodes::handle_edit_submission()` → `update_from_frontend()` | `_didar_fields`, `_didar_shared_note`, events | owner or staff request-edit capability | `didar_edit_submission_{id}` | Validated payload; explicit empty field values persist | PASS |
| Workflow/status/notes/assignment | `Didar_Submission_Service::update_workflow()` | `_didar_*` workflow meta and event log | granular `didar_*` capability for each value | carried by protected admin/frontend request | Changed values only; zero assignee removes assignment | PASS |
| Profile form | `Didar_User_Profile::save_current_user()` | WP user fields and user meta | current user; configured state enforced | `didar_profile_update` | Submitted editable values update; empty national ID is retained as empty | PASS with no-op sync double |
| Profile document reference | profile-document AJAX path / catalog | `_didar_profile_documents` user meta | authenticated owner | profile-document AJAX nonce | Current document key replaced or set to `0` on removal | PASS; catalog-only runtime fixture |
| Submission file removal | `wp_ajax_didar_remove_file` | file record, submission file reference, physical private fixture | authenticated owner/request editor via File Service | `didar_remove_file` | File Service validates relation before removal | Source-audited; no filesystem fixture used |
| Queue item discard | `admin_post_didar_discard_queue_item` | sync state post/user meta and item retry event | `didar_manage_settings` | `didar_discard_queue_item` | Selected queue item only | PASS with synthetic queue item |
| Queue purge | `admin_post_didar_purge_queue` → `purge_queue()` | queue-state meta and item-specific retry events | `didar_manage_settings` | `didar_purge_queue` | Current discovered queue only; identities/submissions/users remain | PASS for synthetic removal core; public purge not invoked because unrelated queued work existed |
| Queue Run Now | `admin_post_didar_run_queue_item` | existing queue state/attempt counters | `didar_manage_settings` | `didar_run_queue_item` | Existing item only; worker owns generation accounting | Locked synthetic path PASS; executable path intentionally not invoked |
| Diagnostics/log clear | `admin_post_didar_clear_logs` | diagnostic log table | `didar_manage_settings` | `didar_clear_logs` | Intentional full diagnostic-log clear | Source-audited PASS |
| Settings transfer | export, preview, apply admin-post actions → `Didar_Settings_Transfer` | `didar_settings`, bounded import backups | `didar_manage_settings` | action-specific transfer nonce | Versioned allowlist with verify-and-rollback | FIXED/PASS |
| Metadata refresh, connection test, manual sync | dedicated admin-post actions | caches / remote operation state | `didar_manage_settings` | action-specific nonce | Action-specific | Source-audited only; intentionally not invoked because they can contact Didar |
| Webhook-secret rotation | `admin_post_didar_rotate_webhook_secret` | `didar_settings` | `didar_manage_settings` | action-specific nonce | Explicit secret rotation only | Source-audited; intentionally not invoked |

All state-changing handlers above use a nonce and an authorization check appropriate to their boundary. Settings and POST handlers redirect with `wp_safe_redirect()` or the WordPress Settings API; AJAX handlers return structured JSON after nonce and ownership checks.

## Defects found and corrected

### DEFECT-01 — profile-state sibling reset

- **Area/storage:** Profile settings / `didar_settings.profile_field_states`.
- **Root cause:** The sanitizer rebuilt every profile state from registry defaults when an individual nested control was absent.
- **Fix:** Start from saved profile states and only replace a state whose control is present.
- **Regression:** The full audit submits only `national_id`; saved `email=readonly` remains `readonly` after reload.

### DEFECT-02 — partial default-assignee save removed other forms

- **Area/storage:** Form settings / `didar_settings.didar_form_default_assignees`.
- **Root cause:** The sanitizer began with an empty map and treated a missing nested form control as zero.
- **Fix:** Begin with saved assignments, skip absent forms, and treat a present zero as an explicit unassign.
- **Regression:** Clearing consultation does not remove Visa; assigning consultation again survives reload.

### DEFECT-03 — omitted page selector was cleared

- **Area/storage:** Shared shortcode page settings / `didar_page_settings`.
- **Root cause:** `sanitize_page_settings()` converted an absent selector to `0`.
- **Fix:** Preserve the saved selector when its control is absent; retain present `0` as a clear.
- **Regression:** A partial details-page clear leaves the saved edit page intact and the clear survives a second save.

### DEFECT-04 — failed submission workflow validation could write field metadata

- **Area/storage:** Submission edit / `_didar_form_type`, `_didar_fields` post meta.
- **Root cause:** `Didar_Submission_Service::update()` wrote form metadata before resolving the required internal default workflow status.
- **Fix:** Resolve and validate the default status before any metadata write.
- **Regression:** A synthetic workflow without a default returns `workflow_default_missing` and the prior field array is byte-for-byte unchanged.

### DEFECT-05 — workflow explicit opt-out could not be imported

- **Area/storage:** Settings transfer / `didar_form_workflows`.
- **Root cause:** Export represented an intentional opt-out as `pipeline_id=''` with no statuses, while transfer validation rejected that canonical pair as incomplete.
- **Fix:** Accept that exact canonical pair as an opt-out before normal workflow completeness validation.
- **Regression:** Official preview/apply preserves the empty workflow entry, cleared mappings, Case tombstone, and removal of retired `case_role`/legacy business `companion_uid` mappings.

### DEFECT-06 — settings success notice could accompany a rejected value

- **Area/storage:** Settings API response / `didar_settings`.
- **Root cause:** The sanitizer added the generic success notice after adding a validation error and retaining the previous value.
- **Fix:** Emit the generic success notice only when the current settings save has no error-level validation result.
- **Regression:** An invalid form-access URL keeps its previous URL, adds the validation error, and does not add `didar_settings_saved`.

### DEFECT-07 — Case invalid mapping could be reported as a successful save

- **Area/storage:** Case settings save response / `didar_settings.case_form_settings`.
- **Root cause:** Invalid pipeline/stage input marked the redirect invalid, but a rejected Case custom-field mapping did not.
- **Fix:** The Case mapping sanitizer reports invalid/duplicate targets to the dedicated save coordinator; the redirect and notice now identify invalid pipeline, stage, or mapping input while retaining the valid saved value or blank tombstone.
- **Regression:** A forged invalid mapping returns `invalid=true` and preserves the explicit blank mapping.

## Explicit-empty and absent-control contract

- **Options:** Omitted top-level and nested controls retain saved values. Present empty form/person/broker mappings clear the setting. Page selector `0` clears the selector.
- **Post meta:** Authorised frontend and admin-backed updates retain an explicit empty field value; no old field/default is rehydrated by the persistence layer.
- **User meta:** The profile form writes an editable empty national ID as an empty value; profile document removal persists the document key with `0` without touching a file in this audit.
- **Mappings:** General Deal/Person mappings clear by removal; Case mappings retain the established three-state contract: absent, valid field ID, or explicit `''` tombstone.
- **Assignments:** A present `0` removes the per-form or per-request assignee. A missing form control preserves the existing assignee.

## Runtime validation

`smoke-full-admin-persistence-audit.php` verifies:

- settings profile/default-assignee/page-selector partial saves and explicit clears;
- required/optional round trips for consultation, embassy appointment, traveler evaluation, complaint/suggestion, and Visa;
- Case tombstone and invalid mapping behavior;
- official settings preview/apply/verification and import backups;
- admin service submission persistence, failed workflow atomicity, frontend edit persistence, notes, statuses, assignment clear, and duplicate-event prevention;
- profile user-meta/document-reference persistence using a no-op sync double;
- a locked synthetic Run Now path, single-item discard, and the exact synthetic purge removal core, while preserving Person/Deal/Case identities;
- settings-notice accuracy.

The runtime test detected unrelated pre-existing queue work, so it deliberately did not call public `purge_queue()`. Its private removal core was exercised solely with a synthetic submission, and the real queue remained untouched.

No executable queue worker, file deletion, Didar API endpoint, CRM mutation, production submission, or production user record was used.
