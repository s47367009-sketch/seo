#!/usr/bin/env node
/**
 * Local preview server for Hoosh SEO Studio.
 *
 * Serves the plugin's REAL assets/app.css and assets/app.js, with a
 * window.HOOSH config extracted from the plugin's own PHP source
 * (tools/preview-config.php) and a mock REST API underneath.
 *
 * What this is faithful about: markup, CSS, the whole SPA, the nav, the
 * component library — the real shipped files, byte for byte.
 * What is NOT real: every number. There is no WordPress and no database here,
 * so the API responses are fixtures. Appearance is the point; data is not.
 *
 *   node tools/preview-server.js [port]
 */
'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const PORT = parseInt(process.argv[2] || process.env.PORT || '8080', 10);
const CONFIG_FILE = process.env.HOOSH_PREVIEW_CONFIG || '/tmp/hs-config.json';

/* ------------------------------------------------------------------ config */

let extracted;
try {
  extracted = JSON.parse(fs.readFileSync(CONFIG_FILE, 'utf8'));
} catch (e) {
  console.error('FATAL: cannot read ' + CONFIG_FILE + ' (' + e.message + ')');
  console.error('Generate it first:  php tools/preview-config.php > ' + CONFIG_FILE);
  process.exit(1);
}

const CONFIG = {
  version: '1.1.1',
  rest: '/api/',
  nonce: 'preview-nonce',
  studioUrl: '/',
  site: {
    name: 'فروشگاه نمونه',
    home: 'http://localhost:8080/',
    admin: 'http://localhost:8080/wp-admin/',
    timezone: 'Asia/Tehran',
    locale: 'fa_IR',
    charset: 'UTF-8',
    wp: '6.8.2',
    php: '8.3.0',
    multisite: false,
  },
  user: {
    id: 1,
    name: 'مدیر سایت',
    email: 'admin@example.com',
    canManage: true,
    avatar: '',
  },
  appearance: {
    theme: 'dark',
    accent: 'firouzeh',
    density: 'comfortable',
    font: 'vazirmatn',
    remoteFont: false, // no CDN here; the preview must not depend on jsdelivr
    fontUrl: '',
    digits: true,
    shamsi: true,
    customCss: '',
  },
  nav: extracted.nav || [],
  taxonomies: [],
  postTypes: [
    { name: 'post', label: 'نوشته‌ها' },
    { name: 'page', label: 'برگه‌ها' },
    { name: 'product', label: 'محصولات' },
  ],
  settings: {},
  ai: {
    enabled: true,
    driver: 'gapgpt',
    providers: extracted.aiProviders || [],
    tasks: extracted.aiTasks || [],
    spend: { total: 0.42, month: 0.42, calls: 37, by_driver: { gapgpt: 0.42 } },
  },
  keywordSources: extracted.keywordSources || [],
  schemaTypes: extracted.schemaTypes || [],
  checks: extracted.checks || [],
  features: { woo: true, gsc: false, indexing: false, ai: true, agent: true },
  notices: [],
  kpiSnapshot: { score: 74, issues: 18, indexed: 122, notfound: 7 },
};

/* ------------------------------------------------------------- mock api ---- */

const SKILLS = [
  { id: 'audit_fix', label: 'رفع ایراد فنی', about: 'نوایندکس اشتباه، کنونیکال تکراری و خطاهای ممیزی را درست می‌کند.', cost: 0, risk: 'low', enabled: true, group: 'technical' },
  { id: 'meta_fill', label: 'تکمیل عنوان و توضیح', about: 'برای صفحاتی که عنوان یا توضیح متا ندارند، متن بهینه می‌سازد.', cost: 1, risk: 'medium', enabled: true, group: 'content' },
  { id: 'internal_link', label: 'لینک داخلی', about: 'به محتوای یتیم و صفحات نزدیک به رتبهٔ اول لینک می‌دهد.', cost: 0, risk: 'medium', enabled: true, group: 'content' },
  { id: 'alt_fill', label: 'تکمیل متن جایگزین تصویر', about: 'برای تصاویر بدون alt متن مرتبط می‌نویسد.', cost: 1, risk: 'low', enabled: true, group: 'content' },
  { id: 'schema_fill', label: 'تکمیل اسکیما', about: 'اسکیمای ساخت‌یافتهٔ ناقص را کامل می‌کند.', cost: 1, risk: 'low', enabled: true, group: 'technical' },
  { id: 'redirect_404', label: 'ریدایرکت ۴۰۴', about: 'خطاهای ۴۰۴ را به نزدیک‌ترین صفحهٔ سالم می‌برد.', cost: 0, risk: 'high', enabled: true, group: 'technical' },
  { id: 'index_submit', label: 'ارسال به ایندکس', about: 'نشانی‌های تازه یا اصلاح‌شده را به گوگل اعلام می‌کند.', cost: 0, risk: 'low', enabled: true, group: 'technical' },
  { id: 'optimize_post', label: 'بازنویسی محتوای ضعیف', about: 'محتوای نازک را با حفظ لحن سایت بازنویسی می‌کند.', cost: 1, risk: 'high', enabled: true, group: 'content' },
  { id: 'content_gap', label: 'کشف شکاف محتوا', about: 'از دادهٔ سرچ کنسول فرصت‌های بی‌پوشش پیدا می‌کند.', cost: 1, risk: 'low', enabled: true, group: 'strategy' },
  { id: 'write_article', label: 'نوشتن مقاله', about: 'مقالهٔ کامل بر پایهٔ کلیدواژهٔ هدف تولید می‌کند.', cost: 1, risk: 'high', enabled: false, group: 'strategy' },
];

const AUTONOMY = [
  { id: 'manual', label: 'دستی', about: 'ایجنت هیچ تغییری نمی‌دهد؛ فقط تحلیل و پیشنهاد.' },
  { id: 'supervised', label: 'نیمه‌خودکار', about: 'کارهای کم‌خطر خودکار، کارهای پرخطر با تأیید شما.' },
  { id: 'autopilot', label: 'کاملاً خودکار', about: 'همهٔ مهارت‌های روشن بدون تأیید اجرا می‌شوند.' },
];

const RUNS = [
  { id: 41, status: 'done', phase: 'fix', steps: 8, done: 6, skipped: 1, failed: 1, score_before: 74, score_after: 89, cost_usd: 0.06, started: '2026-09-08 03:00', ms: 94210 },
  { id: 40, status: 'done', phase: 'fix', steps: 5, done: 5, skipped: 0, failed: 0, score_before: 70, score_after: 74, cost_usd: 0.03, started: '2026-09-07 03:00', ms: 61880 },
  { id: 39, status: 'review', phase: 'content', steps: 3, done: 2, skipped: 0, failed: 0, score_before: 68, score_after: 70, cost_usd: 0.09, started: '2026-09-06 03:00', ms: 120540 },
];

const ARTICLES = [
  { id: 12, title: 'راهنمای انتخاب کفش رانینگ برای pemula', status: 'draft', words: 1460, score: 91, keyword: 'کفش رانینگ', created: '2026-09-08 03:04' },
  { id: 11, title: 'مقایسهٔ ۷ مدل ساعت هوشمند پرفروش', status: 'publish', words: 1720, score: 88, keyword: 'ساعت هوشمند', created: '2026-09-07 03:02' },
];

const API = {
  'agent/status': () => ({
    enabled: true,
    halted: false,
    autonomy: 'supervised',
    schedule: 'daily',
    can_run: true,
    next_run: '2026-09-09 03:00',
    ai_ready: true,
    driver: 'gapgpt',
    skills: SKILLS,
    autonomy_levels: AUTONOMY,
    totals: { runs: 41, wins: 34, steps: 212, review: 3, cost_usd: 0.42, last_score: 89 },
    last: RUNS[0],
    limits: { max_steps: 25, max_minutes: 8, budget_usd: 2.0, parallel: 4, publish: 'draft', dry_run: false },
    runs: RUNS,
    articles: ARTICLES,
  }),

  'agent/diagnose': () => ({
    score: 74,
    sources: ['internal', 'gsc'],
    findings: [
      { severity: 'critical', message: '۲۴ صفحه عنوان یکتا ندارند و در نتایج یکسان دیده می‌شوند.', skill: 'meta_fill', impact: 25 },
      { severity: 'high', message: '۱۸ نوشته هیچ لینک داخلی ندارند (محتوای یتیم).', skill: 'internal_link', impact: 18 },
      { severity: 'high', message: '۷ نشانی با خطای ۴۰۴ در ۳۰ روز گذشته ثبت شده است.', skill: 'redirect_404', impact: 14 },
      { severity: 'medium', message: '۴۲ تصویر بدون متن جایگزین در انبار رسانه.', skill: 'alt_fill', impact: 9 },
      { severity: 'medium', message: '۶ صفحه کنونیکال تکراری به یک نشانی اشاره می‌کنند.', skill: 'audit_fix', impact: 8 },
      { severity: 'low', message: '۱۱ نوشته زیر ۴۰۰ کلمه هستند (محتوای نازک).', skill: 'optimize_post', impact: 6 },
    ],
  }),

  'agent/opportunities': () => ({
    rows: [
      { keyword: 'کفش رانینگ مردانه', volume: 8100, difficulty: 32, intent: 'commercial', score: 92, source: 'gsc', words: 1400 },
      { keyword: 'ساعت هوشمند ارزان', volume: 5400, difficulty: 28, intent: 'transactional', score: 88, source: 'suggest', words: 1200 },
      { keyword: 'بهترین هدفون بی‌سیم', volume: 3600, difficulty: 41, intent: 'informational', score: 81, source: 'gsc', words: 1600 },
      { keyword: 'کیف چرم دست‌دوز', volume: 1900, difficulty: 22, intent: 'transactional', score: 79, source: 'research', words: 1100 },
      { keyword: 'راهنمای سایز کفش', volume: 2400, difficulty: 19, intent: 'informational', score: 76, source: 'suggest', words: 900 },
    ],
  }),

  'agent/plan': () => ({
    plan: {
      rationale: ['۲۴ صفحه بدون عنوان یکتا', '۱۸ محتوای یتیم', 'بودجهٔ باقی‌ماندهٔ امروز کافی است'],
      steps: [
        { id: 1, skill: 'audit_fix', risk: 'low', target: 'post:31', why: 'کنونیکال تکراری', eta_ms: 1200 },
        { id: 2, skill: 'meta_fill', risk: 'medium', target: 'post:12', why: 'عنوان ندارد', eta_ms: 6400 },
        { id: 3, skill: 'internal_link', risk: 'medium', target: 'post:44', why: 'لینک ورودی ندارد', eta_ms: 900 },
      ],
    },
  }),

  'agent/runs': () => ({ rows: RUNS }),
  'agent/articles': () => ({ rows: ARTICLES }),
  'agent/halt': () => ({ ok: true, halted: true }),
  'agent/run': () => ({ ok: true, run_id: 42, phase: 'fix', steps: 8, done: 6, skipped: 1, failed: 1, score_before: 74, score_after: 89 }),

  overview: () => ({
    score: 89,
    trend: [72, 74, 70, 78, 82, 85, 89],
    kpis: [
      { label: 'امتیاز سلامت', value: 89, delta: 5, hint: 'از ۱۰۰' },
      { label: 'صفحات ایندکس‌شده', value: 122, delta: 6, hint: 'از ۱۳۱' },
      { label: 'ایراد باز', value: 18, delta: -7, hint: '۴ مورد بحرانی' },
      { label: 'خطای ۴۰۴', value: 7, delta: -2, hint: '۳۰ روز گذشته' },
    ],
    tasks: [
      { id: 1, title: 'تکمیل عنوان ۲۴ صفحه', group: 'محتوا', risk: 'medium', done: false },
      { id: 2, title: 'لینک داخلی به ۱۸ نوشتهٔ یتیم', group: 'محتوا', risk: 'medium', done: false },
      { id: 3, title: 'ریدایرکت ۷ خطای ۴۰۴', group: 'فنی', risk: 'high', done: false },
    ],
    activity: [
      { at: 'امروز ۰۳:۰۰', text: 'ایجنت ۶ کار انجام داد و امتیاز را از ۷۴ به ۸۹ رساند.', kind: 'ok' },
      { at: 'دیروز ۰۳:۰۰', text: '۵ عنوان و توضیح متا تکمیل شد.', kind: 'info' },
      { at: '۲ روز پیش', text: '۳ تغییر نیازمند بازبینی شماست.', kind: 'warn' },
    ],
  }),
};

// Anything not listed above gets a shaped-but-empty response so the SPA
// renders its real empty state instead of throwing.
function fallback(route) {
  if (/(list|pages|items|rows|runs|keywords|redirects|rules|report)/.test(route)) {
    return { rows: [], total: 0, route: route };
  }
  return { ok: true, rows: [], total: 0, score: 0, route: route };
}

/* ------------------------------------------------------------- http -------- */

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.json': 'application/json; charset=utf-8',
  '.woff2': 'font/woff2',
};

function page() {
  return (
    '<!doctype html>\n' +
    '<html lang="fa-IR" dir="rtl" data-theme="' + CONFIG.appearance.theme + '">\n' +
    '<head>\n' +
    '<meta charset="UTF-8">\n' +
    '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">\n' +
    '<meta name="robots" content="noindex, nofollow">\n' +
    '<title>پیش‌نمایش استودیوی هوش‌سئو</title>\n' +
    '<script>window.HOOSH = ' + JSON.stringify(CONFIG) + ';</script>\n' +
    '<link rel="stylesheet" href="/assets/app.css?ver=' + CONFIG.version + '">\n' +
    '</head>\n' +
    '<body class="hs-body hs-density-' + CONFIG.appearance.density + ' hs-accent-' + CONFIG.appearance.accent + '">\n' +
    '<div id="hs-root" class="hs-app" aria-busy="true"></div>\n' +
    '<noscript><div class="hs-noscript">جاوااسکریپت لازم است.</div></noscript>\n' +
    '<script src="/assets/app.js?ver=' + CONFIG.version + '" defer></script>\n' +
    '</body>\n</html>\n'
  );
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const pathname = decodeURIComponent(url.pathname);

  if (pathname === '/' || pathname === '/index.html') {
    res.writeHead(200, { 'Content-Type': TYPES['.html'] });
    return res.end(page());
  }

  if (pathname.startsWith('/api/')) {
    const route = pathname.replace(/^\/api\//, '').replace(/\/+$/, '');
    const hit = API[route];
    const body = hit ? hit(url) : fallback(route);
    res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
    return res.end(JSON.stringify(body));
  }

  if (pathname.startsWith('/assets/')) {
    const file = path.join(ROOT, pathname);
    if (!file.startsWith(ROOT)) { res.writeHead(403); return res.end('forbidden'); }
    return fs.readFile(file, (err, buf) => {
      if (err) { res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' }); return res.end('404 ' + pathname); }
      res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream' });
      res.end(buf);
    });
  }

  res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
  res.end('404 ' + pathname);
});

server.listen(PORT, '0.0.0.0', () => {
  console.log('Hoosh SEO Studio preview on 0.0.0.0:' + PORT);
  console.log('  nav groups : ' + CONFIG.nav.length);
  console.log('  nav items  : ' + CONFIG.nav.reduce((n, g) => n + (g.items || []).length, 0));
  // The PHP catalogues are associative arrays, so they arrive as objects.
  const n = (o) => (Array.isArray(o) ? o.length : Object.keys(o || {}).length);
  console.log('  ai providers: ' + n(CONFIG.ai.providers) + ', tasks: ' + n(CONFIG.ai.tasks));
  console.log('  schema types: ' + n(CONFIG.schemaTypes) + ', content checks: ' + n(CONFIG.checks));
  console.log('  mock routes: ' + Object.keys(API).length + ' (+ generic fallback)');
});
