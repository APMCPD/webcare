# Webcare - setting up a new client site (for APM staff)

Do these steps once for every client site, after the Webcare plugin is installed.
Total time: about 15 minutes.

## 1. Visitor numbers: Independent Analytics (free)

1. In the WordPress admin go to Plugins > Add New, search for **Independent Analytics**, install and activate it.
2. Leave the settings on their defaults. It does not use cookies, so it does not need a cookie banner.
3. Open its settings and make sure logged-in **Administrators and Editors are excluded** from being counted (so our own visits don't inflate the numbers).
4. Check it works with page caching switched on (Breeze):
   - Open the live site in a **private/incognito window** and click through two or three pages.
   - Back in the admin go to **Analytics** in the left menu and confirm those visits appear (it can take a minute).
   - If nothing appears, purge the Breeze cache and try again. If it still doesn't record, tell the developer before carrying on.

## 2. Confirm the other plugins are present

- **Yoast SEO** (free) is installed and active.
- **Complianz** (free) is installed and active.

(Webcare adds the client's business details to Yoast's own markup. If the site uses a different SEO plugin, Webcare says so on the Business details page - ask the developer.)

## 3. Fill in Webcare > Business details

Go to **Webcare > Business details** and fill in everything you can:

- Type of business, business name (full name - this one is required), short description
- Phone, email, address
- Opening hours (tick **Closed** for days they are shut - blank days are treated as closed)
- Services and areas served
- **Online booking link** - the address of the client's online booking page, if they have one (for example `https://theirclinic.cliniko.com` or `https://theirsite.co.uk/book`). Webcare already recognises Cliniko, Jane, Acuity, Calendly, Setmore, SimplyBook.me and Fresha, but filling this in makes sure their own booking page is counted too. Leave it blank if they don't take bookings online.

Press **Save business details**. Because of page caching, changes can take a few minutes to appear.

## 4. Test the enquiry tracking

Staff who are logged in as Editor or Administrator are **never counted**, so test in a private window.

1. Open the live site in a **private/incognito window** (not logged in).
2. Click a **phone number** link (on a phone/tablet, or on desktop click any "tel:" link).
3. Click a **booking** link (for example the "Book now" button).
4. Click an **email** link if the site has one.
5. Send a **test contact form** (fill in the Divi contact form and submit it - you should see the normal "thank you" message).
6. Log in as an Administrator, go to **Webcare** and look at the **Enquiry actions this quarter** card.
   - Refresh the page after a minute. You should see 1 more for each thing you tried.
   - If a number is missing, wait a few minutes, purge the Breeze cache, repeat the test in a new private window and check again.
7. If the contact form is still not counted, tell the developer which form it was (some forms are not made with Divi and cannot be counted).

Things that stop counting: a security plugin that blocks the public REST API for visitors, or a site-specific setting that switches tracking off (`webcare_track_enquiries`).

## 5. Update the privacy statement (Complianz)

Webcare counts clicks as anonymous totals. No cookies, no names or contact details, and no record of which pages people visit. To stop spam, a temporary scrambled code based on the visitor's connection is set to expire after 10 minutes. Do **not** change any Complianz settings, but do update the wording in the client's privacy statement so it mentions:

> anonymous counts of clicks on phone, email and booking links

Do this in the Complianz privacy statement (or the client's own privacy page, wherever they list what they measure). If Independent Analytics is installed, mention it there too (cookie-free visitor statistics).

## 6. Final check

- Webcare > Overview shows the "Enquiry actions this quarter" card and says "Visitor numbers are recorded by Independent Analytics".
- Business details status says it is being published.
- Note the date in the site's records so the first quarterly report knows when counting started (the card also shows "Counted since tracking started on ...").
