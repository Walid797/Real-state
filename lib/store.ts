import crypto from 'node:crypto';
import {
  addressHash,
  fullAddress,
  normalizeStreet,
  titleCity,
  geoValid,
} from './address.js';
import { detectCondition } from './condition.js';
import { daysAgoDate, daysAgoTimestamp, now, parseNum, today } from './helpers.js';

export type UserRole = 'admin' | 'partner' | 'viewer';

export const ROLES: Record<UserRole, string> = {
  admin: 'Admin',
  partner: 'Partner',
  viewer: 'Viewer',
};

export interface User {
  id: number;
  email: string;
  name: string;
  role: UserRole;
  passwordHash: string;
  googleSub: string | null;
  telegramChat: string | null;
  whatsappPhone: string | null;
  callmebotKey: string | null;
  isActive: boolean;
  sessionEpoch: number;
  lastLoginAt: string | null;
  createdAt: string;
}

export interface Property {
  id: number;
  addr_hash: string;
  address: string;
  city: string;
  state: string;
  zip: string;
  county: string | null;
  lat: number | null;
  lng: number | null;
  property_type: string | null;
  beds: number | null;
  baths: number | null;
  sqft: number | null;
  lot_sqft: number | null;
  year_built: number | null;
  hoa_fee: number | null;
  garage_spaces: number | null;
  has_basement: number | null;
  flags: string;
  status: 'for_sale' | 'pending' | 'sold' | 'off_market';
  list_price: number | null;
  listed_date: string | null;
  days_on_market: number | null;
  listing_type: string | null;
  last_sale_price: number | null;
  last_sale_date: string | null;
  est_value: number | null;
  est_value_low: number | null;
  est_value_high: number | null;
  est_rent: number | null;
  est_source: string | null;
  est_at: string | null;
  tax_annual: number | null;
  assessed_value: number | null;
  mls_name: string | null;
  mls_number: string | null;
  agent_name: string | null;
  agent_phone: string | null;
  agent_email: string | null;
  office_name: string | null;
  photo_url: string | null;
  description: string | null;
  source: string;
  source_id: string | null;
  source_url: string | null;
  source_site: string | null;
  data_status: 'full' | 'limited' | 'link_only' | 'link_dead' | 'removed';
  details: Record<string, any>;
  score: number | null;
  risk: number | null;
  confidence: number | null;
  est_arv: number | null;
  est_reno: number | null;
  est_profit: number | null;
  est_roi: number | null;
  scored_at: string | null;
  first_seen_at: string;
  last_seen_at: string;
  updated_at: string;
}

export interface PriceEvent {
  id: number;
  property_id: number;
  event: 'listed' | 'price_change' | 'sold' | 'removed' | 'relisted';
  price: number;
  event_date: string;
  source: string;
  created_at: string;
}

export interface WatchlistItem {
  id: number;
  property_id: number;
  stage: string;
  owner_id: number | null;
  added_by: number | null;
  updated_by: number | null;
  source_url: string | null;
  created_at: string;
  updated_at: string;
}

export interface Note {
  id: number;
  property_id: number;
  user_id: number | null;
  body: string;
  created_at: string;
}

export interface ActivityItem {
  id: number;
  user_id: number | null;
  action: string;
  property_id: number | null;
  detail: string;
  created_at: string;
}

export interface PropertyCondition {
  property_id: number;
  overall: string;
  kitchen: string;
  bathroom: string;
  roof: string;
  hvac: string;
  flooring: string;
  electrical: string;
  plumbing: string;
  windows: string;
  renovated_year: number | null;
  evidence: string[];
  source: 'auto' | 'manual';
  updated_at: string;
}

export interface DealInputs {
  property_id: number;
  purchase_price: number | null;
  closing_pct: number | null;
  reno_cost: number | null;
  holding_months: number | null;
  holding_monthly: number | null;
  loan_ltv_pct: number | null;
  loan_rate_pct: number | null;
  loan_points_pct: number | null;
  selling_pct: number | null;
  arv: number | null;
  contingency_pct: number | null;
  actual_purchase: number | null;
  actual_costs: number | null;
  actual_sale: number | null;
  updated_by: number | null;
  updated_at: string;
}

export interface SavedSearch {
  id: number;
  user_id: number;
  name: string;
  params: Record<string, any>;
  last_run_at: string | null;
  created_at: string;
}

export interface Alert {
  id: number;
  user_id: number;
  name: string;
  kind: 'new_listing' | 'price_drop' | 'new_sale';
  location: string;
  criteria: Record<string, any>;
  every_hours: number;
  channels: string[];
  delivery: 'instant' | 'daily';
  use_quota: boolean;
  enabled: boolean;
  last_checked_at: string | null;
  last_note: string | null;
  created_at: string;
}

export interface AlertHit {
  id: number;
  alert_id: number;
  property_id: number;
  price: number;
  summary: string;
  notified: boolean;
  created_at: string;
}

export interface MarketRegion {
  id: number;
  kind: 'zip' | 'city';
  name: string;
  state: string;
  added_at: string;
}

export interface MarketPoint {
  region_id: number;
  metric: string;
  period: string;
  value: number;
}

export interface ExternalSite {
  id: number;
  name: string;
  category: string;
  property_url: string | null;
  area_url: string | null;
  sort_order: number;
  enabled: boolean;
}

export interface Snapshot {
  id: number;
  provider: string;
  mode: 'sale' | 'sold';
  loc_key: string;
  filter_sig: string;
  filter_json: string;
  result_count: number;
  truncated: number;
  fetched_at: string;
}

export interface SessionData {
  id: string;
  userId: number | null;
  epoch: number;
  csrf: string;
  flash: { msg: string; type: 'ok' | 'warn' | 'err' } | null;
  inviteLink: { email: string; link: string; mailed: boolean } | null;
  createdAt: number;
}

export const PIPELINE_STAGES: Record<string, string> = {
  new: 'New',
  under_review: 'Under review',
  contact_agent: 'Contact agent',
  inspection: 'Inspection needed',
  offer: 'Offer submitted',
  rejected: 'Rejected',
  purchased: 'Purchased',
  sold: 'Sold',
};

export const PIPELINE_ACTIVE = ['under_review', 'contact_agent', 'inspection', 'offer'];

export const PROPERTY_TYPES = [
  'Single Family',
  'Condo',
  'Townhouse',
  'Multi-Family',
  'Manufactured',
  'Apartment',
  'Land',
];

export const PROPERTY_SORTS: Record<string, string> = {
  newest: 'Newest',
  price_asc: 'Price, low to high',
  price_desc: 'Price, high to low',
  ppsf_asc: '$/sqft, low to high',
  sqft_desc: 'Largest',
  score: 'Best opportunity',
  profit: 'Highest est. profit',
  dom_desc: 'Longest on market',
};

export const LISTING_FILTER_LABELS: Record<string, string> = {
  new: 'New listings (7 days)',
  reduced: 'Price reduced',
  foreclosure: 'Foreclosure / short sale',
  auction: 'Auction',
  fixer: 'Fixer-upper',
  basement: 'Has a basement',
};

export const ACTIVITY_LABELS: Record<string, string> = {
  stage: 'Status',
  claim: 'Handled by',
  deal: 'Deal calculator',
  condition: 'Condition',
  note: 'Note',
  added: 'Added',
  edit: 'Edited',
  refresh: 'Updated',
  user: 'Team',
};

export const DEFAULT_EXTERNAL_SITES: Array<[string, string, string | null, string | null]> = [
  ['Zillow', 'listings', 'https://www.zillow.com/homes/{address_slug}_rb/', 'https://www.zillow.com/homes/{area_slug}_rb/'],
  ['Zillow — recently sold', 'listings', null, 'https://www.zillow.com/homes/recently_sold/{area_slug}_rb/'],
  ['Redfin', 'listings', 'https://www.google.com/search?q={address_full}+site%3Aredfin.com', 'https://www.redfin.com/zipcode/{zip}'],
  ['Realtor.com', 'listings', 'https://www.google.com/search?q={address_full}+site%3Arealtor.com', 'https://www.realtor.com/realestateandhomes-search/{area_under}'],
  ['Trulia', 'listings', 'https://www.google.com/search?q={address_full}+site%3Atrulia.com', 'https://www.trulia.com/{state}/{city_under}/'],
  ['Homes.com', 'listings', 'https://www.google.com/search?q={address_full}+site%3Ahomes.com', 'https://www.homes.com/{city_slug}-{state_lower}/'],
  ['Movoto', 'listings', 'https://www.google.com/search?q={address_full}+site%3Amovoto.com', 'https://www.movoto.com/{city_slug}-{state_lower}/'],
  ['County assessor', 'records', 'https://www.google.com/search?q={county}+County+{state}+property+assessor+{address}', 'https://www.google.com/search?q={county}+County+{state}+property+assessor+search'],
  ['Google Maps', 'maps', 'https://www.google.com/maps/search/?api=1&query={address_full}', 'https://www.google.com/maps/search/?api=1&query={area}'],
  ['Street View', 'maps', 'https://www.google.com/maps/@?api=1&map_action=pano&viewpoint={lat},{lng}', null],
];

// ── In-Memory Database Store ───────────────────────────────────────────────

class Store {
  users: User[] = [];
  properties: Property[] = [];
  priceEvents: PriceEvent[] = [];
  watchlist: WatchlistItem[] = [];
  notes: Note[] = [];
  activityLog: ActivityItem[] = [];
  conditions = new Map<number, PropertyCondition>();
  deals = new Map<number, DealInputs>();
  savedSearches: SavedSearch[] = [];
  alerts: Alert[] = [];
  alertHits: AlertHit[] = [];
  marketRegions: MarketRegion[] = [];
  marketSeries: MarketPoint[] = [];
  externalSites: ExternalSite[] = [];
  snapshots: Snapshot[] = [];
  settings = new Map<string, string>();
  sessions = new Map<string, SessionData>();

  private seq = {
    user: 1,
    property: 1,
    event: 1,
    watch: 1,
    note: 1,
    activity: 1,
    saved: 1,
    alert: 1,
    hit: 1,
    region: 1,
    site: 1,
    snapshot: 1,
  };

  nextId(k: keyof Store['seq']): number {
    return this.seq[k]++;
  }

  seeded = false;
}

export const db = new Store();

// ── Password Hashing & Verification ────────────────────────────────────────

export function hashPassword(password: string): string {
  const salt = crypto.randomBytes(16).toString('hex');
  const derived = crypto.scryptSync(password, salt, 64).toString('hex');
  return `${salt}:${derived}`;
}

export function verifyPassword(password: string, stored: string): boolean {
  if (!stored || !stored.includes(':')) return false;
  const [salt, key] = stored.split(':', 2);
  if (!salt || !key) return false;
  const derived = crypto.scryptSync(password, salt, 64);
  const keyBuf = Buffer.from(key, 'hex');
  if (derived.length !== keyBuf.length) return false;
  return crypto.timingSafeEqual(derived, keyBuf);
}

export function passwordProblems(password: string): string[] {
  const problems: string[] = [];
  if (password.length < 12) problems.push('Use at least 12 characters.');
  if (password.length > 200) problems.push('Maximum 200 characters.');
  return problems;
}

export function can(user: User | null, perm: 'view' | 'edit' | 'admin'): boolean {
  if (!user || !user.isActive) return false;
  if (user.role === 'admin') return true;
  if (user.role === 'partner') return perm === 'view' || perm === 'edit';
  return perm === 'view';
}

export function getOrCreateSession(sid?: string): SessionData {
  if (sid && db.sessions.has(sid)) {
    return db.sessions.get(sid)!;
  }
  const id = crypto.randomBytes(24).toString('hex');
  const session: SessionData = {
    id,
    userId: null,
    epoch: 0,
    csrf: crypto.randomBytes(24).toString('hex'),
    flash: null,
    inviteLink: null,
    createdAt: Date.now(),
  };
  db.sessions.set(id, session);
  return session;
}

export function findSessionUser(session: SessionData): User | null {
  if (!session.userId) return null;
  const u = db.users.find((x) => x.id === session.userId);
  if (!u || !u.isActive || u.sessionEpoch !== session.epoch) {
    session.userId = null;
    return null;
  }
  return u;
}

export function signInSession(session: SessionData, user: User): void {
  session.userId = user.id;
  session.epoch = user.sessionEpoch;
  user.lastLoginAt = now();
}

export function signOutEverywhere(userId: number): void {
  const u = db.users.find((x) => x.id === userId);
  if (u) u.sessionEpoch += 1;
}

// ── Settings ───────────────────────────────────────────────────────────────

export function getSetting(key: string, def: string | null = null): string | null {
  return db.settings.has(key) ? db.settings.get(key)! : def;
}

export function setSetting(key: string, value: string | null): void {
  if (value === null || value === '') {
    db.settings.delete(key);
  } else {
    db.settings.set(key, value);
  }
}

export function hasSetting(key: string): boolean {
  const v = db.settings.get(key);
  return v !== undefined && v !== '';
}

export function maskedSetting(key: string): string {
  const v = db.settings.get(key) ?? '';
  if (v.length <= 6) return '••••••';
  return '••••••' + v.slice(-4);
}

export function intSetting(key: string, def: number): number {
  const v = getSetting(key);
  if (v === null) return def;
  const n = parseInt(v, 10);
  return Number.isFinite(n) ? n : def;
}

// ── Activity & Notes ───────────────────────────────────────────────────────

export function logActivity(userId: number | null, action: string, propertyId: number | null, detail: string): void {
  db.activityLog.push({
    id: db.nextId('activity'),
    user_id: userId,
    action,
    property_id: propertyId,
    detail: detail.slice(0, 255),
    created_at: now(),
  });
}

export function activityWho(item: ActivityItem): string {
  if (!item.user_id) return 'System';
  const u = db.users.find((x) => x.id === item.user_id);
  return u ? u.name || u.email : 'Former member';
}

export function recentActivity(limit = 10) {
  return [...db.activityLog]
    .sort((a, b) => b.id - a.id)
    .slice(0, limit)
    .map((a) => {
      const p = a.property_id ? db.properties.find((x) => x.id === a.property_id) : null;
      return {
        ...a,
        who: activityWho(a),
        address: p?.address ?? null,
        city: p?.city ?? null,
      };
    });
}

export function propertyActivity(propertyId: number, limit = 30) {
  return db.activityLog
    .filter((a) => a.property_id === propertyId)
    .sort((a, b) => b.id - a.id)
    .slice(0, limit)
    .map((a) => ({
      ...a,
      who: activityWho(a),
    }));
}

export function getPropertyNotes(propertyId: number) {
  return db.notes
    .filter((n) => n.property_id === propertyId)
    .sort((a, b) => b.id - a.id)
    .map((n) => {
      const u = n.user_id ? db.users.find((x) => x.id === n.user_id) : null;
      return {
        ...n,
        author: u ? u.name || u.email : 'Former member',
      };
    });
}

export function addNote(propertyId: number, userId: number | null, body: string): void {
  const clean = body.trim().slice(0, 10000);
  if (!clean) return;
  db.notes.push({
    id: db.nextId('note'),
    property_id: propertyId,
    user_id: userId,
    body: clean,
    created_at: now(),
  });
  logActivity(userId, 'note', propertyId, 'Added a note');
}

export function deleteNote(noteId: number, propertyId: number, actor: User): boolean {
  const idx = db.notes.findIndex((n) => n.id === noteId && n.property_id === propertyId);
  if (idx < 0) return false;
  const note = db.notes[idx];
  if (note.user_id !== actor.id && !can(actor, 'admin')) return false;
  db.notes.splice(idx, 1);
  return true;
}

// ── Condition ──────────────────────────────────────────────────────────────

export function defaultCondition(propertyId: number): PropertyCondition {
  return {
    property_id: propertyId,
    overall: 'not_disclosed',
    kitchen: 'unknown',
    bathroom: 'unknown',
    roof: 'unknown',
    hvac: 'unknown',
    flooring: 'unknown',
    electrical: 'unknown',
    plumbing: 'unknown',
    windows: 'unknown',
    renovated_year: null,
    evidence: [],
    source: 'auto',
    updated_at: now(),
  };
}

export function getCondition(propertyId: number): PropertyCondition {
  return db.conditions.get(propertyId) ?? defaultCondition(propertyId);
}

export function refreshCondition(p: Property): PropertyCondition {
  const prev = db.conditions.get(p.id);
  const det = detectCondition(p.description ?? '', p.listing_type);

  const mergedFlags = new Set<string>();
  for (const f of p.flags.split(',').filter(Boolean)) mergedFlags.add(f);
  for (const f of det.flags) mergedFlags.add(f);
  p.flags = Array.from(mergedFlags).join(',');
  if (det.basement !== null && p.has_basement === null) p.has_basement = det.basement ? 1 : 0;
  if (det.garage !== null && p.garage_spaces === null) p.garage_spaces = det.garage;

  if (prev?.source === 'manual') {
    return prev;
  }

  const cond: PropertyCondition = {
    property_id: p.id,
    overall: det.overall,
    kitchen: det.parts.kitchen ?? 'unknown',
    bathroom: det.parts.bathroom ?? 'unknown',
    roof: det.parts.roof ?? 'unknown',
    hvac: det.parts.hvac ?? 'unknown',
    flooring: det.parts.flooring ?? 'unknown',
    electrical: det.parts.electrical ?? 'unknown',
    plumbing: det.parts.plumbing ?? 'unknown',
    windows: det.parts.windows ?? 'unknown',
    renovated_year: det.renovatedYear,
    evidence: det.evidence,
    source: 'auto',
    updated_at: now(),
  };
  db.conditions.set(p.id, cond);
  return cond;
}

// ── Pipeline / Watchlist ───────────────────────────────────────────────────

export function addToPipeline(propertyId: number, userId: number | null, sourceUrl: string | null = null): void {
  const existing = db.watchlist.find((w) => w.property_id === propertyId);
  if (existing) {
    if (sourceUrl && !existing.source_url) existing.source_url = sourceUrl;
    return;
  }
  const ts = now();
  db.watchlist.push({
    id: db.nextId('watch'),
    property_id: propertyId,
    stage: 'new',
    owner_id: null,
    added_by: userId,
    updated_by: userId,
    source_url: sourceUrl,
    created_at: ts,
    updated_at: ts,
  });
  logActivity(userId, 'stage', propertyId, 'Added to the pipeline');
}

export function removeFromPipeline(propertyId: number, userId: number | null): void {
  const idx = db.watchlist.findIndex((w) => w.property_id === propertyId);
  if (idx >= 0) {
    db.watchlist.splice(idx, 1);
    logActivity(userId, 'stage', propertyId, 'Removed from the pipeline');
  }
}

export function setPipelineStage(propertyId: number, stage: string, userId: number | null): void {
  if (!PIPELINE_STAGES[stage]) return;
  addToPipeline(propertyId, userId);
  const item = db.watchlist.find((w) => w.property_id === propertyId);
  if (!item || item.stage === stage) return;
  const before = item.stage;
  item.stage = stage;
  item.updated_by = userId;
  item.updated_at = now();
  if (!item.owner_id && PIPELINE_ACTIVE.includes(stage) && userId) {
    item.owner_id = userId;
  }
  logActivity(userId, 'stage', propertyId, `${PIPELINE_STAGES[before] ?? 'New'} → ${PIPELINE_STAGES[stage]}`);
}

export function setPipelineOwner(propertyId: number, ownerId: number | null, actorId: number | null): void {
  addToPipeline(propertyId, actorId);
  const item = db.watchlist.find((w) => w.property_id === propertyId);
  if (!item) return;
  item.owner_id = ownerId;
  item.updated_by = actorId;
  item.updated_at = now();
  const u = ownerId ? db.users.find((x) => x.id === ownerId) : null;
  logActivity(actorId, 'claim', propertyId, u ? `Handled by ${u.name || u.email}` : 'No one assigned');
}

// ── Property Repo ──────────────────────────────────────────────────────────

export function normalizeDate(v: unknown): string | null {
  if (v === null || v === undefined || v === '') return null;
  if (typeof v === 'number' && Number.isFinite(v)) {
    const ms = v > 20_000_000_000 ? v : v * 1000;
    return ms > 0 ? new Date(ms).toISOString().slice(0, 10) : null;
  }
  const s = String(v).trim();
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (m) return `${m[1]}-${m[2]}-${m[3]}`;
  const d = new Date(s);
  return Number.isNaN(d.getTime()) ? null : d.toISOString().slice(0, 10);
}

export function addPriceEvent(propertyId: number, event: PriceEvent['event'], price: number, dateStr: string, source: string): void {
  const d = normalizeDate(dateStr);
  if (!d) return;
  const p = Math.max(0, Math.round(price));
  const dup = db.priceEvents.some(
    (e) => e.property_id === propertyId && e.event === event && e.event_date === d && e.price === p
  );
  if (dup) return;
  db.priceEvents.push({
    id: db.nextId('event'),
    property_id: propertyId,
    event,
    price: p,
    event_date: d,
    source,
    created_at: now(),
  });
}

const DATA_RANK: Record<string, number> = { link_only: 1, limited: 2, full: 3 };

export function upsertProperty(input: Record<string, any>, source: string): { id: number; created: boolean; old_price: number | null; new_price: number | null } {
  const address = String(input.address ?? '').trim();
  const city = titleCity(String(input.city ?? ''));
  const state = String(input.state ?? '').trim().toUpperCase().slice(0, 2);
  const zip = String(input.zip ?? '').replace(/\D/g, '').slice(0, 5);
  if (!address || (!zip && (!city || !state))) {
    throw new Error('A property needs a street address and a ZIP (or city and state).');
  }
  const hash = addressHash(address, city, state, zip);
  const ts = now();

  const existing = db.properties.find((p) => p.addr_hash === hash);
  const numOrNull = (v: unknown) => {
    const n = Number(v);
    return v !== null && v !== undefined && v !== '' && Number.isFinite(n) && n > 0 ? Math.round(n) : null;
  };
  const floatOrNull = (v: unknown) => {
    const n = Number(v);
    return v !== null && v !== undefined && v !== '' && Number.isFinite(n) ? Number(n.toFixed(6)) : null;
  };

  let incomingStatus: Property['data_status'] | null = null;
  if (input.data_status && DATA_RANK[input.data_status]) {
    incomingStatus = input.data_status;
  } else if (source !== 'manual') {
    incomingStatus = 'limited';
  }

  let oldPriceRet: number | null = null;
  let newPriceRet: number | null = null;

  if (!existing) {
    const id = db.nextId('property');
    const listPrice = numOrNull(input.list_price);
    const status = (['for_sale', 'pending', 'sold', 'off_market'].includes(input.status) ? input.status : 'off_market') as Property['status'];
    const p: Property = {
      id,
      addr_hash: hash,
      address: address.slice(0, 190),
      city: city.slice(0, 100),
      state,
      zip,
      county: input.county ? String(input.county).slice(0, 100) : null,
      lat: floatOrNull(input.lat),
      lng: floatOrNull(input.lng),
      property_type: input.property_type ? String(input.property_type) : null,
      beds: input.beds !== undefined && input.beds !== null && input.beds !== '' ? Number(Number(input.beds).toFixed(1)) : null,
      baths: input.baths !== undefined && input.baths !== null && input.baths !== '' ? Number(Number(input.baths).toFixed(1)) : null,
      sqft: numOrNull(input.sqft),
      lot_sqft: numOrNull(input.lot_sqft),
      year_built: input.year_built && Number(input.year_built) >= 1600 ? Math.round(Number(input.year_built)) : null,
      hoa_fee: input.hoa_fee !== undefined && input.hoa_fee !== null && input.hoa_fee !== '' && Number(input.hoa_fee) >= 0 ? Number(Number(input.hoa_fee).toFixed(2)) : null,
      garage_spaces: input.garage_spaces !== undefined && input.garage_spaces !== null ? Math.round(Number(input.garage_spaces)) : null,
      has_basement: input.has_basement === null || input.has_basement === undefined ? null : input.has_basement ? 1 : 0,
      flags: String(input.flags ?? ''),
      status,
      list_price: listPrice,
      listed_date: normalizeDate(input.listed_date),
      days_on_market: input.days_on_market !== undefined && input.days_on_market !== null ? Math.round(Number(input.days_on_market)) : null,
      listing_type: input.listing_type ? String(input.listing_type) : null,
      last_sale_price: numOrNull(input.last_sale_price),
      last_sale_date: normalizeDate(input.last_sale_date),
      est_value: numOrNull(input.est_value),
      est_value_low: numOrNull(input.est_value_low),
      est_value_high: numOrNull(input.est_value_high),
      est_rent: numOrNull(input.est_rent),
      est_source: input.est_source ? String(input.est_source) : null,
      est_at: input.est_at ? String(input.est_at) : null,
      tax_annual: numOrNull(input.tax_annual),
      assessed_value: numOrNull(input.assessed_value),
      mls_name: input.mls_name ? String(input.mls_name) : null,
      mls_number: input.mls_number ? String(input.mls_number) : null,
      agent_name: input.agent_name ? String(input.agent_name) : null,
      agent_phone: input.agent_phone ? String(input.agent_phone) : null,
      agent_email: input.agent_email ? String(input.agent_email) : null,
      office_name: input.office_name ? String(input.office_name) : null,
      photo_url: input.photo_url ? String(input.photo_url) : null,
      description: input.description ? String(input.description).trim() : null,
      source,
      source_id: input.source_id ? String(input.source_id) : null,
      source_url: input.source_url ? String(input.source_url) : null,
      source_site: input.source_site ? String(input.source_site) : null,
      data_status: incomingStatus ?? 'limited',
      details: typeof input.details === 'object' && input.details ? { ...input.details } : {},
      score: null,
      risk: null,
      confidence: null,
      est_arv: null,
      est_reno: null,
      est_profit: null,
      est_roi: null,
      scored_at: null,
      first_seen_at: ts,
      last_seen_at: ts,
      updated_at: ts,
    };
    db.properties.push(p);
    if (status === 'for_sale' && listPrice) {
      addPriceEvent(id, 'listed', listPrice, p.listed_date ?? today(), source);
    }
    if (p.last_sale_price && p.last_sale_date) {
      addPriceEvent(id, 'sold', p.last_sale_price, p.last_sale_date, source);
    }
    for (const ev of (input.events ?? []) as Array<any>) {
      if (ev?.date && ev?.event) addPriceEvent(id, ev.event, Number(ev.price ?? 0), String(ev.date), source);
    }
    return { id, created: true, old_price: null, new_price: null };
  }

  // Merge into existing property
  const wasForSale = existing.status === 'for_sale';
  const nextStatus = ['for_sale', 'pending', 'sold', 'off_market'].includes(input.status) ? input.status : existing.status;
  const isForSale = nextStatus === 'for_sale';
  const oldP = existing.list_price ?? 0;
  const newP = numOrNull(input.list_price) ?? oldP;

  if (isForSale && newP > 0) {
    if (!wasForSale) {
      addPriceEvent(existing.id, oldP > 0 ? 'relisted' : 'listed', newP, normalizeDate(input.listed_date) ?? today(), source);
    } else if (oldP > 0 && newP !== oldP) {
      addPriceEvent(existing.id, 'price_change', newP, today(), source);
      oldPriceRet = oldP;
      newPriceRet = newP;
    }
  } else if (wasForSale && input.status && input.status !== 'for_sale' && input.status !== 'pending') {
    addPriceEvent(existing.id, 'removed', oldP, today(), source);
  }

  if (incomingStatus && (DATA_RANK[existing.data_status] ?? 9) < DATA_RANK[incomingStatus]) {
    existing.data_status = incomingStatus;
  }

  if (city && !existing.city) existing.city = city;
  if (state && !existing.state) existing.state = state;
  if (zip && !existing.zip) existing.zip = zip;
  if (input.county) existing.county = String(input.county);
  if (floatOrNull(input.lat) !== null) existing.lat = floatOrNull(input.lat);
  if (floatOrNull(input.lng) !== null) existing.lng = floatOrNull(input.lng);
  if (input.property_type) existing.property_type = String(input.property_type);
  if (input.beds !== undefined && input.beds !== null && input.beds !== '') existing.beds = Number(Number(input.beds).toFixed(1));
  if (input.baths !== undefined && input.baths !== null && input.baths !== '') existing.baths = Number(Number(input.baths).toFixed(1));
  if (numOrNull(input.sqft)) existing.sqft = numOrNull(input.sqft);
  if (numOrNull(input.lot_sqft)) existing.lot_sqft = numOrNull(input.lot_sqft);
  if (input.year_built) existing.year_built = Math.round(Number(input.year_built));
  if (input.hoa_fee !== undefined && input.hoa_fee !== null && input.hoa_fee !== '') existing.hoa_fee = Number(Number(input.hoa_fee).toFixed(2));
  existing.status = nextStatus;
  if (numOrNull(input.list_price) !== null) existing.list_price = numOrNull(input.list_price);
  if (normalizeDate(input.listed_date)) existing.listed_date = normalizeDate(input.listed_date);
  if (numOrNull(input.last_sale_price)) existing.last_sale_price = numOrNull(input.last_sale_price);
  if (normalizeDate(input.last_sale_date)) existing.last_sale_date = normalizeDate(input.last_sale_date);
  if (input.description) existing.description = String(input.description).trim();
  if (input.source_url) existing.source_url = String(input.source_url);
  if (input.source_site) existing.source_site = String(input.source_site);
  if (typeof input.details === 'object' && input.details) {
    existing.details = { ...existing.details, ...input.details };
  }
  if (source !== 'manual' && existing.source === 'manual') existing.source = source;
  existing.last_seen_at = ts;
  existing.updated_at = ts;

  if (existing.last_sale_price && existing.last_sale_date) {
    addPriceEvent(existing.id, 'sold', existing.last_sale_price, existing.last_sale_date, source);
  }
  return { id: existing.id, created: false, old_price: oldPriceRet, new_price: newPriceRet };
}

export interface PropertyView extends Property {
  watch_id: number | null;
  watch_stage: string | null;
  watch_url: string | null;
  owner_id: number | null;
  stage_at: string | null;
  owner_name: string | null;
  stage_by: string | null;
  cond_overall: string;
}

export function hydrateProperty(p: Property): PropertyView {
  const w = db.watchlist.find((x) => x.property_id === p.id);
  const ou = w?.owner_id ? db.users.find((u) => u.id === w.owner_id) : null;
  const ub = w?.updated_by ? db.users.find((u) => u.id === w.updated_by) : null;
  const c = getCondition(p.id);
  return {
    ...p,
    watch_id: w?.id ?? null,
    watch_stage: w?.stage ?? null,
    watch_url: w?.source_url ?? null,
    owner_id: w?.owner_id ?? null,
    stage_at: w?.updated_at ?? null,
    owner_name: ou ? ou.name || ou.email : null,
    stage_by: ub ? ub.name || ub.email : null,
    cond_overall: c.overall,
  };
}

export function findProperty(id: number): PropertyView | null {
  const p = db.properties.find((x) => x.id === id);
  return p ? hydrateProperty(p) : null;
}

export function deleteProperty(id: number): boolean {
  const idx = db.properties.findIndex((p) => p.id === id);
  if (idx < 0) return false;
  db.properties.splice(idx, 1);
  db.priceEvents = db.priceEvents.filter((e) => e.property_id !== id);
  db.watchlist = db.watchlist.filter((w) => w.property_id !== id);
  db.notes = db.notes.filter((n) => n.property_id !== id);
  db.activityLog = db.activityLog.filter((a) => a.property_id !== id);
  db.conditions.delete(id);
  db.deals.delete(id);
  db.alertHits = db.alertHits.filter((h) => h.property_id !== id);
  return true;
}

export function getPropertyEvents(propertyId: number): PriceEvent[] {
  return db.priceEvents
    .filter((e) => e.property_id === propertyId)
    .sort((a, b) => (b.event_date !== a.event_date ? b.event_date.localeCompare(a.event_date) : b.id - a.id));
}

export function propertyDom(p: Pick<Property, 'listed_date' | 'days_on_market'>): number | null {
  if (p.listed_date) {
    const t = new Date(p.listed_date.slice(0, 10) + 'T00:00:00Z').getTime();
    if (!Number.isNaN(t)) return Math.max(0, Math.floor((Date.now() - t) / 86400_000));
  }
  return p.days_on_market ?? null;
}

export function propertyFacts(p: Pick<Property, 'beds' | 'baths' | 'sqft' | 'year_built'>): string {
  const bits: string[] = [];
  if (p.beds !== null && p.beds !== undefined) {
    bits.push(p.beds === 0 ? 'Studio' : `${String(p.beds).replace(/\.0$/, '')} bd`);
  }
  if (p.baths !== null && p.baths !== undefined) {
    bits.push(`${String(p.baths).replace(/\.0$/, '')} ba`);
  }
  if (p.sqft) bits.push(`${p.sqft.toLocaleString('en-US')} sqft`);
  if (p.year_built) bits.push(`built ${p.year_built}`);
  return bits.join(' · ');
}

export function formatLot(sqft: number | null | undefined): string {
  const n = Number(sqft ?? 0);
  if (!n || n <= 0) return '—';
  return n >= 21780 ? `${(n / 43560).toFixed(2).replace(/\.?0+$/, '')} acres` : `${Math.round(n).toLocaleString('en-US')} sqft`;
}

// ── Deterministic PRNG for Demo Seeding ────────────────────────────────────

function createRng(seed: number) {
  let s = seed >>> 0;
  return () => {
    s = (s * 1664525 + 1013904223) >>> 0;
    return s / 4294967296;
  };
}

export function seedDemoData(): void {
  if (db.seeded) return;
  db.seeded = true;

  // Settings
  db.settings.set('site_name', 'Plat');
  db.settings.set('default_state', 'TX');
  db.settings.set('rentcast_limit', '50');
  db.settings.set('rentcast_reserve', '15');
  db.settings.set('openwebninja_limit', '100');
  db.settings.set('openwebninja_reserve', '20');
  db.settings.set('openwebninja_auto', '60');
  db.settings.set('search_provider', 'auto');
  db.settings.set('snapshot_hours', '24');
  db.settings.set('job_last_run', daysAgoTimestamp(0));

  // External Sites
  DEFAULT_EXTERNAL_SITES.forEach(([name, category, property_url, area_url], idx) => {
    db.externalSites.push({
      id: db.nextId('site'),
      name,
      category,
      property_url,
      area_url,
      sort_order: (idx + 1) * 10,
      enabled: true,
    });
  });

  // Demo Users (password: "correct horse battery staple")
  const pwHash = hashPassword('correct horse battery staple');
  const demoUsers: Array<[string, string, UserRole]> = [
    ['admin@plat.local', 'Alex Rivera', 'admin'],
    ['partner@example.com', 'Ajmal Test', 'partner'],
    ['viewer@example.com', 'Vera Viewer', 'viewer'],
  ];
  for (const [email, name, role] of demoUsers) {
    db.users.push({
      id: db.nextId('user'),
      email,
      name,
      role,
      passwordHash: pwHash,
      googleSub: null,
      telegramChat: role === 'admin' ? '48291044' : null,
      whatsappPhone: null,
      callmebotKey: null,
      isActive: true,
      sessionEpoch: 1,
      lastLoginAt: daysAgoTimestamp(1),
      createdAt: daysAgoTimestamp(45),
    });
  }

  // Deterministic Homes (Austin TX + Richmond/Fairfax VA)
  const rnd = createRng(20261116);
  const rand = (a: number, b: number) => a + (b - a) * rnd();
  const randInt = (a: number, b: number) => Math.floor(rand(a, b + 1));

  const streets = [
    'Maple St', 'Bluebonnet Ln', 'Kinney Ave', 'S 5th St', 'Garner Ave',
    'Oltorf St', 'Treadwell St', 'Hether St', 'Frazier Ave', 'Ashby Ave',
    'Rabb Rd', 'Dexter St', 'Goodrich Ave', 'Paramount Ave', 'Montclaire St',
    'Arpdale St', 'Clawson Rd', 'Fortview Rd', 'Bauerle Ave', 'Ford St',
  ];
  const agents: Array<[string, string]> = [
    ['Dana Whitfield', 'Lamar & Oak Realty'],
    ['Marcus Ibarra', 'Violet Crown Homes'],
    ['Priya Raman', 'Bouldin Group'],
    ['Tom Eckhart', 'Capitol City Brokers'],
  ];
  const zips: Array<[string, string, string, string, number, number, number]> = [
    ['78704', 'Austin', 'TX', 'Travis', 30.246, -97.768, 60],
    ['78745', 'Austin', 'TX', 'Travis', 30.207, -97.795, 60],
    ['78741', 'Austin', 'TX', 'Travis', 30.231, -97.72, 60],
    ['23220', 'Richmond', 'VA', 'Richmond', 37.551, -77.459, 25],
    ['22030', 'Fairfax', 'VA', 'Fairfax', 38.846, -77.306, 20],
  ];
  const types = ['Single Family', 'Single Family', 'Single Family', 'Condo', 'Townhouse'];
  const texts = [
    'Investor special, sold as-is. Original kitchen and dated bathrooms, roof is older. Cash or rehab loan only.',
    'Needs TLC throughout. Bring your contractor — great bones on a quiet street.',
    'Fully renovated in 2023: new roof, new HVAC, updated kitchen with quartz counters, remodeled baths, new LVP flooring.',
    'Updated kitchen with stainless appliances. Bathrooms are original. Finished basement and 2-car garage.',
    'Move-in ready and meticulously maintained. Fresh paint, new carpet in the bedrooms.',
    'Estate sale. Foundation issues noted by seller; water damage in the basement. Priced accordingly.',
    'Spacious home close to schools and parks.',
    '',
  ];

  const forSaleIds: number[] = [];

  for (let zi = 0; zi < zips.length; zi++) {
    const [zip, city, state, county, clat, clng, count] = zips[zi];
    let saleCount = 0;
    let soldCount = 0;
    for (let i = 0; i < count; i++) {
      const type = types[randInt(0, types.length - 1)];
      const sqft = Math.round(rand(type === 'Condo' ? 620 : 1050, type === 'Condo' ? 1350 : 2900) / 10) * 10;
      const beds = Math.max(1, Math.min(5, Math.round(sqft / 620)));
      const baths = Math.max(1, beds - randInt(0, 1)) - (randInt(0, 3) === 0 ? 0.5 : 0);
      const basePpsf = state === 'VA' ? rand(240, 390) : rand(330, 520) * (zip === '78704' ? 1.18 : 1.0);
      const price = Math.round((sqft * basePpsf) / 1000) * 1000;
      const streetNum = 900 + i * 37 + zi * 111;
      const streetName = streets[(i + zi * 7) % streets.length];
      const unitSuffix = type === 'Condo' ? ` Unit ${101 + (i % 220)}` : '';
      const desc = texts[randInt(0, texts.length - 1)] || null;
      const lotSqft = type === 'Condo' ? null : Math.round(rand(4200, 11000) / 10) * 10;
      const yearBuilt = randInt(1948, 2023);
      const hoaFee = type === 'Single Family' ? (randInt(0, 4) === 0 ? randInt(35, 90) : 0) : randInt(180, 460);

      const base = {
        address: `${streetNum} ${streetName}${unitSuffix}`,
        city,
        state,
        zip,
        county,
        lat: Number((clat + rand(-0.016, 0.016)).toFixed(6)),
        lng: Number((clng + rand(-0.019, 0.019)).toFixed(6)),
        property_type: type,
        beds,
        baths,
        sqft,
        lot_sqft: lotSqft,
        year_built: yearBuilt,
        hoa_fee: hoaFee,
        description: desc,
      };

      if (i % 5 < 2) {
        const days = randInt(0, 120);
        const [agentName, officeName] = agents[randInt(0, agents.length - 1)];
        const listedDate = daysAgoDate(days);
        const cutMult = days > 40 && randInt(0, 2) === 0 ? rand(1.04, 1.1) : 1;
        // Make fixer listings noticeably cheaper so opportunity scores spread naturally
        const descDiscount = desc && /investor special|tlc|foundation|estate/i.test(desc) ? 0.78 : 1.0;
        const targetPrice = Math.round((price * descDiscount) / 1000) * 1000;
        const firstPrice = Math.round((targetPrice * cutMult) / 1000) * 1000;

        const res = upsertProperty(
          {
            ...base,
            status: 'for_sale',
            list_price: firstPrice,
            listed_date: listedDate,
            days_on_market: days,
            listing_type: randInt(0, 14) === 0 ? 'New Construction' : 'Standard',
            agent_name: agentName,
            office_name: officeName,
            agent_phone: state === 'VA' ? `804555${randInt(1000, 9999)}` : `512555${randInt(1000, 9999)}`,
            mls_name: state === 'VA' ? 'CVRMLS' : 'UnlockMLS',
            mls_number: String(randInt(1000000, 9999999)),
            tax_annual: Math.round((targetPrice * (state === 'VA' ? 0.011 : 0.019)) / 10) * 10,
            est_value: Math.round((targetPrice * rand(1.02, 1.18)) / 1000) * 1000,
            est_value_low: Math.round((targetPrice * 0.94) / 1000) * 1000,
            est_value_high: Math.round((targetPrice * 1.24) / 1000) * 1000,
            est_rent: Math.round((targetPrice * 0.0055) / 50) * 50,
            est_source: 'rentcast',
            est_at: daysAgoTimestamp(2),
            data_status: 'full',
          },
          'rentcast'
        );

        const prop = db.properties.find((p) => p.id === res.id)!;
        if (firstPrice !== targetPrice) {
          addPriceEvent(res.id, 'price_change', targetPrice, daysAgoDate(randInt(1, 6)), 'rentcast');
          prop.list_price = targetPrice;
        }
        if (randInt(0, 2) === 0) {
          addPriceEvent(res.id, 'sold', Math.round((targetPrice * rand(0.55, 0.8)) / 1000) * 1000, daysAgoDate(randInt(1500, 4200)), 'rentcast');
        }
        refreshCondition(prop);
        forSaleIds.push(res.id);
        saleCount++;
      } else {
        const res = upsertProperty(
          {
            ...base,
            status: 'sold',
            last_sale_price: Math.round((price * rand(0.93, 1.04)) / 1000) * 1000,
            last_sale_date: daysAgoDate(randInt(6, 540)),
            tax_annual: Math.round((price * (state === 'VA' ? 0.011 : 0.019)) / 10) * 10,
            assessed_value: Math.round((price * 0.88) / 1000) * 1000,
            data_status: 'full',
          },
          'rentcast'
        );
        const prop = db.properties.find((p) => p.id === res.id)!;
        refreshCondition(prop);
        soldCount++;
      }
    }

    for (const mode of ['sale', 'sold'] as const) {
      db.snapshots.push({
        id: db.nextId('snapshot'),
        provider: 'rentcast',
        mode,
        loc_key: `zip:${zip}`,
        filter_sig: mode === 'sold' ? crypto.createHash('md5').update('{"days":730}').digest('hex') : '',
        filter_json: mode === 'sold' ? '{"days":730}' : '{}',
        result_count: mode === 'sale' ? saleCount : soldCount,
        truncated: 0,
        fetched_at: daysAgoTimestamp(0),
      });
    }
  }

  for (const [locKey, total] of [
    ['city:TX:austin', 180],
    ['state:TX', 180],
    ['city:VA:richmond', 25],
    ['state:VA', 45],
  ] as const) {
    for (const mode of ['sale', 'sold'] as const) {
      db.snapshots.push({
        id: db.nextId('snapshot'),
        provider: 'rentcast',
        mode,
        loc_key: locKey,
        filter_sig: mode === 'sold' ? crypto.createHash('md5').update('{"days":730}').digest('hex') : '',
        filter_json: mode === 'sold' ? '{"days":730}' : '{}',
        result_count: Math.round(total * (mode === 'sale' ? 0.4 : 0.6)),
        truncated: 0,
        fetched_at: daysAgoTimestamp(0),
      });
    }
  }

  // Seed Watchlist / Pipeline + Notes + Actual Deal for Reports
  const stagePlan = ['offer', 'inspection', 'contact_agent', 'under_review', 'new', 'purchased'];
  stagePlan.forEach((stage, i) => {
    const pid = forSaleIds[i * 4];
    if (!pid) return;
    const owner = stage === 'new' ? null : i % 2 === 0 ? 1 : 2;
    db.watchlist.push({
      id: db.nextId('watch'),
      property_id: pid,
      stage,
      owner_id: owner,
      added_by: 1,
      updated_by: owner ?? 1,
      source_url: null,
      created_at: daysAgoTimestamp(12 - i),
      updated_at: daysAgoTimestamp(Math.max(1, 5 - i)),
    });
    logActivity(owner ?? 1, 'stage', pid, `Moved to ${PIPELINE_STAGES[stage]}`);

    if (i === 0) {
      addNote(pid, 1, 'Spoke with listing agent — seller is open to a 21-day close if earnest money is non-refundable after inspection.');
      addNote(pid, 2, 'Walked the perimeter: roof looks 15+ years old, HVAC condenser is 2019, good layout for an open kitchen.');
    }
    if (i === 5) {
      // Completed project for "Predicted vs actual" table in Reports
      const p = db.properties.find((x) => x.id === pid)!;
      const buy = p.list_price ? Math.round(p.list_price * 0.92) : 380000;
      const costs = 64000;
      const sale = Math.round(buy * 1.32);
      db.deals.set(pid, {
        property_id: pid,
        purchase_price: buy,
        closing_pct: 2,
        reno_cost: 42000,
        holding_months: 5,
        holding_monthly: 950,
        loan_ltv_pct: 0,
        loan_rate_pct: 11,
        loan_points_pct: 2,
        selling_pct: 7,
        arv: sale,
        contingency_pct: 10,
        actual_purchase: buy,
        actual_costs: costs,
        actual_sale: sale,
        updated_by: 1,
        updated_at: daysAgoTimestamp(3),
      });
    }
  });

  // Saved Search Profiles
  db.savedSearches.push(
    {
      id: db.nextId('saved'),
      user_id: 1,
      name: 'Austin fixers under $550K',
      params: { q: 'Austin, TX', types: ['Single Family', 'Townhouse'], price_max: 550000, listing: ['fixer'], sort: 'score' },
      last_run_at: daysAgoTimestamp(1),
      created_at: daysAgoTimestamp(10),
    },
    {
      id: db.nextId('saved'),
      user_id: 1,
      name: '78704 High Opportunity',
      params: { q: '78704', score_min: 60, sort: 'score' },
      last_run_at: daysAgoTimestamp(2),
      created_at: daysAgoTimestamp(14),
    }
  );

  // Sample Alerts + Hits
  const a1Id = db.nextId('alert');
  db.alerts.push({
    id: a1Id,
    user_id: 1,
    name: 'Austin, TX · Fixer-uppers',
    kind: 'new_listing',
    location: 'Austin, TX',
    criteria: { types: ['Single Family', 'Townhouse'], listing: ['fixer'], score_min: 60 },
    every_hours: 24,
    channels: ['telegram'],
    delivery: 'instant',
    use_quota: true,
    enabled: true,
    last_checked_at: daysAgoTimestamp(0),
    last_note: '2 new matches sent.',
    created_at: daysAgoTimestamp(14),
  });
  db.alerts.push({
    id: db.nextId('alert'),
    user_id: 1,
    name: 'Pipeline price cuts (3%+)',
    kind: 'price_drop',
    location: '',
    criteria: { min_drop_pct: 3 },
    every_hours: 12,
    channels: ['telegram'],
    delivery: 'instant',
    use_quota: false,
    enabled: true,
    last_checked_at: daysAgoTimestamp(0),
    last_note: 'No new price cuts.',
    created_at: daysAgoTimestamp(9),
  });

  if (forSaleIds.length >= 2) {
    const p1 = db.properties.find((x) => x.id === forSaleIds[0])!;
    db.alertHits.push({
      id: db.nextId('hit'),
      alert_id: a1Id,
      property_id: p1.id,
      price: p1.list_price ?? 420000,
      summary: 'New match · 3 bd · fixer-upper',
      notified: true,
      created_at: daysAgoTimestamp(1),
    });
  }

  // Market Regions + Monthly Zillow Research Series (last 36 months)
  const seedRegion = (kind: 'zip' | 'city', name: string, state: string, startZhvi: number, growthPerMo: number, baseList: number) => {
    const rid = db.nextId('region');
    db.marketRegions.push({ id: rid, kind, name, state, added_at: daysAgoTimestamp(20) });
    const nowDate = new Date();
    for (let m = 60; m >= 1; m--) {
      const d = new Date(Date.UTC(nowDate.getUTCFullYear(), nowDate.getUTCMonth() - m, 1));
      const period = d.toISOString().slice(0, 10);
      const t = 60 - m;
      const wave = Math.sin(t / 4.5) * 4500;
      const zhvi = Math.round(startZhvi * Math.pow(1 + growthPerMo, t) + wave);
      const medSale = Math.round(zhvi * 0.985);
      const medList = Math.round(baseList * Math.pow(1 + growthPerMo * 0.9, t) + wave * 1.1);
      const medPpsf = Math.round((zhvi / 1780) * 100) / 100;
      const inv = Math.round(280 + Math.sin(t / 3.0) * 45 + t * 1.1);
      const dom = Math.round(34 + Math.cos(t / 3.2) * 9);
      db.marketSeries.push(
        { region_id: rid, metric: 'zhvi', period, value: zhvi },
        { region_id: rid, metric: 'median_sale', period, value: medSale },
        { region_id: rid, metric: 'median_list', period, value: medList },
        { region_id: rid, metric: 'median_ppsf', period, value: medPpsf },
        { region_id: rid, metric: 'inventory', period, value: inv },
        { region_id: rid, metric: 'days_pending', period, value: dom }
      );
    }
  };

  seedRegion('zip', '78704', 'TX', 595000, 0.0024, 635000);
  seedRegion('city', 'Austin', 'TX', 485000, 0.0021, 515000);
  seedRegion('zip', '23220', 'VA', 365000, 0.0038, 389000);
}
