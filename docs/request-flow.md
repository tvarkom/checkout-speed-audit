# How an audit travels through the plugin

A 30-minute guide for a developer new to the code. Read it with the code open; every step
names the file and function that does it. `DEVELOPMENT.md` maps the files and hooks; this
follows one click through them.

## The picture

```
WooCommerce → Speed Audit, "Run free audit"
   │  admin-post.php?action=tvarkom_csa_form
   ▼
tvarkom_csa_handle_form (admin.php) ── lock ── register if needed ──▶ api.tvarkom.com/v1/register (+ /verify)
   │                                                  ▲                       │ GETs /wp-json/tvarkom/v1/verify (rest.php)
   ▼                                                  └───────────────────────┘
tvarkom_csa_run_audit (audit.php) ── plan ──▶ api.tvarkom.com/v1/client
   │ metrics.php, detailed.php (with consent)
   │ └─ Pro: profile-request.php ──▶ this store's own checkout ──▶ profile.php measures, profile-report.php builds the result
   ▼
POST api.tvarkom.com/v1/audits ──▶ gateway ──▶ audit tool ──▶ report email
   │
   ▼
result code stored (settings.php) ──▶ redirect back to the admin page, which shows the message
```

The plugin never runs the audit itself: it collects aggregate store data (only with consent),
sends it to the Tvarkom API gateway, and shows the gateway's answer. The report arrives by email.

## The audit, step by step

| # | What happens | Where | If it fails |
|---|---|---|---|
| 1 | The admin page shows the form, the plan (from the gateway) and the last result | `tvarkom_csa_render_page`, `tvarkom_csa_render_plan` (`admin.php`), `tvarkom_csa_status` (`api.php`) | the plan box shows the error |
| 2 | The click posts to `admin-post.php`: capability (`manage_woocommerce`) and nonce checked, email and consent saved | `tvarkom_csa_handle_form` (`admin.php`), `tvarkom_csa_update_settings` (`settings.php`) | 403, `invalid_email` |
| 3 | A pasted license key is checked against the gateway and must belong to this store | `tvarkom_csa_save_license` (`api.php`) | `license_invalid`, `license_other_store` |
| 4 | One audit at a time: an atomic option row is the lock (stale after 5 minutes) | `tvarkom_csa_audit_lock` (`audit.php`) | `audit_running` |
| 5 | No API key yet: register. The gateway gets a token, fetches it from this site's REST route to prove the domain, and returns a free key | `tvarkom_csa_register` (`api.php`), `tvarkom_csa_register_routes` (`rest.php`) | `network`, the gateway's code (e.g. `origin_taken`) |
| 6 | The plan now: with Pro audits left, every audit is a Pro audit and needs the store data | `tvarkom_csa_run_audit` (`audit.php`) → `tvarkom_csa_status` | `consent_required` (nothing sent, no Pro audit used) |
| 7 | With consent: aggregate store metrics (versions, sizes, counts, an order range) | `tvarkom_csa_collect_metrics` (`metrics.php`) | — |
| 8 | Pro only: autoloaded options, leftover tables, PHP settings, failed and late tasks | `tvarkom_csa_collect_detailed` (`detailed.php`) | — |
| 9 | Pro only: the checkout profile. A single-use token is stored; the plugin loads its own checkout once with the token in a header; on that request only, the profiler times every hook, query and outgoing call, then stores the result | `tvarkom_csa_profile_checkout` (`profile-request.php`) → `tvarkom_csa_profile_maybe_start` (`profile.php`, runs at load) → `tvarkom_csa_profile_finish` (`profile-report.php`) | the profile says why it's unavailable (`request_failed`, `redirected`, `not_profiled`); the audit still runs |
| 10 | `POST /v1/audits` with the API key: website, cart and checkout URLs, email, and the data above | `tvarkom_csa_post` (`api.php`) | `network`, or the gateway's code via `tvarkom_csa_error_code` |
| 11 | The answer is stored as the last result; a revoked or moved key is cleared so the next audit registers again | `tvarkom_csa_run_audit`, `tvarkom_csa_update_settings` | — |
| 12 | The lock is released and the admin is redirected back with the result code, shown as a message | `tvarkom_csa_audit_unlock`, `tvarkom_csa_redirect`, `tvarkom_csa_messages` (`admin.php`) | — |

What leaves the store, field by field, is in `readme.txt` ("External services") and the
privacy policy; the profiler's header in `profile.php` lists what the profile sends and never sends.

## The other flows, in one paragraph each

- **Store diagnostics and "Profile my checkout"** run locally and send nothing:
  `tvarkom_csa_render_diagnostics` shows the same Pro diagnostics as step 8, and the button
  (`tvarkom_csa_handle_profile`, `diagnostics.php`) runs step 9 and shows the result on the page.
- **Buying Pro**: when the gateway's plan status includes an `upgrade_url` (the Stripe Payment
  Link with this store's reference), the plan box shows **Buy Pro** (`tvarkom_csa_render_plan`).
  Payment and the license happen in Stripe and the gateway; the plugin sees Pro the next time
  it asks for the plan (step 1). A store that paid before installing gets a key by email and
  pastes it (step 3).
- **Audits queued by 0.6.0 and earlier** run through `tvarkom_csa_run_queued_audit` under the
  same lock.
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
