=== Webcare ===
Contributors: apm
Tags: support, maintenance, requests
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One place for your Webcare service details, website health and change requests.

== Description ==

Webcare is installed on every website we host and manage. It gives the client (Editors and Administrators) one place to:

* see what their Webcare service includes and how to contact us,
* see their quarterly website health check (coming soon),
* send us a request for a change, which arrives by email in our shared mailbox, and
* enter their business details (phone, address, opening hours, services) for Google and AI assistants, and
* see anonymous quarterly website numbers: visits, page views, most-viewed pages, where visitors came from and which devices they use, and
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

= Visitor numbers (quarterly report figures) =

The main Webcare page has a "Your website this quarter" card. Its "Visitors" part shows, for this quarter (and last quarter for the first two items):

* Visits and page views. A page view is one new look at a page. A visit is a page view where the visitor arrived from OUTSIDE the website (a search engine, another website, or a typed address / bookmark). Moving from one page of the site to another is a page view only. Reloading a page and using the browser's back/forward buttons are not counted again. Visits are therefore not a count of unique people.
* The five most-viewed pages (shown as page addresses such as `/about-us`).
* Where visits came from, as a share of visits: Search engines (Google, Bing, DuckDuckGo, Yahoo Search, Ecosia, Yandex, Baidu, Startpage, Qwant, Brave Search, and the Google app on Android), AI assistants (ChatGPT, Perplexity, Claude, Gemini, Copilot, You.com, Duck.ai, Mistral, Meta AI), Social media (Facebook, Instagram, LinkedIn, X/Twitter, TikTok, YouTube, Pinterest, Threads, Nextdoor, Reddit, Bluesky and similar), Other websites (including other Android apps such as Gmail), and Typed in or bookmarked (no referrer). Names are matched on whole parts of the website name, so look-alikes such as `notgoogle.com` count as "other". Bing's AI chat can't be told apart from Bing search, so Bing counts as search. Some AI assistants send visitors with no referrer but tag the link with `?utm_source=chatgpt.com` (or similar); for those names only (`chatgpt.com`, `chatgpt`, `openai`, `perplexity`, `perplexity.ai`, `claude.ai`, `gemini`, `copilot`) the script reads that one setting and the visit is counted as an AI visit instead of "typed in" or "other". No other part of the visitor's address is read.
* The share of visits from mobile, tablet and desktop devices (guessed from the browser name; counted once per visit).

How it works: the page itself carries its own path and a short proof code, both worked out on the server and printed into the page (each cached page has its own). The same tiny script (`assets/webcare-track.js`) sends one "pageview" for a new page visit, containing that path and code, the host name of the referring website (nothing else of the referring address) and, for the AI names above only, `utm_source`. It does not read the visitor's address bar for the path, so search words and "#" parts can never be sent. It sends nothing for error (404) pages or search-results pages, for reloads and back/forward visits, or from an old cached copy of a page that doesn't carry a path and code yet. The server checks the path (plain printable characters only, must start with "/", repeated slashes collapsed, trailing slash removed, cut to 200 characters; a site in a sub-folder keeps the folder) and the proof code, so made-up addresses can't be added to the most-viewed list; then it works out the source and device. Robot, other-site and rate-limit rules are the same as for enquiry clicks, but page views have their own separate allowance (120 per visitor per 10 minutes and 5000 per hour for the whole site), so page views can never use up the enquiry allowance or the other way round. If page views are turned away by those limits, a note is kept for that month and the card says "Some visits this quarter weren't counted because of unusually heavy traffic (spam protection)." The script is skipped in the admin area, the Customizer, the Divi visual builder, previews, and for logged-in Editors/Administrators.

Switching it off on one site: add `add_filter( 'webcare_track_visits', '__return_false' );` to that site's code. Page views stop being counted and the Visitors part of the card is hidden. (Enquiry counting has its own switch, below.)

Limits: each month's numbers are read, increased and saved back, so on a very busy site two page views at the same instant could count as one. At most 200 page addresses are kept per month; when a new page would go over that, the page with the fewest views is dropped, so the list is approximate for rarely viewed pages (fine for a top-20). Each counted page view is one small background request to the site (plus a few small temporary records for the spam limits), so a Redis (or similar) object cache is recommended; it is fine for small clinic sites but not meant for high-traffic sites. Page addresses are shown on the card decoded for readability (for example `/café`). The paths use the web address that was requested, so sites using "plain" permalinks (`?p=123`) would see every page as "/"; use pretty permalinks. If the site's own pages send no referrer (a strict "no-referrer" policy), every page view looks like a visit.

= Enquiry actions (quarterly report figures) =

The same card has an "Enquiry actions" table showing, for this quarter and last quarter (calendar quarters: Jan-Mar, Apr-Jun, Jul-Sep, Oct-Dec):

* Phone number clicks - clicks on any `tel:` link.
* Email link clicks - clicks on any `mailto:` link.
* Online booking link clicks - clicks on links to Cliniko, Jane (janeapp.com), Acuity (acuityscheduling.com and as.me), Calendly, Setmore, SimplyBook.me or Fresha, plus the optional "Online booking link" the client enters on the Business details page (a whole site such as `https://clinic.cliniko.com`, or a page/folder on the client's own site such as `https://clinic.co.uk/book`). The booking link is not published to Google.
* Contact form sends - successful sends of a Divi contact form (counted on the server through Divi's `et_pb_contact_form_submit` action, only when Divi reports no error).

Clicks show interest - a booking click is not a confirmed appointment.

How it works: a tiny script (`assets/webcare-track.js`, no jQuery) listens for link clicks on public pages and sends only the type of click ("phone", "email" or "booking") to `POST /wp-json/webcare/v1/event`. It never delays or blocks the click. It is not loaded in the admin area, the Customizer, the Divi visual builder, or for logged-in Editors/Administrators (so testing doesn't inflate the numbers). No nonce is used on purpose: pages are cached, so a nonce printed into a page would be out of date by the time a visitor clicked. Instead the address ignores unknown event types (400 error), robots and crawlers (no browser name, or a name containing "bot", "crawl", "spider", "headless", a web address, or a known tool such as curl or Lighthouse), requests whose Origin/Referer is another website, anything over 30 events per visitor per 10 minutes, and anything over 300 events per hour across the whole site.

Privacy (visitor numbers and enquiry actions): these figures are anonymous totals. No cookies and nothing stored on visitors' devices (no localStorage or sessionStorage either); no names or contact details. We keep monthly totals and a list of the most-viewed page addresses. To limit spam, a one-way scrambled code based on the visitor's connection is kept briefly (normally 10 minutes) and then deleted. Suggested wording for the client's privacy statement (Complianz): "anonymous counts of visits and page views, and of clicks on phone, email and booking links". Webcare does not change any Complianz settings.

Switching it off on one site: add `add_filter( 'webcare_track_enquiries', '__return_false' );` to that site's code. Clicks and form sends are no longer counted and the Enquiry actions table is hidden. The script is only left out of pages if visitor counting (`webcare_track_visits`) is switched off too, and if both are off the card says tracking is switched off.

Limits: the monthly total is read, increased by one and saved, so on a very busy site two clicks at the same instant could count as one (fine for small clinic sites). If a security plugin blocks the public REST API for visitors, clicks cannot be counted. Counting starts from the first page view after the update (shown on the card as "Counted since tracking started on ..."). The booking-link setting is built into cached pages, so a changed booking link can take a few minutes (until the page cache refreshes) to start counting.

= What is stored =

* `webcare_business_details` - what the client typed on the Business details page (including the optional online booking link).
* `webcare_stats_YYYY_MM` - one small option per month (for example `webcare_stats_2026_10`) holding four totals: phone, email, booking and form. Not autoloaded.
* `webcare_visits_YYYY_MM` - one option per month (for example `webcare_visits_2026_10`) holding page views, visits, visits by source (search, ai, social, other, direct), visits by device (mobile, tablet, desktop), up to 200 page addresses with their view counts, and a "capped" note if some page views were turned away by the spam limits. Not autoloaded.
* `webcare_visits_started` - the date the first page view was recorded (used for "counted since" on the Visitors part and to show last quarter as a dash if it ended before then).
* `webcare_tracking_started` - the date enquiry counting began.

All of these are deliberately kept if the plugin is deleted, so details and history survive an accidental reinstall; there is no uninstall clean-up. Saving business details does not clear any caches, so changes can take a few minutes to appear. Webcare also keeps a one-minute "please wait" marker to stop accidental double-sending of change requests, the typed form values for up to 5 minutes after a failed submission so nothing is lost, and the temporary spam-limit markers described above (10 minutes per visitor, 1 hour for the whole site; separate ones for enquiry clicks and page views).

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

= 1.3.0 =
* New "Your website this quarter" card: visits, page views, most-viewed pages, where visits came from (search, AI assistants, social, other, direct) and the mobile/tablet/desktop share, as anonymous monthly totals counted by Webcare itself. No cookies and nothing stored on visitors' devices. Can be switched off per site with the `webcare_track_visits` filter. The enquiry actions table now sits in the same card.

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
