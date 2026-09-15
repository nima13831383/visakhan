# Sync stranding recovery hardening

## Evidence and assessment

One production Visa request remained pending at zero of three automatic attempts without a visible item event. Manual Run Now later completed its Person, Deal, and Case synchronization. A later Visa request synchronized automatically. This is an intermittent rare condition, not a reproducible systemic business-sync failure.

## Existing path preserved

The normal path remains durable state creation, single-event scheduling, and immediate best-effort cron dispatch. The recovery sweep only invokes the existing `process_scheduled_submission()` / `process_submission()` processor, which owns locking, eligibility re-checking, attempt reservation, Person, Deal, and Case behavior.

## Recovery safety net

The existing five-minute worker evaluates every durable `pending` submission state (the state used by fresh work and retries). Fresh zero-attempt pending work receives a two-worker-interval grace (10 minutes), allowing normal item-event dispatch to finish. A due retry or a stale zero-attempt pending generation can be recovered only when it is current, actionable, below the three-attempt cap, and has no active lock. Synced, exhausted, superseded, future-retry, and active-lock states are skipped.

Scans and skipped items consume no attempts. The canonical execution reservation remains the only operation that consumes an automatic attempt.

## Observability and tests

Verbose diagnostics record `sync_worker_scan`, `sync_worker_recovery_dispatch`, and `sync_worker_item_skipped`; existing `sync_schedule_failed` logging is preserved. `tests/smoke-sync-stranding-recovery.php` performs local, no-network recovery coverage with a canonical-processor test double.
