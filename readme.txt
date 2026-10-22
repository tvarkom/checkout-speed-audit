=== Tvarkom Checkout Speed Audit for WooCommerce ===
Contributors: tvarkom
Tags: woocommerce, performance, speed, audit, database
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Store diagnostics and a checkout profiler in your WordPress admin, plus a free health report from Tvarkom scored from 0 to 100.

== Description ==

Adds a **WooCommerce → Speed Audit** page with two parts.

**Store diagnostics** (in your admin, nothing is sent anywhere): the 20 largest autoloaded options with their likely plugin, database tables no active plugin or theme matches (with whether they likely belong to a switched-off plugin or theme, or to a known plugin that isn't installed), your PHP memory, time-limit and OPcache settings, failed background tasks with their last error, and scheduled tasks running late. **Profile my checkout** loads your checkout page once from your own server and shows where the time goes: by plugin and theme, the slowest hooks, the most expensive database queries, calls to outside services and object cache hits.

**Store health report** (from Tvarkom's service): click **Run free audit** and Tvarkom scans your store and emails you a report with a health score from 0 to 100:

* Server response time of your homepage, cart and checkout
* Caching of your pages and static files
* Page weight, number of files and marketing trackers
* With your permission: autoloaded options and database size, PHP version support, active plugins, object cache, Action Scheduler and WP-Cron health, and an estimated revenue-at-risk level

The free plan includes 4 audits per 30 days. Reports go to the site's admin email by default; you can change the address.

**Pro** is Tvarkom's paid report service: a one-time payment for 4 detailed reports, to use within 12 months. For each one, the plugin sends your store diagnostics and a fresh checkout profile to Tvarkom, which analyzes them on its servers and emails a report with a fix plan: what to fix first, step by step, with estimated hours, and recommended PHP settings. Everything the plugin itself does stays free; Pro is the analysis and the report. While you have Pro reports left, every audit you run is a Pro one; an audit that fails on our side doesn't count. Pro audits need **Include store data in my audit** ticked. Buy it from the plugin page with **Buy Pro**; Pro then appears on the plugin page by itself. When the 4 audits are used or the 12 months pass, the store goes back to the free plan, and you can buy Pro again.

The scan runs on Tvarkom's servers and loads your public pages like a visitor would. The plugin changes nothing on your store. For the checkout profile it loads your checkout page once from your own server, with one product in a temporary cart that is never saved; nothing is ordered.

== External services ==

This plugin uses the Tvarkom audit service (https://tvarkom.com) to register your store and run audits. Store diagnostics and **Profile my checkout** work without it and send nothing. Nothing is sent to Tvarkom until an administrator clicks **Run free audit**.

**Registration** (first audit only): your store's home URL, the report email and the URL of the plugin's verification endpoint are sent to `https://api.tvarkom.com/v1/register`. Tvarkom then calls `https://<your store>/wp-json/tvarkom/v1/verify` to confirm the request came from your store, and issues a free API key for your domain (`https://api.tvarkom.com/v1/register/verify`).

**Each audit**: your store's home URL, cart and checkout URLs and the report email are sent to `https://api.tvarkom.com/v1/audits`. Tvarkom's scanner then loads your homepage, cart and checkout pages. If you tick **Include store data in my audit**, these aggregate values are sent as well (use **Preview data** to see them):

* PHP, WordPress and WooCommerce versions
* Database and options table sizes, autoloaded options size
* Number of active plugins, object cache type
* Action Scheduler task counts (pending, overdue, failed) and how late WP-Cron is
* Orders in the last 30 days as a range (for example 50–200)

**Pro audits** (only with the box ticked) also send the store diagnostics: the names and sizes of the 20 largest autoloaded options (never their contents); names, sizes and estimated row counts of database tables no active plugin or theme matches, each with the name of the switched-off or not-installed plugin or theme it likely belongs to; `memory_limit`, `WP_MEMORY_LIMIT`, `max_execution_time` and whether OPcache is on; failed Action Scheduler tasks grouped by hook with their count and last error message (with emails, links, IP addresses and long numbers removed); and the hook names and times of tasks more than 15 minutes late (never their arguments). For the checkout profile they also send: plugin, theme and hook names with their timings and call counts, database query shapes with every value replaced by `?`, the host names (not the full addresses) of outside services called while the checkout page loads, object cache hit and miss counts, peak memory and the page's server time. Analytics or marketing plugins that record checkout visits on the server may count the profile's page load as one visit.

**Plan status**: the plugin asks `https://api.tvarkom.com/v1/client` for your plan, license expiry and usage each time you open its page.

**Buying Pro**: **Buy Pro** opens Stripe's checkout (`buy.stripe.com`) for a one-time payment, with a reference to your store. Payment is handled by Stripe; Tvarkom receives your email, the amount and the store reference, and emails you a confirmation. If your store wasn't registered with the plugin yet, that email contains a license key to paste under **License key**.

No customer data, order contents or revenue figures are ever sent. The report is emailed to you, with a copy to hello@tvarkom.com.

Terms of use: https://tvarkom.com/terms/

Privacy policy: https://tvarkom.com/privacy

== Installation ==

1. Install from **Plugins → Add New**, or upload the `tvarkom-checkout-speed-audit-for-woocommerce` folder to `/wp-content/plugins/`.
2. Activate the plugin. WooCommerce must be active.
3. Go to **WooCommerce → Speed Audit** and click **Run free audit**.

== Frequently Asked Questions ==

= Does it work on a staging or local site? =

No. Tvarkom must reach your store over the public internet with https, so localhost, IP addresses and password-protected sites can't be audited.

= Registration fails with "We couldn't confirm this domain" =

Tvarkom needs to reach `/wp-json/tvarkom/v1/verify` on your store. Check that a security plugin or firewall doesn't block the WordPress REST API for outside visitors.

= How often can I run it? =

The free plan allows 4 audits per 30 days, at most one per store and email address per day. Pro gives 4 detailed audits to use within 12 months, with no waiting between them; they don't use the free allowance.

= What happens to my data if I uninstall? =

Uninstalling removes the plugin's settings from your database. Run the plugin again later and your store registers again with a new key.

== Changelog ==

= 0.7.0 =
* Store diagnostics and Pro audits now tell which plugin or theme a database table likely belongs to (switched off, not installed, or not identified), and no longer list tables of active plugins or the active theme as leftovers.

= 0.6.3 =
* If the Tvarkom service answers with an error this version has no message for, the settings page now says "Something went wrong on our side" instead of showing no notice.

= 0.6.2 =
* One audit at a time: clicking Run audit again while an audit is still running no longer sends a second one (and, on Pro, no longer uses a second Pro audit).

= 0.6.1 =
* Run free audit and Run Pro audit send the audit right away and show the result, instead of waiting for WordPress's background tasks, which may be stalled on the stores that need an audit most.
* Store diagnostics load when you click Show store diagnostics, so the plugin page opens quickly on large sites.

= 0.6.0 =
* New name: Tvarkom Checkout Speed Audit for WooCommerce.
* Store diagnostics and Profile my checkout on the plugin page, free for every store and sent nowhere.
* Pro is described as what it is: Tvarkom's analysis and report service.

= 0.5.5 =
* Readme links Tvarkom's Terms of Use.

= 0.5.4 =
* When Pro ends before its audits are used, the plugin page says it ended on that date.

= 0.5.3 =
* Text domain matches the WordPress.org slug, checkout-speed-audit-for-woocommerce.

= 0.5.2 =
* Preview data shows what a Pro audit adds to every store, not only Pro stores.
* Tested with WordPress 7.1.

= 0.5.1 =
* The plugin page always shows the current plan, so Pro appears right after a purchase.

= 0.5.0 =
* Pro is now a one-time payment for 4 detailed audits within 12 months. The plugin shows the Pro audits left, and a Pro audit needs the store data box ticked.
* Pro reports include a fix plan.

= 0.4.0 =
* Pro checkout profile: server time on the checkout page by plugin, theme and WordPress, slowest hooks and database queries, outside service calls and object cache hits.

= 0.3.0 =
* Pro license: paste the key from your email; detailed audit every 30 days with autoloaded options, leftover tables, PHP settings, failed and late background tasks.
* Plan, license expiry and usage on the plugin page.

= 0.2.0 =
* Free API key per store, with a domain check.
* Optional store data: database, PHP, plugins, object cache, background tasks and order volume range.
* Cart and checkout response times. Scores from 0 to 100.

= 0.1.0 =
* First release.
