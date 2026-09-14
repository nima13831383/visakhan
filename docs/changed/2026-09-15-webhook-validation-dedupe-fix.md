# ns-didar Webhook Validation & Deduplication Fix

## BUG-01

### Before

After authentication and top-level metadata checks, `receive_webhook()` called `seen_webhook()` before checking whether the entity/action was supported. An authenticated unsupported event, including a Case event, was therefore stored in `didar_seen_webhooks` and then returned as HTTP 422.

### Root Cause

The existing `seen_webhook()` helper combines the duplicate lookup and first-seen write, and it was invoked before `webhook_event_key()` support validation.

### Fix

Support validation now runs before the existing `seen_webhook()` call. No second ledger or alternate dedupe mechanism was added.

### After

Unsupported events return `didar_webhook_unsupported` with HTTP 422 and do not enter `didar_seen_webhooks`. Replayed unsupported events return 422 again rather than being classified as duplicates. Supported valid Deal and Person events retain the existing duplicate suppression behavior.

Unsupported event stored in seen ledger: **NO**

## BUG-02

### Before

For Deal webhook payloads, `apply_deal_webhook()` converted a present non-array `data.Fields` value into an empty array. The webhook could then return HTTP 200 and be recorded as seen.

### Root Cause

The Deal application path used `isset( $data['Fields'] ) && is_array( ... ) ? ... : array()`, which did not distinguish an absent key from a present key with an invalid type.

### Fix

After support validation and before deduplication, supported Deal events now reject a present `Fields` key unless its value is an array. The existing malformed webhook error family is used.

### After

Deal payloads with `Fields` equal to a string, number, boolean, or null return HTTP 400 with `didar_webhook_invalid`. They do not mutate WordPress, create a generation, schedule outbound sync, write successful webhook history, or enter the deduplication ledger. A completely absent `Fields` key keeps the prior behavior.

Non-array Deal Fields accepted: **NO**

Response: **400 / didar_webhook_invalid**

## Validation Order

1. Authenticate the route secret or enabled legacy header.
2. Validate the JSON content type and apply the existing rate limit.
3. Parse JSON and validate the required top-level `data`/`meta` arrays.
4. Validate required metadata: event ID, entity ID, and entity title.
5. Determine the supported entity/action with `webhook_event_key()`.
6. For supported Deal events, reject a present non-array `data.Fields` value.
7. Derive the event ID and perform the existing duplicate lookup/write.
8. Apply the supported Deal or Person webhook under the existing reverse-sync behavior.
9. Return the existing successful response.

Malformed and unsupported payloads fail before ledger insertion. Valid supported duplicates are still acknowledged without a second business application.

## Valid Duplicate Behavior

- Deal duplicate: existing ledger detects it and returns the existing duplicate response.
- Person duplicate: existing ledger detects it and returns the existing duplicate response.
- duplicate mutation: no second local business application.
- outbound echo: no submission or Person generation is created by inbound webhook application; existing Deal suppression remains intact.

## Loop Suppression Regression

- inbound Deal creates outbound generation: **NO**
- inbound Person creates outbound generation: **NO**

The patch changes only pre-application validation order and the Deal `Fields` type guard. It does not change `self::$suppress`, `apply_deal_webhook()`, `apply_person_webhook()`, or generation entry points.

## Tests

The new `tests/test-didar-webhook-validation.php` covers:

1. valid Deal with array `Fields`: normal local application — test added
2. exact valid Deal replay: duplicate response and no second mutation — test added
3. unique unsupported Case: 422 and absent ledger entry — test added
4. unsupported Case replay: 422 again and absent ledger entry — test added
5. Deal `Fields` string: 400, no mutation, absent ledger entry — test added
6. Deal `Fields` number: 400, no mutation, absent ledger entry — test added
7. Deal `Fields` boolean: 400, no mutation, absent ledger entry — test added
8. Deal `Fields` null: 400, no mutation, absent ledger entry — test added
9. Deal without `Fields`: prior successful behavior preserved — test added
10. valid Person: existing local reverse-sync behavior — test added
11. duplicate Person: existing dedupe behavior — test added
12. invalid/unsupported payloads: no outbound generation — test added
13. missing/invalid token and malformed JSON: existing security errors — test added

Each test snapshots and restores `didar_seen_webhooks`, preserving unrelated ledger entries.

## Security Regression

The route secret/header authentication, JSON content-type requirement, rate limit, required metadata checks, and existing REST route method restriction are unchanged. No live Didar request or CRM mutation was used.

## Files Changed

- `includes/class-didar-sync-manager.php`
- `tests/test-didar-webhook-validation.php`
- `docs/changed/2026-09-15-webhook-validation-dedupe-fix.md`

## PHP Lint

PASS — `includes/class-didar-sync-manager.php` and `tests/test-didar-webhook-validation.php` both pass `php -l`.

The standard PHPUnit command could not start because the installed legacy PHPUnit runner calls the removed PHP 8 `each()` function. No webhook test case was executed by that incompatible runner.

## git diff --check

PASS — no whitespace errors. Git emitted only existing line-ending warnings.

## Final Status

FIXED — UNSUPPORTED WEBHOOKS NO LONGER POLLUTE DEDUPE STATE AND MALFORMED DEAL FIELDS RETURN 400
