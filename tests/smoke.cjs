// Browser smoke test for a LOCAL instance seeded with tests/seed_demo.php (+ cron/run.php score).
//   BASE=http://127.0.0.1:8088 SHOTS=/some/dir node tests/smoke.cjs
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8088';
const SHOTS = process.env.SHOTS || '';
const PASS = process.env.PLAT_PASSWORD || 'correct horse battery staple';

(async () => {
  const browser = await chromium.launch();
  const problems = [];
  const run = async (name, viewport, fn) => {
    const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, acceptDownloads: true });
    const page = await ctx.newPage();
    page.on('console', m => { if (m.type() === 'error' && !/tile\.openstreetmap|overpass|ERR_TUNNEL|Failed to load resource/.test(m.text())) problems.push(`[${name}] console: ${m.text()}`); });
    page.on('pageerror', e => problems.push(`[${name}] js: ${e.message}`));
    page.on('response', r => { if (r.status() >= 500) problems.push(`[${name}] ${r.status()} ${r.url()}`); });
    const shot = async (label, full = false) => { if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}-${label}.png`, fullPage: full }); };
    try { await fn(page, shot); } catch (e) { problems.push(`[${name}] ${e.message.split('\n')[0]}`); await shot('FAILED', true); }
    await ctx.close();
  };
  const login = async (page, email) => {
    await page.goto(BASE + '/login');
    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', PASS);
    await Promise.all([page.waitForURL(BASE + '/'), page.click('button[type=submit]')]);
  };
  const has = async (page, text) => { if (!(await page.content()).includes(text)) throw new Error(`missing text: ${text}`); };
  const noOverflow = async (page, what) => {
    const o = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (o > 1) throw new Error(`${what} scrolls sideways by ${o}px`);
  };
  let propertyUrl = '';

  await run('welcome', { width: 390, height: 844 }, async (page, shot) => {
    await page.goto(BASE + '/');
    if (!page.url().endsWith('/welcome')) throw new Error('signed-out home does not open the welcome page: ' + page.url());
    await has(page, 'Find the houses worth fixing');
    const frameAt = () => page.evaluate(() => { const c = document.querySelector('.wl-media canvas'); const x = c.getContext('2d').getImageData(c.width >> 1, c.height >> 1, 1, 1).data; return [x[0], x[1], x[2]].join(','); });
    await page.waitForTimeout(800);
    const first = await frameAt();
    await page.evaluate(() => { const t = document.querySelector('.wl-scroll'); window.scrollTo(0, t.offsetTop + (t.offsetHeight - innerHeight) * 0.5); });
    await page.waitForTimeout(1500);
    const later = await frameAt();
    if (first === '0,0,0' || later === '0,0,0') throw new Error('the flight is not drawn');
    if (first === later) throw new Error('scrolling does not move the flight');
    const ch = await page.evaluate(() => document.querySelector('.wl-ch.is-on').dataset.ch);
    if (ch !== '3') throw new Error('half way should show chapter 3, shows ' + ch);
    await noOverflow(page, 'welcome'); await shot('01-flight');
    const deep = await page.request.get(BASE + '/pipeline', { maxRedirects: 0 });
    if (!(deep.headers()['location'] || '').includes('/login')) throw new Error('a deep link should still ask to sign in');
  });

  await run('admin', { width: 1440, height: 900 }, async (page, shot) => {
    await page.goto(BASE + '/login'); await shot('01-login');
    await login(page, 'owner@example.com');
    if (await page.locator('button[data-menu]').isVisible()) throw new Error('menu button shows on a wide screen');
    if (!(await page.locator('.nav a[href="/reports"]').isVisible())) throw new Error('top links missing on a wide screen');
    if (await page.locator('.nav-more').isVisible()) throw new Error('phone-only links show on a wide screen');
    await has(page, 'Strong opportunities'); await shot('02-dashboard', true);
    await page.goto(BASE + '/search'); await has(page, 'Renovation &amp; condition'); await shot('03-search-filters', true);
    await page.fill('#dq', 'x').catch(() => {});
    await page.goto(BASE + '/search?q=Austin%2C+TX&types%5B%5D=Single+Family&types%5B%5D=Townhouse&sort=score');
    await has(page, 'in Austin, TX'); await page.waitForTimeout(600); await shot('04-results');
    await page.click('details.drop-wide > summary'); await page.waitForTimeout(200); await shot('05-filter-drawer');
    await page.keyboard.press('Escape');
    // condition filter + listing status
    await page.goto(BASE + '/search?q=Austin%2C+TX&cond%5B%5D=needs_renovation&cond%5B%5D=major_repair&sort=profit');
    await has(page, 'needs renovation'); await shot('06-fixers');
    // multi-area search asks before spending requests (no provider here, so it just shows saved data)
    await page.goto(BASE + '/search?q=78704%2C+78745'); await has(page, '78704 · 78745');
    // save profile
    await page.goto(BASE + '/search?q=Austin%2C+TX&sort=score');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Save as profile")')]);
    if (!/Saved as a profile|already have that profile/.test(await page.content())) throw new Error('profile not saved');
    // open best home
    await Promise.all([page.waitForNavigation(), page.locator('.hrow .addr').first().click()]);
    propertyUrl = page.url();
    await has(page, 'Deal analysis'); await has(page, 'Condition &amp; renovation'); await page.waitForTimeout(500);
    await shot('07-property', true);
    // add to pipeline and take it
    if (await page.locator('button:has-text("Add to pipeline")').count()) {
      await Promise.all([page.waitForNavigation(), page.locator('button:has-text("Add to pipeline")').first().click()]);
    }
    await Promise.all([page.waitForNavigation(), page.selectOption('.pipe-card select[name=stage]', 'under_review')]);
    await has(page, 'Changed just now');
    // note
    await page.fill('#notes-box', 'Drove by: roof looks new, side yard overgrown.');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Add note")')]);
    await has(page, 'Drove by: roof looks new');
    // deal numbers
    await page.click('details.deal-edit > summary >> nth=0');
    await page.fill('input[name=reno_cost]', '45,000');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Save and recalculate")')]);
    await has(page, 'recalculated'); await has(page, 'Changed reno cost');
    // condition by hand
    await page.click('#condition details.deal-edit > summary');
    await page.selectOption('#condition select[name=overall]', 'needs_renovation');
    await page.selectOption('#condition select[name=roof]', 'needs_work');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Save condition")')]);
    await has(page, 'set by the team'); await shot('08-property-after', true);
    await page.goto(BASE + '/pipeline'); await has(page, 'Under review'); await shot('09-pipeline', true);
    await page.goto(BASE + '/reports'); await has(page, 'By county'); await shot('10-reports', true);
    const [dl] = await Promise.all([page.waitForEvent('download'), page.click('a:has-text("Excel")')]);
    if (!(await dl.suggestedFilename()).endsWith('.xlsx')) throw new Error('report download is not xlsx');
    await page.goto(BASE + '/alerts/new?q=Austin%2C+TX'); await shot('11-alert-new', true);
    await page.goto(BASE + '/users'); await has(page, 'Invite someone'); await shot('12-team', true);
    await page.fill('form input[name=name]', 'New Person'); await page.fill('form input[name=email]', 'newperson@example.com');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Create invite link")')]);
    await has(page, 'Invitation link for newperson@example.com');
    await page.goto(BASE + '/add'); await has(page, 'Add from a listing link'); await shot('13-add', true);
    await page.goto(BASE + '/settings'); await shot('14-settings', true);
    await page.goto(BASE + '/account'); await shot('15-account', true);
  });

  await run('partner', { width: 1440, height: 900 }, async (page, shot) => {
    await login(page, 'partner@example.com');
    await page.goto(propertyUrl);
    await has(page, 'is handling this home'); await shot('01-claimed');
    const r = await page.request.get(BASE + '/users');
    if (r.status() !== 403) throw new Error('partner could open the team page: ' + r.status());
    // their own WhatsApp, kept apart from everyone else's
    await page.goto(BASE + '/account');
    await page.fill('input[name=whatsapp_phone]', '+1 804 555 0123');
    await page.fill('input[name=callmebot_key]', '123456');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Save WhatsApp")')]);
    await has(page, 'WhatsApp saved'); await has(page, 'on for your alerts');
    if (!(await page.locator('form:has(input[name=channel][value=whatsapp]) button').isEnabled())) throw new Error('WhatsApp test button stays disabled');
    await shot('02-account-whatsapp', true);
  });

  await run('viewer', { width: 1440, height: 900 }, async (page, shot) => {
    await login(page, 'viewer@example.com');
    await page.goto(propertyUrl);
    if (await page.locator('#notes-box').count()) throw new Error('viewer sees the note form');
    if (await page.locator('a:has-text("Add a home")').count()) throw new Error('viewer sees Add a home');
    await shot('01-property', true);
    const token = await page.locator('input[name=_csrf]').first().getAttribute('value');
    const id = propertyUrl.split('/').pop();
    const res = await page.request.post(`${BASE}/property/${id}/act`, { form: { _csrf: token, do: 'note', note: 'should fail' } });
    if (res.status() !== 403) throw new Error('viewer could post a note: ' + res.status());
  });

  await run('mobile', { width: 390, height: 844 }, async (page, shot) => {
    await login(page, 'owner@example.com');
    await shot('01-dashboard', true); await noOverflow(page, 'dashboard');
    // main menu: a button opens a panel with every page; Esc and a link close it
    const btn = page.locator('button[data-menu]');
    if (!(await btn.isVisible())) throw new Error('no menu button on a phone');
    if (await page.locator('#main-nav a[href="/reports"]').isVisible()) throw new Error('menu links show before the button is pressed');
    await btn.click();
    if ((await btn.getAttribute('aria-expanded')) !== 'true') throw new Error('menu button does not report open');
    for (const href of ['/', '/search', '/pipeline', '/alerts', '/reports', '/market', '/add', '/account', '/settings']) {
      if (!(await page.locator(`#main-nav a[href="${href}"]`).isVisible())) throw new Error('menu is missing ' + href);
    }
    if (!(await page.locator('#main-nav button:has-text("Sign out")').isVisible())) throw new Error('menu has no sign out');
    await noOverflow(page, 'open menu'); await shot('01b-menu-open');
    await page.keyboard.press('Escape');
    if (await page.locator('#main-nav a[href="/reports"]').isVisible()) throw new Error('Esc does not close the menu');
    await btn.click();
    await Promise.all([page.waitForURL(BASE + '/pipeline'), page.locator('#main-nav a[href="/pipeline"]').click()]);
    if (await page.locator('#main-nav a[href="/reports"]').isVisible()) throw new Error('menu stays open after moving page');
    await page.goto(BASE + '/search?q=Austin%2C+TX&sort=score'); await page.waitForTimeout(400); await shot('02-results');
    await noOverflow(page, 'results');
    await page.goto(propertyUrl); await page.waitForLoadState(); await shot('03-property', true);
    await noOverflow(page, 'property');
    await page.goto(BASE + '/pipeline'); await noOverflow(page, 'pipeline');
    // an empty state with its sketch and Persian notes
    await page.goto(BASE + '/property/99999999');
    if (!(await page.locator('figure.sketch svg').count())) throw new Error('empty state has no sketch');
    await page.evaluate(() => document.fonts.ready);
    if (!(await page.evaluate(() => document.fonts.check('16px Vazirmatn')))) throw new Error('Persian font did not load');
    await noOverflow(page, 'empty state'); await shot('04-empty-state');
  });

  await browser.close();
  if (problems.length) { console.log('PROBLEMS:\n' + problems.join('\n')); process.exit(1); }
  console.log('Smoke test passed.');
})();
