/* ═══ VitaTrack SPA ═══ */
'use strict';

// ── State ─────────────────────────────────────────────────────────────
const S = {
  user: null, profile: null, settings: {},
  view: 'home', diaryDate: null,
  day: null,             // cached day summary for diaryDate
  recipes: null, exercises: null,
  fastTimer: null, installPrompt: null,
};

// ── Utilities ─────────────────────────────────────────────────────────
const $ = s => document.querySelector(s);
const app = () => $('#app');
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const todayStr = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); };
const round1 = n => Math.round(n * 10) / 10;

// Noom-style calorie-density colors: green = low density, orange = high
const densityClass = kcalPer100g => kcalPer100g < 100 ? 'g' : kcalPer100g < 300 ? 'y' : 'o';
const dot = cls => `<i class="dot dot-${cls}"></i>`;
const entryDot = e => +e.grams >= 20 ? dot(densityClass(+e.kcal / +e.grams * 100)) : '';

async function api(action, data) {
  const r = await fetch(`api.php?action=${action}&tzdate=${todayStr()}`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data || {}), credentials: 'same-origin',
  });
  const j = await r.json().catch(() => ({ ok: false, error: 'Bad server response' }));
  if (r.status === 401 && action !== 'login' && action !== 'me') { S.user = null; render(); }
  return j;
}

function toast(msg, ms = 2200) {
  document.querySelectorAll('.toast').forEach(t => t.remove());
  const t = document.createElement('div');
  t.className = 'toast'; t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), ms);
}

// Units
const isImperial = () => (S.profile?.units || 'metric') === 'imperial';
const kg2lb = kg => kg * 2.20462;
const lb2kg = lb => lb / 2.20462;
const fmtW = kg => kg == null ? '—' : (isImperial() ? round1(kg2lb(kg)) + ' lb' : round1(kg) + ' kg');
const wUnit = () => isImperial() ? 'lb' : 'kg';
const inputW2kg = v => isImperial() ? lb2kg(parseFloat(v)) : parseFloat(v);
const kg2input = kg => kg == null ? '' : round1(isImperial() ? kg2lb(kg) : kg);

function applyTheme() {
  const t = S.settings.theme || localStorage.getItem('vt_theme') || 'auto';
  const dark = t === 'dark' || (t === 'auto' && matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
}

// ── SVG helpers ───────────────────────────────────────────────────────
function ringSVG(size, stroke, pct, color, centerHTML, trackColor) {
  const r = (size - stroke) / 2, c = 2 * Math.PI * r;
  const off = c * (1 - Math.min(1, Math.max(0, pct)));
  return `<div class="ring-wrap" style="width:${size}px;height:${size}px">
    <svg width="${size}" height="${size}">
      <circle class="ring-bg" cx="${size/2}" cy="${size/2}" r="${r}" stroke-width="${stroke}" ${trackColor?`style="stroke:${trackColor}"`:''}/>
      <circle class="ring-fg" cx="${size/2}" cy="${size/2}" r="${r}" stroke-width="${stroke}"
        stroke="${color}" stroke-dasharray="${c}" stroke-dashoffset="${c}" data-off="${off}"/>
    </svg>
    <div class="ring-center">${centerHTML}</div>
  </div>`;
}
function animateRings(root) {
  requestAnimationFrame(() => requestAnimationFrame(() => {
    (root || document).querySelectorAll('.ring-fg[data-off]').forEach(el => el.style.strokeDashoffset = el.dataset.off);
    (root || document).querySelectorAll('.bar i[data-w]').forEach(el => el.style.width = el.dataset.w);
  }));
}
function animateNumbers(root) {
  (root || document).querySelectorAll('[data-count]').forEach(el => {
    const v = parseFloat(el.dataset.count) || 0, t0 = performance.now(), dur = 850;
    const step = t => {
      const p = Math.min(1, (t - t0) / dur);
      el.textContent = Math.round(v * (1 - Math.pow(1 - p, 3))).toLocaleString();
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  });
}

function lineChart(points, { color = '#10b981', w = 340, h = 150, unit = '', goal = null } = {}) {
  if (!points || points.length === 0) return `<div class="empty"><div class="em">${ic('chart', 34)}</div>No data yet — start logging</div>`;
  const vals = points.map(p => p.v);
  let mn = Math.min(...vals, goal ?? Infinity), mx = Math.max(...vals, goal ?? -Infinity);
  if (mn === mx) { mn -= 1; mx += 1; }
  const pad = (mx - mn) * 0.12; mn -= pad; mx += pad;
  const px = 34, py = 12, cw = w - px - 8, ch = h - py - 26;
  const X = i => px + (points.length === 1 ? cw / 2 : i / (points.length - 1) * cw);
  const Y = v => py + ch - (v - mn) / (mx - mn) * ch;
  let path = '', area = '';
  points.forEach((p, i) => {
    path += (i ? ' L' : 'M') + X(i).toFixed(1) + ' ' + Y(p.v).toFixed(1);
  });
  area = path + ` L${X(points.length-1).toFixed(1)} ${py+ch} L${X(0).toFixed(1)} ${py+ch} Z`;
  const gid = 'g' + Math.floor(Y(vals[0]) * 997);
  const ticks = [mn + pad, (mn + mx) / 2, mx - pad].map(v =>
    `<text x="${px-6}" y="${Y(v)+4}" font-size="10" fill="var(--text3)" text-anchor="end">${round1(v)}</text>
     <line x1="${px}" y1="${Y(v)}" x2="${w-8}" y2="${Y(v)}" stroke="var(--border)" stroke-width="1"/>`).join('');
  const goalLine = goal != null && goal >= mn && goal <= mx
    ? `<line x1="${px}" y1="${Y(goal)}" x2="${w-8}" y2="${Y(goal)}" stroke="var(--orange)" stroke-width="1.5" stroke-dasharray="5 4"/>
       <text x="${w-8}" y="${Y(goal)-5}" font-size="10" fill="var(--orange)" text-anchor="end">goal</text>` : '';
  const dots = points.length <= 40 ? points.map((p, i) =>
    `<circle cx="${X(i)}" cy="${Y(p.v)}" r="3" fill="${color}"/>`).join('') : '';
  const lastLbl = `<text x="${X(points.length-1)}" y="${Y(vals[vals.length-1])-8}" font-size="11" font-weight="700" fill="${color}" text-anchor="end">${round1(vals[vals.length-1])}${unit}</text>`;
  const xl = [0, points.length - 1].map(i =>
    `<text x="${X(i)}" y="${h-6}" font-size="10" fill="var(--text3)" text-anchor="${i ? 'end' : 'start'}">${points[i].l}</text>`).join('');
  return `<svg viewBox="0 0 ${w} ${h}">
    <defs><linearGradient id="${gid}" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="${color}" stop-opacity="0.25"/><stop offset="1" stop-color="${color}" stop-opacity="0"/>
    </linearGradient></defs>
    ${ticks}${goalLine}
    <path d="${area}" fill="url(#${gid})"/>
    <path d="${path}" fill="none" stroke="${color}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
    ${dots}${lastLbl}${xl}
  </svg>`;
}

function barChart(points, { color = '#3b82f6', w = 340, h = 140, goal = null } = {}) {
  if (!points || !points.length) return `<div class="empty"><div class="em">${ic('chart', 34)}</div>No data yet</div>`;
  const mx = Math.max(...points.map(p => p.v), goal || 0) * 1.1 || 1;
  const px = 6, ch = h - 24, bw = Math.min(26, (w - px * 2) / points.length - 4);
  const bars = points.map((p, i) => {
    const x = px + i * ((w - px * 2) / points.length) + 2;
    const bh = Math.max(2, p.v / mx * ch);
    return `<rect x="${x}" y="${ch - bh + 8}" width="${bw}" height="${bh}" rx="4" fill="${p.v && goal && p.v > goal ? 'var(--orange)' : color}" opacity="0.9"/>`;
  }).join('');
  const goalLine = goal ? `<line x1="0" y1="${ch - goal/mx*ch + 8}" x2="${w}" y2="${ch - goal/mx*ch + 8}" stroke="var(--text3)" stroke-dasharray="4 4" stroke-width="1"/>` : '';
  const xl = [0, points.length - 1].map(i => {
    const x = px + i * ((w - px * 2) / points.length) + 2 + bw / 2;
    return `<text x="${x}" y="${h-2}" font-size="10" fill="var(--text3)" text-anchor="middle">${points[i].l}</text>`;
  }).join('');
  return `<svg viewBox="0 0 ${w} ${h}">${goalLine}${bars}${xl}</svg>`;
}

// ── Icon system: Lucide (https://lucide.dev, ISC license), embedded ──
const LUCIDE = {
  home: '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" /> <path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />',
  journal: '<path d="M13.4 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7.4" /> <path d="M2 6h4" /> <path d="M2 10h4" /> <path d="M2 14h4" /> <path d="M2 18h4" /> <path d="M21.378 5.626a1 1 0 1 0-3.004-3.004l-5.01 5.012a2 2 0 0 0-.506.854l-.837 2.87a.5.5 0 0 0 .62.62l2.87-.837a2 2 0 0 0 .854-.506z" />',
  timer: '<line x1="10" x2="14" y1="2" y2="2" /> <line x1="12" x2="15" y1="14" y2="11" /> <circle cx="12" cy="14" r="8" />',
  flame: '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z" />',
  utensils: '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2" /> <path d="M7 2v20" /> <path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7" />',
  droplet: '<path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z" />',
  scale: '<circle cx="12" cy="5" r="3" /> <path d="M6.5 8a2 2 0 0 0-1.905 1.46L2.1 18.5A2 2 0 0 0 4 21h16a2 2 0 0 0 1.925-2.54L19.4 9.5A2 2 0 0 0 17.48 8Z" />',
  compass: '<path d="m16.24 7.76-1.804 5.411a2 2 0 0 1-1.265 1.265L7.76 16.24l1.804-5.411a2 2 0 0 1 1.265-1.265z" /> <circle cx="12" cy="12" r="10" />',
  chart: '<path d="M3 3v16a2 2 0 0 0 2 2h16" /> <path d="M18 17V9" /> <path d="M13 17V5" /> <path d="M8 17v-3" />',
  heartpulse: '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z" /> <path d="M3.22 12H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27" />',
  dumbbell: '<path d="M17.596 12.768a2 2 0 1 0 2.829-2.829l-1.768-1.767a2 2 0 0 0 2.828-2.829l-2.828-2.828a2 2 0 0 0-2.829 2.828l-1.767-1.768a2 2 0 1 0-2.829 2.829z" /> <path d="m2.5 21.5 1.4-1.4" /> <path d="m20.1 3.9 1.4-1.4" /> <path d="M5.343 21.485a2 2 0 1 0 2.829-2.828l1.767 1.768a2 2 0 1 0 2.829-2.829l-6.364-6.364a2 2 0 1 0-2.829 2.829l1.768 1.767a2 2 0 0 0-2.828 2.829z" /> <path d="m9.6 14.4 4.8-4.8" />',
  camera: '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z" /> <circle cx="12" cy="13" r="3" />',
  barcode: '<path d="M3 5v14" /> <path d="M8 5v14" /> <path d="M12 5v14" /> <path d="M17 5v14" /> <path d="M21 5v14" />',
  search: '<path d="m21 21-4.34-4.34" /> <circle cx="11" cy="11" r="8" />',
  pencil: '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z" /> <path d="m15 5 4 4" />',
  star: '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />',
  copy: '<rect width="14" height="14" x="8" y="8" rx="2" ry="2" /> <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />',
  chevron: '<path d="m9 18 6-6-6-6" />',
  lock: '<rect width="18" height="11" x="3" y="11" rx="2" ry="2" /> <path d="M7 11V7a5 5 0 0 1 10 0v4" />',
  check: '<path d="M20 6 9 17l-5-5" />',
  checkcircle: '<circle cx="12" cy="12" r="10" /> <path d="m9 12 2 2 4-4" />',
  sun: '<circle cx="12" cy="12" r="4" /> <path d="M12 2v2" /> <path d="M12 20v2" /> <path d="m4.93 4.93 1.41 1.41" /> <path d="m17.66 17.66 1.41 1.41" /> <path d="M2 12h2" /> <path d="M20 12h2" /> <path d="m6.34 17.66-1.41 1.41" /> <path d="m19.07 4.93-1.41 1.41" />',
  moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z" />',
  sunrise: '<path d="M12 2v8" /> <path d="m4.93 10.93 1.41 1.41" /> <path d="M2 18h2" /> <path d="M20 18h2" /> <path d="m19.07 10.93-1.41 1.41" /> <path d="M22 22H2" /> <path d="m8 6 4-4 4 4" /> <path d="M16 18a4 4 0 0 0-8 0" />',
  cookie: '<path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5" /> <path d="M8.5 8.5v.01" /> <path d="M16 15.5v.01" /> <path d="M12 12v.01" /> <path d="M11 17v.01" /> <path d="M7 14v.01" />',
  bed: '<path d="M2 4v16" /> <path d="M2 8h18a2 2 0 0 1 2 2v10" /> <path d="M2 17h20" /> <path d="M6 8v9" />',
  activity: '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2" />',
  calendar: '<path d="M8 2v4" /> <path d="M16 2v4" /> <rect width="18" height="18" x="3" y="4" rx="2" /> <path d="M3 10h18" />',
  bulb: '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5" /> <path d="M9 18h6" /> <path d="M10 22h4" />',
  trophy: '<path d="M10 14.66v1.626a2 2 0 0 1-.976 1.696A5 5 0 0 0 7 21.978" /> <path d="M14 14.66v1.626a2 2 0 0 0 .976 1.696A5 5 0 0 1 17 21.978" /> <path d="M18 9h1.5a1 1 0 0 0 0-5H18" /> <path d="M4 22h16" /> <path d="M6 9a6 6 0 0 0 12 0V3a1 1 0 0 0-1-1H7a1 1 0 0 0-1 1z" /> <path d="M6 9H4.5a1 1 0 0 1 0-5H6" />',
  chefhat: '<path d="M17 21a1 1 0 0 0 1-1v-5.35c0-.457.316-.844.727-1.041a4 4 0 0 0-2.134-7.589 5 5 0 0 0-9.186 0 4 4 0 0 0-2.134 7.588c.411.198.727.585.727 1.041V20a1 1 0 0 0 1 1Z" /> <path d="M6 17h12" />',
  gear: '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" /> <circle cx="12" cy="12" r="3" />',
  user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /> <circle cx="12" cy="7" r="4" />',
  bell: '<path d="M10.268 21a2 2 0 0 0 3.464 0" /> <path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326" />',
  sparkle: '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" /> <path d="M20 3v4" /> <path d="M22 5h-4" /> <path d="M4 17v2" /> <path d="M5 18H3" />',
  target: '<circle cx="12" cy="12" r="10" /> <circle cx="12" cy="12" r="6" /> <circle cx="12" cy="12" r="2" />',
  mail: '<path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7" /> <rect x="2" y="4" width="20" height="16" rx="2" />',
  trenddown: '<path d="M16 17h6v-6" /> <path d="m22 17-8.5-8.5-5 5L2 7" />',
  download: '<path d="M12 15V3" /> <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /> <path d="m7 10 5 5 5-5" />',
  leaf: '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z" /> <path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12" />',
  coffee: '<path d="M10 2v2" /> <path d="M14 2v2" /> <path d="M16 8a1 1 0 0 1 1 1v8a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V9a1 1 0 0 1 1-1h14a4 4 0 1 1 0 8h-1" /> <path d="M6 2v2" />',
  glucose: '<path d="M7 16.3c2.2 0 4-1.83 4-4.05 0-1.16-.57-2.26-1.71-3.19S7.29 6.75 7 5.3c-.29 1.45-1.14 2.84-2.29 3.76S3 11.1 3 12.25c0 2.22 1.8 4.05 4 4.05z" /> <path d="M12.56 6.6A10.97 10.97 0 0 0 14 3.02c.5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a6.98 6.98 0 0 1-11.91 4.97" />',
  x: '<path d="M18 6 6 18" /> <path d="m6 6 12 12" />',
  plus: '<path d="M5 12h14" /> <path d="M12 5v14" />',
};
const ic = (n, s = 18, sw = 1.8) =>
  `<svg class="ic" width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${sw}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${LUCIDE[n] || ''}</svg>`;

// Bottom-nav icons
const IC = {
  home: ic('home', 23),
  diary: ic('journal', 23),
  fast: ic('timer', 23),
  more: '<svg viewBox="0 0 24 24" width="23" height="23" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="5" cy="12" r="1.4" fill="currentColor"/><circle cx="12" cy="12" r="1.4" fill="currentColor"/><circle cx="19" cy="12" r="1.4" fill="currentColor"/></svg>',
};

// ── Sheets (bottom modals) ────────────────────────────────────────────
function openSheet(html) {
  closeSheet();
  const veil = document.createElement('div');
  veil.className = 'sheet-veil'; veil.onclick = closeSheet;
  const sh = document.createElement('div');
  sh.className = 'sheet'; sh.innerHTML = '<div class="sheet-handle"></div>' + html;
  document.body.append(veil, sh);
  return sh;
}
function closeSheet() {
  document.querySelectorAll('.sheet-veil,.sheet').forEach(e => e.remove());
  if (S._camStream) { S._camStream.getTracks().forEach(t => t.stop()); S._camStream = null; }
  clearInterval(S._bcTimer);
}

// ══ AUTH ══════════════════════════════════════════════════════════════
function renderAuth(mode = 'login') {
  app().innerHTML = `
  <div class="auth-wrap">
    <div class="auth-hero">
      <div class="logo brandmark">${ic('heartpulse', 34, 1.6)}</div>
      <h1>VitaTrack</h1>
      <p>Calories · Keto · Fasting · Workouts<br>Your personal weight-loss companion</p>
    </div>
    <div class="card" style="animation-delay:.1s">
      <div id="authErr"></div>
      ${mode === 'register' ? `<div class="field"><label>Your name</label><input id="aName" placeholder="e.g. Mohammad" autocomplete="name"></div>` : ''}
      <div class="field"><label>Email</label><input id="aEmail" type="email" placeholder="you@email.com" autocomplete="email" inputmode="email"></div>
      <div class="field"><label>Password</label><input id="aPass" type="password" placeholder="${mode === 'register' ? 'At least 6 characters' : 'Your password'}" autocomplete="${mode === 'register' ? 'new-password' : 'current-password'}"></div>
      <button class="btn" id="aGo">${mode === 'register' ? 'Create my account' : 'Sign in'}</button>
    </div>
    <div class="auth-toggle">
      ${mode === 'register' ? 'Already have an account? <b id="aSwap">Sign in</b>' : 'New here? <b id="aSwap">Create an account</b>'}
    </div>
  </div>`;
  $('#aSwap').onclick = () => renderAuth(mode === 'register' ? 'login' : 'register');
  const go = async () => {
    const btn = $('#aGo'); btn.disabled = true; btn.textContent = 'Please wait…';
    const body = { email: $('#aEmail').value, password: $('#aPass').value };
    if (mode === 'register') body.name = $('#aName').value;
    const r = await api(mode, body);
    if (!r.ok) {
      $('#authErr').innerHTML = `<div class="error-msg">${esc(r.error)}</div>`;
      btn.disabled = false; btn.textContent = mode === 'register' ? 'Create my account' : 'Sign in';
      return;
    }
    S.user = r.user; S.profile = r.profile; S.settings = r.settings || {};
    applyTheme(); render();
  };
  $('#aGo').onclick = go;
  $('#aPass').addEventListener('keydown', e => e.key === 'Enter' && go());
}

// ══ ONBOARDING ════════════════════════════════════════════════════════
const OB = { step: 0, data: {} };
const ISSUES = [
  ['back', 'Back problems'], ['knee', 'Knee pain'], ['shoulder', 'Shoulder issues'],
  ['heart', 'Heart condition'], ['diabetes', 'Diabetes / pre-diabetes'],
  ['hypertension', 'High blood pressure'], ['none', 'None'],
];
const ACTIVITIES = [
  [1.2, 'Sedentary', 'Desk job, little exercise'],
  [1.375, 'Lightly active', 'Light exercise 1-3 days/week'],
  [1.55, 'Moderately active', 'Exercise 3-5 days/week'],
  [1.725, 'Very active', 'Hard exercise 6-7 days/week'],
];

function renderOnboarding() {
  const d = OB.data;
  if (OB.step === 0 && !d.units) {
    Object.assign(d, {
      units: S.profile.units || 'metric', sex: S.profile.sex || 'male',
      birth_year: S.profile.birth_year || 1985, height_cm: S.profile.height_cm || 175,
      start_weight_kg: S.profile.start_weight_kg || '', goal_weight_kg: S.profile.goal_weight_kg || '',
      body_fat: S.profile.body_fat || '', activity: S.profile.activity || 1.375,
      weekly_rate: S.profile.weekly_rate || 0.5, diet: S.profile.diet || 'keto',
      fasting_plan: S.profile.fasting_plan || '16:8',
      health_issues: JSON.parse(S.profile.health_issues || '[]'),
    });
  }
  const imp = d.units === 'imperial';
  const steps = [stepBasics, stepWeight, stepActivity, stepDiet, stepIssues];
  const bar = `<div class="ob-progress">${steps.map((_, i) => `<span class="${i <= OB.step ? 'done' : ''}"></span>`).join('')}</div>`;

  function stepBasics() {
    const hFt = Math.floor((d.height_cm || 175) / 30.48);
    const hIn = Math.round(((d.height_cm || 175) / 2.54) % 12);
    return `
    <div class="ob-step">
      <h2>Let's get to know you</h2>
      <p class="sub">We'll use this to calculate your personal calorie and macro plan.</p>
      <div class="field"><label>Units</label>
        <div class="seg" id="segUnits">
          <button data-v="metric" class="${!imp ? 'on' : ''}">Metric (kg, cm)</button>
          <button data-v="imperial" class="${imp ? 'on' : ''}">Imperial (lb, ft)</button>
        </div></div>
      <div class="field"><label>Biological sex <span class="tiny">(affects calorie math)</span></label>
        <div class="seg" id="segSex">
          <button data-v="male" class="${d.sex === 'male' ? 'on' : ''}">Male</button>
          <button data-v="female" class="${d.sex === 'female' ? 'on' : ''}">Female</button>
        </div></div>
      <div class="grid2">
        <div class="field"><label>Birth year</label><input id="obYear" type="number" inputmode="numeric" value="${d.birth_year}"></div>
        ${imp
          ? `<div class="field"><label>Height</label><div class="row">
               <input id="obFt" type="number" inputmode="numeric" value="${hFt}" style="width:50%"> <span class="muted">ft</span>
               <input id="obIn" type="number" inputmode="numeric" value="${hIn}" style="width:50%"> <span class="muted">in</span>
             </div></div>`
          : `<div class="field"><label>Height (cm)</label><input id="obH" type="number" inputmode="decimal" value="${d.height_cm}"></div>`}
      </div>
    </div>`;
  }
  function stepWeight() {
    return `
    <div class="ob-step">
      <h2>Your weight</h2>
      <p class="sub">Body fat % is optional — a smart scale or estimate works fine.</p>
      <div class="field"><label>Current weight (${wU()})</label><input id="obW" type="number" inputmode="decimal" value="${cvtOut(d.start_weight_kg)}" placeholder="e.g. ${imp ? 200 : 90}"></div>
      <div class="field"><label>Goal weight (${wU()})</label><input id="obGW" type="number" inputmode="decimal" value="${cvtOut(d.goal_weight_kg)}" placeholder="e.g. ${imp ? 165 : 75}"></div>
      <div class="field"><label>Body fat % <span class="tiny">(optional)</span></label><input id="obBF" type="number" inputmode="decimal" value="${d.body_fat || ''}" placeholder="e.g. 28"></div>
    </div>`;
  }
  function stepActivity() {
    return `
    <div class="ob-step">
      <h2>How active are you?</h2>
      <p class="sub">Be honest — this sets your daily calorie budget.</p>
      ${ACTIVITIES.map(([v, t, s]) => `
        <div class="card" data-act="${v}" style="cursor:pointer;padding:14px;margin-bottom:10px;${+d.activity === v ? 'border-color:var(--accent);background:var(--accent-soft)' : ''}">
          <b>${t}</b><div class="muted">${s}</div>
        </div>`).join('')}
      <div class="field"><label>How fast do you want to lose?</label>
        <div class="seg" id="segRate">
          <button data-v="0.25" class="${+d.weekly_rate === 0.25 ? 'on' : ''}">Relaxed<br><span class="tiny">${imp ? '½ lb' : '¼ kg'}/wk</span></button>
          <button data-v="0.5" class="${+d.weekly_rate === 0.5 ? 'on' : ''}">Steady<br><span class="tiny">${imp ? '1 lb' : '½ kg'}/wk</span></button>
          <button data-v="0.75" class="${+d.weekly_rate === 0.75 ? 'on' : ''}">Ambitious<br><span class="tiny">${imp ? '1½ lb' : '¾ kg'}/wk</span></button>
        </div></div>
    </div>`;
  }
  function stepDiet() {
    return `
    <div class="ob-step">
      <h2>Diet & fasting style</h2>
      <p class="sub">You can change these anytime in Settings.</p>
      <div class="field"><label>Eating style</label>
        <div class="seg" id="segDiet">
          <button data-v="keto" class="${d.diet === 'keto' ? 'on' : ''}">Keto</button>
          <button data-v="lowcarb" class="${d.diet === 'lowcarb' ? 'on' : ''}">Low-carb</button>
          <button data-v="balanced" class="${d.diet === 'balanced' ? 'on' : ''}">Balanced</button>
        </div></div>
      <div class="field"><label>Intermittent fasting plan</label>
        <div class="seg" id="segFast">
          <button data-v="16:8" class="${d.fasting_plan === '16:8' ? 'on' : ''}">16:8</button>
          <button data-v="18:6" class="${d.fasting_plan === '18:6' ? 'on' : ''}">18:6</button>
          <button data-v="20:4" class="${d.fasting_plan === '20:4' ? 'on' : ''}">20:4</button>
          <button data-v="none" class="${d.fasting_plan === 'none' ? 'on' : ''}">None</button>
        </div></div>
      <div class="fast-stage"><span class="em" style="color:var(--orange)">${ic('bulb', 22)}</span><span><b>16:8</b> means fasting 16 hours (e.g. 8pm → 12pm) and eating within an 8-hour window. It pairs beautifully with keto for fat loss.</span></div>
    </div>`;
  }
  function stepIssues() {
    return `
    <div class="ob-step">
      <h2>Any health considerations?</h2>
      <p class="sub">We'll adapt workout suggestions — e.g. back-safe, low-impact exercises only.</p>
      <div class="chips" id="chipsIssues">
        ${ISSUES.map(([k, t]) => `<button class="chip ${d.health_issues.includes(k) ? 'on' : ''}" data-k="${k}">${t}</button>`).join('')}
      </div>
      <div class="spacer"></div>
      <p class="tiny" style="margin-top:14px">VitaTrack gives general wellness guidance, not medical advice. Check with your doctor before starting a new diet or exercise program, especially with existing conditions.</p>
    </div>`;
  }
  function wU() { return imp ? 'lb' : 'kg'; }
  function cvtOut(kg) { return kg ? round1(imp ? kg2lb(kg) : +kg) : ''; }

  app().innerHTML = `<div class="screen" style="max-width:480px">${bar}${steps[OB.step]()}
    <div class="row" style="margin-top:20px">
      ${OB.step > 0 ? '<button class="btn secondary" id="obBack" style="width:110px">Back</button>' : ''}
      <button class="btn grow" id="obNext">${OB.step === steps.length - 1 ? 'Create my plan' : 'Continue'}</button>
    </div></div>`;

  // wiring
  const seg = (id, key, num) => {
    const el = $(id); if (!el) return;
    el.querySelectorAll('button').forEach(b => b.onclick = () => {
      d[key] = num ? parseFloat(b.dataset.v) : b.dataset.v;
      renderOnboarding();
    });
  };
  seg('#segUnits', 'units'); seg('#segSex', 'sex'); seg('#segRate', 'weekly_rate', true);
  seg('#segDiet', 'diet'); seg('#segFast', 'fasting_plan');
  document.querySelectorAll('[data-act]').forEach(c => c.onclick = () => { d.activity = parseFloat(c.dataset.act); renderOnboarding(); });
  const chips = $('#chipsIssues');
  if (chips) chips.querySelectorAll('.chip').forEach(ch => ch.onclick = () => {
    const k = ch.dataset.k;
    if (k === 'none') d.health_issues = ['none'];
    else {
      d.health_issues = d.health_issues.filter(x => x !== 'none');
      d.health_issues.includes(k) ? d.health_issues = d.health_issues.filter(x => x !== k) : d.health_issues.push(k);
    }
    renderOnboarding();
  });
  if ($('#obBack')) $('#obBack').onclick = () => { OB.step--; renderOnboarding(); };
  $('#obNext').onclick = async () => {
    // collect step inputs
    if (OB.step === 0) {
      d.birth_year = parseInt($('#obYear').value) || 1985;
      if (imp) d.height_cm = round1((parseInt($('#obFt').value || 5) * 12 + parseInt($('#obIn').value || 9)) * 2.54);
      else d.height_cm = parseFloat($('#obH').value) || 175;
    }
    if (OB.step === 1) {
      const w = parseFloat($('#obW').value), g = parseFloat($('#obGW').value);
      if (!w || !g) return toast('Please enter your current and goal weight');
      d.start_weight_kg = imp ? lb2kg(w) : w;
      d.goal_weight_kg = imp ? lb2kg(g) : g;
      d.body_fat = parseFloat($('#obBF').value) || '';
    }
    if (OB.step < steps.length - 1) { OB.step++; renderOnboarding(); return; }
    // finish
    $('#obNext').disabled = true; $('#obNext').textContent = 'Building your plan…';
    const r = await api('save_profile', d);
    if (!r.ok) { toast(r.error || 'Something went wrong'); $('#obNext').disabled = false; return; }
    S.profile = r.profile;
    showPlanReveal(r.targets);
  };
}

function showPlanReveal(t) {
  const p = S.profile;
  const lose = Math.max(0, (p.start_weight_kg - p.goal_weight_kg));
  const weeks = p.weekly_rate > 0 ? Math.ceil(lose / p.weekly_rate) : 0;
  const eta = new Date(Date.now() + weeks * 7 * 864e5);
  app().innerHTML = `<div class="screen" style="max-width:480px">
    <div class="card hero-card" style="text-align:center;padding:28px 18px">
      <div style="color:#fff;opacity:.9">${ic('target', 42, 1.5)}</div>
      <h2 style="font-size:24px;margin:8px 0 4px">Your plan is ready</h2>
      <div class="muted">Lose ${fmtW(lose)} by ~${eta.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}</div>
      <div class="hero-big" style="margin-top:14px">${t.kcal_target} <span style="font-size:16px;font-weight:600">kcal/day</span></div>
      <div class="muted">BMR ${t.bmr} · TDEE ${t.tdee} · BMI ${t.bmi}</div>
    </div>
    <div class="grid3">
      <div class="stat-tile"><div class="emoji" style="color:var(--blue)">${ic('dumbbell', 20)}</div><div class="v">${t.protein_g}g</div><div class="k">Protein</div></div>
      <div class="stat-tile"><div class="emoji" style="color:var(--purple)">${ic('droplet', 20)}</div><div class="v">${t.fat_g}g</div><div class="k">Fat</div></div>
      <div class="stat-tile"><div class="emoji" style="color:var(--orange)">${ic('leaf', 20)}</div><div class="v">${t.carbs_g}g</div><div class="k">${p.diet === 'keto' ? 'Net carbs' : 'Carbs'}</div></div>
    </div>
    <div class="card">
      <div class="card-title"><span class="icon" style="background:var(--blue-soft);color:var(--blue)">${ic('droplet', 16)}</span>Daily water goal</div>
      <b style="font-size:22px">${(t.water_ml / 1000).toFixed(1)} L</b> <span class="muted">≈ ${Math.round(t.water_ml / 250)} glasses</span>
    </div>
    <button class="btn" id="planGo">Let's go</button>
  </div>`;
  $('#planGo').onclick = () => { S.view = 'home'; render(); };
}

// ══ NAV & SHELL ═══════════════════════════════════════════════════════
function shell(content) {
  app().innerHTML = content + `
  <nav class="bottom-nav">
    <button data-v="home" class="${S.view === 'home' ? 'on' : ''}">${IC.home}<span>Home</span></button>
    <button data-v="diary" class="${S.view === 'diary' ? 'on' : ''}">${IC.diary}<span>Diary</span></button>
    <button class="nav-fab" id="fabAdd" title="Add food">${ic('plus', 25, 2.4)}</button>
    <button data-v="fast" class="${S.view === 'fast' ? 'on' : ''}">${IC.fast}<span>Fasting</span></button>
    <button data-v="more" class="${['more','progress','recipes','workouts','settings','learn'].includes(S.view) ? 'on' : ''}">${IC.more}<span>More</span></button>
  </nav>`;
  document.querySelectorAll('.bottom-nav [data-v]').forEach(b => b.onclick = () => { S.view = b.dataset.v; render(); });
  $('#fabAdd').onclick = () => openAddSheet();
  animateRings();
  animateNumbers();
}

// ══ HOME (dashboard) ═════════════════════════════════════════════════
function sparkline(vals, { w = 130, h = 34, color = 'var(--accent)' } = {}) {
  if (!vals || vals.length < 2) return '';
  let mn = Math.min(...vals), mx = Math.max(...vals);
  if (mn === mx) { mn -= 1; mx += 1; }
  const X = i => 2 + i / (vals.length - 1) * (w - 4);
  const Y = v => 3 + (h - 6) * (1 - (v - mn) / (mx - mn));
  const d = vals.map((v, i) => (i ? 'L' : 'M') + X(i).toFixed(1) + ' ' + Y(v).toFixed(1)).join(' ');
  return `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">
    <path d="${d}" fill="none" stroke="${color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    <circle cx="${X(vals.length - 1)}" cy="${Y(vals[vals.length - 1])}" r="3" fill="${color}"/></svg>`;
}

async function renderHome() {
  const p = S.profile;
  const [day, wr] = await Promise.all([api('day', { date: todayStr() }), api('weights')]);
  S.day = day;
  const weights = wr.weights || [];
  const eaten = day.entries.reduce((a, e) => a + +e.kcal, 0);
  const protein = day.entries.reduce((a, e) => a + +e.protein, 0);
  const carbs = day.entries.reduce((a, e) => a + +e.carbs, 0);
  const fiber = day.entries.reduce((a, e) => a + +e.fiber, 0);
  const fat = day.entries.reduce((a, e) => a + +e.fat, 0);
  const burned = day.workouts.reduce((a, w) => a + +w.kcal, 0);
  const netCarbs = Math.max(0, carbs - fiber);
  const target = p.kcal_target || 1800;
  const remaining = Math.round(target - eaten + burned);
  const hour = new Date().getHours();
  const greet = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
  const carbLbl = p.diet === 'keto' ? 'Net carbs' : 'Carbs';

  // hero macro mini-bars (white-on-dark)
  const overCarb = netCarbs > (p.carbs_g || 25);
  const hmacro = (name, val, tgt, warn) => `
    <div class="hmacro">
      <div class="row"><span>${name}</span><span>${Math.round(val)}<em> / ${tgt}g</em></span></div>
      <div class="hbar"><i data-w="${Math.min(100, val / (tgt || 1) * 100)}%" style="${warn ? 'background:#ffb3a3' : ''}"></i></div>
    </div>`;

  const score = (() => {
    let s = 0;
    if (day.entries.length) s += 25;
    if (eaten > 0 && eaten <= target * 1.05) s += 25;
    if (protein >= (p.protein_g || 100) * 0.9) s += 20;
    if (day.water >= (p.water_ml || 2500)) s += 15;
    if (day.entries.length && netCarbs <= (p.carbs_g || 25)) s += 15;
    return s;
  })();

  const wl = day.last_weight != null ? +day.last_weight : p.start_weight_kg;
  const lost = p.start_weight_kg && wl ? p.start_weight_kg - wl : 0;
  const toGo = wl && p.goal_weight_kg ? Math.max(0, wl - p.goal_weight_kg) : 0;

  // status tiles: fasting + water
  const fastTile = p.fasting_plan === 'none' ? '' : day.active_fast
    ? `<div class="card tile" onclick="S.view='fast';render()">
        <div class="tile-head" style="color:var(--purple)">${ic('timer', 14)} Fasting now</div>
        <b class="tile-big" id="homeFastTime">…</b>
        <div class="bar" style="margin-top:8px"><i data-w="100%" style="background:var(--purple);width:0"></i></div>
        <div class="tiny" style="margin-top:6px">of ${day.active_fast.target_hours}h — tap to view</div>
      </div>`
    : `<div class="card tile" onclick="startFastQuick()">
        <div class="tile-head" style="color:var(--purple)">${ic('timer', 14)} Fasting</div>
        <b class="tile-big" style="font-size:16px;color:var(--text2)">Not fasting</b>
        <div class="tiny" style="margin-top:6px;color:var(--purple);font-weight:650">Tap to start ${esc(p.fasting_plan)}</div>
      </div>`;
  const waterPct = Math.min(100, day.water / (p.water_ml || 2500) * 100);
  const waterTile = `<div class="card tile" ${fastTile ? '' : 'style="grid-column:1/-1"'} onclick="addWater(250)">
      <div class="tile-head" style="color:var(--blue)">${ic('droplet', 14)} Water</div>
      <b class="tile-big">${(day.water / 1000).toFixed(2)}<span style="font-size:12px;font-weight:600;color:var(--text2)"> / ${((p.water_ml || 2500) / 1000).toFixed(1)} L</span></b>
      <div class="bar" style="margin-top:8px"><i data-w="${waterPct}%" style="background:var(--blue);width:0"></i></div>
      <div class="tiny" style="margin-top:6px">Tap for +250 ml · <u onclick="event.stopPropagation();addWater(-250)">undo</u></div>
    </div>`;

  shell(`<div class="screen">
    <div class="screen-header">
      <div><div class="screen-sub">${new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' })}</div>
      <div class="screen-title">${greet}, ${esc((S.user.name || '').split(' ')[0])}</div></div>
      <button class="avatar" onclick="S.view='settings';render()" title="Settings">${esc((S.user.name || 'U')[0].toUpperCase())}</button>
    </div>

    <div class="card calorie-hero ${remaining < 0 ? 'over' : ''}" onclick="S.view='diary';render()" style="cursor:pointer">
      <div class="hero-flex">
        ${ringSVG(136, 11, Math.min(1, eaten / target), '#ffffff',
          `<div class="big" style="font-size:26px" data-count="${Math.abs(remaining)}">0</div><div class="lbl">${remaining >= 0 ? 'kcal left' : 'kcal over'}</div>`,
          'rgba(255,255,255,0.25)')}
        <div class="hero-macros">
          ${hmacro('Protein', protein, p.protein_g || 100)}
          ${hmacro(carbLbl, netCarbs, p.carbs_g || 25, overCarb)}
          ${hmacro('Fat', fat, p.fat_g || 120)}
        </div>
      </div>
      <div class="hero-foot">
        <div><b data-count="${Math.round(eaten)}">0</b><span>eaten</span></div>
        <div><b data-count="${Math.round(burned)}">0</b><span>burned</span></div>
        <div class="score-pill">${ic('target', 14)} <b data-count="${score}">0</b>/100</div>
      </div>
      ${p.diet === 'keto' && overCarb ? '<div class="tiny" style="color:#ffd9d0;margin-top:10px;text-align:left">Over your keto carb limit — go zero-carb for the rest of today.</div>' : ''}
    </div>

    <div class="quick-row">
      <button class="quick-btn" onclick="openAddSheet()">${ic('utensils', 20)}<span>Food</span></button>
      <button class="quick-btn" onclick="openAddSheet(null,'barcode')">${ic('barcode', 20)}<span>Scan</span></button>
      <button class="quick-btn" onclick="addWater(250)">${ic('droplet', 20)}<span>+250 ml</span></button>
      <button class="quick-btn" onclick="openWeightSheet()">${ic('scale', 20)}<span>Weigh</span></button>
    </div>

    <div class="grid2">${fastTile}${waterTile}</div>

    <div class="card" onclick="S.view='progress';render()" style="cursor:pointer">
      <div class="row">
        <span class="card-title" style="margin:0"><span class="icon" style="background:var(--orange-soft);color:var(--orange)">${ic('scale', 15)}</span></span>
        <div class="grow">
          <b style="font-size:17px">${fmtW(wl)}</b>
          ${lost > 0 ? `<span style="color:var(--accent);font-weight:700;font-size:13.5px;margin-left:6px">−${fmtW(lost)}</span>` : ''}
          <div class="tiny">${toGo > 0 ? fmtW(toGo) + ' to goal' : 'Goal reached'}</div>
        </div>
        ${weights.length >= 2 ? sparkline(weights.slice(-14).map(w => +w.weight_kg), { w: 96, h: 30 }) : ''}
        <button class="btn small secondary" onclick="event.stopPropagation();openWeightSheet()">Log</button>
      </div>
    </div>

    <div id="coachCard"></div>
  </div>`);

  startHomeFastTicker(day.active_fast);
  renderCoach(day, { eaten, netCarbs, remaining });
}

function startHomeFastTicker(fast) {
  clearInterval(S.fastTimer);
  if (!fast) return;
  const start = parseUTC(fast.start_ts);
  const tick = () => {
    const el = $('#homeFastTime'); if (!el) { clearInterval(S.fastTimer); return; }
    el.textContent = fmtDur(Date.now() - start);
  };
  tick(); S.fastTimer = setInterval(tick, 1000);
}
const parseUTC = s => new Date(s.replace(' ', 'T') + 'Z').getTime();
const fmtDur = ms => {
  const s = Math.max(0, Math.floor(ms / 1000));
  return `${Math.floor(s / 3600)}h ${String(Math.floor(s / 60) % 60).padStart(2, '0')}m ${String(s % 60).padStart(2, '0')}s`;
};

async function renderCoach(day, m) {
  const el = $('#coachCard'); if (!el) return;
  if (!S.recipes) S.recipes = (await api('recipes')).recipes || [];
  if (!S.exercises) S.exercises = (await api('exercises')).exercises || [];
  const lr = await api('lessons');
  const nextLesson = (lr.lessons || []).find(l => !lr.reads[l.id]);
  const readToday = Object.values(lr.reads || {}).includes(lr.today);
  const lessonRow = nextLesson && !readToday
    ? `<div class="food-row" style="cursor:pointer" onclick="openLesson(${nextLesson.id})">
        <span style="color:var(--orange)">${ic('bulb', 22)}</span>
        <div class="grow"><div class="n">Today's lesson: ${esc(nextLesson.title)}</div>
        <div class="d">2-min read · ${esc(nextLesson.category)} · lesson ${nextLesson.ord} of ${lr.lessons.length}</div></div>
        <span class="tiny">read ›</span></div>`
    : nextLesson ? `<div class="muted" style="padding:8px 0">📚 Lesson done for today — next one unlocks tomorrow ✓</div>`
    : `<div class="muted" style="padding:8px 0">🏆 Course complete — all ${(lr.lessons || []).length} lessons read!</div>`;
  const issues = JSON.parse(S.profile.health_issues || '[]');
  const hour = new Date().getHours();
  const mealTag = hour < 10 ? 'breakfast' : hour < 15 ? 'lunch' : hour < 20 ? 'dinner' : 'snack';
  const pool = S.recipes.filter(r => r.tag === mealTag && (S.profile.diet !== 'keto' || +r.carbs <= 12));
  const rec = pool[new Date().getDate() % Math.max(1, pool.length)];
  let exPool = S.exercises.filter(e => e.difficulty !== 'hard');
  if (issues.includes('back')) exPool = exPool.filter(e => +e.back_safe);
  if (issues.includes('knee')) exPool = exPool.filter(e => +e.low_impact);
  const ex = exPool[new Date().getDate() % Math.max(1, exPool.length)];
  const workedOut = day.workouts.length > 0;
  el.innerHTML = `<div class="card">
    <div class="card-title"><span class="icon" style="background:var(--accent-soft);color:var(--accent)">${ic('compass', 16)}</span>Today's coach</div>
    ${lessonRow}
    ${rec ? `<div class="food-row" style="cursor:pointer" onclick='openRecipe(${rec.id})'>
      <span style="font-size:24px">${rec.emoji}</span>
      <div class="grow"><div class="n">For ${mealTag}: ${esc(rec.name)}</div>
      <div class="d">${Math.round(rec.kcal)} kcal · ${Math.round(rec.protein)}g protein · ${Math.round(rec.carbs)}g carbs</div></div>
      <span class="tiny">view ›</span></div>` : ''}
    ${ex && !workedOut ? `<div class="food-row" style="cursor:pointer" onclick='openExercise(${ex.id})'>
      <span style="color:var(--accent)">${ic(EX_ICONS[ex.category] || 'activity', 22)}</span>
      <div class="grow"><div class="n">Move today: ${esc(ex.name)}</div>
      <div class="d">~${ex.kcal30} kcal / 30 min${+ex.back_safe ? ' · back-safe ✓' : ''}</div></div>
      <span class="tiny">view ›</span></div>`
    : workedOut ? '<div class="muted" style="padding:8px 0">Workout logged today — nice work.</div>' : ''}
    ${m.remaining < 0 ? `<div class="fast-stage"><span class="em" style="color:var(--accent)">${ic('activity', 22)}</span><span>You're ${Math.abs(m.remaining)} kcal over budget — a ${Math.ceil(Math.abs(m.remaining) / 5)}-minute brisk walk would balance it out.</span></div>` : ''}
  </div>`;
}

window.addWater = async ml => {
  const r = await api('water', { delta: ml, date: todayStr() });
  if (r.ok) { toast(ml > 0 ? `+${ml} ml water logged` : 'Removed'); render(); }
};
window.startFastQuick = async () => {
  const hours = parseFloat((S.profile.fasting_plan || '16:8').split(':')[0]) || 16;
  await api('fast_start', { target_hours: hours });
  toast('Fast started — you got this'); render();
};

// ── Weight sheet ──────────────────────────────────────────────────────
window.openWeightSheet = () => {
  const sh = openSheet(`<h3>Log weight</h3>
    <div class="field"><label>Weight (${wUnit()})</label><input id="lwW" type="number" inputmode="decimal" value="${kg2input(S.day?.last_weight ?? S.profile.start_weight_kg)}"></div>
    <div class="field"><label>Body fat % <span class="tiny">(optional)</span></label><input id="lwBF" type="number" inputmode="decimal" placeholder="e.g. 27"></div>
    <div class="field"><label>Date</label><input id="lwD" type="date" value="${todayStr()}" max="${todayStr()}"></div>
    <button class="btn" id="lwGo">Save</button>`);
  sh.querySelector('#lwGo').onclick = async () => {
    const kg = inputW2kg(sh.querySelector('#lwW').value);
    if (!kg || isNaN(kg)) return toast('Enter a valid weight');
    const r = await api('log_weight', { weight_kg: kg, body_fat: sh.querySelector('#lwBF').value, date: sh.querySelector('#lwD').value });
    if (r.ok) { closeSheet(); toast('Weight saved ✓'); render(); }
    else toast(r.error);
  };
};

// ══ ADD FOOD SHEET (search / custom / photo) ══════════════════════════
function openAddSheet(meal, tab0) {
  meal = meal || (h => h < 10 ? 'breakfast' : h < 15 ? 'lunch' : h < 20 ? 'dinner' : 'snacks')(new Date().getHours());
  let tab = tab0 || 'search';
  const sh = openSheet(`
    <h3>Add food</h3>
    <div class="seg" id="addMeal" style="margin-bottom:12px">
      ${['breakfast', 'lunch', 'dinner', 'snacks'].map(m =>
        `<button data-v="${m}" class="${m === meal ? 'on' : ''}">${m[0].toUpperCase() + m.slice(1, m === 'breakfast' ? 5 : 20)}</button>`).join('')}
    </div>
    <div class="seg" id="addTab" style="margin-bottom:14px">
      ${[['search', 'search'], ['barcode', 'barcode'], ['photo', 'camera'], ['meals', 'star'], ['custom', 'pencil']].map(([t, icn]) =>
        `<button data-v="${t}" class="${t === tab ? 'on' : ''}">${ic(icn, 17)}</button>`).join('')}
    </div>
    <div class="tiny" id="addTabLbl" style="text-align:center;margin:-8px 0 12px"></div>
    <div id="addBody"></div>`);
  const getMeal = () => sh.querySelector('#addMeal .on').dataset.v;
  sh.querySelector('#addMeal').querySelectorAll('button').forEach(b => b.onclick = () => {
    sh.querySelectorAll('#addMeal button').forEach(x => x.classList.remove('on')); b.classList.add('on');
  });
  const TAB_LBL = { search: 'Search foods', barcode: 'Scan a barcode (free)', photo: 'AI photo scan', meals: 'My saved meals', custom: 'Custom food' };
  sh.querySelector('#addTabLbl').textContent = TAB_LBL[tab];
  sh.querySelector('#addTab').querySelectorAll('button').forEach(b => b.onclick = () => {
    sh.querySelectorAll('#addTab button').forEach(x => x.classList.remove('on')); b.classList.add('on');
    tab = b.dataset.v; sh.querySelector('#addTabLbl').textContent = TAB_LBL[tab]; body();
  });

  const body = () => {
    const el = sh.querySelector('#addBody');
    const foodRowHTML = (f, src) => `
      <div class="food-row">
        <div class="grow"><div class="n">${dot(densityClass(+f.kcal))} ${esc(f.name)}${+f.keto ? ' <span class="kmark" title="Keto-friendly">' + ic('leaf', 11) + '</span>' : ''}${f.user_id ? ' <span class="tiny">(mine)</span>' : ''}${src === 'off' ? ' <span class="tiny">· online</span>' : ''}</div>
          <div class="d">${Math.round(f.kcal)} kcal · P ${f.protein} · C ${f.carbs} · F ${f.fat} <span class="tiny">per 100g</span></div></div>
        <input type="number" inputmode="numeric" value="100" data-g class="grams-in"> <span class="tiny">g</span>
        <button class="btn small" data-add='${JSON.stringify({ name: f.name, kcal: +f.kcal, protein: +f.protein, carbs: +f.carbs, fat: +f.fat, fiber: +f.fiber || 0, sugar: +f.sugar || 0, sodium: +f.sodium || 0, satfat: +f.satfat || 0 }).replace(/'/g, '&#39;')}' data-src="${src || ''}">${ic('plus', 16, 2.4)}</button>
      </div>`;
    const wireAdd = container => {
      container.querySelectorAll('[data-add]').forEach(btn => btn.onclick = async () => {
        const f = JSON.parse(btn.dataset.add.replace(/&#39;/g, "'"));
        const g = parseFloat(btn.parentElement.querySelector('[data-g]').value) || 100;
        const k = g / 100;
        const r2 = await api('log_food', { date: todayStr(), meal: getMeal(), name: f.name, grams: g,
          kcal: f.kcal * k, protein: f.protein * k, carbs: f.carbs * k, fat: f.fat * k, fiber: f.fiber * k,
          sugar: (f.sugar || 0) * k, sodium: (f.sodium || 0) * k, satfat: (f.satfat || 0) * k });
        if (r2.ok) {
          toast(`${f.name} added`);
          btn.innerHTML = ic('check', 16, 2.4);
          setTimeout(() => { btn.innerHTML = ic('plus', 16, 2.4); }, 900);
          if (btn.dataset.src === 'off') api('add_food', f); // remember online finds in "my foods"
        }
      });
    };
    if (tab === 'search') {
      el.innerHTML = `<div class="field"><input id="fq" placeholder="Search foods… (e.g. chicken, avocado)" autocomplete="off"></div><div id="fRes"></div><div id="fOnlineWrap"></div>`;
      const doSearch = debounce(async () => {
        const q = el.querySelector('#fq').value.trim();
        const r = await api('foods', { q });
        el.querySelector('#fRes').innerHTML = (r.foods || []).map(f => foodRowHTML(f)).join('')
          || `<div class="empty"><div class="em">${ic('search', 34)}</div>Nothing local matches</div>`;
        el.querySelector('#fOnlineWrap').innerHTML = q.length >= 2
          ? `<button class="btn ghost small" id="fOnline">Search online database for “${esc(q)}”</button>` : '';
        wireAdd(el.querySelector('#fRes'));
        const ob = el.querySelector('#fOnline');
        if (ob) ob.onclick = async () => {
          ob.textContent = 'Searching…'; ob.disabled = true;
          const ro = await api('off_search', { q });
          const res = el.querySelector('#fRes');
          if (ro.ok && ro.foods.length) {
            res.innerHTML = ro.foods.map(f => foodRowHTML(f, 'off')).join('');
            el.querySelector('#fOnlineWrap').innerHTML = '<div class="tiny" style="text-align:center;padding:6px">Results from Open Food Facts · logged items are saved to “my foods”</div>';
            wireAdd(res);
          } else { ob.textContent = 'No online results'; }
        };
      }, 250);
      el.querySelector('#fq').addEventListener('input', doSearch);
      doSearch();
    }
    if (tab === 'barcode') {
      el.innerHTML = `
        <div id="bcCam" hidden>
          <video id="bcVideo" playsinline muted style="width:100%;max-height:250px;object-fit:cover;border-radius:16px;background:#000"></video>
          <div class="tiny" style="text-align:center;margin:8px 0" id="bcHint">Point the camera at a product barcode…</div>
        </div>
        <div class="row" style="margin-bottom:12px">
          <input id="bcManual" inputmode="numeric" placeholder="…or type the barcode digits" class="grow" style="background:var(--surface2);border:none;border-radius:13px;padding:13px 14px">
          <button class="btn small" id="bcGo">Look up</button>
        </div>
        <div id="bcOut"></div>`;
      const out = el.querySelector('#bcOut');
      const lookup = async code => {
        clearInterval(S._bcTimer);
        out.innerHTML = '<div class="skeleton" style="width:70%"></div><div class="skeleton" style="width:45%"></div>';
        const r = await api('barcode', { code });
        if (!r.ok) { out.innerHTML = `<div class="error-msg">${esc(r.error)}</div>`; startScan(); return; }
        out.innerHTML = foodRowHTML({ ...r.food }, 'off');
        wireAdd(out);
        if (navigator.vibrate) navigator.vibrate(60);
      };
      el.querySelector('#bcGo').onclick = () => {
        const c = el.querySelector('#bcManual').value.replace(/\D/g, '');
        if (c.length >= 6) lookup(c); else toast('Enter the digits under the barcode');
      };
      const startScan = async () => {
        if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
          el.querySelector('#bcHint')?.remove();
          out.innerHTML = out.innerHTML || '<div class="tiny" style="padding:4px 2px">📱 Live camera scanning isn\'t supported in this browser (iPhone Safari doesn\'t allow it yet) — just type the digits printed under the barcode above. Lookup itself works everywhere.</div>';
          return;
        }
        try {
          const det = new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128'] });
          const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
          S._camStream = stream;
          const v = el.querySelector('#bcVideo');
          el.querySelector('#bcCam').hidden = false;
          v.srcObject = stream; await v.play();
          clearInterval(S._bcTimer);
          S._bcTimer = setInterval(async () => {
            if (!document.body.contains(v)) { closeSheet(); return; }
            try {
              const codes = await det.detect(v);
              if (codes.length) lookup(codes[0].rawValue);
            } catch (e) { /* frame not ready */ }
          }, 350);
        } catch (e) {
          out.innerHTML = '<div class="tiny" style="padding:4px 2px">📷 Camera unavailable (permission denied?) — type the barcode digits above instead.</div>';
        }
      };
      startScan();
    }
    if (tab === 'meals') {
      el.innerHTML = '<div class="skeleton" style="width:70%"></div><div class="skeleton" style="width:45%"></div>';
      api('meals').then(r => {
        const meals = r.meals || [];
        el.innerHTML = meals.length ? meals.map(m => {
          const kc = m.items.reduce((a, i) => a + i.kcal, 0); // items store absolute values, as logged
          return `<div class="food-row">
            <div class="grow"><div class="n">${ic('star', 13)} ${esc(m.name)}</div>
              <div class="d">${m.items.length} items · ${Math.round(kc)} kcal — ${esc(m.items.map(i => i.name).join(', ')).slice(0, 70)}</div></div>
            <button class="btn small" data-logmeal="${m.id}">${ic('plus', 16, 2.4)}</button>
            <button class="del" data-delmeal="${m.id}">✕</button>
          </div>`;
        }).join('') : `<div class="empty"><div class="em">${ic('star', 34)}</div>No saved meals yet.<br><span class="tiny">In the Diary, tap the star on any meal you've logged to save it for one-tap logging here.</span></div>`;
        el.querySelectorAll('[data-logmeal]').forEach(b => b.onclick = async () => {
          const m = meals.find(x => +x.id === +b.dataset.logmeal);
          b.textContent = '…';
          for (const i of m.items) {
            await api('log_food', { date: todayStr(), meal: getMeal(), name: i.name, grams: i.grams,
              kcal: i.kcal, protein: i.protein, carbs: i.carbs, fat: i.fat, fiber: i.fiber,
              sugar: i.sugar || 0, sodium: i.sodium || 0, satfat: i.satfat || 0 });
          }
          toast(`${m.name} logged`); closeSheet(); render();
        });
        el.querySelectorAll('[data-delmeal]').forEach(b => b.onclick = async () => {
          await api('del_meal', { id: +b.dataset.delmeal }); body();
        });
      });
    }
    if (tab === 'custom') {
      el.innerHTML = `
        <div class="field"><label>Name</label><input id="cN" placeholder="e.g. Mom's kebab"></div>
        <div class="grid2">
          <div class="field"><label>Calories (kcal)</label><input id="cK" type="number" inputmode="decimal"></div>
          <div class="field"><label>Protein (g)</label><input id="cP" type="number" inputmode="decimal"></div>
          <div class="field"><label>Carbs (g)</label><input id="cC" type="number" inputmode="decimal"></div>
          <div class="field"><label>Fat (g)</label><input id="cF" type="number" inputmode="decimal"></div>
        </div>
        <label class="row tiny" style="margin-bottom:12px"><input type="checkbox" id="cSave" checked style="width:auto"> Save to my foods for next time (values per 100g)</label>
        <button class="btn" id="cGo">Add to diary</button>`;
      el.querySelector('#cGo').onclick = async () => {
        const f = { name: el.querySelector('#cN').value.trim(), kcal: +el.querySelector('#cK').value || 0,
          protein: +el.querySelector('#cP').value || 0, carbs: +el.querySelector('#cC').value || 0, fat: +el.querySelector('#cF').value || 0 };
        if (!f.name) return toast('Give it a name');
        await api('log_food', { ...f, date: todayStr(), meal: getMeal(), grams: 100, fiber: 0 });
        if (el.querySelector('#cSave').checked) await api('add_food', f);
        toast('✓ Added'); closeSheet(); render();
      };
    }
    if (tab === 'photo') {
      el.innerHTML = `
        <div class="scan-drop" id="scanDrop">
          <div style="color:var(--accent)">${ic('camera', 36)}</div>
          <b>Snap or choose a photo of your meal</b>
          <div class="tiny" style="margin-top:6px">AI estimates calories, protein, carbs & fat</div>
        </div>
        <input type="file" id="scanFile" accept="image/*" capture="environment" hidden>
        <div id="scanOut" style="margin-top:14px"></div>`;
      const file = el.querySelector('#scanFile');
      el.querySelector('#scanDrop').onclick = () => file.click();
      file.onchange = async () => {
        if (!file.files[0]) return;
        const dataUrl = await shrinkImage(file.files[0], 1100);
        const out = el.querySelector('#scanOut');
        out.innerHTML = `<img class="scan-preview" src="${dataUrl}">
          <div class="skeleton" style="width:70%"></div><div class="skeleton" style="width:45%"></div>
          <div class="muted" style="text-align:center">Analyzing your meal…</div>`;
        const r = await api('analyze_photo', { image: dataUrl });
        if (!r.ok) { out.innerHTML = `<img class="scan-preview" src="${dataUrl}"><div class="error-msg" style="margin-top:10px">${esc(r.error)}</div>`; return; }
        if (!r.items.length) { out.innerHTML = `<div class="empty"><div class="em">${ic('camera', 34)}</div>${esc(r.notes || 'No food detected')}</div>`; return; }
        const tot = r.items.reduce((a, i) => ({ kcal: a.kcal + i.kcal, protein: a.protein + i.protein, carbs: a.carbs + i.carbs, fat: a.fat + i.fat }), { kcal: 0, protein: 0, carbs: 0, fat: 0 });
        out.innerHTML = `<img class="scan-preview" src="${dataUrl}">
          ${r.items.map(i => `<div class="scan-item"><span>${esc(i.name)} <span class="tiny">${i.grams}g</span></span>
            <b>${i.kcal} kcal</b></div>`).join('')}
          <div class="scan-item" style="border:none"><b>Total</b><b>${Math.round(tot.kcal)} kcal · P${Math.round(tot.protein)} C${Math.round(tot.carbs)} F${Math.round(tot.fat)}</b></div>
          ${r.notes ? `<div class="tiny" style="margin:6px 0">${esc(r.notes)}</div>` : ''}
          <button class="btn" id="scanLog" style="margin-top:10px">Log all to ${getMeal()}</button>`;
        out.querySelector('#scanLog').onclick = async () => {
          for (const i of r.items) {
            await api('log_food', { date: todayStr(), meal: getMeal(), name: i.name, grams: i.grams,
              kcal: i.kcal, protein: i.protein, carbs: i.carbs, fat: i.fat, fiber: i.fiber,
              sugar: i.sugar || 0, sodium: i.sodium || 0, satfat: i.satfat || 0 });
          }
          toast('Meal logged'); closeSheet(); render();
        };
      };
    }
  };
  body();
}

function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

function shrinkImage(file, maxDim) {
  return new Promise(res => {
    const img = new Image();
    img.onload = () => {
      const sc = Math.min(1, maxDim / Math.max(img.width, img.height));
      const cv = document.createElement('canvas');
      cv.width = Math.round(img.width * sc); cv.height = Math.round(img.height * sc);
      cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
      res(cv.toDataURL('image/jpeg', 0.82));
      URL.revokeObjectURL(img.src);
    };
    img.src = URL.createObjectURL(file);
  });
}

// ══ DIARY ═════════════════════════════════════════════════════════════
async function renderDiary() {
  if (!S.diaryDate) S.diaryDate = todayStr();
  const day = await api('day', { date: S.diaryDate });
  const p = S.profile;
  const tot = day.entries.reduce((a, e) => ({ kcal: a.kcal + +e.kcal, protein: a.protein + +e.protein, carbs: a.carbs + +e.carbs, fat: a.fat + +e.fat, fiber: a.fiber + +e.fiber, sugar: a.sugar + +(e.sugar || 0), sodium: a.sodium + +(e.sodium || 0), satfat: a.satfat + +(e.satfat || 0) }), { kcal: 0, protein: 0, carbs: 0, fat: 0, fiber: 0, sugar: 0, sodium: 0, satfat: 0 });
  const meals = { breakfast: [ic('sunrise', 16), 'Breakfast'], lunch: [ic('sun', 16), 'Lunch'], dinner: [ic('moon', 16), 'Dinner'], snacks: [ic('cookie', 16), 'Snacks'] };
  const isToday = S.diaryDate === todayStr();

  // 7-day strip ending today
  const strip = Array.from({ length: 7 }, (_, i) => {
    const ds = shiftDate(todayStr(), i - 6);
    const dd = new Date(ds + 'T12:00:00');
    return `<button class="day-pill ${ds === S.diaryDate ? 'on' : ''}" data-day="${ds}">
      <span>${dd.toLocaleDateString(undefined, { weekday: 'short' })[0]}</span><b>${dd.getDate()}</b></button>`;
  }).join('');

  // Noom-style color split by calories
  const split = { g: 0, y: 0, o: 0 };
  day.entries.forEach(e => { if (+e.grams >= 20) split[densityClass(+e.kcal / +e.grams * 100)] += +e.kcal; });
  const splitTot = split.g + split.y + split.o;
  const pct = c => splitTot ? Math.round(split[c] / splitTot * 100) : 0;

  // Extended nutrition vs guideline limits
  const satLimit = Math.max(15, Math.round((p.kcal_target || 1800) * 0.10 / 9));
  const detail = [
    ['Sugar', tot.sugar, 30, 'g'], ['Sodium', tot.sodium, 2300, 'mg'],
    ['Saturated fat', tot.satfat, satLimit, 'g'], ['Fiber', tot.fiber, 30, 'g'],
  ];

  shell(`<div class="screen">
    <div class="screen-header">
      <div class="screen-title">Diary</div>
      <div class="row">
        <button class="iconbtn" id="dPrev">‹</button>
        <button class="iconbtn" id="dNext" ${isToday ? 'disabled style="opacity:.4"' : ''}>›</button>
      </div>
    </div>
    <div class="day-strip">${strip}</div>

    <div class="card">
      <div class="row" style="justify-content:space-around;text-align:center">
        <div><b style="font-size:20px">${Math.round(tot.kcal)}</b><div class="tiny">kcal eaten</div></div>
        <div><b style="font-size:20px;color:var(--blue)">${Math.round(tot.protein)}g</b><div class="tiny">protein</div></div>
        <div><b style="font-size:20px;color:${Math.max(0, tot.carbs - tot.fiber) > (p.carbs_g || 25) && p.diet === 'keto' ? 'var(--red)' : 'var(--orange)'}">${Math.round(Math.max(0, tot.carbs - tot.fiber))}g</b><div class="tiny">net carbs</div></div>
        <div><b style="font-size:20px;color:var(--purple)">${Math.round(tot.fat)}g</b><div class="tiny">fat</div></div>
      </div>
      ${splitTot ? `
      <div class="split-bar" title="Calorie-density mix">
        ${split.g ? `<i class="sb-g" style="width:${pct('g')}%"></i>` : ''}${split.y ? `<i class="sb-y" style="width:${pct('y')}%"></i>` : ''}${split.o ? `<i class="sb-o" style="width:${pct('o')}%"></i>` : ''}
      </div>
      <div class="split-legend">${dot('g')} light ${pct('g')}% &nbsp; ${dot('y')} medium ${pct('y')}% &nbsp; ${dot('o')} dense ${pct('o')}%</div>` : ''}
      <button class="btn ghost small" id="dDetail" style="margin-top:6px">More nutrition ▾</button>
      <div id="dDetailBody" hidden>
        ${detail.map(([lbl, v, lim, u]) => `
          <div class="macro"><div class="row"><span>${lbl}</span><span class="val" style="${v > lim && lbl !== 'Fiber' ? 'color:var(--red);font-weight:700' : ''}">${Math.round(v)} / ${lim} ${u}</span></div>
          <div class="bar"><i data-w="${Math.min(100, v / lim * 100)}%" style="background:${lbl === 'Fiber' ? 'var(--accent)' : v > lim ? 'var(--red)' : 'var(--text3)'};width:0"></i></div></div>`).join('')}
        <div class="tiny">General daily guidelines — sugar & sat-fat limits matter most; fiber is a “more is better” target.</div>
      </div>
    </div>

    ${Object.entries(meals).map(([key, [em, label]]) => {
      const items = day.entries.filter(e => e.meal === key);
      const kc = items.reduce((a, e) => a + +e.kcal, 0);
      return `<div class="card meal-group">
        <div class="meal-head"><h3>${em} ${label}</h3>
          <span class="row" style="gap:4px">
            ${items.length ? `<button class="mini-act" data-save="${key}" title="Save as meal">${ic('star', 14)}</button>` : ''}
            <button class="mini-act" data-copy="${key}" title="Copy from yesterday">${ic('copy', 14)}</button>
            <span class="kc" style="margin-left:6px">${Math.round(kc)} kcal</span>
          </span></div>
        ${items.map(e => `<div class="food-row">
          <div class="grow"><div class="n">${entryDot(e)} ${esc(e.name)}</div><div class="d">${round1(e.grams)}g · P${round1(e.protein)} C${round1(e.carbs)} F${round1(e.fat)}</div></div>
          <span class="kcal">${Math.round(e.kcal)}</span>
          <button class="del" data-del="${e.id}">✕</button>
        </div>`).join('') || '<div class="tiny" style="padding:4px 0 8px">Nothing logged yet</div>'}
        <button class="btn ghost small" data-meal="${key}">+ Add food</button>
      </div>`;
    }).join('')}

    ${day.workouts.length ? `<div class="card">
      <div class="meal-head"><h3>${ic('dumbbell', 16)} Workouts</h3><span class="kc">−${Math.round(day.workouts.reduce((a, w) => a + +w.kcal, 0))} kcal</span></div>
      ${day.workouts.map(w => `<div class="food-row"><div class="grow"><div class="n">${esc(w.name)}</div><div class="d">${w.minutes} min</div></div>
        <span class="kcal">−${Math.round(w.kcal)}</span><button class="del" data-delw="${w.id}">✕</button></div>`).join('')}
    </div>` : ''}
  </div>`);

  $('#dPrev').onclick = () => { S.diaryDate = shiftDate(S.diaryDate, -1); render(); };
  $('#dNext').onclick = () => { if (!isToday) { S.diaryDate = shiftDate(S.diaryDate, 1); render(); } };
  document.querySelectorAll('[data-day]').forEach(b => b.onclick = () => { S.diaryDate = b.dataset.day; render(); });
  $('#dDetail').onclick = () => {
    const b = $('#dDetailBody'); b.hidden = !b.hidden;
    $('#dDetail').textContent = b.hidden ? 'More nutrition ▾' : 'Less ▴';
    if (!b.hidden) animateRings(b);
  };
  document.querySelectorAll('[data-save]').forEach(b => b.onclick = async () => {
    const key = b.dataset.save;
    const items = day.entries.filter(e => e.meal === key).map(e => ({
      name: e.name, grams: +e.grams, kcal: +e.kcal, protein: +e.protein, carbs: +e.carbs,
      fat: +e.fat, fiber: +e.fiber, sugar: +(e.sugar || 0), sodium: +(e.sodium || 0), satfat: +(e.satfat || 0) }));
    const name = prompt('Name this meal (e.g. "My usual breakfast"):',
      `My ${key} (${items.length} items)`);
    if (!name) return;
    const r = await api('save_meal', { name, items });
    toast(r.ok ? 'Saved — find it under the + button, star tab' : r.error);
  });
  document.querySelectorAll('[data-copy]').forEach(b => b.onclick = async () => {
    const key = b.dataset.copy;
    const yd = await api('day', { date: shiftDate(S.diaryDate, -1) });
    const items = (yd.entries || []).filter(e => e.meal === key);
    if (!items.length) return toast('Nothing logged for ' + key + ' yesterday');
    for (const e of items) {
      await api('log_food', { date: S.diaryDate, meal: key, name: e.name, grams: +e.grams,
        kcal: +e.kcal, protein: +e.protein, carbs: +e.carbs, fat: +e.fat, fiber: +e.fiber,
        sugar: +(e.sugar || 0), sodium: +(e.sodium || 0), satfat: +(e.satfat || 0) });
    }
    toast(`Copied ${items.length} item${items.length > 1 ? 's' : ''} from yesterday`); render();
  });
  document.querySelectorAll('[data-meal]').forEach(b => b.onclick = () => openAddSheet(b.dataset.meal));
  document.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => { await api('del_food_entry', { id: +b.dataset.del }); render(); });
  document.querySelectorAll('[data-delw]').forEach(b => b.onclick = async () => { await api('del_workout', { id: +b.dataset.delw }); render(); });
}
const shiftDate = (ds, n) => { const d = new Date(ds + 'T12:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };

// ══ FASTING ═══════════════════════════════════════════════════════════
const FAST_STAGES = [
  [0, 'utensils', 'Fed state', 'Digesting — insulin is elevated, body stores energy.'],
  [4, 'trenddown', 'Early fasting', 'Blood sugar falls, insulin drops, body switches to stored glycogen.'],
  [10, 'flame', 'Fat burning', 'Glycogen runs low — your body starts burning fat for fuel.'],
  [14, 'activity', 'Ketosis begins', 'Ketone production ramps up. Mental clarity often peaks here.'],
  [18, 'sparkle', 'Deep ketosis', 'Autophagy increases — cellular cleanup and repair mode.'],
  [24, 'trophy', 'Extended fast', 'Growth hormone surges; deep autophagy. Break gently with light food.'],
];
async function renderFast() {
  const r = await api('fasts');
  const fasts = r.fasts || [];
  const active = fasts.find(f => !f.end_ts);
  const p = S.profile;
  const plans = ['16:8', '18:6', '20:4', '23:1'];

  shell(`<div class="screen">
    <div class="screen-header"><div><div class="screen-title">Fasting</div>
      <div class="screen-sub">Intermittent fasting timer</div></div></div>
    <div class="card" style="text-align:center" id="fastCard"></div>
    <div class="card">
      <div class="card-title"><span class="icon" style="background:var(--purple-soft);color:var(--purple)">${ic('calendar', 16)}</span>History</div>
      ${fasts.filter(f => f.end_ts).slice(0, 8).map(f => {
        const dur = parseUTC(f.end_ts) - parseUTC(f.start_ts);
        const hrs = dur / 36e5, hit = hrs >= +f.target_hours;
        return `<div class="food-row"><div class="grow"><div class="n" style="display:flex;align-items:center;gap:6px"><span style="color:${hit ? 'var(--accent)' : 'var(--text3)'}">${ic(hit ? 'checkcircle' : 'timer', 15)}</span> ${round1(hrs)}h fast</div>
          <div class="d">${new Date(parseUTC(f.start_ts)).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} · target ${f.target_hours}h</div></div>
          <span class="kcal">${hit ? 'Done' : Math.round(hrs / f.target_hours * 100) + '%'}</span></div>`;
      }).join('') || `<div class="empty"><div class="em">${ic('timer', 34)}</div>No completed fasts yet</div>`}
    </div>
  </div>`);

  const card = $('#fastCard');
  clearInterval(S.fastTimer);
  if (active) {
    const start = parseUTC(active.start_ts);
    const targetMs = active.target_hours * 36e5;
    const draw = () => {
      if (!document.body.contains(card)) { clearInterval(S.fastTimer); return; }
      const el = Date.now() - start;
      const pct = Math.min(1, el / targetMs);
      const hrs = el / 36e5;
      const stage = [...FAST_STAGES].reverse().find(s => hrs >= s[0]) || FAST_STAGES[0];
      const done = el >= targetMs;
      card.innerHTML = `
        ${ringSVG(210, 15, pct, done ? 'var(--accent)' : 'var(--purple)',
          `<div class="big" style="font-size:24px">${fmtDur(el)}</div><div class="lbl">${done ? 'target reached' : 'of ' + active.target_hours + 'h'}</div>`)}
        <div class="fast-stage"><span class="em" style="color:var(--purple)">${ic(stage[1], 22)}</span><span style="text-align:left"><b>${stage[2]}</b> — ${stage[3]}</span></div>
        <div class="tiny" style="margin:10px 0 12px">Ends ${new Date(start + targetMs).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} · water, black coffee & plain tea are OK</div>
        <button class="btn ${done ? '' : 'danger'}" id="fEnd">${done ? 'Complete fast' : 'End fast early'}</button>`;
      animateRings(card);
      card.querySelector('#fEnd').onclick = async () => { await api('fast_end'); toast(done ? 'Fast completed — well done' : 'Fast ended'); render(); };
    };
    draw();
    S.fastTimer = setInterval(() => {
      const t = card.querySelector('.ring-center .big');
      if (t) t.textContent = fmtDur(Date.now() - start); else clearInterval(S.fastTimer);
    }, 1000);
    const redraw = setInterval(() => {
      if (!document.body.contains(card)) return clearInterval(redraw);
      draw();
    }, 60000);
  } else {
    let sel = p.fasting_plan !== 'none' ? p.fasting_plan : '16:8';
    if (!plans.includes(sel)) sel = '16:8';
    const draw = () => {
      card.innerHTML = `
        <div style="margin:10px 0;color:var(--purple)">${ic('timer', 46, 1.4)}</div>
        <h3 style="margin-bottom:4px">Ready to fast?</h3>
        <div class="muted" style="margin-bottom:14px">Pick a plan — the first number is your fasting hours.</div>
        <div class="seg" style="margin-bottom:16px">${plans.map(pl =>
          `<button data-v="${pl}" class="${pl === sel ? 'on' : ''}">${pl}</button>`).join('')}</div>
        <button class="btn" style="background:var(--purple);box-shadow:0 2px 8px var(--purple-soft)" id="fGo">Start ${sel.split(':')[0]}-hour fast</button>`;
      card.querySelectorAll('[data-v]').forEach(b => b.onclick = () => { sel = b.dataset.v; draw(); });
      card.querySelector('#fGo').onclick = async () => {
        await api('fast_start', { target_hours: +sel.split(':')[0] });
        toast('Fast started'); render();
      };
    };
    draw();
  }
}

// ══ MORE MENU ═════════════════════════════════════════════════════════
function renderMore() {
  const items = [
    ['progress', 'chart', 'Progress & charts', 'Weight, calories, biometrics, streaks'],
    ['learn', 'bulb', 'Learn', 'Daily 2-minute coaching lessons'],
    ['recipes', 'chefhat', 'Keto recipes', 'Meal ideas with macros'],
    ['workouts', 'dumbbell', 'Workouts', 'Back-safe exercise library'],
    ['settings', 'gear', 'Settings', 'Profile, reminders, AI key, theme'],
  ];
  shell(`<div class="screen">
    <div class="screen-header"><div class="screen-title">More</div></div>
    ${items.map(([v, icn, t, s]) => `<div class="list-item" data-go="${v}">
      <div class="em ico">${ic(icn, 22)}</div><div class="grow"><h4>${t}</h4><div class="meta">${s}</div></div><span class="muted">${ic('chevron', 15)}</span>
    </div>`).join('')}
    ${S.installPrompt ? `<div class="list-item" id="installBtn"><div class="em ico">${ic('download', 22)}</div><div class="grow"><h4>Install app</h4><div class="meta">Add VitaTrack to your home screen</div></div></div>` : ''}
  </div>`);
  document.querySelectorAll('[data-go]').forEach(b => b.onclick = () => { S.view = b.dataset.go; render(); });
  const ib = $('#installBtn');
  if (ib) ib.onclick = async () => { S.installPrompt.prompt(); };
}

// ══ LEARN (drip lessons, Noom-style) ══════════════════════════════════
async function renderLearn() {
  const r = await api('lessons');
  const lessons = r.lessons || [], reads = r.reads || {};
  const readCount = Object.keys(reads).length;
  const readToday = Object.values(reads).includes(r.today);
  const nextId = (lessons.find(l => !reads[l.id]) || {}).id;
  shell(`<div class="screen">
    <div class="screen-header"><div><div class="screen-title">Learn</div>
      <div class="screen-sub">One 2-minute lesson a day</div></div></div>
    <div class="card" style="text-align:center">
      <b style="font-size:22px">${readCount} / ${lessons.length}</b><div class="tiny">lessons completed</div>
      <div class="bar" style="margin-top:10px"><i data-w="${readCount / lessons.length * 100}%" style="background:linear-gradient(90deg,var(--accent2),var(--accent));width:0"></i></div>
    </div>
    ${lessons.map(l => {
      const done = !!reads[l.id];
      const unlocked = done || (l.id === nextId && !readToday);
      const lockedToday = l.id === nextId && readToday;
      return `<div class="list-item ${!unlocked && !lockedToday ? 'locked' : ''}" ${unlocked ? `onclick="openLesson(${l.id})"` : ''}>
        <div class="em ico" style="${done ? 'color:var(--accent)' : unlocked ? 'color:var(--orange)' : ''}">${done ? ic('checkcircle', 22) : unlocked ? ic('bulb', 22) : ic(lockedToday ? 'moon' : 'lock', 20)}</div>
        <div class="grow"><h4>${l.ord}. ${esc(l.title)}</h4>
          <div class="meta">${esc(l.category)}${done ? ' · read ' + reads[l.id] : lockedToday ? ' · unlocks tomorrow' : unlocked ? ' · ready to read' : ''}</div></div>
        ${unlocked ? `<span class="muted">${ic('chevron', 15)}</span>` : ''}
      </div>`;
    }).join('')}
  </div>`);
}
window.openLesson = async id => {
  if (!S._lessonCache) S._lessonCache = await api('lessons');
  const l = (S._lessonCache.lessons || []).find(x => +x.id === +id); if (!l) return;
  const sh = openSheet(`
    <div class="brandmark" style="width:60px;height:60px;border-radius:18px">${ic('bulb', 28, 1.5)}</div>
    <div class="tiny" style="text-align:center;text-transform:uppercase;letter-spacing:.6px;margin-top:10px">${esc(l.category)} · lesson ${l.ord}</div>
    <h3 style="text-align:center;margin-top:6px">${esc(l.title)}</h3>
    <p style="font-size:15px;line-height:1.75;color:var(--text2);white-space:pre-line">${esc(l.body)}</p>
    <button class="btn" id="lDone" style="margin-top:14px">Got it</button>`);
  sh.querySelector('#lDone').onclick = async () => {
    const r = await api('lesson_read', { id: l.id });
    S._lessonCache = null;
    if (!r.ok) { toast(r.error); return; }
    toast('Lesson complete'); closeSheet(); render();
  };
};

// ══ PROGRESS ══════════════════════════════════════════════════════════
const BIO_TYPES = {
  bp:      ['heartpulse', 'Blood pressure', 'mmHg', 2],
  glucose: ['glucose', 'Blood glucose', 'mg/dL', 1],
  ketones: ['leaf', 'Ketones', 'mmol/L', 1],
  rhr:     ['activity', 'Resting heart rate', 'bpm', 1],
  sleep:   ['bed', 'Sleep', 'hours', 1],
  steps:   ['target', 'Steps', 'steps', 1],
};

function weeklyInsight(r, p) {
  const last7 = new Set(Array.from({ length: 7 }, (_, i) => shiftDate(todayStr(), -i)));
  const days = (r.daily || []).filter(d => last7.has(d.date));
  if (!days.length) return '';
  const avg = Math.round(days.reduce((a, d) => a + +d.kcal, 0) / days.length);
  const onTarget = days.filter(d => +d.kcal <= (p.kcal_target || 1800) * 1.05).length;
  const w = r.weights || [];
  const wk = w.filter(x => last7.has(x.date));
  const delta = wk.length >= 2 ? +wk[wk.length - 1].weight_kg - +wk[0].weight_kg : null;
  const avgWater = (r.water || []).filter(d => last7.has(d.date));
  const waterL = avgWater.length ? (avgWater.reduce((a, d) => a + +d.ml, 0) / avgWater.length / 1000).toFixed(1) : '0';
  return `<div class="card">
    <div class="card-title"><span class="icon" style="background:var(--accent-soft);color:var(--accent)">${ic('mail', 16)}</span>This week</div>
    <div class="row" style="justify-content:space-around;text-align:center">
      <div><b style="font-size:18px">${avg}</b><div class="tiny">avg kcal/day</div></div>
      <div><b style="font-size:18px;color:var(--accent)">${onTarget}/${days.length}</b><div class="tiny">days on target</div></div>
      <div><b style="font-size:18px;color:${delta != null && delta < 0 ? 'var(--accent)' : 'var(--text)'}">${delta == null ? '—' : (delta > 0 ? '+' : '') + fmtW(Math.abs(delta)).replace(' ', '')}</b><div class="tiny">weight ${delta != null && delta < 0 ? '↓' : 'Δ'}</div></div>
      <div><b style="font-size:18px;color:var(--blue)">${waterL}L</b><div class="tiny">avg water</div></div>
    </div>
    <div class="tiny" style="margin-top:10px">${
      onTarget >= 5 ? 'Strong week — this is exactly how weight comes off and stays off.'
      : onTarget >= 3 ? 'Decent week. Look at which days went over and what happened — patterns beat willpower.'
      : 'Rough week? Zoom out: one logged week is still progress. Pick one thing to fix next week.'}</div>
  </div>`;
}

async function renderProgress() {
  if (!S._range) S._range = 90;
  const days = S._range;
  const r = await api('progress', { days: days === 'all' ? 3650 : days });
  const bios = (await api('bios')).bios || [];
  const p = S.profile;
  const cutoff = days === 'all' ? '0000' : shiftDate(todayStr(), -days);
  const wAll = r.weights || [];
  const wRange = wAll.filter(w => w.date >= cutoff);
  const wpts = wRange.map(w => ({ v: isImperial() ? round1(kg2lb(w.weight_kg)) : +w.weight_kg, l: w.date.slice(5) }));
  const bfpts = wRange.filter(w => w.body_fat).map(w => ({ v: +w.body_fat, l: w.date.slice(5) }));
  const kpts = (r.daily || []).map(d => ({ v: +d.kcal, l: d.date.slice(5) }));
  const cpts = (r.daily || []).map(d => ({ v: Math.max(0, +d.netcarbs), l: d.date.slice(5) }));
  const wapts = (r.water || []).map(d => ({ v: +d.ml / 1000, l: d.date.slice(5) }));
  const last = r.weights?.length ? +r.weights[r.weights.length - 1].weight_kg : p.start_weight_kg;
  const lost = p.start_weight_kg ? Math.max(0, p.start_weight_kg - last) : 0;
  const bmi = last && p.height_cm ? round1(last / Math.pow(p.height_cm / 100, 2)) : '—';

  shell(`<div class="screen">
    <div class="screen-header"><div><div class="screen-title">Progress</div>
      <div class="screen-sub">Your journey so far</div></div>
      <button class="btn small secondary" onclick="openWeightSheet()">Log weight</button></div>

    <div class="grid3">
      <div class="stat-tile"><div class="emoji" style="color:var(--orange)"><span class="flame">${ic('flame', 22)}</span></div><div class="v">${r.streak}</div><div class="k">Day streak</div></div>
      <div class="stat-tile"><div class="emoji" style="color:var(--accent)">${ic('trenddown', 22)}</div><div class="v">${lost > 0 ? '−' + (isImperial() ? round1(kg2lb(lost)) : round1(lost)) : 0}</div><div class="k">${wUnit()} lost</div></div>
      <div class="stat-tile"><div class="emoji" style="color:var(--blue)">${ic('calendar', 22)}</div><div class="v">${r.days_logged}</div><div class="k">Days logged</div></div>
    </div>
    <div class="spacer"></div>
    ${weeklyInsight(r, p)}

    <div class="seg" style="margin-bottom:14px" id="rangeSeg">
      ${[[7, '7d'], [30, '30d'], [90, '90d'], [365, '1y'], ['all', 'All']].map(([v, l]) =>
        `<button data-range="${v}" class="${String(days) === String(v) ? 'on' : ''}">${l}</button>`).join('')}
    </div>

    <div class="card chart-card">
      <div class="card-title"><span class="icon" style="background:var(--accent-soft);color:var(--accent)">${ic('scale', 16)}</span>Weight (${wUnit()})
        <span class="grow"></span><span class="tiny">${wpts.length} entries</span></div>
      ${lineChart(wpts, { color: 'var(--accent)', unit: '', goal: p.goal_weight_kg ? (isImperial() ? round1(kg2lb(p.goal_weight_kg)) : +p.goal_weight_kg) : null })}
      ${wpts.length === 1 ? '<div class="tiny" style="margin-top:8px">One weigh-in so far — log daily and the trend line appears here.</div>' : ''}
    </div>
    ${bfpts.length > 1 ? `<div class="card chart-card">
      <div class="card-title"><span class="icon" style="background:var(--orange-soft);color:var(--orange)">${ic('activity', 16)}</span>Body fat %</div>
      ${lineChart(bfpts, { color: 'var(--orange)' })}</div>` : ''}
    <div class="card chart-card">
      <div class="card-title"><span class="icon" style="background:var(--red-soft);color:var(--red)">${ic('flame', 16)}</span>Calories — last 30 days</div>
      ${barChart(kpts.slice(-45), { color: 'var(--accent)', goal: p.kcal_target })}
      <div class="tiny">Dashed line = your ${p.kcal_target} kcal target. Orange bars = over target.</div>
    </div>

    <div class="card">
      <div class="card-title"><span class="icon" style="background:var(--blue-soft);color:var(--blue)">${ic('calendar', 16)}</span>Weigh-in history</div>
      ${[...wAll].reverse().slice(0, 10).map((w, i, arr) => {
        const prev = arr[i + 1];
        const d = prev ? +w.weight_kg - +prev.weight_kg : null;
        return `<div class="food-row">
          <div class="grow"><div class="n">${fmtW(+w.weight_kg)}${w.body_fat ? ` <span class="tiny">· ${w.body_fat}% fat</span>` : ''}</div>
            <div class="d">${new Date(w.date + 'T12:00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })}</div></div>
          ${d != null ? `<span class="kcal" style="color:${d < 0 ? 'var(--accent)' : d > 0 ? 'var(--red)' : 'var(--text3)'}">${d > 0 ? '+' : ''}${round1(isImperial() ? kg2lb(d) : d)}</span>` : '<span class="tiny">start</span>'}
          <button class="del" data-delw2="${w.id}">${ic('x', 14)}</button>
        </div>`;
      }).join('') || '<div class="muted">No weigh-ins yet — tap “Log weight” above.</div>'}
    </div>
    ${p.diet !== 'balanced' ? `<div class="card chart-card">
      <div class="card-title"><span class="icon" style="background:var(--orange-soft);color:var(--orange)">${ic('leaf', 16)}</span>Net carbs (g)</div>
      ${barChart(cpts.slice(-30), { color: 'var(--orange)', goal: p.carbs_g })}</div>` : ''}
    <div class="card chart-card">
      <div class="card-title"><span class="icon" style="background:var(--blue-soft);color:var(--blue)">${ic('droplet', 16)}</span>Water (L)</div>
      ${barChart(wapts.slice(-30), { color: 'var(--blue)', goal: (p.water_ml || 2500) / 1000 })}
    </div>
    <div class="card">
      <div class="card-title"><span class="icon" style="background:var(--purple-soft);color:var(--purple)">${ic('target', 16)}</span>Body stats</div>
      <div class="row" style="justify-content:space-around;text-align:center">
        <div><b style="font-size:19px">${bmi}</b><div class="tiny">BMI</div></div>
        <div><b style="font-size:19px">${fmtW(last)}</b><div class="tiny">Current</div></div>
        <div><b style="font-size:19px">${fmtW(p.goal_weight_kg)}</b><div class="tiny">Goal</div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-title"><span class="icon" style="background:var(--red-soft);color:var(--red)">${ic('heartpulse', 16)}</span>Health metrics
        <span class="grow"></span><button class="btn small secondary" id="bioAdd">+ Log</button></div>
      ${Object.entries(BIO_TYPES).map(([t, [em, label, unit]]) => {
        const pts = bios.filter(b => b.type === t);
        if (!pts.length) return '';
        const lastB = pts[pts.length - 1];
        const lastVal = t === 'bp' ? `${Math.round(lastB.v1)}/${Math.round(lastB.v2 || 0)}` : round1(lastB.v1).toLocaleString();
        const chart = pts.length >= 2
          ? lineChart(pts.slice(-30).map(b => ({ v: +b.v1, l: b.date.slice(5) })), { color: 'var(--red)', h: 110 })
          : '';
        return `<div style="margin-bottom:14px">
          <div class="row" style="justify-content:space-between;font-size:14px;font-weight:650">
            <span class="row" style="gap:7px;color:var(--text)"><span style="color:var(--red)">${ic(em, 16)}</span>${label}</span>
            <span>${lastVal} <span class="tiny">${unit} · ${lastB.date.slice(5)}</span></span></div>
          ${chart}</div>`;
      }).join('') || '<div class="muted">Track blood pressure, glucose, ketones, resting heart rate, sleep or steps — tap + Log.</div>'}
    </div>
  </div>`);
  $('#bioAdd').onclick = openBioSheet;
  document.querySelectorAll('[data-range]').forEach(b => b.onclick = () => {
    S._range = b.dataset.range === 'all' ? 'all' : +b.dataset.range; render();
  });
  document.querySelectorAll('[data-delw2]').forEach(b => b.onclick = async () => {
    await api('del_weight', { id: +b.dataset.delw2 }); toast('Weigh-in removed'); render();
  });
}

function openBioSheet() {
  const sh = openSheet(`<h3>Log health metric</h3>
    <div class="field"><label>Metric</label>
      <select id="bioT">${Object.entries(BIO_TYPES).map(([t, [, label]]) => `<option value="${t}">${label}</option>`).join('')}</select></div>
    <div class="grid2">
      <div class="field"><label id="bioL1">Value</label><input id="bioV1" type="number" inputmode="decimal"></div>
      <div class="field" id="bioW2" hidden><label>Diastolic</label><input id="bioV2" type="number" inputmode="decimal"></div>
    </div>
    <div class="field"><label>Date</label><input id="bioD" type="date" value="${todayStr()}" max="${todayStr()}"></div>
    <button class="btn" id="bioGo">Save</button>`);
  const sel = sh.querySelector('#bioT');
  const upd = () => {
    const t = sel.value;
    sh.querySelector('#bioW2').hidden = t !== 'bp';
    sh.querySelector('#bioL1').textContent = t === 'bp' ? 'Systolic' : `Value (${BIO_TYPES[t][2]})`;
  };
  sel.onchange = upd; upd();
  sh.querySelector('#bioGo').onclick = async () => {
    const r = await api('log_bio', { type: sel.value, v1: sh.querySelector('#bioV1').value,
      v2: sh.querySelector('#bioV2').value, date: sh.querySelector('#bioD').value });
    if (r.ok) { closeSheet(); toast('Metric logged'); render(); } else toast(r.error);
  };
}

// ══ RECIPES ═══════════════════════════════════════════════════════════
async function renderRecipes() {
  if (!S.recipes) S.recipes = (await api('recipes')).recipes || [];
  const tag = S._recipeTag || 'all';
  const tags = ['all', 'breakfast', 'lunch', 'dinner', 'snack'];
  const list = S.recipes.filter(r => tag === 'all' || r.tag === tag);
  shell(`<div class="screen">
    <div class="screen-header"><div><div class="screen-title">Recipes</div>
      <div class="screen-sub">Keto-friendly, macro-counted</div></div></div>
    <div class="chips" style="margin-bottom:14px">${tags.map(t =>
      `<button class="chip ${t === tag ? 'on' : ''}" data-t="${t}">${t[0].toUpperCase() + t.slice(1)}</button>`).join('')}</div>
    ${list.map(r => `<div class="list-item" data-r="${r.id}">
      <div class="em">${r.emoji}</div>
      <div class="grow"><h4>${esc(r.name)}</h4>
        <div class="meta">⏱ ${r.minutes} min · ${Math.round(r.kcal)} kcal · P${Math.round(r.protein)} C${Math.round(r.carbs)} F${Math.round(r.fat)}</div>
        <span class="badge green">${+r.carbs <= 10 ? 'Keto ✓' : 'Low-carb'}</span><span class="badge blue">${r.tag}</span></div>
      <span class="muted">›</span></div>`).join('')}
  </div>`);
  document.querySelectorAll('[data-t]').forEach(b => b.onclick = () => { S._recipeTag = b.dataset.t; render(); });
  document.querySelectorAll('[data-r]').forEach(b => b.onclick = () => openRecipe(+b.dataset.r));
}
window.openRecipe = async id => {
  if (!S.recipes) S.recipes = (await api('recipes')).recipes || [];
  const r = S.recipes.find(x => +x.id === +id); if (!r) return;
  const sh = openSheet(`
    <div style="text-align:center;font-size:52px">${r.emoji}</div>
    <h3 style="text-align:center">${esc(r.name)}</h3>
    <div class="grid3" style="margin-bottom:14px">
      <div class="stat-tile"><div class="v">${Math.round(r.kcal)}</div><div class="k">kcal</div></div>
      <div class="stat-tile"><div class="v">${Math.round(r.protein)}g</div><div class="k">protein</div></div>
      <div class="stat-tile"><div class="v">${Math.round(r.carbs)}g</div><div class="k">carbs</div></div>
    </div>
    <div class="card" style="box-shadow:none;background:var(--surface2)">
      <b class="row" style="gap:7px">${ic('journal', 15)} Ingredients</b>
      <ul style="margin:8px 0 0 18px;line-height:1.8;font-size:14px">${r.ingredients.split('|').map(i => `<li>${esc(i)}</li>`).join('')}</ul>
    </div>
    <div class="card" style="box-shadow:none;background:var(--surface2)">
      <b class="row" style="gap:7px">${ic('chefhat', 15)} Instructions</b>
      <p style="margin-top:8px;font-size:14px;line-height:1.7">${esc(r.instructions)}</p>
    </div>
    <button class="btn" id="rLog">Log 1 serving to diary</button>`);
  sh.querySelector('#rLog').onclick = async () => {
    const meal = r.tag === 'snack' ? 'snacks' : r.tag;
    await api('log_food', { date: todayStr(), meal, name: r.name, grams: 1,
      kcal: +r.kcal, protein: +r.protein, carbs: +r.carbs, fat: +r.fat, fiber: +r.fiber });
    toast('✓ Logged to ' + meal); closeSheet(); render();
  };
};

// ══ WORKOUTS ══════════════════════════════════════════════════════════
const EX_ICONS = { cardio: 'activity', strength: 'dumbbell', core: 'target', mobility: 'leaf' };
async function renderWorkouts() {
  if (!S.exercises) S.exercises = (await api('exercises')).exercises || [];
  const issues = JSON.parse(S.profile.health_issues || '[]');
  const hasBack = issues.includes('back');
  if (S._safeOnly === undefined) S._safeOnly = hasBack;
  const cat = S._exCat || 'all';
  const cats = ['all', 'cardio', 'strength', 'core', 'mobility'];
  let list = S.exercises.filter(e => cat === 'all' || e.category === cat);
  if (S._safeOnly) list = list.filter(e => +e.back_safe);

  shell(`<div class="screen">
    <div class="screen-header"><div><div class="screen-title">Workouts</div>
      <div class="screen-sub">${hasBack ? 'Filtered for your back' : 'Move more, feel better'}</div></div></div>
    ${hasBack ? `<div class="fast-stage" style="margin-bottom:14px"><span class="em" style="color:var(--accent)">${ic('checkcircle', 22)}</span><span>You noted back problems, so back-safe filtering is <b>${S._safeOnly ? 'ON' : 'OFF'}</b>. <b style="color:var(--accent);cursor:pointer" id="toggleSafe">${S._safeOnly ? 'Show all' : 'Filter again'}</b></span></div>` : ''}
    <div class="chips" style="margin-bottom:14px">${cats.map(c =>
      `<button class="chip ${c === cat ? 'on' : ''}" data-c="${c}">${c[0].toUpperCase() + c.slice(1)}</button>`).join('')}</div>
    ${list.map(e => `<div class="list-item" data-e="${e.id}">
      <div class="em ico">${ic(EX_ICONS[e.category] || 'activity', 22)}</div>
      <div class="grow"><h4>${esc(e.name)}</h4>
        <div class="meta">~${e.kcal30} kcal / 30 min · ${e.difficulty}</div>
        ${+e.back_safe ? '<span class="badge green">Back-safe</span>' : '<span class="badge orange">Healthy back only</span>'}
        ${+e.low_impact ? '<span class="badge blue">Low impact</span>' : ''}</div>
      <span class="muted">${ic('chevron', 15)}</span></div>`).join('')}
  </div>`);
  const ts = $('#toggleSafe');
  if (ts) ts.onclick = () => { S._safeOnly = !S._safeOnly; render(); };
  document.querySelectorAll('[data-c]').forEach(b => b.onclick = () => { S._exCat = b.dataset.c; render(); });
  document.querySelectorAll('[data-e]').forEach(b => b.onclick = () => openExercise(+b.dataset.e));
}
window.openExercise = async id => {
  if (!S.exercises) S.exercises = (await api('exercises')).exercises || [];
  const e = S.exercises.find(x => +x.id === +id); if (!e) return;
  const sh = openSheet(`
    <div class="brandmark" style="width:60px;height:60px;border-radius:18px">${ic(EX_ICONS[e.category] || 'activity', 28, 1.5)}</div>
    <h3 style="text-align:center;margin-top:12px">${esc(e.name)}</h3>
    <div style="text-align:center;margin-bottom:12px">
      ${+e.back_safe ? '<span class="badge green">Back-safe</span>' : '<span class="badge orange">Healthy back only</span>'}
      <span class="badge blue">${e.category}</span><span class="badge purple">${e.difficulty}</span></div>
    <p style="font-size:14.5px;line-height:1.7;color:var(--text2)">${esc(e.description)}</p>
    <div class="field" style="margin-top:16px"><label>Minutes</label><input id="exMin" type="number" inputmode="numeric" value="30"></div>
    <button class="btn" id="exLog">Log workout (~<span id="exK">${e.kcal30}</span> kcal)</button>`);
  const min = sh.querySelector('#exMin');
  min.oninput = () => sh.querySelector('#exK').textContent = Math.round(+e.kcal30 * (+min.value || 0) / 30);
  sh.querySelector('#exLog').onclick = async () => {
    const m = +min.value || 30;
    await api('log_workout', { date: todayStr(), name: e.name, minutes: m, kcal: Math.round(+e.kcal30 * m / 30) });
    toast('Workout logged'); closeSheet(); render();
  };
};

// ══ SETTINGS ══════════════════════════════════════════════════════════
async function renderSettings() {
  const p = S.profile, st = S.settings;
  const keyInfo = await api('has_api_key');
  shell(`<div class="screen">
    <div class="screen-header"><div class="screen-title">Settings</div></div>

    <div class="card">
      <div class="card-title"><span class="icon" style="background:var(--accent-soft);color:var(--accent)">${ic('user', 16)}</span>${esc(S.user.name)}
        <span class="grow"></span><span class="tiny">${esc(S.user.email)}</span></div>
      <div class="muted" style="margin-bottom:10px">Plan: ${p.kcal_target} kcal · P${p.protein_g} C${p.carbs_g} F${p.fat_g} · ${esc(p.diet)} · fasting ${esc(p.fasting_plan)}</div>
      <button class="btn small secondary" id="sEditProfile">Edit profile & recalculate plan</button>
    </div>

    <div class="card">
      <div class="card-title"><span class="icon" style="background:var(--purple-soft);color:var(--purple)">${ic('moon', 16)}</span>Appearance</div>
      <div class="field"><label>Theme</label>
        <div class="seg" id="segTheme">
          ${['auto', 'light', 'dark'].map(t => `<button data-v="${t}" class="${(st.theme || 'auto') === t ? 'on' : ''}">${t[0].toUpperCase() + t.slice(1)}</button>`).join('')}
        </div></div>
    </div>

    <div class="card">
      <div class="card-title"><span class="icon" style="background:var(--blue-soft);color:var(--blue)">${ic('bell', 16)}</span>Reminders</div>
      <div class="muted" style="margin-bottom:12px">Notifications fire while the app is open or installed on your home screen.</div>
      ${[['reminders_water', 'Drink water (every 2h, 9am–9pm)'], ['reminders_meals', 'Meal times (8:00, 13:00, 19:00)'], ['reminders_weight', 'Morning weigh-in (8:00)']].map(([k, lbl]) => `
        <label class="row" style="padding:8px 0;justify-content:space-between;font-size:14.5px">${lbl}
          <input type="checkbox" data-rem="${k}" ${st[k] === '1' ? 'checked' : ''} style="width:22px;height:22px;accent-color:var(--accent)"></label>`).join('')}
      <div id="notifState" class="tiny" style="margin-top:6px"></div>
    </div>

    <div class="card">
      <div class="card-title"><span class="icon" style="background:var(--orange-soft);color:var(--orange)">${ic('sparkle', 16)}</span>AI photo scan</div>
      <div class="muted" style="margin-bottom:10px">Powers the food photo analysis. Get a key at <b>console.anthropic.com</b> — it stays on your server, and each scan costs well under a cent. ${keyInfo.has_key ? '<b style="color:var(--accent)">✓ Key saved</b>' : '<b style="color:var(--orange)">No key yet</b>'}</div>
      <div class="field"><input id="sKey" type="password" placeholder="${keyInfo.has_key ? '•••••••• (enter new key to replace)' : 'sk-ant-…'}"></div>
      <button class="btn small secondary" id="sKeySave">Save key</button>
    </div>

    <button class="btn danger" id="sLogout" style="margin-top:6px">Log out</button>
    <p class="tiny" style="text-align:center;margin-top:16px">VitaTrack · not medical advice — consult your doctor for health decisions.</p>
  </div>`);

  $('#sEditProfile').onclick = () => { OB.step = 0; OB.data = {}; S.profile.onboarded = 0; render(); };
  $('#segTheme').querySelectorAll('button').forEach(b => b.onclick = async () => {
    S.settings.theme = b.dataset.v; localStorage.setItem('vt_theme', b.dataset.v);
    await api('save_settings', { theme: b.dataset.v }); applyTheme(); render();
  });
  document.querySelectorAll('[data-rem]').forEach(cb => cb.onchange = async () => {
    if (cb.checked && 'Notification' in window && Notification.permission === 'default') {
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') { toast('Enable notifications in your browser settings'); }
    }
    await api('save_settings', { [cb.dataset.rem]: cb.checked ? '1' : '0' });
    S.settings[cb.dataset.rem] = cb.checked ? '1' : '0';
    updateNotifState();
  });
  const updateNotifState = () => {
    const el = $('#notifState'); if (!el) return;
    if (!('Notification' in window)) el.textContent = 'ℹ️ This browser does not support notifications. On iPhone: install the app to your home screen first (Share → Add to Home Screen).';
    else if (Notification.permission === 'denied') el.textContent = '⚠️ Notifications are blocked in browser settings.';
    else if (Notification.permission === 'granted') el.textContent = '✓ Notifications enabled on this device.';
    else el.textContent = 'Turn on a reminder to allow notifications.';
  };
  updateNotifState();
  $('#sKeySave').onclick = async () => {
    const v = $('#sKey').value.trim();
    if (!v) return toast('Paste your API key first');
    await api('save_settings', { anthropic_key: v });
    toast('API key saved'); render();
  };
  $('#sLogout').onclick = async () => { await api('logout'); S.user = null; clearInterval(S.fastTimer); render(); };
}

// ══ REMINDERS (in-app scheduler) ══════════════════════════════════════
function notify(title, body, tag) {
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  if (navigator.serviceWorker?.controller) {
    navigator.serviceWorker.controller.postMessage({ type: 'notify', title, body, tag });
  } else {
    try { new Notification(title, { body, tag }); } catch (e) { /* mobile requires SW */ }
  }
}
function reminderTick() {
  if (!S.user || !S.profile?.onboarded) return;
  const st = S.settings, now = new Date(), h = now.getHours(), mi = now.getMinutes();
  const fired = key => {
    const k = 'vt_rem_' + key + '_' + todayStr() + '_' + h;
    if (localStorage.getItem(k)) return true;
    localStorage.setItem(k, '1'); return false;
  };
  if (st.reminders_water === '1' && h >= 9 && h <= 21 && h % 2 === 1 && mi < 2 && !fired('water')) {
    notify('💧 Water break!', 'Time for a glass of water — stay hydrated to burn fat efficiently.', 'water');
  }
  if (st.reminders_meals === '1' && mi < 2 && [8, 13, 19].includes(h) && !fired('meal')) {
    const meal = h === 8 ? 'breakfast' : h === 13 ? 'lunch' : 'dinner';
    notify('🍽️ ' + meal[0].toUpperCase() + meal.slice(1) + ' time', 'Log your ' + meal + ' — check Recipes for a keto idea!', 'meal');
  }
  if (st.reminders_weight === '1' && h === 8 && mi < 2 && !fired('weigh')) {
    notify('⚖️ Morning weigh-in', 'Best time to weigh: after waking, before eating. Log it now!', 'weigh');
  }
  // tidy old localStorage flags occasionally
  if (Math.floor(Date.now() / 6e4) % 720 === 0) {
    Object.keys(localStorage).filter(k => k.startsWith('vt_rem_') && !k.includes(todayStr())).forEach(k => localStorage.removeItem(k));
  }
}
setInterval(reminderTick, 60000);

// ══ ROUTER ════════════════════════════════════════════════════════════
async function render() {
  clearInterval(S.fastTimer);
  if (!S.user) return renderAuth();
  if (!+S.profile?.onboarded) return renderOnboarding();
  const views = { home: renderHome, diary: renderDiary, fast: renderFast, more: renderMore,
    progress: renderProgress, recipes: renderRecipes, workouts: renderWorkouts, settings: renderSettings,
    learn: renderLearn };
  (views[S.view] || renderHome)();
}

// ══ BOOT ══════════════════════════════════════════════════════════════
window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); S.installPrompt = e; });
(async function boot() {
  applyTheme();
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
  const r = await api('me');
  if (r.ok && r.user) { S.user = r.user; S.profile = r.profile; S.settings = r.settings || {}; }
  applyTheme();
  render();
})();
