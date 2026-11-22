# Exact home and building searches

The existing `/search` field accepts counties, cities, states, ZIPs and complete street addresses. `search_kind=building` explicitly selects a named building, avoiding confusion with a city name. Saved search profiles retain this selection. County and multi-county searches keep their previous behavior.

Exact addresses accept the existing comma-separated forms, a street-suffix-delimited address without commas, and pasted postal text such as:

```
5501 Seminary Rd
Apartment 1703 S
22041 Falls Church VA
```

Multiline browser paste preserves the lines as comma separators. Apartment markers and tower letters are normalized for comparison. Saved addresses are reused even when a pasted address omits a ZIP. A new address is saved for an editor and opens the usual property page, where the existing listing and individual AI-report fetches run. Census coordinates are attached only when the matched street and locality agree. An address alone does not establish a sale, price or other property facts.

Named searches (for example `Skyline Square, Falls Church, VA`) resolve matching U.S. building names through Photon/OpenStreetMap. Candidates require a name match, explicit street number, street, city and the requested state, with any requested city/ZIP also checked. They retain links to the actual OSM objects. Results cache for seven days; unavailable/empty map responses cache for fifteen minutes. This public service is best-effort and may throttle or omit buildings.

The existing Zillow-data source searches the building name within its existing quota. Saved or returned listings are included only when their source building name matches, or their street address matches a verified building address. A broad provider response cannot silently become the entire city's results. An unresolved name returns no matches. RentCast area search is not used for building names because it has no building-name filter. No new API key or database migration is required.

Map building matches are displayed separately from individual listings. A user can open a mapped address or enter a full address plus apartment number. A building location never supplies another apartment's details. Full listing enrichment now rejects a provider response with a different street, state, locality or unit before applying photos, price and other facts. School and investment reports continue to run only for the opened property.

Verification: `php tests/check.php` exercises pasted addresses, apartment identity, map name/locality checks, local building filtering, SQL/wildcard input, saved modes and rejection of a mismatched apartment response. Browser checks additionally cover multiline paste, navigation to the exact saved unit, named-building results and mobile layout. Live listing retrieval still depends on configured source keys and remaining quotas.
