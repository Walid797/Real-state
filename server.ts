import express, { NextFunction, Request, Response } from 'express';
import cookieParser from 'cookie-parser';
import path from 'node:path';
import {
  areas,
  geoValid,
  parseFullAddress,
  parseLocation,
  stateAbbr,
  STATES,
  titleCity,
} from './lib/address.js';
import {
  ALERT_FREQUENCIES,
  ALERT_KINDS,
  analyseDeal,
  buildNearbyPlaces,
  buildPropertyContext,
  buildReportTables,
  COMP_MONTHS,
  COMP_RADII,
  compactSearch,
  computeScore,
  daysLabel,
  DEAL_SETTINGS,
  describeSearch,
  exportPropertiesCsv,
  exportReportsCsv,
  externalForArea,
  externalForProperty,
  findComps,
  MARKET_LABELS,
  NUMERIC_FILTERS,
  populateSyntheticMarketSeries,
  refreshScore,
  resolveLink,
  runAlertNow,
  runSearch,
  saveDeal,
  savedDeal,
  SCORE_WEIGHTS,
  scoreAllSeeded,
  scoreBand,
  searchFromRequest,
  searchProperties,
  validSiteTemplate,
} from './lib/analysis.js';
import {
  FILTER as CONDITION_FILTER,
  FLAGS as CONDITION_FLAGS,
  OVERALL as CONDITION_OVERALL,
  PARTS as CONDITION_PARTS,
  STATES as CONDITION_STATES,
} from './lib/condition.js';
import {
  ago,
  APP_ROOT,
  checked,
  daysAgoDate,
  e,
  fmtDate,
  fmtPhone,
  icon,
  jsonEmbed,
  money,
  moneyShort,
  now,
  num,
  parseNum,
  pct,
  plural,
  ppsf,
  selected,
  sketch,
  today,
  trimNum,
  url,
} from './lib/helpers.js';
import {
  ACTIVITY_LABELS,
  addNote,
  addPriceEvent,
  addToPipeline,
  Alert,
  can,
  db,
  DEFAULT_EXTERNAL_SITES,
  deleteNote,
  deleteProperty,
  findProperty,
  findSessionUser,
  formatLot,
  getCondition,
  getOrCreateSession,
  getPropertyEvents,
  getPropertyNotes,
  getSetting,
  hashPassword,
  hydrateProperty,
  LISTING_FILTER_LABELS,
  logActivity,
  MarketRegion,
  passwordProblems,
  PIPELINE_ACTIVE,
  PIPELINE_STAGES,
  Property,
  PROPERTY_SORTS,
  PROPERTY_TYPES,
  propertyActivity,
  propertyDom,
  propertyFacts,
  recentActivity,
  refreshCondition,
  removeFromPipeline,
  ROLES,
  seedDemoData,
  SessionData,
  setPipelineOwner,
  setPipelineStage,
  setSetting,
  signInSession,
  signOutEverywhere,
  upsertProperty,
  User,
  UserRole,
  verifyPassword,
} from './lib/store.js';

seedDemoData();
scoreAllSeeded();

const app = express();
const PORT = Number(process.env.PORT) || 3000;
const SESSION_COOKIE = 'plat_sid';
const FLASH_COOKIE = 'plat_flash';

app.set('view engine', 'ejs');
app.set('views', path.join(APP_ROOT, 'views'));
app.set('query parser', 'extended');
app.disable('x-powered-by');

app.use(express.static(path.join(APP_ROOT, 'public')));
app.use(cookieParser());
app.use(express.urlencoded({ extended: true }));
app.use(express.json());

declare global {
  namespace Express {
    interface Request {
      platSession: SessionData;
      platUser: User | null;
    }
  }
}

function safeBack(target: unknown, fallback = '/'): string {
  const s = String(target ?? '').trim();
  if (s.startsWith('/') && !s.startsWith('//')) return s;
  return fallback;
}

function setFlash(res: Response, type: 'ok' | 'err', msg: string): void {
  res.cookie(FLASH_COOKIE, JSON.stringify({ type, msg }), {
    httpOnly: true,
    sameSite: 'lax',
    path: '/',
    maxAge: 60_000,
  });
}

function readFlash(req: Request, res: Response): { type: string; msg: string } | null {
  const raw = req.cookies?.[FLASH_COOKIE];
  if (!raw) return null;
  res.clearCookie(FLASH_COOKIE, { path: '/' });
  try {
    const parsed = JSON.parse(String(raw));
    if (parsed && typeof parsed.msg === 'string') return parsed;
  } catch {
    // ignore malformed flash
  }
  return null;
}

function statusTag(p: Pick<Property, 'status'>): string {
  const map: Record<string, [string, string]> = {
    for_sale: ['For sale', 'tag-live'],
    pending: ['Pending', 'tag-warn'],
    sold: ['Sold', 'tag-sold'],
    off_market: ['Off market', ''],
  };
  const [label, cls] = map[p.status] ?? [p.status, ''];
  return `<span class="tag ${cls}">${e(label)}</span>`;
}

function rangeBar(low: number, high: number, mark: number): string {
  const span = Math.max(1, high - low);
  const pos = Math.max(0, Math.min(100, ((mark - low) / span) * 100));
  return `<div class="range"><div class="range-track"><span class="range-mark" style="left:${pos.toFixed(1)}%"></span></div></div>`;
}

function pager(total: number, per: number, page: number, query: Record<string, any>): string {
  const pages = Math.max(1, Math.ceil(total / Math.max(1, per)));
  if (pages <= 1) return '';
  const items: string[] = [];
  if (page > 1) {
    items.push(`<a class="btn btn-sm" href="${e(url('/search', { ...query, page: page - 1 }))}">Previous</a>`);
  }
  const start = Math.max(1, page - 2);
  const end = Math.min(pages, page + 2);
  for (let i = start; i <= end; i++) {
    if (i === page) {
      items.push(`<span class="pager-cur" aria-current="page">${i} of ${pages}</span>`);
    } else {
      items.push(`<a class="btn btn-sm btn-ghost" href="${e(url('/search', { ...query, page: i }))}">${i}</a>`);
    }
  }
  if (page < pages) {
    items.push(`<a class="btn btn-sm" href="${e(url('/search', { ...query, page: page + 1 }))}">Next</a>`);
  }
  return `<nav class="pager" aria-label="Pagination">${items.join(' ')}</nav>`;
}

function normalizeQuery(src: Record<string, any>): Record<string, any> {
  const out: Record<string, any> = { ...src };
  for (const key of ['types', 'listing', 'cond', 'channels']) {
    if (out[`${key}[]`] !== undefined && out[key] === undefined) {
      out[key] = out[`${key}[]`];
    }
  }
  return out;
}

// Session, CSRF & template locals middleware
app.use((req: Request, res: Response, next: NextFunction) => {
  const incomingSid = req.cookies?.[SESSION_COOKIE];
  const session = getOrCreateSession(incomingSid);
  if (incomingSid !== session.id) {
    res.cookie(SESSION_COOKIE, session.id, {
      httpOnly: true,
      sameSite: 'lax',
      path: '/',
      maxAge: 14 * 86400 * 1000,
    });
  }
  req.platSession = session;
  req.platUser = findSessionUser(session);

  if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(req.method)) {
    const token = String(req.body?._csrf ?? req.get('X-CSRF-Token') ?? '');
    if (!token || token !== session.csrf) {
      if (req.accepts(['html', 'json']) === 'json' || req.path.startsWith('/api/')) {
        res.status(403).json({ error: 'Invalid or expired security token' });
        return;
      }
      setFlash(res, 'err', 'Your form session expired — please try again.');
      res.redirect(303, req.originalUrl || '/');
      return;
    }
  }

  const defState = getSetting('default_state', 'TX') ?? 'TX';
  const siteName = getSetting('site_name', 'Plat') ?? 'Plat';
  const flash = readFlash(req, res);

  res.locals = {
    siteName,
    defaultState: defState,
    stateName: STATES[defState] ?? defState,
    flash,
    csrfToken: session.csrf,
    csrfField: () => `<input type="hidden" name="_csrf" value="${e(session.csrf)}">`,
    user: req.platUser,
    canView: can(req.platUser, 'view'),
    canEdit: can(req.platUser, 'edit'),
    canAdmin: can(req.platUser, 'admin'),
    e,
    icon,
    sketch,
    money,
    moneyShort,
    num,
    trimNum,
    fmtPhone,
    ppsf,
    pct,
    fmtDate,
    ago,
    plural,
    url,
    jsonEmbed,
    selected,
    checked,
    statusTag,
    rangeBar,
    pager,
    PIPELINE_STAGES,
    PIPELINE_ACTIVE,
    PROPERTY_TYPES,
    PROPERTY_SORTS,
    LISTING_FILTER_LABELS,
    ACTIVITY_LABELS,
    CONDITION_OVERALL,
    CONDITION_PARTS,
    CONDITION_STATES,
    CONDITION_FILTER,
    CONDITION_FLAGS,
    COMP_RADII,
    COMP_MONTHS,
    DEAL_SETTINGS,
    SCORE_WEIGHTS,
    ALERT_KINDS,
    ALERT_FREQUENCIES,
    ROLES,
    propertyFacts,
    propertyDom,
    formatLot,
    scoreBand,
    daysLabel,
    describeSearch,
  };
  next();
});

function requireAuth(req: Request, res: Response, next: NextFunction): void {
  if (!req.platUser) {
    if (req.path === '/') {
      res.redirect(302, '/welcome');
      return;
    }
    res.redirect(302, url('/login', { next: req.originalUrl }));
    return;
  }
  next();
}

function requirePerm(perm: 'view' | 'edit' | 'admin') {
  return (req: Request, res: Response, next: NextFunction): void => {
    if (!req.platUser) {
      if (req.path.startsWith('/api/')) {
        res.status(401).json({ error: 'Authentication required' });
        return;
      }
      res.redirect(302, url('/login', { next: req.originalUrl }));
      return;
    }
    if (!can(req.platUser, perm)) {
      if (req.path.startsWith('/api/')) {
        res.status(403).json({ error: 'Forbidden' });
        return;
      }
      res.status(403).render('plain', {
        title: 'Access restricted',
        active: '',
        opt: {},
        sketchName: 'home-missing',
        heading: 'You don’t have permission for that.',
        message: 'Ask an admin if you need partner or admin access.',
        ctaHref: '/',
        ctaLabel: 'Back to dashboard',
      });
      return;
    }
    next();
  };
}

// ── Public & Auth Routes ───────────────────────────────────────────────────

app.get('/welcome', (req: Request, res: Response) => {
  const chapters: Array<[string, string, string]> = [
    ['01 · PULL', 'Every listing in one quiet place', 'Browse saved areas or paste a link from Homes.com, Zillow, Realtor.com or Redfin. Price drops and status changes are kept together.'],
    ['02 · READ', 'Listing text turned into facts', 'Kitchens, baths, roofs, HVAC and distress flags are read directly from the description — anything unmentioned is never assumed fine.'],
    ['03 · PRICE', 'Comps, ARV & renovation math', 'Nearby sales set an after-repair benchmark alongside cautious, typical and hopeful profit scenarios and a 70% rule check.'],
    ['04 · SCORE', 'Focus on the deals that pencil', 'Each home gets an opportunity score from 0 to 100 and a separate risk score so thin margins or weak comps stand out immediately.'],
    ['05 · WORK', 'One shared pipeline for the team', 'Claim homes, track stages from New through Sold, log notes, and get alerts when matching listings or price cuts appear.'],
  ];
  res.render('welcome', {
    ctaHref: req.platUser ? '/' : '/login',
    ctaLabel: req.platUser ? 'Open dashboard' : 'Sign in',
    chapters,
  });
});

app.get('/login', (req: Request, res: Response) => {
  if (req.platUser) {
    res.redirect(302, safeBack(req.query.next, '/'));
    return;
  }
  res.render('login', {
    next: safeBack(req.query.next, '/'),
    email: String(req.query.email ?? ''),
    error: '',
  });
});

app.post('/login', (req: Request, res: Response) => {
  const email = String(req.body.email ?? '').trim().toLowerCase();
  const password = String(req.body.password ?? '');
  const nextUrl = safeBack(req.body.next, '/');
  const found = db.users.find((u) => u.email.toLowerCase() === email);
  if (!found || !found.isActive || !verifyPassword(password, found.passwordHash)) {
    res.status(401).render('login', {
      next: nextUrl,
      email,
      error: found && !found.isActive ? 'That account is disabled.' : 'That email or password didn’t match.',
    });
    return;
  }
  signInSession(req.platSession, found);
  res.redirect(303, nextUrl);
});

app.post('/logout', (req: Request, res: Response) => {
  req.platSession.userId = null;
  res.clearCookie(SESSION_COOKIE, { path: '/' });
  res.redirect(303, '/welcome');
});

// ── Dashboard ──────────────────────────────────────────────────────────────

app.get('/', requireAuth, (req: Request, res: Response) => {
  const user = req.platUser!;
  const weekCutoff = daysAgoDate(7);

  const forSale = db.properties.filter((p) => p.status === 'for_sale' && (p.list_price ?? 0) > 0);
  const newThisWeek = forSale.filter((p) => (p.listed_date ?? p.first_seen_at.slice(0, 10)) >= weekCutoff).length;
  const good = forSale.filter((p) => (p.score ?? 0) >= 70).length;

  // Recent price cuts
  const cuts: Array<{ id: number; address: string; city: string; event_date: string; price: number; before_price: number }> = [];
  for (const p of forSale) {
    const evs = getPropertyEvents(p.id);
    const recentCut = evs.find((ev) => ev.event === 'price_change' && ev.event_date >= weekCutoff);
    if (recentCut) {
      const prior = evs.find((ev) => ev.id !== recentCut.id && ev.event_date <= recentCut.event_date && ev.price > recentCut.price);
      if (prior) {
        cuts.push({
          id: p.id,
          address: p.address,
          city: p.city,
          event_date: recentCut.event_date,
          price: recentCut.price,
          before_price: prior.price,
        });
      }
    }
  }
  cuts.sort((a, b) => b.event_date.localeCompare(a.event_date));

  const review = db.watchlist.filter((w) => PIPELINE_ACTIVE.includes(w.stage)).length;
  const mine = db.watchlist.filter((w) => PIPELINE_ACTIVE.includes(w.stage) && w.owner_id === user.id).length;

  const top = forSale
    .filter((p) => p.score !== null)
    .sort((a, b) => (b.score ?? 0) - (a.score ?? 0) || b.id - a.id)
    .slice(0, 8)
    .map((p) => {
      const h = hydrateProperty(p);
      return { ...h, facts: propertyFacts(h) || 'Details pending' };
    });

  const stageCounts: Record<string, number> = {};
  for (const k of Object.keys(PIPELINE_STAGES)) stageCounts[k] = 0;
  for (const w of db.watchlist) {
    stageCounts[w.stage] = (stageCounts[w.stage] ?? 0) + 1;
  }

  const activity = recentActivity(12).map((a) => {
    const actionLabel = ACTIVITY_LABELS[a.action] ?? a.action;
    return {
      who: a.who,
      detail: a.detail ? `${actionLabel.toLowerCase()} (${a.detail})` : actionLabel.toLowerCase(),
      property_id: a.property_id,
      address: a.address,
      city: a.city,
      created_at: a.created_at,
    };
  });

  const alertsOn = db.alerts.filter((a) => a.user_id === user.id && a.enabled).length;

  res.render('dashboard', {
    todayStr: today(),
    hello: user.name ? user.name.split(/\s+/)[0] : '',
    k: {
      homes: forSale.length,
      newCount: newThisWeek,
      good,
      cuts: cuts.length,
      review,
      mine,
    },
    top,
    cuts: cuts.slice(0, 6),
    stageCounts,
    activity,
    alertsOn,
  });
});

// ── Search ─────────────────────────────────────────────────────────────────

app.get('/search', requireAuth, (req: Request, res: Response) => {
  const rawQ = normalizeQuery(req.query as Record<string, any>);
  const f = searchFromRequest(rawQ);
  const query = compactSearch(f);
  const user = req.platUser!;
  const defState = getSetting('default_state', 'TX') ?? 'TX';
  const typesDefault = ['Single Family', 'Townhouse'];

  if (!f.q) {
    const saved = db.savedSearches
      .filter((s) => s.user_id === user.id)
      .sort((a, b) => b.id - a.id);
    res.render('search', {
      f,
      query,
      typesDefault,
      saved,
      mode: f.mode,
      loc: null,
      res: null,
      anyInArea: 0,
      elsewhere: [],
      points: [],
      activeFilters: 0,
      openDrawer: false,
      per: 25,
      page: 1,
      reqUrl: req.originalUrl,
    });
    return;
  }

  // If the query is a full address, check if it matches a property directly
  const loc = parseLocation(f.q, defState);
  if (loc?.type === 'address') {
    const parsed = parseFullAddress(f.q);
    if (parsed) {
      const existing = db.properties.find(
        (p) => p.address.toLowerCase() === parsed.address.toLowerCase() && p.state === parsed.state
      );
      if (existing) {
        res.redirect(302, `/property/${existing.id}`);
        return;
      }
    }
    const fuzzy = db.properties.find((p) =>
      f.q.toLowerCase().includes(p.address.toLowerCase())
    );
    if (fuzzy) {
      res.redirect(302, `/property/${fuzzy.id}`);
      return;
    }
  }

  const per = 25;
  const page = Math.max(1, Number(req.query.page) || 1);
  const offset = (page - 1) * per;
  const searchRes = runSearch(f, { limit: per, offset });

  const anyInArea =
    searchRes.loc && searchRes.loc.type !== 'address'
      ? searchProperties({ q: f.q, mode: f.mode, sold_days: f.sold_days, loc: searchRes.loc, limit: 1 }).total
      : 0;

  const activeFilters = Object.keys(query).filter((k) => !['q', 'mode', 'sort'].includes(k)).length;
  const points = searchRes.rows
    .filter((r) => geoValid(r.lat, r.lng))
    .map((r) => {
      const price = f.mode === 'sold' ? r.last_sale_price : r.list_price;
      return {
        id: r.id,
        lat: Number(r.lat),
        lng: Number(r.lng),
        label: moneyShort(price),
        price: money(price),
        title: r.address,
        sub: propertyFacts(r),
        href: `/property/${r.id}`,
        sold: f.mode === 'sold',
        band: f.mode === 'sold' ? 'none' : scoreBand(r.score),
      };
    });

  const elsewhere = searchRes.loc ? externalForArea(searchRes.loc) : [];

  res.render('search', {
    f,
    query,
    typesDefault,
    saved: [],
    mode: f.mode,
    loc: searchRes.loc,
    res: searchRes,
    anyInArea,
    elsewhere,
    points,
    activeFilters,
    openDrawer: req.query.open !== undefined,
    per,
    page,
    reqUrl: req.originalUrl,
  });
});

app.post('/search/act', requireAuth, (req: Request, res: Response) => {
  const user = req.platUser!;
  const action = String(req.body.do ?? '');
  if (action === 'save') {
    let params: Record<string, any> = {};
    try {
      params = JSON.parse(String(req.body.params ?? '{}'));
    } catch {
      params = {};
    }
    const clean = compactSearch(searchFromRequest(params));
    const name = describeSearch(clean) || String(clean.q || 'Saved search');
    db.savedSearches.push({
      id: db.nextId('saved'),
      user_id: user.id,
      name: name.slice(0, 120),
      params: clean,
      last_run_at: null,
      created_at: now(),
    });
    setFlash(res, 'ok', 'Search profile saved.');
    res.redirect(303, url('/search', clean));
    return;
  }
  if (action === 'unsave') {
    const id = Number(req.body.id);
    db.savedSearches = db.savedSearches.filter((s) => !(s.id === id && s.user_id === user.id));
    setFlash(res, 'ok', 'Profile deleted.');
    res.redirect(303, '/search');
    return;
  }
  res.redirect(303, '/search');
});

// ── Property Detail & Actions ──────────────────────────────────────────────

app.get('/property/:id', requireAuth, (req: Request, res: Response) => {
  const id = Number(req.params.id);
  const prop = findProperty(id);
  if (!prop) {
    res.status(404).render('plain', {
      title: 'Home not found',
      active: '',
      opt: {},
      sketchName: 'home-missing',
      heading: 'That home isn’t on file.',
      message: 'It may have been deleted or never saved here.',
      ctaHref: '/search',
      ctaLabel: 'Search homes',
    });
    return;
  }

  const p = hydrateProperty(prop);
  const forSale = p.status === 'for_sale' || p.status === 'pending';
  const headline = forSale ? p.list_price : p.last_sale_price;
  const pp = ppsf(headline, p.sqft);
  const dom = propertyDom(p);
  const hasGeo = geoValid(p.lat, p.lng);

  const reqR = req.query.r !== undefined ? Number(req.query.r) : undefined;
  const reqM = req.query.m !== undefined ? Number(req.query.m) : undefined;
  const comps = findComps(p, {
    radius: reqR && COMP_RADII.includes(reqR) ? reqR : undefined,
    months: reqM && COMP_MONTHS.includes(reqM) ? reqM : undefined,
  });

  const cond = getCondition(p.id);
  const svDeal = savedDeal(p.id);
  const deal = analyseDeal(p, cond, svDeal);
  const sc = computeScore(p, deal, cond);

  const events = getPropertyEvents(p.id);
  const series = [...events]
    .reverse()
    .filter((ev) => ev.price > 0)
    .map((ev) => ({ x: fmtDate(ev.event_date, 'M j, Y'), y: ev.price }));

  const points: any[] = [];
  if (hasGeo) {
    points.push({
      id: p.id,
      lat: Number(p.lat),
      lng: Number(p.lng),
      label: headline ? moneyShort(headline) : p.address,
      price: money(headline),
      title: p.address,
      sub: propertyFacts(p),
      href: null,
      subject: true,
      sold: !forSale,
      band: forSale ? scoreBand(sc.score) : 'none',
    });
    for (const c of comps.comps) {
      if (!geoValid(c.lat, c.lng)) continue;
      points.push({
        id: c.id,
        lat: Number(c.lat),
        lng: Number(c.lng),
        label: moneyShort(c.last_sale_price),
        price: money(c.last_sale_price),
        title: c.address,
        sub: `${fmtDate(c.last_sale_date, 'M j, Y')} · ${c.distance.toFixed(2)} mi`,
        href: `/property/${c.id}`,
        subject: false,
        sold: true,
        band: 'none',
      });
    }
  }

  const notes = getPropertyNotes(p.id);
  const history = propertyActivity(p.id, 40);

  const team = db.users.filter((u) => u.isActive && u.role !== 'viewer').map((u) => ({ id: u.id, name: u.name }));
  const links = externalForProperty(p);
  const defState = getSetting('default_state', 'TX') ?? 'TX';
  const backQ = p.city ? `${p.city}, ${p.state}` : (STATES[defState] ?? defState);

  res.render('property', {
    p,
    forSale,
    headline,
    pp,
    dom,
    hasGeo,
    comps,
    stats: comps.stats,
    cond,
    savedDeal: svDeal,
    deal,
    sc,
    events,
    series,
    points,
    notes,
    history,
    team,
    links,
    backQ,
  });
});

app.post('/property/:id/act', requirePerm('edit'), (req: Request, res: Response) => {
  const id = Number(req.params.id);
  const p = findProperty(id);
  if (!p) {
    res.redirect(303, '/search');
    return;
  }
  const user = req.platUser!;
  const action = String(req.body.do ?? '');
  const back = safeBack(req.body.back, `/property/${id}`);

  if (action === 'watch') {
    addToPipeline(id, user.id, p.source_url);
    setFlash(res, 'ok', 'Added to team pipeline.');
  } else if (action === 'unwatch') {
    removeFromPipeline(id, user.id);
    setFlash(res, 'ok', 'Removed from pipeline.');
  } else if (action === 'stage') {
    const stage = String(req.body.stage ?? '');
    if (PIPELINE_STAGES[stage]) {
      setPipelineStage(id, stage, user.id);
      setFlash(res, 'ok', `Status updated to ${PIPELINE_STAGES[stage]}.`);
    }
  } else if (action === 'owner') {
    const ownerId = req.body.owner_id ? Number(req.body.owner_id) : null;
    setPipelineOwner(id, ownerId, user.id);
    const ownerName = ownerId ? db.users.find((u) => u.id === ownerId)?.name ?? 'Member' : 'No one';
    setFlash(res, 'ok', `Handled by ${ownerName}.`);
  } else if (action === 'note') {
    const body = String(req.body.note ?? '').trim();
    if (body) {
      addNote(id, user.id, body);
      setFlash(res, 'ok', 'Note added.');
    }
  } else if (action === 'note_delete') {
    const noteId = Number(req.body.note_id);
    if (deleteNote(noteId, id, user)) {
      setFlash(res, 'ok', 'Note deleted.');
    }
  } else if (action === 'deal') {
    saveDeal(id, req.body, user.id);
    refreshScore(id);
    setFlash(res, 'ok', 'Deal analysis updated and score recalculated.');
  } else if (action === 'condition') {
    if (req.body.reset_auto) {
      db.conditions.delete(id);
      refreshCondition(p);
      refreshScore(id);
      logActivity(user.id, 'condition', id, 'Reset condition to automatic reading');
      setFlash(res, 'ok', 'Condition returned to automatic listing reading.');
    } else {
      const existing = getCondition(id);
      const revYear = parseNum(req.body.renovated_year);
      db.conditions.set(id, {
        ...existing,
        property_id: id,
        source: 'manual',
        overall: CONDITION_OVERALL[req.body.overall] ? String(req.body.overall) : existing.overall,
        kitchen: CONDITION_STATES[req.body.kitchen] ? String(req.body.kitchen) : existing.kitchen,
        bathroom: CONDITION_STATES[req.body.bathroom] ? String(req.body.bathroom) : existing.bathroom,
        roof: CONDITION_STATES[req.body.roof] ? String(req.body.roof) : existing.roof,
        hvac: CONDITION_STATES[req.body.hvac] ? String(req.body.hvac) : existing.hvac,
        flooring: CONDITION_STATES[req.body.flooring] ? String(req.body.flooring) : existing.flooring,
        electrical: CONDITION_STATES[req.body.electrical] ? String(req.body.electrical) : existing.electrical,
        plumbing: CONDITION_STATES[req.body.plumbing] ? String(req.body.plumbing) : existing.plumbing,
        windows: CONDITION_STATES[req.body.windows] ? String(req.body.windows) : existing.windows,
        renovated_year: revYear && revYear >= 1900 && revYear <= 2035 ? Math.round(revYear) : null,
        updated_at: now(),
      });
      refreshScore(id);
      logActivity(user.id, 'condition', id, `Updated condition to ${CONDITION_OVERALL[req.body.overall] ?? req.body.overall}`);
      setFlash(res, 'ok', 'Condition updated.');
    }
  } else if (action === 'data_status') {
    const ds = String(req.body.data_status ?? '') as Property['data_status'];
    if (['full', 'limited', 'link_only', 'link_dead', 'removed'].includes(ds)) {
      const targetProp = db.properties.find((x) => x.id === id);
      if (targetProp) targetProp.data_status = ds;
      setFlash(res, 'ok', 'Listing detail status updated.');
    }
  } else if (action === 'delete') {
    if (!can(user, 'admin')) {
      res.status(403).send('Forbidden');
      return;
    }
    deleteProperty(id);
    logActivity(user.id, 'delete', null, `Deleted ${p.address}`);
    setFlash(res, 'ok', `${p.address} deleted.`);
    res.redirect(303, '/search');
    return;
  }

  res.redirect(303, back);
});

// ── Pipeline ───────────────────────────────────────────────────────────────

function renderPipeline(req: Request, res: Response): void {
  const user = req.platUser!;
  const who = req.query.who === 'me' ? 'me' : '';
  const show = ['open', 'closed', 'all'].includes(String(req.query.show ?? '')) ? String(req.query.show) : 'open';

  const allItems = db.watchlist
    .map((w) => {
      const p = db.properties.find((x) => x.id === w.property_id);
      if (!p) return null;
      const h = hydrateProperty(p);
      const events = getPropertyEvents(p.id);
      const listedEv = events.find((e) => e.event === 'listed') ?? events[events.length - 1];
      const noteCount = db.notes.filter((n) => n.property_id === p.id).length;
      return {
        ...h,
        first_price: listedEv?.price ?? null,
        note_count: noteCount,
      };
    })
    .filter((x): x is NonNullable<typeof x> => x !== null);

  const counts: Record<string, number> = {};
  for (const k of Object.keys(PIPELINE_STAGES)) counts[k] = 0;
  for (const item of allItems) {
    if (item.watch_stage) counts[item.watch_stage] = (counts[item.watch_stage] ?? 0) + 1;
  }

  const rows = allItems
    .filter((r) => {
      if (who === 'me' && r.owner_id !== user.id) return false;
      if (show === 'open') return PIPELINE_ACTIVE.includes(r.watch_stage ?? '');
      if (show === 'closed') return !PIPELINE_ACTIVE.includes(r.watch_stage ?? '');
      return true;
    })
    .sort((a, b) => (b.stage_at ?? '').localeCompare(a.stage_at ?? '') || b.id - a.id);

  const byStage: Record<string, typeof rows> = {};
  for (const k of Object.keys(PIPELINE_STAGES)) byStage[k] = [];
  for (const r of rows) {
    const st = r.watch_stage ?? 'new';
    if (!byStage[st]) byStage[st] = [];
    byStage[st].push(r);
  }

  res.render('pipeline', {
    who,
    show,
    counts,
    rows,
    byStage,
  });
}

app.get('/pipeline', requireAuth, renderPipeline);
app.get('/watchlist', requireAuth, renderPipeline);

// ── Add / Edit Home ────────────────────────────────────────────────────────

app.get('/add', requirePerm('edit'), (req: Request, res: Response) => {
  const editId = Number(req.query.id ?? 0);
  const editProp = editId ? findProperty(editId) : null;
  const nearId = Number(req.query.near ?? 0);
  const near = nearId ? findProperty(nearId) : null;
  const defState = getSetting('default_state', 'TX') ?? 'TX';

  let form: Record<string, any> = {
    address: '',
    city: near?.city ?? '',
    state: near?.state ?? defState,
    zip: near?.zip ?? '',
    status: near ? 'sold' : 'for_sale',
    list_price: '',
    listed_date: near ? '' : today(),
    last_sale_price: '',
    last_sale_date: near ? today() : '',
    property_type: near?.property_type ?? 'Single Family',
    beds: '',
    baths: '',
    sqft: '',
    lot_sqft: '',
    year_built: '',
    hoa_fee: '',
    source_url: '',
    notes: '',
    watch: Boolean(req.query.watch) || !near,
  };

  if (editProp) {
    const w = db.watchlist.find((x) => x.property_id === editProp.id);
    form = {
      address: editProp.address,
      city: editProp.city,
      state: editProp.state,
      zip: editProp.zip,
      status: editProp.status,
      list_price: editProp.list_price ?? '',
      listed_date: editProp.listed_date ?? '',
      last_sale_price: editProp.last_sale_price ?? '',
      last_sale_date: editProp.last_sale_date ?? '',
      property_type: editProp.property_type ?? '',
      beds: editProp.beds ?? '',
      baths: editProp.baths ?? '',
      sqft: editProp.sqft ?? '',
      lot_sqft: editProp.lot_sqft ?? '',
      year_built: editProp.year_built ?? '',
      hoa_fee: editProp.hoa_fee ?? '',
      source_url: editProp.source_url ?? '',
      notes: '',
      watch: Boolean(w),
    };
  }

  res.render('add', {
    edit: editProp,
    editId: editProp?.id ?? 0,
    near,
    form,
    errors: [],
    linkError: '',
    linkUrl: '',
  });
});

app.post('/add', requirePerm('edit'), (req: Request, res: Response) => {
  const user = req.platUser!;
  const defState = getSetting('default_state', 'TX') ?? 'TX';

  if (req.body.do === 'link') {
    const rawUrl = String(req.body.url ?? '').trim();
    const resolved = resolveLink(rawUrl);
    if (!resolved.ok || !resolved.address || !resolved.city || !resolved.state) {
      res.status(400).render('add', {
        edit: null,
        editId: 0,
        near: null,
        form: {
          address: '',
          city: '',
          state: defState,
          zip: '',
          status: 'for_sale',
          list_price: req.body.price ?? '',
          listed_date: today(),
          last_sale_price: '',
          last_sale_date: '',
          property_type: 'Single Family',
          beds: '',
          baths: '',
          sqft: '',
          lot_sqft: '',
          year_built: '',
          hoa_fee: '',
          source_url: rawUrl,
          notes: req.body.note ?? '',
          watch: true,
        },
        errors: [],
        linkError: resolved.error ?? 'Could not read that link.',
        linkUrl: rawUrl,
      });
      return;
    }

    const price = parseNum(req.body.price);
    const { id } = upsertProperty(
      {
        address: resolved.address,
        city: resolved.city,
        state: resolved.state,
        zip: resolved.zip ?? '',
        county: resolved.county ?? null,
        lat: resolved.lat ?? null,
        lng: resolved.lng ?? null,
        status: 'for_sale',
        list_price: price && price > 0 ? Math.round(price) : null,
        listed_date: today(),
        source_site: resolved.site,
        source_url: rawUrl,
        data_status: 'link_only',
      },
      'manual'
    );

    if (price && price > 0) {
      addPriceEvent(id, 'listed', Math.round(price), today(), 'manual');
    }

    addToPipeline(id, user.id, rawUrl);

    const noteBody = String(req.body.note ?? '').trim();
    if (noteBody) {
      addNote(id, user.id, noteBody);
    }

    refreshScore(id);
    setFlash(res, 'ok', `${resolved.address} added from ${resolved.site}.`);
    res.redirect(303, `/property/${id}`);
    return;
  }

  const editId = Number(req.body.id ?? req.query.id ?? 0);
  const existing = editId ? findProperty(editId) : null;
  const address = existing ? existing.address : String(req.body.address ?? '').trim();
  const city = existing ? existing.city : titleCity(String(req.body.city ?? '').trim());
  const state = existing ? existing.state : (stateAbbr(String(req.body.state ?? defState)) ?? defState);
  const zip = existing ? existing.zip : String(req.body.zip ?? '').replace(/\D/g, '').slice(0, 5);

  const errors: string[] = [];
  if (!address) errors.push('Street address is required.');
  if (!city) errors.push('City is required.');
  if (!state) errors.push('State is required.');

  if (errors.length) {
    res.status(400).render('add', {
      edit: existing,
      editId: existing?.id ?? 0,
      near: null,
      form: { ...req.body, watch: Boolean(req.body.watch) },
      errors,
      linkError: '',
      linkUrl: '',
    });
    return;
  }

  const listPrice = parseNum(req.body.list_price);
  const salePrice = parseNum(req.body.last_sale_price);
  const beds = parseNum(req.body.beds);
  const baths = parseNum(req.body.baths);
  const sqft = parseNum(req.body.sqft);
  const lotSqft = parseNum(req.body.lot_sqft);
  const yearBuilt = parseNum(req.body.year_built);
  const hoa = parseNum(req.body.hoa_fee);
  const status = ['for_sale', 'pending', 'sold', 'off_market'].includes(String(req.body.status))
    ? String(req.body.status)
    : 'for_sale';

  // Approximate lat/lng in the local area if new and missing coordinates
  const areaAnchor = state === 'VA' ? { lat: 37.5512, lng: -77.4591, county: 'Richmond' } : { lat: 30.2465, lng: -97.7681, county: 'Travis' };

  const { id } = upsertProperty(
    {
      address,
      city,
      state,
      zip,
      county: existing?.county ?? areaAnchor.county,
      lat: existing?.lat ?? areaAnchor.lat,
      lng: existing?.lng ?? areaAnchor.lng,
      property_type: PROPERTY_TYPES.includes(String(req.body.property_type)) ? String(req.body.property_type) : null,
      beds: beds !== null ? beds : null,
      baths: baths !== null ? baths : null,
      sqft: sqft ? Math.round(sqft) : null,
      lot_sqft: lotSqft ? Math.round(lotSqft) : null,
      year_built: yearBuilt ? Math.round(yearBuilt) : null,
      hoa_fee: hoa !== null ? Math.round(hoa) : null,
      status,
      list_price: listPrice ? Math.round(listPrice) : null,
      listed_date: String(req.body.listed_date || '') || null,
      last_sale_price: salePrice ? Math.round(salePrice) : null,
      last_sale_date: String(req.body.last_sale_date || '') || null,
      source_url: String(req.body.source_url ?? '').trim() || existing?.source_url || null,
      data_status: 'full',
    },
    existing?.source ?? 'manual'
  );

  if (existing && listPrice && existing.list_price && Math.round(listPrice) !== existing.list_price) {
    addPriceEvent(id, 'price_change', Math.round(listPrice), today(), 'manual');
    logActivity(user.id, 'edit', id, `Updated asking price to ${money(listPrice)}`);
  } else if (!existing && listPrice) {
    addPriceEvent(id, 'listed', Math.round(listPrice), String(req.body.listed_date || '') || today(), 'manual');
    logActivity(user.id, 'added', id, 'Added home manually');
  } else if (existing) {
    logActivity(user.id, 'edit', id, 'Updated facts and price');
  }

  const wantWatch = Boolean(req.body.watch);
  const inWatch = db.watchlist.some((w) => w.property_id === id);
  if (wantWatch && !inWatch) {
    addToPipeline(id, user.id, String(req.body.source_url ?? '').trim() || null);
  } else if (!wantWatch && inWatch) {
    removeFromPipeline(id, user.id);
  }

  const noteBody = String(req.body.notes ?? '').trim();
  if (noteBody) {
    addNote(id, user.id, noteBody);
  }

  refreshScore(id);
  setFlash(res, 'ok', existing ? 'Changes saved.' : 'Home added.');
  res.redirect(303, `/property/${id}`);
});

// ── Alerts ─────────────────────────────────────────────────────────────────

app.get('/alerts', requireAuth, (req: Request, res: Response) => {
  const user = req.platUser!;
  const alerts = db.alerts
    .filter((a) => a.user_id === user.id)
    .map((a) => ({
      ...a,
      sent: db.alertHits.filter((h) => h.alert_id === a.id).length,
    }));

  const myIds = new Set(alerts.map((a) => a.id));
  const hits = db.alertHits
    .filter((h) => myIds.has(h.alert_id))
    .sort((a, b) => b.created_at.localeCompare(a.created_at))
    .slice(0, 20)
    .map((h) => {
      const p = db.properties.find((x) => x.id === h.property_id);
      const al = db.alerts.find((x) => x.id === h.alert_id);
      return {
        pid: h.property_id,
        address: p?.address ?? 'Unknown',
        city: p?.city ?? '',
        price: h.price,
        summary: h.summary,
        alert_name: al?.name ?? 'Alert',
        created_at: h.created_at,
      };
    });

  res.render('alerts', {
    alerts,
    hits,
    lastRun: getSetting('last_cron_at'),
  });
});

function buildAlertEditModel(alert: Alert | null, source: Record<string, any>) {
  const norm = normalizeQuery(source);
  const crit = alert ? alert.criteria : compactSearch(searchFromRequest(norm));
  if (!alert && norm.min_drop_pct !== undefined) {
    const md = parseNum(norm.min_drop_pct);
    if (md !== null) crit.min_drop_pct = md;
  }
  const toArr = (v: unknown): string[] => (Array.isArray(v) ? v.map(String) : v ? [String(v)] : []);
  const form = {
    kind: alert?.kind ?? (ALERT_KINDS[String(norm.kind)] ? String(norm.kind) : 'new_listing'),
    location: alert?.location ?? String(norm.location ?? norm.q ?? ''),
    every_hours: alert?.every_hours ?? (Number(norm.every_hours) || 24),
    delivery: alert?.delivery ?? (norm.delivery === 'daily' ? 'daily' : 'instant'),
    channels: alert?.channels ?? (toArr(norm.channels).length ? toArr(norm.channels) : ['email']),
    enabled: alert ? alert.enabled : norm.enabled !== undefined ? Boolean(norm.enabled) : true,
    name: alert?.name ?? String(norm.name ?? ''),
  };

  const critVal = (k: string): string => {
    const v = crit[k];
    if (v === undefined || v === null || v === '') return '';
    return String(v);
  };

  const history = alert
    ? db.alertHits
        .filter((h) => h.alert_id === alert.id)
        .sort((a, b) => b.created_at.localeCompare(a.created_at))
        .slice(0, 30)
        .map((h) => {
          const p = db.properties.find((x) => x.id === h.property_id);
          return {
            pid: h.property_id,
            address: p?.address ?? 'Unknown',
            city: p?.city ?? '',
            price: h.price,
            summary: h.summary,
            created_at: h.created_at,
          };
        })
    : [];

  return { alert, form, crit, critVal, history };
}

app.get('/alerts/new', requireAuth, (req: Request, res: Response) => {
  const model = buildAlertEditModel(null, req.query as Record<string, any>);
  res.render('alert_edit', { ...model, errors: [] });
});

app.post('/alerts/new', requireAuth, (req: Request, res: Response) => {
  const user = req.platUser!;
  const norm = normalizeQuery(req.body);
  const kind = ALERT_KINDS[String(norm.kind)] ? String(norm.kind) : 'new_listing';
  const location = String(norm.location ?? '').trim();
  const criteria = compactSearch(searchFromRequest({ ...norm, q: location }));
  delete criteria.q;
  if (kind === 'price_drop' && parseNum(norm.min_drop_pct) !== null) {
    criteria.min_drop_pct = parseNum(norm.min_drop_pct);
  }
  const toArr = (v: unknown): string[] => (Array.isArray(v) ? v.map(String) : v ? [String(v)] : []);
  const channels = toArr(norm.channels).filter((c) => ['email', 'telegram', 'whatsapp'].includes(c));
  const errors: string[] = [];
  if (kind !== 'price_drop' && !location) errors.push('Enter an area for this alert.');
  if (!channels.length) errors.push('Choose at least one channel (Email, Telegram or WhatsApp).');

  if (errors.length) {
    const model = buildAlertEditModel(null, norm);
    res.status(400).render('alert_edit', { ...model, errors });
    return;
  }

  const autoName = describeSearch({ ...criteria, q: location }) || ALERT_KINDS[kind];
  const name = String(norm.name ?? '').trim() || autoName;
  const created: Alert = {
    id: db.nextId('alert'),
    user_id: user.id,
    name: name.slice(0, 100),
    kind: kind as Alert['kind'],
    location,
    criteria,
    every_hours: Number(norm.every_hours) || 24,
    delivery: norm.delivery === 'daily' ? 'daily' : 'instant',
    channels,
    use_quota: false,
    enabled: Boolean(norm.enabled),
    last_checked_at: null,
    last_note: null,
    created_at: now(),
  };
  db.alerts.push(created);
  runAlertNow(created);
  setFlash(res, 'ok', 'Alert created.');
  res.redirect(303, '/alerts');
});

app.get('/alerts/:id', requireAuth, (req: Request, res: Response) => {
  const user = req.platUser!;
  const id = Number(req.params.id);
  const alert = db.alerts.find((a) => a.id === id && a.user_id === user.id) ?? null;
  if (!alert) {
    res.status(404).render('plain', {
      title: 'Alert not found',
      active: 'alerts',
      opt: {},
      sketchName: 'alerts-empty',
      heading: 'Alert not found.',
      message: 'It may have been deleted.',
      ctaHref: '/alerts',
      ctaLabel: 'Back to alerts',
    });
    return;
  }
  const model = buildAlertEditModel(alert, {});
  res.render('alert_edit', { ...model, errors: [] });
});

app.post('/alerts/:id', requireAuth, (req: Request, res: Response) => {
  const user = req.platUser!;
  const id = Number(req.params.id);
  const alert = db.alerts.find((a) => a.id === id && a.user_id === user.id);
  if (!alert) {
    res.redirect(303, '/alerts');
    return;
  }
  const norm = normalizeQuery(req.body);
  const kind = ALERT_KINDS[String(norm.kind)] ? String(norm.kind) : alert.kind;
  const location = String(norm.location ?? '').trim();
  const criteria = compactSearch(searchFromRequest({ ...norm, q: location }));
  delete criteria.q;
  if (kind === 'price_drop' && parseNum(norm.min_drop_pct) !== null) {
    criteria.min_drop_pct = parseNum(norm.min_drop_pct);
  }
  const toArr = (v: unknown): string[] => (Array.isArray(v) ? v.map(String) : v ? [String(v)] : []);
  const channels = toArr(norm.channels).filter((c) => ['email', 'telegram', 'whatsapp'].includes(c));
  const errors: string[] = [];
  if (kind !== 'price_drop' && !location) errors.push('Enter an area for this alert.');
  if (!channels.length) errors.push('Choose at least one channel.');

  if (errors.length) {
    const model = buildAlertEditModel(alert, norm);
    res.status(400).render('alert_edit', { ...model, errors });
    return;
  }

  alert.kind = kind as Alert['kind'];
  alert.location = location;
  alert.criteria = criteria;
  alert.every_hours = Number(norm.every_hours) || 24;
  alert.delivery = norm.delivery === 'daily' ? 'daily' : 'instant';
  alert.channels = channels;
  alert.enabled = Boolean(norm.enabled);
  const autoName = describeSearch({ ...criteria, q: location }) || ALERT_KINDS[kind];
  alert.name = (String(norm.name ?? '').trim() || autoName).slice(0, 100);

  setFlash(res, 'ok', 'Alert updated.');
  res.redirect(303, '/alerts');
});

app.post('/alerts/:id/act', requireAuth, (req: Request, res: Response) => {
  const user = req.platUser!;
  const id = Number(req.params.id);
  const alert = db.alerts.find((a) => a.id === id && a.user_id === user.id);
  if (!alert) {
    res.redirect(303, '/alerts');
    return;
  }
  const action = String(req.body.do ?? '');
  if (action === 'toggle') {
    alert.enabled = !alert.enabled;
    setFlash(res, 'ok', alert.enabled ? 'Alert turned on.' : 'Alert paused.');
  } else if (action === 'run') {
    const out = runAlertNow(alert);
    setFlash(res, 'ok', out.note);
  } else if (action === 'delete') {
    db.alerts = db.alerts.filter((a) => a.id !== id);
    db.alertHits = db.alertHits.filter((h) => h.alert_id !== id);
    setFlash(res, 'ok', 'Alert deleted.');
  }
  res.redirect(303, '/alerts');
});

// ── Market ─────────────────────────────────────────────────────────────────

function regionLabel(r: MarketRegion): string {
  return r.kind === 'zip' ? `${r.name} (${r.state})` : `${r.name}, ${r.state}`;
}

function metricStats(regionId: number, metric: string) {
  const pts = db.marketSeries
    .filter((s) => s.region_id === regionId && s.metric === metric)
    .sort((a, b) => a.period.localeCompare(b.period));
  if (!pts.length) return null;
  const last = pts[pts.length - 1];
  const prev12 = pts.length > 12 ? pts[pts.length - 13] : null;
  const yoy = prev12 && prev12.value > 0 ? ((last.value - prev12.value) / prev12.value) * 100 : null;
  const first = pts[0];
  const five = first && first.value > 0 && pts.length >= 24 ? ((last.value - first.value) / first.value) * 100 : null;
  return { pts, latest: { value: last.value, date: last.period, yoy }, five };
}

app.get('/market', requireAuth, (_req: Request, res: Response) => {
  const regions = db.marketRegions.map((r) => {
    const zh = metricStats(r.id, 'zhvi');
    return {
      ...r,
      label: regionLabel(r),
      latest: zh?.latest ?? null,
      five: zh?.five ?? null,
    };
  });
  res.render('market', {
    region: null,
    regions,
  });
});

app.get('/market/:id', requireAuth, (req: Request, res: Response) => {
  const id = Number(req.params.id);
  const region = db.marketRegions.find((r) => r.id === id);
  if (!region) {
    res.status(404).render('plain', {
      title: 'Market area not found',
      active: 'market',
      opt: {},
      sketchName: 'market-empty',
      heading: 'Area not found.',
      message: 'This tracked market area does not exist.',
      ctaHref: '/market',
      ctaLabel: 'Back to Market',
    });
    return;
  }

  const label = regionLabel(region);
  const zh = metricStats(region.id, 'zhvi');
  const medSale = metricStats(region.id, 'median_sale');
  const medList = metricStats(region.id, 'median_list');
  const inv = metricStats(region.id, 'inventory');
  const dom = metricStats(region.id, 'days_pending');
  const ppsfMetric = metricStats(region.id, 'median_ppsf');

  const chartConfigs: Array<{ key: string; title: string; start: string; end: string; payload: any }> = [];
  if (zh?.pts.length) {
    const labels = zh.pts.map((pt) => fmtDate(pt.period, 'M Y'));
    const series = [{ name: 'Typical home value', data: zh.pts.map((pt) => pt.value) }];
    if (medSale?.pts.length) {
      series.push({ name: 'Median sale price', data: medSale.pts.map((pt) => pt.value) });
    }
    if (medList?.pts.length) {
      series.push({ name: 'Median asking price', data: medList.pts.map((pt) => pt.value) });
    }
    chartConfigs.push({
      key: 'prices',
      title: 'Home values & prices',
      start: zh.pts[0].period,
      end: zh.pts[zh.pts.length - 1].period,
      payload: { format: 'usd', labels, series },
    });
  }
  if (inv?.pts.length && dom?.pts.length) {
    chartConfigs.push({
      key: 'supply',
      title: 'Inventory & pace',
      start: inv.pts[0].period,
      end: inv.pts[inv.pts.length - 1].period,
      payload: {
        format: 'num',
        labels: inv.pts.map((pt) => fmtDate(pt.period, 'M Y')),
        series: [
          { name: 'Homes for sale', data: inv.pts.map((pt) => pt.value) },
          { name: 'Days to pending', data: dom.pts.map((pt) => pt.value) },
        ],
      },
    });
  }

  const metricOrder = [
    ['zhvi', true, zh],
    ['median_sale', true, medSale],
    ['median_list', true, medList],
    ['median_ppsf', true, ppsfMetric],
    ['inventory', false, inv],
    ['days_pending', false, dom],
  ] as const;

  const latestRows = metricOrder
    .map(([k, usd, st]) =>
      st
        ? {
            label: MARKET_LABELS[k] ?? k,
            date: st.latest.date,
            value: st.latest.value,
            usd,
            yoy: st.latest.yoy,
          }
        : null
    )
    .filter((x): x is NonNullable<typeof x> => x !== null);

  res.render('market', {
    region,
    label,
    zh: zh?.latest ?? null,
    hasData: latestRows.length > 0,
    chartConfigs,
    latestRows,
  });
});

app.post('/market', requireAuth, (req: Request, res: Response) => {
  const action = String(req.body.do ?? '');
  if (action === 'add') {
    const q = String(req.body.q ?? '').trim();
    const defState = getSetting('default_state', 'TX') ?? 'TX';
    const loc = parseLocation(q, defState);
    if (!loc || (loc.type !== 'zip' && loc.type !== 'city')) {
      setFlash(res, 'err', 'Type a 5-digit ZIP code or city & state, e.g. 78745 or Dallas, TX.');
      res.redirect(303, '/market');
      return;
    }
    const kind = loc.type === 'zip' ? 'zip' : 'city';
    const name = loc.type === 'zip' ? loc.zip! : loc.city!;
    const state = loc.state ?? defState;
    const existing = db.marketRegions.find(
      (r) => r.kind === kind && r.name.toLowerCase() === name.toLowerCase() && r.state === state
    );
    if (existing) {
      res.redirect(303, `/market/${existing.id}`);
      return;
    }
    const region: MarketRegion = {
      id: db.nextId('region'),
      kind,
      name,
      state,
      added_at: now(),
    };
    db.marketRegions.push(region);
    populateSyntheticMarketSeries(region);
    setFlash(res, 'ok', `Now tracking ${regionLabel(region)}.`);
    res.redirect(303, `/market/${region.id}`);
    return;
  }

  if (action === 'remove') {
    const id = Number(req.body.id);
    db.marketRegions = db.marketRegions.filter((r) => r.id !== id);
    db.marketSeries = db.marketSeries.filter((s) => s.region_id !== id);
    setFlash(res, 'ok', 'Area removed from Market.');
    res.redirect(303, '/market');
    return;
  }

  res.redirect(303, '/market');
});

// ── Reports & CSV Export ───────────────────────────────────────────────────

app.get('/reports', requireAuth, (_req: Request, res: Response) => {
  res.render('reports', {
    tables: buildReportTables(),
    nowStr: now(),
  });
});

app.get('/export.csv', requireAuth, (req: Request, res: Response) => {
  if (req.query.report) {
    const csv = exportReportsCsv(buildReportTables());
    res.setHeader('Content-Type', 'text/csv; charset=utf-8');
    res.setHeader('Content-Disposition', `attachment; filename="plat-reports-${today()}.csv"`);
    res.send(csv);
    return;
  }
  const f = searchFromRequest(normalizeQuery(req.query as Record<string, any>));
  const result = runSearch(f, { limit: 2000, offset: 0 });
  const csv = exportPropertiesCsv(result.rows, f.mode);
  res.setHeader('Content-Type', 'text/csv; charset=utf-8');
  res.setHeader('Content-Disposition', `attachment; filename="plat-search-${today()}.csv"`);
  res.send(csv);
});

app.get('/export.xlsx', requireAuth, (_req: Request, res: Response) => {
  res.status(501).json({ error: 'Not yet migrated — use /export.csv for spreadsheet export.' });
});

// ── Admin: Settings, Team, External Sites ──────────────────────────────────

app.get('/settings', requirePerm('admin'), (_req: Request, res: Response) => {
  res.render('settings', {
    s: (k: string) => getSetting(k, '') ?? '',
    counts: {
      homes: db.properties.length,
      sales: db.properties.filter((p) => (p.last_sale_price ?? 0) > 0).length,
      events: db.priceEvents.length,
      watchlist: db.watchlist.length,
    },
  });
});

app.post('/settings', requirePerm('admin'), (req: Request, res: Response) => {
  const action = String(req.body.do ?? '');
  if (action === 'site') {
    const siteName = String(req.body.site_name ?? '').trim().slice(0, 30);
    if (siteName) setSetting('site_name', siteName);
    const defState = stateAbbr(String(req.body.default_state ?? '')) ?? 'TX';
    setSetting('default_state', defState);
    for (const key of Object.keys(DEAL_SETTINGS)) {
      const val = parseNum(req.body[key]);
      if (val !== null && val >= 0) {
        setSetting(key, String(val));
      }
    }
    scoreAllSeeded();
    setFlash(res, 'ok', 'Settings saved and opportunity scores recalculated.');
  } else if (action === 'rescore') {
    scoreAllSeeded();
    setFlash(res, 'ok', 'All property scores recalculated.');
  }
  res.redirect(303, '/settings');
});

app.get('/users', requirePerm('admin'), (req: Request, res: Response) => {
  const me = req.platUser!;
  const users = db.users.map((u) => ({
    ...u,
    actions: db.activityLog.filter((a) => a.user_id === u.id).length,
  }));
  res.render('users', {
    users,
    meId: me.id,
  });
});

app.post('/users', requirePerm('admin'), (req: Request, res: Response) => {
  const me = req.platUser!;
  const action = String(req.body.do ?? '');
  const id = Number(req.body.id ?? 0);
  const target = id ? db.users.find((u) => u.id === id) : undefined;

  if (action === 'role' && target && (String(req.body.role) in ROLES)) {
    const nextRole = String(req.body.role) as UserRole;
    target.role = nextRole;
    setFlash(res, 'ok', `${target.name} is now ${ROLES[nextRole]}.`);
  } else if (action === 'toggle' && target && target.id !== me.id) {
    target.isActive = !target.isActive;
    if (!target.isActive) signOutEverywhere(target.id);
    setFlash(res, 'ok', target.isActive ? `${target.name} re-enabled.` : `${target.name} disabled.`);
  } else if (action === 'signout' && target) {
    signOutEverywhere(target.id);
    setFlash(res, 'ok', `Signed ${target.name} out everywhere.`);
  } else if (action === 'invite') {
    const name = String(req.body.name ?? '').trim();
    const email = String(req.body.email ?? '').trim().toLowerCase();
    const role = (String(req.body.role) in ROLES ? String(req.body.role) : 'partner') as UserRole;
    const password = String(req.body.password ?? '');
    const pwErrs = passwordProblems(password);
    if (!name || !email.includes('@')) {
      setFlash(res, 'err', 'Enter a valid name and email address.');
    } else if (db.users.some((u) => u.email.toLowerCase() === email)) {
      setFlash(res, 'err', 'That email address already belongs to a member.');
    } else if (pwErrs.length) {
      setFlash(res, 'err', pwErrs[0]);
    } else {
      db.users.push({
        id: db.nextId('user'),
        email,
        name,
        role,
        passwordHash: hashPassword(password),
        googleSub: null,
        telegramChat: null,
        whatsappPhone: null,
        callmebotKey: null,
        isActive: true,
        sessionEpoch: 1,
        lastLoginAt: null,
        createdAt: now(),
      });
      setFlash(res, 'ok', `${name} added to the team.`);
    }
  }
  res.redirect(303, '/users');
});

app.get('/sites', requirePerm('admin'), (_req: Request, res: Response) => {
  const sites = [...db.externalSites].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
  res.render('sites', {
    sites,
    categories: { listings: 'Listings', records: 'Public records', maps: 'Maps', other: 'Other' },
  });
});

app.post('/sites', requirePerm('admin'), (req: Request, res: Response) => {
  const action = String(req.body.do ?? '');
  if (action === 'save') {
    const id = Number(req.body.id);
    const site = db.externalSites.find((s) => s.id === id);
    if (site) {
      const propUrl = String(req.body.property_url ?? '').trim();
      const areaUrl = String(req.body.area_url ?? '').trim();
      if (!validSiteTemplate(propUrl) || !validSiteTemplate(areaUrl)) {
        setFlash(res, 'err', 'Links must start with https://');
      } else {
        site.name = String(req.body.name ?? site.name).trim() || site.name;
        site.category = String(req.body.category ?? site.category);
        site.property_url = propUrl || null;
        site.area_url = areaUrl || null;
        site.sort_order = Number(req.body.sort_order) || 10;
        site.enabled = Boolean(req.body.enabled);
        setFlash(res, 'ok', `${site.name} saved.`);
      }
    }
  } else if (action === 'add') {
    const name = String(req.body.name ?? '').trim();
    const propUrl = String(req.body.property_url ?? '').trim();
    const areaUrl = String(req.body.area_url ?? '').trim();
    if (!name || !validSiteTemplate(propUrl) || !validSiteTemplate(areaUrl)) {
      setFlash(res, 'err', 'Enter a site name and valid https:// templates.');
    } else {
      db.externalSites.push({
        id: db.nextId('site'),
        name,
        category: String(req.body.category ?? 'other'),
        property_url: propUrl || null,
        area_url: areaUrl || null,
        sort_order: Number(req.body.sort_order) || 100,
        enabled: true,
      });
      setFlash(res, 'ok', `${name} added.`);
    }
  } else if (action === 'delete') {
    const id = Number(req.body.id);
    db.externalSites = db.externalSites.filter((s) => s.id !== id);
    setFlash(res, 'ok', 'Site removed.');
  } else if (action === 'reset') {
    db.externalSites = DEFAULT_EXTERNAL_SITES.map(([name, category, property_url, area_url], idx) => ({
      id: db.nextId('site'),
      name,
      category,
      property_url,
      area_url,
      sort_order: (idx + 1) * 10,
      enabled: true,
    }));
    setFlash(res, 'ok', 'Original site list restored.');
  }
  res.redirect(303, '/sites');
});

// ── Account ────────────────────────────────────────────────────────────────

app.get('/account', requireAuth, (req: Request, res: Response) => {
  res.render('account', {
    me: req.platUser!,
  });
});

app.post('/account', requireAuth, (req: Request, res: Response) => {
  const me = req.platUser!;
  const action = String(req.body.do ?? '');
  if (action === 'profile') {
    const name = String(req.body.name ?? '').trim().slice(0, 80);
    if (name) me.name = name;
    const chat = String(req.body.telegram_chat ?? '').trim();
    me.telegramChat = chat || null;
    setFlash(res, 'ok', 'Account profile saved.');
  } else if (action === 'password') {
    const cur = String(req.body.current_password ?? '');
    const p1 = String(req.body.new_password ?? '');
    const p2 = String(req.body.new_password2 ?? '');
    const probs = passwordProblems(p1);
    if (!verifyPassword(cur, me.passwordHash)) {
      setFlash(res, 'err', 'Current password didn’t match.');
    } else if (p1 !== p2) {
      setFlash(res, 'err', 'New passwords didn’t match.');
    } else if (probs.length) {
      setFlash(res, 'err', probs[0]);
    } else {
      me.passwordHash = hashPassword(p1);
      setFlash(res, 'ok', 'Password changed.');
    }
  } else if (action === 'everywhere') {
    signOutEverywhere(me.id);
    res.clearCookie(SESSION_COOKIE, { path: '/' });
    res.redirect(303, '/login');
    return;
  }
  res.redirect(303, '/account');
});

// ── JSON Endpoints for Client JS ───────────────────────────────────────────

app.get('/api/context/:id', requireAuth, (req: Request, res: Response) => {
  const prop = findProperty(Number(req.params.id));
  if (!prop) {
    res.status(404).json({ error: 'Not found' });
    return;
  }
  res.json(buildPropertyContext(prop));
});

app.get('/api/nearby/:id', requireAuth, (req: Request, res: Response) => {
  const prop = findProperty(Number(req.params.id));
  if (!prop) {
    res.status(404).json({ error: 'Not found' });
    return;
  }
  res.json(buildNearbyPlaces(prop));
});

app.post('/api/nearby/:id', requireAuth, (req: Request, res: Response) => {
  res.json({ ok: true });
});

// ── Un-migrated External Integrations & Legacy Endpoints (501 Stubs) ───────

const notYetMigrated = (_req: Request, res: Response) => {
  res.status(501).json({ error: 'Not yet migrated' });
};

app.all('/auth/google', notYetMigrated);
app.all('/auth/google/callback', notYetMigrated);
app.all('/cron', notYetMigrated);
app.all('/backup/:file', notYetMigrated);
app.all('/setup', notYetMigrated);
app.all('/invite', notYetMigrated);
app.all('/forgot', notYetMigrated);
app.all('/reset', notYetMigrated);
app.all('/api/aerial/:id', notYetMigrated);
app.all('/api/details/:id', notYetMigrated);

// 404 fallback
app.use((req: Request, res: Response) => {
  if (req.path.startsWith('/api/')) {
    res.status(404).json({ error: 'Not found' });
    return;
  }
  res.status(404).render('plain', {
    title: 'Page not found',
    active: '',
    opt: { bare: !req.platUser },
    sketchName: 'home-missing',
    heading: 'Page not found.',
    message: 'Nothing lives at that address.',
    ctaHref: req.platUser ? '/' : '/welcome',
    ctaLabel: req.platUser ? 'Back to dashboard' : 'Back to welcome',
  });
});

app.listen(PORT, '0.0.0.0', () => {
  console.log(`Plat listening on http://0.0.0.0:${PORT}`);
});
