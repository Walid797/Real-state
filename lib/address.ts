import crypto from 'node:crypto';

export const STATES: Record<string, string> = {
  AL: 'Alabama', AK: 'Alaska', AZ: 'Arizona', AR: 'Arkansas', CA: 'California',
  CO: 'Colorado', CT: 'Connecticut', DE: 'Delaware', DC: 'District of Columbia',
  FL: 'Florida', GA: 'Georgia', HI: 'Hawaii', ID: 'Idaho', IL: 'Illinois',
  IN: 'Indiana', IA: 'Iowa', KS: 'Kansas', KY: 'Kentucky', LA: 'Louisiana',
  ME: 'Maine', MD: 'Maryland', MA: 'Massachusetts', MI: 'Michigan', MN: 'Minnesota',
  MS: 'Mississippi', MO: 'Missouri', MT: 'Montana', NE: 'Nebraska', NV: 'Nevada',
  NH: 'New Hampshire', NJ: 'New Jersey', NM: 'New Mexico', NY: 'New York',
  NC: 'North Carolina', ND: 'North Dakota', OH: 'Ohio', OK: 'Oklahoma', OR: 'Oregon',
  PA: 'Pennsylvania', RI: 'Rhode Island', SC: 'South Carolina', SD: 'South Dakota',
  TN: 'Tennessee', TX: 'Texas', UT: 'Utah', VT: 'Vermont', VA: 'Virginia',
  WA: 'Washington', WV: 'West Virginia', WI: 'Wisconsin', WY: 'Wyoming',
};

const SUFFIX: Record<string, string> = {
  street: 'st', avenue: 'ave', boulevard: 'blvd', drive: 'dr', road: 'rd',
  lane: 'ln', court: 'ct', place: 'pl', terrace: 'ter', circle: 'cir',
  highway: 'hwy', parkway: 'pkwy', trail: 'trl', square: 'sq', cove: 'cv',
  north: 'n', south: 's', east: 'e', west: 'w',
  northeast: 'ne', northwest: 'nw', southeast: 'se', southwest: 'sw',
  apartment: 'unit', apt: 'unit', suite: 'unit', ste: 'unit', number: 'unit',
};

export interface ParsedLocation {
  type: 'zip' | 'city' | 'county' | 'state' | 'multi' | 'address';
  key: string;
  label: string;
  zip?: string;
  city?: string;
  state?: string;
  county?: string;
  address?: string;
  parts?: ParsedLocation[];
}

export function stateAbbr(s: string): string | null {
  const trimmed = s.trim();
  const up = trimmed.toUpperCase();
  if (STATES[up]) return up;
  for (const [abbr, name] of Object.entries(STATES)) {
    if (name.toLowerCase() === trimmed.toLowerCase()) return abbr;
  }
  return null;
}

export function normalizeStreet(line: string): string {
  let s = line.toLowerCase().trim().replace(/#/g, ' unit ');
  s = s.replace(/[^a-z0-9\s/-]/g, ' ');
  const words = s.trim().split(/\s+/).filter(Boolean).map((w) => SUFFIX[w] ?? w);
  return words.join(' ');
}

export function addressHash(line1: string, city: string, state: string, zip: string): string {
  const z = zip.replace(/\D/g, '').slice(0, 5);
  const area = z !== '' ? z : `${city.trim().toLowerCase()}|${state.trim().toUpperCase()}`;
  return crypto.createHash('sha1').update(`${normalizeStreet(line1)}|${area}`).digest('hex');
}

export function fullAddress(p: { address?: string; city?: string; state?: string; zip?: string }): string {
  const tail = `${p.state ?? ''} ${p.zip ?? ''}`.trim();
  return [p.address ?? '', p.city ?? '', tail].filter(Boolean).join(', ');
}

export function titleCity(city: string): string {
  const c = city.trim().replace(/\s+/g, ' ');
  if (c !== c.toLowerCase() && c !== c.toUpperCase()) return c;
  return c.toLowerCase().replace(/(^|[\s-'])[a-z]/g, (m) => m.toUpperCase());
}

export function slug(s: string, sep = '-', lower = false): string {
  const out = s.trim().replace(/[^A-Za-z0-9]+/g, sep).replace(new RegExp(`^\\${sep}+|\\${sep}+$`, 'g'), '');
  return lower ? out.toLowerCase() : out;
}

export function parseFullAddress(q: string): { address: string; city: string; state: string; zip: string } | null {
  const parts = q.split(',').map((s) => s.trim()).filter(Boolean);
  if (parts.length < 3) return null;
  const last = parts.pop()!;
  let st: string | null = null;
  let zip = '';
  const m = last.match(/^([A-Za-z .]+?)\s*(\d{5})?(?:-\d{4})?$/);
  if (!m) {
    if (/^\d{5}/.test(last) && parts.length > 0) {
      zip = last.slice(0, 5);
      st = stateAbbr(parts.pop()!);
    } else {
      return null;
    }
  } else {
    st = stateAbbr(m[1].trim());
    zip = m[2] ?? '';
  }
  if (!st || parts.length < 2) return null;
  const city = titleCity(parts.pop()!);
  const line = parts.join(', ').replace(/,\s*/g, ' ');
  return { address: line, city, state: st, zip };
}

function parseOne(qInput: string, defaultState: string): ParsedLocation | null {
  const q = qInput.replace(/^[ \t,]+|[ \t,]+$/g, '');
  if (!q) return null;

  if (/^\d{5}(-\d{4})?$/.test(q)) {
    const zip = q.slice(0, 5);
    return { type: 'zip', zip, key: `zip:${zip}`, label: zip };
  }

  if (/^\d+[a-z]?\s+\S+/i.test(q)) {
    const a = parseFullAddress(q);
    if (a) {
      return {
        type: 'address',
        key: `addr:${addressHash(a.address, a.city, a.state, a.zip)}`,
        label: fullAddress(a),
        ...a,
      };
    }
    return null;
  }

  const bare = q.replace(/^(?:all of|entire|whole|statewide)\s+|\s+(?:statewide|state)$/i, '');
  const stBare = stateAbbr(bare);
  if (stBare && (bare.length === 2 ? bare.toUpperCase() === bare || bare !== q : true)) {
    return { type: 'state', state: stBare, key: `state:${stBare}`, label: STATES[stBare] };
  }

  let state: string | null = null;
  let head = q;
  const m = q.match(/^(.+?)[,\s]\s*([A-Za-z]{2})$/);
  if (m && stateAbbr(m[2])) {
    state = stateAbbr(m[2]);
    head = m[1].replace(/[, ]+$/, '');
  } else if (q.includes(',')) {
    const [c, sn] = q.split(',', 2).map((s) => s.trim());
    const st2 = stateAbbr(sn);
    if (st2) {
      state = st2;
      head = c;
    }
  } else {
    for (const [abbr, name] of Object.entries(STATES)) {
      const re = new RegExp(`^(.+)\\s+${name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`, 'i');
      const hit = q.match(re);
      if (hit) {
        state = abbr;
        head = hit[1];
        break;
      }
    }
  }

  const finalState = state ?? defaultState;
  head = head.trim();
  if (!head || /\d/.test(head)) return null;

  const countyMatch = head.match(/^(.+?)\s+(county|parish|borough)$/i);
  if (countyMatch) {
    const county = titleCity(countyMatch[1]);
    return {
      type: 'county',
      county,
      state: finalState,
      key: `county:${finalState}:${county.toLowerCase()}`,
      label: `${county} County, ${finalState}`,
    };
  }

  const city = titleCity(head);
  return {
    type: 'city',
    city,
    state: finalState,
    key: `city:${finalState}:${city.toLowerCase()}`,
    label: `${city}, ${finalState}`,
  };
}

function multi(parts: Array<ParsedLocation | null>): ParsedLocation | null {
  const clean = parts.filter((p): p is ParsedLocation => p !== null);
  if (clean.length === 0) return null;
  if (clean.length === 1) return clean[0];
  const keys = clean.map((p) => p.key).sort();
  const hash = crypto.createHash('sha1').update(keys.join('|')).digest('hex').slice(0, 16);
  return {
    type: 'multi',
    parts: clean,
    key: `multi:${hash}`,
    label: clean.map((p) => p.label).join(' · '),
  };
}

export function parseLocation(qInput: string, defaultState = 'TX'): ParsedLocation | null {
  const q = qInput.replace(/\s+/g, ' ').trim();
  if (!q) return null;

  if (/^\d{5}(?:[\s,;]+\d{5})+$/.test(q)) {
    const zips = Array.from(new Set(q.split(/[\s,;]+/).filter(Boolean))).slice(0, 40);
    return multi(zips.map((z) => parseOne(z, defaultState)));
  }

  if (q.includes(';')) {
    const parts = q
      .split(';')
      .map((s) => parseOne(s.trim(), defaultState))
      .filter((x): x is ParsedLocation => x !== null && x.type !== 'address')
      .slice(0, 40);
    if (parts.length === 0) return null;
    return parts.length === 1 ? parts[0] : multi(parts);
  }

  return parseOne(q, defaultState);
}

export function areas(loc: ParsedLocation): ParsedLocation[] {
  return loc.type === 'multi' && loc.parts ? loc.parts : [loc];
}

export function geoValid(lat: unknown, lng: unknown): boolean {
  const la = Number(lat);
  const lo = Number(lng);
  return Number.isFinite(la) && Number.isFinite(lo) && la >= -90 && la <= 90 && lo >= -180 && lo <= 180 && (la !== 0 || lo !== 0);
}

export function geoMiles(lat1: number, lng1: number, lat2: number, lng2: number): number {
  const r = Math.PI / 180;
  const dLat = (lat2 - lat1) * r;
  const dLng = (lng2 - lng1) * r;
  const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * r) * Math.cos(lat2 * r) * Math.sin(dLng / 2) ** 2;
  return 3958.8 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

export function geoBox(lat: number, lng: number, miles: number): { lat1: number; lat2: number; lng1: number; lng2: number } {
  const dLat = miles / 69.0;
  const cosLat = Math.max(0.01, Math.cos((lat * Math.PI) / 180));
  const dLng = miles / (69.172 * cosLat);
  return { lat1: lat - dLat, lat2: lat + dLat, lng1: lng - dLng, lng2: lng + dLng };
}
