# Reliable listing access

Reviewed 2026-11-24. The production screenshot reports HTTP 403 from Homes.com. It also shows an unrelated error: Tampa Bay Area was parsed as a city in Virginia. An MCP connection cannot repair a wrong location or grant access to a provider that has refused this hosting server.

## Recommended route

Use a licensed MLS feed as the listing source, with a PHP server-side API adapter. For the Florida search, MLS Grid's official network page explicitly lists Stellar MLS (FL/PR). Confirm the exact territory, fields, photo-display rights, historical-sale access and approved use with the MLS before enabling it. MLS Grid describes RESO-compliant IDX, VOW and back-office feeds, with a subscription and broker/MLS approval process. Those permissions are not interchangeable and do not imply nationwide access.

- https://www.mlsgrid.com
- https://www.mlsgrid.com/resources

SimplyRETS is an alternative integration layer when a broker already has RETS or RESO Web API credentials. Its official page describes this requirement and lists a Basic plan at $49/month plus a $99 connection fee per feed at review time. MLS charges and additional feeds can cost extra. Its public `simplyrets:simplyrets` credentials are demonstration data and must never be used as live listing evidence in this project.

- https://simplyrets.com

No purchase, subscription, MLS agreement or new credential was created. No new provider is presented as connected. Model Context Protocol is an optional interface above the authenticated data source, not a source of unlimited MLS records, photos or redistribution rights. A REST adapter is sufficient for this PHP site; adding MCP only makes sense when an AI client needs its tools.

Keep Census/BLS/FEMA and the existing calculation engine for sourced area statistics and risk. They do not supply current listing photos or substitute for MLS inventory. Keep Homes.com optional and explicitly unavailable on HTTP 403, rather than treating a development-machine success as proof of hosting access. Only refresh and display source evidence whose address, unit and geography match the requested property.

## Location correction

`Tampa Bay`, `Tampa Bay Area` and their Florida variants expand to the Tampa–St. Petersburg–Clearwater metropolitan definition (CBSA 45300, July 2023 OMB delineation): Hernando, Hillsborough, Pasco and Pinellas counties, FL. The UI names this boundary explicitly. This is geographic configuration, not a claim about current listing coverage. Existing multi-area quota confirmation and partial-coverage notices remain in effect.

Reference: https://www.census.gov/programs-surveys/metro-micro/about/delineation-files.html

Unqualified city lookups search US map candidates instead of appending the default state. A unique verified city supplies its actual state. Multiple namesakes or an unverified live lookup require a city/state or ZIP; they never spend listing quota on an arbitrary default-state city. Explicit state constraints remain enforced. Cached saved-inventory/offline lookup behavior is retained.
