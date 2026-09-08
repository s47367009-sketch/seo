/**
 * HooshSEO — editor UI.
 *
 * Builds the SEO metabox for the classic editor and exposes the same component
 * to assets/gutenberg.js for the block editor, so both editors share one brain.
 *
 * window.HooshEditor.create({ root, tabHost, scoreHost, postId, value, onChange, source, compact })
 *   -> { refresh(value), paint(analysis), focusTab(id), destroy() }
 */
(function (window, document) {
	'use strict';

	var E = (window.HOOSH_EDITOR || {});
	var T = E.i18n || {};
	var S = E.settings || {};

	/* ---------- tiny DOM ---------- */
	function el(tag, props, kids) {
		var n = document.createElement(tag);
		if (props) {
			Object.keys(props).forEach(function (k) {
				var v = props[k];
				if (v === null || v === undefined || v === false) { return; }
				if (k === 'class') { n.className = v; }
				else if (k === 'text') { n.textContent = v; }
				else if (k === 'html') { n.innerHTML = v; }
				else if (k === 'style') { Object.keys(v).forEach(function (s) { n.style[s] = v[s]; } ); }
				else if (k === 'dataset') { Object.keys(v).forEach(function (s) { n.setAttribute('data-' + s, v[s]); } ); }
				else if (k.indexOf('on') === 0 && typeof v === 'function') { n.addEventListener(k.slice(2), v); }
				else { n.setAttribute(k, v === true ? '' : v); }
			});
		}
		(kids || []).forEach(function (kid) {
			if (kid === null || kid === undefined || kid === false) { return; }
			n.appendChild(typeof kid === 'string' || typeof kid === 'number' ? document.createTextNode(String(kid)) : kid);
		});
		return n;
	}
	function q(sel, root) { return (root || document).querySelector(sel); }
	function qa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	/* ---------- REST ---------- */
	function api(path, data, method) {
		var url = (E.rest || '/wp-json/hoosh/v1') + '/' + String(path).replace(/^\/+/, '');
		var opt = {
			method: method || (data ? 'POST' : 'GET'),
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': E.nonce || '' }
		};
		if (data) { opt.body = JSON.stringify(data); }
		return window.fetch(url, opt).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) {
					var msg = (j && (j.message || (j.data && j.data.message))) || (T.error || 'خطا');
					throw new Error(String(msg).replace(/<[^>]+>/g, ''));
				}
				return j;
			});
		});
	}

	/* ---------- formatters ---------- */
	function len(s) { return Array.from(String(s || '')).length; }
	function digits(s) {
		if (!S.digits) { return String(s); }
		return String(s).replace(/[0-9]/g, function (x) { return '۰۱۲۳۴۵۶۷۸۹'[+x]; });
	}

	/* ============================================================= */
	function create(opts) {
		opts = opts || {};
		var api = {};
		var root = opts.root;
		var value = Object.assign({
			title: '', description: '', keywords: [], canonical: '', slug: '',
			redirect: { url: '', type: 301 }, primaryCat: 0,
			social: { title: '', description: '', image_id: 0, skip: false },
			robots: { index: true, follow: true, nocache: false, noarchive: false, nosnippet: false, indexifembedded: false },
			schema: [], faq: [], howto: [], breadcrumb: '', geo: {}, ai_notes: ''
		}, opts.value || {});
		value.social = Object.assign({ title: '', description: '', image_id: 0, skip: false }, value.social || {});
		value.robots = Object.assign({ index: true, follow: true, nocache: false, noarchive: false, nosnippet: false, indexifembedded: false }, value.robots || {});
		if (!Array.isArray(value.keywords)) { value.keywords = String(value.keywords || '').split(/[,،\n]/).map(function (x) { return x.trim(); }).filter(Boolean); }

		var listeners = [];
		var dirty = false;
		var saveTimer = null;
		var lastAnalysis = null;

		function emit(patch) {
			Object.keys(patch).forEach(function (k) {
				if (patch[k] && typeof patch[k] === 'object' && !Array.isArray(patch[k]) && value[k]) {
					value[k] = Object.assign({}, value[k], patch[k]);
				} else {
					value[k] = patch[k];
				}
			});
			dirty = true;
			if (opts.onChange) { opts.onChange(patch, value); }
			scheduleSave();
			scheduleAnalyze();
		}
		function scheduleSave() {
			if (opts.live === false) { return; }
			clearTimeout(saveTimer);
			saveTimer = setTimeout(function () {
				if (!dirty) { return; }
				dirty = false;
				api('post/' + (opts.postId || E.postId || 0), value).then(function (res) {
					flag('on');
					if (res && res.analysis) { api.paint(res.analysis); }
				}, function () { flag('off'); });
			}, 1400);
		}
		function flag(state) {
			var f = q('.hs-mb-saved', root);
			if (!f) { return; }
			f.textContent = state === 'on' ? (T.saved || 'ذخیره شد') : (T.saving || '…');
			f.classList.toggle('is-on', state === 'on');
			if (state === 'on') { setTimeout(function () { f.classList.remove('is-on'); }, 1600); }
		}
		var anTimer = null;
		function scheduleAnalyze() {
			clearTimeout(anTimer);
			anTimer = setTimeout(function () { api.analyze(); }, 900);
		}

		/* ---------- render ---------- */
		root.innerHTML = '';
		root.classList.add('hs-mb');
		root.setAttribute('data-accent', S.accent || 'firouzeh');
		var tabs = ['seo', 'readability', 'social', 'schema', 'ai'];
		var labels = { seo: T.seo || 'سئو', readability: T.readability || 'خوانایی', social: T.social || 'شبکه', schema: T.schema || 'اسکیما', ai: T.ai || 'هوش مصنوعی' };
		var panes = {};
		var body = el('div', { class: 'hs-mb-body' });

		var tabHost = opts.tabHost;
		if (!tabHost) {
			tabHost = el('div', { class: 'hs-mb-tabs', role: 'tablist' });
			root.appendChild(tabHost);
		}
		tabs.forEach(function (id) {
			var b = q('.hs-tab[data-tab="' + id + '"]', tabHost);
			if (!b) {
				b = el('button', { type: 'button', class: 'hs-tab', dataset: { tab: id }, role: 'tab', text: labels[id] });
				tabHost.appendChild(b);
			}
			listeners.push([b, 'click', function () { api.focusTab(id); }]);
		});

		tabs.forEach(function (id) {
			panes[id] = el('div', { class: 'hs-mb-pane', dataset: { pane: id }, role: 'tabpanel' });
			body.appendChild(panes[id]);
		});
		root.appendChild(body);
		var saved = el('span', { class: 'hs-mb-saved' });
		body.appendChild(el('div', { class: 'hs-mb-row hs-mb-row--end', style: { marginTop: '10px' } }, [saved, opts.compact ? null : el('a', { class: 'hs-mb-btn hs-mb-btn--ghost', href: E.studio || '#', target: '_blank', rel: 'noopener', text: T.studio || 'استودیو' })]));

		api.focusTab = function (id) {
			qa('.hs-tab', tabHost).forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-tab') === id); });
			qa('.hs-mb-pane', body).forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-pane') === id); });
		};
		api.focusTab('seo');

		/* ---------- shared field builders ---------- */
		function field(labelText, node, help) {
			return el('div', { class: 'hs-mb-f' }, [
				labelText ? el('span', { class: 'hs-mb-f-l', text: labelText }) : null,
				node,
				help ? el('span', { class: 'hs-mb-f-h', text: help }) : null
			]);
		}

		/* ---------- SEO pane ---------- */
		(function () {
			var p = panes.seo;
			var top = el('div', { class: 'hs-mb-top' });
			var donut = el('div', { class: 'hs-mb-donut' }, [
				(function () {
					var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
					svg.setAttribute('viewBox', '0 0 42 42');
					var bg = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
					bg.setAttribute('cx', '21'); bg.setAttribute('cy', '21'); bg.setAttribute('r', '15.9'); bg.setAttribute('class', 'bg');
					var fg = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
					fg.setAttribute('cx', '21'); fg.setAttribute('cy', '21'); fg.setAttribute('r', '15.9'); fg.setAttribute('class', 'fg');
					fg.setAttribute('stroke-dasharray', '100 100');
					fg.setAttribute('stroke-dashoffset', '100');
					svg.appendChild(bg); svg.appendChild(fg);
					return svg;
				})(),
				el('b', { text: '—' })
			]);
			var facts = el('div', { class: 'hs-mb-facts' });
			top.appendChild(donut);
			top.appendChild(facts);
			top.appendChild(el('div', { class: 'hs-mb-row', style: { marginInlineStart: 'auto', gap: '6px' } }, [
				el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--sm', text: T.preview || 'پیش‌نمایش', onclick: function () { api.focusTab('seo'); preview.scrollIntoView({ block: 'center', behavior: 'smooth' }); } }),
				el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--sm', text: T.analysis || 'نتایج بررسی', onclick: function () { api.focusTab('readability'); } })
			]));
			p.appendChild(top);

			/* keywords */
			var chips = el('div', { class: 'hs-mb-chips' });
			var kIn = el('input', { type: 'text', placeholder: T.addKeyword || 'افزودن کلمه کلیدی…' });
			function paintKw() {
				qa('.hs-mb-chip', chips).forEach(function (c) { c.remove(); });
				(value.keywords || []).forEach(function (k, i) {
					var chip = el('span', { class: 'hs-mb-chip' + (i === 0 ? ' is-focus' : '') }, [
						document.createTextNode(k),
						i > 0 ? el('button', { type: 'button', title: 'اول شود', text: '★', onclick: function () { var a = value.keywords.slice(); a.splice(i, 1); a.unshift(k); emit({ keywords: a }); paintKw(); } }) : null,
						el('button', { type: 'button', text: '×', onclick: function () { var a = value.keywords.slice(); a.splice(i, 1); emit({ keywords: a }); paintKw(); } })
					]);
					chips.insertBefore(chip, kIn);
				});
				if (!(value.keywords || []).length) {
					chips.insertBefore(el('span', { class: 'hs-mb-f-h', text: T.noKeywords || 'کلمه کلیدی ندارید' }), kIn);
				}
			}
			kIn.addEventListener('keydown', function (ev) {
				if (ev.key !== 'Enter' && ev.key !== ',') { return; }
				ev.preventDefault();
				var v = kIn.value.trim().replace(/[,،]/g, '');
				if (!v) { return; }
				var a = (value.keywords || []).slice();
				if (a.indexOf(v) > -1) { kIn.value = ''; return; }
				if (a.length >= (S.maxFocus || 5)) { a.shift(); }
				a.push(v);
				kIn.value = '';
				emit({ keywords: a });
				paintKw();
			});
			paintKw();
			p.appendChild(field(
				(T.suggestions || 'کلمات کلیدی') + ' (' + digits(0) + '/' + digits(S.maxFocus || 5) + ')',
				el('div', { class: 'hs-mb-row' }, [chips, el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--sm hs-mb-btn--ghost', text: T.researchKw || 'تحقیق کلمه کلیدی', onclick: function () { openResearch(); } })]),
				'اولین کلمه، کلمه اصلی نوشته است.'
			));

			var g = el('div', { class: 'hs-mb-grid' });
			var tIn = el('input', { class: 'hs-mb-in', type: 'text', name: 'hoosh_seo[title]', value: value.title || '', placeholder: S.siteName ? (postTitle() + ' ' + (S.sep || '|') + ' ' + S.siteName) : '' });
			var dIn = el('textarea', { class: 'hs-mb-ta', name: 'hoosh_seo[description]', rows: 3, placeholder: T.description || 'توضیحات متا' });
			dIn.value = value.description || '';
			var meterT = el('div', { class: 'hs-mb-meter' }, [el('i')]);
			var cntT = el('span', { class: 'hs-mb-count' });
			var meterD = el('div', { class: 'hs-mb-meter' }, [el('i')]);
			var cntD = el('span', { class: 'hs-mb-count' });
			function paintMeta() {
				var tl = len(tIn.value) || len(postTitle());
				var max = S.titleMax || 60;
				cntT.textContent = digits(tl) + ' / ' + digits(max);
				cntT.classList.toggle('is-over', tl > max || tl < 25);
				meterT.firstChild.style.width = Math.min(100, (tl / max) * 100) + '%';
				meterT.classList.toggle('is-over', tl > max || tl < 25);
				var dl = len(dIn.value);
				var dmax = S.descMax || 158;
				cntD.textContent = digits(dl) + ' / ' + digits(dmax);
				cntD.classList.toggle('is-over', dl > dmax || (dl > 0 && dl < 70));
				meterD.firstChild.style.width = Math.min(100, (dl / dmax) * 100) + '%';
				meterD.classList.toggle('is-over', dl > dmax || (dl > 0 && dl < 70));
				paintPreview();
			}
			var onT = function () { emit({ title: tIn.value }); paintMeta(); };
			tIn.addEventListener('input', onT); tIn.addEventListener('change', onT);
			var onD = function () { emit({ description: dIn.value }); paintMeta(); };
			dIn.addEventListener('input', onD); dIn.addEventListener('change', onD);
			g.appendChild(field(T.title, tIn));
			g.appendChild(meterT);
			g.appendChild(cntT);
			g.appendChild(field(T.description, dIn));
			g.appendChild(meterD);
			g.appendChild(cntD);
			p.appendChild(g);

			/* preview */
			var preview = el('div', { class: 'hs-mb-preview' }, [
				el('div', { class: 'hs-mb-preview__u' }, [el('span', { class: 'hs-mb-preview__fav', text: 'ه' }), el('span', { text: hostOf() })]),
				el('div', { class: 'hs-mb-preview__t' }),
				el('p', { class: 'hs-mb-preview__d' })
			]);
			p.appendChild(el('div', { class: 'hs-mb-row' }, [el('span', { class: 'hs-mb-f-l', text: T.preview || 'پیش‌نمایش گوگل' }), el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--sm hs-mb-btn--ghost', text: T.mobile || 'موبایل', onclick: function () { preview.classList.toggle('hs-mb-preview--mobile'); } })]));
			p.appendChild(preview);
			function paintPreview() {
				preview.querySelector('.hs-mb-preview__t').textContent = tIn.value || postTitle() || '(عنوان)';
				preview.querySelector('.hs-mb-preview__d').textContent = dIn.value || excerpt() || '—';
			}
			paintMeta();

			/* canonical / slug / breadcrumb */
			var g2 = el('div', { class: 'hs-mb-grid' });
			[['canonical', T.canonical, 'https://…'], ['slug', T.slug, ''], ['breadcrumb', 'مسیر راهنما (breadcrumb)', 'کتاب‌فروشی › رمان']].forEach(function (r) {
				var i2 = el('input', { class: 'hs-mb-in', type: 'text', name: 'hoosh_seo[' + r[0] + ']', value: String(value[r[0]] || ''), placeholder: r[2] });
				var fn = function () { var o = {}; o[r[0]] = i2.value; emit(o); };
				i2.addEventListener('input', fn); i2.addEventListener('change', fn);
				g2.appendChild(field(r[1], i2));
			});
			var rg = el('input', { class: 'hs-mb-in', type: 'text', name: 'hoosh_seo[redirect][url]', value: (value.redirect && value.redirect.url) || '', placeholder: '/old-target' });
			var rt = el('select', { class: 'hs-mb-se', name: 'hoosh_seo[redirect][type]' });
			[301, 302, 307].forEach(function (c) { var o = el('option', { value: c, text: String(c) }); if (String(((value.redirect || {}).type) || 301) === String(c)) { o.selected = true; } rt.appendChild(o); });
			var rf = function () { emit({ redirect: { url: rg.value, type: parseInt(rt.value, 10) } }); };
			rg.addEventListener('change', rf); rt.addEventListener('change', rf);
			g2.appendChild(field('ریدایرکت این صفحه به', rg));
			g2.appendChild(rt);
			p.appendChild(g2);

			/* robots */
			var rk = el('div', { class: 'hs-mb-checks' });
			[['index', 'ایندکس شود'], ['follow', 'لینک‌ها دنبال شود'], ['nocache', 'بدون کش'], ['noarchive', 'بدون آرشیو'], ['nosnippet', 'بدون توضیح در نتایج'], ['indexifembedded', 'ایندکس در حالت جاسازی']].forEach(function (r) {
				var cb = el('input', { type: 'checkbox', name: 'hoosh_seo[robots][' + r[0] + ']' });
				cb.checked = (r[0] === 'index' || r[0] === 'follow') ? value.robots[r[0]] !== false : !!value.robots[r[0]];
				var lab = el('label', { class: 'hs-mb-check' + (cb.checked ? ' is-on' : '') }, [cb, document.createTextNode(r[1])]);
				cb.addEventListener('change', function () {
					var o = {};
					o[r[0]] = cb.checked;
					lab.classList.toggle('is-on', cb.checked);
					emit({ robots: o });
				});
				rk.appendChild(lab);
			});
			p.appendChild(field(T.robots, rk));
		})();

		/* ---------- readability pane ---------- */
		(function () {
			var p = panes.readability;
			p.appendChild(el('div', { class: 'hs-mb-row hs-mb-row--between' }, [
				el('span', { class: 'hs-mb-f-l', text: T.analysis || 'نتایج بررسی' }),
				el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--sm', text: 'بررسی دوباره', onclick: function () { api.analyze(true); } })
			]));
			var meta = el('div', { class: 'hs-mb-facts', style: { margin: '8px 0 12px' } });
			var list = el('div', { class: 'hs-mb-list' }, [el('div', { class: 'hs-mb-note', text: 'هنوز بررسی نشده — پس از تغییر متن، خودکار انجام می‌شود.' })]);
			p.appendChild(meta);
			p.appendChild(list);
			api.paintChecks = function (a) {
				meta.innerHTML = '';
				[[T.words || 'کلمه', digits(a.words || 0)], [T.readTime || 'زمان مطالعه', digits(Math.max(1, Math.ceil((a.reading_time || a.words / 200)))) + ' ' + (T.min || 'دقیقه')], ['عنوان', digits(len(a.metrics && a.metrics.title_chars)) + ' نویسه'], ['توضیح', digits(len(a.metrics && a.metrics.desc_chars)) + ' نویسه'], ['چگالی', digits(((a.metrics && a.metrics.density) || 0) * 100, 1) + '٪'], [T.linksIn || 'لینک داخلی', digits((a.metrics && a.metrics.internal) || 0)], [T.linksOut || 'لینک خارجی', digits((a.metrics && a.metrics.external) || 0)]].forEach(function (r) {
					meta.appendChild(el('span', {}, [document.createTextNode(r[0] + ': '), el('b', { text: r[1] })]));
				});
				list.innerHTML = '';
				var items = (a.issues || []).slice();
				var groups = { seo: [], readability: [], content: [], other: [] };
				items.forEach(function (it) { (groups[it.group] || groups.other).push(it); });
				var painted = 0;
				Object.keys(groups).forEach(function (gk) {
					if (!groups[gk].length) { return; }
					painted++;
					list.appendChild(el('div', { class: 'hs-mb-group', text: ({ seo: T.seo, readability: T.readability, content: T.summary, other: 'سایر' })[gk] || gk }));
					groups[gk].forEach(function (it) { api.checkItem(it, list); });
				});
				if (!painted) { list.appendChild(el('div', { class: 'hs-mb-note', text: (T.passed || 'خوب است') + ' ✓' })); }
			};
		})();

		api.checkItem = function (it, host) {
			var st = it.status === 'pass' ? 'ok' : (it.status === 'warn' ? 'warn' : 'bad');
			var row = el('div', { class: 'hs-mb-item hs-mb-item--' + st });
			row.appendChild(el('span', { class: 'hs-mb-item__i is-' + st, text: st === 'ok' ? '✓' : (st === 'warn' ? '!' : '×') }));
			var m = el('div', { class: 'hs-mb-item__m' }, [
				el('b', { text: it.label || it.id }),
				it.value ? el('span', { text: digits(it.value) + (it.unit ? ' ' + it.unit : '') }) : null,
				it.why ? el('em', { text: it.why }) : null
			]);
			var acts = el('div', { class: 'hs-mb-row', style: { gap: '4px' } });
			if (it.how && it.status !== 'pass') { acts.appendChild(el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--sm hs-mb-btn--ghost', text: 'راهنما', title: it.how, onclick: function () { m.appendChild(el('em', { text: it.how })); this.remove(); } })); }
			if (it.fix && st !== 'ok' && E.aiReady) {
				acts.appendChild(el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--sm hs-mb-btn--pri', text: T.fixWithAI || 'اصلاح', onclick: function (ev, b) {
					b = ev.currentTarget;
					b.classList.add('is-busy');
					api('content/fix', { post_id: opts.postId || E.postId || 0, check: it.id, mode: it.fix === 'ai' ? 'ai' : 'auto', payload: {} }).then(function (r) {
						b.classList.remove('is-busy');
						if (r && r.changed) { api.analyze(true); }
						var msg = el('span', { class: 'hs-mb-f-h', text: (r && r.message) || (T.done || 'انجام شد') });
						m.appendChild(msg);
						setTimeout(function () { msg.remove(); }, 2600);
					}, function (e) { b.classList.remove('is-busy'); m.appendChild(el('em', { text: e.message })); });
				} }));
			}
			m.appendChild(acts);
			row.appendChild(m);
			host.appendChild(row);
			return row;
		};

		/* ---------- social pane ---------- */
		(function () {
			var p = panes.social;
			var g = el('div', { class: 'hs-mb-grid' });
			var st = el('input', { class: 'hs-mb-in', type: 'text', name: 'hoosh_seo[social][title]', value: value.social.title || '' });
			var sd = el('textarea', { class: 'hs-mb-ta', name: 'hoosh_seo[social][description]', rows: 3 });
			sd.value = value.social.description || '';
			var f1 = function () { emit({ social: { title: st.value, description: sd.value } }); };
			[st, sd].forEach(function (i2) { i2.addEventListener('input', f1); i2.addEventListener('change', f1); });
			g.appendChild(field(T.socialTitle, st));
			g.appendChild(field(T.socialDesc, sd));
			p.appendChild(g);

			var img = el('div', { class: 'hs-mb-img' });
			var imgState = { id: value.social.image_id || 0, url: value.social.image_url || '' };
			function paintImg() {
				img.innerHTML = '';
				if (imgState.url) { img.appendChild(el('img', { src: imgState.url, alt: '' })); }
				img.appendChild(el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--sm', text: imgState.id ? 'تعویض تصویر' : (T.chooseImage || 'انتخاب تصویر'), onclick: pick }));
				if (imgState.id) { img.appendChild(el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--sm hs-mb-btn--ghost', text: T.removeImage || 'حذف', onclick: function () { imgState = { id: 0, url: '' }; emit({ social: { image_id: 0 } }); paintImg(); } })); }
				if (!imgState.url) { img.appendChild(el('span', { class: 'hs-mb-f-h', text: 'از تصویر شاخص استفاده می‌شود.' })); }
			}
			function pick() {
				if (!window.wp || !window.wp.media) {
					img.appendChild(el('span', { class: 'hs-mb-f-h', text: 'کتابخانه رسانه در دسترس نیست.' }));
					return;
				}
				var frame = window.wp.media({ multiple: false, library: { type: 'image' } });
				frame.on('select', function () {
					var att = frame.state().get('selection').first().toJSON();
					imgState = { id: att.id, url: att.url };
					emit({ social: { image_id: att.id } });
					paintImg();
				});
				frame.open();
			}
			p.appendChild(field(T.socialImage, img));
			paintImg();
			var skip = el('input', { type: 'checkbox', name: 'hoosh_seo[social][skip]', checked: !!value.social.skip });
			skip.addEventListener('change', function () { emit({ social: { skip: skip.checked } }); });
			p.appendChild(el('label', { class: 'hs-mb-check' }, [skip, document.createTextNode('بدون کارت اجتماعی برای این صفحه')]));
		})();

		/* ---------- schema pane ---------- */
		(function () {
			var p = panes.schema;
			var typeList = ['Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'Product', 'FAQPage', 'HowTo', 'Event', 'Recipe', 'Service', 'Course', 'VideoObject'];
			var cur = (value.schema && value.schema.length) ? (value.schema[0].type || value.schema[0].name || '') : '';
			var sel = el('select', { class: 'hs-mb-se', name: 'hoosh_seo[schema][type]' });
			sel.appendChild(el('option', { value: '', text: 'خودکار (بر پایه نوع نوشته)' }));
			typeList.forEach(function (t2) { var o = el('option', { value: t2, text: t2 }); if (cur === t2) { o.selected = true; } sel.appendChild(o); });
			sel.addEventListener('change', function () {
				emit({ schema: sel.value ? [{ type: sel.value }] : [] });
			});
			p.appendChild(field(T.schemaType, sel));

			/* FAQ repeater */
			var faqWrap = el('div', { class: 'hs-mb-rows' });
			var faq = (value.faq || []).map(function (x) { return Object.assign({ question: '', answer: '' }, x); });
			function paintFaq() {
				faqWrap.innerHTML = '';
				faq.forEach(function (item, i) {
					var qi = el('input', { class: 'hs-mb-in', type: 'text', value: item.question, placeholder: 'پرسش ' + digits(i + 1) });
					var ai = el('textarea', { class: 'hs-mb-ta', rows: 2, placeholder: 'پاسخ کوتاه و کامل' });
					ai.value = item.answer;
					var sync = function () { faq[i].question = qi.value; faq[i].answer = ai.value; emit({ faq: faq }); };
					qi.addEventListener('change', sync); ai.addEventListener('change', sync);
					faqWrap.appendChild(el('div', { class: 'hs-mb-r' }, [qi, ai, el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--sm hs-mb-btn--ghost', text: '×', onclick: function () { faq.splice(i, 1); emit({ faq: faq }); paintFaq(); } })]));
				});
				faqWrap.appendChild(el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--sm', text: T.addFaq || 'افزودن پرسش', onclick: function () { faq.push({ question: '', answer: '' }); emit({ faq: faq }); paintFaq(); } }));
			}
			p.appendChild(el('div', { class: 'hs-mb-group', text: T.faq || 'پرسش‌های متداول' }));
			p.appendChild(faqWrap);
			paintFaq();

			/* HowTo */
			var howWrap = el('div', { class: 'hs-mb-rows' });
			var how = (value.howto || []).map(function (x) { return Object.assign({ name: '', text: '' }, x); });
			function paintHow() {
				howWrap.innerHTML = '';
				how.forEach(function (item, i) {
					var ni = el('input', { class: 'hs-mb-in', type: 'text', value: item.name, placeholder: 'عنوان مرحله' });
					var ti = el('textarea', { class: 'hs-mb-ta', rows: 2 });
					ti.value = item.text;
					var sync = function () { how[i].name = ni.value; how[i].text = ti.value; emit({ howto: how }); };
					ni.addEventListener('change', sync); ti.addEventListener('change', sync);
					howWrap.appendChild(el('div', { class: 'hs-mb-r hs-mb-r--step' }, [el('div', { class: 'hs-mb-r__n', text: digits(i + 1) }), el('div', { class: 'hs-mb-col' }, [ni, ti]), el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--sm hs-mb-btn--ghost', text: '×', onclick: function () { how.splice(i, 1); emit({ howto: how }); paintHow(); } })]));
				});
				howWrap.appendChild(el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--sm', text: T.addStep || 'افزودن مرحله', onclick: function () { how.push({ name: '', text: '' }); emit({ howto: how }); paintHow(); } }));
			}
			p.appendChild(el('div', { class: 'hs-mb-group', text: T.steps || 'مراحل' }));
			p.appendChild(howWrap);
			paintHow();
			p.appendChild(el('div', { class: 'hs-mb-note', text: 'پاسخ‌ها و مراحل هم روی صفحه نمایش داده می‌شوند و هم در اسکیما JSON-LD.' }));
		})();

		/* ---------- AI pane ---------- */
		(function () {
			var p = panes.ai;
			if (!E.aiReady) {
				p.appendChild(el('div', { class: 'hs-mb-note hs-mb-note--warn', text: (T.self_host_hint || 'یک سرویس هوش مصنوعی وصل کنید') + ' — ' + (T.ai || 'هوش مصنوعی') }));
			}
			var btns = el('div', { class: 'hs-mb-row' });
			[['titles', 'عنوان‌های پیشنهادی'], ['description', 'توضیحات متا'], ['keywords', 'کلمه کلیدی مناسب'], ['summary', 'خلاصه صفحه']].forEach(function (t2) {
				btns.appendChild(el('button', { type: 'button', class: 'hs-mb-btn', text: t2[1], onclick: function () { run(t2[0], this); } }));
			});
			p.appendChild(btns);
			var out = el('div', { class: 'hs-mb-rows', style: { marginTop: '10px' } });
			p.appendChild(out);
			function run(task, b) {
				out.innerHTML = '';
				out.appendChild(el('div', { class: 'hs-mb-note' }, [el('span', { class: 'hs-mb-spin' }), document.createTextNode(' ' + (T.progress || 'در حال پردازش…'))]));
				b.classList.add('is-busy');
				api('ai/run', { task: task, post_id: opts.postId || E.postId || 0, dry_run: true }).then(function (r) {
					b.classList.remove('is-busy');
					out.innerHTML = '';
					if (!r || r.ok === false) { out.appendChild(el('div', { class: 'hs-mb-note hs-mb-note--warn', text: (r && (r.error || r.message)) || T.failed || 'ناموفق' })); return; }
					var d = r.data || {};
					var list = d.titles || d.descriptions || (d.primary ? [d.primary].concat(d.secondary || []) : null);
					if (list) {
						list.forEach(function (line, i) {
							out.appendChild(el('div', { class: 'hs-mb-item' }, [
								el('div', { class: 'hs-mb-item__m' }, [el('b', { text: typeof line === 'string' ? line : JSON.stringify(line) })]),
								el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--sm', text: T.apply || 'اعمال', onclick: function () { apply(task, typeof line === 'string' ? line : line, i); } })
							]));
						});
					}
					if (d.why) { out.appendChild(el('div', { class: 'hs-mb-note', text: d.why })); }
					if (!list && r.text) { out.appendChild(el('div', { class: 'hs-mb-note', text: r.text })); }
					if (d.headings) {
						(d.headings || []).forEach(function (h2) {
							out.appendChild(el('div', { class: 'hs-mb-item' }, [el('div', { class: 'hs-mb-item__m' }, [el('b', { text: h2.h2 || '' }), el('span', { text: (h2.h3 || []).join(' · ') })])]));
						});
					}
				}, function (e) { b.classList.remove('is-busy'); out.innerHTML = ''; out.appendChild(el('div', { class: 'hs-mb-note hs-mb-note--warn', text: e.message })); });
			}
			function apply(task, line, idx) {
				if (task === 'titles') { var t3 = q('[name="hoosh_seo[title]"]', root); if (t3) { t3.value = line; emit({ title: line }); } }
				else if (task === 'description') { var d3 = q('[name="hoosh_seo[description]"]', root); if (d3) { d3.value = line; emit({ description: line }); } }
				else if (task === 'keywords') { var a3 = (value.keywords || []).slice(); if (a3.indexOf(line) === -1) { a3.unshift(line); emit({ keywords: a3.slice(0, S.maxFocus || 5) }); } }
				api.analyze(true);
			}
			var notes = el('textarea', { class: 'hs-mb-ta', rows: 3, name: 'hoosh_seo[ai_notes]', placeholder: 'یادداشت برای هوش مصنوعی (لحن، مخاطب، نکات اجباری)' });
			notes.value = value.ai_notes || '';
			notes.addEventListener('change', function () { emit({ ai_notes: notes.value }); });
			p.appendChild(el('div', { class: 'hs-mb-group', text: 'راهنمای این نوشته' }));
			p.appendChild(notes);
		})();

		/* ---------- research popover ---------- */
		function openResearch() {
			var inp = el('input', { class: 'hs-mb-in', type: 'text', placeholder: 'مثلاً «کفش مردانه»', value: (value.keywords && value.keywords[0]) || postTitle() });
			var list = el('div', { class: 'hs-mb-rows', style: { marginTop: '8px' } });
			var go = el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--pri', text: 'پیدا کن' });
			go.addEventListener('click', function () {
				list.innerHTML = '';
				list.appendChild(el('div', { class: 'hs-mb-note' }, [el('span', { class: 'hs-mb-spin' }), document.createTextNode(' ' + (T.progress || '…'))]));
				api('research/start', { seed: inp.value, lang: 'fa', depth: 1, max: 30, delay: 1 }).then(function (res) {
					var session = res && (res.session || res.id);
					var tries = 0;
					var tick = function () {
						tries++;
						api('research/status' + (session ? '?session=' + session : '')).then(function (st) {
							var rows = (st && (st.rows || st.items)) || [];
							if (rows.length || !(st && st.running) || tries > 12) {
								list.innerHTML = '';
								if (!rows.length) { list.appendChild(el('div', { class: 'hs-mb-note', text: 'نتیجه‌ای نبود' })); return; }
								rows.slice(0, 20).forEach(function (r) {
									var phrase = r.phrase || r.keyword || String(r);
									list.appendChild(el('div', { class: 'hs-mb-item' }, [
										el('div', { class: 'hs-mb-item__m' }, [el('b', { text: phrase }), el('span', { text: (r.volume ? 'حجم ' + digits(r.volume) : '') })]),
										el('button', { type: 'button', class: 'hs-mb-btn hs-mb-btn--sm', text: '+', onclick: function () { var a = (value.keywords || []).slice(); if (a.indexOf(phrase) === -1) { a.push(phrase); emit({ keywords: a }); } } })
									]));
								});
								return;
							}
							setTimeout(tick, 1400);
						}, function () { list.innerHTML = ''; list.appendChild(el('div', { class: 'hs-mb-note hs-mb-note--warn', text: 'تحقیق در دسترس نیست' })); });
					};
					tick();
				}, function () { list.innerHTML = ''; list.appendChild(el('div', { class: 'hs-mb-note hs-mb-note--warn', text: 'شروع نشد' })); });
			});
			var box = el('div', { class: 'hs-mb-note', style: { marginTop: '10px' } }, [el('div', { class: 'hs-mb-row' }, [inp, go]), list]);
			var host = q('.hs-mb-research', root) || el('div', { class: 'hs-mb-research' });
			if (!host.parentNode) { panes.seo.appendChild(host); }
			host.innerHTML = '';
			host.appendChild(box);
			host.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		}

		/* ---------- analysis ---------- */
		var anBusy = false;
		api.lastAnalysis = function () { return lastAnalysis; };
		api.analyze = function (force) {
			if (anBusy) { return Promise.resolve(); }
			if (!opts.postId && !E.postId) { return Promise.resolve(); }
			anBusy = true;
			var src = opts.source ? opts.source() : {};
			return api('analyze', {
				post_id: opts.postId || E.postId || 0,
				title: src.title || postTitle(),
				content: src.content || content(),
				excerpt: src.excerpt || excerpt(),
				keywords: value.keywords,
				slug: value.slug
			}).then(function (a) {
				anBusy = false;
				lastAnalysis = a;
				api.paint(a);
			}, function () { anBusy = false; });
		};
		api.paint = function (a) {
			if (!a) { return; }
			var score = parseInt(a.score || a.seo_score || 0, 10);
			var ring = q('.hs-mb-donut', root);
			if (ring) {
				ring.classList.remove('is-ok', 'is-warn', 'is-bad');
				ring.classList.add(score >= 80 ? 'is-ok' : score >= 55 ? 'is-warn' : 'is-bad');
				var fg = q('.fg', ring);
				if (fg) { fg.setAttribute('stroke-dashoffset', String(100 - Math.max(0, Math.min(100, score)))); }
				var b = q('b', ring);
				if (b) { b.textContent = digits(score); }
			}
			var badge = opts.scoreHost;
			if (badge) {
				badge.setAttribute('data-score', String(score));
				badge.innerHTML = '';
				badge.appendChild(el('i'));
				badge.appendChild(el('b', { text: digits(score) }));
				badge.style.setProperty('--pct', score + '%');
				badge.querySelector('i').style.background = 'conic-gradient(var(--hs-a) ' + score + '%, var(--hs-line) ' + score + '%)';
			}
			if (api.paintChecks) { api.paintChecks(a); }
		};
		api.refresh = function (v) {
			value = Object.assign(value, v || {});
		};
		api.value = function () { return value; };
		api.destroy = function () {
			listeners.forEach(function (l) { l[0].removeEventListener(l[1], l[2]); });
			clearTimeout(saveTimer);
			clearTimeout(anTimer);
		};
		window.addEventListener('beforeunload', function () {
			if (!dirty || opts.live === false || !window.fetch) { return; }
			try {
				fetch((E.rest || '') + '/post/' + (opts.postId || E.postId || 0), {
					method: 'POST', credentials: 'same-origin', keepalive: true,
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': E.nonce || '' },
					body: JSON.stringify(value)
				});
			} catch (e) { /* nothing sensible to do here */ }
		});

		if (value.analysis && (value.analysis.issues || value.analysis.score)) { api.paint(value.analysis); }
		setTimeout(function () { api.analyze(); }, 500);
		return api;
	}

	/* ---------- classic editor bootstrap ---------- */
	function postTitle() {
		var t = q('#title');
		return t ? t.value : '';
	}
	function content() {
		if (window.tinymce && window.tinymce.activeEditor && !window.tinymce.activeEditor.isHidden()) {
			return window.tinymce.activeEditor.getContent();
		}
		var c = q('#content');
		return c ? c.value : '';
	}
	function excerpt() {
		var e2 = q('#excerpt');
		return e2 ? e2.value : '';
	}
	function hostOf() {
		var u = (q('#sample-permalink a') || {}).textContent || window.location.host;
		return String(u).replace(/^https?:\/\//, '').replace(/\/$/, '');
	}

	function mount() {
		var box = q('#hoosh-seo-classic');
		if (!box || box.getAttribute('data-ready')) { return; }
		box.setAttribute('data-ready', '1');
		var initial = {};
		try { initial = JSON.parse(box.getAttribute('data-initial') || '{}'); } catch (e) { initial = {}; }
		box.classList.add('is-ready');
		var inst = create({
			root: q('.hs-mb-panes', box) || box,
			tabHost: q('.hs-mb-tabs', box),
			scoreHost: q('.hs-mb-score', box),
			postId: parseInt(box.getAttribute('data-post'), 10) || E.postId,
			value: initial,
			source: function () { return { title: postTitle(), content: content(), excerpt: excerpt() }; }
		});
		window.HooshMB = inst;
		['input', 'change'].forEach(function (ev) {
			document.addEventListener(ev, function (e) {
				if (e.target && (e.target.id === 'title' || e.target.id === 'content' || e.target.id === 'excerpt')) {
					inst.analyze();
				}
			}, true);
		});
		if (window.tinymce) {
			var bind = function () {
				if (window.tinymce.activeEditor) {
					window.tinymce.activeEditor.on('change keyup undo redo', function () { inst.analyze(); });
				}
			};
			document.addEventListener('tinymce-editor-init', bind);
			setTimeout(bind, 1500);
		}
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', mount); } else { mount(); }

	window.HooshEditor = { create: create, api: api, el: el, postTitle: postTitle, content: content, excerpt: excerpt, hostOf: hostOf, digits: digits, len: len, settings: S, strings: T, cfg: E };
})(window, document);
