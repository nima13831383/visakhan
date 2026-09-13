# ns-didar documentation map

**Last reviewed: 2026-09-13.** The current implementation and [PROJECT-HANDOFF.md](PROJECT-HANDOFF.md) are the current-state source for this plugin. The numbered guides were written before the uncommitted Phase 2–8 upgrades and remain useful as historical/reference material; they must not override the handoff or source.

| File | Purpose | Status | Action |
|---|---|---|---|
| `../Agents.md` | Legacy operational instructions | Partly stale | Replaced with the current concise operating guide. |
| `../README.md` | Public plugin overview | Partly current | Retain as an overview; use the handoff for operational details. |
| `../REQUIREMENTS.md` | Earlier product requirements | Historical | Retain; do not treat as current field/configuration truth. |
| `../FORMS.txt` | Original form inventory | Historical input | Retain for legacy values only; Registry wins. |
| `../SHORTCODES.txt` | Earlier shortcode notes | Historical/reference | Retain; verify against `Didar_Shortcodes`. |
| `../DIDAR-CUSTOM-FIELDS.txt` | Earlier CRM field suggestions | Historical | Retain; it contains superseded field-type recommendations. |
| `../FINAL-DIDAR-AUDIT-SOURCE-OF-TRUTH.md` | 2026-09-01 source/runtime audit | Historical audit | Retain; its counts, mappings, and Case model predate current upgrades. |
| `01-overview.md` | Old product overview | Historical/reference | Retain. |
| `02-architecture.md` | Old component map | Historical/reference | Retain. |
| `03-forms-and-submissions.md` | Old form/storage guide | **Conflicts** | Retain as historical; it incorrectly limits `applicant_note` to three forms. |
| `04-users-and-access-control.md` | Access-control guide | Historical/reference | Retain. |
| `05-user-profile.md` | Profile guide | Historical/reference | Retain. |
| `06-didar-integration.md` | Earlier CRM integration guide | Historical/reference | Retain. |
| `07-sync-flows.md` | Earlier sync-flow guide | Historical/reference | Retain. |
| `08-webhook.md` | Webhook guide | Historical/reference | Retain. |
| `09-workflows-and-statuses.md` | Workflow guide | Historical/reference | Retain. |
| `10-files.md` | Earlier file guide | Historical/reference | Retain. |
| `11-event-log-and-diagnostics.md` | Event/log guide | Historical/reference | Retain. |
| `12-settings.md` | Earlier settings guide | **Partly stale** | Retain; see handoff for per-form Case settings. |
| `13-settings-import-export.md` | Earlier transfer guide | Historical/reference | Retain. |
| `14-background-jobs-and-cron.md` | Worker guide | Historical/reference | Retain. |
| `15-security.md` | Security guide | Historical/reference | Retain. |
| `16-database-and-storage.md` | Storage guide | Historical/reference | Retain. |
| `17-hooks-and-extension-points.md` | Extension guide | Historical/reference | Retain. |
| `18-deployment.md` | Deployment guide | Historical/reference | Retain. |
| `19-troubleshooting.md` | Troubleshooting guide | Historical/reference | Retain. |
| `20-developer-guide.md` | Earlier implementation notes | **Partly stale** | Retain; default precedence and Case guidance are superseded by the handoff/source. |
| `21-companion-cases.md` | Earlier Visa-only companion Case guide | **Superseded** | Retain historically; current code supports Visa and Embassy plus main-applicant Cases. |
| `../tests/README.md` | Test-suite usage notes | Historical/reference | Retain; local PHPUnit compatibility is recorded in the handoff. |
| `../vendor/**/{README,CHANGELOG,SECURITY}.md` | Third-party package documentation | Third-party | Retain; not an ns-didar behavior source. |
| `PROJECT-HANDOFF.md` | Current architecture, config audit, QA, guardrails | **Canonical** | Read first after `Agents.md`. |
| `changed/2026-09-13-documentation-handoff-refresh.md` | This refresh/audit record | Current history | Read when reviewing how conflicts were resolved. |

The vendor documents are inventoried as a third-party collection because they describe dependencies, not ns-didar behavior.
