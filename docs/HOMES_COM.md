# Homes.com as an additional search source

Manual searches retain the existing RentCast/Zillow provider and additionally read public Homes.com sale collection pages. The **Data sources** settings include an on/off control, enabled by default. No new API key or schema migration is required. PHP DOM/XML is needed; its absence fails closed without breaking the other sources.

Only the first public page is read, for at most three distinct areas and within a twelve-second network budget. Pages cache for one hour; failures cache for fifteen minutes. HTTP 403, 429 and 503 put the host on a one-hour cooldown. The reader does not use private endpoints, proxies, cookies or challenge bypasses. Availability on the production host still needs verification.

City, county and state pages use their known public URL shapes. A ZIP page uses a cached U.S. postal city/state from Zippopotam.us. County searches read the actual county page instead of sampling the entire state. Canonical URL and collection URL must match the requested page. Returned home state, city or ZIP is checked against the relevant scope. County membership is attributed to the verified county collection page, not an independently verified parcel lookup.

The parser reads `CollectionPage` / `ItemList` / `RealEstateListing` JSON-LD and verifies each item against its own visible for-sale card and complete address, including the unit. It requires a real Homes.com property URL, USD asking price and BuyAction. Fractional bathrooms are read from the visible card because the JSON-LD can round them. Photos come only from that listing's image field. Coordinates must belong to the home or an event with the same exact address. Unavailable facts remain absent. Sold collection pages are deliberately excluded; asking prices never become recorded sales.

Results enter the normal database and local filters. Identical addresses/units merge. Another apartment cannot receive the subject's photos or facts. Named-building searches match verified building addresses. Exact-address searches inspect the relevant ZIP/city page and import only the exact home; a missing unit in the first page is not proof that the property is absent from Homes.com.

`details.homes` records the original property URL, source page, observation time and source quote. Existing RentCast/Zillow prices and other known facts are retained; missing fields can be filled. The source-specific Homes.com quote is shown in the property page, and results have a Homes.com link. Reusing a cached observation does not repeatedly update the property or invalidate its AI report. New/changed homes enter the existing scoring engine, without queuing AI reports for unopened houses.

The UI states that coverage is partial and exposes source errors and observation times. A page being accessible during development does not guarantee future access, unlimited use or complete market coverage. Saved-search email imports remain available independently.

Validation includes offline collection/card fixtures, scope and unit mismatches, unavailable sources, source-quote separation, deduplication, cache reuse, no RentCast quota consumption, and browser checks of combined results and source details. Public ZIP 22041 and Fairfax County pages were also checked during development; full live listing content is not committed to the repository.
