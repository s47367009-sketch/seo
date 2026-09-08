/**
 * Contract check between the agent screen's CSS and its markup.
 *
 * jsdom does NOT implement CSS custom-property substitution (getComputedStyle
 * returns the literal "var(--ok)"), so no colour can be verified here — there
 * is no browser in this environment and visual rendering stays unverified.
 *
 * What jsdom *can* prove is the selector/markup contract: every class the CSS
 * targets must exist in the markup, and the section must not freeze colours
 * with hardcoded fallbacks (which is what made it ignore the theme).
 */
const fs = require('fs');
const { JSDOM } = require('jsdom');

const css = fs.readFileSync(require('path').join(__dirname, '..', 'assets', 'app.css'), 'utf8');
const start = css.indexOf('/* =================== 23. Mission Control');
const section = css.slice(start);

// Class names the agent view emits (assets/app.js, HS.view('agent')).
const MARKUP = `
<div class="hs-app">
  <div class="hs-row hs-row--wrap hs-agenthead">
    <div><h1 class="hs-agenthead__title">ایجنت</h1></div>
    <div class="hs-scorebox hs-scorebox--ok"><strong>90</strong><span>امتیاز</span>
      <div class="hs-scorebox__bar"><i></i></div></div>
    <div class="hs-scorebox hs-scorebox--warn"><strong>12</strong><span>کار</span></div>
    <div class="hs-scorebox hs-scorebox--bad"><strong>3</strong><span>خطا</span></div>
  </div>
  <div class="hs-agentauto">
    <button class="hs-autocard is-on"><strong>نیمه‌خودکار</strong><span>توضیح</span></button>
    <button class="hs-autocard"><strong>دستی</strong><span>توضیح</span></button>
  </div>
  <div class="hs-findings">
    <div class="hs-finding hs-finding--critical"><span class="hs-finding__sev">بحرانی</span>
      <div class="hs-finding__body"><p>متن</p></div>
      <div class="hs-finding__impact"><span>25</span><small>امتیاز</small></div></div>
    <div class="hs-finding hs-finding--high"><span class="hs-finding__sev">مهم</span></div>
    <div class="hs-finding hs-finding--medium"><span class="hs-finding__sev">متوسط</span></div>
  </div>
</div>`;

const dom = new JSDOM(`<!doctype html><html dir="rtl"><body>${MARKUP}</body></html>`);
const doc = dom.window.document;

// Every .hs-* selector inside section 23 must match something in the markup.
const selectors = [...new Set([...section.matchAll(/\.(hs-[a-zA-Z0-9_-]+)/g)].map((m) => '.' + m[1]))];
const unmatched = selectors.filter((sel) => !doc.querySelector(sel));

// A var() with a hardcoded colour fallback freezes the colour and ignores the
// theme/accent switches — the exact regression this guards against.
const frozen = [...section.matchAll(/var\(\s*--[a-zA-Z0-9-]+\s*,\s*(#[0-9a-fA-F]{3,8}|rgba?\([^)]*\))\)/g)];

console.log(`agent CSS classes : ${selectors.length}`);
console.log(`unmatched         : ${unmatched.length}${unmatched.length ? ' → ' + unmatched.join(', ') : ''}`);
console.log(`frozen colours    : ${frozen.length}`);

const ok = unmatched.length === 0 && frozen.length === 0;
console.log(ok ? '\ncontract holds' : '\nFAILED');
process.exit(ok ? 0 : 1);
