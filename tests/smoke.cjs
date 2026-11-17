// Browser smoke test for a LOCAL instance seeded with tests/seed_demo.php.
//   BASE=http://127.0.0.1:8088 SHOTS=/some/dir node tests/smoke.cjs
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8088';
const SHOTS = process.env.SHOTS || '';
const EMAIL = process.env.PLAT_EMAIL || 'owner@example.com';
const PASS = process.env.PLAT_PASSWORD || 'correct horse battery staple';

(async () => {
  const browser = await chromium.launch();
  const problems = [];
  const run = async (name, viewport, fn) => {
    const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    page.on('console', m => { if (m.type() === 'error' && !/tile\.openstreetmap|ERR_TUNNEL|Failed to load resource/.test(m.text())) problems.push(`[${name}] console: ${m.text()}`); });
    page.on('pageerror', e => problems.push(`[${name}] js: ${e.message}`));
    page.on('response', r => { if (r.status() >= 500) problems.push(`[${name}] ${r.status()} ${r.url()}`); });
    const shot = async (label, full = false) => { if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}-${label}.png`, fullPage: full }); };
    try { await fn(page, shot); } catch (e) { problems.push(`[${name}] ${e.message.split('\n')[0]}`); await shot('FAILED'); }
    await ctx.close();
  };
  const login = async page => {
    await page.goto(BASE + '/login');
    await page.fill('input[name=email]', EMAIL);
    await page.fill('input[name=password]', PASS);
    await Promise.all([page.waitForURL(BASE + '/'), page.click('button[type=submit]')]);
  };
  const expectText = async (page, text) => { if (!(await page.content()).includes(text)) throw new Error(`missing text: ${text}`); };

  await run('desktop', { width: 1440, height: 900 }, async (page, shot) => {
    await page.goto(BASE + '/login'); await shot('01-login');
    await login(page); await shot('02-home');
    await page.fill('input[name=q]', 'Austin, TX');
    await Promise.all([page.waitForNavigation(), page.click('.bigsearch button[type=submit]')]);
    await expectText(page, 'homes for sale in Austin, TX'); await page.waitForTimeout(600); await shot('03-results');
    // filters
    await page.fill('input[name=price_max]', '600k'); await page.selectOption('select[name=beds_min]', '3');
    await Promise.all([page.waitForNavigation(), page.click('.sbar button[type=submit]')]);
    await expectText(page, 'in Austin, TX'); await shot('04-filtered');
    // save search + watch first home
    await Promise.all([page.waitForNavigation(), page.click('form[action="/search/act"] button:has-text("Save")')]);
    if (!/Saved\.|already saved/.test(await page.content())) throw new Error('saving the search gave no confirmation');
    await Promise.all([page.waitForNavigation(), page.locator('.hrow .star').first().click()]);
    if ((await page.content()).includes('Removed from your watchlist')) { // left starred by an earlier run — star it again
      await Promise.all([page.waitForNavigation(), page.locator('.hrow .star').first().click()]);
    }
    await expectText(page, 'Added to your watchlist');
    // property page
    await Promise.all([page.waitForNavigation(), page.locator('.hrow .addr').first().click()]);
    await expectText(page, 'Comparable sales'); await page.waitForTimeout(500); await shot('05-property', true);
    await page.fill('#notes-box', 'Roof looked new.');
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Save note")')]);
    await expectText(page, 'Roof looked new.');
    // sold search
    await page.goto(BASE + '/?q=78704&mode=sold'); await expectText(page, 'in 78704'); await shot('06-sold');
    await page.goto(BASE + '/watchlist'); await expectText(page, 'in play'); await shot('07-watchlist');
    // alert
    await page.goto(BASE + '/alerts/new?q=Austin%2C+TX&price_max=600000&beds_min=3'); await shot('08-alert-new', true);
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Create alert")')]);
    if (page.url().includes('/alerts/new')) { // no channel configured yet → expected validation message
      await expectText(page, 'Pick at least one place');
    }
    await page.goto(BASE + '/alerts'); await shot('09-alerts');
    await page.goto(BASE + '/market'); await shot('10-market');
    await page.goto(BASE + '/add'); await shot('11-add', true);
    await page.goto(BASE + '/sites'); await shot('12-sites', true);
    await page.goto(BASE + '/settings'); await shot('13-settings', true);
  });

  await run('mobile', { width: 390, height: 844 }, async (page, shot) => {
    await login(page); await shot('01-home');
    await page.goto(BASE + '/?q=Austin%2C+TX'); await page.waitForTimeout(400); await shot('02-results');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (overflow > 1) throw new Error(`results page scrolls sideways by ${overflow}px`);
    await page.locator('.hrow .addr').first().click(); await page.waitForLoadState(); await shot('03-property', true);
    const o2 = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (o2 > 1) throw new Error(`property page scrolls sideways by ${o2}px`);
  });

  await browser.close();
  if (problems.length) { console.log('PROBLEMS:\n' + problems.join('\n')); process.exit(1); }
  console.log('Smoke test passed.');
})();
