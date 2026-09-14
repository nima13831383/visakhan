# Queue inspector and per-item manager

## Scope

`تشخیص و گزارش` now contains `مدیریت صف همگام‌سازی`. It derives its rows from the existing durable sync state and plugin-owned item-specific cron events; it does not create a second queue, poll Didar, or expose payloads or credentials.

## Queue rows

- Submission rows come from `_didar_sync_state` in a pending/retry/failed queue state.
- Person rows come from `_didar_person_sync_state` in the same durable queue state.
- Case rows summarize pending `_didar_case_sync_state`, companion links, or main-applicant Case state. They are informational because their retry path is the parent submission worker.
- A scheduled submission/Person event augments an existing durable row. An event without durable state is shown as a non-executable scheduled row so it can be discarded safely.

## Actions

`اجرای فوری` validates the normalized item key at request time and invokes only the existing scheduled submission or Person worker. It preserves locks, retry state, identity lookup, and idempotency. Case rows have no independent Run Now action.

`حذف از صف` is POST-only, nonce-protected, capability-protected, and validates the item against the current inventory. It removes only the matching local state and item-specific cron event. It never calls Didar and preserves WordPress records plus Deal, Person, Case, companion UID, and form data.

The existing full purge stays available for discarding all current queue work at once. It remains distinct from per-item management.
