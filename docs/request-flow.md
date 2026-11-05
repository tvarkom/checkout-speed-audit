# How an audit travels through the plugin

A 30-minute guide for a developer new to the code. Read it with the code open; every step
names the file and function that does it. `DEVELOPMENT.md` maps the files and hooks; this
follows one click through them.

## The picture

```
WooCommerce → Checkout Speed Audit, "Send me the Report"
   │  admin-post.php?action=tvarkom_csa_form
   ▼
tvarkom_csa_handle_form (admin.php) ── lock ── register if needed ──▶ api.tvarkom.com/v1/register (+ /verify)
   │                                                  ▲                       │ GETs /wp-json/tvarkom/v1/verify (rest.php)
   ▼                                                  └───────────────────────┘
tvarkom_csa_run_audit (audit.php) ── week ──▶ api.tvarkom.com/v1/client
   │ metrics.php, detailed.php
   │ └─ profile-request.php ──▶ this store's own checkout ──▶ profile.php measures, profile-report.php builds the result
   ▼
POST api.tvarkom.com/v1/audits ──▶ gateway ──▶ audit tool ──▶ report email
   │
   ▼
result code stored (settings.php) ──▶ redirect back to the admin page, which shows the message
```

The plugin never runs the audit itself: every audit is a Full Scan, so it collects aggregate store
data and the detailed diagnostics, sends the request to the tvarkom API gateway, and shows the gateway's
answer. Results are never shown on the page: the report arrives by email as a PDF.

## The audit, step by step

| # | What happens | Where | If it fails |
|---|---|---|---|
| 1 | The admin page shows the form, the last result and, inside the week, when the next report is possible (from the gateway) | `tvarkom_csa_render_page` (`admin.php`), `tvarkom_csa_status` (`api.php`) | the button stays enabled; the gateway decides |
| 2 | The click posts to `admin-post.php`: capability (`manage_woocommerce`) and nonce checked, email saved; only the **Send me the Report** button (`csa_do=full`) runs an audit (a stale Partial Scan button from before 0.9.4 only saves) | `tvarkom_csa_handle_form` (`admin.php`), `tvarkom_csa_update_settings` (`settings.php`) | 403, `invalid_email` |
| 3 | One audit at a time: an atomic option row is the lock (stale after 5 minutes) | `tvarkom_csa_audit_lock` (`audit.php`) | `audit_running` |
| 4 | No API key yet: register. The gateway gets a token, fetches it from this site's REST route to prove the domain, and returns a key | `tvarkom_csa_register` (`api.php`), `tvarkom_csa_register_routes` (`rest.php`) | `network`, the gateway's code (e.g. `verification_failed`) |
| 5 | One report a week: inside the week (`next_scan_at`) nothing is sent (`too_soon`, with the date) | `tvarkom_csa_run_audit` (`audit.php`) → `tvarkom_csa_status`, `tvarkom_csa_next_scan_at` | `too_soon` (nothing sent) |
| 6 | Aggregate store metrics (versions, sizes, counts, an order range) | `tvarkom_csa_collect_metrics` (`metrics.php`) | — |
| 7 | Autoloaded options, leftover tables, PHP settings, failed and late tasks | `tvarkom_csa_collect_detailed` (`detailed.php`) | — |
| 8 | The checkout profile. A single-use token is stored; the plugin loads its own checkout once with the token in a header; on that request only, the profiler times every hook, query and outgoing call, then stores the result | `tvarkom_csa_profile_checkout` (`profile-request.php`) → `tvarkom_csa_profile_maybe_start` (`profile.php`, runs at load) → `tvarkom_csa_profile_finish` (`profile-report.php`) | the profile says why it's unavailable (`request_failed`, `redirected`, `not_profiled`); the audit still runs |
| 9 | `POST /v1/audits` with the API key: website, cart and checkout URLs, email, and the data above | `tvarkom_csa_post` (`api.php`) | `network`, or the gateway's code via `tvarkom_csa_error_code` |
| 10 | The answer is stored as the last result: `queued_full`, `too_soon` (the gateway's weekly refusal; its date is kept for the message) or an error code; a revoked or moved key is cleared so the next audit registers again | `tvarkom_csa_run_audit`, `tvarkom_csa_update_settings` | — |
| 11 | The lock is released and the admin is redirected back with the result code, shown as a message | `tvarkom_csa_audit_unlock`, `tvarkom_csa_redirect`, `tvarkom_csa_messages` (`admin.php`) | — |

What leaves the store, field by field, is in `readme.txt` ("External services") and the
privacy policy; the profiler's header in `profile.php` lists what the profile sends and never sends.


## The other flows, in one paragraph each

- **No local results**: the page shows no diagnostics; the results reach the admin in the
  emailed report.
- **Audits queued by 0.6.0 and earlier** are dropped unsent by `tvarkom_csa_drop_queued_audits`:
  store data leaves only on a click, and the gateway runs only Full Scans.
- **Uninstall** removes the plugin's options and transients (`uninstall.php`).

## Where to change things

| To change… | Edit |
|---|---|
| What an audit sends | `includes/audit.php`, `metrics.php` or `detailed.php`, and `readme.txt` ("External services") and the privacy policy |
| What the profiler measures | `includes/profile.php` (inside the profiled request) and `profile-report.php` (the result) |
| A message on the admin page | `tvarkom_csa_messages` in `includes/admin.php` |
| A new gateway call | `includes/api.php` |
| A new hook | the hook list in `checkout-speed-audit.php` |
| A stored setting | `includes/settings.php` (and `uninstall.php` if it adds an option) |

## Tests

`tests/` (PHPUnit, with MySQL; not shipped) covers the admin form, the gateway client,
registration through the REST route, the audit and its lock, the profile token and uninstall,
against a fake gateway (`tests/class-tvarkom-csa-test-case.php`). `DEVELOPMENT.md` ("Checks")
lists PHPCS, PHPStan and the PHP 7.4/8.4 runs.
