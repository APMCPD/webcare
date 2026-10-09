# Webcare report feed - the contract (for developers)

Added in Webcare 1.4.0. This is what APM's health check app (Laravel, on a separate server) uses to fetch a client site's monthly figures for the quarterly report. It is read-only, and it only answers requests signed with the site's secret connection key.

Plugin code: `includes/report-feed.php`. Schema version: **1**.

## The request

```
GET https://<client-site>/wp-json/webcare/v1/report?from=2026_07&to=2026_09
X-Webcare-Timestamp: 1790000000
X-Webcare-Signature: c9f9b478e5a99b3005127fd873e51753c07ddeb4ac4c01931380b4ef7cfc08dd
```

Query parameters (only `from` and `to` are read and signed; both are required):

| Parameter | Format | Meaning |
|-----------|--------|---------|
| `from` | `YYYY_MM`, e.g. `2026_07` | First month wanted (inclusive) |
| `to` | `YYYY_MM`, e.g. `2026_09` | Last month wanted (inclusive) |

Any other query parameter is ignored by the plugin and is not part of the signed string. In particular the caller **may** append an extra unsigned `_=<anything>` (for example `&_=1790000000123`) to defeat a misconfigured edge cache or CDN that ignores `Cache-Control`. Put it after `to`; the canonical string must still contain only `from` and `to`, exactly as shown below.

Rules (checked after the signature, so a bad range gets `400`):

- Both exactly four digits, an underscore, two digits (month `01` to `12`). No spaces, no `-`.
- `from` must not be after `to`.
- At most **24 months** in one request (`to` minus `from` plus one).
- `to` may be at most **one month in the future** (by the website's own timezone calendar).

Months are the calendar months in the **website's timezone** (the `timezone` field of the answer).

## Signing

The key is the site's connection key: 64 lower-case hex characters, shown to Administrators on the Webcare page in the "Connection to APM" card. Use it **as the text string it is** (the 64 characters), not decoded to bytes.

1. `timestamp` = current unix time in whole seconds, as a string of digits (for example `1790000000`).
2. Build the **canonical string**, three parts joined by a single line-feed character (`\n`, byte 0x0A; no carriage return, no trailing newline):

   ```
   <timestamp> LF GET LF /webcare/v1/report?from=<from>&to=<to>
   ```

   For the example above the canonical string is exactly these 3 lines (shown here with real line breaks):

   ```
   1790000000
   GET
   /webcare/v1/report?from=2026_07&to=2026_09
   ```

   - The third line is the **route and query only**: no scheme, no host, no `/wp-json` prefix, and always `from` first then `to`, using exactly the same text as in the URL you send.
   - The method part is always the capital letters `GET`.
3. `signature` = lower-case hex of `HMAC-SHA256( canonical string, key )`. (Upper-case hex is also accepted.)
4. Send `timestamp` and `signature` in the headers `X-Webcare-Timestamp` and `X-Webcare-Signature`.

The site accepts a timestamp up to **300 seconds** (5 minutes) either side of its own clock, so keep the app server's clock accurate (NTP). A signature is not remembered after use, so it can be replayed inside that window; the answer contains nothing secret and the connection key itself never travels.

### Known-answer test vector

Use this to check an implementation:

| | |
|--|--|
| key | `0000000000000000000000000000000000000000000000000000000000000000` (64 zeros) |
| timestamp | `1790000000` |
| from / to | `2026_07` / `2026_09` |
| canonical string | `"1790000000\nGET\n/webcare/v1/report?from=2026_07&to=2026_09"` |
| signature | `c9f9b478e5a99b3005127fd873e51753c07ddeb4ac4c01931380b4ef7cfc08dd` |

### Examples

PHP / Laravel:

```php
$key  = $site->connection_key;                       // 64 hex characters
$from = '2026_07';
$to   = '2026_09';
$ts   = (string) time();
$path = '/webcare/v1/report?from=' . $from . '&to=' . $to;
$sig  = hash_hmac( 'sha256', $ts . "\n" . 'GET' . "\n" . $path, $key );

$response = Http::withHeaders( [
        'X-Webcare-Timestamp' => $ts,
        'X-Webcare-Signature' => $sig,
        'Accept'              => 'application/json',
    ] )
    ->timeout( 20 )
    ->get( rtrim( $site->url, '/' ) . '/wp-json' . $path );
```

Command line (bash + openssl):

```bash
KEY=<the 64 hex characters>
TS=$(date +%s)
FROM=2026_07; TO=2026_09
PATHQ="/webcare/v1/report?from=$FROM&to=$TO"
SIG=$(printf '%s\n%s\n%s' "$TS" "GET" "$PATHQ" | openssl dgst -sha256 -hmac "$KEY" -hex | sed 's/^.* //')
curl -s -H "X-Webcare-Timestamp: $TS" -H "X-Webcare-Signature: $SIG" \
     "https://example.co.uk/wp-json$PATHQ"
```

(If the site uses "plain" permalinks, the same request works as `/?rest_route=/webcare/v1/report&from=...&to=...`; the canonical string stays the `/webcare/v1/report?from=...&to=...` form.)

## The answer (`200`, JSON)

Every answer from this address (success and errors) is sent with `Cache-Control: no-store`, `Pragma: no-cache` and `Expires: 0`.

```json
{
  "schema": 1,
  "plugin_version": "1.4.0",
  "home_url": "https://example.co.uk/",
  "generated_at": "2026-10-09T09:30:12+00:00",
  "timezone": "Europe/London",
  "range": { "from": "2026_07", "to": "2026_09" },
  "tracking": {
    "enquiries_enabled": true,
    "visits_enabled": true,
    "enquiries_started": "2026-08-20",
    "visits_started": "2026-09-01"
  },
  "months": {
    "2026_07": {
      "enquiries": { "phone": 0, "email": 0, "booking": 0, "form": 0 },
      "visits": {
        "views": 0, "visits": 0,
        "sources": { "search": 0, "ai": 0, "social": 0, "other": 0, "direct": 0 },
        "devices": { "mobile": 0, "tablet": 0, "desktop": 0 },
        "pages": {},
        "capped": false
      }
    },
    "2026_08": { "...": "same shape" },
    "2026_09": {
      "enquiries": { "phone": 5, "email": 2, "booking": 7, "form": 1 },
      "visits": {
        "views": 120, "visits": 80,
        "sources": { "search": 40, "ai": 5, "social": 10, "other": 5, "direct": 20 },
        "devices": { "mobile": 50, "tablet": 5, "desktop": 25 },
        "pages": { "/": 60, "/about": 30, "/caf%C3%A9": 3 },
        "capped": true
      }
    }
  },
  "care": {
    "wp_version": "6.8.1",
    "php_version": "8.2.20",
    "theme": "Divi",
    "updates": { "core": 0, "plugins": 2, "themes": 0 },
    "active_plugins": 14
  },
  "business_details": { "complete": true, "published": "yoast" }
}
```

Field notes:

- `home_url`, `generated_at` (ISO 8601, UTC), `timezone` (the site's own setting as WordPress reports it). It is usually an IANA name such as `Europe/London`, but a site set to a fixed offset returns an offset such as `+01:00` (or `UTC`), so the app must accept both forms and must not assume an IANA name.
- `tracking.enquiries_started` / `visits_started`: the day (site timezone) counting began, or `null` if it has not started. Months before that date have no data (zeros), which is different from "no one visited": the report should say "not counted yet" for those months rather than show 0. `*_enabled` is `false` if counting was switched off on that site with the `webcare_track_enquiries` / `webcare_track_visits` filters.
- `months`: exactly one key per calendar month in the range, oldest first, keyed `YYYY_MM`. A month with no stored figures shows zeros and `"pages": {}` (always an object, never `[]`).
- `enquiries`: counts of clicks on phone (`tel:`), email (`mailto:`) and online-booking links, and Divi contact form sends. Clicks show interest; a booking click is not a booking.
- `visits.views` = page views. `visits.visits` = page views that arrived from outside the site (not unique people). `sources` and `devices` are per visit. `pages` = page path to views, at most 200 per month (the stored list; the app should sort it and take the top 20). `capped` = `true` if some page views that month were turned away by the spam limits (figures may be a little low).
- `care.updates`: how many WordPress core (0 or 1), plugin and theme updates WordPress currently knows about. This comes from WordPress's own saved update checks (refreshed about twice a day) and is `0` if unknown. `care.active_plugins` is the number of active plugins on this site.
- `business_details.complete`: enough details entered to be published (a business name and a phone or address). `published`: `yoast` (added to Yoast's markup), `webcare` (printed by Webcare), `other_seo_plugin` (another SEO plugin or Yoast Local SEO handles it), `none` (not enough details yet) or `off` (switched off on this site).
- Unknown or damaged stored values are cleaned to whole numbers (never negative) before they are sent. New fields may be added in later versions without changing `schema`; the app should ignore fields it does not know. A change that removes or renames a field will increase `schema`.

Never included: the connection key, the booking link, the client's business details, or anything about individual visitors (none is stored).

## Errors

Error bodies are the standard WordPress REST shape: `{ "code": "...", "message": "...", "data": { "status": 401 } }`.

| Status | `code` | When |
|--------|--------|------|
| `200` | | Success |
| `400` | `webcare_bad_range` | Signature correct, but `from`/`to` are not valid months, `from` is after `to`, more than 24 months, or `to` too far in the future |
| `401` | `webcare_unauthorised` | Anything wrong with the authentication: missing or malformed headers, wrong key, timestamp more than 5 minutes off, tampered `from`/`to`, or **no key created yet on this site**. The message is always the same ("Not authorised.") and never says which part failed. |
| `404` | `rest_no_route` | The feed has been switched off on this site (`webcare_report_feed` filter), or Webcare is not installed/active, or the REST API is blocked |
| `429` | `webcare_rate_limited` | A request that **failed authentication** came from a connection that has already made 20 failed attempts in the last 10 minutes. The 10 minutes run from the first of those attempts. A correctly signed request is **never** blocked by this limit (see below). |
| `500` | `webcare_feed_error` | Unexpected problem building the report |

Notes for the app:

- Treat `401` as "the key we hold is wrong, expired or was replaced (a client Administrator pressed "Create a new key"), or the clock is off". Check the app server's clock first, then ask for the new key.
- The signature is checked first. A correctly signed request is always answered, even if other wrong attempts have filled the limit (several visitors can share one connection address behind a proxy or CDN, so strangers must not be able to lock APM out). Only a request that fails authentication is counted (`401`), and once 20 have been counted in 10 minutes further failing requests get `429` and nothing more is recorded.
- Do not retry `401`s in a loop: it only adds to the failure count for the app's connection address on that site. Successful requests do not count towards the limit.
- **Onboarding step:** a new site returns `401` until the key exists, and the key is only created when an Administrator opens the main Webcare page once (Webcare menu in the WordPress admin). So for every new site, log in as an Administrator, open **Webcare**, and copy the key from the "Connection to APM" card into the app.

## The connection key

- Option `webcare_connection_key`: 64 lower-case hex characters from `random_bytes( 32 )`. Not autoloaded.
- Created the first time a user who can `manage_options` (an Administrator) opens the main Webcare page. Shown only to such users in the "Connection to APM" card, in a read-only box. Editors (who can otherwise use Webcare) never see it.
- "Create a new key" (a button with a confirmation question) posts to `admin-post.php?action=webcare_new_key`, which needs `manage_options` and a nonce. The old key stops working immediately.
- The key is never logged, returned by the feed or put in a web address.

## Other things that are stored

- `webcare_feed_last_fetch` (not autoloaded): unix time of the last successful fetch. Written at most once an hour. Shown as "Last fetched by APM" in the card.
- A temporary record (transient `webcare_rf_<20 character one-way code of the visitor's connection>`, kept up to 10 minutes) counts wrong attempts. The real address is never stored.

## Switching it off

On one site: `add_filter( 'webcare_report_feed', '__return_false' );` (for example in a code-snippets plugin). The address then does not exist at all and the Connection card says it is switched off.
