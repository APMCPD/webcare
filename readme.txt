=== Webcare ===
Contributors: apm
Tags: support, maintenance, requests
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One place for your Webcare service details, website health and change requests.

== Description ==

Webcare is installed on every website we host and manage. It gives the client (Editors and Administrators) one place to:

* see what their Webcare service includes and how to contact us,
* see their quarterly website health check (coming soon),
* send us a request for a change, which arrives by email in our shared mailbox, and
* enter their business details (phone, address, opening hours, services) for Google and AI assistants, and
* see anonymous quarterly counts of enquiry actions: clicks on phone, email and online-booking links, and contact form sends.

= Business details for Google & AI =

The Webcare menu has a "Business details" page. The client fills in their business type, phone, email, address, opening hours, services, areas served and (optionally) price range. Webcare publishes this invisibly in the website's code as schema.org markup, so Google and AI assistants can read it accurately. It does not change anything visitors see, and it never publishes ratings or reviews.

How it is published (decided automatically when each page loads):

* Yoast SEO (free) active: the details are added to Yoast's own "Organization" piece of markup (Yoast's logo, social links and web address are left alone). If Yoast has no Organization piece (the one whose id ends in "#organization"), Webcare adds its own. What is typed on the Business details page takes priority over anything Yoast had for the same fields: the business name replaces the organisation name set in Yoast for Google/AI (your Yoast name is kept as an alternative name), and the description, telephone, email and address filled in here also override Yoast's.
* No SEO plugin active: Webcare prints its own `<script type="application/ld+json" id="webcare-schema">` block in the page head.
* Yoast Local SEO, Rank Math, All in One SEO, SEOPress or The SEO Framework active: Webcare publishes nothing, and the page says so (these plugins handle it; ask APM).
* Nothing is published until there is at least a business name and a phone number or address.
* Days left blank in the opening hours are treated by Google as closed, so the page tells clients to tick "Closed" for shut days and warns after saving if some days are blank.
* Text is cleaned before publishing: any HTML is stripped, and any value containing `<` or `>` (e.g. "Prices < £50") is left out entirely, so write "under £50" instead.

Switching it off on one site: add `add_filter( 'webcare_publish_business_schema', '__return_false' );` to that site's code (for example a code-snippets plugin or the theme's functions.php). Webcare then adds nothing to the markup and the Business details page says "Not published: switched off on this site".

= Enquiry actions (quarterly report figures) =

The main Webcare page has an "Enquiry actions this quarter" card showing, for this quarter and last quarter (calendar quarters: Jan-Mar, Apr-Jun, Jul-Sep, Oct-Dec):

* Phone number clicks - clicks on any `tel:` link.
* Email link clicks - clicks on any `mailto:` link.
* Online booking link clicks - clicks on links to Cliniko, Jane (janeapp.com), Acuity (acuityscheduling.com and as.me), Calendly, Setmore, SimplyBook.me or Fresha, plus the optional "Online booking link" the client enters on the Business details page (a whole site such as `https://clinic.cliniko.com`, or a page/folder on the client's own site such as `https://clinic.co.uk/book`). The booking link is not published to Google.
* Contact form sends - successful sends of a Divi contact form (counted on the server through Divi's `et_pb_contact_form_submit` action, only when Divi reports no error).

Clicks show interest - a booking click is not a confirmed appointment. The card also says whether Independent Analytics (visitor numbers) is installed.

How it works: a tiny script (`assets/webcare-track.js`, no jQuery) listens for link clicks on public pages and sends only the type of click ("phone", "email" or "booking") to `POST /wp-json/webcare/v1/event`. It never delays or blocks the click. It is not loaded in the admin area, the Customizer, the Divi visual builder, or for logged-in Editors/Administrators (so testing doesn't inflate the numbers). No nonce is used on purpose: pages are cached, so a nonce printed into a page would be out of date by the time a visitor clicked. Instead the address ignores unknown event types (400 error), robots and crawlers (no browser name, or a name containing "bot", "crawl", "spider", "headless", a web address, or a known tool such as curl or Lighthouse), requests whose Origin/Referer is another website, anything over 30 events per visitor per 10 minutes, and anything over 300 events per hour across the whole site.

Privacy: No cookies, no names or contact details, and no record of which pages people visit. To stop spam, a temporary scrambled code based on the visitor's connection is set to expire after 10 minutes. Only four totals per month are stored. Suggested wording for the client's privacy statement (Complianz): "anonymous counts of clicks on phone, email and booking links". Webcare does not change any Complianz settings.

Switching it off on one site: add `add_filter( 'webcare_track_enquiries', '__return_false' );` to that site's code. The script is not loaded, nothing is counted, and the card says tracking is switched off.

Limits: the monthly total is read, increased by one and saved, so on a very busy site two clicks at the same instant could count as one (fine for small clinic sites). If a security plugin blocks the public REST API for visitors, clicks cannot be counted. Counting starts from the first page view after the update (shown on the card as "Counted since tracking started on ..."). The booking-link setting is built into cached pages, so a changed booking link can take a few minutes (until the page cache refreshes) to start counting.

= What is stored =

* `webcare_business_details` - what the client typed on the Business details page (including the optional online booking link).
* `webcare_stats_YYYY_MM` - one small option per month (for example `webcare_stats_2026_10`) holding four totals: phone, email, booking and form. Not autoloaded.
* `webcare_tracking_started` - the date counting began.

All of these are deliberately kept if the plugin is deleted, so details and history survive an accidental reinstall; there is no uninstall clean-up. Saving business details does not clear any caches, so changes can take a few minutes to appear. Webcare also keeps a one-minute "please wait" marker to stop accidental double-sending of change requests, the typed form values for up to 5 minutes after a failed submission so nothing is lost, and the 10-minute spam-limit markers described above.

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

= 1.2.0 =
* New "Enquiry actions" card on the Webcare page: anonymous monthly totals of clicks on phone, email and online-booking links, plus Divi contact form sends, shown for this quarter and last quarter. No cookies, no names or contact details. New optional "Online booking link" field on the Business details page. Can be switched off per site with the `webcare_track_enquiries` filter.

= 1.1.0 =
* New "Business details" page (Webcare menu): clients enter their phone, address, opening hours and services once, and Webcare publishes them invisibly for Google and AI assistants (added to Yoast SEO's own markup, or printed on its own if no SEO plugin is active).

= 1.0.2 =
* Support emails go to webcare@apmcpd.co.uk by default (wp-config setting now optional); safer service wording.

= 1.0.1 =
* Final service wording for APM Webcare; health check described as quarterly.

= 1.0.0 =
* Initial release. Webcare page and dashboard widget, "Request a change" form with confirmation email, "Your service" information, and automatic updates from GitHub.
