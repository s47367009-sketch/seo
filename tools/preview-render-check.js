#!/usr/bin/env node
/**
 * Boots the LIVE preview server's page in jsdom and walks every nav route.
 *
 * This executes the real assets/app.js against the mock API, so it catches
 * runtime errors and dead views before the user opens the page. It does NOT
 * judge appearance — jsdom cannot resolve CSS custom properties, so colours
 * stay unverified without a real browser.
 *
 *   node tools/preview-render-check.js [base]
 */
'use strict';

const { JSDOM, VirtualConsole } = require('/tmp/node_modules/jsdom');

const BASE = process.argv[2] || 'http://127.0.0.1:8080';

const jsErrors = [];
const vc = new VirtualConsole();
vc.on('jsdomError', (e) => jsErrors.push(e.message || String(e)));
vc.on('error', (...a) => jsErrors.push('console.error: ' + a.join(' ')));

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const dom = await JSDOM.fromURL(BASE + '/', {
    runScripts: 'dangerously',
    resources: 'usable',
    pretendToBeVisual: true,
    virtualConsole: vc,
    // jsdom ships no fetch/AbortController; app.js needs both to reach the API.
    beforeParse(window) {
      window.fetch = (url, init) => fetch(new URL(url, BASE), init);
      if (!('AbortController' in window)) window.AbortController = AbortController;
      if (!window.Request) window.Request = Request;
      if (!window.Response) window.Response = Response;
      if (!window.Headers) window.Headers = Headers;
    },
  });
  const { window } = dom;
  const doc = window.document;

  // Wait for the SPA to replace the empty #hs-root shell.
  for (let i = 0; i < 60; i++) {
    if (doc.querySelector('#hs-root').children.length > 0) break;
    await sleep(200);
  }

  const nav = (window.HOOSH && window.HOOSH.nav) || [];
  const routes = [];
  nav.forEach((g) => (g.items || []).forEach((it) => routes.push(it.id)));

  console.log('booted      : ' + BASE);
  console.log('root children: ' + doc.querySelector('#hs-root').children.length);
  console.log('routes      : ' + routes.length);

  const sidebar = doc.querySelectorAll('.hs-nav a, .hs-nav button, nav a, nav button').length;
  console.log('nav elements : ' + sidebar);

  const bad = [];
  const rendered = [];

  for (const r of routes) {
    const before = jsErrors.length;
    window.location.hash = '#/' + r;
    if (typeof window.HS !== 'undefined' && window.HS.renderRoute) window.HS.renderRoute();
    await sleep(350);

    const view = doc.querySelector('#hs-view') || doc.querySelector('#hs-root');
    const html = view ? view.innerHTML : '';
    const text = view ? (view.textContent || '') : '';
    const errs = jsErrors.length - before;

    const suspicious = /\bundefined\b|\bNaN\b|\[object Object\]/.exec(text);
    const empty = html.trim().length < 40;

    rendered.push({ route: r, chars: html.length, buttons: view ? view.querySelectorAll('button').length : 0, tables: view ? view.querySelectorAll('table').length : 0 });

    if (errs || suspicious || empty) {
      bad.push({ route: r, errs, suspicious: suspicious ? suspicious[0] : null, chars: html.length });
    }
  }

  console.log('\nroute                     chars  buttons  tables');
  rendered.forEach((r) => {
    console.log('  ' + r.route.padEnd(20) + String(r.chars).padStart(6) + String(r.buttons).padStart(9) + String(r.tables).padStart(8));
  });

  console.log('\njsdom errors : ' + jsErrors.length);
  jsErrors.slice(0, 8).forEach((e) => console.log('  ! ' + e.slice(0, 200)));

  console.log('bad routes   : ' + bad.length);
  bad.forEach((b) => console.log('  ✗ ' + b.route + ' errors=' + b.errs + ' suspicious=' + b.suspicious + ' chars=' + b.chars));

  const ok = jsErrors.length === 0 && bad.length === 0 && routes.length > 0;
  console.log(ok ? '\nall routes render clean' : '\nFAILED');
  window.close();
  process.exit(ok ? 0 : 1);
})();
