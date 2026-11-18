import fs from 'node:fs';
import path from 'node:path';

export const APP_ROOT = process.cwd();

const ICONS: Record<string, string> = {
  'search': '<path d="m21 21-4.34-4.34" /> <circle cx="11" cy="11" r="8" />',
  'star': '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />',
  'bell': '<path d="M10.268 21a2 2 0 0 0 3.464 0" /> <path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326" />',
  'map-pin': '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" /> <circle cx="12" cy="10" r="3" />',
  'external-link': '<path d="M15 3h6v6" /> <path d="M10 14 21 3" /> <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />',
  'plus': '<path d="M5 12h14" /> <path d="M12 5v14" />',
  'trash-2': '<path d="M10 11v6" /> <path d="M14 11v6" /> <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" /> <path d="M3 6h18" /> <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />',
  'refresh-cw': '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8" /> <path d="M21 3v5h-5" /> <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16" /> <path d="M8 16H3v5" />',
  'check': '<path d="M20 6 9 17l-5-5" />',
  'x': '<path d="M18 6 6 18" /> <path d="m6 6 12 12" />',
  'menu': '<path d="M4 12h16" /> <path d="M4 18h16" /> <path d="M4 6h16" />',
  'chevron-right': '<path d="m9 18 6-6-6-6" />',
  'chevron-down': '<path d="m6 9 6 6 6-6" />',
  'settings': '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915" /> <circle cx="12" cy="12" r="3" />',
  'download': '<path d="M12 15V3" /> <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /> <path d="m7 10 5 5 5-5" />',
  'house': '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" /> <path d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />',
  'trending-up': '<path d="M16 7h6v6" /> <path d="m22 7-8.5 8.5-5-5L2 17" />',
  'trending-down': '<path d="M16 17h6v-6" /> <path d="m22 17-8.5-8.5-5 5L2 7" />',
  'bookmark': '<path d="M17 3a2 2 0 0 1 2 2v15a1 1 0 0 1-1.496.868l-4.512-2.578a2 2 0 0 0-1.984 0l-4.512 2.578A1 1 0 0 1 5 20V5a2 2 0 0 1 2-2z" />',
  'link': '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" /> <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />',
  'pencil': '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z" /> <path d="m15 5 4 4" />',
  'log-out': '<path d="m16 17 5-5-5-5" /> <path d="M21 12H9" /> <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />',
  'mail': '<path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7" /> <rect x="2" y="4" width="20" height="16" rx="2" />',
  'send': '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z" /> <path d="m21.854 2.147-10.94 10.939" />',
  'message-circle': '<path d="M2.992 16.342a2 2 0 0 1 .094 1.167l-1.065 3.29a1 1 0 0 0 1.236 1.168l3.413-.998a2 2 0 0 1 1.099.092 10 10 0 1 0-4.777-4.719" />',
  'list': '<path d="M3 5h.01" /> <path d="M3 12h.01" /> <path d="M3 19h.01" /> <path d="M8 5h13" /> <path d="M8 12h13" /> <path d="M8 19h13" />',
  'map': '<path d="M14.106 5.553a2 2 0 0 0 1.788 0l3.659-1.83A1 1 0 0 1 21 4.619v12.764a1 1 0 0 1-.553.894l-4.553 2.277a2 2 0 0 1-1.788 0l-4.212-2.106a2 2 0 0 0-1.788 0l-3.659 1.83A1 1 0 0 1 3 19.381V6.618a1 1 0 0 1 .553-.894l4.553-2.277a2 2 0 0 1 1.788 0z" /> <path d="M15 5.764v15" /> <path d="M9 3.236v15" />',
  'triangle-alert': '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3" /> <path d="M12 9v4" /> <path d="M12 17h.01" />',
  'info': '<circle cx="12" cy="12" r="10" /> <path d="M12 16v-4" /> <path d="M12 8h.01" />',
  'arrow-up-right': '<path d="M7 7h10v10" /> <path d="M7 17 17 7" />',
  'arrow-down-right': '<path d="m7 7 10 10" /> <path d="M17 7v10H7" />',
  'arrow-left': '<path d="m12 19-7-7 7-7" /> <path d="M19 12H5" />',
  'clock': '<circle cx="12" cy="12" r="10" /> <path d="M12 6v6l4 2" />',
  'database': '<ellipse cx="12" cy="5" rx="9" ry="3" /> <path d="M3 5V19A9 3 0 0 0 21 19V5" /> <path d="M3 12A9 3 0 0 0 21 12" />',
  'users': '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /> <path d="M16 3.128a4 4 0 0 1 0 7.744" /> <path d="M22 21v-2a4 4 0 0 0-3-3.87" /> <circle cx="9" cy="7" r="4" />',
  'copy': '<rect width="14" height="14" x="8" y="8" rx="2" ry="2" /> <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />',
  'filter': '<path d="M10 20a1 1 0 0 0 .553.895l2 1A1 1 0 0 0 14 21v-7a2 2 0 0 1 .517-1.341L21.74 4.67A1 1 0 0 0 21 3H3a1 1 0 0 0-.742 1.67l7.225 7.989A2 2 0 0 1 10 14z" />',
  'eye': '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0" /> <circle cx="12" cy="12" r="3" />',
  'layout-dashboard': '<rect width="7" height="9" x="3" y="3" rx="1" /> <rect width="7" height="5" x="14" y="3" rx="1" /> <rect width="7" height="9" x="14" y="12" rx="1" /> <rect width="7" height="5" x="3" y="16" rx="1" />',
  'calculator': '<rect width="16" height="20" x="4" y="2" rx="2" /> <line x1="8" x2="16" y1="6" y2="6" /> <line x1="16" x2="16" y1="14" y2="18" /> <path d="M16 10h.01" /> <path d="M12 10h.01" /> <path d="M8 10h.01" /> <path d="M12 14h.01" /> <path d="M8 14h.01" /> <path d="M12 18h.01" /> <path d="M8 18h.01" />',
  'file-spreadsheet': '<path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z" /> <path d="M14 2v5a1 1 0 0 0 1 1h5" /> <path d="M8 13h2" /> <path d="M14 13h2" /> <path d="M8 17h2" /> <path d="M14 17h2" />',
  'printer': '<path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" /> <path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6" /> <rect x="6" y="14" width="12" height="8" rx="1" />',
  'user': '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /> <circle cx="12" cy="7" r="4" />',
  'kanban': '<path d="M5 3v14" /> <path d="M12 3v8" /> <path d="M19 3v18" />',
  'wrench': '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.106-3.105c.32-.322.863-.22.983.218a6 6 0 0 1-8.259 7.057l-7.91 7.91a1 1 0 0 1-2.999-3l7.91-7.91a6 6 0 0 1 7.057-8.259c.438.12.54.662.219.984z" />',
  'user-check': '<path d="m16 11 2 2 4-4" /> <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /> <circle cx="9" cy="7" r="4" />',
  'trophy': '<path d="M10 14.66V17a1 1 0 0 1-1 1 2 2 0 0 0-2 2v2" /> <path d="M14 14.66V17a1 1 0 0 0 1 1 2 2 0 0 1 2 2v2" /> <path d="M17.916 10H19.5A2.5 2.5 0 0 0 22 7.5V5a1 1 0 0 0-1-1h-3" /> <path d="M4 22h16" /> <path d="M6 9a6 6 0 0 0 12 0V3a1 1 0 0 0-1-1H7a1 1 0 0 0-1 1z" /> <path d="M6.084 10H4.5A2.5 2.5 0 0 1 2 7.5V5a1 1 0 0 1 1-1h3" />',
  'link-2': '<path d="M9 17H7A5 5 0 0 1 7 7h2" /> <path d="M15 7h2a5 5 0 1 1 0 10h-2" /> <line x1="8" x2="16" y1="12" y2="12" />',
  'gauge': '<path d="m12 14 4-4" /> <path d="M3.34 19a10 10 0 1 1 17.32 0" />',
};

export function e(v: unknown): string {
  return String(v ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

export function now(): string {
  return new Date().toISOString().slice(0, 19).replace('T', ' ');
}

export function today(): string {
  return new Date().toISOString().slice(0, 10);
}

export function daysAgoDate(days: number): string {
  return new Date(Date.now() - days * 86400_000).toISOString().slice(0, 10);
}

export function daysAgoTimestamp(days: number): string {
  return new Date(Date.now() - days * 86400_000).toISOString().slice(0, 19).replace('T', ' ');
}

export function url(pathStr = '/', query: Record<string, unknown> = {}): string {
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(query)) {
    if (v === null || v === undefined || v === '') continue;
    if (Array.isArray(v)) {
      if (v.length === 0) continue;
      for (const item of v) {
        if (item !== null && item !== undefined && item !== '') {
          params.append(`${k}[]`, String(item));
        }
      }
    } else {
      params.append(k, String(v));
    }
  }
  const qs = params.toString();
  return pathStr + (qs ? '?' + qs : '');
}

export function parseNum(v: unknown): number | null {
  if (v === null || v === undefined) return null;
  let s = String(v).toLowerCase().trim();
  if (s === '') return null;
  let mult = 1;
  if (s.endsWith('k')) {
    mult = 1000;
    s = s.slice(0, -1);
  } else if (s.endsWith('m')) {
    mult = 1_000_000;
    s = s.slice(0, -1);
  }
  s = s.replace(/[^0-9.\-]/g, '');
  if (s === '' || Number.isNaN(Number(s))) return null;
  return Number(s) * mult;
}

export function money(v: unknown, dash = '—'): string {
  const n = Number(v);
  if (v === null || v === undefined || v === '' || Number.isNaN(n) || n <= 0) return dash;
  return '$' + Math.round(n).toLocaleString('en-US');
}

export function moneyShort(v: unknown): string {
  const n = Number(v);
  if (!Number.isFinite(n) || n <= 0) return '—';
  if (n >= 1_000_000) {
    return '$' + (n / 1_000_000).toFixed(2).replace(/\.?0+$/, '') + 'M';
  }
  if (n >= 1000) {
    return '$' + Math.round(n / 1000).toLocaleString('en-US') + 'K';
  }
  return '$' + Math.round(n).toLocaleString('en-US');
}

export function num(v: unknown, dec = 0, dash = '—'): string {
  if (v === null || v === undefined || v === '') return dash;
  const n = Number(v);
  if (!Number.isFinite(n)) return dash;
  return n.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
}

export function trimNum(v: unknown, dash = '—'): string {
  if (v === null || v === undefined || v === '') return dash;
  const n = Number(v);
  if (!Number.isFinite(n)) return dash;
  return n.toFixed(1).replace(/\.0$/, '');
}

export function fmtPhone(v?: string | null): string {
  if (!v) return '';
  let d = v.replace(/\D/g, '');
  if (d.length === 11 && d[0] === '1') d = d.slice(1);
  if (d.length === 10) return `(${d.slice(0, 3)}) ${d.slice(3, 6)}-${d.slice(6)}`;
  return v;
}

export function ppsf(price: unknown, sqft: unknown): number | null {
  const p = Number(price);
  const s = Number(sqft);
  return p > 0 && s > 0 ? p / s : null;
}

export function pct(v: number, dec = 1): string {
  const sign = v > 0 ? '+' : v < 0 ? '−' : '';
  return sign + Math.abs(v).toFixed(dec) + '%';
}

export function fmtDate(v?: string | null, format = 'M j, Y', dash = '—'): string {
  if (!v || v.startsWith('0000')) return dash;
  const iso = v.length === 10 ? `${v}T00:00:00Z` : v.replace(' ', 'T') + (v.endsWith('Z') ? '' : 'Z');
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return dash;
  if (format === 'l, F j') {
    return d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'UTC' });
  }
  if (format === 'M j') {
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' });
  }
  if (format === 'M Y') {
    return d.toLocaleDateString('en-US', { month: 'short', year: 'numeric', timeZone: 'UTC' });
  }
  if (format === 'F Y') {
    return d.toLocaleDateString('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' });
  }
  if (format === 'M j, g:i a' || format === 'M j, Y g:i a' || format === 'F j, Y g:i a') {
    return d.toLocaleString('en-US', {
      month: format.startsWith('F') ? 'long' : 'short',
      day: 'numeric',
      year: format.includes('Y') ? 'numeric' : undefined,
      hour: 'numeric',
      minute: '2-digit',
      timeZone: 'UTC',
    });
  }
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
}

export function ago(utc?: string | null): string {
  if (!utc) return 'never';
  const iso = utc.length === 10 ? `${utc}T00:00:00Z` : utc.replace(' ', 'T') + (utc.endsWith('Z') ? '' : 'Z');
  const t = new Date(iso).getTime();
  if (Number.isNaN(t)) return 'never';
  const s = Math.max(0, Math.floor((Date.now() - t) / 1000));
  if (s < 90) return 'just now';
  if (s < 3600) return `${Math.round(s / 60)} min ago`;
  if (s < 86400) return `${Math.round(s / 3600)}h ago`;
  if (s < 172800) return 'yesterday';
  if (s < 86400 * 60) return `${Math.round(s / 86400)} days ago`;
  return fmtDate(utc);
}

export function daysSince(date?: string | null): number | null {
  if (!date) return null;
  const t = new Date(date.slice(0, 10) + 'T00:00:00Z').getTime();
  if (Number.isNaN(t)) return null;
  return Math.max(0, Math.floor((Date.now() - t) / 86400_000));
}

export function plural(n: number, one: string, many?: string): string {
  return `${n.toLocaleString('en-US')} ${n === 1 ? one : (many ?? `${one}s`)}`;
}

export function icon(name: string, size = 16, cls = ''): string {
  const inner = ICONS[name] ?? '';
  return `<svg class="icon ${e(cls)}" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${inner}</svg>`;
}

export function sketch(name: string): string {
  if (!/^[a-z-]+$/.test(name)) return '';
  const file = path.join(APP_ROOT, 'views', 'partials', 'sketches', `${name}.svg`);
  if (!fs.existsSync(file)) return '';
  const raw = fs.readFileSync(file, 'utf-8');
  const svg = raw
    .replace(/id="t"/g, `id="sk-${name}"`)
    .replace(/aria-labelledby="t"/g, `aria-labelledby="sk-${name}"`);
  return `<figure class="sketch" lang="fa">${svg}</figure>`;
}

export function jsonEmbed(data: unknown): string {
  return JSON.stringify(data)
    .replace(/</g, '\\u003c')
    .replace(/>/g, '\\u003e')
    .replace(/&/g, '\\u0026')
    .replace(/'/g, '\\u0027');
}

export function selected(a: unknown, b: unknown): string {
  return String(a ?? '') === String(b ?? '') ? ' selected' : '';
}

export function checked(v: unknown): string {
  return v ? ' checked' : '';
}

export function median(values: Array<number | null | undefined>): number | null {
  const clean = values.filter((v): v is number => v !== null && v !== undefined && Number.isFinite(v)).sort((a, b) => a - b);
  if (clean.length === 0) return null;
  const mid = Math.floor(clean.length / 2);
  return clean.length % 2 ? clean[mid] : (clean[mid - 1] + clean[mid]) / 2;
}

export function percentile(values: Array<number | null | undefined>, p: number): number | null {
  const clean = values.filter((v): v is number => v !== null && v !== undefined && Number.isFinite(v)).sort((a, b) => a - b);
  if (clean.length === 0) return null;
  const pos = (clean.length - 1) * p;
  const lo = Math.floor(pos);
  const hi = Math.ceil(pos);
  return clean[lo] + (clean[hi] - clean[lo]) * (pos - lo);
}
