import crypto from 'node:crypto';
import {
  areas,
  fullAddress,
  geoBox,
  geoMiles,
  geoValid,
  ParsedLocation,
  parseLocation,
  slug,
  stateAbbr,
  STATES,
  titleCity,
} from './address.js';
import { conditionTier, OVERALL as CONDITION_OVERALL } from './condition.js';
import {
  daysAgoDate,
  fmtDate,
  median,
  money,
  moneyShort,
  now,
  parseNum,
  percentile,
  ppsf,
  today,
  trimNum,
} from './helpers.js';
import {
  Alert,
  db,
  DealInputs,
  getCondition,
  getPropertyEvents,
  getSetting,
  hydrateProperty,
  LISTING_FILTER_LABELS,
  logActivity,
  MarketRegion,
  PIPELINE_STAGES,
  Property,
  PROPERTY_SORTS,
  PROPERTY_TYPES,
  PropertyCondition,
  propertyDom,
  PropertyView,
  refreshCondition,
  Snapshot,
} from './store.js';

// ── Comps ──────────────────────────────────────────────────────────────────

export const COMP_RADII = [0.5, 1, 2, 5];
export const COMP_MONTHS = [3, 6, 12, 24];

export interface CompRow extends Property {
  distance: number;
  ppsf: number | null;
  compScore: number;
}

export interface CompStats {
  count: number;
  median_price: number | null;
  low_price: number;
  high_price: number;
  median_ppsf: number | null;
  value: number | null;
  value_low: number | null;
  value_high: number | null;
}

function nearScore(a: number | null | undefined, b: number | null | undefined, span: number): number {
  if (a === null || a === undefined || b === null || b === undefined || !Number.isFinite(a) || !Number.isFinite(b)) {
    return 0.5;
  }
  return 1 - Math.min(1, Math.abs(a - b) / span);
}

function scoreComp(s: Property, r: Property, dist: number, radius: number, months: number): number {
  const closeness = 1 - Math.min(1, dist / Math.max(0.1, radius));
  const sqft = Number(s.sqft ?? 0);
  const rs = Number(r.sqft ?? 0);
  const size = sqft > 0 && rs > 0 ? 1 - Math.min(1, Math.abs(rs - sqft) / sqft / 0.35) : 0.4;
  const beds = nearScore(s.beds, r.beds, 2);
  const baths = nearScore(s.baths, r.baths, 2);
  const age = nearScore(s.year_built, r.year_built, 30);
  const saleMs = r.last_sale_date ? new Date(r.last_sale_date + 'T00:00:00Z').getTime() : Date.now();
  const ageDays = Math.max(0, (Date.now() - saleMs) / 86400_000);
  const recency = 1 - Math.min(1, ageDays / Math.max(30, months * 30.4));
  return Math.round((0.3 * closeness + 0.3 * size + 0.1 * beds + 0.05 * baths + 0.07 * age + 0.18 * recency) * 100);
}

function queryComps(s: Property, radius: number, months: number, sameType: boolean): CompRow[] {
  const lat = Number(s.lat);
  const lng = Number(s.lng);
  const box = geoBox(lat, lng, radius);
  const cutoff = daysAgoDate(Math.round(months * 30.4));
  const sqft = Number(s.sqft ?? 0);

  const out: CompRow[] = [];
  for (const r of db.properties) {
    if (r.id === s.id || !r.last_sale_price || r.last_sale_price <= 0 || !r.last_sale_date || r.last_sale_date < cutoff) {
      continue;
    }
    if (!geoValid(r.lat, r.lng)) continue;
    const rLat = Number(r.lat);
    const rLng = Number(r.lng);
    if (rLat < box.lat1 || rLat > box.lat2 || rLng < box.lng1 || rLng > box.lng2) continue;

    const d = geoMiles(lat, lng, rLat, rLng);
    if (d > radius) continue;
    if (sameType && s.property_type && r.property_type && r.property_type !== s.property_type) continue;

    const rs = Number(r.sqft ?? 0);
    if (sqft > 0 && rs > 0 && Math.abs(rs - sqft) / sqft > 0.35) continue;

    out.push({
      ...r,
      distance: d,
      ppsf: rs > 0 ? r.last_sale_price / rs : null,
      compScore: scoreComp(s, r, d, radius, months),
    });
  }
  out.sort((a, b) => b.compScore - a.compScore);
  return out;
}

export function compStats(comps: CompRow[], subject: Property): CompStats | null {
  if (!comps.length) return null;
  const prices = comps.map((c) => Number(c.last_sale_price));
  const ppsfs = comps.map((c) => c.ppsf).filter((v): v is number => v !== null && Number.isFinite(v));
  const sqft = Number(subject.sqft ?? 0);

  const stats: CompStats = {
    count: comps.length,
    median_price: median(prices),
    low_price: Math.min(...prices),
    high_price: Math.max(...prices),
    median_ppsf: ppsfs.length ? median(ppsfs) : null,
    value: null,
    value_low: null,
    value_high: null,
  };
  if (sqft > 0 && ppsfs.length >= 3) {
    const med = median(ppsfs)!;
    const q1 = percentile(ppsfs, 0.25)!;
    const q3 = percentile(ppsfs, 0.75)!;
    stats.value = Math.round((med * sqft) / 100) * 100;
    stats.value_low = Math.round((q1 * sqft) / 100) * 100;
    stats.value_high = Math.round((q3 * sqft) / 100) * 100;
  }
  return stats;
}

export function findComps(subject: Property, opt: { radius?: number; months?: number; limit?: number; sameType?: boolean } = {}) {
  const defR = opt.radius ?? 1;
  const defM = opt.months ?? 6;
  if (!geoValid(subject.lat, subject.lng)) {
    return { comps: [] as CompRow[], stats: null, radius: defR, months: defM, widened: false };
  }
  const explicit = opt.radius !== undefined || opt.months !== undefined;
  const sameType = opt.sameType ?? true;
  let radius = defR;
  let months = defM;
  let comps = queryComps(subject, radius, months, sameType);
  let widened = false;

  if (!explicit && comps.length < 4) {
    for (const [r, m] of [
      [2, 6],
      [2, 12],
      [5, 12],
    ]) {
      const tryList = queryComps(subject, r, m, sameType);
      if (tryList.length > comps.length) {
        comps = tryList;
        radius = r;
        months = m;
        widened = true;
      }
      if (comps.length >= 4) break;
    }
  }
  comps = comps.slice(0, opt.limit ?? 15);
  return { comps, stats: compStats(comps, subject), radius, months, widened };
}

export function findCompListings(s: Property, want = 5): { rows: Array<{ ppsf: number; distance: number }>; radius: number } {
  if (!geoValid(s.lat, s.lng) || !s.sqft || s.sqft <= 0) {
    return { rows: [], radius: 0 };
  }
  const lat = Number(s.lat);
  const lng = Number(s.lng);
  const sqft = Number(s.sqft);
  let best = { rows: [] as Array<{ ppsf: number; distance: number }>, radius: 0 };
  for (const radius of [1, 2, 3]) {
    const box = geoBox(lat, lng, radius);
    const out: Array<{ ppsf: number; distance: number }> = [];
    for (const r of db.properties) {
      if (r.id === s.id || (r.status !== 'for_sale' && r.status !== 'pending') || !r.list_price || r.list_price <= 0 || !r.sqft || r.sqft <= 200) {
        continue;
      }
      if (r.sqft < sqft * 0.65 || r.sqft > sqft * 1.35) continue;
      if (s.property_type && r.property_type && r.property_type !== s.property_type) continue;
      if (!geoValid(r.lat, r.lng)) continue;
      const rLat = Number(r.lat);
      const rLng = Number(r.lng);
      if (rLat < box.lat1 || rLat > box.lat2 || rLng < box.lng1 || rLng > box.lng2) continue;
      const d = geoMiles(lat, lng, rLat, rLng);
      if (d <= radius) out.push({ ppsf: r.list_price / r.sqft, distance: d });
    }
    best = { rows: out, radius };
    if (out.length >= want) break;
  }
  return best;
}

// ── Deal Calculator ────────────────────────────────────────────────────────

export const DEAL_SETTINGS: Record<string, [string, number, string]> = {
  deal_closing_pct: ['Buying closing costs', 2, '% of price'],
  deal_selling_pct: ['Selling costs (commission, transfer, concessions)', 7, '% of ARV'],
  deal_months: ['Months from purchase to sale', 6, 'months'],
  deal_carry_monthly: ['Insurance, utilities, upkeep', 250, '$ a month'],
  deal_tax_pct: ['Property tax, when the record has none', 1.0, '% of price a year'],
  deal_loan_ltv: ['Loan amount', 0, '% of price (0 = cash)'],
  deal_loan_rate: ['Loan interest', 11, '% a year'],
  deal_loan_points: ['Lender points', 2, '% of loan'],
  deal_contingency_pct: ['Contingency', 10, '% of renovation'],
  deal_target_profit: ['Profit you aim for', 30000, '$ per deal'],
  reno_light: ['Renovation — move-in ready / renovated', 8, '$ per sqft'],
  reno_medium: ['Renovation — partially renovated', 30, '$ per sqft'],
  reno_heavy: ['Renovation — needs renovation', 55, '$ per sqft'],
  reno_major: ['Renovation — major repairs', 85, '$ per sqft'],
  reno_unknown: ['Renovation — condition not disclosed', 35, '$ per sqft'],
};

export const DEAL_FIELDS = [
  'purchase_price',
  'closing_pct',
  'reno_cost',
  'holding_months',
  'holding_monthly',
  'loan_ltv_pct',
  'loan_rate_pct',
  'loan_points_pct',
  'selling_pct',
  'arv',
  'contingency_pct',
  'actual_purchase',
  'actual_costs',
  'actual_sale',
] as const;

export function dealSetting(key: string): number {
  const v = getSetting(key);
  if (v !== null && Number.isFinite(Number(v))) return Number(v);
  return DEAL_SETTINGS[key]?.[1] ?? 0;
}

export function savedDeal(propertyId: number): Partial<DealInputs> {
  return db.deals.get(propertyId) ?? {};
}

export function saveDeal(propertyId: number, input: Record<string, any>, userId: number | null): void {
  const before = db.deals.get(propertyId);
  const next: DealInputs = {
    property_id: propertyId,
    purchase_price: null,
    closing_pct: null,
    reno_cost: null,
    holding_months: null,
    holding_monthly: null,
    loan_ltv_pct: null,
    loan_rate_pct: null,
    loan_points_pct: null,
    selling_pct: null,
    arv: null,
    contingency_pct: null,
    actual_purchase: null,
    actual_costs: null,
    actual_sale: null,
    updated_by: userId,
    updated_at: now(),
  };
  const changed: string[] = [];
  for (const f of DEAL_FIELDS) {
    let v = parseNum(input[f]);
    if (v !== null && (v < 0 || v > 1e9)) v = null;
    const rounded = v === null ? null : f.endsWith('_pct') || f === 'holding_months' ? Math.round(v * 100) / 100 : Math.round(v);
    (next as any)[f] = rounded;
    const prevVal = before ? (before as any)[f] : null;
    if (String(prevVal ?? '') !== String(rounded ?? '') && Number(prevVal ?? 0) !== Number(rounded ?? 0)) {
      changed.push(f.replace(/_/g, ' '));
    }
  }
  db.deals.set(propertyId, next);
  if (changed.length > 0) {
    logActivity(userId, 'deal', propertyId, `Changed ${changed.slice(0, 6).join(', ')}`);
  }
}

export interface ArvEstimate {
  value: number | null;
  low: number | null;
  high: number | null;
  confidence: number;
  basis: string;
  comps: number;
  from?: string;
}

export function estimateArv(p: Property): ArvEstimate {
  const sqft = Number(p.sqft ?? 0);
  let c = findComps(p, { months: 12, radius: 1, limit: 25 });
  if (!c.comps.length || !c.stats) {
    c = findComps(p, {});
  }
  const ppsfs = c.comps.map((x) => x.ppsf).filter((v): v is number => v !== null);
  if (sqft > 0 && ppsfs.length >= 3) {
    const q3 = percentile(ppsfs, 0.75)!;
    const med = median(ppsfs)!;
    const q1 = percentile(ppsfs, 0.25)!;
    const q90 = percentile(ppsfs, 0.9)!;
    const spread = med > 0 ? (q3 - q1) / med : 1;
    const avgDist = c.comps.reduce((acc, x) => acc + x.distance, 0) / Math.max(1, c.comps.length);
    const conf =
      35 +
      Math.min(30, ppsfs.length * 4) -
      Math.round(Math.min(1, spread) * 30) -
      Math.round(Math.min(3, avgDist) * 5) +
      (c.months <= 12 ? 10 : 0);
    return {
      value: Math.round((q3 * sqft) / 1000) * 1000,
      low: Math.round((med * sqft) / 1000) * 1000,
      high: Math.round((q90 * sqft) / 1000) * 1000,
      confidence: Math.max(10, Math.min(95, conf)),
      basis: `${ppsfs.length} sales within ${trimNum(c.radius)} mi, last ${c.months} months`,
      comps: ppsfs.length,
    };
  }
  const avm = p.est_value_high || p.est_value || 0;
  if (avm > 0) {
    return {
      value: avm,
      low: p.est_value ?? avm,
      high: avm,
      confidence: 25,
      basis: `${p.est_source === 'zestimate' ? 'Zestimate' : 'RentCast estimate'} (no sold comps on file)`,
      comps: 0,
    };
  }
  const ask = findCompListings(p);
  const askPpsfs = ask.rows.map((r) => r.ppsf);
  if (sqft > 0 && askPpsfs.length >= 5) {
    const med = Math.max(1, median(askPpsfs)!);
    const spread = (percentile(askPpsfs, 0.75)! - percentile(askPpsfs, 0.25)!) / med;
    return {
      value: Math.round((percentile(askPpsfs, 0.65)! * sqft) / 1000) * 1000,
      low: Math.round((med * sqft) / 1000) * 1000,
      high: Math.round((percentile(askPpsfs, 0.85)! * sqft) / 1000) * 1000,
      confidence: Math.max(12, Math.min(30, 30 - Math.round(Math.min(1, spread) * 15) + Math.min(5, askPpsfs.length - 5))),
      basis: `Asking prices of ${askPpsfs.length} similar homes for sale within ${trimNum(ask.radius)} mi — no recorded sales yet`,
      comps: 0,
      from: 'asking',
    };
  }
  return { value: null, low: null, high: null, confidence: 0, basis: 'Not enough data', comps: 0 };
}

export function estimateReno(p: Property, overall: string): number | null {
  const sqft = Number(p.sqft ?? 0);
  if (sqft <= 0) return null;
  const rate = dealSetting(conditionTier(overall));
  const year = p.year_built ?? 0;
  const age = year > 0 && year < 1960 ? 1.15 : year > 0 && year < 1980 ? 1.08 : 1.0;
  return Math.round((sqft * rate * age) / 100) * 100;
}

export interface ScenarioCalc {
  closing: number;
  contingency: number;
  holding: number;
  financing: number;
  loan: number;
  total: number;
  selling: number;
  profit: number;
  roi: number;
  cash: number;
}

export interface DealAnalysis {
  inputs: {
    purchase: number | null;
    arvValue: number | null;
    reno: number | null;
    closingPct: number;
    sellingPct: number;
    months: number;
    monthly: number;
    ltv: number;
    rate: number;
    points: number;
    contPct: number;
  };
  source: Record<string, 'set' | 'est'>;
  arv: ArvEstimate;
  condition: string;
  ok: boolean;
  tax_estimated: boolean;
  missing?: string[];
  base?: ScenarioCalc;
  max_offer?: number;
  rule70?: number;
  break_even?: number;
  target?: number;
  scenarios?: {
    optimistic: ScenarioCalc;
    typical: ScenarioCalc;
    pessimistic: ScenarioCalc;
  };
  actual?: number;
}

export function analyseDeal(p: Property, condition?: PropertyCondition, saved?: Partial<DealInputs>, arv?: ArvEstimate): DealAnalysis {
  const sv = saved ?? savedDeal(p.id);
  const cond = condition ?? getCondition(p.id);
  const arvEst = arv ?? estimateArv(p);
  const src: Record<string, 'set' | 'est'> = {};

  const pick = (field: keyof DealInputs, estimate: number | null): number | null => {
    const val = (sv as any)[field];
    if (val !== undefined && val !== null) {
      src[field] = 'set';
      return Number(val);
    }
    src[field] = 'est';
    return estimate === null ? null : Number(estimate);
  };

  const listed = (p.status === 'for_sale' || p.status === 'pending') && (p.list_price ?? 0) > 0;
  const purchase = pick('purchase_price', listed ? p.list_price : p.last_sale_price || null);
  const arvValue = pick('arv', arvEst.value);
  const reno = pick('reno_cost', estimateReno(p, cond.overall));
  const closingPct = pick('closing_pct', dealSetting('deal_closing_pct'))!;
  const sellingPct = pick('selling_pct', dealSetting('deal_selling_pct'))!;
  const months = pick('holding_months', dealSetting('deal_months'))!;
  let taxYear = Number(p.tax_annual ?? 0);
  const taxEstimated = taxYear <= 0 && Boolean(purchase);
  if (taxEstimated && purchase) {
    taxYear = (purchase * dealSetting('deal_tax_pct')) / 100;
  }
  const monthly = pick('holding_monthly', dealSetting('deal_carry_monthly') + taxYear / 12 + Number(p.hoa_fee ?? 0))!;
  const ltv = pick('loan_ltv_pct', dealSetting('deal_loan_ltv'))!;
  const rate = pick('loan_rate_pct', dealSetting('deal_loan_rate'))!;
  const points = pick('loan_points_pct', dealSetting('deal_loan_points'))!;
  const contPct = pick('contingency_pct', dealSetting('deal_contingency_pct'))!;

  const out: DealAnalysis = {
    inputs: { purchase, arvValue, reno, closingPct, sellingPct, months, monthly, ltv, rate, points, contPct },
    source: src,
    arv: arvEst,
    condition: cond.overall,
    ok: false,
    tax_estimated: taxEstimated,
  };

  if (!purchase || !arvValue || reno === null) {
    const missing: string[] = [];
    if (!purchase) missing.push('purchase price');
    if (!arvValue) missing.push('ARV');
    if (reno === null) missing.push('renovation cost (needs sqft)');
    out.missing = missing;
    return out;
  }

  const calc = (price: number, arvV: number, renoV: number, m: number): ScenarioCalc => {
    const closing = (price * closingPct) / 100;
    const contingency = (renoV * contPct) / 100;
    const holding = m * monthly;
    const loan = (price * ltv) / 100;
    const financing = loan * ((rate / 100 / 12) * m + points / 100);
    const total = price + closing + renoV + contingency + holding + financing;
    const selling = (arvV * sellingPct) / 100;
    const profit = arvV - total - selling;
    return {
      closing,
      contingency,
      holding,
      financing,
      loan,
      total,
      selling,
      profit,
      roi: total > 0 ? (profit / total) * 100 : 0,
      cash: total - loan,
    };
  };

  const base = calc(purchase, arvValue, reno, months);
  const target = dealSetting('deal_target_profit');
  const perDollar = 1 + closingPct / 100 + (ltv / 100) * ((rate / 100 / 12) * months + points / 100);
  const fixed = reno * (1 + contPct / 100) + months * monthly;
  const maxOffer = (arvValue * (1 - sellingPct / 100) - fixed - target) / perDollar;

  out.ok = true;
  out.base = base;
  out.max_offer = Math.max(0, maxOffer);
  out.rule70 = Math.max(0, arvValue * 0.7 - reno);
  out.break_even = base.total / Math.max(0.01, 1 - sellingPct / 100);
  out.target = target;
  out.scenarios = {
    optimistic: calc(purchase, arvValue * 1.05, reno * 0.9, Math.max(1, months - 1)),
    typical: base,
    pessimistic: calc(purchase, arvValue * 0.92, reno * 1.25, months + 2),
  };
  if (sv.actual_sale && sv.actual_purchase) {
    out.actual = sv.actual_sale - sv.actual_purchase - (sv.actual_costs ?? 0);
  }
  return out;
}

// ── Opportunity & Risk Score ───────────────────────────────────────────────

export const SCORE_WEIGHTS: Record<string, [string, number]> = {
  profit: ['Net profit', 30],
  roi: ['Return on investment', 20],
  price: ['Price vs the area', 15],
  arv: ['ARV backed by sales', 15],
  condition: ['Renovation load', 10],
  liquidity: ['How fast homes sell', 5],
  data: ['How complete the data is', 5],
};

export function scoreBand(score: number | null | undefined): string {
  if (score === null || score === undefined) return 'none';
  if (score >= 70) return 'high';
  if (score >= 50) return 'mid';
  return 'low';
}

export function areaPpsf(loc: ParsedLocation, type: string | null = null): number | null {
  const vals: number[] = [];
  for (const p of db.properties) {
    if (p.status !== 'for_sale' || !p.list_price || p.list_price <= 0 || !p.sqft || p.sqft <= 200) continue;
    if (type && p.property_type !== type) continue;
    if (loc.type === 'zip' && p.zip !== loc.zip) continue;
    else if (loc.type === 'city' && (p.state !== loc.state || p.city.toLowerCase() !== (loc.city ?? '').toLowerCase())) continue;
    else if (loc.type !== 'zip' && loc.type !== 'city') return null;
    vals.push(p.list_price / p.sqft);
  }
  return vals.length >= 5 ? median(vals) : null;
}

function areaPpsfForProperty(p: Property): number | null {
  const loc: ParsedLocation =
    p.zip !== ''
      ? { type: 'zip', zip: p.zip, key: `zip:${p.zip}`, label: p.zip }
      : { type: 'city', city: p.city, state: p.state, key: `city:${p.state}:${p.city}`, label: `${p.city}, ${p.state}` };
  return (p.property_type ? areaPpsf(loc, p.property_type) : null) ?? areaPpsf(loc, null);
}

export function computeScore(p: Property, deal: DealAnalysis, condition: PropertyCondition) {
  const parts: Record<string, number> = {};
  for (const k of Object.keys(SCORE_WEIGHTS)) parts[k] = 0;
  const risks: string[] = [];
  const missing: string[] = [];

  const lerp = (v: number, lo: number, hi: number, max: number) =>
    Math.round(Math.max(0, Math.min(1, (v - lo) / (hi - lo))) * max);

  const facts: Array<[keyof Property, string]> = [
    ['sqft', 'living area'],
    ['beds', 'bedrooms'],
    ['baths', 'bathrooms'],
    ['year_built', 'year built'],
    ['lat', 'map position'],
    ['tax_annual', 'property tax'],
  ];
  let have = 0;
  for (const [col, label] of facts) {
    const val = p[col];
    if (val !== null && val !== undefined && val !== '' && Number(val) > 0) {
      have++;
    } else {
      missing.push(label);
    }
  }
  const condKnown = condition.overall !== 'not_disclosed';
  if (!condKnown) missing.push('condition');
  const completeness = (have + (condKnown ? 1 : 0) + (p.description ? 1 : 0)) / (facts.length + 2);
  parts.data = Math.round(completeness * 5);

  let arvConf = deal.arv.confidence ?? 0;
  if (deal.source.arv === 'set') arvConf = Math.max(arvConf, 70);
  parts.arv = Math.round((arvConf / 100) * 15);

  if (deal.ok && deal.base && deal.scenarios && deal.inputs.purchase && deal.inputs.arvValue && deal.inputs.reno !== null) {
    const profit = deal.base.profit;
    const roi = deal.base.roi;
    parts.profit = lerp(profit, 0, 70000, 30);
    parts.roi = lerp(roi, 0, 25, 20);
    const renoShare = deal.inputs.reno / Math.max(1, deal.inputs.purchase);
    parts.condition = condKnown ? 10 - lerp(renoShare, 0.08, 0.45, 8) : 4;
    if (profit / Math.max(1, deal.inputs.arvValue) < 0.08) risks.push('Thin margin: under 8% of ARV');
    if (renoShare > 0.3) risks.push('Renovation is over 30% of the price');
    if (deal.scenarios.pessimistic.profit < 0) risks.push('Loses money in the cautious scenario');
  } else if (deal.missing) {
    missing.push(...deal.missing);
  }

  const area = areaPpsfForProperty(p);
  const pp = ppsf(deal.inputs.purchase, p.sqft);
  parts.price = area && pp ? lerp(1 - pp / area, -0.05, 0.25, 15) : 5;

  const dom = propertyDom(p);
  parts.liquidity = dom === null ? 2 : 5 - lerp(dom, 21, 150, 4);

  let score: number | null = deal.ok ? Object.values(parts).reduce((a, b) => a + b, 0) : null;

  let risk = 0;
  if (condition.overall === 'major_repair') {
    risk += 30;
    risks.push('Major repairs mentioned');
  } else if (!condKnown) {
    risk += 15;
    risks.push('Condition not disclosed');
  }
  risk += Math.round((100 - arvConf) * 0.25);
  const askingArv = deal.arv.from === 'asking' && deal.source.arv !== 'set';
  if (askingArv) {
    risk += 10;
    risks.push('ARV from asking prices, not recorded sales');
    if (score !== null) score = Math.min(score, 69);
  } else if (arvConf < 40) {
    risks.push('Few comparable sales behind the ARV');
  }
  for (const r of risks) {
    if (r === 'Thin margin: under 8% of ARV') risk += 15;
    else if (r === 'Renovation is over 30% of the price') risk += 10;
    else if (r === 'Loses money in the cautious scenario') risk += 10;
  }
  if (dom !== null && dom > 90) {
    risk += 8;
    risks.push(`On the market ${dom} days`);
  }
  if (p.year_built && p.year_built < 1950) {
    risk += 8;
    risks.push('Built before 1950');
  }
  const fl = p.flags.split(',').filter(Boolean);
  if (fl.some((x) => ['foreclosure', 'auction', 'as_is', 'short_sale'].includes(x))) {
    risk += 10;
    risks.push('Distressed sale (title, access or timing risk)');
  }

  const confidence = Math.round(arvConf * 0.6 + completeness * 40);
  return {
    score,
    risk: Math.min(100, risk),
    confidence: Math.max(0, Math.min(100, confidence)),
    parts,
    risks: Array.from(new Set(risks)),
    missing: Array.from(new Set(missing)),
  };
}

export function refreshScore(propertyId: number) {
  const p = db.properties.find((x) => x.id === propertyId);
  if (!p) return null;
  const condition = refreshCondition(p);
  const deal = analyseDeal(p, condition);
  const s = computeScore(p, deal, condition);
  p.score = s.score;
  p.risk = s.risk;
  p.confidence = s.confidence;
  p.est_arv = deal.inputs.arvValue ? Math.round(deal.inputs.arvValue) : null;
  p.est_reno = deal.inputs.reno !== null ? Math.round(deal.inputs.reno) : null;
  p.est_profit = deal.ok && deal.base ? Math.round(deal.base.profit) : null;
  p.est_roi = deal.ok && deal.base ? Math.round(deal.base.roi * 10) / 10 : null;
  p.scored_at = now();
  return { deal, score: s, condition };
}

export function scoreAllSeeded(): void {
  for (const p of db.properties) {
    if (p.status === 'for_sale' || p.status === 'pending') {
      refreshScore(p.id);
    }
  }
}

// ── Search ─────────────────────────────────────────────────────────────────

export const NUMERIC_FILTERS = [
  'price_min', 'price_max', 'beds_min', 'beds_max', 'baths_min', 'baths_max',
  'sqft_min', 'sqft_max', 'lot_min', 'lot_max', 'year_min', 'year_max',
  'hoa_min', 'hoa_max', 'dom_min', 'dom_max', 'garage_min', 'ppsf_max',
  'score_min', 'profit_min', 'risk_max', 'below_median',
] as const;

export interface SearchFilters {
  q: string;
  mode: 'sale' | 'sold';
  sold_days: number;
  types: string[];
  listing: string[];
  cond: string[];
  c_kitchen: string;
  c_bathroom: string;
  c_roof: string;
  c_hvac: string;
  sort: string;
  [key: string]: any;
}

export function searchFromRequest(src: Record<string, any>): SearchFilters {
  const toArray = (v: unknown): string[] => {
    if (Array.isArray(v)) return v.map(String);
    if (typeof v === 'string' && v !== '') return [v];
    return [];
  };
  const f: SearchFilters = {
    q: String(src.q ?? '').trim().slice(0, 300),
    mode: src.mode === 'sold' ? 'sold' : 'sale',
    sold_days: [30, 90, 180, 365, 730].includes(Number(src.sold_days)) ? Number(src.sold_days) : 180,
    types: toArray(src.types).filter((t) => PROPERTY_TYPES.includes(t)),
    listing: toArray(src.listing).filter((l) => Boolean(LISTING_FILTER_LABELS[l])),
    cond: toArray(src.cond).filter((c) => Boolean(CONDITION_OVERALL[c])),
    c_kitchen: ['any', 'updated', 'needs_work'].includes(String(src.c_kitchen ?? 'any')) ? String(src.c_kitchen ?? 'any') : 'any',
    c_bathroom: ['any', 'updated', 'needs_work'].includes(String(src.c_bathroom ?? 'any')) ? String(src.c_bathroom ?? 'any') : 'any',
    c_roof: ['any', 'updated', 'needs_work'].includes(String(src.c_roof ?? 'any')) ? String(src.c_roof ?? 'any') : 'any',
    c_hvac: ['any', 'updated', 'needs_work'].includes(String(src.c_hvac ?? 'any')) ? String(src.c_hvac ?? 'any') : 'any',
    sort: PROPERTY_SORTS[String(src.sort ?? '')] ? String(src.sort) : 'newest',
  };
  for (const k of NUMERIC_FILTERS) {
    const v = parseNum(src[k]);
    if (v !== null && v >= 0) f[k] = v;
  }
  return f;
}

export function compactSearch(f: Partial<SearchFilters>): Record<string, any> {
  const out: Record<string, any> = {};
  const keys = ['q', 'mode', 'sold_days', 'types', 'listing', 'cond', 'c_kitchen', 'c_bathroom', 'c_roof', 'c_hvac', 'sort', ...NUMERIC_FILTERS];
  for (const k of keys) {
    const v = (f as any)[k];
    if (
      v === undefined ||
      v === null ||
      v === '' ||
      (Array.isArray(v) && v.length === 0) ||
      (k === 'mode' && v === 'sale') ||
      (k === 'sort' && v === 'newest') ||
      (k.startsWith('c_') && v === 'any') ||
      (k === 'sold_days' && ((f.mode ?? 'sale') !== 'sold' || Number(v) === 180))
    ) {
      continue;
    }
    out[k] = v;
  }
  return out;
}

export function daysLabel(days: number): string {
  switch (days) {
    case 30: return '30 days';
    case 90: return '3 months';
    case 180: return '6 months';
    case 365: return 'year';
    case 730: return '2 years';
    default: return `${days} days`;
  }
}

export function describeSearch(f: Partial<SearchFilters>): string {
  const defState = getSetting('default_state', 'TX') ?? 'TX';
  const bits: string[] = [];
  if (f.q) {
    const loc = parseLocation(String(f.q), defState);
    bits.push(loc?.label ?? String(f.q));
  }
  if (f.mode === 'sold') {
    bits.push(`sold in the last ${daysLabel(Number(f.sold_days ?? 180))}`);
  }
  const span = (lo: unknown, hi: unknown, fmt: (n: unknown) => string, unit = ''): string | null => {
    const hasLo = lo !== undefined && lo !== null && lo !== '' && Number(lo) > 0;
    const hasHi = hi !== undefined && hi !== null && hi !== '' && Number(hi) > 0;
    if (hasLo && hasHi) return `${fmt(lo)}–${fmt(hi)}${unit}`;
    if (hasLo) return `${fmt(lo)}+${unit}`;
    if (hasHi) return `up to ${fmt(hi)}${unit}`;
    return null;
  };
  const s1 = span(f.price_min, f.price_max, moneyShort);
  if (s1) bits.push(s1);
  const s2 = span(f.beds_min, f.beds_max, trimNum, ' bd');
  if (s2) bits.push(s2);
  const s3 = span(f.baths_min, f.baths_max, trimNum, ' ba');
  if (s3) bits.push(s3);
  const s4 = span(f.sqft_min, f.sqft_max, (v) => Math.round(Number(v)).toLocaleString('en-US'), ' sqft');
  if (s4) bits.push(s4);
  const s5 = span(f.year_min, f.year_max, (v) => String(Math.round(Number(v))), ' built');
  if (s5) bits.push(s5);
  if (f.types?.length) bits.push(f.types.join(', '));
  for (const l of f.listing ?? []) {
    bits.push((LISTING_FILTER_LABELS[l] ?? l).toLowerCase());
  }
  for (const c of f.cond ?? []) {
    bits.push((CONDITION_OVERALL[c] ?? c).toLowerCase());
  }
  for (const part of ['kitchen', 'bathroom', 'roof', 'hvac']) {
    const cv = (f as any)[`c_${part}`] ?? 'any';
    if (cv !== 'any') bits.push(`${part} ${cv === 'updated' ? 'updated' : 'needs work'}`);
  }
  if (f.hoa_max !== undefined && f.hoa_max !== null && f.hoa_max !== '') {
    bits.push(Number(f.hoa_max) === 0 ? 'no HOA' : `HOA ≤ $${Math.round(Number(f.hoa_max)).toLocaleString('en-US')}`);
  }
  if (f.score_min) bits.push(`score ${f.score_min}+`);
  if (f.profit_min) bits.push(`profit ${moneyShort(f.profit_min)}+`);
  if (f.below_median) bits.push(`${trimNum(f.below_median)}% under area $/sqft`);
  return bits.join(' · ');
}

function matchesLoc(p: Property, loc: ParsedLocation): boolean {
  switch (loc.type) {
    case 'zip':
      return p.zip === loc.zip;
    case 'city':
      return p.state === loc.state && p.city.toLowerCase() === (loc.city ?? '').toLowerCase();
    case 'county': {
      const c = (p.county ?? '').toLowerCase();
      const target = (loc.county ?? '').toLowerCase();
      return p.state === loc.state && (c === target || c === `${target} county` || c === `${target} city`);
    }
    case 'state':
      return p.state === loc.state;
    case 'multi':
      return (loc.parts ?? []).some((part) => matchesLoc(p, part));
    default:
      return true;
  }
}

export function isPriceReduced(propertyId: number, sinceDays = 90): boolean {
  const cutoff = daysAgoDate(sinceDays);
  const evs = getPropertyEvents(propertyId);
  for (let i = 0; i < evs.length; i++) {
    const ev = evs[i];
    if (ev.event === 'price_change' && ev.event_date >= cutoff) {
      let maxBefore = 0;
      for (const prev of evs) {
        if (prev.id !== ev.id && prev.event_date <= ev.event_date && (prev.event === 'listed' || prev.event === 'relisted' || prev.event === 'price_change')) {
          if (prev.price > maxBefore) maxBefore = prev.price;
        }
      }
      if (maxBefore > ev.price) return true;
    }
  }
  return false;
}

export function searchProperties(f: Partial<SearchFilters> & { loc?: ParsedLocation; limit?: number; offset?: number; since?: string }): { rows: PropertyView[]; total: number } {
  const mode = f.mode === 'sold' ? 'sold' : 'sale';
  const soldCutoff = daysAgoDate(Math.max(1, Math.min(3650, Number(f.sold_days ?? 180))));
  const weekCutoff = daysAgoDate(7);

  const rangePass = (val: number | null, min: unknown, max: unknown, nullPasses = false): boolean => {
    const hasMin = min !== undefined && min !== null && min !== '' && Number(min) > 0;
    const hasMax = max !== undefined && max !== null && max !== '' && (Number(max) > 0 || nullPasses);
    if (!hasMin && !hasMax) return true;
    if (val === null || val === undefined) return nullPasses;
    if (hasMin && val < Number(min)) return false;
    if (hasMax && val > Number(max)) return false;
    return true;
  };

  let matches: PropertyView[] = [];
  for (const p of db.properties) {
    if (mode === 'sold') {
      if (!p.last_sale_price || p.last_sale_price <= 0 || !p.last_sale_date || p.last_sale_date < soldCutoff) continue;
    } else {
      if ((p.status !== 'for_sale' && p.status !== 'pending') || !p.list_price || p.list_price <= 0) continue;
    }
    if (f.loc && !matchesLoc(p, f.loc)) continue;

    const price = mode === 'sold' ? p.last_sale_price : p.list_price;
    if (!rangePass(price, f.price_min, f.price_max)) continue;
    if (!rangePass(p.beds, f.beds_min, f.beds_max)) continue;
    if (!rangePass(p.baths, f.baths_min, f.baths_max)) continue;
    if (!rangePass(p.sqft, f.sqft_min, f.sqft_max)) continue;
    if (!rangePass(p.lot_sqft, f.lot_min, f.lot_max)) continue;
    if (!rangePass(p.year_built, f.year_min, f.year_max)) continue;
    if (!rangePass(p.garage_spaces, f.garage_min, null)) continue;
    if (!rangePass(p.hoa_fee, f.hoa_min, f.hoa_max, true)) continue;
    if (!rangePass(p.score, f.score_min, null)) continue;
    if (!rangePass(p.est_profit, f.profit_min, null)) continue;
    if (f.risk_max && Number(f.risk_max) > 0 && p.risk !== null && p.risk > Number(f.risk_max)) continue;

    if (mode === 'sale') {
      const domDate = p.listed_date ?? p.first_seen_at.slice(0, 10);
      if (f.dom_max && Number(f.dom_max) > 0 && domDate < daysAgoDate(Number(f.dom_max))) continue;
      if (f.dom_min && Number(f.dom_min) > 0 && domDate > daysAgoDate(Number(f.dom_min))) continue;
    }
    if (f.ppsf_max && Number(f.ppsf_max) > 0) {
      if (!p.sqft || p.sqft <= 0 || !price || price / p.sqft > Number(f.ppsf_max)) continue;
    }
    if (f.types?.length && (!p.property_type || !f.types.includes(p.property_type))) continue;

    const cond = getCondition(p.id);
    if (f.listing?.length) {
      const flags = p.flags.split(',').filter(Boolean);
      const ok = f.listing.some((k) => {
        if (k === 'new') return (p.listed_date ?? p.first_seen_at.slice(0, 10)) >= weekCutoff;
        if (k === 'reduced') return isPriceReduced(p.id, 90);
        if (k === 'foreclosure') return flags.includes('foreclosure') || flags.includes('short_sale');
        if (k === 'auction') return flags.includes('auction');
        if (k === 'fixer') return flags.includes('fixer') || cond.overall === 'needs_renovation' || cond.overall === 'major_repair';
        if (k === 'basement') return p.has_basement === 1;
        return false;
      });
      if (!ok) continue;
    }

    if (f.cond?.length && !f.cond.includes(cond.overall)) continue;

    let partOk = true;
    for (const part of ['kitchen', 'bathroom', 'roof', 'hvac'] as const) {
      const want = (f as any)[`c_${part}`] ?? 'any';
      const val = (cond as any)[part];
      if (want === 'updated' && val !== 'updated' && val !== 'new') partOk = false;
      if (want === 'needs_work' && val !== 'needs_work' && val !== 'original') partOk = false;
    }
    if (!partOk) continue;

    if (f.since && p.first_seen_at <= f.since) continue;

    matches.push(hydrateProperty(p));
  }

  const sort = f.sort ?? 'newest';
  matches.sort((a, b) => {
    const ap = mode === 'sold' ? (a.last_sale_price ?? 0) : (a.list_price ?? 0);
    const bp = mode === 'sold' ? (b.last_sale_price ?? 0) : (b.list_price ?? 0);
    switch (sort) {
      case 'score':
        if (a.score === null && b.score !== null) return 1;
        if (a.score !== null && b.score === null) return -1;
        return (b.score ?? 0) - (a.score ?? 0) || b.id - a.id;
      case 'profit':
        if (a.est_profit === null && b.est_profit !== null) return 1;
        if (a.est_profit !== null && b.est_profit === null) return -1;
        return (b.est_profit ?? 0) - (a.est_profit ?? 0) || b.id - a.id;
      case 'price_asc':
        return ap - bp || b.id - a.id;
      case 'price_desc':
        return bp - ap || b.id - a.id;
      case 'ppsf_asc': {
        const app = a.sqft ? ap / a.sqft : 999999;
        const bpp = b.sqft ? bp / b.sqft : 999999;
        return app - bpp || b.id - a.id;
      }
      case 'sqft_desc':
        return (b.sqft ?? 0) - (a.sqft ?? 0) || b.id - a.id;
      case 'dom_desc': {
        const ad = a.listed_date ?? a.first_seen_at.slice(0, 10);
        const bd = b.listed_date ?? b.first_seen_at.slice(0, 10);
        return ad.localeCompare(bd) || b.id - a.id;
      }
      default: {
        if (mode === 'sold') return (b.last_sale_date ?? '').localeCompare(a.last_sale_date ?? '') || b.id - a.id;
        const ad = a.listed_date ?? a.first_seen_at.slice(0, 10);
        const bd = b.listed_date ?? b.first_seen_at.slice(0, 10);
        return bd.localeCompare(ad) || b.id - a.id;
      }
    }
  });

  const total = matches.length;
  const limit = Math.max(1, Math.min(2000, Number(f.limit ?? 60)));
  const offset = Math.max(0, Number(f.offset ?? 0));
  return { rows: matches.slice(offset, offset + limit), total };
}

export function latestSnapshot(loc: ParsedLocation, mode: 'sale' | 'sold'): Snapshot | null {
  const candidates = db.snapshots
    .filter((s) => s.loc_key === loc.key && s.mode === mode)
    .sort((a, b) => b.fetched_at.localeCompare(a.fetched_at));
  return candidates[0] ?? null;
}

export function runSearch(f: SearchFilters, opt: { limit?: number; offset?: number } = {}) {
  const defState = getSetting('default_state', 'TX') ?? 'TX';
  const loc = parseLocation(f.q ?? '', defState);
  if (!loc) {
    return {
      loc: null as ParsedLocation | null,
      rows: [] as PropertyView[],
      total: 0,
      snapshot: null as Snapshot | null,
      fetched: false,
      partial: false,
      notice: null as string | null,
      error: 'Type a state (Texas), a county (Travis County), a city (Austin, TX), one or more ZIP codes, or a full street address.',
    };
  }
  if (loc.type === 'address') {
    return { loc, rows: [] as PropertyView[], total: 0, snapshot: null as Snapshot | null, fetched: false, partial: false, notice: null, error: null };
  }
  const snap = latestSnapshot(loc, f.mode);
  const res = searchProperties({ ...f, loc, limit: opt.limit ?? 60, offset: opt.offset ?? 0 });
  return {
    loc,
    rows: res.rows,
    total: res.total,
    snapshot: snap,
    fetched: false,
    partial: false,
    notice: null as string | null,
    error: null as string | null,
  };
}

// ── External Sites ─────────────────────────────────────────────────────────

export const SITE_PLACEHOLDERS = [
  '{address}', '{address_full}', '{address_slug}', '{city}', '{city_slug}', '{city_dash}', '{city_under}',
  '{state}', '{state_lower}', '{zip}', '{county}', '{area}', '{area_slug}', '{area_under}', '{lat}', '{lng}',
];

export function validSiteTemplate(tpl: string): boolean {
  return tpl === '' || /^https:\/\/[a-z0-9.-]+\.[a-z]{2,}(\/|\?|$)/i.test(tpl);
}

function fillSiteTemplate(tpl: string, vars: Record<string, string>): string | null {
  let s = tpl.trim();
  if (!s) return null;
  for (const [k, v] of Object.entries(vars)) {
    if (s.includes(k)) {
      if (v === '') return null;
      s = s.split(k).join(v);
    }
  }
  return validSiteTemplate(s) && !s.includes('{') ? s : null;
}

function siteVars(p: { address?: string; city?: string; state?: string; zip?: string; county?: string | null; lat?: number | null; lng?: number | null }, loc?: ParsedLocation | null) {
  const city = p.city ?? '';
  const state = p.state ?? '';
  const zip = p.zip ?? '';
  const full = fullAddress(p);
  const isZipArea = loc?.type === 'zip';
  const areaText = isZipArea || city === '' ? zip : `${city}, ${state}`;
  const hasGeo = geoValid(p.lat, p.lng);
  return {
    '{address}': encodeURIComponent(p.address ?? ''),
    '{address_full}': encodeURIComponent(full),
    '{address_slug}': slug(full),
    '{city}': encodeURIComponent(city),
    '{city_slug}': slug(city, '-', true),
    '{city_dash}': slug(city),
    '{city_under}': slug(city, '_'),
    '{state}': encodeURIComponent(state),
    '{state_lower}': state.toLowerCase(),
    '{zip}': encodeURIComponent(zip),
    '{county}': encodeURIComponent(p.county ?? ''),
    '{area}': encodeURIComponent(areaText),
    '{area_slug}': isZipArea || city === '' ? zip : slug(`${city} ${state}`),
    '{area_under}': isZipArea || city === '' ? zip : `${slug(city)}_${state}`,
    '{lat}': hasGeo ? String(Number(p.lat)) : '',
    '{lng}': hasGeo ? String(Number(p.lng)) : '',
  };
}

export function externalForProperty(p: Property) {
  const vars = siteVars(p, null);
  return db.externalSites
    .filter((s) => s.enabled && s.property_url)
    .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
    .map((s) => ({ name: s.name, category: s.category, url: fillSiteTemplate(s.property_url!, vars) }))
    .filter((x): x is { name: string; category: string; url: string } => x.url !== null);
}

export function externalForArea(loc: ParsedLocation & { city?: string; state?: string }) {
  const vars = siteVars({ city: loc.city ?? '', state: loc.state ?? '', zip: loc.zip ?? '' }, loc);
  return db.externalSites
    .filter((s) => s.enabled && s.area_url)
    .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
    .map((s) => ({ name: s.name, category: s.category, url: fillSiteTemplate(s.area_url!, vars) }))
    .filter((x): x is { name: string; category: string; url: string } => x.url !== null);
}

// ── Link Import ────────────────────────────────────────────────────────────

const KNOWN_SITES: Record<string, string> = {
  'zillow.com': 'Zillow',
  'homes.com': 'Homes.com',
  'realtor.com': 'Realtor.com',
  'redfin.com': 'Redfin',
  'trulia.com': 'Trulia',
  'movoto.com': 'Movoto',
  'compass.com': 'Compass',
  'auction.com': 'Auction.com',
  'hubzu.com': 'Hubzu',
  'xome.com': 'Xome',
  'brightmls.com': 'Bright MLS',
  'loopnet.com': 'LoopNet',
};

export function siteFromUrl(rawUrl: string): string {
  try {
    const host = new URL(rawUrl).hostname.toLowerCase();
    for (const [domain, name] of Object.entries(KNOWN_SITES)) {
      if (host === domain || host.endsWith('.' + domain)) return name;
    }
    return host.replace(/^www\./, '') || 'Other site';
  } catch {
    return 'Other site';
  }
}

export function addressTextFromUrl(rawUrl: string): string | null {
  try {
    const u = new URL(rawUrl);
    const segs = decodeURIComponent(u.pathname).split('/').filter(Boolean);
    if (!segs.length) return null;
    const host = u.hostname.toLowerCase();

    if (host.includes('redfin.com') && segs.length >= 3 && segs[0].length === 2) {
      const street = segs[2].replace(/-/g, ' ');
      const zm = street.match(/\s(\d{5})$/);
      const zip = zm ? zm[1] : '';
      return `${street.replace(/\s\d{5}$/, '').trim()}, ${segs[1].replace(/-/g, ' ')}, ${segs[0].toUpperCase()}${zip ? ' ' + zip : ''}`;
    }
    if (host.includes('realtor.com')) {
      for (const s of segs) {
        const m = s.match(/^(\d[^_]*)_([^_]+)_([A-Za-z]{2})_(\d{5})/);
        if (m) return `${m[1].replace(/-/g, ' ')}, ${m[2].replace(/-/g, ' ')}, ${m[3].toUpperCase()} ${m[4]}`;
      }
    }
    for (const s of segs) {
      if (/^\d+[a-z]?[-_ ][a-z]/i.test(s)) {
        const cleaned = s.replace(/[-_](\d+_zpid|[a-z]*\d{6,}[a-z0-9]*)$/i, '');
        let t = cleaned.replace(/[-_]+/g, ' ').trim();
        t = t.replace(/\s+([A-Za-z]{2})\s+(\d{5})(?:\s+\d+)?$/, ', $1 $2');
        t = t.replace(/\s+([A-Za-z]{2})$/, ', $1');
        return t;
      }
    }
    return null;
  } catch {
    return null;
  }
}

export function resolveLink(rawUrl: string): {
  ok: boolean;
  error?: string;
  site: string;
  address?: string;
  city?: string;
  state?: string;
  zip?: string;
  lat?: number;
  lng?: number;
  county?: string | null;
} {
  const site = siteFromUrl(rawUrl);
  if (!/^https?:\/\/[^\s]+$/i.test(rawUrl)) {
    return { ok: false, error: 'That doesn’t look like a web link.', site };
  }
  const text = addressTextFromUrl(rawUrl);
  if (!text) {
    return {
      ok: false,
      error: 'This link doesn’t contain the address. Type the address below instead — the link is still saved with the home.',
      site,
    };
  }
  // Parse components heuristically from the extracted string (since outbound geocoding isn't assumed offline)
  const parts = text.split(',').map((s) => s.trim()).filter(Boolean);
  const tail = parts.pop() ?? '';
  const tailMatch = tail.match(/^([A-Za-z]{2})(?:\s+(\d{5}))?$/);
  const st = tailMatch ? stateAbbr(tailMatch[1]) ?? 'TX' : 'TX';
  const zip = tailMatch?.[2] ?? '78704';
  const rawHead = parts.join(' ');
  const tokens = rawHead.split(/\s+/);
  const suffixSet = new Set(['st', 'street', 'ave', 'avenue', 'dr', 'drive', 'rd', 'road', 'ln', 'lane', 'ct', 'court', 'pl', 'place', 'blvd', 'way', 'cir', 'circle']);
  let splitIdx = tokens.length - 1;
  for (let i = 1; i < tokens.length - 1; i++) {
    if (suffixSet.has(tokens[i].toLowerCase())) {
      splitIdx = i + 1;
      break;
    }
  }
  const streetTokens = tokens.slice(0, Math.max(2, splitIdx));
  const cityTokens = tokens.slice(Math.max(2, splitIdx));
  const street = streetTokens.map((w) => (/^[nsew]{1,2}$/i.test(w) ? w.toUpperCase() : w[0].toUpperCase() + w.slice(1).toLowerCase())).join(' ');
  const city = titleCity(cityTokens.join(' ') || (st === 'VA' ? 'Richmond' : 'Austin'));
  const isVA = st === 'VA';
  return {
    ok: true,
    site,
    address: street,
    city,
    state: st,
    zip,
    lat: isVA ? 37.5512 : 30.2465,
    lng: isVA ? -77.4591 : -97.7681,
    county: isVA ? 'Richmond' : 'Travis',
  };
}

// ── Alerts ─────────────────────────────────────────────────────────────────

export const ALERT_KINDS: Record<string, string> = {
  new_listing: 'New listing matching criteria',
  price_drop: 'Price cut on pipeline homes',
  new_sale: 'New comparable sale',
};

export const ALERT_FREQUENCIES: Record<number, string> = {
  6: 'Every 6 hours',
  12: 'Every 12 hours',
  24: 'Once a day',
  168: 'Once a week',
};

export function alertMonthlyCost(a: Pick<Alert, 'enabled' | 'use_quota' | 'location' | 'every_hours'>): number {
  if (!a.enabled || !a.use_quota || !a.location) return 0;
  return Math.ceil(720 / Math.max(1, a.every_hours));
}

export function runAlertNow(alert: Alert): { checked: number; hits: number; note: string } {
  const defState = getSetting('default_state', 'TX') ?? 'TX';
  let matched: PropertyView[] = [];
  if (alert.kind === 'price_drop') {
    const watchIds = new Set(db.watchlist.map((w) => w.property_id));
    const loc = alert.location ? parseLocation(alert.location, defState) : null;
    matched = db.properties
      .filter((p) => watchIds.has(p.id) && p.status === 'for_sale' && (!loc || matchesLoc(p, loc)) && isPriceReduced(p.id, 30))
      .map(hydrateProperty);
  } else {
    const loc = alert.location ? parseLocation(alert.location, defState) : undefined;
    matched = searchProperties({
      ...alert.criteria,
      mode: alert.kind === 'new_sale' ? 'sold' : 'sale',
      sold_days: 90,
      loc: loc ?? undefined,
      limit: 50,
    }).rows;
  }

  let newHits = 0;
  for (const m of matched) {
    const already = db.alertHits.some((h) => h.alert_id === alert.id && h.property_id === m.id);
    if (!already) {
      const price = alert.kind === 'new_sale' ? (m.last_sale_price ?? 0) : (m.list_price ?? 0);
      db.alertHits.push({
        id: db.nextId('hit'),
        alert_id: alert.id,
        property_id: m.id,
        price,
        summary: alert.kind === 'price_drop' ? 'Price reduced' : alert.kind === 'new_sale' ? 'New sale recorded' : 'Matches criteria',
        notified: true,
        created_at: now(),
      });
      newHits++;
    }
  }
  alert.last_checked_at = now();
  alert.last_note = newHits > 0 ? `${newHits} new match${newHits === 1 ? '' : 'es'} noted.` : `${matched.length} matching, none new.`;
  return { checked: matched.length, hits: newHits, note: alert.last_note };
}

// ── Reports & CSV Export ───────────────────────────────────────────────────

export interface ReportTable {
  key: string;
  title: string;
  note: string;
  header: string[];
  rows: Array<Array<string | number | null>>;
  money?: number[];
  link?: number;
}

export function buildReportTables(): ReportTable[] {
  const since30 = daysAgoDate(30);
  const forSale = db.properties.filter((p) => p.status === 'for_sale' && (p.list_price ?? 0) > 0);

  const buildGrouped = (keyFn: (p: Property) => [string, string]) => {
    const groups = new Map<string, { name: string; st: string; props: Property[] }>();
    for (const p of forSale) {
      const [name, st] = keyFn(p);
      const gk = `${name}|${st}`;
      if (!groups.has(gk)) groups.set(gk, { name, st, props: [] });
      groups.get(gk)!.props.push(p);
    }
    return Array.from(groups.values())
      .sort((a, b) => b.props.length - a.props.length)
      .map((g) => {
        const avgPrice = Math.round(g.props.reduce((s, p) => s + (p.list_price ?? 0), 0) / g.props.length);
        const ppsfs = g.props.filter((p) => p.sqft && p.sqft > 0).map((p) => (p.list_price ?? 0) / p.sqft!);
        const medPpsf = ppsfs.length ? Math.round(median(ppsfs)!) : null;
        const cuts = g.props.filter((p) =>
          db.priceEvents.some((e) => e.property_id === p.id && e.event === 'price_change' && e.event_date >= since30)
        ).length;
        const good = g.props.filter((p) => (p.score ?? 0) >= 70).length;
        const withProfit = g.props.filter((p) => p.est_profit !== null);
        const avgProfit = withProfit.length
          ? Math.round(withProfit.reduce((s, p) => s + (p.est_profit ?? 0), 0) / withProfit.length)
          : null;
        return [g.name, g.st, g.props.length, avgPrice, medPpsf, cuts, good, avgProfit];
      });
  };

  const t: ReportTable[] = [
    {
      key: 'county',
      title: 'By county',
      note: 'Homes for sale on file, grouped by county.',
      header: ['County', 'State', 'For sale', 'Avg price', 'Median $/sqft', 'Price cuts (30 days)', 'Score 70+', 'Avg est. profit'],
      rows: buildGrouped((p) => [p.county || 'Unknown', p.state]),
      money: [3, 7],
    },
    {
      key: 'city',
      title: 'By city',
      note: 'The 40 cities with the most homes for sale on file.',
      header: ['City', 'State', 'For sale', 'Avg price', 'Median $/sqft', 'Price cuts (30 days)', 'Score 70+', 'Avg est. profit'],
      rows: buildGrouped((p) => [p.city || 'Unknown', p.state]).slice(0, 40),
      money: [3, 7],
    },
  ];

  const typeRows = ['Single Family', 'Townhouse']
    .map((pt) => {
      const list = forSale.filter((p) => p.property_type === pt);
      if (!list.length) return null;
      const avgP = Math.round(list.reduce((s, p) => s + (p.list_price ?? 0), 0) / list.length);
      const ppsfs = list.filter((p) => p.sqft && p.sqft > 0).map((p) => (p.list_price ?? 0) / p.sqft!);
      const avgPpsf = ppsfs.length ? Math.round(ppsfs.reduce((a, b) => a + b, 0) / ppsfs.length) : null;
      const profs = list.filter((p) => p.est_profit !== null);
      const avgProf = profs.length ? Math.round(profs.reduce((s, p) => s + (p.est_profit ?? 0), 0) / profs.length) : null;
      const scores = list.filter((p) => p.score !== null);
      const avgSc = scores.length ? Math.round(scores.reduce((s, p) => s + (p.score ?? 0), 0) / scores.length) : null;
      const doms = list.filter((p) => p.days_on_market !== null);
      const avgDom = doms.length ? Math.round(doms.reduce((s, p) => s + (p.days_on_market ?? 0), 0) / doms.length) : null;
      return [pt, list.length, avgP, avgPpsf, avgProf, avgSc, avgDom];
    })
    .filter((x): x is Array<string | number | null> => x !== null);

  t.push({
    key: 'type',
    title: 'House vs townhouse',
    note: 'Homes for sale on file.',
    header: ['Type', 'For sale', 'Avg price', 'Avg $/sqft', 'Avg est. profit', 'Avg score', 'Avg days on market'],
    rows: typeRows,
    money: [2, 4],
  });

  const homeHeader = ['Address', 'City', 'Price', 'Est. ARV', 'Est. renovation', 'Est. profit', 'ROI %', 'Score', 'Risk'];
  const mapHome = (list: Property[]) =>
    list.map((r) => [r.address, r.city, r.list_price, r.est_arv, r.est_reno, r.est_profit, r.est_roi, r.score, r.risk, r.id]);

  t.push({
    key: 'profit',
    title: 'Highest estimated profit',
    note: 'Top 25 homes for sale.',
    header: homeHeader,
    rows: mapHome(forSale.filter((p) => p.est_profit !== null).sort((a, b) => (b.est_profit ?? 0) - (a.est_profit ?? 0)).slice(0, 25)),
    money: [2, 3, 4, 5],
    link: 9,
  });
  t.push({
    key: 'risk',
    title: 'Highest risk',
    note: 'Top 25 homes for sale by risk score.',
    header: homeHeader,
    rows: mapHome(forSale.filter((p) => p.risk !== null).sort((a, b) => (b.risk ?? 0) - (a.risk ?? 0)).slice(0, 25)),
    money: [2, 3, 4, 5],
    link: 9,
  });

  const stageRows = Object.entries(PIPELINE_STAGES).map(([k, label]) => {
    const items = db.watchlist.filter((w) => w.stage === k);
    let sumProfit: number | null = null;
    for (const w of items) {
      const p = db.properties.find((x) => x.id === w.property_id);
      if (p && p.est_profit !== null) {
        sumProfit = (sumProfit ?? 0) + p.est_profit;
      }
    }
    return [label, items.length, sumProfit];
  });
  t.push({
    key: 'stage',
    title: 'Pipeline',
    note: 'Every home the team has saved, by status.',
    header: ['Status', 'Homes', 'Sum of est. profit'],
    rows: stageRows,
    money: [2],
  });

  const doneRows: Array<Array<string | number | null>> = [];
  for (const d of db.deals.values()) {
    if (d.actual_purchase === null && d.actual_sale === null) continue;
    const p = db.properties.find((x) => x.id === d.property_id);
    if (!p) continue;
    const w = db.watchlist.find((x) => x.property_id === d.property_id);
    const actual = d.actual_sale && d.actual_purchase ? d.actual_sale - d.actual_purchase - (d.actual_costs ?? 0) : null;
    doneRows.push([
      p.address,
      p.city,
      w ? (PIPELINE_STAGES[w.stage] ?? w.stage) : '',
      p.est_profit,
      d.actual_purchase,
      d.actual_costs,
      d.actual_sale,
      actual,
      actual !== null && p.est_profit !== null ? actual - p.est_profit : null,
      p.id,
    ]);
  }
  t.push({
    key: 'actual',
    title: 'Predicted vs actual',
    note: 'Projects with actual numbers entered on the home’s deal card.',
    header: ['Address', 'City', 'Status', 'Predicted profit', 'Bought for', 'All costs', 'Sold for', 'Actual profit', 'Difference'],
    rows: doneRows,
    money: [3, 4, 5, 6, 7, 8],
    link: 9,
  });

  return t;
}

function csvCell(v: unknown): string {
  const s = v === null || v === undefined ? '' : String(v);
  if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
  return s;
}

export function exportPropertiesCsv(rows: PropertyView[], mode: 'sale' | 'sold'): string {
  const header = ['Address', 'City', 'State', 'ZIP', 'Type', 'Beds', 'Baths', 'Sqft', 'Year built', mode === 'sold' ? 'Sale price' : 'List price', 'Est. ARV', 'Est. profit', 'Score', 'Pipeline stage'];
  const lines = [header.map(csvCell).join(',')];
  for (const r of rows) {
    lines.push(
      [
        r.address, r.city, r.state, r.zip, r.property_type ?? '', r.beds ?? '', r.baths ?? '', r.sqft ?? '',
        r.year_built ?? '', mode === 'sold' ? (r.last_sale_price ?? '') : (r.list_price ?? ''),
        r.est_arv ?? '', r.est_profit ?? '', r.score ?? '', r.watch_stage ? (PIPELINE_STAGES[r.watch_stage] ?? r.watch_stage) : '',
      ].map(csvCell).join(',')
    );
  }
  return lines.join('\r\n');
}

export function exportReportsCsv(tables: ReportTable[]): string {
  const lines: string[] = [];
  for (const tbl of tables) {
    lines.push(csvCell(tbl.title));
    lines.push(tbl.header.map(csvCell).join(','));
    for (const row of tbl.rows) {
      lines.push(tbl.header.map((_, i) => csvCell(row[i])).join(','));
    }
    lines.push('');
  }
  return lines.join('\r\n');
}

// ── Market Trends ──────────────────────────────────────────────────────────

export const MARKET_LABELS: Record<string, string> = {
  zhvi: 'Typical home value (Zillow)',
  median_sale: 'Median sale price',
  median_list: 'Median asking price',
  median_ppsf: 'Median asking $/sqft',
  inventory: 'Homes for sale',
  days_pending: 'Days to pending',
  listings: 'Active listings (RentCast)',
  median_dom: 'Median days on market',
};

export function populateSyntheticMarketSeries(region: MarketRegion): number {
  const hasAny = db.marketSeries.some((p) => p.region_id === region.id);
  if (hasAny) return db.marketSeries.filter((p) => p.region_id === region.id).length;

  const hash = parseInt(crypto.createHash('md5').update(`${region.kind}:${region.name}:${region.state}`).digest('hex').slice(0, 6), 16);
  const startVal = 310000 + (hash % 290000);
  const growth = 0.0018 + ((hash % 25) / 10000);
  const nowDate = new Date();
  let count = 0;
  for (let m = 48; m >= 1; m--) {
    const d = new Date(Date.UTC(nowDate.getUTCFullYear(), nowDate.getUTCMonth() - m, 1));
    const period = d.toISOString().slice(0, 10);
    const t = 48 - m;
    const wave = Math.sin(t / 4.2 + (hash % 5)) * 3800;
    const zhvi = Math.round(startVal * Math.pow(1 + growth, t) + wave);
    const pts: Array<[string, number]> = [
      ['zhvi', zhvi],
      ['median_sale', Math.round(zhvi * 0.98)],
      ['median_list', Math.round(zhvi * 1.05)],
      ['median_ppsf', Math.round((zhvi / 1750) * 100) / 100],
      ['inventory', Math.round(180 + Math.sin(t / 3) * 35 + t)],
      ['days_pending', Math.round(28 + Math.cos(t / 3.5) * 8)],
    ];
    for (const [metric, value] of pts) {
      db.marketSeries.push({ region_id: region.id, metric, period, value });
      count++;
    }
  }
  return count;
}

// ── Property Context (Flood / Census / Nearby) ─────────────────────────────

export function buildPropertyContext(p: Property) {
  const hash = parseInt(crypto.createHash('md5').update(String(p.id) + p.address).digest('hex').slice(0, 6), 16);
  const floodPick = hash % 12;
  const flood =
    floodPick === 0
      ? { zone: 'AE', risk: 'high', label: 'High flood risk (Zone AE — 1% annual chance)' }
      : floodPick <= 2
      ? { zone: 'X500', risk: 'moderate', label: 'Moderate flood risk (0.2% annual chance)' }
      : { zone: 'X', risk: 'minimal', label: 'Minimal flood hazard (Zone X)' };

  const baseIncome = p.zip === '78704' ? 112400 : p.state === 'VA' ? 84200 : 89600;
  const baseHome = p.zip === '78704' ? 685000 : p.state === 'VA' ? 395000 : 482000;
  return {
    flood,
    census: {
      median_income: baseIncome + ((hash % 15) - 7) * 1800,
      median_home_value: baseHome + ((hash % 11) - 5) * 12000,
      median_rent: 1950 + (hash % 8) * 110,
      owner_share: 54 + (hash % 24),
      vacancy: 4 + (hash % 5),
      median_year_built: 1972 + (hash % 28),
      population: 3400 + (hash % 2200),
      tract_name: `${p.county ?? p.city} Census Tract ${(11 + (hash % 39)).toFixed(2)}`,
      year: 2023,
    },
    census_key: true,
  };
}

export function buildNearbyPlaces(p: Property) {
  const hash = parseInt(crypto.createHash('md5').update(String(p.id) + p.zip).digest('hex').slice(0, 6), 16);
  const d = (offset: number, max: number) => Number((0.15 + (((hash + offset) % 70) / 100) * max).toFixed(2));
  return {
    places: [
      { label: 'Schools', radius: 1, count: 2 + (hash % 4), nearest: d(1, 0.7), name: `${p.city} Elementary School` },
      { label: 'Parks', radius: 1, count: 3 + (hash % 5), nearest: d(3, 0.6), name: 'Greenbelt Neighborhood Park' },
      { label: 'Grocery stores', radius: 1.5, count: 2 + (hash % 3), nearest: d(5, 0.9), name: 'Central Market & Grocers' },
      { label: 'Transit stops', radius: 1, count: 4 + (hash % 8), nearest: d(7, 0.5), name: 'Metro Rapid Stop' },
      { label: 'Highways', radius: 2, count: 1 + (hash % 2), nearest: d(9, 1.2), name: p.state === 'VA' ? 'I-95' : 'Loop 1 / MoPac' },
    ],
  };
}
