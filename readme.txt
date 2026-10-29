=== Tvarkom Checkout Speed Audit for WooCommerce ===
Contributors: tvarkom
Tags: woocommerce, performance, speed, audit, database
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Store diagnostics and a checkout profiler in your WordPress admin, plus a free health report from Tvarkom scored from 0 to 100.

== Description ==

Adds a **WooCommerce → Speed Audit** page with two parts.

**Store diagnostics** (in your admin, nothing is sent anywhere): the 20 largest autoloaded options with their likely plugin, database tables no active plugin or theme matches (with whether they likely belong to a switched-off plugin or theme, or to a known plugin that isn't installed), your PHP memory, time-limit and OPcache settings, failed background tasks with their last error, and scheduled tasks running late. **Profile my checkout** loads your checkout page once from your own server and shows where the time goes: by plugin and theme, the slowest hooks, the most expensive database queries, calls to outside services and object cache hits.

**Free store health report** (from Tvarkom's service): click **Run free audit** and Tvarkom scans your store and emails you a report with a health score from 0 to 100:

* Server response time of your homepage, cart and checkout
* Caching of your pages and static files
* Page weight, number of files and marketing trackers
* With your permission: autoloaded options and database size, PHP version support, active plugins, object cache, and Action Scheduler and WP-Cron health

Run an audit whenever you like. If your store already has a report from the last 7 days, Tvarkom emails you that report again, with the date it was generated, instead of running a new check; after 7 days a new audit runs. Reports go to the site's admin email by default; you can change the address. Store diagnostics and Profile my checkout are free and unlimited, and work without the audit service.

The scan runs on Tvarkom's servers and loads your public pages like a visitor would. The plugin changes nothing on your store. For the checkout profile it loads your checkout page once from your own server, with one product in a temporary cart that is never saved; nothing is ordered. Analytics or marketing plugins that record checkout visits on the server may count that page load as one visit.

== External services ==

This plugin uses the Tvarkom audit service (https://tvarkom.com) to register your store and run audits. Store diagnostics and **Profile my checkout** work without it and send nothing. Nothing is sent to Tvarkom until an administrator clicks **Run free audit**, or pastes a license key Tvarkom sent them (the key is then checked with Tvarkom).

**Registration** (first audit only): your store's home URL, the report email and the URL of the plugin's verification endpoint are sent to `https://api.tvarkom.com/v1/register`. Tvarkom then calls `https://<your store>/wp-json/tvarkom/v1/verify` to confirm the request came from your store, and issues a free API key for your domain (`https://api.tvarkom.com/v1/register/verify`).

**Each audit**: your store's home URL, cart and checkout URLs and the report email are sent to `https://api.tvarkom.com/v1/audits`. Tvarkom's scanner then loads your homepage, cart and checkout pages. If you tick **Include store data in my audit**, these aggregate values are sent as well (use **Preview data** to see them):

* PHP, WordPress and WooCommerce versions
* Database and options table sizes, autoloaded options size
* Number of active plugins, object cache type
* Action Scheduler task counts (pending, overdue, failed) and how late WP-Cron is
* Orders in the last 30 days as a range (for example 50–200)

Tvarkom keeps each report, with the store data in it, so it can send it to you again: a request within 7 days of a report gets that report instead of a new check. Reports are deleted after 24 months, or sooner on request (see the privacy policy).

**Plan status**: the plugin asks `https://api.tvarkom.com/v1/client` for your store's plan each time you open its page and before each audit.

**Only stores Tvarkom has issued a license key to.** Most stores never get one. Their audits (only with the box ticked) also send the store diagnostics: the names and sizes of the 20 largest autoloaded options (never their contents); names, sizes and estimated row counts of database tables no active plugin or theme matches, each with the name of the switched-off or not-installed plugin or theme it likely belongs to; `memory_limit`, `WP_MEMORY_LIMIT`, `max_execution_time` and whether OPcache is on; failed Action Scheduler tasks grouped by hook with their count and last error message (with emails, links, IP addresses and long numbers removed); and the hook names and times of tasks more than 15 minutes late (never their arguments). For the checkout profile they also send: plugin, theme and hook names with their timings and call counts, database query shapes with every value replaced by `?`, the host names (not the full addresses) of outside services called while the checkout page loads, object cache hit and miss counts, peak memory and the page's server time. While the license runs, Tvarkom's server also asks the store about once a week, with a request signed with the store's key, to run the same audit; the plugin then sends the same data as above to `https://api.tvarkom.com/v1/audits` and answers with only the audit's result. It uses the REST route `/wp-json/tvarkom/v1/monitor`, which refuses any request not signed with the store's key. A store without such a license never sends this data and is never asked.

No customer data, order contents or revenue figures are ever sent. The report is emailed to you, with a copy to Tvarkom's inbox (hello@tvarkom.com).

Terms of use: https://tvarkom.com/terms/

Privacy policy: https://tvarkom.com/privacy

== Installation ==

1. Install from **Plugins → Add New**: search for "Tvarkom Checkout Speed Audit", or upload the plugin zip. WooCommerce must be installed and active.
2. Activate the plugin.
3. Go to **WooCommerce → Speed Audit**. Store diagnostics and **Profile my checkout** work right away.
4. For the free health report, check the **Report email**, optionally tick **Include store data in my audit** (click **Preview data** first to see exactly what it sends), and click **Run free audit**. The first audit registers your store with Tvarkom; the report arrives by email within a few minutes.

== Frequently Asked Questions ==

= Does it work on a staging or local site? =

Store diagnostics and Profile my checkout do. The free report doesn't: Tvarkom must reach your store over the public internet with https, so localhost, IP addresses and password-protected sites can't be audited.

= Registration fails with "We couldn't confirm this domain" =

Tvarkom needs to reach `/wp-json/tvarkom/v1/verify` on your store. Check that a security plugin or firewall doesn't block the WordPress REST API for outside visitors.

= How often can I run the free audit? =

Whenever you like. If your store has a report from the last 7 days, you get that report again by email, with the date it was generated, and no new check runs; the 7 days count from that report. After 7 days, the next audit is a new one.

= The report didn't arrive =

Reports come from checkoutspeedaudit@tvarkom.com within a few minutes. Check your spam folder and that the Report email on the plugin page is one you read.

= What happens to my data if I uninstall? =

Uninstalling removes the plugin's settings from your database. Run the plugin again later and your store registers again with a new key.

= Where do I get help? =

Use the plugin's support forum on WordPress.org, or email support@tvarkom.com.

== Screenshots ==

1. The Speed Audit page: run the free audit, choose the report email and whether to include store data.
2. Store diagnostics: largest autoloaded options, leftover tables, PHP settings and background tasks.

== Changelog ==

= 0.8.2 =
* First release on WordPress.org.
* Store diagnostics and Profile my checkout in your admin, free and sent nowhere.
* Free store health report from Tvarkom, scored from 0 to 100, emailed within minutes. A report from the last 7 days is sent again instead of running a new check.
