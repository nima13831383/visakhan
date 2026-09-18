# SMS notification architecture

## Scope

`ns-didar` now has a disabled-by-default notification path for the six active Visa and Embassy events:

- `visa_request.created`
- `embassy_appointment.created`
- `visa_request.assignee_changed`
- `embassy_appointment.assignee_changed`
- `visa_request.status_changed`
- `embassy_appointment.status_changed`

The event registry is the source of truth. Consultation, Traveler Evaluation, and Complaint/Suggestion do not receive active notification events in this phase.

## Delivery flow

The request service emits a canonical event after the local change is valid. The notification manager reads the event configuration, resolves explicit eligible staff users plus the optional request owner and current assignee, normalizes mobiles through `Didar_Field_Mapper`, and creates one durable job per deduplicated destination. Provider code is behind `Didar_Notification_Channel_Interface`; business and queue code never call Melipayamak directly.

The queue is stored in `wp_didar_notification_queue`, independently of the Didar synchronization state. A worker and one-off retry events process due rows. Each job has a stable idempotency key, an atomic processing claim, bounded exponential retry, and sanitized result fields. A stale lock is recovered by the worker. Run Now, Retry, and Discard are POST-only actions protected by the existing `didar_manage_settings` capability and nonces.

## Snapshot semantics

At job creation the queue stores the event key, request ID, Body ID, ordered variable mapping, resolved variable values, canonical destination, recipient user and role, and a small context snapshot. Changing the admin configuration later cannot change an existing job. Provider credentials are read from the protected `didar_settings` option only when delivery runs and are never copied into a job.

## Melipayamak transport

The current adapter uses the classic REST `SendByBaseNumber2` endpoint with HTTPS certificate verification. It sends `username`, the configured API Key in the provider password field, semicolon-delimited variable text, one recipient, and the integer Body ID. A positive provider `recId` is accepted as an accepted submission result; it is not treated as delivery confirmation. HTTP/transport failures and known transient provider responses are retried within the queue limit. Missing credentials, invalid recipients, missing Body IDs, missing variables, and permanent provider rejections are recorded as safe terminal errors.

## Variables

Only these variables can be selected, in the order saved by the admin:

`first_name`, `last_name`, `full_name`, `national_id`, `postal_code`, `form_type`, `request_status`, `request_number`, and `request_assignee`.

`form_type` uses the Registry label, `request_status` uses the canonical Request Status label, `request_number` is the existing WordPress submission ID, and `request_assignee` is the current WordPress assignee display name. The stored request `postal_code` is used when that field exists; the current canonical profile catalog has no postal-code source.

## Admin and security

The existing Diagnostics/Reporting page keeps its diagnostics tab as the default and adds an SMS/notification tab. The SMS tab stores the username and API Key server-side, renders only a masked API Key placeholder, and shows recipient mobiles masked in queue diagnostics. Notification event configuration is included in portable settings; provider credentials, queue rows, runtime locks, and provider response bodies are excluded.

## Extension point and verification

An Email or another SMS transport can implement `Didar_Notification_Channel_Interface` without changing event generation, recipient resolution, snapshots, or queue recovery. The local `tests/smoke-notifications.php` uses a fake channel, blocks HTTP, verifies event definitions, disabled defaults, recipient deduplication, snapshot stability, idempotency, retry reuse, actual-change status/assignee hooks, credential exclusion, and portable event settings. Live SMS delivery remains an explicit operational step after provider credentials and event settings are configured.
