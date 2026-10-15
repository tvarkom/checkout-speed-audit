=== Checkout Speed Audit for WooCommerce ===
Contributors: tvarkom
Tags: woocommerce, performance, speed, audit, database
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free store health audit by Tvarkom: server and checkout speed, caching, database and background tasks, scored from 0 to 100 and emailed to you.

== Description ==

Adds a **WooCommerce → Speed Audit** page. Click **Run free audit** and Tvarkom scans your store and emails you a report with a health score from 0 to 100:

* Server response time of your homepage, cart and checkout
* Caching of your pages and static files
* Page weight, number of files and marketing trackers
* With your permission: autoloaded options and database size, PHP version support, active plugins, object cache, Action Scheduler and WP-Cron health, and an estimated revenue-at-risk level

The free plan includes 4 audits per 30 days. Reports go to the site's admin email by default; you can change the address.

The scan runs on Tvarkom's servers and loads your public pages like a visitor would. The plugin changes nothing on your store.

== External services ==

This plugin uses the Tvarkom audit service (https://tvarkom.com) to register your store and run audits. Nothing is sent until an administrator clicks **Run free audit**.

**Registration** (first audit only): your store's home URL, the report email and the URL of the plugin's verification endpoint are sent to `https://api.tvarkom.com/v1/register`. Tvarkom then calls `https://<your store>/wp-json/tvarkom/v1/verify` to confirm the request came from your store, and issues a free API key for your domain (`https://api.tvarkom.com/v1/register/verify`).

**Each audit**: your store's home URL, cart and checkout URLs and the report email are sent to `https://api.tvarkom.com/v1/audits`. Tvarkom's scanner then loads your homepage, cart and checkout pages. If you tick **Include store data in my audit**, these aggregate values are sent as well (use **Preview data** to see them):

* PHP, WordPress and WooCommerce versions
* Database and options table sizes, autoloaded options size
* Number of active plugins, object cache type
* Action Scheduler task counts (pending, overdue, failed) and how late WP-Cron is
* Orders in the last 30 days as a range (for example 50–200)

No customer data, order contents or revenue figures are ever sent. The report is emailed to you, with a copy to hello@tvarkom.com.

Privacy policy: https://tvarkom.com/privacy

== Installation ==

1. Install from **Plugins → Add New**, or upload the `checkout-speed-audit` folder to `/wp-content/plugins/`.
2. Activate the plugin. WooCommerce must be active.
3. Go to **WooCommerce → Speed Audit** and click **Run free audit**.

== Frequently Asked Questions ==

= Does it work on a staging or local site? =

No. Tvarkom must reach your store over the public internet with https, so localhost, IP addresses and password-protected sites can't be audited.

= Registration fails with "We couldn't confirm this domain" =

Tvarkom needs to reach `/wp-json/tvarkom/v1/verify` on your store. Check that a security plugin or firewall doesn't block the WordPress REST API for outside visitors.

= How often can I run it? =

The free plan allows 4 audits per 30 days, and one audit per store and email address per day.

= What happens to my data if I uninstall? =

Uninstalling removes the plugin's settings from your database. Run the plugin again later and your store registers again with a new key.

== Changelog ==

= 0.2.0 =
* Free API key per store, with a domain check.
* Optional store data: database, PHP, plugins, object cache, background tasks and order volume range.
* Cart and checkout response times. Scores from 0 to 100.

= 0.1.0 =
* First release.
