# Development

`readme.txt` is the WordPress.org readme for store owners. This file is for working on the code.

The plugin is procedural WordPress code: a main file, `includes/`, and every function, constant
and option prefixed `tvarkom_csa` / `TVARKOM_CSA`. It supports PHP 7.4+ and WordPress 6.5+, and
needs WooCommerce.

## Files

| File | What it holds |
|---|---|
| `checkout-speed-audit.php` | Plugin header, constants, the include list and every hook the plugin registers at load |
| `includes/settings.php` | The stored settings (option `tvarkom_csa`): API key, plan, email, consent, last result |
| `includes/api.php` | The Tvarkom API gateway client: requests, plan status, license keys, registration, error codes |
| `includes/rest.php` | `GET /wp-json/tvarkom/v1/verify`, which the gateway fetches to check the domain at registration |
| `includes/audit.php` | One audit: what is collected (only with consent) and sent, and the stored outcome; the lock that allows one audit at a time |
| `includes/metrics.php` | Store metrics sent with every audit that has consent |
| `includes/detailed.php` | Pro diagnostics: autoloaded options, orphaned tables, PHP setup, failed background tasks |
| `includes/class-tvarkom-csa-profiler.php` | The checkout profiler's state |
| `includes/profile.php` | The profiler inside the profiled checkout request (its header describes what is measured and what leaves the store) |
| `includes/profile-report.php` | Building the profile result at the end of that request |
| `includes/profile-request.php` | The audit side: requesting the store's own checkout with a single-use token |
| `includes/diagnostics.php` | The Store diagnostics section and the local checkout profile |
| `includes/admin.php` | The WooCommerce → Speed Audit page, its form handler and script |
| `uninstall.php` | Removes the plugin's options and transients |

## Hooks

All in `checkout-speed-audit.php`:

| Hook | Callback |
|---|---|
| `admin_menu` (priority 99) | `tvarkom_csa_admin_menu` |
| `admin_post_tvarkom_csa_form` | `tvarkom_csa_handle_form` (save settings, license, run an audit) |
| `admin_enqueue_scripts` | `tvarkom_csa_admin_scripts` |
| `admin_post_tvarkom_csa_profile` | `tvarkom_csa_handle_profile` (the "Profile my checkout" button) |
| `rest_api_init` | `tvarkom_csa_register_routes` |
| `tvarkom_csa_run_audit` | `tvarkom_csa_run_queued_audit`; only for audits queued by versions up to 0.6.0 |

`tvarkom_csa_profile_maybe_start()` runs at load. On the profiled checkout request only, it
registers the profiler's own hooks (`includes/profile.php`); every other request returns after
one header check.

## Checks

The CI (`.gitlab-ci.yml`) runs on every branch and merge request:

```sh
composer install                                   # development tools only, into vendor/
vendor/bin/phpcs                                   # WordPress Coding Standards + PHP 7.4 compatibility
vendor/bin/phpstan analyse --memory-limit=1G       # level 5, WordPress and WooCommerce stubs
php -l <file>                                      # on PHP 7.4 and 8.4
```

`vendor/bin/phpcbf` fixes most formatting reports. Before a release, also run
[Plugin Check](https://wordpress.org/plugins/plugin-check/) on the zip's contents, for example in
WordPress Playground with WooCommerce and Plugin Check installed:
`wp plugin check tvarkom-checkout-speed-audit-for-woocommerce`.

To test against a local gateway, set `TVARKOM_CSA_API_URL` in `wp-config.php`.

## Release

1. Set the version in three places: the `Version:` header and `TVARKOM_CSA_VERSION` in
   `checkout-speed-audit.php`, and `Stable tag:` in `readme.txt`; add the changelog entry.
2. Build the zip from the release commit. Only the plugin files go in; the development files
   (`composer.*`, `phpcs.xml.dist`, `phpstan*`, CI) stay out:

   ```sh
   git archive --format=zip --prefix=tvarkom-checkout-speed-audit-for-woocommerce/ \
     -o tvarkom-checkout-speed-audit-for-woocommerce-<version>.zip HEAD \
     checkout-speed-audit.php includes readme.txt uninstall.php
   ```
