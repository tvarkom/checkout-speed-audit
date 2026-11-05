=== Checkout Speed Audit ===
Contributors: tvarkom
Tags: woocommerce, performance, speed, audit, database
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.11.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A free WooCommerce health report from tvarkom, emailed as a PDF: speed, caching, database, checkout, and what each finding means for your sales.

== Description ==

Adds a **WooCommerce → Checkout Speed Audit** page with one button, **Send me the Report**. It's free, and the report arrives by email as a PDF within a few minutes.

tvarkom's scanner measures your store from outside, the way a visitor's request would reach it:

* Server response time of your homepage, cart and checkout
* Caching of your pages and static files
* Page weight, number of files and marketing trackers

It adds what the plugin reads from your store:

* Autoloaded options and database size, with the largest autoloaded options and the database tables left by removed plugins
* PHP version and settings, active plugins and object cache
* Action Scheduler and WP-Cron health, with failed and late tasks
* A profile of one checkout page load: which plugins, hooks, database queries and outside services take the time
* A fix plan: what to fix first, step by step, with estimated developer hours

You can request one report per week. Until 7 days have passed, the **Send me the Report** button is disabled and the page shows the date of your next scan. A scan that failed doesn't count. Reports go to the site's admin email by default; you can change the address.

The scan runs on tvarkom's servers and loads your public pages like a visitor would. The plugin changes nothing on your store, apart from one small table of daily totals (below). For the checkout profile it loads your checkout page once from your own server, with one product in a temporary cart that is never saved; nothing is ordered. Analytics or marketing plugins that record checkout visits on the server may count that page load as one visit.

== External services ==

This plugin uses the tvarkom audit service (https://tvarkom.com) to register your store and run scans. Nothing is sent to tvarkom until an administrator clicks **Send me the Report**, right under a list of what it sends and the line "By clicking “Send me the Report”, you agree to send this data to tvarkom and accept tvarkom's Terms of Use and Privacy Policy".

**Registration** (first scan only): your store's home URL, the report email and the URL of the plugin's verification endpoint are sent to `https://api.tvarkom.com/v1/register`. tvarkom then calls `https://<your store>/wp-json/tvarkom/v1/verify` to confirm the request came from your store, and issues a free API key for your domain (`https://api.tvarkom.com/v1/register/verify`).

**Each scan**: your store's home URL, cart and checkout URLs and the report email are sent to `https://api.tvarkom.com/v1/audits`. tvarkom's scanner then loads your homepage, cart and checkout pages.

**Store data**: these values are sent as well. The plugin lists them above the **Send me the Report** button.

* PHP, WordPress and WooCommerce versions
* Database and options table sizes, autoloaded options size
* Number of active plugins, object cache type
* Action Scheduler task counts (pending, overdue, failed) and how late WP-Cron is
* Orders in the last 30 days as a range (for example 50–200)
* Your store's business context, so the report can explain findings in business terms:
  * country and currency from your WooCommerce settings
  * the number of paid and on-hold orders in the last 30 days, and of failed, pending and cancelled orders
  * gross sales in the last 30 days and the average order value, as euro ranges only (for example €5,000–25,000). The plugin converts them on your store with the European Central Bank's daily rates, which it gets from tvarkom; the amounts themselves are never sent
  * how many customers in the last 30 days were new and how many returning, as two counts from WooCommerce Analytics (no customer is identified)
  * the number of published products and variations, and whether stock management is on
  * the IDs of the enabled payment gateways and shipping methods (for example `stripe`, `flat_rate`), never their settings or keys
  * whether checkout uses the Checkout block or the classic shortcode, and whether High-Performance Order Storage is on
  * the database and web server product (for example "MariaDB 10.11", "nginx"), how many of your theme's WooCommerce template overrides are outdated, how many plugins have an update waiting, and the name of an active backup plugin, if any
  * the domain your store's emails come from (WooCommerce → Settings → Emails), never the address, so tvarkom can check its SPF and DMARC records
  * how many products were added to the cart, checkout pages opened and orders placed over the last 30 days, and how many days that covers. The plugin counts these on your server in a small table of daily totals (its name ends in `tvarkom_csa_funnel`): no cookies, no browser script and nothing about the shopper. Logged-in administrators and shop managers aren't counted. Days older than 35 are deleted, and uninstalling the plugin removes the table
  * how many orders in the last 30 days came from each source type (for example organic search, referral, campaign, direct) and from the 10 most common campaign sources (`utm_source`, for example "google"), from WooCommerce Order Attribution
* The names and sizes of the 20 largest autoloaded options (never their contents)
* Names, sizes and estimated row counts of database tables no active plugin or theme matches, each with the name of the switched-off or not-installed plugin or theme it likely belongs to
* `memory_limit`, `WP_MEMORY_LIMIT`, `max_execution_time` and whether OPcache is on
* Failed Action Scheduler tasks grouped by hook with their count and last error message (with emails, links, IP addresses and long numbers removed), and the hook names and times of tasks more than 15 minutes late (never their arguments)
* The checkout profile: plugin, theme and hook names with their timings and call counts, database query shapes with every value replaced by `?`, the host names (not the full addresses) of outside services called while the checkout page loads, object cache hit and miss counts, peak memory and the page's server time

The report is emailed to the report email, with a copy to tvarkom's report mailbox (checkoutspeedaudit@tvarkom.com) for support and records, deleted after 12 months. tvarkom's database keeps no copy of it once it's sent (see the privacy policy for the mailbox, the short-lived working copy and backups).

**Store profile**: tvarkom keeps the latest business context above (not the report) for your store, replaced by each scan, to understand which stores use the plugin. It's only ever used as totals across stores (for example how many stores per country or size), and it's deleted with your store's registration, at the latest 24 months after its last scan.

**Next scan date and exchange rates**: the plugin asks `https://api.tvarkom.com/v1/client` when your store may scan again, each time you open its page and before each scan. The answer also carries the European Central Bank's daily euro rates (fetched by tvarkom, not by your store), used for the euro ranges above.

No customer names, emails or addresses, order contents or exact sales amounts are ever sent. The report is emailed only to the report email.

Terms of use: https://plugins.svn.wordpress.org/tvarkom-checkout-speed-audit-for-woocommerce/trunk/legal/terms-of-use.txt

Privacy policy: https://plugins.svn.wordpress.org/tvarkom-checkout-speed-audit-for-woocommerce/trunk/legal/privacy-policy.txt

Both ship with the plugin in its `legal` folder. The plugin page links to them before you run a scan, with a short privacy summary, and the plugin suggests text for your own privacy policy under **Settings → Privacy**.

== Installation ==

1. Install from **Plugins → Add New**: search for "Checkout Speed Audit", or upload the plugin zip. WooCommerce must be installed and active.
2. Activate the plugin.
3. Go to **WooCommerce → Checkout Speed Audit**, check the report email, and click **Send me the Report**. The first scan registers your store with tvarkom; the report arrives by email within a few minutes.

== Frequently Asked Questions ==

= Does it work on a staging or local site? =

No: tvarkom must reach your store over the public internet with https, so localhost, IP addresses and password-protected sites can't be scanned.

= Registration fails with "We couldn't confirm this domain" =

tvarkom needs to reach `/wp-json/tvarkom/v1/verify` on your store. Check that a security plugin or firewall doesn't block the WordPress REST API for outside visitors.

= How often can I run a scan? =

Once a week. The 7 days count from your last scan; until then the **Send me the Report** button is disabled and the page shows the date of your next scan. A scan that failed doesn't count, so you can try again (at most 3 new scans in 24 hours).

= Why don't I see results on the plugin page? =

Every result arrives by email as a PDF report, so you can keep it and pass it to your developer.

= The report didn't arrive =

Reports come from checkoutspeedaudit@tvarkom.com within a few minutes. Check your spam folder and that the Report email on the plugin page is one you read.

= What happens to my data if I uninstall? =

Uninstalling removes the plugin's settings from your database. Run the plugin again later and your store registers again with a new key.

= Where do I get help? =

Use the plugin's support forum on WordPress.org, or email support@tvarkom.com.

== Screenshots ==

1. The Checkout Speed Audit page: the report email, what is sent, and the **Send me the Report** button.

== Changelog ==

= 0.11.0 =
* Your shop's funnel in the report: the plugin counts products added to the cart, checkouts opened and orders placed as daily totals on your server (no cookies, nothing about shoppers), and reads where orders came from with WooCommerce Order Attribution. The report shows the funnel once 7 days have been counted.
* Your email domain is sent (not the address), so the SPF and DMARC checks look at the domain your order emails come from.

= 0.10.1 =
* The plugin's description on the Plugins screen names tvarkom correctly and lists what 0.10.0 added.
* Reports now also check your HTTPS certificate's expiry and your email domain's SPF and DMARC records, from outside (no change on your store).

= 0.10.0 =
* The report now explains its findings with your store's business context: country and currency, orders, sales and average order value as euro ranges (converted on your store; the amounts are never sent), new and returning customer counts, catalog size, stock management, payment and shipping methods, checkout type and more. Everything sent is listed above the button and in the privacy policy.
* tvarkom keeps the latest business context of your store, only to count stores by country, size and so on.

= 0.9.4 =
* A redesigned page: one button, Send me the Report, with what it sends and your agreement right above it. The Partial Scan button is gone.
* The menu item is now WooCommerce → Checkout Speed Audit.
* Every report is a Full Scan. An audit still queued by version 0.6.0 or earlier is dropped, unsent.

= 0.9.3 =
* The checkout profile's request is named "tvarkom checkout profiler" in your server logs.

= 0.9.2 =
* The brand is written tvarkom; the legal name is MB TVARKOM SOFTWARE.

= 0.9.1 =
* The plugin is now called Checkout Speed Audit.

= 0.9.0 =
* First release on WordPress.org.
* Free store health report from tvarkom, scored from 0 to 100 and emailed as a PDF: Partial Scan (outside measurements) or Full Scan (with store data, a checkout profile and a fix plan). One report per week.
* Terms of use and privacy policy ship with the plugin.
