# Email notification channel

The existing six-event notification registry now supports SMS and Email independently. Email is disabled by default, while legacy `enabled` settings continue to mean SMS so older settings imports retain their behavior.

Email recipients use the canonical WordPress `user_email` value for the same selected staff, request owner, and current assignee targets used by SMS. Deduplication is independent per channel, so one recipient can receive one SMS job and one Email job for the same event without either channel suppressing the other.

Email templates are plain text and use the shared ordered variable mapping with numeric placeholders such as `{0}` and `{1}`. Unknown indexes, named placeholders, and unbalanced braces are rejected. The rendered body and subject are frozen when the queue job is created. The subject convention is:

```text
اعلان درخواست #{request_number}: {event label}
```

The subject uses the existing canonical submission number and the Persian event label from `Didar_Notification_Event_Registry`; there is no separate subject-variable configuration.

Email jobs use the same durable notification queue, atomic claim, retry/backoff, stale-lock recovery, Run Now, Retry, Discard, and channel-specific idempotency model as SMS. The queue stores a channel discriminator and supports Email destinations without storing provider credentials. Email delivery is delegated to `wp_mail()` through `Didar_WordPress_Email_Channel`; a successful return means WordPress accepted the message for transport, not that delivery was confirmed.

Settings transfer includes the normalized Email event configuration while preserving legacy SMS-only imports and excluding Melipayamak credentials and runtime queue state. Local smoke coverage uses fake adapters and a short-circuited `wp_mail()` boundary. No real Email or SMS was sent, and no Didar API or CRM mutation was performed during validation. Human/manual Email QA and live provider verification remain pending.
