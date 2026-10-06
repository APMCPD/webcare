=== Webcare ===
Contributors: apm
Tags: support, maintenance, requests
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One place for your Webcare service details, website health and change requests.

== Description ==

Webcare is installed on every website we host and manage. It gives the client (Editors and Administrators) one place to:

* see what their Webcare service includes and how to contact us,
* see their quarterly website health check (coming soon), and
* send us a request for a change, which arrives by email in our shared mailbox.

The plugin has no settings stored in the database. It keeps a one-minute "please wait" marker to stop accidental double-sending, plus, after a failed submission, the typed form values for up to 5 minutes so nothing is lost.

== Installation ==

1. Upload the `webcare` folder to `/wp-content/plugins/` (or install the zip from a GitHub release) and activate it.
2. Visit the Webcare menu in the WordPress admin to check it looks right.

No setup is needed. Support emails go to webcare@apmcpd.co.uk by default.

Optional: to send one site's requests to a different address, add this line to `wp-config.php` above the "That's all, stop editing!" comment:

`define( 'WEBCARE_SUPPORT_EMAIL', 'someone@example.com' );`

If that address is invalid it is ignored (and noted in the PHP error log) and the default is used. The plugin never falls back to the site's admin email. If the built-in address were ever invalid, the request form would switch off and clients would see "Online requests aren't set up yet".

== How to edit the Your service text ==

1. Open `includes/config.php` and find the function `webcare_service_info()` (it is marked "EDIT THIS").
2. Change the wording (what's included, response times, the "Something bigger in mind?" note, phone number, ownership promises). Anything left empty is simply not shown. Edit `response_times` and `response_short` together so the page and the confirmation email stay consistent.
3. Save, then release an update (see below) so every client site gets the new text.

== Releasing an update ==

IMPORTANT: every release goes to ALL client sites automatically, usually within about 12 hours. Test first.

1. Make your change.
2. Change the version number in THREE places: the `Version:` line at the top of `webcare.php`, `WEBCARE_VERSION` just below it in the same file, and `Stable tag:` at the top of this file. All three must match.
3. Add a new entry at the top of the Changelog in `webcare.php` and in the Changelog section of this file.
4. Commit and push to the `master` branch on GitHub (https://github.com/APMCPD/webcare).
5. Test the new version on one site first.
6. On GitHub, create a new Release from the `master` branch. The tag must be `v` followed by the exact version number, for example `v1.0.1`.
7. Client sites check for updates about every 12 hours and install Webcare updates automatically.

WARNING: if the tag does not exactly match the version number inside the plugin (for example the tag says v1.0.2 but the plugin still says 1.0.1), sites will keep offering the same update over and over again.

== Changelog ==

= 1.0.2 =
* Support emails go to webcare@apmcpd.co.uk by default (wp-config setting now optional); safer service wording.

= 1.0.1 =
* Final service wording for APM Webcare; health check described as quarterly.

= 1.0.0 =
* Initial release. Webcare page and dashboard widget, "Request a change" form with confirmation email, "Your service" information, and automatic updates from GitHub.
