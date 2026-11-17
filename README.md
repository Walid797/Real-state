# Plat

A private, single-user site for researching U.S. homes: search any city or ZIP with
filters, open a home for its full record, see what comparable homes nearby sold for,
follow prices on a watchlist, and get an email / WhatsApp / Telegram message when
something new matches.

Plain PHP 8.1+ and MySQL (or MariaDB). No framework, no build step, no Composer.

## Where the data comes from

| Source | What it gives | Cost |
| --- | --- | --- |
| [RentCast](https://developers.rentcast.io) | Listings for sale, county sale records, value and rent estimates | Free plan: 50 requests / month |
| [OpenWeb Ninja – Zillow data](https://www.openwebninja.com/api/real-time-zillow-data) (optional) | Photos, description, price and tax history, Zestimate, sold comparables | Free plan: 100 requests / month |
| U.S. Census geocoder | Map position, county, census tract | Free, no key |
| U.S. Census ACS | Neighbourhood income / home value / rent | Free key |
| FEMA flood hazard layer | Flood zone | Free |
| Zillow Research files | Monthly home values and market trends by ZIP / city | Free |
| OpenStreetMap | Map tiles | Free |

Nothing is scraped. Every metered request is stored in MySQL, so repeating a search,
opening a home again, computing comps or checking an alert against saved data costs
nothing. A monthly counter refuses to go past the free allowance, and keeps a share
of it back from alerts so manual searches always have some left.

Links to Zillow, Redfin, Realtor.com and others are ordinary links you open yourself;
the list is editable under **Sites**.

## Install

1. Point the (sub)domain's document root at the `public/` folder.
2. Create a MySQL database and user.
3. Copy `config.sample.php` to `config.php` in the project root (it is git-ignored and
   blocked from the web) and fill it in. Generate the keys with
   `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.
4. Open `https://your-site/setup?token=<setup_token>` and create the account —
   or run `php bin/install.php you@example.com`.
5. Sign in, open **Settings**, paste the free API keys, set up a delivery channel.
6. Add an hourly cron job: `php /path/to/project/cron/run.php`, and one every 10 minutes: `php /path/to/project/cron/run.php score` (scores newly pulled homes).
   `php cron/run.php status` prints usage, recent provider calls and the error log.
   (or call `https://your-site/cron?key=<cron_key>` hourly).

## Layout

```
public/      document root: front controller, CSS, JS, fonts, Leaflet, Chart.js
pages/       one file per screen
src/         Kernel (routing, security headers), Auth, Db, Settings (encrypted),
             Quota, PropertyRepo, Providers/*, Services/* (Search, Comps, Alerts…)
cron/run.php scheduled work: alerts, monthly market import, housekeeping
sql/         schema (safe to re-run)
tests/       local-only: seed data, logic checks, browser smoke test
```

## Security notes

- One account; Argon2id password hash; login throttling; 14-day sessions.
- CSRF token on every POST; strict Content-Security-Policy with no inline script or style.
- All SQL through real prepared statements.
- API keys, the SMTP password and bot tokens are AES-256-GCM encrypted in the
  database with `app_key`, which exists only in `config.php` on the server.
- The site answers only on the host in `base_url` and sends `noindex` everywhere.

## Testing locally

```
php -S 127.0.0.1:8088 -t public tests/router.php
php tests/seed_demo.php        # made-up Austin homes; refuses to run on a non-local base_url
php tests/check.php            # logic checks
node tests/smoke.cjs           # browser walk-through (needs Playwright)
```

## Limits worth knowing

- Sale prices are public-record figures. Texas, Utah and a few other states do not
  disclose them, so sold comps are thin there.
- RentCast returns at most 500 homes per request; in large cities narrow the filters.
- Alerts are as fresh as the schedule and the allowance let them be — one daily alert
  that pulls its own data uses about 30 requests a month.

Icons: Lucide (ISC). Fonts: Newsreader, Public Sans (OFL). Map: Leaflet (BSD-2).
Charts: Chart.js (MIT). Licences are in `public/assets/vendor/licenses/`.
