# ns-didar Post-E2E Bug Fixes

## PDF 204

### Root Cause

The PDF generator was healthy, but the browser PDF request used the default cache mode. A stale empty HTTP 204 response was returned for the unchanged admin-post URL. A fresh request and direct plugin generation both produced valid PDF bytes.

### Fix

The ns-didar PDF fetch now uses `cache: 'no-store'`, so an earlier empty response cannot be reused. The existing one-fetch, one-Blob-download flow remains unchanged.

### Before

The existing browser QA request returned HTTP 204 with an empty body for both print modes.

### After

- without files: HTTP 200, `application/pdf`, 58,956 bytes, valid `%PDF` signature
- with files: HTTP 200, `application/pdf`, 61,917 bytes, valid `%PDF` signature
- status: PASS
- content-type: `application/pdf`
- bytes: positive and mode-specific
- single download: PASS; the focused flow test confirms one anchor click per mode, with no popup or page navigation

## JavaScript Initialization

### libphonenumber

- root cause: Digits enqueues `https://unpkg.com/libphonenumber-js@latest/bundle/libphonenumber-max.js`; the local browser network policy blocks that external request, leaving `window.libphonenumber` undefined. ns-didar does not reference this dependency.
- fix: no ns-didar change is appropriate for this external Digits dependency; fixing it requires a Digits/local asset or browser network configuration change outside this focused plugin scope.
- browser result: unresolved on all five local forms; the same `libphonenumber is not defined` page error remains.

### Failed Fetch

- exact request: `https://unpkg.com/libphonenumber-js@latest/examples.mobile.json`
- root cause: Digits calls this optional placeholder-data request without a rejection handler, and the local browser blocks the external request.
- fix: no ns-didar change; the failing request belongs to Digits and is outside this plugin-only scope.
- browser result: unresolved; the same `Failed to fetch` page error remains on all five local forms.

## Time Picker Color

- previous computed: `rgb(255, 254, 251)` during the trigger's yellow-to-white `background-color` transition after selection
- winning selector: closed base `.didar-app .didar-time-picker__trigger`; `.didar-time-picker.is-open .didar-time-picker__trigger` and `:hover` remain the yellow states
- final computed: `rgb(255, 255, 255)` immediately after close; keyboard-active and selected options compute to the brand yellow `rgb(255, 193, 0)`

## Traveler Applicant Note

- classification: no confirmed plugin bug; the existing QA record contains no successful note input, so this is an evidence gap
- storage: `_didar_shared_note` is empty; `applicant_note` is intentionally stored separately from `_didar_fields`
- rendered: the detail page renders the applicant-note section with its empty placeholder; no QA marker is present
- Didar mapping: the current registry supports the note and the sync path maps the stored shared note to Deal Description; with empty storage there was no note value to map

## Regression

- Consultation: existing local QA record and form behavior preserved; time picker canonical value remains ASCII `HH:MM`
- Traveler: existing local QA record preserved; no current-pass write performed
- Visa: existing local QA record, Deal, main Case, companion UIDs/Cases, and upload state preserved
- Deal ID unchanged: PASS
- Main Case ID unchanged: PASS
- Companion IDs unchanged: PASS
- new CRM objects created: 0

## Files Changed

- `assets/css/frontend.css`
- `assets/js/frontend.js`
- `tests/test-didar-consultation-time-picker.js`
- `tests/test-didar-pdf-download-flow.js`
- `docs/changed/2026-09-14-post-e2e-bug-fixes.md`

## Validation

- PHP lint: PASS; 10 current modified PHP files linted successfully
- JS syntax: PASS; 5 JavaScript files checked
- git diff --check: PASS
- real browser: PASS for existing-record PDF modes and consultation time-picker states; no live Didar/API calls
- PDF: PASS; direct plugin generation and browser requests returned valid PDFs for both modes
- console: ns-didar PDF/time-picker flows produced no new errors; the two external Digits errors remain on all five local forms

## Final Status

PARTIAL — SOME E2E ISSUES REMAIN
