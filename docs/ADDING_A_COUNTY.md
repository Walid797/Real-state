# Adding a county (or state) public-records source

The weekly check follows this, and so should anyone adding a source by hand. Accuracy comes
first: a county is only switched on after a live run on the server shows real records and sales.

## 1. Pick what to add

`php cron/run.php status` prints `Counties without a source (homes for sale)` — most homes first,
with their ZIP codes. Settings → Free sources shows the same list. Take the top two or three.

## 2. Find the official source

Search for the county assessor's / property appraiser's open data: an ArcGIS REST layer
(`…/rest/services/…/FeatureServer/N` or `MapServer/N`), an OData/JSON API, or a state-wide layer
(Maryland's SDAT data is one). Only the assessor's own data, or a state agency built on it —
never a commercial reseller, never scraping a website.

What a source needs: the site address with ZIP, and ideally assessed / market value, year built,
living area, bedrooms, bathrooms, lot, and recorded sales with price, date and a qualified /
arm's-length marker. A layer without values or sales is not worth adding (note it as such).

## 3. Probe it from the server — not from a laptop

Hosts often answer differently to the server, and the server is what runs the code.

1. Put the URLs in `bin/probe-urls.txt` (a directory, a service, a layer, or a `…/query?…` URL;
   prefix `names ` to list layer names only).
2. Commit, push, deploy, add a one-minute cron running `php bin/probe.php`, read its output,
   **delete the cron**.
3. Check, from real rows: field names and types; how dates are written (epoch ms, `YYYYMMDD`
   text, ISO); which sale codes mean a fair-market sale (`returnDistinctValues=true`);
   whether a ZIP-wide sales query returns a sensible count.

## 4. Write the source

One class in `src/Providers/County/` implementing `CountySource` (see `LeeCounty.php`,
`MiamiDade.php`, `Arlington.php`, `Maryland.php`, `DistrictOfColumbia.php`):

- `covers()` / `candidates()` — by state and county name; ZIP prefixes only as a fallback.
- `home()` — match the address with `Street::split` / `Street::same` / `Street::unitRank`,
  cache 30 days, return `facts` (PropertyRepo columns) and `county` (the record panel).
- `fairSale()` — the county's own qualified / arm's-length code. Never guess it.
- `salesInZip()` — fair-market sales of the last 12 months in one ZIP, residential, one row per
  parcel, with position for comps.
- Tax only when the rate is one known, adopted rate (cite it in a setting); otherwise none.
- Dates through `PropertyRepo::date()` (it reads `YYYYMMDD`, ISO and epoch ms).

Add it to `CountyRecords::SOURCES` (a city before the county around it), add a check to
`tests/check.php` built from a real sample row, and run `php tests/check.php` — all must pass.

## 5. Ship and verify live

Commit with a message naming the source and what it gives, push, deploy, then:

1. one-off cron `php cron/run.php county`, read the output, delete the cron;
2. one-off cron `php cron/run.php status`, read `Records by source` and `sales lists by source`,
   delete the cron.

The new source must show records for most of its homes and a non-zero sales count. If a number is
0 or odd, find the cause (address format, date format, sale codes, a mislabelled county) and fix
it before calling it done. Remove every one-off cron.

## 6. Report

Write the report in Persian first: which counties were added (records found / homes, sales
brought in), which have no usable public data and why, and what is next.
