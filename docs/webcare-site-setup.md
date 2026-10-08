# Webcare - setting up a new client site (for APM staff)

Do these steps once for every client site, after the Webcare plugin is installed.
Total time: about 15 minutes.

Webcare counts visitors, page views and enquiry actions by itself. There is **no separate analytics plugin to install** (no Independent Analytics, no Google Analytics).

## 1. Confirm the other plugins are present

- **Yoast SEO** (free) is installed and active.
- **Complianz** (free) is installed and active.

(Webcare adds the client's business details to Yoast's own markup. If the site uses a different SEO plugin, Webcare says so on the Business details page - ask the developer.)

## 2. Fill in Webcare > Business details

Go to **Webcare > Business details** and fill in everything you can:

- Type of business, business name (full name - this one is required), short description
- Phone, email, address
- Opening hours (tick **Closed** for days they are shut - blank days are treated as closed)
- Services and areas served
- **Online booking link** - the address of the client's online booking page, if they have one (for example `https://theirclinic.cliniko.com` or `https://theirsite.co.uk/book`). Webcare already recognises Cliniko, Jane, Acuity, Calendly, Setmore, SimplyBook.me and Fresha, but filling this in makes sure their own booking page is counted too. Leave it blank if they don't take bookings online.

Press **Save business details**. Because of page caching, changes can take a few minutes to appear.

## 3. Test the visitor counts

Staff who are logged in as Editor or Administrator are **never counted**, so test in a private window.

1. Note the numbers on **Webcare > Overview > Your website this quarter** (Visits and Page views).
2. Open the live site's home page in a **private/incognito window** (not logged in), typing the address in directly.
3. Click through to **one other page** on the site.
4. Back in the admin, refresh **Webcare > Overview** after a minute. You should see **Page views +2** and **Visits +1** (the home page was a visit; the second page was only a page view because you came from inside the site).
5. If nothing changes, purge the Breeze cache, repeat in a new private window and check again.

Visits are counted when someone arrives from outside the website (a search engine, another site, a typed address or bookmark). They are **not** a count of unique people. Reloading a page, or using the browser's back/forward buttons, is **not** counted again, so test by opening two different pages (don't reload). Pages that were cached before the plugin update carry no page code yet and are not counted until the page cache is purged (Breeze > purge all). The same applies if the site's WordPress security keys ("salts") are ever changed: purge the cache afterwards, or page views stop counting until the cached pages refresh.

Use pretty permalinks (the default on our sites). With "plain" permalinks (`?p=123`) every page would show as "/".

## 4. Test the enquiry tracking

1. In the same private/incognito window, click a **phone number** link (on a phone/tablet, or on desktop click any "tel:" link).
2. Click a **booking** link (for example the "Book now" button).
3. Click an **email** link if the site has one.
4. Send a **test contact form** (fill in the Divi contact form and submit it - you should see the normal "thank you" message).
5. Log in as an Administrator, go to **Webcare** and look at the **Enquiry actions** table in the same card.
   - Refresh the page after a minute. You should see 1 more for each thing you tried.
   - If a number is missing, wait a few minutes, purge the Breeze cache, repeat the test in a new private window and check again.
6. If the contact form is still not counted, tell the developer which form it was (some forms are not made with Divi and cannot be counted).

Things that stop counting: a security plugin that blocks the public REST API for visitors, or a site-specific setting that switches counting off (`webcare_track_visits` for visitor numbers, `webcare_track_enquiries` for enquiry actions).

## 5. Update the privacy statement (Complianz)

Webcare counts visits, page views and clicks as anonymous totals. No cookies and nothing stored on visitors' devices; no names or contact details. We keep monthly totals and a list of the most-viewed page addresses. To limit spam, a one-way scrambled code based on the visitor's connection is kept briefly (normally 10 minutes) and then deleted. Do **not** change any Complianz settings, but do update the wording in the client's privacy statement so it mentions:

> anonymous counts of visits and page views, and of clicks on phone, email and booking links

Do this in the Complianz privacy statement (or the client's own privacy page, wherever they list what they measure).

**Rollout note (existing sites):** clients whose privacy statement already quotes the earlier wording ("anonymous counts of clicks on phone, email and booking links") need it updated to also mention anonymous visit and page-view counts, now that Webcare 1.3.0 counts those too.

## 6. Final check

- Webcare > Overview shows the **Your website this quarter** card with the Visitors part and the Enquiry actions table.
- Business details status says it is being published.
- Note the date in the site's records so the first quarterly report knows when counting started (the card also shows "Counted since tracking started on ...").
