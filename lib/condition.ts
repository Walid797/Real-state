export const OVERALL: Record<string, string> = {
  not_disclosed: 'Not disclosed',
  needs_renovation: 'Needs renovation',
  major_repair: 'Major repair needed',
  partially_renovated: 'Partially renovated',
  recently_renovated: 'Recently renovated',
  move_in_ready: 'Move-in ready',
};

export const PARTS: Record<string, string> = {
  kitchen: 'Kitchen',
  bathroom: 'Bathroom',
  roof: 'Roof',
  hvac: 'HVAC',
  flooring: 'Flooring',
  electrical: 'Electrical',
  plumbing: 'Plumbing',
  windows: 'Windows',
};

export const STATES: Record<string, string> = {
  unknown: 'Not disclosed',
  needs_work: 'Needs work',
  original: 'Original / dated',
  updated: 'Updated',
  new: 'New',
};

export const FILTER: Record<string, string> = {
  any: 'Any',
  updated: 'Updated',
  needs_work: 'Needs work',
};

export const FLAGS: Record<string, string> = {
  foreclosure: 'Foreclosure',
  short_sale: 'Short sale',
  auction: 'Auction',
  fixer: 'Fixer-upper',
  as_is: 'Sold as-is',
  estate: 'Estate sale',
  new_construction: 'New construction',
};

const PART_WORDS: Record<string, string> = {
  kitchen: 'kitchen|cabinets|countertops?|appliances',
  bathroom: 'bath(?:room)?s?|vanit(?:y|ies)|showers?',
  roof: 'roof|shingles',
  hvac: 'hvac|furnace|heat pump|a/?c\\b|air condition(?:er|ing)|ac unit|heating system',
  flooring: 'floor(?:ing|s)?|carpet|lvp|hardwoods?',
  electrical: 'electrical|electric panel|panel|wiring',
  plumbing: 'plumbing|pipes|water heater',
  windows: 'windows',
};

const NEW_WORDS = 'brand[- ]new|new|newer|replaced|installed';
const UPDATED_WORDS = 'updated|renovated|remodel(?:l)?ed|upgraded|refinished|redone|modernized';
const BAD_WORDS = 'needs?|needing|dated|old|original|worn|damaged|leak(?:s|ing|y)?|outdated|tired|repairs? needed';

export interface DetectedCondition {
  overall: string;
  parts: Record<string, string>;
  flags: string[];
  basement: boolean | null;
  garage: number | null;
  renovatedYear: number | null;
  evidence: string[];
}

export function detectCondition(text: string, listingType?: string | null): DetectedCondition {
  const t = ' ' + text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').toLowerCase() + ' ';
  const parts: Record<string, string> = {};
  for (const k of Object.keys(PARTS)) parts[k] = 'unknown';
  const evidence: string[] = [];
  const flags: string[] = [];

  for (const [part, words] of Object.entries(PART_WORDS)) {
    let best: [string, string] | null = null;
    for (const [state, adj] of [
      ['needs_work', BAD_WORDS],
      ['new', NEW_WORDS],
      ['updated', UPDATED_WORDS],
    ] as const) {
      const re = new RegExp(
        `\\b(?:${adj})\\b[^.;!]{0,30}?\\b(?:${words})\\b|\\b(?:${words})\\b[^.;!]{0,25}?\\b(?:${adj})\\b`,
        'gu'
      );
      const hits = t.match(re) ?? [];
      for (const hit of hits) {
        if (new RegExp(`\\bno (?:${words})\\b`, 'i').test(hit)) continue;
        const state2 = new RegExp(`\\bneeds? (?:a )?new\\b`, 'i').test(hit) && state !== 'needs_work' ? 'needs_work' : state;
        if (!best || hit.length < best[1].length) {
          best = [state2, hit];
        }
      }
    }
    if (best) {
      let [st, hit] = best;
      if (st === 'needs_work' && /original|dated|\bold\b|outdated/.test(hit)) st = 'original';
      parts[part] = st;
      evidence.push(hit.trim());
    }
  }

  const flagWords: Record<string, string> = {
    auction: '\\bauction\\b',
    fixer: 'fixer[- ]?upper|handyman special|investor special|needs (?:a lot of |some )?(?:tlc|work)|\\btlc\\b|bring your (?:tools|contractor|vision)|needs? (?:a )?rehab|rehab (?:opportunity|project)|cash (?:buyers? )?only|needs (?:full |complete )?renovation|\\bgut job\\b',
    as_is: '\\bas[- ]is\\b',
    estate: 'estate sale|sold by (?:the )?estate',
    foreclosure: 'foreclos|bank[- ]owned|\\breo\\b',
    short_sale: 'short sale',
    new_construction: 'new construction|newly built|builder warranty',
  };
  for (const [flag, pattern] of Object.entries(flagWords)) {
    const m = t.match(new RegExp(pattern, 'u'));
    if (m) {
      flags.push(flag);
      evidence.push(m[0].trim());
    }
  }
  const lt = (listingType ?? '').toLowerCase();
  if (lt === 'foreclosure') flags.push('foreclosure');
  else if (lt === 'short sale') flags.push('short_sale');
  else if (lt === 'new construction') flags.push('new_construction');
  const uniqueFlags = Array.from(new Set(flags));

  const majorMatch = t.match(/foundation (?:issue|problem|crack|repair)|structural|fire damage|water damage|mold|needs (?:a )?new roof|roof (?:leaks?|needs)|condemned|uninhabitable|not livable/u);
  if (majorMatch) evidence.push(majorMatch[0].trim());

  const vals = Object.values(parts);
  const good = vals.filter((s) => s === 'new' || s === 'updated').length;
  const bad = vals.filter((s) => s === 'needs_work' || s === 'original').length;
  const fullyMatch = t.match(/(?:fully|completely|totally|newly|beautifully|recently) (?:renovated|remodel(?:l)?ed|updated|rehabbed)|top to bottom|complete renovation|gut renovation (?:completed|done)/u);

  let overall = 'not_disclosed';
  if (majorMatch) overall = 'major_repair';
  else if (uniqueFlags.includes('fixer') || (bad >= 2 && good === 0)) overall = 'needs_renovation';
  else if (fullyMatch || good >= 4) overall = 'recently_renovated';
  else if ((good >= 1 && bad >= 1) || (good >= 1 && good < 4)) overall = 'partially_renovated';
  else if (/move[- ]in ready|turn[- ]?key|meticulously maintained|immaculate/u.test(t)) overall = 'move_in_ready';

  if (fullyMatch) evidence.push(fullyMatch[0].trim());

  let basement: boolean | null = null;
  if (/\bno basement\b|\bslab foundation\b/u.test(t)) basement = false;
  else if (/\b(?:finished|unfinished|walk[- ]?out|full|partial|daylight)? ?basement\b/u.test(t)) basement = true;

  let garage: number | null = null;
  const g = t.match(/\b(one|two|three|four|1|2|3|4)[- ]car garage\b/u);
  if (g) {
    const map: Record<string, number> = { one: 1, two: 2, three: 3, four: 4 };
    garage = map[g[1]] ?? Number(g[1]);
  }

  let renovatedYear: number | null = null;
  const y = t.match(/(?:renovated|remodel(?:l)?ed|updated) in (20[0-3]\d|19[89]\d)/u);
  if (y) renovatedYear = Number(y[1]);

  return {
    overall,
    parts,
    flags: uniqueFlags,
    basement,
    garage,
    renovatedYear,
    evidence: Array.from(new Set(evidence)).slice(0, 12),
  };
}

export function conditionTier(overall: string): string {
  switch (overall) {
    case 'move_in_ready':
    case 'recently_renovated':
      return 'reno_light';
    case 'partially_renovated':
      return 'reno_medium';
    case 'needs_renovation':
      return 'reno_heavy';
    case 'major_repair':
      return 'reno_major';
    default:
      return 'reno_unknown';
  }
}
