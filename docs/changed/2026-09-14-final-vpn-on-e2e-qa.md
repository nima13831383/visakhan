# Final VPN-On E2E QA — blocked before submissions

## Scope and environment

- Local site: `http://localhost/visa/`
- VPN: reported ON by the operator.
- Browser runtime: PASS. Local login, Didar settings, connection-test UI, and diagnostics UI were rendered in the browser.
- API key: configured (presence only verified).
- No production WordPress instance was accessed.

## Preflight

The admin connection-test UI reported success at the start of the run. The required independent authenticated read immediately returned `didar_http_error` with source `http_request_failed`.

The existing localhost-only diagnostic then reproduced failures in every transport path:

| Check | Runs | Failures |
|---|---:|---:|
| Raw PHP cURL | 10 | 10 |
| WordPress HTTP | 10 | 10 |
| Plugin connection path | 10 | 10 |
| Authenticated pipeline read | 5 | 5 |
| Authenticated custom-field read | 5 | 5 |
| Authenticated Person detail read | 5 | 5 |
| Raw cURL burst | 20 | 20 |

Diagnostic classification: `INTERMITTENT_NETWORK_FAILURE` / `network_or_vpn_path`.

A final minimal transport sample reported PHP cURL errno `7` and WordPress `http_request_failed`: the host could not be reached on port 443. No cURL 28 was observed in this run.

## Queue baseline

The browser diagnostics screen showed six pre-existing queue items:

| Type | Count | State |
|---|---:|---|
| Submission | 1 | exhausted, 3 of 3 automatic attempts |
| Case | 5 | pending; configuration error recorded |
| Person | 0 | — |
| Scheduled item-specific retries | 0 | — |

No queue item was run, purged, discarded, edited, or otherwise changed.

## E2E result matrix

| Flow | WP | Person | Deal | Cases | Generation | Attempts | Queue | Duplicate | Result |
|---|---|---|---|---|---|---|---|---|---|
| Profile | Not started | Not started | N/A | N/A | Not created | N/A | Unchanged | N/A | BLOCKED |
| Consultation | Not started | Not started | Not started | N/A | Not created | N/A | Unchanged | N/A | BLOCKED |
| Complaint/Suggestion | Not started | Not started | Not started | N/A | Not created | N/A | Unchanged | N/A | BLOCKED |
| Traveler Evaluation | Not started | Not started | Not started | N/A | Not created | N/A | Unchanged | N/A | BLOCKED |
| Visa Request | Not started | Not started | Not started | Not started | Not created | N/A | Unchanged | N/A | BLOCKED |
| Visa Edit | Not started | Not started | Not started | Not started | Not created | N/A | Unchanged | N/A | BLOCKED |
| Embassy Appointment | Not started | Not started | Not started | Not started | Not created | N/A | Unchanged | N/A | BLOCKED |

## Safety result

No frontend form was submitted. No profile save, upload, Deal, Person, Case, webhook, retry-cap seam, same-value re-save, queue worker execution, mapping/configuration change, CRM deletion, or WordPress data mutation was performed.

## Next action

Restore stable connectivity from this local PHP/WordPress host to `app.didar.me:443` while VPN is ON, then restart the controlled E2E QA from preflight with a fresh queue baseline.
