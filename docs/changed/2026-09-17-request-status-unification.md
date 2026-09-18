# Unified Request Status

The active workflow now has one logical status: `وضعیت درخواست`.

The existing per-form Internal Status value remains the canonical stored status in `_didar_internal_status`, with `_didar_status` kept as a compatibility mirror. Staff and customers read the same status through `Didar_Submission_Service::get_request_status()`. The status continues to resolve through `Didar_Workflow_Manager` to the configured Deal Pipeline and Stage.

The old Public Status and Public Note metadata are preserved for historical compatibility but no longer control persistence, frontend output, PDF output, Deal payloads, or reverse webhook updates. New workflow notes use the existing internal note storage as the request workflow note, with `_didar_admin_note` as a compatibility fallback for older submissions.

New status and request-note changes use `request_status_changed` and `request_note_changed` events. Existing public/internal event types remain readable so historical activity is not rewritten or deleted.

The deprecated `didar_public_status_field_id` setting remains accepted by settings transfer for compatibility. It is no longer rendered or used to add a second status value to Deal custom fields.
