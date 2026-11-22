# Virginia Property Intelligence

Plat uses PHP 8.1+, plain PHP templates, CSS and JavaScript, PDO, and MySQL or MariaDB. It has a front controller in public/index.php. Existing RentCast and OpenWeb Ninja connections supply listings and property details. Recorded county sales, Census, FEMA, OpenStreetMap and Zillow Research provide additional evidence.

Opening a property page starts analysis for that property through its authenticated AJAX request. Search results do not request reports. The database migration runs automatically at the next application request. Existing properties, calculations and source caches are reused.

## Configuration

Add the following to the private .env beside config.php. The key stays on the server.

    OPENROUTER_API_KEY=your_private_key
    OPENROUTER_MODEL=openrouter/free

The default router and any configured :free model are restricted to providers with zero prompt and completion prices. OpenRouter free models have account and model rate limits. Read current limits at https://openrouter.ai/docs/api/reference/limits. A free key cannot guarantee unlimited or immediate reports. No provider key means public evidence can still be collected and saved.

Run this job every two minutes in addition to the existing scheduled jobs, using the real installation path.

    php /path/to/app/cron/run.php intelligence

The existing auto and score jobs also process analysis work. Each background pass processes one previously opened property. An open page starts its own property immediately through the POST endpoint. Data collection and AI interpretation can take separate passes on slower hosts. The queue deduplicates properties, uses a property-specific database lock shared by CLI and HTTP workers, retries interrupted work, and resumes after temporary source failures. Keep one regular scheduler active to retry work for properties that were opened.

## Reports and APIs

Opening a property marks only its report as requested, starts a targeted worker through JavaScript, and polls its status. The worker always selects that property ID. Recently opened homes have background retry priority. Legacy bulk requests stay dormant until their own property page is opened.

Reports are refreshed after 48 hours when opened again. Property and calculator edits invalidate the stored input fingerprint. The refresh action requests an earlier update. Source collection and AI interpretation may take separate requests on slower hosts. Cached completed reports are reused.

Authenticated endpoints are available through the existing session and CSRF checks.

- POST /api/property-analysis accepts property_id and processes only that existing opened property.
- GET /api/property-analysis/{id} returns its stored report and status.
- GET /api/property-analysis/{id}/status returns status and rendered report content.
- POST /api/property-analysis/{id}/refresh requires edit permission and requests a fresh report.

property_analyses holds one current snapshot, AI narrative, source evidence, model, status, input fingerprint and retry state per property. The earlier analysis_searches table is dormant. It is retained for upgrade compatibility. api_log records model call status and duration without prompts, keys or model error bodies. Existing api_cache shares county and tract statistics between properties.

## Sources and calculation

The collector obtains Census county and tract statistics through Census Reporter, BLS employment, Freddie Mac mortgage rates through FRED, FEMA flood evidence, OpenStreetMap places and construction observations, OSRM road routes, and VDOT approved-program projects within three miles in Virginia. Historical Census growth requires the configured free Census key. Sources may be unavailable or throttled. Their missing values stay null. Map construction is an observation, with official project approval and completion still unknown. Crime, school quality, employers, business formation, building permits and insurance quotes remain missing until verified datasets are integrated.

The server calculates six components with weights 40, 20, 15, 10, 10 and 5. Final score requires all six. Risk is higher when measured risk is lower. Financial verdict and property score are shown alongside the model's interpretation.

Profitability averages profit as a fraction of the configured target and ROI as a fraction of 25%, bounded to 0–100. Housing needs eight linked same-type nearby sales, using closing time and sale-to-asking ratio. Nearby samples are within one mile and 35% of floor area. Regional economic and migration calculations reuse the disclosed regional model. Infrastructure uses actual route distances where at least four destination routes are available. Risk averages FEMA risk and measured property tax. Each report shows these rules and remaining gaps. The calculated recommendation requires complete evidence. A score of 75 or higher and a nonnegative cautious profit can qualify as potentially attractive. Scores from 55 require further review. A measured loss is flagged as high financial risk even when other evidence is missing.

Age, education and family composition are descriptive only and excluded from scoring. County statistics retain county scope. Property-local sales and road routes are specific to the subject's coordinates. Census tract figures retain the verified tract identifier.

AI receives the server's evidence packet and financial verdict. It returns bilingual prose and citations to evidence IDs. It cannot supply numeric scores. The response validator rejects a different property ID, unknown evidence IDs, incomplete sections, numerical claims, HTML and links. Numbers are displayed from the stored calculation packet. AI interpretation still requires human review before a purchase.
