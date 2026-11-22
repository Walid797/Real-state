# Exact home and building searches

The single `/search` field detects counties, cities, states, ZIPs, street addresses and building names automatically. There is no type selector. Legacy `search_kind=building` URLs remain readable, while new profiles and URLs need only the query. All property types are included unless the user chooses filters, so a building search cannot silently exclude its condos and apartments. County and multi-county searches keep their previous behavior.

Structured queries are parsed locally. Ambiguous names and incomplete streets use bounded Photon/OpenStreetMap evidence, saved for seven days. Known saved cities/buildings and cached matches avoid repeated lookups. Persian/Arabic digits are normalized. The resolver verifies the requested/default state, building name and address; multiple distinct street matches require more locality instead of choosing a home arbitrarily. The detected type is displayed in search results. No LLM or paid API request is used for classification.

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

Verification: `php tests/check.php` exercises pasted and incomplete addresses, automatic building/city classification, Persian ZIP digits, ambiguous streets, apartment identity, map name/locality checks, local building filtering, SQL/wildcard input, legacy modes and rejection of a mismatched apartment response. Browser checks cover the single input, multiline paste, navigation to the exact saved unit, automatic building results and mobile layout. Live listing retrieval still depends on configured source keys and remaining quotas.
