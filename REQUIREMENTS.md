# Didar Requirements

This document records the implemented architectural and behavioral requirements for Didar. `FORMS.txt` remains the source of truth for form fields and business options.

## Roles and capabilities

Didar defines two stable roles:

- Colleague: `didar_colleague` (`همکار`)
- Broker: `didar_broker` (`کارگزار`)

Authorization is capability-based. Brokers receive only the custom-post-type capabilities needed to read and edit Didar requests plus these workflow capabilities:

```text
didar_view_requests
didar_edit_requests
didar_change_public_status
didar_edit_public_notes
didar_view_internal_workflow
didar_change_internal_status
didar_add_internal_notes
didar_assign_requests
didar_receive_requests
didar_view_request_history
```

Administrators receive all required Didar capabilities and `didar_change_request_owner`. Colleagues receive only frontend/owned-request capabilities:

```text
didar_colleague_access
didar_view_own_internal_workflow
didar_view_own_request_history
```

Role and capability installation is idempotent, runs on activation, and is also version-checked on ordinary plugin load for existing installations. Deactivation does not remove roles, capabilities, submissions, assignments, or history.

Colleagues and frontend-only users are denied wp-admin access. Brokers are restricted to the Didar request list and Didar request edit screens; menu hiding is supplemental to server-side capability enforcement.

## Ownership and creator identity

`post_author` remains the authorization boundary for frontend submission ownership. It is always derived from the authenticated user for frontend creation and is applied inside frontend queries. Applicant names, email addresses, phone numbers, and other form values never confer access.

`_didar_created_by_user_id` records the authenticated actor that initially created/configured the submission and is not changed when an administrator changes the legacy owner field. Existing submissions fall back to `post_author` when this metadata does not exist.

## Workflow state

Current submission state uses separate private metadata:

```text
_didar_public_status
_didar_public_note
_didar_internal_status
_didar_internal_note
_didar_assigned_user_id
```

The existing applicant-editable `_didar_shared_note` remains a separate applicant note. It is not reinterpreted as the staff-authored Public Note.

For backward compatibility:

- `_didar_status` is the fallback and synchronized alias for Public Status.
- `_didar_admin_note` is the fallback and synchronized alias for Internal Note.
- missing Public/Internal Status values resolve to `pending_review`.
- missing notes and assignments resolve to empty/unassigned.

Normal customers can receive only Public Status and Public Note for submissions they own. They cannot submit workflow fields. Colleagues can additionally receive Internal Status, Internal Note, and Activity only for submissions they own. Authorized Brokers and Administrators can view and update workflow fields according to their capabilities.

## Assignment

Assignment is optional. `_didar_assigned_user_id` may be empty or zero. A non-empty assignee must exist and have `didar_receive_requests`; the current actor must have `didar_assign_requests`. Assignment IDs and actor IDs are never trusted from browser data.

The wp-admin “requests assigned to me” view adds `_didar_assigned_user_id = get_current_user_id()` to the server-side query. The unassigned view uses a server-side missing/empty assignment query.

## Audit/event storage

Current submission metadata remains authoritative. Audit history is an append-only stream in the dedicated WordPress table:

```text
{$wpdb->prefix}didar_events
```

Schema:

| Column | Purpose |
| --- | --- |
| `event_id` | Auto-incrementing event identity |
| `submission_id` | Didar submission ID |
| `event_type` | Stable structured event key |
| `actor_user_id` | Authenticated actor derived server-side; zero for system actions |
| `old_value` | JSON-wrapped structured previous value |
| `new_value` | JSON-wrapped structured new value |
| `event_meta` | JSON-wrapped optional metadata, including an actor-label snapshot |
| `created_at_gmt` | Server-generated UTC WordPress database timestamp |

The table has indexes for submission/event order, event type, actor, and timestamp. Its schema version is stored in `didar_event_schema_version` and upgraded with `dbDelta()`.

Each audit event is inserted independently. No update or delete API is exposed for normal submission editing. No-op saves do not create events. Implemented events include creation, public/internal status and note changes, applicant-note changes, assignment/reassignment/removal, owner changes, submitted-data changes, and file addition/replacement.

History access uses the same server-side capability and ownership checks as workflow access. There is no public REST exposure, and normal customers do not receive the event stream.

## Admin screen

The submission edit screen separates:

1. Request/Form Data
2. Applicant Note
3. Customer-facing Information (Public Status and Public Note)
4. Internal Workflow (Internal Status, Internal Note, Assignment)
5. Ownership/creator information
6. Append-only Activity timeline

## Security and privacy

- All changes require a Didar nonce and independent capability/ownership checks.
- Form fields and option values remain registry allowlisted and field-appropriately sanitized.
- Frontend detail/edit access validates post ID, post type, publication state, and ownership.
- Internal workflow values are excluded while server responses for normal customers are constructed.
- Assignment and actor IDs are resolved and authorized server-side.
- The submission CPT remains non-public and unavailable through REST.
- Event queries are bounded and use prepared values; event inserts are independent for audit concurrency.

## Tests

WordPress PHPUnit integration coverage is provided in `tests/test-didar-workflow.php` for role capabilities, public/internal visibility, colleague ownership, forged workflow updates, valid/invalid assignments, no-op events, append-only ordering, and server-side assignment queries. The repository does not bundle the WordPress PHPUnit test library; see `tests/README.md`.
