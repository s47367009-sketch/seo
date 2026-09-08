/**
 * HooshSEO Studio — single page application.
 * Vanilla JS, RTL-first, no build step, no dependencies.
 */
var HS = (function () {
	'use strict';

	var C = window.HOOSH || {};
	var NS = 'hoosh';
	var I18N = {
		save: 'ذخیره', saving: 'در حال ذخیره…', saved: 'ذخیره شد', cancel: 'انصراف', close: 'بستن', apply: 'اعمال',
		run: 'اجرا', refresh: 'نوسازی', search: 'جست‌وجو', all: 'همه', none: 'هیچ‌کدام', add: 'افزودن', edit: 'ویرایش',
		del: 'حذف', undo: 'بازگردانی', more: 'بیشتر', loading: 'در حال بارگذاری…', empty: 'چیزی برای نمایش نیست',
		error: 'خطا رخ داد', retry: 'تلاش دوباره', yes: 'بله', no: 'خیر', on: 'روشن', off: 'خاموش',
		export: 'خروجی CSV', import: 'درون‌ریزی', copy: 'کپی', copied: 'کپی شد', open: 'باز کردن',
		noData: 'داده‌ای ثبت نشده است.', notFound: 'چیزی پیدا نشد.', confirm: 'مطمئنید؟',
		offline: 'اتصال برقرار نیست — تغییرات محلی ذخیره می‌شود.', perm: 'اجازه دسترسی', unknown: 'ناشناخته',
		applyAll: 'اعمال همه', dismiss: 'رد کردن', viewAll: 'همه', perPage: 'ردیف در هر صفحه',
		page: 'صفحه', of: 'از', results: 'نتیجه', seconds: 'ثانیه', minutes: 'دقیقه'
	};

	/* ---------------- tiny dom helpers ---------------- */

	function esc(s) {
		if (s === null || s === undefined) { return ''; }
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function Raw(s) { return { __raw: String(s === undefined || s === null ? '' : s) }; }
	function html(strings) {
		var out = strings[0], i = 1;
		for (; i < arguments.length; i++) {
			var v = arguments[i];
			out += (v && v.__raw !== undefined) ? v.__raw : esc(v);
			out += strings[i];
		}
		return out;
	}
	function h(tag, props, kids) {
		var el = document.createElement(tag);
		if (props) {
			Object.keys(props).forEach(function (k) {
				var v = props[k];
				if (v === null || v === undefined || v === false) { return; }
				if (k === 'class') { el.className = v; }
				else if (k === 'html') { el.innerHTML = v; }
				else if (k === 'text') { el.textContent = v; }
				else if (k === 'dataset') { Object.keys(v).forEach(function (d) { el.dataset[d] = v[d]; }); }
				else if (k.slice(0, 2) === 'on') { el.addEventListener(k.slice(2).toLowerCase(), v); }
				else if (k === 'style' && typeof v === 'object') { Object.keys(v).forEach(function (s) { el.style[s] = v[s]; }); }
				else { el.setAttribute(k, v === true ? '' : v); }
			});
		}
		mount(el, kids);
		return el;
	}

	/**
	 * Append a node, string, Raw() blob, or any (nested) array of them.
	 * Every builder routes children through this, so `cond ? el : null` and
	 * arrays of nodes are always safe to hand to a component.
	 */
	function mount(parent, list) {
		if (list === null || list === undefined || list === false || list === '') { return parent; }
		if (Array.isArray(list)) {
			for (var i = 0; i < list.length; i++) { mount(parent, list[i]); }
			return parent;
		}
		if (typeof list === 'object' && list.__raw !== undefined) {
			var tp = document.createElement('template');
			tp.innerHTML = list.__raw;
			while (tp.content.firstChild) { parent.appendChild(tp.content.firstChild); }
			return parent;
		}
		if (typeof list === 'object' && list.nodeType) { parent.appendChild(list); return parent; }
		if (typeof list === 'object') { return parent; }
		parent.appendChild(document.createTextNode(String(list)));
		return parent;
	}

	var WIDGET_ORDER = ['score', 'issues', 'traffic', 'keywords', 'ai', 'actions'];
	/* The widget list is a setting, so accept an array, a {id: bool} map, or nothing. */
	function widgetIds(v) {
		if (v && !Array.isArray(v) && typeof v === 'object') {
			return WIDGET_ORDER.concat(Object.keys(v)).filter(function (id, i, all) {
				return v[id] !== false && all.indexOf(id) === i;
			});
		}
		if (Array.isArray(v) && v.length) { return v.slice(); }
		return WIDGET_ORDER.slice();
	}

	function qs(sel, root) { return (root || document).querySelector(sel); }
	function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
	function on(root, ev, sel, fn) {
		root.addEventListener(ev, function (e) {
			var t = e.target.closest ? e.target.closest(sel) : null;
			if (t && root.contains(t)) { fn(e, t); }
		});
	}
	function frag(htmlStr) {
		var t = document.createElement('template');
		t.innerHTML = htmlStr;
		return t.content.cloneNode(true);
	}

	/* ---------------- formatters ---------------- */

	var FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
	function useDigits() { return !!(C.appearance && C.appearance.digits); }
	function num(n, dec) {
		if (n === null || n === undefined || n === '' || isNaN(parseFloat(n))) { return useDigits() ? FA_DIGITS[0] : '0'; }
		var v = parseFloat(n);
		var s = (dec === undefined) ? Math.round(v).toString() : v.toFixed(dec);
		var parts = s.split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '٬');
		var out = parts.join('.');
		return useDigits() ? out.replace(/[0-9]/g, function (d) { return FA_DIGITS[+d]; }) : out;
	}
	function pct(n) { return num(Math.round(parseFloat(n) || 0)) + '٪'; }
	function bytes(b) {
		b = parseFloat(b) || 0;
		if (b < 1024) { return num(b) + ' B'; }
		if (b < 1048576) { return num(b / 1024, 1) + ' KB'; }
		return num(b / 1048576, 2) + ' MB';
	}
	function faDate(d, time) {
		if (!d) { return '—'; }
		var dt = (d instanceof Date) ? d : new Date(String(d).replace(' ', 'T'));
		if (isNaN(dt.getTime())) { return String(d); }
		try {
			var o = { year: 'numeric', month: 'long', day: 'numeric' };
			if (C.appearance && C.appearance.shamsi) { o.calendar = 'persian'; }
			if (time) { o.hour = '2-digit'; o.minute = '2-digit'; }
			o.numberingSystem = useDigits() ? 'arabext' : 'latn';
			return new Intl.DateTimeFormat('fa-IR', o).format(dt);
		} catch (e) {
			return dt.toLocaleDateString(useDigits() ? 'fa-IR' : 'en-US', time ? { dateStyle: 'medium', timeStyle: 'short' } : { dateStyle: 'medium' });
		}
	}
	function ago(d) {
		if (!d) { return '—'; }
		var dt = (d instanceof Date) ? d : new Date(String(d).replace(' ', 'T').replace(/(\+00:00)$/, 'Z'));
		if (isNaN(dt.getTime())) { return String(d); }
		var s = Math.max(1, Math.floor((Date.now() - dt.getTime()) / 1000));
		if (s < 60) { return num(s) + ' ثانیه پیش'; }
		if (s < 3600) { return num(Math.floor(s / 60)) + ' دقیقه پیش'; }
		if (s < 86400) { return num(Math.floor(s / 3600)) + ' ساعت پیش'; }
		if (s < 2592000) { return num(Math.floor(s / 86400)) + ' روز پیش'; }
		return faDate(dt);
	}
	function trunc(s, n) { s = String(s === undefined || s === null ? '' : s); return s.length > n ? s.slice(0, n - 1) + '…' : s; }
	function entries(x) {
		if (!x) { return []; }
		if (Array.isArray(x)) { return x.map(function (v, i) { return [String(i), v]; }); }
		return Object.keys(x).map(function (k) { return [k, x[k]]; });
	}
	function labelOf(x, key, fallback) {
		if (!x || typeof x !== 'object') { return fallback || key || ''; }
		return x.label || x.title || x.name || x.caption || fallback || key || '';
	}
	function isObj(v) { return v && typeof v === 'object' && !Array.isArray(v); }
	function arr(v) { return v === undefined || v === null || v === '' ? [] : (Array.isArray(v) ? v : [v]); }

	/* ---------------- state ---------------- */

	var state = {
		view: '',
		params: {},
		settings: JSON.parse(JSON.stringify(C.settings || {})),
		dirty: {},
		busy: false,
		mode: window.localStorage.getItem(NS + ':mode') || 'easy',
		collapsed: window.localStorage.getItem(NS + ':collapsed') === '1',
		theme: (C.appearance && C.appearance.theme) || 'auto',
		accent: (C.appearance && C.appearance.accent) || 'firouzeh',
		density: (C.appearance && C.appearance.density) || 'comfortable',
		cache: {},
		badges: {},
		poll: null
	};

	var cacheTtl = 45000;
	function cacheGet(key) {
		var e = state.cache[key];
		if (!e) { return null; }
		if (Date.now() - e.at > cacheTtl) { return null; }
		return e.value;
	}
	function cacheSet(key, value) { state.cache[key] = { at: Date.now(), value: value }; return value; }
	function cacheClear(prefix) {
		Object.keys(state.cache).forEach(function (k) { if (!prefix || k.indexOf(prefix) === 0) { delete state.cache[k]; } });
	}

	/* ---------------- api ---------------- */

	var BASE = (C.rest || (location.origin + '/wp-json/' + 'hoosh/v1')).replace(/\/$/, '') + '/';

	function apiUrl(path, query) {
		var url = BASE + String(path || '').replace(/^\//, '');
		var q = query || {};
		var parts = [];
		Object.keys(q).forEach(function (k) {
			var v = q[k];
			if (v === undefined || v === null || v === '') { return; }
			if (Array.isArray(v)) { v.forEach(function (one) { parts.push(encodeURIComponent(k) + '[]=' + encodeURIComponent(one)); }); return; }
			parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
		});
		if (parts.length) { url += (url.indexOf('?') > -1 ? '&' : '?') + parts.join('&'); }
		return url;
	}

	function ApiError(message, data, status) {
		var e = new Error(message || I18N.error);
		e.data = data || {};
		e.status = status || 0;
		return e;
	}

	function api(path, opts) {
		opts = opts || {};
		var method = (opts.method || 'GET').toUpperCase();
		var key = method === 'GET' ? path + '?' + JSON.stringify(opts.query || {}) : null;
		if (key && !opts.force) {
			var hit = cacheGet(key);
			if (hit) { return Promise.resolve(hit); }
		}
		var ctl = ('AbortController' in window) ? new AbortController() : null;
		var init = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.nonce || '', 'Accept': 'application/json' },
			signal: ctl ? ctl.signal : undefined
		};
		if (opts.body !== undefined) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(opts.body);
		}
		var timer = opts.timeout ? setTimeout(function () { if (ctl) { ctl.abort(); } }, opts.timeout) : null;
		return fetch(apiUrl(path, opts.query), init).then(function (res) {
			if (timer) { clearTimeout(timer); }
			var ct = res.headers.get('content-type') || '';
			if (res.status === 401 || res.status === 403) {
				return res.text().then(function () { throw ApiError('دسترسی شما منقضی شده است. از پیشخوان وردپرس دوباره وارد شوید.', {}, res.status); });
			}
			if (ct.indexOf('json') === -1) {
				return res.text().then(function (t) { throw ApiError(t ? trunc(t, 160) : ('پاسخ نامعتبر (' + res.status + ')'), {}, res.status); });
			}
			return res.json().then(function (data) {
				if (!res.ok) {
					throw ApiError(data && (data.message || data.error) ? String(data.message || data.error) : ('خطا (' + res.status + ')'), data, res.status);
				}
				if (key) { cacheSet(key, data); }
				return data;
			});
		}, function (err) {
			if (timer) { clearTimeout(timer); }
			if (err && err.name === 'AbortError') { throw ApiError('زمان درخواست تمام شد.', {}, 0); }
			throw ApiError('اتصال به سایت برقرار نشد. (' + (err && err.message ? err.message : 'network') + ')');
		});
	}
	function get(path, query, opts) { return api(path, Object.assign({ method: 'GET', query: query || {} }, opts || {})); }
	function post(path, body, query) { return api(path, { method: 'POST', body: body || {}, query: query || {} }); }
	function postQ(path, query) { return api(path, { method: 'POST', query: query || {} }); }

	/* ---------------- toasts / modals / drawer ---------------- */

	var layers = { toasts: null };
	function toastLayer() {
		if (!layers.toasts) {
			layers.toasts = h('div', { class: 'hs-toasts', role: 'status', 'aria-live': 'polite' });
			document.body.appendChild(layers.toasts);
		}
		return layers.toasts;
	}
	function toast(message, kind, opts) {
		opts = opts || {};
		kind = kind || 'info';
		var t = h('div', { class: 'hs-toast is-' + kind });
		var body = h('div', { class: 'hs-toast__m' });
		if (opts.title) { body.appendChild(h('b', { text: opts.title })); }
		body.appendChild(h('span', { text: String(message || '') }));
		t.appendChild(body);
		if (opts.action) {
			t.appendChild(h('button', {
				class: 'hs-toast__act', text: opts.action.label || I18N.apply,
				onclick: function () { close(); opts.action.run && opts.action.run(); }
			}));
		}
		var close = function () { if (t.parentNode) { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 160); } };
		t.appendChild(h('button', { class: 'hs-toast__x', 'aria-label': I18N.close, html: '&times;', onclick: close }));
		toastLayer().appendChild(t);
		if (opts.sticky !== true) { setTimeout(close, opts.ms || (kind === 'error' ? 8200 : 4200)); }
		return { close: close, el: t };
	}
	var lastErr = 0;
	function fail(err) {
		var now = Date.now();
		if (now - lastErr < 700) { return; }
		lastErr = now;
		toast(err && err.message ? err.message : I18N.error, 'error', { title: err && err.status ? ('کد ' + err.status) : undefined });
	}

	function overlay(inner, onClose) {
		var back = h('div', { class: 'hs-backdrop' });
		back.addEventListener('click', function () { done(); });
		var done = function () {
			document.removeEventListener('keydown', key);
			back.remove();
			inner.remove();
			onClose && onClose();
		};
		var key = function (e) { if (e.key === 'Escape') { done(); } };
		document.addEventListener('keydown', key);
		document.body.appendChild(back);
		document.body.appendChild(inner);
		return { close: done, el: inner };
	}

	function modal(cfg) {
		cfg = cfg || {};
		var box = h('div', { class: 'hs-modal__box' + (cfg.size === 'sm' ? ' hs-modal__box--sm' : '') });
		var head = h('div', { class: 'hs-modal__head' }, [
			h('div', { class: 'hs-col' }, [h('h3', { text: cfg.title || '' }), cfg.sub ? h('p', { text: cfg.sub }) : null])
		]);
		var x = h('button', { class: 'hs-btn hs-btn--ghost hs-btn--icon', 'aria-label': I18N.close, html: '&times;' });
		head.appendChild(x);
		var body = h('div', { class: 'hs-modal__body' });
		if (typeof cfg.body === 'string') { body.innerHTML = cfg.body; } else { mount(body, cfg.body); }
		box.appendChild(head);
		box.appendChild(body);
		var foot = h('div', { class: 'hs-modal__foot' });
		(cfg.actions || []).forEach(function (a) {
			var b = h('button', {
				class: 'hs-btn ' + (a.kind === 'primary' ? 'hs-btn--primary' : (a.kind === 'danger' ? 'hs-btn--danger' : '')),
				text: a.label
			});
			b.addEventListener('click', function () {
				var r = a.run ? a.run(body, o) : undefined;
				if (a.keepOpen) { return; }
				if (r && r.then) {
					b.classList.add('is-busy');
					r.then(function () { o.close(); }, function (e) { b.classList.remove('is-busy'); fail(e); });
					return;
				}
				o.close();
			});
			foot.appendChild(b);
		});
		if (cfg.actions && cfg.actions.length) { box.appendChild(foot); }
		var wrap = h('div', { class: 'hs-modal', role: 'dialog', 'aria-modal': 'true' }, [box]);
		var o = overlay(wrap, cfg.onClose);
		x.addEventListener('click', o.close);
		var focusable = qs('input,select,textarea,button', body) || qs('button', box);
		if (focusable) { setTimeout(function () { focusable.focus(); }, 40); }
		return Object.assign(o, { body: body, box: box });
	}

	function confirmBox(title, message, onYes, kind) {
		return modal({
			title: title, size: 'sm',
			body: h('p', { class: 'hs-muted', text: message || I18N.confirm }),
			actions: [
				{ label: I18N.cancel },
				{ label: I18N.yes, kind: kind || 'primary', run: function () { return onYes ? onYes() : undefined; } }
			]
		});
	}

	function drawer(cfg) {
		cfg = cfg || {};
		var d = h('div', { class: 'hs-drawer', role: 'dialog', 'aria-modal': 'true' });
		var head = h('div', { class: 'hs-drawer__head' }, [
			h('h3', { text: cfg.title || '' }),
			h('button', { class: 'hs-btn hs-btn--ghost hs-btn--icon', html: '&times;', 'aria-label': I18N.close })
		]);
		var body = h('div', { class: 'hs-drawer__body' });
		if (typeof cfg.body === 'string') { body.innerHTML = cfg.body; } else { mount(body, cfg.body); }
		d.appendChild(head);
		d.appendChild(body);
		var foot = h('div', { class: 'hs-drawer__foot' });
		if (cfg.actions) {
			cfg.actions.forEach(function (a) {
				var b = h('button', { class: 'hs-btn ' + (a.kind === 'primary' ? 'hs-btn--primary' : ''), text: a.label });
				b.addEventListener('click', function () {
					var r = a.run ? a.run(body, o) : undefined;
					if (r && r.then) { b.classList.add('is-busy'); r.then(function () { b.classList.remove('is-busy'); if (a.close !== false) { o.close(); } }, function (e) { b.classList.remove('is-busy'); fail(e); }); return; }
					if (a.close !== false) { o.close(); }
				});
				foot.appendChild(b);
			});
			d.appendChild(foot);
		}
		var back = h('div', { class: 'hs-backdrop' });
		var o = {};
		var close = function () {
			back.remove();
			d.remove();
			document.removeEventListener('keydown', key);
			if (cfg.onClose) { cfg.onClose(); }
		};
		var key = function (e) { if (e.key === 'Escape') { close(); } };
		o.el = d;
		o.body = body;
		o.close = close;
		document.body.appendChild(back);
		document.body.appendChild(d);
		document.addEventListener('keydown', key);
		back.addEventListener('click', close);
		qs('button', head).addEventListener('click', close);
		setTimeout(function () { d.classList.add('is-open'); }, 10);
		var focusable = qs('input,select,textarea,[contenteditable],button', body) || qs('button', head);
		if (focusable) { setTimeout(function () { focusable.focus(); }, 60); }
		return o;
	}

	/* ---------------- shared components ---------------- */

	function card(cfg, body) {
		var c = h('section', { class: 'hs-card' + (cfg.flush ? ' hs-card--flush' : '') + (cfg.tint ? ' hs-card--tint' : '') });
		if (cfg.title || cfg.actions) {
			var head = h('div', { class: 'hs-card__head' });
			var tt = h('h3', { class: 'hs-card__title' });
			if (cfg.icon) { tt.appendChild(icon(cfg.icon)); }
			tt.appendChild(h('span', { text: cfg.title || '' }));
			if (cfg.sub) { tt.appendChild(h('span', { class: 'hs-card__sub', text: cfg.sub })); }
			head.appendChild(tt);
			if (cfg.actions) {
				var a = h('div', { class: 'hs-card__actions' });
				arr(cfg.actions).forEach(function (x) { if (x) { a.appendChild(typeof x === 'string' ? h('span', { class: 'hs-chip', text: x }) : x); } });
				head.appendChild(a);
			}
			c.appendChild(head);
		}
		var b = h('div', { class: 'hs-card__body' });
		if (typeof body === 'string') { b.innerHTML = body; } else { mount(b, body); }
		c.appendChild(b);
		if (cfg.id) { c.id = cfg.id; }
		return c;
	}

	function btn(label, opts) {
		opts = opts || {};
		var b = h('button', {
			type: opts.type || 'button',
			class: 'hs-btn' + (opts.kind === 'primary' ? ' hs-btn--primary' : opts.kind === 'ghost' ? ' hs-btn--ghost' : opts.kind === 'danger' ? ' hs-btn--danger' : '') + (opts.sm ? ' hs-btn--sm' : '') + (opts.icon && !label ? ' hs-btn--icon' : ''),
			title: opts.title || '',
			'aria-label': opts.title || ''
		});
		if (opts.icon) { b.appendChild(icon(opts.icon)); }
		if (label) { b.appendChild(h('span', { text: label })); }
		if (opts.run) {
			b.addEventListener('click', function () {
				b.classList.add('is-busy');
				var r;
				try { r = opts.run(b); } catch (e) { b.classList.remove('is-busy'); throw e; }
				if (r && r.then) { r.then(function () { b.classList.remove('is-busy'); }, function (e) { b.classList.remove('is-busy'); fail(e); }); }
				else { b.classList.remove('is-busy'); }
			});
		}
		if (opts.href) {
			var a = h('a', { class: b.className, href: opts.href, target: opts.target || '_self', rel: opts.rel || '', title: opts.title || '' });
			if (opts.icon) { a.appendChild(icon(opts.icon)); }
			if (label) { a.appendChild(h('span', { text: label })); }
			return a;
		}
		return b;
	}

	function switchEl(checked, onChange, opts) {
		opts = opts || {};
		var s = h('button', { type: 'button', class: 'hs-switch' + (checked ? ' is-on' : ''), role: 'switch', 'aria-checked': checked ? 'true' : 'false', title: opts.title || '' });
		s.appendChild(h('i'));
		if (onChange) {
			s.addEventListener('click', function () {
				var next = !s.classList.contains('is-on');
				s.classList.toggle('is-on', next);
				s.setAttribute('aria-checked', next ? 'true' : 'false');
				onChange(next, s);
			});
		}
		if (opts.disabled) { s.setAttribute('disabled', ''); }
		return s;
	}

	function kpis(list) {
		var w = h('div', { class: 'hs-kpis' });
		list.forEach(function (k) {
			var el = h('div', { class: 'hs-kpi' + (k.plain ? ' hs-kpi--plain' : '') });
			el.appendChild(h('div', { class: 'hs-kpi__v', text: k.value }));
			el.appendChild(h('div', { class: 'hs-kpi__l', text: k.label }));
			if (k.delta !== undefined && k.delta !== null && k.delta !== '') {
				var up = parseFloat(k.delta) >= 0;
				el.appendChild(h('div', { class: 'hs-kpi__d ' + (k.delta === 0 ? '' : (up ? 'is-up' : 'is-down')), text: (k.delta === 0 ? 'بدون تغییر' : (up ? '▲ ' : '▼ ') + String(Math.abs(parseFloat(k.delta))).replace('-')) }));
			}
			if (k.hint) { el.appendChild(h('div', { class: 'hs-kpi__d hs-muted', text: k.hint })); }
			if (k.href) {
				el.style.cursor = 'pointer';
				el.addEventListener('click', function () { go(k.href); });
			}
			w.appendChild(el);
		});
		return w;
	}

	function bars(rows, opts) {
		opts = opts || {};
		var w = h('div', { class: 'hs-bars' });
		var max = opts.max || Math.max(1, Math.max.apply(null, rows.map(function (r) { return parseFloat(r.value) || 0; })));
		rows.forEach(function (r) {
			var v = parseFloat(r.value) || 0;
			var pctv = Math.max(0, Math.min(100, (v / max) * 100));
			var row = h('div', { class: 'hs-bar' });
			var lbl = r.href ? h('a', { class: 'hs-bar__label', href: r.href, text: r.label, title: r.label }) : h('div', { class: 'hs-bar__label', text: r.label, title: r.label });
			row.appendChild(lbl);
			row.appendChild(h('div', { class: 'hs-bar__track' }, [h('i', { class: 'hs-bar__fill' + (r.kind ? ' is-' + r.kind : ''), style: { width: pctv + '%' } })]));
			row.appendChild(h('div', { class: 'hs-bar__val', text: r.display !== undefined ? r.display : num(v) }));
			w.appendChild(row);
		});
		if (!rows.length) { w.appendChild(empty(I18N.noData, null, true)); }
		return w;
	}

	function donut(score, label, size) {
		var s = Math.max(0, Math.min(100, Math.round(parseFloat(score) || 0)));
		var d = size === 'sm' ? 66 : 116;
		var r = (d / 2) - (size === 'sm' ? 8 : 10);
		var circ = 2 * Math.PI * r;
		var kind = s >= 80 ? 'ok' : (s >= 50 ? 'warn' : 'bad');
		var svgNS = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(svgNS, 'svg');
		svg.setAttribute('width', d); svg.setAttribute('height', d); svg.setAttribute('viewBox', '0 0 ' + d + ' ' + d);
		[['bg', circ], ['fg', circ * (s / 100)]].forEach(function (pair, i) {
			var c = document.createElementNS(svgNS, 'circle');
			c.setAttribute('cx', d / 2); c.setAttribute('cy', d / 2); c.setAttribute('r', r);
			c.setAttribute('class', 'hs-donut__' + pair[0]);
			if (i === 1) { c.setAttribute('stroke-dasharray', circ); c.setAttribute('stroke-dashoffset', circ - pair[1]); }
			svg.appendChild(c);
		});
		var wrap = h('div', { class: 'hs-donut' + (size === 'sm' ? ' hs-donut--sm' : '') + ' is-' + kind });
		wrap.appendChild(svg);
		wrap.appendChild(h('div', { class: 'hs-donut__t' }, [h('b', { text: num(s) }), h('span', { text: label || 'امتیاز' })]));
		return wrap;
	}

	function lineChart(series, opts) {
		opts = opts || {};
		var w = 700, hh = opts.height || 190, pad = 26;
		var sets = series.filter(function (s) { return s && s.values && s.values.length; });
		if (!sets.length) { return empty(I18N.noData, null, true); }
		var n = Math.max.apply(null, sets.map(function (s) { return s.values.length; }));
		var all = [].concat.apply([], sets.map(function (s) { return s.values.map(Number); }));
		var max = opts.max !== undefined ? opts.max : Math.max(1, Math.max.apply(null, all));
		var min = opts.min !== undefined ? opts.min : Math.min(0, Math.min.apply(null, all));
		var rev = opts.invert === true;
		var sx = function (i) { return pad + (i * (w - pad * 2)) / Math.max(1, n - 1); };
		var sy = function (v) { var t = (max === min) ? .5 : (v - min) / (max - min); return rev ? (hh - pad) - t * (hh - pad * 2) : pad + (1 - t) * (hh - pad * 2); };
		var svgNS = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(svgNS, 'svg');
		svg.setAttribute('viewBox', '0 0 ' + w + ' ' + hh);
		svg.setAttribute('class', 'hs-chart');
		svg.setAttribute('preserveAspectRatio', 'none');
		var add = function (tag, attrs) {
			var e = document.createElementNS(svgNS, tag);
			Object.keys(attrs).forEach(function (k) { e.setAttribute(k, attrs[k]); });
			svg.appendChild(e);
			return e;
		};
		for (var g = 0; g <= 4; g++) {
			var y = pad + g * (hh - pad * 2) / 4;
			add('line', { x1: pad, y1: y, x2: w - pad, y2: y, class: 'hs-chart__grid' });
		}
		var colors = ['var(--a)', 'var(--info)', 'var(--warn)', 'var(--ok)'];
		sets.forEach(function (s, si) {
			var pts = s.values.map(function (v, i) { return [sx(i), sy(Number(v))]; });
			var d = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' ');
			if (s.area !== false && si === 0) {
				var ap = d + ' L ' + sx(s.values.length - 1).toFixed(1) + ' ' + (hh - pad) + ' L ' + sx(0) + ' ' + (hh - pad) + ' Z';
				add('path', { d: ap, class: 'hs-chart__area' });
			}
			var line = add('path', { d: d, class: 'hs-chart__line' });
			line.style.stroke = s.color || colors[si % 4];
			if (s.area === false) { line.style.strokeDasharray = '4 3'; }
			if (pts.length <= 40) {
				pts.forEach(function (p, i) {
					var c = add('circle', { cx: p[0], cy: p[1], r: 2.6, class: 'hs-chart__pt' });
					c.style.stroke = s.color || colors[si % 4];
					if (s.labels && s.labels[i]) { c.appendChild(document.createElementNS(svgNS, 'title')).textContent = s.labels[i] + ': ' + s.values[i]; }
				});
			}
		});
		if (sets[0].labels) {
			var L = sets[0].labels;
			[0, Math.floor(L.length / 2), L.length - 1].forEach(function (i, k) {
				if (!L[i]) { return; }
				var t = add('text', { x: sx(i), y: hh - 6, class: 'hs-chart__lbl', 'text-anchor': ['start', 'middle', 'end'][k] });
				t.textContent = L[i];
			});
		}
		var wrap = h('div', { class: 'hs-col' });
		wrap.appendChild(svg);
		if (sets.length > 1) {
			var lg = h('div', { class: 'hs-legend' });
			sets.forEach(function (s, si) {
				var i2 = h('i');
				i2.style.background = s.color || colors[si % 4];
				lg.appendChild(h('span', {}, [i2, document.createTextNode(s.label || '')]));
			});
			wrap.appendChild(lg);
		}
		return wrap;
	}

	function table(cfg) {
		// cfg: {columns:[{key,label,align,width,render(row),sortable}], rows, empty:{}, onRow, footer, picky, rowKey}
		var wrap = h('div', { class: 'hs-table__wrap' });
		var t = h('table', { class: 'hs-table' });
		var thead = h('thead');
		var trh = h('tr');
		if (cfg.picky) { trh.appendChild(h('th', {}, [h('input', { type: 'checkbox', class: 'hs-check', 'data-all': '1' })])); }
		(cfg.columns || []).forEach(function (col) {
			var th = h('th', {
				class: (col.sortable ? 'hs-th-sort' : '') + (cfg.sort && cfg.sort.by === col.key ? ' is-sorted' : ''),
				style: col.width ? { width: col.width } : null,
				text: (col.label || '') + (cfg.sort && cfg.sort.by === col.key ? (cfg.sort.dir === 'asc' ? ' ↑' : ' ↓') : '')
			});
			if (col.sortable && cfg.onSort) { th.dataset.sort = col.key; }
			trh.appendChild(th);
		});
		thead.appendChild(trh);
		t.appendChild(thead);
		var tb = h('tbody');
		(cfg.rows || []).forEach(function (row, i) {
			var tr = h('tr', { dataset: { i: i, id: cfg.rowKey ? row[cfg.rowKey] : '' } });
			if (cfg.picky) { tr.appendChild(h('td', {}, [h('input', { type: 'checkbox', class: 'hs-check', 'data-id': row[cfg.rowKey] })])); }
			(cfg.columns || []).forEach(function (col) {
				var td = h('td', { class: col.align === 'num' ? 'hs-td--num' : '' });
				var v = col.render ? col.render(row) : row[col.key];
				if (v === null || v === undefined) { v = ''; }
				if (typeof v === 'object' && v.nodeType) { td.appendChild(v); }
				else { td.innerHTML = String(v.__raw !== undefined ? v.__raw : v); }
				tr.appendChild(td);
			});
			if (cfg.onRow) {
				tr.style.cursor = 'pointer';
				tr.addEventListener('click', function (e) {
					if (e.target.closest('button,a,input,select,label')) { return; }
					cfg.onRow(row, tr);
				});
			}
			tb.appendChild(tr);
		});
		t.appendChild(tb);
		wrap.appendChild(t);
		if (!(cfg.rows || []).length) {
			wrap.innerHTML = '';
			wrap.appendChild(empty(cfg.emptyTitle || I18N.noData, cfg.emptyHint, true, cfg.emptyAction));
		}
		if (cfg.onSort) {
			on(trh, 'click', '.hs-th-sort', function (e, th) { if (th.dataset.sort) { cfg.onSort(th.dataset.sort); } });
		}
		if (cfg.picky) {
			var all = qs('input[data-all]', trh);
			if (all) {
				all.addEventListener('change', function () {
					qsa('input[data-id]', tb).forEach(function (c) { c.checked = all.checked; c.closest('tr').classList.toggle('is-picked', all.checked); });
					if (cfg.onPick) { cfg.onPick(picked(wrap)); }
				});
			}
			on(tb, 'change', 'input[data-id]', function (e, c) {
				c.closest('tr').classList.toggle('is-picked', c.checked);
				if (cfg.onPick) { cfg.onPick(picked(wrap)); }
			});
		}
		if (cfg.footer) { wrap.appendChild(cfg.footer); }
		return wrap;
	}
	function picked(root) {
		return qsa('input[data-id]', root).filter(function (c) { return c.checked; }).map(function (c) { return c.getAttribute('data-id'); });
	}

	function empty(title, hint, small, action) {
		var e = h('div', { class: 'hs-empty', style: small ? { padding: '22px 14px' } : null });
		e.appendChild(icon('inbox'));
		e.appendChild(h('b', { text: title || I18N.noData }));
		if (hint) { e.appendChild(h('p', { text: hint })); }
		if (action) { mount(e, action); }
		return e;
	}
	function skeleton(rows) {
		var s = h('div', { class: 'hs-skeleton' });
		for (var i = 0; i < (rows || 4); i++) { s.appendChild(h('i', { style: { width: (60 + Math.random() * 38) + '%' } })); }
		return s;
	}
	function progress(value, max, kind) {
		var pctv = Math.max(0, Math.min(100, ((parseFloat(value) || 0) / (max || 100)) * 100));
		return h('div', { class: 'hs-progress' + (kind ? ' is-' + kind : '') }, [h('i', { class: 'hs-progress__bar', style: { width: pctv + '%' } })]);
	}
	function chip(text, kind, opts) {
		opts = opts || {};
		var c = h('span', { class: 'hs-chip' + (kind ? ' hs-chip--' + kind : ''), title: opts.title || '' });
		if (opts.dot !== false) { c.appendChild(h('i', { class: 'hs-dot' + (kind === 'ok' ? ' is-ok' : kind === 'bad' ? ' is-bad' : kind === 'warn' ? ' is-warn' : '') })); }
		c.appendChild(document.createTextNode(String(text)));
		if (opts.onClick) {
			c.style.cursor = 'pointer';
			c.addEventListener('click', function () { opts.onClick(); });
		}
		if (opts.onRemove) { c.appendChild(h('button', { html: '&times;', title: I18N.del, onclick: function () { opts.onRemove(); } })); }
		return c;
	}
	function note(kind, title, text, actions) {
		var n = h('div', { class: 'hs-note' + (kind ? ' hs-note--' + kind : '') });
		n.appendChild(icon(kind === 'bad' ? 'warning' : kind === 'warn' ? 'info' : 'check'));
		var m = h('div', { class: 'hs-col' });
		if (title) { m.appendChild(h('b', { text: title })); }
		if (text) { m.appendChild(h('span', { text: text })); }
		n.appendChild(m);
		if (actions) {
			var a = h('div', { class: 'hs-row', style: { marginInlineStart: 'auto' } });
			mount(a, actions);
			n.appendChild(a);
		}
		return n;
	}
	function tabs(items, active, onPick) {
		var w = h('div', { class: 'hs-tabs', role: 'tablist' });
		items.forEach(function (it) {
			var b = h('button', { class: 'hs-tab' + (it.id === active ? ' is-active' : ''), role: 'tab', dataset: { tab: it.id } });
			if (it.icon) { b.appendChild(icon(it.icon)); }
			b.appendChild(h('span', { text: it.label }));
			if (it.count !== undefined && it.count !== null) { b.appendChild(h('span', { class: 'hs-tab__count', text: num(it.count) })); }
			if (it.alert) { b.appendChild(h('i', { class: 'hs-tab__dot' })); }
			b.addEventListener('click', function () { onPick && onPick(it.id); });
			w.appendChild(b);
		});
		return w;
	}
	function segmented(opts, value, onChange) {
		var w = h('div', { class: 'hs-toggle-group', role: 'group' });
		opts.forEach(function (o) {
			var v = o.value !== undefined ? o.value : o.id;
			var b = h('button', { type: 'button', class: (String(v) === String(value) ? 'is-active' : ''), text: o.label, title: o.title || '' });
			b.addEventListener('click', function () { onChange(v); });
			w.appendChild(b);
		});
		return w;
	}
	function select(opts, value, onChange, attrs) {
		var s = h('select', Object.assign({ class: 'hs-select' }, attrs || {}));
		opts.forEach(function (o) {
			var v = (o.value !== undefined) ? o.value : o.id;
			var opt = h('option', { value: v, text: o.label !== undefined ? o.label : (o.name || v) });
			if (String(v) === String(value)) { opt.selected = true; }
			s.appendChild(opt);
		});
		if (onChange) { s.addEventListener('change', function () { onChange(s.value, s); }); }
		return s;
	}
	function input(field, value, onChange) {
		var attrs = { class: 'hs-input', name: field.key, placeholder: field.placeholder || '', title: field.help || '' };
		var el;
		if (field.type === 'textarea') {
			el = h('textarea', Object.assign(attrs, { class: 'hs-input hs-textarea' + (field.code ? ' hs-textarea--code' : ''), rows: field.rows || 4 }));
			el.value = value === undefined || value === null ? '' : (typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value));
		} else if (field.type === 'select') {
			return select(field.options || [], value, onChange, attrs);
		} else if (field.type === 'toggle') {
			return switchEl(!!value, function (v) { onChange(v); }, { title: field.help || '' });
		} else if (field.type === 'number') {
			Object.assign(attrs, { type: 'number', min: field.min, max: field.max, step: field.step || 1 });
			el = h('input', attrs);
			el.value = (value === undefined || value === null || value === '') ? '' : String(value);
		} else if (field.type === 'json' || field.type === 'code') {
			el = h('textarea', Object.assign(attrs, { class: 'hs-input hs-textarea hs-textarea--code', rows: field.rows || 8, spellcheck: 'false' }));
			el.value = value ? (typeof value === 'string' ? value : JSON.stringify(value, null, 2)) : '';
		} else if (field.type === 'list') {
			el = h('textarea', Object.assign(attrs, { class: 'hs-input hs-textarea', rows: field.rows || 4 }));
			el.value = arr(value).join('\n');
		} else if (field.type === 'secret') {
			el = h('input', Object.assign(attrs, { type: 'password', autocomplete: 'new-password', placeholder: value && value.set ? (value.masked || '••••••••') : (field.placeholder || '') }));
		} else {
			el = h('input', Object.assign(attrs, { type: field.type === 'url' ? 'url' : field.type === 'email' ? 'email' : field.type === 'color' ? 'color' : 'text' }));
			el.value = value === undefined || value === null ? '' : String(value);
		}
		if (onChange) {
			var fire = function () {
				var v = el.value;
				if (field.type === 'number') { v = v === '' ? '' : Number(v); }
				else if (field.type === 'list') { v = String(v).split(/\r?\n/).map(function (x) { return x.trim(); }).filter(Boolean); }
				else if (field.type === 'json' || field.type === 'code') {
					try { v = v.trim() ? JSON.parse(v) : null; } catch (e) { el.classList.add('is-bad-input'); onChange(v, e); return; }
				}
				el.classList.remove('is-bad-input');
				onChange(v);
			};
			el.addEventListener('input', fire);
			el.addEventListener('change', fire);
		}
		return el;
	}
	function fieldWrap(field, control) {
		var f = h('div', { class: 'hs-field' + (field.full ? ' hs-field--full' : '') });
		if (field.label) {
			var l = h('label', { class: 'hs-field__label' });
			l.appendChild(h('span', { text: field.label }));
			if (field.showKey) { l.appendChild(h('code', { class: 'hs-key', text: field.key })); }
			f.appendChild(l);
		}
		mount(f, control);
		if (field.help) { f.appendChild(h('div', { class: 'hs-field__help', text: field.help })); }
		return f;
	}

	/* ---------------- icons ---------------- */

	var PATHS = {
		gauge: 'M12 14l4-4M4.9 19a9 9 0 1 1 14.2 0',
		'check-list': 'M4 7l2 2 3-3M4 14l2 2 3-3M13 7h7M13 14h7M13 19h5',
		document: 'M7 3h7l4 4v14H7zM14 3v4h4M9 12h6M9 16h6',
		'search-snippet': 'M11 4h9M11 8h6M4 14a5 5 0 1 0 10 0 5 5 0 0 0-10 0M13 18l4 3',
		magnifier: 'M10.5 3a7.5 7.5 0 1 0 0 15 7.5 7.5 0 0 0 0-15zM16 16l5 5',
		trend: 'M4 17l5-6 4 3 7-8M20 6h-5M20 6v5',
		link: 'M9 15l6-6M10 6l-1.5 1.5a3.5 3.5 0 0 0 5 5M14 18l1.5-1.5a3.5 3.5 0 0 0-5-5',
		stethoscope: 'M6 3v6a4 4 0 0 0 8 0V3M10 13v3a5 5 0 0 0 5 5h1a3 3 0 0 0 3-3v-2M18 13a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
		map: 'M4 6l5-2 6 2 5-2v14l-5 2-6-2-5 2zM9 4v14M15 6v14',
		eye: 'M2.5 12S6 6.5 12 6.5 21.5 12 21.5 12 18 17.5 12 17.5 2.5 12 2.5 12zM12 14.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
		braces: 'M8 4c-2 0-2 3-2 4s0 4-2 4 2 4 2 4 0 4 2 4M16 4c2 0 2 3 2 4s0 4 2 4-2 4-2 4 0 4-2 4',
		'arrow-turn': 'M4 7h9a5 5 0 0 1 5 5v5M15 14l3 3 3-3',
		warning: 'M12 4l9 16H3zM12 10v5M12 18h.01',
		image: 'M4 5h16v14H4zM4 16l5-5 4 4 3-2 4 4M15 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
		cart: 'M4 5h2l2 10h9l2-7H7M9 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM17 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
		sparkle: 'M12 3l1.8 4.2L18 9l-4.2 1.8L12 15l-1.8-4.2L6 9l4.2-1.8zM18 15l.9 2.1L21 18l-2.1.9L18 21l-.9-2.1L15 18l2.1-.9z',
		chart: 'M4 20V9M10 20V4M16 20v-7M22 20H2',
		share: 'M7 12a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM17 17a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM9.5 9.8l5.9 4.4M9.5 14.2l5.9-4.4',
		bolt: 'M13 3L5 14h5l-1 7 8-11h-5z',
		robot: 'M9 4h6a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM12 11v4M8 20h8M7 15h10v5H7zM10 7h.01M14 7h.01',
		report: 'M6 3h9l4 4v14H6zM15 3v4h4M9 12h6M9 16h4',
		chip: 'M8 8h8v8H8zM6 6h12v12H6zM10 3v3M14 3v3M10 18v3M14 18v3M3 10h3M3 14h3M18 10h3M18 14h3',
		settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19 12l2-1-2-4-2 .5a7 7 0 0 0-1.5-.9L15 4h-4l-.5 2.6a7 7 0 0 0-1.5.9L5 7l-2 4 2 1a7 7 0 0 0 0 2l-2 1 2 4 2-.5c.5.4 1 .7 1.5.9L11 22h4l.5-2.6c.5-.2 1-.5 1.5-.9l2 .5 2-4-2-1a7 7 0 0 0 0-2z',
		text: 'M5 6h14M5 6V5h6v1M12 6v13M9 19h6',
		shield: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM9 12l2 2 4-4',
		bug: 'M9 8a3 3 0 0 1 6 0v5a3 3 0 0 1-6 0zM9 10H5M9 14H5M15 10h4M15 14h4M10 5L9 3M14 5l1-2M12 20a4 4 0 0 0 4-4H8a4 4 0 0 0 4 4z',
		wrench: 'M14 6a4 4 0 0 0 5 5l-9 9-3-3 9-9a4 4 0 0 0-2-2zM6 8L4 6l3-3 2 2',
		life: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM9.5 9.5A2.5 2.5 0 1 1 12 12v1M12 17h.01',
		check: 'M5 13l4 4L19 7',
		plus: 'M12 5v14M5 12h14',
		trash: 'M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6',
		pencil: 'M4 20l1-4L17 4l3 3L8 19zM15 6l3 3',
		download: 'M12 4v10M8 11l4 4 4-4M4 19h16',
		upload: 'M12 16V6M8 9l4-4 4 4M4 19h16',
		refresh: 'M20 12a8 8 0 1 1-3-6.2M20 4v4h-4',
		close: 'M6 6l12 12M18 6L6 18',
		inbox: 'M4 13l2-8h12l2 8v6H4zM4 13h5l1 2h4l1-2h5',
		info: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 11v6M12 8h.01',
		star: 'M12 4l2.3 5 5.7.6-4.2 3.8 1.2 5.6L12 16.3 7 19l1.2-5.6L4 9.6l5.7-.6z',
		clock: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7v5l4 2',
		external: 'M14 4h6v6M20 4l-9 9M18 13v6H5V6h6',
		spinner: 'M12 3a9 9 0 1 0 9 9',
		filter: 'M4 6h16l-6 7v6l-4-2v-4z',
		user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 20c0-3.3 3.6-5 8-5s8 1.7 8 5',
		copy: 'M9 9h10v10H9zM6 15H4V4h11v2',
		menu: 'M4 7h16M4 12h16M4 17h16',
		key: 'M15 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM13 8L4 17v3h3l9-9',
		play: 'M7 4l12 8-12 8z',
		pause: 'M8 5v14M16 5v14',
		stop: 'M6 6h12v12H6z',
		sun: 'M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10zM12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19',
		moon: 'M20 14a8 8 0 1 1-10-10 7 7 0 0 0 10 10z',
		wand: 'M5 19l10-10M15 5l4 4M13 7l4 4M8 3l1 2 2 1-2 1-1 2-1-2-2-1 2-1zM18 14l.7 1.4 1.4.6-1.4.7-.7 1.3-.7-1.3-1.3-.7 1.3-.6z',
		arrow: 'M5 12h14M13 6l6 6-6 6'
	};
	function icon(name, cls) {
		var d = PATHS[name] || PATHS.info;
		var s = '<svg viewBox="0 0 24 24" aria-hidden="true"' + (cls ? ' class="' + cls + '"' : '') + '><path d="' + d + '"/></svg>';
		var w = h('span', { class: 'hs-nav-item__ico', html: s });
		return w;
	}

	/* ---------------- settings helpers ---------------- */

	function sget(key, fallback) {
		var parts = String(key).split('.');
		var node = state.settings;
		for (var i = 0; i < parts.length; i++) {
			if (!node || typeof node !== 'object') { return fallback; }
			node = node[parts[i]];
		}
		if (node === undefined || node === null) { return fallback; }
		return node;
	}
	function moduleOn(group) {
		if (group === 'sitemap') { return !!sget('sitemap.enabled', true); }
		if (group === 'redirects') { return !!sget('redirects.enabled', true); }
		if (group === 'schema') { return !!sget('schema.enabled', true); }
		if (group === 'opengraph') { return !!sget('opengraph.enabled', true); }
		if (group === 'breadcrumbs') { return !!sget('breadcrumbs.enabled', true); }
		if (group === 'automation') { return !!sget('automation.enabled', false); }
		if (group === 'speed') { return !!sget('speed.enabled', true); }
		if (group === 'woo') { return !!sget('woo.enabled', true); }
		if (group === 'images') { return !!sget('images.enabled', true); }
		if (group === 'geo') { return !!sget('geo.llms_txt', true) || !!sget('geo.answer_box', true); }
		if (group === 'ai') { return !!(C.ai && C.ai.enabled); }
		return true;
	}
	function markDirty(key, value) {
		state.dirty[key] = value;
		var badge = qs('#hs-dirty');
		if (badge) {
			var n = Object.keys(state.dirty).length;
			badge.textContent = n ? num(n) + ' تغییر ذخیره‌نشده' : '';
			badge.classList.toggle('hs-hide', !n);
		}
	}
	function flushDirty(silent) {
		var keys = Object.keys(state.dirty);
		if (!keys.length) { return Promise.resolve(null); }
		var body = {};
		keys.forEach(function (k) {
			var parts = k.split('.');
			if (parts.length === 2) { body[k] = state.dirty[k]; }
		});
		state.dirty = {};
		markDirtyClear();
		return post('settings', body).then(function (res) {
			if (res && res.settings) { state.settings = res.settings; C.settings = res.settings; }
			if (!silent) { toast(I18N.saved, 'ok', { ms: 1600 }); }
			cacheClear('');
			return res;
		}, function (e) { fail(e); throw e; });
	}
	function markDirtyClear() {
		var badge = qs('#hs-dirty');
		if (badge) { badge.textContent = ''; badge.classList.add('hs-hide'); }
	}

	/* ---------------- router ---------------- */

	var views = {};
	function view(id, def) { views[id] = def; }

	function parseRoute() {
		var hash = (location.hash || '').replace(/^#\/?/, '');
		var path = hash.split('?')[0];
		var q = {};
		hash.split('?')[1] && hash.split('?')[1].split('&').forEach(function (kv) {
			var p = kv.split('=');
			q[decodeURIComponent(p[0])] = p.length > 1 ? decodeURIComponent(p[1] || '') : '';
		});
		var usp = new URLSearchParams(location.search);
		if (!path) { path = usp.get('view') || usp.get('hoosh_view') || ''; }
		usp.forEach(function (v, k) { if (q[k] === undefined) { q[k] = v; } });
		if (!path) { path = (C.features && C.features.firstView) || 'dashboard'; }
		if (!views[path]) { path = 'dashboard'; }
		return { id: path, params: q };
	}

	function go(to, params) {
		var q = params || {};
		var qsStr = Object.keys(q).filter(function (k) { return q[k] !== undefined && q[k] !== '' && q[k] !== null; })
			.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(q[k]); }).join('&');
		location.hash = '#/' + to + (qsStr ? '?' + qsStr : '');
	}

	function renderRoute() {
		var r = parseRoute();
		state.view = r.id;
		state.params = r.params;
		qsa('.hs-nav-item').forEach(function (a) {
			a.classList.toggle('is-active', a.dataset.view === r.id);
			if (a.dataset.view === r.id) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
		});
		var def = views[r.id] || views.dashboard;
		var host = qs('#hs-view');
		if (!host) { return; }
		var root = qs('#hs-root');
		if (root) { root.setAttribute('aria-busy', 'true'); }
		host.innerHTML = '';
		host.appendChild(skeleton(5));
		var top = qs('#hs-top-title');
		if (top) { top.textContent = def.title || r.id; }
		var sub = qs('#hs-top-sub');
		if (sub) { sub.textContent = def.sub || ''; }
		var acts = qs('#hs-top-actions');
		if (acts) { acts.innerHTML = ''; }
		document.title = (def.title || 'استودیو') + ' — استودیوی هوش‌سئو';
		Promise.resolve(def.render(host, r.params, acts)).then(function () {
			if (root) { root.setAttribute('aria-busy', 'false'); }
			qs('#hs-scroll') && (qs('#hs-scroll').scrollTop = 0);
		}, function (e) {
			if (root) { root.setAttribute('aria-busy', 'false'); }
			host.innerHTML = '';
			host.appendChild(card({ title: 'خطا در بارگذاری', icon: 'warning' }, [
				note('bad', (e && e.message) || I18N.error, (e && e.status ? 'HTTP ' + e.status : 'REST') + ' — ' + (e && e.data && e.data.code ? e.data.code : '')),
				h('div', { class: 'hs-row' }, [btn(I18N.retry, { kind: 'primary', icon: 'refresh', run: function () { renderRoute(); } })])
			]));
			fail(e);
		});
	}

	function navItems() {
		var out = [];
		(C.nav || []).forEach(function (grp) {
			arr(grp.items).forEach(function (it) { out.push({ group: grp.group, id: it.id, label: it.label, icon: it.icon, badge: it.badge }); });
		});
		if (!out.length) {
			['dashboard', 'content', 'keywords', 'links', 'audit', 'sitemap', 'index', 'schema', 'redirects', 'notfound', 'images', 'woo', 'ai-search', 'search-console', 'social', 'speed', 'autopilot', 'reports', 'ai', 'settings', 'titles', 'robots', 'crawl', 'tools', 'help'].forEach(function (id) {
				out.push({ group: '', id: id, label: id, icon: 'settings' });
			});
		}
		return out;
	}

	return {
		C: C, I18N: I18N, esc: esc, html: html, Raw: Raw, h: h, mount: mount, widgetIds: widgetIds, qs: qs, qsa: qsa, on: on, frag: frag,
		num: num, pct: pct, bytes: bytes, faDate: faDate, ago: ago, trunc: trunc, entries: entries, labelOf: labelOf, isObj: isObj, arr: arr,
		state: state, api: api, get: get, post: post, postQ: postQ, apiUrl: apiUrl,
		toast: toast, fail: fail, modal: modal, confirmBox: confirmBox, drawer: drawer,
		card: card, btn: btn, switchEl: switchEl, kpis: kpis, bars: bars, donut: donut, lineChart: lineChart,
		table: table, picked: picked, empty: empty, skeleton: skeleton, progress: progress, chip: chip, note: note,
		tabs: tabs, segmented: segmented, select: select, input: input, fieldWrap: fieldWrap, icon: icon, iconPaths: PATHS,
		sget: sget, moduleOn: moduleOn, markDirty: markDirty, flushDirty: flushDirty, cacheClear: cacheClear, cacheGet: cacheGet, cacheSet: cacheSet,
		view: view, go: go, renderRoute: function () { renderRoute(); }, parseRoute: parseRoute, navItems: navItems,
		shell: {}
	};
})();

/* =================== shell =================== */
(function (HS) {
	'use strict';
	var h = HS.h, qs = HS.qs, qsa = HS.qsa, C = HS.C, num = HS.num, trunc = HS.trunc;

	function buildShell() {
		var root = qs('#hs-root');
		if (!root) { return; }
		root.className = 'hs-app' + (HS.state.collapsed ? ' hs-collapsed' : '');

		/* sidebar */
		var brand = h('div', { class: 'hs-brand' }, [
			h('div', { class: 'hs-brand-mark', text: 'هش' }),
			h('div', { class: 'hs-brand-txt' }, [
				h('b', { text: 'استودیوی هوش‌سئو' }),
				h('span', { text: 'نسخه ' + (C.version || '1.0') + ' · ' + ((C.site && C.site.name) || '') })
			]),
			HS.btn(null, { icon: 'menu', kind: 'ghost', title: 'جمع/باز کردن منو', run: function () {
				HS.state.collapsed = !HS.state.collapsed;
				root.classList.toggle('hs-collapsed', HS.state.collapsed);
				try { localStorage.setItem('hoosh:collapsed', HS.state.collapsed ? '1' : '0'); } catch (e) {}
			} })
		]);

		var jump = h('button', { class: 'hs-jump', type: 'button' }, [
			HS.icon('magnifier'), h('span', { text: 'پریدن به…' }), h('kbd', { text: '⌘K' })
		]);
		jump.addEventListener('click', openPalette);

		var nav = h('nav', { class: 'hs-nav', 'aria-label': 'بخش‌ها' });
		var groups = [];
		HS.navItems().forEach(function (it) {
			var g = null;
			for (var i = 0; i < groups.length; i++) { if (groups[i].name === it.group) { g = groups[i]; break; } }
			if (!g) { g = { name: it.group, items: [] }; groups.push(g); }
			g.items.push(it);
		});
		groups.forEach(function (g) {
			var box = h('div', { class: 'hs-nav-group' });
			if (g.name) { box.appendChild(h('div', { class: 'hs-nav-group__label', text: g.name })); }
			g.items.forEach(function (it) {
				var a = h('a', { class: 'hs-nav-item', href: '#/' + it.id, dataset: { view: it.id, badge: it.badge || '' } });
				var ico = HS.icon(it.icon);
				ico.className = 'hs-nav-item__ico';
				a.appendChild(ico);
				a.appendChild(h('span', { class: 'hs-nav-item__label', text: it.label }));
				if (it.badge) { a.appendChild(h('span', { class: 'hs-nav-badge hs-hide', dataset: { badge: it.badge } })); }
				box.appendChild(a);
			});
			nav.appendChild(box);
		});

		var foot = h('div', { class: 'hs-side-foot' }, [
			HS.btn(null, { icon: HS.state.theme === 'dark' ? 'sun' : 'moon', kind: 'ghost', title: 'حالت روشن/تاریک', run: cycleTheme }),
			HS.btn('پیشخوان', { kind: 'ghost', icon: 'external', href: (C.site && C.site.admin) || '/wp-admin/', target: '_blank', rel: 'noopener' }),
			HS.btn('سایت', { kind: 'ghost', icon: 'arrow', href: (C.site && C.site.home) || '/', target: '_blank', rel: 'noopener' })
		]);

		var side = h('aside', { class: 'hs-side' }, [brand, jump, nav, foot]);

		/* top bar */
		var actions = h('div', { class: 'hs-top__actions', id: 'hs-top-actions' });
		var top = h('header', { class: 'hs-top' }, [
			h('div', { class: 'hs-col' }, [
				h('h1', { class: 'hs-top__title', id: 'hs-top-title', text: 'داشبورد' }),
				h('p', { class: 'hs-top__sub', id: 'hs-top-sub' })
			]),
			actions
		]);
		actions.appendChild(h('span', { class: 'hs-chip hs-chip--warn hs-hide', id: 'hs-dirty' }));
		actions.appendChild(HS.btn(null, { icon: 'refresh', kind: 'ghost', title: 'نوسازی داده‌ها', run: function () { HS.cacheClear(''); HS.renderRoute(); } }));
		actions.appendChild(HS.segmented([{ id: 'easy', label: 'ساده' }, { id: 'advanced', label: 'پیشرفته' }], HS.state.mode, function (v) {
			HS.state.mode = v;
			try { localStorage.setItem('hoosh:mode', v); } catch (e) {}
			HS.renderRoute();
		}));

		var scroll = h('main', { class: 'hs-scroll', id: 'hs-scroll' }, [h('div', { class: 'hs-view', id: 'hs-view' })]);
		root.appendChild(side);
		root.appendChild(h('div', { class: 'hs-main' }, [top, scroll]));
		HS.shell.nav = nav;
		HS.shell.root = root;
		HS.shell.actions = actions;
		root.addEventListener('click', function (e) {
			if (window.innerWidth <= 960) { root.classList.remove('hs-mobile-nav'); }
		});
		top.insertBefore(h('button', {
			class: 'hs-btn hs-btn--ghost hs-btn--icon hs-mobile-only', 'aria-label': 'منو',
			onclick: function () { root.classList.toggle('hs-mobile-nav'); }
		}), top.firstChild);
	}

	function applyTheme(theme) {
		HS.state.theme = theme;
		document.documentElement.setAttribute('data-theme', theme);
		try { localStorage.setItem('hoosh:theme', theme); } catch (e) {}
		HS.renderRoute();
	}
	function cycleTheme() {
		var order = ['light', 'dark', 'auto'];
		var i = order.indexOf(HS.state.theme);
		applyTheme(order[(i + 1) % order.length]);
		toast2('حالت نمایش: ' + ({ light: 'روشن', dark: 'تیره', auto: 'خودکار' }[HS.state.theme]));
	}
	function toast2(msg) { HS.toast(msg, 'info', { ms: 1800 }); }

	function paintBadges() {
		var snap = C.kpiSnapshot || {};
		var values = {
			tasks: (parseInt(snap.critical, 10) || 0) + (parseInt(snap.warnings, 10) || 0),
			issues: parseInt(snap.critical, 10) || 0,
			'404': parseInt(snap['404'], 10) || 0
		};
		qsa('[data-badge]').forEach(function (el) {
			var key = el.dataset.badge;
			var v = values[key];
			if (!v) { el.classList.add('hs-hide'); return; }
			el.classList.remove('hs-hide');
			el.textContent = num(v);
			el.classList.toggle('is-bad', key === 'issues' || key === '404');
		});
	}

	/* command palette */
	var palette = null;
	function openPalette() {
		if (palette) { palette.close(); return; }
		var items = HS.navItems().map(function (it) { return { group: it.group, label: it.label, icon: it.icon, run: function () { HS.go(it.id); } }; });
		items.push({ group: 'اقدام', label: 'نوسازی داده‌ها', icon: 'refresh', run: function () { HS.cacheClear(''); HS.renderRoute(); } });
		items.push({ group: 'اقدام', label: 'تغییر حالت روشن/تاریک', icon: 'moon', run: cycleTheme });
		items.push({ group: 'اقدام', label: 'گزارش کامل (چاپ‌پذیر)', icon: 'report', run: function () { HS.go('reports'); } });
		items.push({ group: 'اقدام', label: 'باز کردن سایت', icon: 'external', run: function () { window.open((C.site && C.site.home) || '/', '_blank'); } });

		var box = h('div', { class: 'hs-cmdk__box' });
		var inp = h('input', { type: 'text', placeholder: 'بخش یا اقدامی را جست‌وجو کنید…', 'aria-label': 'جست‌وجو', autocomplete: 'off' });
		var list = h('div', { class: 'hs-cmdk__list' });
		box.appendChild(h('div', { class: 'hs-cmdk__in' }, [HS.icon('magnifier'), inp]));
		box.appendChild(list);
		var wrap = h('div', { class: 'hs-cmdk' }, [box]);
		var back = h('div', { class: 'hs-backdrop' });
		var sel = 0, shown = [];

		function paint() {
			var q = inp.value.trim().toLowerCase();
			shown = items.filter(function (it) { return !q || ((it.label + ' ' + (it.group || ''))).toLowerCase().indexOf(q) > -1; }).slice(0, 40);
			if (sel >= shown.length) { sel = Math.max(0, shown.length - 1); }
			list.innerHTML = '';
			var lastG = null;
			shown.forEach(function (it, i) {
				if (it.group && it.group !== lastG) { list.appendChild(h('div', { class: 'hs-cmdk__grp', text: it.group })); lastG = it.group; }
				var row = h('div', { class: 'hs-cmdk__item' + (i === sel ? ' is-sel' : ''), dataset: { i: i } });
				row.appendChild(HS.icon(it.icon));
				row.appendChild(h('span', { text: it.label }));
				row.addEventListener('click', function () { pick(i); });
				row.addEventListener('mouseenter', function () { sel = i; qsa('.hs-cmdk__item', list).forEach(function (x) { x.classList.remove('is-sel'); }); row.classList.add('is-sel'); });
				list.appendChild(row);
			});
			if (!shown.length) { list.appendChild(h('div', { class: 'hs-cmdk__item hs-muted', text: HS.I18N.notFound })); }
		}
		function pick(i) { var it = shown[i]; close(); if (it && it.run) { it.run(); } }
		function onKey(e) {
			if (e.key === 'Escape') { e.preventDefault(); close(); return; }
			if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(shown.length - 1, sel + 1); paint(); scrollSel(); return; }
			if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(0, sel - 1); paint(); scrollSel(); return; }
			if (e.key === 'Enter') { e.preventDefault(); pick(sel); }
		}
		function scrollSel() { var el = qs('.hs-cmdk__item.is-sel', list); if (el) { el.scrollIntoView({ block: 'nearest' }); } }
		function close() {
			if (!palette) { return; }
			document.removeEventListener('keydown', onKey);
			back.remove();
			wrap.remove();
			palette = null;
		}
		inp.addEventListener('input', function () { sel = 0; paint(); });
		back.addEventListener('click', close);
		document.addEventListener('keydown', onKey);
		document.body.appendChild(back);
		document.body.appendChild(wrap);
		palette = { close: close };
		paint();
		inp.focus();
	}

	/* notices + boot */
	function noticeCard(n) {
		var kind = n.level === 'error' ? 'bad' : (n.level === 'warning' ? 'warn' : null);
		var actions = [];
		if (n.action_label) { actions.push(HS.btn(n.action_label, { kind: 'primary', sm: true, href: n.action_url, target: '_self' })); }
		actions.push(HS.btn(null, {
			icon: 'close', kind: 'ghost', sm: true, title: HS.I18N.dismiss, run: function () {
				return HS.post('notices/dismiss', { key: n.key }).then(function () { card.remove(); }, function (e) { HS.fail(e); });
			}
		}));
		var card = HS.card({}, [HS.note(kind, n.title || '', n.message || '', actions)]);
		card.classList.add('hs-card--plain');
		card.style.border = '0';
		card.style.boxShadow = 'none';
		return card;
	}

	function injectFont() {
		var a = C.appearance || {};
		var url = a.fontUrl && String(a.fontUrl).trim() ? String(a.fontUrl).trim() : (a.remoteFont ? 'https://cdn.jsdelivr.net/npm/vazirmatn@3.3.1/misc/Vazirmatn-font-face.css' : '');
		if (!url) { return; }
		var link = h('link', { rel: 'stylesheet', href: url });
		document.head.appendChild(link);
		if (url.indexOf('jsdelivr') > -1) {
			var pre = h('link', { rel: 'preconnect', href: 'https://cdn.jsdelivr.net', crossorigin: '' });
			document.head.insertBefore(pre, link);
		}
	}
	function injectCss() {
		var css = (C.appearance && C.appearance.customCss) || '';
		if (!css) { return; }
		document.head.appendChild(h('style', { 'data-hoosh': 'custom', text: css }));
	}

	function boot() {
		try {
			var saved = localStorage.getItem('hoosh:theme');
			if (saved && ['light', 'dark', 'auto'].indexOf(saved) > -1) { HS.state.theme = saved; }
		} catch (e) {}
		document.documentElement.setAttribute('data-theme', HS.state.theme);
		injectFont();
		injectCss();
		buildShell();
		paintBadges();
		var host = qs('#hs-view');
		if (host) {
			var strip = h('div', { id: 'hs-notices' });
			(C.notices || []).slice(0, 4).forEach(function (n) { strip.appendChild(noticeCard(n)); });
			host.parentNode.insertBefore(strip, host);
		}
		window.addEventListener('hashchange', function () { HS.renderRoute(); });
		document.addEventListener('keydown', function (e) {
			if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); openPalette(); }
			if (e.target && /input|textarea|select/i.test(e.target.tagName)) { return; }
			if (e.key === 'r' && !e.metaKey && !e.ctrlKey && !e.altKey) { HS.cacheClear(''); HS.renderRoute(); }
		});
		window.addEventListener('online', function () { location.reload(); });
		if (!navigator.onLine) { document.body.appendChild(h('div', { class: 'hs-offline', text: HS.I18N.offline })); }
		HS.renderRoute();
	}

	HS.boot = boot;
	HS.paintBadges = paintBadges;
	HS.noticeCard = noticeCard;

	/* =================== dashboard =================== */
	var WIDGETS = {};
	HS.dashboardWidget = function (id, def) { WIDGETS[id] = def; };

	HS.view('dashboard', {
		title: 'داشبورد',
		sub: 'یک نگاه به سلامت سایت، کارها و فرصت‌ها',
		render: function (host) {
			var snap = C.kpiSnapshot || {};
			var top = h('div', { class: 'hs-row hs-row--between' });
			var left = h('div', { class: 'hs-row' }, [
				HS.donut(snap.score, 'امتیاز کل سایت'),
				h('div', { class: 'hs-col' }, [
					h('b', { text: (snap.pages ? num(snap.pages) : '۰') + ' صفحه تحلیل‌شده' }),
					h('span', { class: 'hs-muted hs-small', text: 'پوشش: ' + HS.pct(snap.coverage) + ' · تغییرات ۷ روز اخیر: ' + num(snap.changes_7d) })
				])
			]);
			top.appendChild(left);
			var right = h('div', { class: 'hs-row' }, [
				HS.btn('اجرای ممیزی', { kind: 'primary', icon: 'stethoscope', run: runAudit }),
				HS.btn('شروع تحلیل محتوا', { icon: 'document', run: function () { HS.go('content'); } })
			]);
			top.appendChild(right);

			var kpi = HS.kpis([
				{ label: 'مشکلات بحرانی', value: num(snap.critical || 0), href: 'audit?severity=critical', delta: snap.critical > 0 ? -1 : 0 },
				{ label: 'هشدارها', value: num(snap.warnings || 0), href: 'audit?severity=warning' },
				{ label: 'ریدایرکت فعال', value: num(snap.redirects || 0), href: 'redirects' },
				{ label: 'خطای ۴۰ باز', value: num(snap['404'] || 0), href: 'notfound' },
				{ label: 'کلمه در رصد', value: num(snap.keywords || 0), href: 'tracking' },
				{ label: 'تصویر بدون متن جایگزین', value: num(snap.broken || 0), href: 'images' }
			]);

			host.appendChild(HS.card({ title: 'خلاصه امروز', icon: 'gauge', sub: (C.site && C.site.name) || '' }, [top, kpi]));

			var enabled = HS.widgetIds(C.appearance && C.appearance.widgets);
			var grid = h('div', { class: 'hs-cols hs-cols--2' });
			enabled.forEach(function (w) {
				if (!WIDGETS[w]) { return; }
				var el = h('div', {}, [HS.skeleton(3)]);
				grid.appendChild(el);
				Promise.resolve(WIDGETS[w].load(el)).catch(function (e) {
					el.innerHTML = '';
					el.appendChild(HS.card({ title: WIDGETS[w].title, icon: WIDGETS[w].icon }, [HS.note('bad', 'این بخش بارگذاری نشد', e.message)]));
				});
			});
			host.appendChild(grid);

			var mods = h('div', { class: 'hs-modgrid' });
			[['autopilot', 'robot', 'خلبانی سئو', 'کارهای خودکار: تولید توضیحات، لینک داخلی، پر کردن متن جایگزین و رصد رتبه.'],
			['ai', 'chip', 'هوش مصنوعی', 'ارسال درخواست به مدل‌ها، صف کارها و گزارش هزینه.'],
			['ai-search', 'sparkle', 'موتورهای پاسخ', 'فایل llms.txt، جعبه پاسخ و دیده‌شدن در ChatGPT و Perplexity.'],
			['speed', 'bolt', 'سرعت', 'تیک‌های بهینه‌سازی، کش و امتیاز PageSpeed.'],
			['tools', 'wrench', 'مهاجرت و ابزارها', 'انتقال از افزونه‌های دیگر، پاک کردن کش و وضعیت cron.']]
				.forEach(function (m) {
					var c = h('article', { class: 'hs-modcard' });
					var ic = HS.icon(m[1]); ic.className = 'hs-modcard__ico';
					c.appendChild(ic);
					c.appendChild(h('h3', { class: 'hs-modcard__title', text: m[2] }));
					c.appendChild(h('p', { class: 'hs-modcard__desc', text: m[3] }));
					c.appendChild(h('div', { class: 'hs-modcard__foot' }, [
						HS.btn('باز کردن', { kind: 'ghost', sm: true, icon: 'arrow', run: function () { HS.go(m[0]); } })
					]));
					mods.appendChild(c);
				});
			host.appendChild(HS.card({ title: 'ابزارهای پرکاربرد', icon: 'wrench' }, [mods]));

			function runAudit() {
				HS.toast('ممیزی فنی در حال اجرا…', 'info', { ms: 1500 });
				return HS.get('audit', { refresh: 1 }).then(function (res) {
					HS.cacheClear('audit');
					var c = (res && res.summary) || {};
					HS.toast('ممیزی تمام شد — ' + num(c.score !== undefined ? c.score : (res.score || 0)) + ' از ۱۰۰', 'ok');
					HS.renderRoute();
				});
			}
		}
	});

	/* widgets */
	HS.dashboardWidget('score', {
		title: 'سلامت محتوا', icon: 'stethoscope',
		load: function (el) {
			return HS.get('content/pages', { per: 6, orderby: 'score', order: 'asc', filter: 'all' }).then(function (res) {
				var rows = (res && res.rows) || [];
				el.innerHTML = '';
				var dist = [];
				entries2(res && res.distribution).forEach(function (p) { dist.push({ label: p[0], value: p[1] }); });
				var body = h('div', { class: 'hs-col' });
				if (dist.length) { body.appendChild(HS.bars(dist)); }
				var list = h('div', { class: 'hs-list' });
				rows.forEach(function (r) {
					var li = h('div', { class: 'hs-list__item' });
					li.appendChild(h('span', { class: 'hs-dot ' + scoreKind(r.score) }));
					li.appendChild(h('div', { class: 'hs-list__main' }, [
						h('div', { class: 'hs-list__title', text: r.title || '(بی‌عنوان)' }),
						h('div', { class: 'hs-list__meta' }, [h('span', { text: num(r.words || 0) + ' واژه' }), h('span', { text: 'امتیاز ' + num(r.score) })])
					]));
					li.appendChild(HS.btn('ویرایش', { sm: true, kind: 'ghost', href: editUrl(r), target: '_blank' }));
					list.appendChild(li);
				});
				if (!rows.length) { list.appendChild(HS.empty('هنوز صفحه‌ای تحلیل نشده', 'دکمه «شروع تحلیل محتوا» را بزنید.', true, HS.btn('تحلیل محتوا', { kind: 'primary', sm: true, run: function () { HS.go('content'); } }))); }
				body.appendChild(list);
				el.appendChild(HS.card({ title: 'ضعیف‌ترین صفحه‌ها', icon: 'stethoscope', sub: 'بر اساس امتیاز' }, [body]));
			});
		}
	});
	HS.dashboardWidget('issues', {
		title: 'اقدام‌های فوری', icon: 'check-list',
		load: function (el) {
			return HS.get('audit', { per: 7 }).then(function (res) {
				el.innerHTML = '';
				var rows = (res && res.rows) || [];
				var list = h('div', { class: 'hs-list' });
				rows.forEach(function (r) {
					var li = h('div', { class: 'hs-list__item' });
					li.appendChild(h('span', { class: 'hs-dot ' + (r.severity === 'critical' ? 'is-bad' : 'is-warn') }));
					li.appendChild(h('div', { class: 'hs-list__main' }, [
						h('div', { class: 'hs-list__title', text: r.title || r.label || r.check || '' }),
						h('div', { class: 'hs-list__meta' }, [h('span', { text: r.url || '' })])
					]));
					if (r.id && HS.revertChange) { li.appendChild(HS.btn('بازگردانی', { sm: true, kind: 'ghost', run: function () { return HS.revertChange(r); } })); }
					list.appendChild(li);
				});
				if (!rows.length) { list.appendChild(HS.empty('تغییری ثبت نشده است', 'وقتی چیزی را تغییر دهید یا خودکارسازی اجرا شود، اینجا گزارش می‌شود.', true)); }
				el.appendChild(HS.card({ title: 'آخرین تغییرات و پیشنهادها', icon: 'clock', actions: [HS.btn(HS.I18N.viewAll, { kind: 'ghost', sm: true, run: function () { HS.go('audit'); } })] }, [list]));
			});
		}
	});
	HS.dashboardWidget('traffic', {
		title: 'ترافیک', icon: 'chart',
		load: function (el) {
			return HS.get('analytics', { days: 28 }).then(function (res) {
				el.innerHTML = '';
				res = res || {};
				var series = res.series || [];
				var labels = res.labels || [];
				var body = h('div', { class: 'hs-col' });
				body.appendChild(HS.lineChart([{ label: 'کلیک', values: series.map(function (p) { return p.clicks !== undefined ? p.clicks : p[1]; }), labels: labels }], { height: 150 }));
				if (res.queries && res.queries.length) {
					body.appendChild(HS.bars(res.queries.slice(0, 6).map(function (q) {
						return { label: q.label || q.query || q[0], value: q.clicks !== undefined ? q.clicks : q.value, display: num(q.clicks !== undefined ? q.clicks : q.value) };
					})));
				}
				var k = HS.kpis([
					{ label: 'کلیک (۲۸ روز)', value: num(res.clicks || 0), delta: res.delta && res.delta.clicks },
					{ label: 'بازدید', value: num(res.impressions || 0) },
					{ label: 'نرخ نمایش', value: HS.pct((parseFloat(res.ctr) || 0) * (parseFloat(res.ctr) < 1 ? 100 : 1)) },
					{ label: 'میانگین رتبه', value: num(res.position_avg || res.position || 0, 1) }
				]);
				if (res.available === false) {
					el.innerHTML = '';
					el.appendChild(HS.card({ title: 'سرچ کنسول متصل نیست', icon: 'chart' }, [
						HS.note('warn', 'برای دیدن آمار واقعی، حساب Search Console را وصل کنید یا فایل CSV را درون‌ریزی کنید.', '',
							[HS.btn('اتصال / درون‌ریزی', { kind: 'primary', sm: true, run: function () { HS.go('search-console'); } })])
					]));
					return;
				}
				el.appendChild(HS.card({ title: 'کلیک‌ها و پربازدیدترین پرس‌وجوها', icon: 'chart', sub: res.source || '', actions: [HS.btn('جزئیات', { kind: 'ghost', sm: true, run: function () { HS.go('search-console'); } })] }, [k, body]));
			});
		}
	});
	HS.dashboardWidget('keywords', {
		title: 'کلمات کلیدی', icon: 'magnifier',
		load: function (el) {
			return HS.get('rank/series', { days: 14 }).then(function (res) {
				el.innerHTML = '';
				var s = (res && res.summary) || res || {};
				var body = h('div', { class: 'hs-col' });
				body.appendChild(HS.kpis([
					{ label: 'در سه رتبه اول', value: num(s.top3 || 0) },
					{ label: 'در ده رتبه اول', value: num(s.top10 || 0) },
					{ label: 'میانگین رتبه', value: num(s.average || 0, 1) },
					{ label: 'رشد', value: num(s.improved || 0), delta: s.improved }
				]));
				var rows = (s.gainers || []).concat(s.losers || []).slice(0, 6);
				if (rows.length) {
					body.appendChild(HS.bars(rows.map(function (r) {
						return { label: r.phrase || r.keyword || '', value: Math.abs(parseFloat(r.delta) || 0), display: (parseFloat(r.delta) >= 0 ? '+' : '') + num(r.delta, 1), kind: parseFloat(r.delta) >= 0 ? 'ok' : 'bad' };
					})));
				}
				el.appendChild(HS.card({ title: 'تغییر رتبه‌ها', icon: 'trend', sub: '۱۴ روز اخیر', actions: [HS.btn('ردیاب', { kind: 'ghost', sm: true, run: function () { HS.go('tracking'); } })] }, [body]));
			});
		}
	});
	HS.dashboardWidget('ai', {
		title: 'هوش مصنوعی', icon: 'chip',
		load: function (el) {
			return (C.ai && C.ai.enabled) ? HS.get('ai/overview').then(function (res) { paint(res); }, function () { paint(null); }) : Promise.resolve(paint(null));
			function paint(res) {
				el.innerHTML = '';
				var spend = (res && res.spend) || (C.ai && C.ai.spend) || {};
				if (!C.ai || !C.ai.enabled || !C.features.aiReady) {
					el.appendChild(HS.card({ title: 'هوش مصنوعی پیکربندی نشده', icon: 'chip' }, [
						HS.note('warn', 'با اتصال یک کلید API، تولید عنوان و توضیحات، متن جایگزین تصویر و پاسخ به موتورهای پاسخ فعال می‌شود.', ''),
						HS.btn('تنظیمات هوش مصنوعی', { kind: 'primary', run: function () { HS.go('ai'); } })
					]));
					return;
				}
				var body = h('div', { class: 'hs-col' });
				body.appendChild(HS.kpis([
					{ label: 'درخواست‌های امروز', value: num(spend.today_calls || spend.calls || 0) },
					{ label: 'هزینه این ماه', value: '$' + num(spend.month_cost || spend.cost || 0, 3) },
					{ label: 'در صف', value: num((res && res.queued) || 0) },
					{ label: 'موفق', value: num((res && res.done) || 0) }
				]));
				var jobs = (res && res.jobs) || [];
				var list = h('div', { class: 'hs-list' });
				jobs.slice(0, 5).forEach(function (j) {
					list.appendChild(h('div', { class: 'hs-list__item' }, [
						h('span', { class: 'hs-dot ' + (j.status === 'failed' ? 'is-bad' : j.status === 'running' ? 'is-info' : 'is-ok') }),
						h('div', { class: 'hs-list__main' }, [
							h('div', { class: 'hs-list__title', text: j.type || j.task || '' }),
							h('div', { class: 'hs-list__meta' }, [h('span', { text: j.status || '' }), h('span', { text: j.post_id ? ('نوشته ' + num(j.post_id)) : '' })])
						])
					]));
				});
				if (!jobs.length) { list.appendChild(HS.empty('کاری در صف نیست', 'از استودیو محتوا یا خلبانی سئو درخواست بدهید.', true)); }
				body.appendChild(list);
				el.appendChild(HS.card({ title: 'وضعیت هوش مصنوعی', icon: 'chip', sub: (res && res.driver) || (C.ai.driver || ''), actions: [HS.btn('جزئیات', { kind: 'ghost', sm: true, run: function () { HS.go('ai'); } })] }, [body]));
			}
		}
	});
	HS.dashboardWidget('actions', {
		title: 'میان‌بر', icon: 'bolt',
		load: function (el) {
			var quick = [
				['افزودن ریدایرکت', 'redirects', 'arrow-turn', function () { HS.go('redirects', { new: 1 }); }],
				['ساخت نقشه سایت', 'sitemap', 'map', function () { HS.postQ('sitemap/ping').then(function () { HS.toast('نقشه سایت به‌روزرسانی شد', 'ok'); }, HS.fail); }],
				['پاک کردن کش', 'tools', 'refresh', function () { return HS.post('tools/flush', {}).then(function () { HS.toast('کش‌ها پاک شدند', 'ok'); }, HS.fail); }],
				['اعلام به ایندکس‌بان', 'index', 'eye', function () { return HS.postQ('index/indexnow').then(function (r) { HS.toast(r && r.ok ? 'اینکس‌ناو ارسال شد' : 'اینکس‌ناو فعال نیست', r && r.ok ? 'ok' : 'warn'); }, HS.fail); }],
				['گزارش مدیریتی', 'reports', 'report', function () { HS.go('reports'); }],
				['تحلیل همه صفحه‌ها', 'content', 'document', function () { HS.go('content', { scan: 1 }); }]
			];
			var grid = h('div', { class: 'hs-cols hs-cols--2' });
			quick.forEach(function (q) {
				var ic = HS.icon(q[2]);
				grid.appendChild(h('button', { class: 'hs-step', type: 'button', onclick: function () { Promise.resolve(q[3]()).catch(HS.fail); } }, [
					ic, h('div', { class: 'hs-col' }, [h('span', { class: 'hs-step__t', text: q[0] })])
				]));
			});
			el.innerHTML = '';
			el.appendChild(HS.card({ title: 'میان‌برها', icon: 'bolt' }, [grid]));
			return Promise.resolve();
		}
	});

	/* =================== tasks =================== */
	HS.view('tasks', {
		title: 'صف کارها',
		sub: 'هر کاری که همین حالا ارزش انجام دارد، با یک دکمه',
		render: function (host) {
			host.appendChild(h('div', { class: 'hs-loading', text: HS.I18N.loading }));
			return Promise.all([
				safe(HS.get('content/pages', { per: 12, orderby: 'score', order: 'asc', filter: 'issues' })),
				safe(HS.get('audit', { per: 8 })),
				safe(HS.get('notfound', { per: 6 })),
				safe(HS.get('redirects', { per: 6, status: 'active' })),
				safe(HS.get('speed'))
			]).then(function (r) {
				host.innerHTML = '';
				var pages = (r[0] && r[0].rows) || [];
				var audit = (r[1] && (r[1].rows || r[1].issues)) || [];
				var nf = (r[2] && r[2].rows) || [];
				var speed = r[4] || {};
				var groups = [];

				if (pages.length) {
					groups.push({
						title: 'بهبود محتوا (' + num(pages.length) + ')', icon: 'document', kind: 'warn',
						rows: pages.map(function (p) {
							var issues = parseIssues(p.issues);
							return {
								label: p.title || '(بی‌عنوان)',
								meta: 'امتیاز ' + num(p.score) + ' · ' + num(p.words || 0) + ' واژه' + (issues.length ? (' · ' + issues.slice(0, 3).map(function (i) { return i.label || i.id; }).join('، ')) : ''),
								href: editUrl(p),
								actions: [
									HS.btn('تحلیل و اصلاح', { sm: true, kind: 'primary', run: function () { HS.go('content', { post: p.post_id || p.id }); } })
								]
							};
						})
					});
				}
				if (nf.length) {
					groups.push({
						title: 'خطاهای ۴۰ بدون مقصد (' + num(nf.length) + ')', icon: 'warning', kind: 'bad',
						rows: nf.map(function (n) {
							return {
								label: trunc(n.url, 70),
								meta: num(n.hits || 1) + ' بازدید · ' + HS.ago(n.last_seen || n.first_seen),
								actions: [HS.btn('ساخت ریدایرکت', { sm: true, kind: 'primary', run: function () { HS.go('redirects', { from: n.url, to: '', new: 1 }); } })]
							};
						})
					});
				}
				if (audit.length) {
					groups.push({
						title: 'یافته‌های ممیزی (' + num(audit.length) + ')', icon: 'stethoscope', kind: 'warn',
						rows: audit.slice(0, 10).map(function (a) {
							return {
								label: a.title || a.label || a.check || '',
								meta: (a.severity === 'critical' ? 'بحرانی' : 'هشدار') + (a.url ? (' · ' + trunc(a.url, 50)) : ''),
								actions: a.post_id ? [HS.btn('رفع', { sm: true, run: function () { HS.go('content', { post: a.post_id, fix: a.check || a.id }); } })] : []
							};
						})
					});
				}
				if (speed.rows) {
					var off = speed.rows.filter(function (x) { return x.safe && !x.on; });
					if (off.length) {
						groups.push({
							title: 'بهینه‌سازی سرعت (' + num(off.length) + ')', icon: 'bolt', kind: 'info',
							rows: off.slice(0, 8).map(function (o) {
								return {
									label: o.label, meta: (o.desc || '') + (o.impact ? (' · اثر: ' + o.impact) : ''),
									actions: [HS.btn('فعال کن', { sm: true, kind: 'primary', run: function () {
										return HS.post('speed/toggle', { key: o.key, value: o.type === 'bool' ? true : o.value }).then(function (res) {
											HS.toast(res && res.message ? res.message : 'فعال شد', 'ok');
										}, HS.fail);
									} })]
								};
							})
						});
					}
				}
				if (!groups.length) {
					host.appendChild(HS.card({ title: 'صف کارها خالی است', icon: 'check' }, [HS.note('ok', 'همه چیز در وضعیت خوب است', 'چند دقیقه دیگر دوباره نگاه کن یا یک تحلیل کامل اجرا کن.')]));
					return;
				}
				groups.forEach(function (g) {
					var list = h('div', { class: 'hs-list' });
					g.rows.forEach(function (r) {
						var row = h('div', { class: 'hs-list__item' });
						row.appendChild(h('span', { class: 'hs-dot is-' + g.kind }));
						var main = h('div', { class: 'hs-list__main' }, [
							r.href ? h('a', { class: 'hs-list__title', href: r.href, target: '_blank', rel: 'noopener', text: r.label }) : h('div', { class: 'hs-list__title', text: r.label }),
							h('div', { class: 'hs-list__meta', text: r.meta || '' })
						]);
						row.appendChild(main);
						var acts = h('div', { class: 'hs-row' });
						(r.actions || []).forEach(function (a) { acts.appendChild(a); });
						row.appendChild(acts);
						list.appendChild(row);
					});
					host.appendChild(HS.card({ title: g.title, icon: g.icon }, [list]));
				});
			});
		}
	});

	function safe(p) { return p.then(function (v) { return v; }, function () { return null; }); }
	function entries2(x) { return HS.entries(x); }
	function parseIssues(v) {
		if (!v) { return []; }
		if (typeof v === 'string') { try { v = JSON.parse(v); } catch (e) { return []; } }
		return Array.isArray(v) ? v : [];
	}
	function scoreKind(s) { s = parseFloat(s) || 0; return s >= 80 ? 'is-ok' : (s >= 50 ? 'is-warn' : 'is-bad'); }
	function editUrl(row) {
		var id = row && (row.post_id || row.id);
		if (!id) { return '#'; }
		return ((C.site && C.site.admin) || '/wp-admin/') + 'post.php?post=' + id + '&action=edit';
	}
	HS.safe = safe; HS.parseIssues = parseIssues; HS.scoreKind = scoreKind; HS.editUrl = editUrl;
})(HS);

/* =================== content studio, snippet, keywords, rank =================== */
(function (HS) {
	'use strict';
	var h = HS.h, qs = HS.qs, qsa = HS.qsa, on = HS.on, num = HS.num, esc = HS.esc, C = HS.C;
	var parseIssues = HS.parseIssues, scoreKind = HS.scoreKind, editUrl = HS.editUrl;

	var CONTENT_FILTERS = [
		{ id: 'all', label: 'همه' },
		{ id: 'issues', label: 'دارای مشکل' },
		{ id: 'critical', label: 'بحرانی' },
		{ id: 'ok', label: 'سالم' },
		{ id: 'thin', label: 'کم‌حجم' },
		{ id: 'untracked', label: 'بدون کلمه کلیدی' }
	];

	function contentTable(host, ctx) {
		var body = ctx.body;
		var rows = (body && body.rows) || [];
		var cols = [
			{ key: 'title', label: 'صفحه', sortable: true, render: function (r) {
				return '<div class="hs-col"><b>' + esc(r.title || '(بی‌عنوان)') + '</b><span class="hs-muted hs-small">' + esc(r.url || '') + '</span></div>';
			} },
			{ key: 'type', label: 'نوع', width: '110px', render: function (r) { return HS.chip(typeLabel(r.type), null, { dot: false }); } },
			{ key: 'words', label: 'واژه', align: 'num', sortable: true, width: '90px', render: function (r) { return num(r.words || 0); } },
			{ key: 'score', label: 'سئو', align: 'num', sortable: true, width: '90px', render: function (r) {
				return '<span class="hs-kw"><i class="hs-dot ' + scoreKind(r.score) + '"></i><b class="hs-kw__p">' + num(r.score) + '</b></span>';
			} },
			{ key: 'readability', label: 'خوانایی', align: 'num', width: '90px', render: function (r) { return num(r.readability || 0); } },
			{ key: 'links_in', label: 'لینک', align: 'num', width: '100px', render: function (r) { return num(r.links_in || 0) + ' ↧ / ' + num(r.links_out || 0) + ' ↥'; } },
			{ key: 'issues', label: 'مشکلات', width: '150px', render: function (r) {
				var is = parseIssues(r.issues);
				if (!is.length) { return HS.chip('بدون مشکل', 'ok', { dot: false }); }
				var bad = is.filter(function (x) { return x.severity === 'critical' || x.status === 'fail'; }).length;
				return h('div', { class: 'hs-row', style: { gap: '4px' } }, [
					HS.chip(num(is.length) + ' مورد', bad ? 'bad' : 'warn', { dot: false }),
					bad ? HS.chip(num(bad) + ' بحرانی', 'bad', { dot: false }) : null
				]);
			} },
			{ key: 'scanned_at', label: 'آخرین تحلیل', width: '120px', render: function (r) { return '<span class="hs-muted hs-small">' + esc(r.scanned_at ? HS.ago(r.scanned_at) : '—') + '</span>'; } },
			{ key: '_a', label: '', width: '170px', render: function (r) {
				return '<div class="hs-row-actions">' +
					'<button class="hs-btn hs-btn--sm" data-act="analyze" data-id="' + esc(r.post_id || r.id) + '">تحلیل</button>' +
					'<button class="hs-btn hs-btn--sm hs-btn--ghost" data-act="fix" data-id="' + esc(r.post_id || r.id) + '">اصلاح خودکار</button>' +
					'<a class="hs-btn hs-btn--sm hs-btn--ghost" href="' + esc(editUrl(r)) + '" target="_blank" rel="noopener">ویرایش</a>' +
					'</div>';
			} }
		];
		var total = (body && body.total) || rows.length;
		var foot = h('div', { class: 'hs-pager' }, [
			h('span', { text: num(total) + ' ' + HS.I18N.results }),
			HS.btn(null, { icon: 'arrow', sm: true, kind: 'ghost', title: 'قبلی', run: function () { if (ctx.page > 1) { ctx.page--; ctx.load(); } } }),
			h('span', { text: num(ctx.page) + ' ' + HS.I18N.of + ' ' + num(Math.max(1, Math.ceil(total / ctx.per))) }),
			HS.btn(null, { icon: 'arrow', sm: true, kind: 'ghost', title: 'بعدی', run: function () { if (ctx.page * ctx.per < total) { ctx.page++; ctx.load(); } } })
		]);
		var t = HS.table({ columns: cols, rows: rows, rowKey: 'id', footer: foot, onRow: function (r) { openPost(r.post_id || r.id, ctx); }, sort: ctx.sort, onSort: function (key) {
			ctx.sort = { by: key, dir: (ctx.sort && ctx.sort.by === key && ctx.sort.dir === 'asc') ? 'desc' : 'asc' };
			ctx.load();
		} });
		on(t, 'click', '[data-act]', function (e, el) {
			e.preventDefault();
			e.stopPropagation();
			var id = parseInt(el.dataset.id, 10);
			if (el.dataset.act === 'analyze') { openPost(id, ctx); }
			else { bulkFix(id); }
		});
		return t;
	}

	function typeLabel(t) {
		var map = C.postTypes || {};
		if (map[t]) { return HS.labelOf(map[t], t, t); }
		return ({ post: 'نوشته', page: 'برگه', product: 'محصول', attachment: 'تصویر' })[t] || t || '—';
	}

	HS.view('content', {
		title: 'تحلیل محتوا',
		sub: 'امتیاز سئو و خوانایی برای همه صفحه‌ها، با اصلاح تک‌klikی یا گروهی',
		render: function (host, params) {
			var ctx = { page: parseInt(params.paged, 10) || 1, per: 25, filter: params.filter || 'all', type: params.type || 'all', search: params.search || '', sort: { by: params.orderby || 'score', dir: params.order || 'asc' } };
			var wrap = h('div', { class: 'hs-col' });
			host.appendChild(wrap);

			ctx.load = function () {
				tableHost.innerHTML = '';
				tableHost.appendChild(HS.skeleton(6));
				return HS.get('content/pages', {
					per: ctx.per, paged: ctx.page, filter: ctx.filter, type: ctx.type, search: ctx.search,
					orderby: ctx.sort.by, order: ctx.sort.dir
				}).then(function (res) {
					tableHost.innerHTML = '';
					ctx.body = res;
					tableHost.appendChild(contentTable(tableHost, ctx));
					renderHeader(res);
					if (params.post) { openPost(parseInt(params.post, 10), ctx); }
				});
			};

			var tableHost = h('div', { class: 'hs-card hs-card--flush' });
			var head = HS.card({
				title: 'صفحه‌ها', icon: 'document',
				actions: [
					HS.btn('تحلیل کل سایت', { kind: 'primary', sm: true, icon: 'play', run: startScan }),
					HS.btn(HS.I18N.refresh, { kind: 'ghost', sm: true, icon: 'refresh', run: function () { HS.cacheClear('content'); return ctx.load(); } })
				]
			}, wrap);
			host.insertBefore(head, host.firstChild);

			var bar = h('div', { class: 'hs-row' });
			var search = h('input', { class: 'hs-input', type: 'search', placeholder: 'جست‌وجو در عنوان یا نشانی…', value: ctx.search, style: { maxWidth: '260px' } });
			var deb;
			search.addEventListener('input', function () { clearTimeout(deb); deb = setTimeout(function () { ctx.search = search.value; ctx.page = 1; ctx.load(); }, 400); });
			bar.appendChild(search);
			bar.appendChild(HS.select([{ id: 'all', label: 'همه نوع‌ها' }].concat(HS.entries(C.postTypes).map(function (p) { return { id: p[0], label: HS.labelOf(p[1], p[0]) }; })), ctx.type, function (v) { ctx.type = v; ctx.page = 1; ctx.load(); }));
			bar.appendChild(HS.segmented(CONTENT_FILTERS, ctx.filter, function (v) { ctx.filter = v; ctx.page = 1; ctx.load(); }));
			var prog = h('div', { class: 'hs-row hs-hide', style: { flex: '1 1 180px', minWidth: '150px' } }, [h('span', { class: 'hs-muted hs-small', text: 'در حال تحلیل…' }), HS.progress(0)]);
			bar.appendChild(prog);
			var exportBtn = HS.btn(HS.I18N.export, { kind: 'ghost', sm: true, icon: 'download', href: exportUrl('pages') });
			bar.appendChild(exportBtn);
			wrap.appendChild(bar);
			wrap.appendChild(tableHost);

			function renderHeader(res) {
				var sub = qs('#hs-top-sub');
				if (sub) { sub.textContent = num((res && res.total) || 0) + ' صفحه در ' + (C.site && C.site.name ? C.site.name : 'سایت') + ' تحلیل شده است'; }
			}

			function startScan() {
				prog.classList.remove('hs-hide');
				return HS.post('content/scan', { limit: 0, type: ctx.type === 'all' ? '' : ctx.type }).then(function (res) {
				HS.toast('تحلیل شروع شد — ' + num((res && res.total) || 0) + ' صفحه در صف', 'ok');
				poll();
				}, HS.fail);
			}
			var pollTimer = null;
			function poll() {
				clearTimeout(pollTimer);
				HS.get('content/status', {}, { force: true }).then(function (st) {
					var pctv = (st && st.percent) || 0;
					var bar2 = qs('.hs-progress__bar', prog);
					if (bar2) { bar2.style.width = pctv + '%'; }
					var lbl = qs('span', prog);
					if (lbl) { lbl.textContent = 'تحلیل: ' + HS.pct(pctv) + (st && st.remaining ? (' · باقی‌مانده ' + num(st.remaining)) : ''); }
					if (st && st.running) { pollTimer = setTimeout(poll, 2000); }
					else {
						setTimeout(function () { prog.classList.add('hs-hide'); }, 900);
						HS.cacheClear('content');
						ctx.load();
					}
				}, function () { prog.classList.add('hs-hide'); });
			}
			if (params.scan) { startScan(); }
			HS.get('content/status', {}, { force: true }).then(function (st) { if (st && st.running) { poll(); } });
			return ctx.load();
		}
	});

	function bulkFix(postId) {
		return HS.post('content/fix', { post_id: postId, check: 'auto_all', mode: 'auto' }).then(function (res) {
			HS.toast(res && res.message ? res.message : 'اصلاح‌های خودکار اعمال شد', 'ok');
			HS.cacheClear('content');
		}, function (e) {
			HS.post('content/fix', { post_id: postId, check: 'focus_present', mode: 'ai', payload: {} }).then(function (r2) {
				HS.toast(r2 && r2.message ? r2.message : 'با هوش مصنوعی اصلاح شد', 'ok');
			}, HS.fail);
		});
	}

	function exportUrl(type) {
		var base = (C.site && C.site.home) || '/';
		return base + '?hoosh_export=' + encodeURIComponent(type);
	}
	HS.exportUrl = exportUrl;

	/* ---- post drawer: analysis + fixes + snippet ---- */
	var openPost = function (postId, ctx) {
		HS.get('post/' + postId, {}, { force: true }).then(function (res) {
			var meta = (res && (res.store || res.data || res)) || {};
			var analysis = (res && res.analysis) || {};
			HS.drawer({
				title: (meta.resolved && meta.resolved.title) ? meta.resolved.title : ('نوشته ' + num(postId)),
				body: postPanel(postId, meta, analysis, ctx)
			});
		}, HS.fail);
	};
	HS.openPost = openPost;

	function postPanel(postId, meta, analysis, ctx) {
		var wrap = h('div', { class: 'hs-col' });
		var title = (meta.title !== undefined && meta.title !== '') ? meta.title : ((meta.resolved && meta.resolved.title) || '');
		var desc = (meta.description !== undefined && meta.description !== '') ? meta.description : ((meta.resolved && meta.resolved.description) || '');
		var kw = arr2(meta.keywords);
		var state = { title: title || '', description: desc || '', keywords: kw };

		var scores = h('div', { class: 'hs-row' }, [
			HS.donut(analysis.score !== undefined ? analysis.score : (meta.score || 0), 'سئو'),
			HS.donut(analysis.readability || 0, 'خوانایی'),
			h('div', { class: 'hs-col' }, [
				h('b', { text: num(analysis.words || 0) + ' واژه · ' + num(analysis.reading_time || 0) + ' دقیقه مطالعه' }),
				h('span', { class: 'hs-muted hs-small', text: 'رتبه ' + (analysis.grade || '—') + ' · ' + (analysis.checked_at ? HS.ago(new Date(analysis.checked_at * 1000)) : 'همین حالا') })
			])
		]);
		wrap.appendChild(scores);

		var m = analysis.metrics || {};
		var metricRows = [
			{ label: 'طول عنوان', value: m.title_chars || 0, best: [40, 60] },
			{ label: 'طول توضیحات', value: m.desc_chars || 0, best: [110, 158] },
			{ label: 'چگالی کلمه کلیدی', value: m.density || 0, best: [1, 3] },
			{ label: 'میانگین طول جمله', value: m.avg_sentence || 0, best: [8, 20] },
			{ label: 'لینک داخلی', value: m.internal || 0, best: [2, 40] },
			{ label: 'لینک بیرونی', value: m.external || 0, best: [0, 20] },
			{ label: 'تصویر بدون alt', value: m.images_missing || 0, best: [0, 0] }
		].map(function (r) {
			var over = r.best[1] !== undefined && r.value > r.best[1];
			var under = r.value < r.best[0];
			return { label: r.label, value: r.value, display: num(r.value) + (over ? ' ↑' : under ? ' ↓' : ' ✓'), kind: (over || under) ? 'warn' : 'ok' };
		});
		wrap.appendChild(HS.card({ title: 'سنجه‌ها', icon: 'chart' }, [HS.bars(metricRows, { max: Math.max.apply(null, metricRows.map(function (x) { return x.value; })) })]));

		/* editable fields */
		var form = h('div', { class: 'hs-form hs-form--1' });
		var tIn = HS.input({ key: 'title', type: 'text', placeholder: 'عنوان سئو' }, state.title, function (v) { state.title = v; live(); });
		form.appendChild(HS.fieldWrap({ key: 'title', label: 'عنوان سئو', help: 'با متغیرهایی مثل [[title]] و [[sep]] می‌توانید الگو بسازید.' }, tIn));
		var dIn = HS.input({ key: 'description', type: 'textarea', rows: 3 }, state.description, function (v) { state.description = v; live(); });
		form.appendChild(HS.fieldWrap({ key: 'description', label: 'توضیحات متا' }, dIn));
		var kIn = HS.input({ key: 'keywords', type: 'text', placeholder: 'کلمه کلیدی اصلی، سپس موارد پشتیبان (با کاما)' }, kw.join('، '), function (v) { state.keywords = String(v).split(/[,،\n]/).map(function (x) { return x.trim(); }).filter(Boolean); live(); });
		form.appendChild(HS.fieldWrap({ key: 'keywords', label: 'کلمات کلیدی', help: 'کلمه اول، کلمه اصلی است.' }, kIn));
		wrap.appendChild(HS.card({ title: 'فیلدهای این نوشته', icon: 'pencil' }, [form, h('div', { class: 'hs-row hs-row--end' }, [
			HS.btn('ذخیره', { kind: 'primary', icon: 'check', run: function () { return HS.post('post/' + postId, { title: state.title, description: state.description, keywords: state.keywords.join(', ') }).then(function (r) { HS.toast('ذخیره شد', 'ok'); if (ctx && ctx.load) { ctx.load(); } return r; }, HS.fail); } }),
			HS.btn('باز کردن در ویرایشگر', { icon: 'external', href: editUrl({ post_id: postId }), target: '_blank' })
		])]))

		/* snippet preview */
		var prev = h('div', { class: 'hs-preview' });
		wrap.appendChild(HS.card({ title: 'پیش‌نمایش نتیجه جست‌وجو', icon: 'search-snippet', actions: [h('span', { class: 'hs-count', id: 'hs-counts' })] }, [prev]));
		function snippet() {
			return HS.get('snippet', { post_id: postId, device: 'desktop' }, { force: true }).then(function (r) {
				prev.innerHTML = '';
				['desktop', 'mobile'].forEach(function (dev) {
					var s = r[dev] || {};
					var box = h('div', { class: 'hs-snippet' + (dev === 'mobile' ? ' hs-snippet--mobile' : '') });
					box.appendChild(h('div', { class: 'hs-snippet__u' }, [h('span', { class: 'hs-snippet__fav', text: 'ه' }), h('span', { text: s.host || '' }), s.crumbs && s.crumbs.length ? h('span', { class: 'hs-muted', text: '› ' + s.crumbs.join(' › ') }) : null]));
					box.appendChild(h('div', { class: 'hs-snippet__t', text: s.title || '(بدون عنوان)' }));
					box.appendChild(h('p', { class: 'hs-snippet__d', text: s.description || '' }));
					if (s.kw && s.kw.length) { box.appendChild(h('div', { class: 'hs-snippet__k' }, s.kw.map(function (k) { return h('span', { text: k }); }))); }
					var lab = h('div', { class: 'hs-col' }, [h('span', { class: 'hs-muted hs-small', text: dev === 'mobile' ? 'موبایل' : 'دسکتاپ' }), box]);
					prev.appendChild(lab);
				});
				prev.style.display = 'grid';
				prev.style.gap = '10px';
			});
		}
		var liveT;
		function live() {
			clearTimeout(liveT);
			liveT = setTimeout(function () {
				HS.post('analyze', { post_id: postId, title: state.title, description: state.description, keywords: state.keywords }).then(function (r) {
					var donuts = qsa('.hs-donut', wrap);
					if (donuts[0]) { donuts[0].replaceWith(HS.donut(r.score, 'سئو')); }
					if (donuts[1]) { donuts[1].replaceWith(HS.donut(r.readability, 'خوانایی')); }
					paintChecks(checksHost, r, postId);
					snippet();
				});
				var cnt = qs('#hs-counts');
				if (cnt) {
					var tl = (state.title || '').length, dl = (state.description || '').length;
					var tmax = HS.sget('content.title_max', 60), dmax = HS.sget('content.desc_max', 158);
				cnt.innerHTML = '<span>' + num(tl) + '/' + num(tmax) + '</span><span class="hs-meter"><i style="width:' + Math.min(100, (tl / tmax) * 100) + '%"></i></span><span>' + num(dl) + '/' + num(dmax) + '</span>';
				}
			}, 500);
		}

		/* checks */
		var checksHost = h('div', { class: 'hs-card hs-card--flush' });
		paintChecks(checksHost, analysis, postId);
		wrap.appendChild(HS.card({ title: 'بررسی‌ها و پیشنهادها', icon: 'check-list', actions: [HS.btn('اصلاح خودکار همه', { sm: true, kind: 'primary', run: function () { return bulkFix(postId).then(function () { return HS.get('post/' + postId, {}, { force: true }).then(function (r) { paintChecks(checksHost, r.analysis || {}); }, function () {}); }); } })] }, [checksHost]));

		snippet();
		return wrap;
	}

	function paintChecks(host, analysis, postId) {
		host.innerHTML = '';
		var issues = (analysis && analysis.issues) || [];
		var byGroup = {};
		issues.forEach(function (it) {
			var g = it.group || 'عمومی';
			(byGroup[g] = byGroup[g] || []).push(it);
		});
		Object.keys(byGroup).forEach(function (g) {
			host.appendChild(h('div', { class: 'hs-group__h', text: g }));
			byGroup[g].forEach(function (it) {
				var row = h('div', { class: 'hs-checkrow' });
				var st = it.status || 'fail';
				var ic = h('span', { class: 'hs-checkrow__i is-' + (st === 'pass' ? 'ok' : st === 'warn' ? 'warn' : 'bad'), text: st === 'pass' ? '✓' : (st === 'warn' ? '!' : '×') });
				var main = h('div', { class: 'hs-checkrow__m' }, [
					h('b', { text: it.label || it.id }),
					h('p', { text: (it.why || '') + (it.how ? (' — ' + it.how) : '') })
				]);
				var acts = h('div', { class: 'hs-checkrow__a' });
				if (st !== 'pass' && it.fix) {
					acts.appendChild(HS.btn(it.fix === 'ai' ? 'اصلاح با هوش مصنوعی' : 'اصلاح', {
						sm: true, kind: 'primary', icon: 'wand', run: function (b) {
							return HS.post('content/fix', { post_id: postId || it.post_id || 0, check: it.id, mode: it.fix === 'ai' ? 'ai' : 'auto', payload: {} }).then(function (r) {
								b && b.classList.remove('is-busy');
								HS.toast(r && r.message ? r.message : (r && r.applied ? 'اعمال شد' : 'قابل اعمال نبود'), (r && (r.applied || r.ok)) ? 'ok' : 'warn');
								HS.cacheClear('post/');
							}, function (e) { HS.fail(e); });
						}
					}));
				}
				row.appendChild(ic); row.appendChild(main); row.appendChild(acts);
				host.appendChild(row);
			});
		});
		if (!issues.length) { host.appendChild(HS.empty('بدون یافته', 'این صفحه همه بررسی‌ها را پاس کرده است.', true)); }
	}
	HS.paintChecks = paintChecks;
	HS.bulkFix = bulkFix;

	function arr2(v) {
		if (!v) { return []; }
		if (Array.isArray(v)) { return v; }
		return String(v).split(/[,،\n]/).map(function (x) { return x.trim(); }).filter(Boolean);
	}
	HS.kwList = arr2;

	/* =================== snippet studio =================== */
	HS.view('snippet', {
		title: 'استودیو اسنیپت',
		sub: 'عنوان و توضیحات را بنویسید، نتیجه را در گوگل ببینید',
		render: function (host, params) {
			var picker = h('div', { class: 'hs-col' });
			var input = h('input', { class: 'hs-input', type: 'search', placeholder: 'عنوان نوشته را بنویسید…', autocomplete: 'off' });
			var results = h('div', { class: 'hs-list', style: { maxHeight: '340px', overflow: 'auto' } });
			picker.appendChild(HS.card({ title: 'یک صفحه را انتخاب کنید', icon: 'search-snippet' }, [input, results]));
			host.appendChild(picker);
			var target = h('div');
			host.appendChild(target);
			var t;
			function search() {
				clearTimeout(t);
				t = setTimeout(function () {
					var q = input.value.trim();
					if (q.length < 2) { results.innerHTML = ''; return; }
					HS.get('content/pages', { search: q, per: 12, filter: 'all', orderby: 'title', order: 'asc' }).then(function (res) {
						results.innerHTML = '';
						var rows = (res && res.rows) || [];
						if (!rows.length) { results.appendChild(HS.empty(HS.I18N.notFound, null, true)); return; }
						rows.forEach(function (r) {
							results.appendChild(h('button', {
								class: 'hs-list__item', type: 'button', style: { width: '100%', border: '0', background: 'none', font: 'inherit', color: 'inherit', cursor: 'pointer', textAlign: 'start' },
								onclick: function () { open(r.post_id || r.id); }
							}, [
								h('span', { class: 'hs-dot ' + scoreKind(r.score) }),
								h('div', { class: 'hs-list__main' }, [
									h('div', { class: 'hs-list__title', text: r.title || '(بی‌عنوان)' }),
									h('div', { class: 'hs-list__meta', text: (r.url || '') + ' · امتیاز ' + num(r.score) })
								]),
								HS.icon('arrow')
							]));
						});
					}, HS.fail);
				}, 320);
			}
			input.addEventListener('input', search);
			function open(id) {
				target.innerHTML = '';
				target.appendChild(HS.skeleton(4));
				HS.get('post/' + id, {}, { force: true }).then(function (res) {
					target.innerHTML = '';
					var meta = (res && (res.store || res)) || {};
					target.appendChild(HS.card({ title: 'ویرایش اسنیپت', icon: 'pencil', sub: 'تغییرات همین‌جا ذخیره می‌شود' }, [postPanel(id, meta, (res && res.analysis) || {}, { load: function () { HS.cacheClear('content'); } })]));
					target.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}, function (e) { target.innerHTML = ''; HS.fail(e); });
			}
			if (params.post) { open(parseInt(params.post, 10)); }
			else { search(); }
			return Promise.resolve();
		}
	});
})(HS);

/* =================== keywords, tracking, links =================== */
(function (HS) {
	'use strict';
	var h = HS.h, qs = HS.qs, qsa = HS.qsa, on = HS.on, num = HS.num, esc = HS.esc, C = HS.C;

	/* ---------- keyword research ---------- */
	HS.view('keywords', {
		title: 'تحقیق کلمات کلیدی',
		sub: 'سید فارسی بدهید، از ۱۳ منبع ایرانی و جهانی پیشنهاد بگیرید',
		render: function (host, params) {
			var rs = C.keywordSources || {};
			var srcEntries = HS.entries(rs).map(function (p) { return { id: p[0], label: HS.labelOf(p[1], p[0]), def: p[1] }; });
			if (!srcEntries.length) { srcEntries = [{ id: 'google', label: 'Google Suggest' }]; }
			var pickedSources = srcEntries.slice(0, 5).map(function (s) { return s.id; });

			var v = {
				seed: params.seed || '', lang: 'fa', depth: 1, delay: 2,
				prefixes: (HS.sget('keywords.prefixes') || []).join('\n'),
				suffixes: (HS.sget('keywords.suffixes') || []).join('\n'),
				max: HS.sget('keywords.max_results', 120), alphabet: true, dedupe: true, rotate: true
			};
			var session = 0, timer = null;

			var form = h('div', { class: 'hs-form' });
			form.appendChild(HS.fieldWrap({ key: 'seed', label: 'کلمه یا عبارت اولیه' }, HS.input({ key: 'seed' }, v.seed, function (x) { v.seed = x; })));
			form.appendChild(HS.fieldWrap({ key: 'lang', label: 'زبان' }, HS.select([{ id: 'fa', label: 'فارسی' }, { id: 'en', label: 'انگلیسی' }, { id: 'ar', label: 'عربی' }], v.lang, function (x) { v.lang = x; })));
			form.appendChild(HS.fieldWrap({ key: 'depth', label: 'عمق جست‌وجو', help: 'سطح ۲ و ۳، پیشنهادها را با پیشوند/پسوند ترکیب می‌کند.' }, HS.segmented([{ value: 1, label: '۱' }, { value: 2, label: '۲' }, { value: 3, label: '۳' }], v.depth, function (x) { v.depth = parseInt(x, 10); })));
			form.appendChild(HS.fieldWrap({ key: 'max', label: 'حداکثر نتیجه' }, HS.input({ key: 'max', type: 'number', min: 10, max: 1000 }, v.max, function (x) { v.max = x; })));
			form.appendChild(HS.fieldWrap({ key: 'prefixes', label: 'پیشوندها (هر خط یکی)', full: true }, HS.input({ key: 'prefixes', type: 'textarea', rows: 3 }, v.prefixes, function (x) { v.prefixes = x; })));
			form.appendChild(HS.fieldWrap({ key: 'suffixes', label: 'پسوندها (هر خط یکی)', full: true }, HS.input({ key: 'suffixes', type: 'textarea', rows: 3 }, v.suffixes, function (x) { v.suffixes = x; })));
			form.appendChild(HS.fieldWrap({ key: 'delay', label: 'فاصله بین درخواست‌ها (ثانیه)' }, HS.segmented([{ value: 1, label: '۱' }, { value: 2, label: '۲' }, { value: 3, label: '۳' }, { value: 5, label: '۵' }], v.delay, function (x) { v.delay = parseInt(x, 10); })));
			form.appendChild(HS.fieldWrap({ key: 'flags', label: 'گزینه‌ها' }, h('div', { class: 'hs-row' }, [
				switchRow('alphabet', 'الفبای فارسی و لاتین', v.alphabet, function (x) { v.alphabet = x; }),
				switchRow('dedupe', 'حذف تکراری‌ها', v.dedupe, function (x) { v.dedupe = x; }),
				switchRow('rotate', 'چرخش User-Agent', v.rotate, function (x) { v.rotate = x; })
			])));

			var chips = h('div', { class: 'hs-row', style: { gap: '6px' } });
			srcEntries.forEach(function (s) {
				var kind = pickedSources.indexOf(s.id) > -1 ? 'a' : null;
				var chip = HS.chip(s.label + (s.def && s.def.type ? ' · ' + s.def.type : ''), kind, { dot: false, title: (s.def && s.def.note) || '' });
				chip.style.cursor = 'pointer';
				chip.addEventListener('click', function () {
					var i = pickedSources.indexOf(s.id);
					if (i > -1) { pickedSources.splice(i, 1); } else { pickedSources.push(s.id); }
					renderChips();
				});
				s.chip = chip;
				chips.appendChild(chip);
			});
			function renderChips() {
				qsa('.hs-chip', chips).forEach(function (el, i) {
					var id = srcEntries[i] && srcEntries[i].id;
					el.className = 'hs-chip' + (pickedSources.indexOf(id) > -1 ? ' hs-chip--a' : '');
				});
			}
			function switchRow(key, label, val, cb) {
				var wrap = h('label', { class: 'hs-row', style: { gap: '6px', cursor: 'pointer' } });
				wrap.appendChild(HS.switchEl(val, function (x) { cb(x); }));
				wrap.appendChild(h('span', { class: 'hs-small', text: label }));
				return wrap;
			}

			var runRow = h('div', { class: 'hs-row hs-row--between' }, [
				chips,
				h('div', { class: 'hs-row' }, [
					HS.btn('شروع', { kind: 'primary', icon: 'play', run: start }),
					HS.btn('توقف', { icon: 'stop', run: stop }),
					HS.btn(HS.I18N.export, { kind: 'ghost', icon: 'download', run: exportCsv })
				])
			]);
			var prog = h('div', { class: 'hs-col' }, [HS.progress(0), h('span', { class: 'hs-muted hs-small', text: 'هنوز اجرا نشده است.' })]);
			host.appendChild(HS.card({ title: 'منابع و پارامترها', icon: 'magnifier' }, [form, runRow, prog]));

			var resultsHost = h('div');
			host.appendChild(HS.card({ title: 'نتیجه‌ها', icon: 'list', actions: [h('span', { class: 'hs-chip', id: 'hs-kw-count', text: '۰ کلمه' })] }, [resultsHost]));

			var savedHost = h('div');
			host.appendChild(HS.card({ title: 'کلمات ذخیره‌شده', icon: 'star', actions: [HS.btn('افزودن دستی', { sm: true, run: function () { addKeyword(''); } })] }, [savedHost]));

			var rows = [];

			function start() {
				if (!v.seed.trim()) { HS.toast('اول یک کلمه یا عبارت بنویسید.', 'warn'); return; }
				prog.replaceChildren(HS.progress(0), h('span', { class: 'hs-muted hs-small', text: 'در حال دریافت پیشنهادها…' }));
				return HS.post('research/start', {
					seed: v.seed.trim(), lang: v.lang, depth: v.depth, delay: v.delay, max: v.max,
					sources: pickedSources, prefixes: lines(v.prefixes), suffixes: lines(v.suffixes),
					alphabet: v.alphabet, dedupe: v.dedupe, rotate_ua: v.rotate
				}).then(function (res) {
					session = (res && (res.session || res.id)) || 0;
					poll();
					HS.toast('اجرای تحقیق شروع شد', 'ok');
				}, HS.fail);
			}
			function stop() {
				if (!session) { return; }
				clearTimeout(timer);
				return HS.post('research/stop', { session: session }).then(function () { HS.toast('متوقف شد', 'info'); }, HS.fail);
			}
			function poll() {
				clearTimeout(timer);
				if (!session) { return; }
				HS.get('research/status', { session: session }, { force: true }).then(function (st) {
					st = st || {};
					rows = st.rows || st.items || rows;
					var done = parseInt(st.done || st.found || 0, 10);
					var total = parseInt(st.total || st.target || 0, 10) || 0;
					var pctv = st.percent !== undefined ? st.percent : (total ? Math.round((done / total) * 100) : (rows.length ? 100 : 0));
					prog.replaceChildren(
						HS.progress(pctv),
						h('span', { class: 'hs-muted hs-small', text: (num(done)) + ' ' + (st.running ? 'در حال دریافت…' : 'کلمه پیدا شد') + (st.source_label ? (' · ' + st.source_label) : '') })
					);
					paint();
					if (st.running || st.status === 'running') { timer = setTimeout(poll, 1800); }
					else { HS.cacheClear('keywords'); loadSaved(); }
				}, function (e) { HS.fail(e); });
			}
			function paint() {
				var cnt = qs('#hs-kw-count');
				if (cnt) { cnt.textContent = num(rows.length) + ' کلمه'; }
				if (!rows.length) {
					resultsHost.innerHTML = '';
					resultsHost.appendChild(HS.empty('هنوز نتیجه‌ای نیست', 'یک سید مثل «کفش مردانه» بنویسید و شروع را بزنید.', true));
					return;
				}
				var cols = [
					{ key: 'phrase', label: 'کلمه کلیدی', render: function (r) { return '<b>' + esc(r.phrase || r.keyword || r) + '</b>'; } },
					{ key: 'volume', label: 'حجم ماهانه', align: 'num', width: '110px', render: function (r) { return num(r.volume || 0); } },
					{ key: 'difficulty', label: 'سختی', align: 'num', width: '90px', render: function (r) { var d = parseInt(r.difficulty || 0, 10); return '<span class="hs-chip ' + (d < 30 ? 'hs-chip--ok' : d < 60 ? 'hs-chip--warn' : 'hs-chip--bad') + '">' + num(d) + '</span>'; } },
					{ key: 'intent', label: 'قصد', width: '110px', render: function (r) { return esc(intentLabel(r.intent)); } },
					{ key: 'cpc', label: 'CPC', align: 'num', width: '90px', render: function (r) { return r.cpc ? '$' + num(r.cpc, 2) : '—'; } },
					{ key: 'source', label: 'منبع', width: '130px', render: function (r) { return '<span class="hs-muted hs-small">' + esc(r.source || r.origin || '—') + '</span>'; } },
					{ key: '_a', label: '', width: '210px', render: function (r) {
						var ph = r.phrase || r.keyword || '';
						return '<div class="hs-row-actions">' +
							'<button class="hs-btn hs-btn--sm" data-a="track" data-p="' + esc(ph) + '">پیگیری</button>' +
							'<button class="hs-btn hs-btn--sm hs-btn--ghost" data-a="copy" data-p="' + esc(ph) + '">کپی</button>' +
							'<button class="hs-btn hs-btn--sm hs-btn--ghost" data-a="tags" data-p="' + esc(ph) + '">به عنوان کلمه کلیدی نوشته</button>' +
							'</div>';
					} }
				];
				resultsHost.innerHTML = '';
				var t = HS.table({ columns: cols, rows: rows.slice(0, 400) });
				on(t, 'click', '[data-a]', function (e, el) {
					e.preventDefault();
					var p = el.dataset.p;
					if (el.dataset.a === 'copy') { copy(p); }
					else if (el.dataset.a === 'track') { addKeyword(p); }
					else { HS.toast('برای افزودن به یک نوشته، در استودیو محتوا کلمات کلیدی آن را ذخیره کنید.', 'info'); }
				});
				resultsHost.appendChild(t);
			}
			function exportCsv() {
				var head = 'کلمه کلیدی,حجم,سختی,قصد,CPC,منبع';
				var lines2 = rows.map(function (r) {
					return [q(r.phrase || r.keyword || ''), r.volume || 0, r.difficulty || 0, r.intent || '', r.cpc || 0, r.source || ''].join(',');
				});
				download('HooshSEO-keywords.csv', '\ufeff' + [head].concat(lines2).join('\n'));
			}
			function lines(s) { return String(s || '').split(/\r?\n/).map(function (x) { return x.trim(); }).filter(Boolean); }
			function q(s2) { return '"' + String(s2).replace(/"/g, '""') + '"'; }
			function download(name, text) {
				var b = new Blob([text], { type: 'text/csv;charset=utf-8' });
				var a2 = h('a', { href: URL.createObjectURL(b), download: name });
				document.body.appendChild(a2); a2.click(); a2.remove();
				HS.toast('فایل CSV ساخته شد', 'ok');
			}
			HS.hsDownload = download;
			function copy(text) {
				if (navigator.clipboard) { navigator.clipboard.writeText(text).then(function () { HS.toast('کپی شد', 'ok', { ms: 1200 }); }); return; }
				var ta = h('textarea', { text: text });
				document.body.appendChild(ta); ta.select();
				try { document.execCommand('copy'); HS.toast('کپی شد', 'ok', { ms: 1200 }); } catch (e) {}
				ta.remove();
			}
			HS.copyText = copy;

			function addKeyword(phrase, extra) {
				extra = extra || {};
				var row = rows.filter(function (r) { return (r.phrase || r.keyword) === phrase; })[0] || {};
				HS.post('keywords', Object.assign({
					phrase: phrase || '', volume: row.volume || 0, difficulty: row.difficulty || 0,
					intent: row.intent || '', cpc: row.cpc || 0, source: row.source || 'research', state: 'tracking', kind: 'supporting'
				}, extra)).then(function (r) {
					HS.toast((r && r.message) || 'به فهرست پیگیری اضافه شد', 'ok');
					HS.cacheClear('keywords'); loadSaved();
				}, HS.fail);
			}
			HS.addKeyword = addKeyword;

			function loadSaved() {
				savedHost.innerHTML = '';
				savedHost.appendChild(HS.skeleton(3));
				HS.get('keywords', { per: 40, state: 'tracking', orderby: 'position', order: 'asc' }).then(function (res) {
					var list = (res && res.rows) || [];
					savedHost.innerHTML = '';
					if (!list.length) { savedHost.appendChild(HS.empty('کلمه‌ای در حال پیگیری نیست', 'از جدول بالا «پیگیری» را بزنید یا در ویرایشگر نوشته، کلمه اصلی را ثبت کنید.', true)); return; }
					var cols = [
						{ key: 'phrase', label: 'کلمه', render: function (r) { return '<b>' + esc(r.phrase) + '</b>' + (r.post_id ? ' <span class="hs-tag">' + esc(typeLabel2(r)) + '</span>' : ''); } },
						{ key: 'volume', label: 'حجم', align: 'num', width: '90px', render: function (r) { return num(r.volume || 0); } },
						{ key: 'position', label: 'رتبه', align: 'num', width: '90px', render: function (r) { return num(r.position || 0, 1); } },
						{ key: 'prev_position', label: 'تغییر', align: 'num', width: '90px', render: function (r) {
							var d = (parseFloat(r.prev_position) || 0) - (parseFloat(r.position) || 0);
							if (!d) { return '<span class="hs-muted">—</span>'; }
							return '<span class="' + (d > 0 ? 'hs-chip hs-chip--ok' : 'hs-chip hs-chip--bad') + '">' + (d > 0 ? '+' : '') + num(d, 1) + '</span>';
						} },
						{ key: 'clicks', label: 'کلیک', align: 'num', width: '90px', render: function (r) { return num(r.clicks || 0); } },
						{ key: 'kind', label: 'نوع', width: '100px', render: function (r) { return esc(r.kind === 'focus' ? 'اصلی' : 'پشتیبان'); } },
						{ key: '_a', label: '', width: '170px', render: function (r) {
							return '<div class="hs-row-actions">' +
								'<button class="hs-btn hs-btn--sm hs-btn--ghost" data-k="un" data-id="' + esc(r.id) + '">توقف پیگیری</button>' +
								'<button class="hs-btn hs-btn--sm hs-btn--danger" data-k="del" data-id="' + esc(r.id) + '" title="حذف">×</button></div>';
						} }
					];
					var t = HS.table({ columns: cols, rows: list, picky: true, rowKey: 'id', onPick: function (ids) { pickHost.replaceChildren(
						HS.btn('بررسی رتبه (' + num(ids.length) + ')', { sm: true, run: function () { return HS.post('rank/check', { ids: ids, limit: ids.length }).then(function (r) { HS.toast('بررسی شد', 'ok'); HS.cacheClear('rank'); loadSaved(); return r; }, HS.fail); } }),
						HS.btn('حذف (' + num(ids.length) + ')', { sm: true, kind: 'danger', run: function () { return HS.post('keywords/delete', { ids: ids }).then(function () { HS.cacheClear('keywords'); loadSaved(); }, HS.fail); } })
					); } });
					var pickHost = h('div', { class: 'hs-row' });
					on(t, 'click', '[data-k]', function (e, el) {
						e.preventDefault();
						var id = parseInt(el.dataset.id, 10);
						if (el.dataset.k === 'del') { HS.post('keywords/delete', { ids: [id] }).then(function () { HS.cacheClear('keywords'); loadSaved(); }, HS.fail); }
						else { HS.post('keywords', { id: id, state: 'saved' }).then(function () { HS.cacheClear('keywords'); loadSaved(); }, HS.fail); }
					});
					savedHost.appendChild(t);
					savedHost.appendChild(pickHost);
				}, function (e) { savedHost.innerHTML = ''; savedHost.appendChild(HS.empty('درایه کلمات در دسترس نیست', e && e.message)); });
			}
			function typeLabel2(r) { return r.post_type ? r.post_type : 'نوشته'; }
			function intentLabel(i) {
				return ({ informational: 'اطلاعاتی', commercial: 'تجاری', transactional: 'تراکنشی', navigational: 'پیمایشی', buy: 'خرید', info: 'اطلاعاتی' })[i] || i || '—';
			}
			loadSaved();
			paint();
			return Promise.resolve();
		}
	});

	/* ---------- rank tracking ---------- */
	HS.view('tracking', {
		title: 'ردیاب رتبه',
		sub: 'جایگاه کلمات کلیدی در نتایج جست‌وجو، با مقایسه دوره‌ها',
		render: function (host, params) {
			var days = parseInt(params.days, 10) || 28;
			host.appendChild(HS.skeleton(5));
			return HS.get('rank/series', { days: days, limit: 100 }).then(function (res) {
				host.innerHTML = '';
				res = res || {};
				var s = res.summary || {};
				var series = res.series || {};
				var kw = (res.keywords && res.keywords.rows) || res.keywords || [];
				var bar = h('div', { class: 'hs-row hs-row--between' });
				bar.appendChild(HS.segmented([{ value: 7, label: '۷ روز' }, { value: 28, label: '۲۸ روز' }, { value: 90, label: '۹۰ روز' }, { value: 365, label: 'یک سال' }], days, function (x) { HS.go('tracking', { days: x }); }));
				bar.appendChild(h('div', { class: 'hs-row' }, [
					HS.btn('بررسی رتبه‌ها حالا', { kind: 'primary', icon: 'refresh', run: function () {
						return HS.post('rank/check', { ids: [], limit: 40 }).then(function (r) {
							HS.toast(r && r.message ? r.message : 'بررسی انجام شد', 'ok');
							HS.cacheClear('rank'); HS.renderRoute();
						}, HS.fail);
					} }),
					HS.btn(HS.I18N.export, { kind: 'ghost', icon: 'download', href: HS.exportUrl('positions') })
				]));
				host.appendChild(HS.card({ title: 'نمای کلی', icon: 'trend', sub: s.provider ? ('منبع: ' + s.provider) : '' }, [bar]));

				host.appendChild(HS.kpis([
					{ label: 'کلمه در رصد', value: num(s.tracking || 0), hint: num(s.covered || 0) + ' پوشش‌داده‌شده' },
					{ label: 'سه رتبه اول', value: num(s.top3 || 0) },
					{ label: 'ده رتبه اول', value: num(s.top10 || 0) },
					{ label: 'میانگین رتبه', value: num(s.average || 0, 1) },
					{ label: 'رشد', value: num(s.improved || 0), delta: s.improved },
					{ label: 'افت', value: num(s.lost || 0), delta: -(Math.abs(parseFloat(s.lost) || 0)) }
				]));

				var pts = (series.rows || []).map(function (r) { return r.p !== undefined ? r.p : (r.position !== undefined ? r.position : r[1]); });
				var labels = (series.labels || series.rows || []).map(function (r) { return typeof r === 'object' ? (r.d || r.date || '') : r; });
				if (pts.length) {
					host.appendChild(HS.card({ title: 'روند میانگین رتبه', icon: 'chart', sub: 'رتبه کوچک‌تر بهتر است' }, [
						HS.lineChart([{ label: 'میانگین رتبه', values: pts, labels: labels }], { height: 210, invert: true, min: 0, max: Math.max(10, Math.ceil(Math.max.apply(null, pts.map(Number)))) })
					]));
				}
				var cols = [
					{ key: 'phrase', label: 'کلمه کلیدی', render: function (r) { return '<b>' + esc(r.phrase || r.keyword || '') + '</b><div class="hs-muted hs-small">' + esc(r.url || '') + '</div>'; } },
					{ key: 'position', label: 'رتبه', align: 'num', width: '90px', render: function (r) { return '<b>' + num(r.position || 0, 1) + '</b>'; } },
					{ key: 'delta', label: 'تغییر ۷ روزه', align: 'num', width: '110px', render: function (r) {
						var d = parseFloat(r.delta !== undefined ? r.delta : ((parseFloat(r.prev_position) || 0) - (parseFloat(r.position) || 0)));
						if (!d) { return '<span class="hs-muted">—</span>'; }
						return '<span class="hs-chip ' + (d > 0 ? 'hs-chip--ok' : 'hs-chip--bad') + '">' + (d > 0 ? '▲ ' : '▼ ') + num(Math.abs(d), 1) + '</span>';
					} },
					{ key: 'clicks', label: 'کلیک', align: 'num', width: '90px', render: function (r) { return num(r.clicks || 0); } },
					{ key: 'impressions', label: 'بازدید', align: 'num', width: '100px', render: function (r) { return num(r.impressions || 0); } },
					{ key: 'volume', label: 'حجم', align: 'num', width: '90px', render: function (r) { return num(r.volume || 0); } },
					{ key: 'series', label: 'روند', width: '130px', render: function (r) { return spark((r.series || []).map(Number)); } }
				];
				host.appendChild(HS.card({ title: 'کلمات کلیدی', icon: 'star', flush: true }, [HS.table({ columns: cols, rows: kw, emptyTitle: 'هنوز رتبه‌ای ثبت نشده', emptyHint: 'دکمه «بررسی رتبه‌ها حالا» را بزنید یا از تحقیق کلمات کلیدی، کلمه‌ها را به پیگیری اضافه کنید.' })]));
				if (!s.provider) {
					host.appendChild(HS.note('warn', 'برای سنجش رتبه، یک سرویس SERP (Serper / SERP-API / DataForSEO) را در تنظیمات آنالیتیکس وصل کنید. تا آن زمان، رتبه‌ها از Search Console خوانده می‌شود.'));
				}
			});
		}
	});
	function spark(values) {
		if (!values || values.length < 2) { return '<span class="hs-muted hs-small">—</span>'; }
		var max = Math.max.apply(null, values), min = Math.min.apply(null, values);
		var w = 110, hh = 26;
		var pts = values.map(function (v, i) {
			var x = (i * w) / Math.max(1, values.length - 1);
			var y = hh - ((v - min) / Math.max(1, max - min)) * hh;
			return x.toFixed(1) + ',' + y.toFixed(1);
		}).join(' ');
		var up = values[values.length - 1] <= values[0];
		return '<svg viewBox="0 0 ' + w + ' ' + hh + '" width="' + w + '" height="' + hh + '" aria-hidden="true"><polyline points="' + pts + '" fill="none" stroke="' + (up ? 'var(--ok)' : 'var(--bad)') + '" stroke-width="1.6" stroke-linejoin="round"/></svg>';
	}

	/* ---------- internal links ---------- */
	HS.view('links', {
		title: 'لینک داخلی',
		sub: 'قوانین لینک‌سازی خودکار، پیوند‌های پیشنهادی و پایش لینک‌های شکسته',
		render: function (host, params) {
			var tab = params.tab || 'rules';
			var body = h('div');
			var statsHost = h('div');
			var tabsEl = HS.tabs([
				{ id: 'rules', label: 'قوانین', icon: 'link' },
				{ id: 'suggest', label: 'پیشنهادها', icon: 'wand' },
				{ id: 'clicks', label: 'کلیک‌ها', icon: 'chart' },
				{ id: 'broken', label: 'لینک‌های شکسته', icon: 'warning' }
			], tab, function (id) { HS.go('links', { tab: id }); });
			host.appendChild(HS.card({
				title: 'قوانین لینک داخلی', icon: 'link',
				actions: [
					HS.btn('افزودن قاعده', { kind: 'primary', sm: true, icon: 'plus', run: function () { editRule(null); } }),
					HS.btn('اسکن لینک‌های شکسته', { sm: true, icon: 'stethoscope', run: function () {
						return HS.post('links/scan', { budget: 60 }).then(function (r) {
							HS.toast(r && r.message ? r.message : 'اسکن در صف قرار گرفت', 'ok');
							HS.cacheClear('links'); load(tab);
						}, HS.fail);
					} })
				]
			}, [tabsEl, body]));
			host.insertBefore(HS.card({ title: 'آمار', icon: 'chart' }, [statsHost]), body.parentNode.nextSibling);

			HS.get('links/stats').then(function (st) {
				st = st || {};
				statsHost.replaceChildren(HS.kpis([
					{ label: 'قاعده', value: num(st.rules || 0) },
					{ label: 'فعال', value: num(st.active || 0) },
					{ label: 'کلیک', value: num(st.clicks || 0) },
					{ label: 'امروز', value: num(st.today || 0) },
					{ label: 'شکسته', value: num(st.broken || 0) },
					{ label: 'نامعتبر (rate limit)', value: num(st.rejected || 0) }
				]));
			}, function () { statsHost.replaceChildren(h('span', { class: 'hs-muted hs-small', text: 'آمار در دسترس نیست' })); });

			function load(which) {
				body.innerHTML = '';
				body.appendChild(HS.skeleton(4));
				if (which === 'rules') { return loadRules(); }
				if (which === 'suggest') { return loadSuggest(); }
				if (which === 'clicks') { return loadClicks(); }
				return loadBroken();
			}

			function loadRules() {
				return HS.get('links/rules', { per: 60, status: '', orderby: 'clicks' }).then(function (res) {
					var rows = (res && res.rows) || [];
					var cols = [
						{ key: 'keyword', label: 'کلمه/عبارت', render: function (r) { return '<b>' + esc(r.keyword) + '</b>'; } },
						{ key: 'url', label: 'مقصد', render: function (r) {
							return '<div class="hs-col"><a href="' + esc(r.url) + '" target="_blank" rel="noopener">' + esc(r.target_title || r.url) + '</a><span class="hs-muted hs-small">' + esc(r.url) + '</span></div>';
						} },
						{ key: 'max_links', label: 'سقف', align: 'num', width: '70px', render: function (r) { return num(r.max_links || 0); } },
						{ key: 'clicks', label: 'کلیک', align: 'num', width: '80px', render: function (r) { return num(r.clicks || 0); } },
						{ key: 'flags', label: 'گزینه‌ها', width: '200px', render: function (r) {
							var f = [];
							if (+r.direct) { f.push('مستقیم'); } else { f.push('واسط'); }
							if (+r.nofollow) { f.push('nofollow'); }
							if (+r.new_tab) { f.push('تب جدید'); }
							if (+r.bold) { f.push('پررنگ'); }
							if (+r.case_sensitive) { f.push('حساس به حروف'); }
							return f.map(function (x) { return '<span class="hs-tag">' + esc(x) + '</span>'; }).join(' ');
						} },
						{ key: 'status', label: 'وضعیت', width: '90px', render: function (r) { return r.status === 'active' ? HS.chip('فعال', 'ok', { dot: false }).outerHTML : HS.chip('غیرفعال', null, { dot: false }).outerHTML; } },
						{ key: '_a', label: '', width: '150px', render: function (r) {
							return '<div class="hs-row-actions"><button class="hs-btn hs-btn--sm" data-a="edit" data-id="' + esc(r.id) + '">ویرایش</button><button class="hs-btn hs-btn--sm hs-btn--danger" data-a="del" data-id="' + esc(r.id) + '">×</button></div>';
						} }
					];
					body.innerHTML = '';
					var t = HS.table({ columns: cols, rows: rows, picky: true, rowKey: 'id', emptyTitle: 'هنوز قاعده‌ای ندارید', emptyHint: 'یک کلمه کلیدی و مقصد را ثبت کنید تا در همه نوشته‌ها به همان صفحه لینک داده شود.', onRow: editRule, onPick: function (ids) {
						pick.replaceChildren(HS.btn('غیرفعال کردن (' + num(ids.length) + ')', { sm: true, run: function () { return Promise.all(ids.map(function (id) { return HS.post('links/rules', { id: id, status: 'inactive' }); })).then(function () { load('rules'); }); } }), HS.btn('حذف (' + num(ids.length) + ')', { sm: true, kind: 'danger', run: function () { return HS.post('links/rule/delete', { ids: ids }).then(function () { load('rules'); }, HS.fail); } }));
					} });
					var pick = h('div', { class: 'hs-row', style: { padding: '10px 16px' } });
					body.appendChild(t);
					body.appendChild(pick);
					on(t, 'click', '[data-a]', function (e, el) {
						e.preventDefault(); e.stopPropagation();
						var id = parseInt(el.dataset.id, 10);
						if (el.dataset.a === 'del') {
							HS.confirmBox('حذف قاعده', 'این قاعده حذف شود؟ لینک‌های Already درج‌شده در محتوا باقی می‌مانند.', function () {
								return HS.post('links/rule/delete', { ids: [id] }).then(function () { HS.cacheClear('links'); load('rules'); }, HS.fail);
							}, 'danger');
						} else {
							HS.post('links/rules', { id: id, status: 'active' }).then(function () { load('rules'); }, HS.fail);
						}
					});
				}, function (e) { body.innerHTML = ''; body.appendChild(HS.note('bad', 'قوانین بارگذاری نشد', e.message)); });
			}

			function editRule(row) {
				var data = row && row.id ? row : { keyword: '', url: '', post_id: 0, max_links: 1, link_nth: 1, direct: 1, nofollow: 0, new_tab: 0, bold: 0, case_sensitive: 0, skip_own: 1, status: 'active' };
				var fields = [
					{ key: 'keyword', label: 'کلمه یا عبارت کلیدی', help: 'حساس به شکل فارسی: «می‌رود» و «میرود» یکی حساب می‌شود.' },
					{ key: 'post_id', label: 'نوشته مقصد (اختیاری)', type: 'number', help: 'اگر پر شود، نشانی از خود نوشته گرفته می‌شود.' },
					{ key: 'url', label: 'یا نشانی مقصد', type: 'url' },
					{ key: 'max_links', label: 'حداکثر لینک در هر نوشته', type: 'number', min: 0, max: 20 },
					{ key: 'link_nth', label: 'از چندمین بار استفاده شود', type: 'number', min: 1, max: 10 },
					{ key: 'status', label: 'وضعیت', type: 'select', options: [{ id: 'active', label: 'فعال' }, { id: 'inactive', label: 'غیرفعال' }] },
					{ key: 'flags', label: 'گزینه‌ها' }
				];
				var f = h('div', { class: 'hs-form' });
				var draft = JSON.parse(JSON.stringify(data));
				fields.forEach(function (fd) {
					if (fd.key === 'flags') {
						f.appendChild(HS.fieldWrap(fd, h('div', { class: 'hs-row' }, [
							sw('direct', 'لینک مستقیم (بدون صفحه میانی)', draft.direct),
							sw('nofollow', 'nofollow', draft.nofollow),
							sw('new_tab', 'باز شدن در تب جدید', draft.new_tab),
							sw('bold', 'پررنگ کردن متن لینک', draft.bold),
							sw('case_sensitive', 'حساس به بزرگ/کوچک', draft.case_sensitive),
							sw('skip_own', 'رد کردن نوشته مقصد', draft.skip_own)
						])));
						return;
					}
					f.appendChild(HS.fieldWrap(fd, HS.input(fd, draft[fd.key], function (val) { draft[fd.key] = val; })));
				});
				function sw(key, label, val) {
					var wrap = h('label', { class: 'hs-row', style: { gap: '6px', cursor: 'pointer' } });
					wrap.appendChild(HS.switchEl(!!(+val), function (x) { draft[key] = x ? 1 : 0; }));
					wrap.appendChild(h('span', { class: 'hs-small', text: label }));
					return wrap;
				}
				HS.drawer({
					title: data.id ? 'ویرایش قاعده' : 'قاعده جدید',
					body: f,
					actions: [
						{ label: HS.I18N.cancel },
						{ label: HS.I18N.save, kind: 'primary', run: function () {
							return HS.post('links/rules', draft).then(function (r) {
								HS.toast((r && r.message) || 'قاعده ذخیره شد', 'ok');
								HS.cacheClear('links'); load('rules');
							}, HS.fail);
						} }
					]
				});
			}

			function loadSuggest() {
				var pick = h('div', { class: 'hs-col' });
				pick.appendChild(HS.card({ title: 'یک نوشته را انتخاب کنید', icon: 'document' }, [
					HS.note(null, 'پیشنهادها بر پایه کلمات کلیدی مشترک و محتوای مشابه ساخته می‌شوند؛ می‌توانید قبل از درج، آن‌ها را انتخاب کنید.', ''),
					(function () {
						var inp = h('input', { class: 'hs-input', type: 'search', placeholder: 'جست‌وجوی نوشته…' });
						var out = h('div', { class: 'hs-list' });
						var t2;
						inp.addEventListener('input', function () {
							clearTimeout(t2);
							t2 = setTimeout(function () {
								var q2 = inp.value.trim();
								if (q2.length < 2) { out.innerHTML = ''; return; }
								HS.get('content/pages', { search: q2, per: 8 }).then(function (res) {
									out.innerHTML = '';
									((res && res.rows) || []).forEach(function (r) {
										out.appendChild(h('button', { class: 'hs-list__item', type: 'button', style: { width: '100%', border: '0', background: 'none', font: 'inherit', color: 'inherit', textAlign: 'start', cursor: 'pointer' }, onclick: function () { show(r.post_id || r.id); } }, [
											h('div', { class: 'hs-list__main' }, [h('div', { class: 'hs-list__title', text: r.title })])
										]));
									});
								});
							}, 350);
						});
						var sHost = h('div');
						function show(id) {
							sHost.innerHTML = '';
							sHost.appendChild(h('div', { class: 'hs-loading', text: 'ساخت پیشنهادها…' }));
							Promise.all([HS.get('links/suggest', { post_id: id, limit: 12 }), HS.get('links/related', { post_id: id, limit: 12 })]).then(function (r) {
								var sug = (r[0] && (r[0].rows || r[0].suggest)) || [];
								var rel = (r[1] && r[1].rows) || [];
								sHost.innerHTML = '';
								var chosen = {};
								var list = h('div', { class: 'hs-list' });
								sug.forEach(function (s, i) {
									var cb = h('input', { type: 'checkbox', class: 'hs-check', checked: true });
									cb.addEventListener('change', function () { chosen[i] = cb.checked ? s : null; });
									chosen[i] = s;
									list.appendChild(h('div', { class: 'hs-list__item' }, [cb, h('div', { class: 'hs-list__main' }, [
										h('div', { class: 'hs-list__title', text: s.keyword || s.text || s.title || '' }),
										h('div', { class: 'hs-list__meta', text: (s.url || '') + (s.score ? (' · امتیاز ' + num(s.score)) : '') })
									])]));
								});
								if (!sug.length) { list.appendChild(HS.empty('پیشنهادی پیدا نشد', 'ابتدا کلمات کلیدی را برای نوشته‌ها ثبت کنید.', true)); }
								sHost.appendChild(list);
								var relList = h('div', { class: 'hs-list' });
								rel.forEach(function (p) { relList.appendChild(h('div', { class: 'hs-list__item' }, [h('div', { class: 'hs-list__main' }, [h('div', { class: 'hs-list__title', text: p.title || '' }), h('div', { class: 'hs-list__meta', text: p.url || '' })])])); });
								if (!rel.length) { relList.appendChild(HS.empty('نوشته مرتبطی نیست', null, true)); }
								sHost.appendChild(h('div', { class: 'hs-group__h', text: 'نوشته‌های مرتبط' }));
								sHost.appendChild(relList);
								sHost.appendChild(h('div', { class: 'hs-row hs-row--end' }, [HS.btn('درج لینک‌های انتخاب‌شده در محتوا', { kind: 'primary', run: function () {
									var links = Object.keys(chosen).map(function (k) { return chosen[k]; }).filter(Boolean);
									return HS.post('links/insert', { post_id: id, links: links }).then(function (r) {
										HS.toast(r && r.message ? r.message : (num((r && r.inserted) || 0) + ' لینک درج شد'), 'ok');
									}, HS.fail);
								} })]));
							}, function (e) { sHost.innerHTML = ''; sHost.appendChild(HS.note('bad', 'ساخت پیشنهاد ممکن نشد', e.message)); });
						}
						pick.appendChild(sHost);
						return h('div', {}, [inp, out]);
					})()
				]));
				body.innerHTML = '';
				body.appendChild(pick);
			}

			function loadClicks() {
				HS.get('links/report', { type: 'top', days: 30 }).then(function (res) {
					res = res || {};
					body.innerHTML = '';
					var rows = res.rows || [];
					var cols = [
						{ key: 'label', label: 'لینک', render: function (r) { return '<b>' + esc(r.label || r.keyword || '') + '</b>'; } },
						{ key: 'value', label: 'کلیک معتبر', align: 'num', width: '110px', render: function (r) { return num(r.value || 0); } },
						{ key: 'date', label: 'تاریخ', width: '130px', render: function (r) { return '<span class="hs-muted hs-small">' + esc(r.date || '') + '</span>'; } }
					];
					body.appendChild(HS.lineChart([{ label: 'کلیک', values: (res.series || []).map(function (x) { return typeof x === 'object' ? (x.value || x[1]) : x; }), labels: (res.series || []).map(function (x) { return typeof x === 'object' ? (x.date || x.d) : ''; }) }], { height: 150 }));
					body.appendChild(HS.table({ columns: cols, rows: rows, emptyTitle: 'کلیکی ثبت نشده', emptyHint: 'برای شمارش کلیک، گزینه «ردیابی کلیک» را در تنظیمات لینک داخلی فعال کنید.' }));
				}, function (e) { body.innerHTML = ''; body.appendChild(HS.note('bad', 'گزارش کلیک در دسترس نیست', e.message)); });
			}

			function loadBroken() {
				HS.get('links/report', { type: 'broken', days: 30 }).then(function (res) {
					res = res || {};
					var rows = res.rows || [];
					body.innerHTML = '';
					body.appendChild(HS.note(rows.length ? 'warn' : 'ok', rows.length ? (num(rows.length) + ' لینک شکسته پیدا شده') : 'لینک شکسته‌ای نداریم', rows.length ? 'می‌توانید مقصد را اصلاح کنید یا قاعده ریدایرکت بسازید.' : 'اسکن بعدی به‌صورت خودکار اجرا می‌شود.'));
					var cols = [
						{ key: 'url', label: 'نشانی', render: function (r) { return '<span class="hs-mono">' + esc(r.url || r.label || '') + '</span>'; } },
						{ key: 'code', label: 'کد پاسخ', width: '110px', render: function (r) { var c = r.http_code || r.code || r.value; return '<span class="hs-chip ' + (c >= 500 ? 'hs-chip--bad' : 'hs-chip--warn') + '">' + num(c || 0) + '</span>'; } },
						{ key: 'post_title', label: 'نوشته', render: function (r) { return r.post_id ? ('<a href="' + esc(HS.C.site.admin || '') + 'post.php?post=' + esc(r.post_id) + '&action=edit" target="_blank" rel="noopener">' + esc(r.post_title || ('نوشته ' + num(r.post_id))) + '</a>') : '—'; } },
						{ key: '_a', label: '', width: '150px', render: function (r) {
							return '<div class="hs-row-actions"><button class="hs-btn hs-btn--sm" data-a="test" data-u="' + esc(r.url || '') + '">تست دوباره</button>' + (r.url ? '<button class="hs-btn hs-btn--sm" data-a="fix" data-u="' + esc(r.url) + '">ریدایرکت بساز</button>' : '') + '</div>';
						} }
					];
					var t = HS.table({ columns: cols, rows: rows, emptyTitle: 'موردی نیست', emptyHint: 'اسکن لینک‌های شکسته هنوز اجرا نشده یا نتیجه‌ای نداشته است.' });
					on(t, 'click', '[data-a]', function (e, el) {
						e.preventDefault();
						var url = el.dataset.u;
						if (el.dataset.a === 'test') {
							HS.get('links/check', { url: url }, { force: true }).then(function (r) {
								HS.toast((r && r.valid) ? 'لینک سالم است ✓' : ('هنوز شکسته است — کد ' + num((r && (r.code || r.http_code)) || 0)), (r && r.valid) ? 'ok' : 'warn');
							}, HS.fail);
						} else {
							HS.go('redirects', { from: url, new: 1 });
						}
					});
					body.appendChild(t);
				}, function (e) { body.innerHTML = ''; body.appendChild(HS.note('bad', 'گزارش لینک شکسته در دسترس نیست', e.message)); });
			}
			load(tab);
			return Promise.resolve();
		}
	});
})(HS);

/* =================== structure: redirects, 404, index, sitemap, robots, crawlers, images, audit =================== */
(function (HS) {
	'use strict';
	var h = HS.h, qsa = HS.qsa, on = HS.on, num = HS.num, esc = HS.esc, C = HS.C;

	function pager(ctx, total, per, onGo) {
		var pages = Math.max(1, Math.ceil(total / per));
		return h('div', { class: 'hs-pager' }, [
			h('span', { text: num(total) + ' ' + HS.I18N.results }),
			HS.btn(null, { icon: 'arrow', sm: true, kind: 'ghost', title: 'قبلی', run: function () { if (ctx.page > 1) { ctx.page--; onGo(); } } }),
			h('span', { text: num(ctx.page) + ' ' + HS.I18N.of + ' ' + num(pages) }),
			HS.btn(null, { icon: 'arrow', sm: true, kind: 'ghost', title: 'بعدی', run: function () { if (ctx.page < pages) { ctx.page++; onGo(); } } })
		]);
	}

	/* ---------- redirects ---------- */
	HS.view('redirects', {
		title: 'ریدایرکت‌ها',
		sub: '301، 302، 307، 410 و 451 — با regex، گروه‌بندی و لاگ بازدید',
		render: function (host, params) {
			var ctx = { page: 1, per: 40, search: params.search || '', status: params.status || '', group: params.group || '', sort: { by: 'id', dir: 'desc' } };
			var listHost = h('div');
			var statsHost = h('div', { class: 'hs-kpis' });
			host.appendChild(HS.card({
				title: 'مدیریت ریدایرکت', icon: 'arrow-turn',
				actions: [
					HS.btn('ریدایرکت جدید', { kind: 'primary', sm: true, icon: 'plus', run: function () { edit(null); } }),
					HS.btn('درون‌ریزی CSV', { sm: true, icon: 'upload', run: function () { importCsv(); } }),
					HS.btn('تست نشانی', { sm: true, kind: 'ghost', icon: 'magnifier', run: function () { testUrl(); } }),
					HS.btn(HS.I18N.export, { sm: true, kind: 'ghost', icon: 'download', href: HS.exportUrl('redirects') })
				]
			}, [statsHost, listHost]));

			function load() {
				listHost.innerHTML = '';
				listHost.appendChild(HS.skeleton(5));
				return HS.get('redirects', { search: ctx.search, status: ctx.status, group: ctx.group, per: ctx.per, paged: ctx.page, orderby: ctx.sort.by, order: ctx.sort.dir }).then(function (res) {
					res = res || {};
					var rows = res.rows || [];
					HS.cacheSet('redirects-static', res);
					statsHost.replaceChildren(
						kpi('ریدایرکت فعال', res.stats && res.stats.active),
						kpi('کل رکوردها', res.total),
						kpi('بازدید (۳۰ روز)', res.stats && res.stats.hits),
						kpi('بر پایه regex', rows.filter(function (r) { return +r.is_regex; }).length)
					);
					var groups = (res.groups || []);
					var bar = h('div', { class: 'hs-row' });
					var si = h('input', { class: 'hs-input', type: 'search', placeholder: 'جست‌وجو در نشانی مبدأ یا مقصد…', value: ctx.search, style: { maxWidth: '280px' } });
					var t;
					si.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { ctx.search = si.value; ctx.page = 1; load(); }, 420); });
					bar.appendChild(si);
					bar.appendChild(HS.select([{ id: '', label: 'همه وضعیت‌ها' }, { id: 'active', label: 'فعال' }, { id: 'inactive', label: 'غیرفعال' }], ctx.status, function (v) { ctx.status = v; ctx.page = 1; load(); }));
					if (groups.length) {
						bar.appendChild(HS.select([{ id: '', label: 'همه گروه‌ها' }].concat(groups.map(function (g) { return { id: g, label: g }; })), ctx.group, function (v) { ctx.group = v; ctx.page = 1; load(); }));
					}
					var cols = [
						{ key: 'source', label: 'مبدأ', sortable: true, render: function (r) { return '<b class="hs-mono">' + esc(r.source) + '</b>' + (+r.is_regex ? ' <span class="hs-tag">regex</span>' : ''); } },
						{ key: 'target', label: 'مقصد', render: function (r) { return r.target ? '<span class="hs-mono">' + esc(r.target) + '</span>' : '<span class="hs-muted">—</span>'; } },
						{ key: 'type', label: 'کد', width: '70px', align: 'num', render: function (r) { return '<span class="hs-chip ' + (String(r.type) === '301' ? 'hs-chip--ok' : (String(r.type) === '410' || String(r.type) === '451') ? 'hs-chip--bad' : '') + '">' + esc(r.type) + '</span>'; } },
						{ key: 'group_name', label: 'گروه', width: '110px', render: function (r) { return '<span class="hs-muted hs-small">' + esc(r.group_name || 'default') + '</span>'; } },
						{ key: 'hits', label: 'بازدید', width: '90px', align: 'num', sortable: true, render: function (r) { return num(r.hits || 0); } },
						{ key: 'status', label: 'وضعیت', width: '90px', render: function (r) { return r.status === 'active' ? HS.chip('فعال', 'ok', { dot: false }).outerHTML : HS.chip('غیرفعال', null, { dot: false }).outerHTML; } },
						{ key: '_a', label: '', width: '190px', render: function (r) {
							return '<div class="hs-row-actions">' +
								'<button class="hs-btn hs-btn--sm" data-a="edit" data-id="' + esc(r.id) + '">ویرایش</button>' +
								'<button class="hs-btn hs-btn--sm hs-btn--ghost" data-a="toggle" data-id="' + esc(r.id) + '" data-s="' + esc(r.status) + '">' + (r.status === 'active' ? 'خاموش' : 'روشن') + '</button>' +
								'<button class="hs-btn hs-btn--sm hs-btn--danger" data-a="del" data-id="' + esc(r.id) + '">×</button></div>';
						} }
					];
					listHost.innerHTML = '';
					var t2 = HS.table({ columns: cols, rows: rows, picky: true, rowKey: 'id', footer: pager(ctx, res.total || 0, ctx.per, load), sort: ctx.sort, onSort: function (k) { ctx.sort = { by: k, dir: ctx.sort.by === k && ctx.sort.dir === 'asc' ? 'desc' : 'asc' }; load(); }, onRow: function (r) { edit(r); },
						emptyTitle: 'ریدایرکتی ثبت نشده', emptyHint: 'ریدایرکت‌ها هنگام تغییر نامک یا حذف نوشته خودکار ساخته می‌شوند؛ می‌توانید دستی هم اضافه کنید.',
						onPick: function (ids) { pickBar.replaceChildren(HS.btn('حذف (' + num(ids.length) + ')', { sm: true, kind: 'danger', run: function () { return HS.post('redirects/delete', { ids: ids }).then(function () { HS.toast('حذف شد', 'ok'); HS.cacheClear('redirects'); load(); }, HS.fail); } })); }
					});
					var pickBar = h('div', { class: 'hs-row', style: { padding: '10px 16px' } });
					listHost.appendChild(t2);
					listHost.appendChild(pickBar);
					on(t2, 'click', '[data-a]', function (e, el) {
						e.preventDefault(); e.stopPropagation();
						var id = parseInt(el.dataset.id, 10);
						var act = el.dataset.a;
						if (act === 'edit') { edit(rows.filter(function (r) { return String(r.id) === String(id); })[0]); }
						else if (act === 'del') { HS.confirmBox('حذف ریدایرکت', 'این رکورد حذف شود؟', function () { return HS.post('redirects/delete', { ids: [id] }).then(function () { HS.cacheClear('redirects'); load(); }, HS.fail); }, 'danger'); }
						else { HS.post('redirects', { id: id, status: el.dataset.s === 'active' ? 'inactive' : 'active' }).then(function () { load(); }, HS.fail); }
					});
					listHost.insertBefore(bar, listHost.firstChild);
				}, function (e) { listHost.innerHTML = ''; listHost.appendChild(HS.note('bad', 'ریدایرکت‌ها بارگذاری نشد', e.message)); });
			}
			function kpi(l, v) { return h('div', { class: 'hs-kpi' }, [h('div', { class: 'hs-kpi__v', text: num(v || 0) }), h('div', { class: 'hs-kpi__l', text: l })]); }

			function edit(row) {
				var d = row ? JSON.parse(JSON.stringify(row)) : { source: (params.from || ''), target: params.to || '', type: '301', is_regex: 0, status: 'active', group_name: 'default', notes: '' };
				var f = h('div', { class: 'hs-form hs-form--1' });
				[
					{ key: 'source', label: 'مبدأ (نسبی یا کامل)', help: 'مثل /old-page یا /cat/(.*) با regex', full: true },
					{ key: 'target', label: 'مقصد', help: 'نشانی کامل یا نسبی؛ خالی بگذارید تا ۴۱۰ برگرداند', full: true },
					{ key: 'type', label: 'نوع', type: 'select', options: [{ id: '301', label: '301 — جا به جا شده (همیشگی)' }, { id: '302', label: '302 — موقت' }, { id: '307', label: '307 — موقت با متد' }, { id: '410', label: '410 — برای همیشه حذف' }, { id: '451', label: '451 — در دسترس نیست (قانونی)' }] },
					{ key: 'group_name', label: 'گروه' },
					{ key: 'notes', label: 'یادداشت', type: 'textarea', rows: 2, full: true },
					{ key: 'is_regex', label: 'الگوی منظم (regex)' },
					{ key: 'status', label: 'وضعیت' }
				].forEach(function (fd) {
					if (fd.key === 'is_regex') { f.appendChild(HS.fieldWrap(fd, sw(fd.label, d.is_regex, function (v) { d.is_regex = v ? 1 : 0; }))); return; }
					if (fd.key === 'status') { f.appendChild(HS.fieldWrap(fd, sw('فعال', d.status === 'active', function (v) { d.status = v ? 'active' : 'inactive'; }))); return; }
					f.appendChild(HS.fieldWrap(fd, HS.input(fd, d[fd.key], function (v) { d[fd.key] = v; })));
				});
				HS.drawer({
					title: row && row.id ? 'ویرایش ریدایرکت' : 'ریدایرکت جدید',
					body: f,
					actions: [{ label: HS.I18N.cancel }, { label: HS.I18N.save, kind: 'primary', run: function () {
						return HS.post('redirects', d).then(function (r) {
							HS.toast((r && r.message) || 'ذخیره شد', 'ok');
							HS.cacheClear('redirects'); HS.toast('حافظه ریدایرکت پاک شد', 'info', { ms: 1200 });
							load();
						}, HS.fail);
					} }]
				});
			}
			function sw(label, val, cb) {
				var w = h('label', { class: 'hs-row', style: { gap: '7px', cursor: 'pointer' } });
				w.appendChild(HS.switchEl(!!(+val || val === true || val === 1), cb));
				w.appendChild(h('span', { class: 'hs-small', text: label }));
				return w;
			}
			function importCsv() {
				var ta = h('textarea', { class: 'hs-input hs-textarea hs-textarea--code', rows: 8, placeholder: 'source,target,type\n/old/,/new/,301' });
				var dry = true;
				var body = h('div', { class: 'hs-col' }, [
					HS.note(null, 'ستون‌ها: source، target، type (اختیاری) و regex. با حالت «فقط بررسی» هیچ رکوردی نوشته نمی‌شود.'),
					ta,
					sw('فقط بررسی کن (بدون ذخیره)', dry, function (v) { dry = v; })
				]);
				HS.modal({
					title: 'درون‌ریزی CSV', body: body, size: 'md',
					actions: [{ label: HS.I18N.cancel }, { label: 'درون‌ریزی', kind: 'primary', run: function () {
						return HS.post('redirects/import', { csv: ta.value, dry_run: dry }).then(function (r) {
							HS.toast(((r && r.message) || 'انجام شد') + (r && r.imported !== undefined ? (' · ' + num(r.imported) + ' رکورد') : ''), 'ok');
							HS.cacheClear('redirects'); load();
						}, HS.fail);
					} }]
				});
			}
			function testUrl() {
				var inp = h('input', { class: 'hs-input', placeholder: '/some-path', value: params.from || '/' });
				var out = h('div');
				HS.modal({
					title: 'تست یک نشانی', size: 'sm',
					body: h('div', { class: 'hs-col' }, [inp, out, h('div', { class: 'hs-muted hs-small', text: 'اگر ریدایرکتی روی این مسیر باشد، مقصد و کد آن را نشان می‌دهیم.' })]),
					actions: [{ label: HS.I18N.close }, { label: 'بررسی', kind: 'primary', keepOpen: true, run: function () {
						return HS.get('redirects/test', { path: inp.value }).then(function (r) {
							out.innerHTML = '';
							out.appendChild(h('pre', { class: 'hs-code', text: JSON.stringify(r, null, 2) }));
						}, function (e) { out.innerHTML = ''; out.appendChild(HS.note('bad', 'بررسی نشد', e.message)); });
					} }]
				});
			}

			if (params.new || params.from) { edit(null); }
			return load();
		}
	});

	/* ---------- 404 monitor ---------- */
	HS.view('notfound', {
		title: 'خطاهای ۴۰',
		sub: 'چه نشانی‌هایی پیدا نمی‌شوند، از کجا آمده‌اند و چه باید کرد',
		render: function (host, params) {
			var ctx = { page: 1, per: 40, search: '', state: params.state || 'new', sort: { by: 'last_seen', dir: 'desc' } };
			var stats = h('div', { class: 'hs-kpis' });
			var listHost = h('div');
			host.appendChild(HS.card({
				title: 'پایش ۴۰۴', icon: 'warning',
				actions: [
					HS.btn('پاک کردن موارد قدیمی', { sm: true, kind: 'ghost', icon: 'trash', run: function () { return HS.post('notfound/prune', {}).then(function (r) { HS.toast(num((r && r.pruned) || 0) + ' مورد پاک شد', 'ok'); load(); }, HS.fail); } }),
					HS.btn(HS.I18N.export, { sm: true, kind: 'ghost', icon: 'download', href: HS.exportUrl('notfound') })
				]
			}, [stats, listHost]));
			function load() {
				listHost.innerHTML = '';
				listHost.appendChild(HS.skeleton(5));
				HS.get('notfound', { search: ctx.search, state: ctx.state, per: ctx.per, paged: ctx.page, orderby: ctx.sort.by, order: ctx.sort.dir }).then(function (res) {
					res = res || {};
					var rows = res.rows || [];
					var s = res.stats || {};
					stats.replaceChildren(
						kpi('باز', s.open), kpi('حل‌شده', s.resolved), kpi('کل بازدیدها', s.hits), kpi('در این فهرست', res.total)
					);
					var bar = h('div', { class: 'hs-row' }, [
						(function () { var i = h('input', { class: 'hs-input', type: 'search', placeholder: 'جست‌وجو نشانی…', value: ctx.search, style: { maxWidth: '260px' } }); var t; i.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { ctx.search = i.value; ctx.page = 1; load(); }, 400); }); return i; })(),
						HS.segmented([{ id: 'new', label: 'باز' }, { id: 'ignored', label: 'بی‌اهمیت' }, { id: 'resolved', label: 'حل‌شده' }, { id: '', label: 'همه' }], ctx.state, function (v) { ctx.state = v; ctx.page = 1; load(); })
					]);
					var cols = [
						{ key: 'url', label: 'نشانی', render: function (r) { return '<b class="hs-mono">' + esc(r.url) + '</b>' + (r.referer ? '<div class="hs-muted hs-small">از: ' + esc(trunc(r.referer, 60)) + '</div>' : ''); } },
						{ key: 'hits', label: 'تکرار', align: 'num', width: '80px', sortable: true, render: function (r) { return num(r.hits || 1); } },
						{ key: 'status_code', label: 'کد', width: '70px', align: 'num', render: function (r) { return num(r.status_code || 404); } },
						{ key: 'user_agent', label: 'کاربر', width: '130px', render: function (r) { var bot = /bot|crawl|spider|python|curl|java/i.test(r.user_agent || ''); return bot ? HS.chip('خزنده', 'info', { dot: false }).outerHTML : '<span class="hs-muted hs-small">' + esc(trunc(r.user_agent || '—', 26)) + '</span>'; } },
						{ key: 'last_seen', label: 'آخرین مشاهده', width: '130px', sortable: true, render: function (r) { return '<span class="hs-muted hs-small">' + esc(HS.ago(r.last_seen)) + '</span>'; } },
						{ key: 'state', label: 'وضعیت', width: '90px', render: function (r) { return '<span class="hs-tag">' + esc(stateLabel(r.state)) + '</span>'; } },
						{ key: '_a', label: '', width: '230px', render: function (r) {
							return '<div class="hs-row-actions">' +
								'<button class="hs-btn hs-btn--sm" data-a="fix" data-id="' + esc(r.id) + '" data-u="' + esc(r.url) + '">ریدایرکت</button>' +
								'<button class="hs-btn hs-btn--sm hs-btn--ghost" data-a="ignore" data-id="' + esc(r.id) + '">بی‌اهمیت</button>' +
								'<button class="hs-btn hs-btn--sm hs-btn--ghost" data-a="delete" data-id="' + esc(r.id) + '">حذف</button></div>';
						} }
					];
					listHost.innerHTML = '';
					listHost.appendChild(bar);
					var t2 = HS.table({ columns: cols, rows: rows, picky: true, rowKey: 'id', footer: pager(ctx, res.total || 0, ctx.per, load), sort: ctx.sort, onSort: function (k) { ctx.sort = { by: k, dir: ctx.sort.by === k && ctx.sort.dir === 'asc' ? 'desc' : 'asc' }; load(); },
						emptyTitle: 'خطای ۴۰۴ بازیافت نشده', emptyHint: 'وقتی کاربر یا خزنده‌ای به نشانی بسته‌ای برسد، اینجا ثبت می‌شود.',
						onPick: function (ids) { pick.replaceChildren(
							HS.btn('بی‌اهمیت (' + num(ids.length) + ')', { sm: true, run: function () { return bulk(ids, 'ignore'); } }),
							HS.btn('حل‌شده (' + num(ids.length) + ')', { sm: true, run: function () { return bulk(ids, 'resolve'); } }),
							HS.btn('حذف (' + num(ids.length) + ')', { sm: true, kind: 'danger', run: function () { return bulk(ids, 'delete'); } })
						); } });
					var pick = h('div', { class: 'hs-row', style: { padding: '10px 16px' } });
					listHost.appendChild(t2);
					listHost.appendChild(pick);
					on(t2, 'click', '[data-a]', function (e, el) {
						e.preventDefault();
						var id = parseInt(el.dataset.id, 10);
						if (el.dataset.a === 'fix') {
							HS.go('redirects', { from: el.dataset.u, new: 1 });
							return;
						}
						bulk([id], el.dataset.a);
					});
					function bulk(ids, action) {
						return HS.post('notfound/bulk', { ids: ids, action: action }).then(function (r) {
							HS.toast((r && r.message) || 'انجام شد', 'ok');
							HS.cacheClear('notfound'); load();
						}, HS.fail);
					}
				}, function (e) { listHost.innerHTML = ''; listHost.appendChild(HS.note('bad', 'فهرست ۴۰۴ در دسترس نیست', e.message)); });
			}
			function stateLabel(s) { return ({ new: 'باز', bot: 'خزنده', ignored: 'بی‌اهمیت', resolved: 'حل‌شده' })[s] || s || '—'; }
			function kpi(l, v) { return h('div', { class: 'hs-kpi' }, [h('div', { class: 'hs-kpi__v', text: num(v || 0) }), h('div', { class: 'hs-kpi__l', text: l })]); }
			function trunc(s, n) { return HS.trunc(s, n); }
			return load();
		}
	});

	/* ---------- index manager ---------- */
	HS.view('index', {
		title: 'ایندکس‌بان',
		sub: 'به‌صورت گروهی به گوگل بگویید چه چیزی ایندکس شود',
		render: function (host, params) {
			var ctx = { page: 1, per: 40, search: '', state: params.state || '', type: params.type || 'post' };
			var sumHost = h('div', { class: 'hs-col' });
			var listHost = h('div');
			host.appendChild(sumHost);
			host.appendChild(HS.card({
				title: 'صفحه‌ها و نوشته‌ها', icon: 'eye', flush: true,
				actions: [HS.btn('ارسال اینکس‌ناو', { sm: true, kind: 'primary', icon: 'upload', run: indexNow }), HS.btn('بازگردانی آخرین دسته', { sm: true, kind: 'ghost', icon: 'refresh', run: undoLast })]
			}, [listHost]));

			HS.get('index/summary').then(function (s) {
				s = s || {};
				var row = HS.kpis([
					{ label: 'قابل ایندکس', value: num(s.indexable || 0) },
					{ label: 'noindex', value: num(s.noindex || 0) },
					{ label: 'کل محتوا', value: num(s.total || 0) },
					{ label: 'اینکس‌ناو', value: (s.indexnow && s.indexnow.enabled) ? 'فعال' : 'خاموش' }
				]);
				var key = (s.indexnow && (s.indexnow.key || s.indexnow.key_preview)) || '';
				sumHost.appendChild(HS.card({ title: 'وضعیت ایندکس', icon: 'eye', sub: s.last ? ('آخرین ارسال: ' + s.last) : '' }, [
					row,
					key ? h('div', { class: 'hs-row' }, [h('span', { class: 'hs-muted hs-small', text: 'کلید اینکس‌ناو:' }), h('code', { class: 'hs-code', style: { padding: '3px 8px', maxWidth: '360px' }, text: key }), HS.btn('کپی', { sm: true, kind: 'ghost', run: function () { HS.copyText(key); } }), HS.btn('کلید جدید', { sm: true, kind: 'ghost', run: function () { return HS.post('index/key', {}).then(function () { HS.toast('کلید تازه ساخته شد', 'ok'); }, HS.fail); } })]) : null
				]));
			}, function () { sumHost.innerHTML = ''; });

			function load() {
				listHost.innerHTML = '';
				listHost.appendChild(HS.skeleton(5));
				HS.get('index/items', { type: ctx.type, state: ctx.state, search: ctx.search, per: ctx.per, paged: ctx.page }).then(function (res) {
					res = res || {};
					var rows = res.rows || [];
					var bar = h('div', { class: 'hs-row' }, [
						HS.select([{ id: 'post', label: 'نوشته‌ها' }, { id: 'page', label: 'برگه‌ها' }].concat(HS.entries(C.postTypes).filter(function (p) { return ['post', 'page'].indexOf(p[0]) === -1; }).map(function (p) { return { id: p[0], label: HS.labelOf(p[1], p[0]) }; })), ctx.type, function (v) { ctx.type = v; ctx.page = 1; load(); }),
						HS.segmented([{ id: '', label: 'همه' }, { id: 'index', label: 'ایندکس' }, { id: 'noindex', label: 'noindex' }], ctx.state, function (v) { ctx.state = v; ctx.page = 1; load(); }),
						(function () { var i = h('input', { class: 'hs-input', type: 'search', placeholder: 'جست‌وجو…', value: ctx.search, style: { maxWidth: '220px' } }); var t; i.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { ctx.search = i.value; ctx.page = 1; load(); }, 400); }); return i; })()
					]);
					var cols = [
						{ key: 'title', label: 'عنوان', render: function (r) { return '<b>' + esc(r.title || '(بی‌عنوان)') + '</b><div class="hs-muted hs-small">' + esc(r.url || '') + '</div>'; } },
						{ key: 'score', label: 'امتیاز', align: 'num', width: '80px', render: function (r) { return r.score ? num(r.score) : '—'; } },
						{ key: 'words', label: 'واژه', align: 'num', width: '80px', render: function (r) { return num(r.words || 0); } },
						{ key: 'modified', label: 'ویرایش', width: '120px', render: function (r) { return '<span class="hs-muted hs-small">' + esc(r.modified ? HS.ago(r.modified) : '—') + '</span>'; } },
						{ key: 'state', label: 'وضعیت ایندکس', width: '150px', render: function (r) {
							var isOn = r.state !== 'noindex';
							return '<button type="button" class="hs-switch' + (isOn ? ' is-on' : '') + '" data-hs-toggle="' + esc(r.id) + '" aria-checked="' + (isOn ? 'true' : 'false') + '"><i></i></button>';
						} }
					];
					listHost.innerHTML = '';
					listHost.appendChild(bar);
					var t2 = HS.table({ columns: cols, rows: rows, picky: true, rowKey: 'id', footer: pager(ctx, res.total || 0, ctx.per, load),
						emptyTitle: 'موردی نیست', emptyHint: 'فیلترها را تغییر دهید.',
						onPick: function (ids) { pick.replaceChildren(
							HS.btn('ایندکس (' + num(ids.length) + ')', { sm: true, run: function () { return set(ids, 'index'); } }),
							HS.btn('noindex (' + num(ids.length) + ')', { sm: true, run: function () { return set(ids, 'noindex'); } }),
							HS.btn('اینکس‌ناو (' + num(ids.length) + ')', { sm: true, kind: 'ghost', run: function () { return HS.post('index/indexnow', { urls: ids.map(String) }).then(function () { HS.toast('ارسال شد', 'ok'); }, HS.fail); } })
						); } });
					var pick = h('div', { class: 'hs-row', style: { padding: '10px 16px' } });
					listHost.appendChild(t2);
					listHost.appendChild(pick);
					/* switch cells need wiring after render (outerHTML loses handler) */
					on(t2, 'click', '[data-hs-toggle]', function (e, el) {
						e.preventDefault(); e.stopPropagation();
						var next = !el.classList.contains('is-on');
						HS.post('index/set', { post_id: parseInt(el.getAttribute('data-hs-toggle'), 10), state: next ? 'index' : 'noindex' }).then(function () {
							el.classList.toggle('is-on', next);
							el.setAttribute('aria-checked', next ? 'true' : 'false');
							HS.toast(next ? 'ایندکس می‌شود' : 'noindex شد', 'ok', { ms: 1400 });
						}, HS.fail);
					});
					function set(ids, st) {
						return HS.post('index/bulk', { ids: ids, state: st }).then(function (r) {
							HS.toast((r && r.message) || 'تغییر کرد', 'ok');
							HS.cacheClear('index'); load();
							if (r && r.batch) { HS.toast('در صورت نیاز می‌توانید بازگردانی کنید.', 'info'); }
						}, HS.fail);
					}
				}, function (e) { listHost.innerHTML = ''; listHost.appendChild(HS.note('bad', 'فهرست ایندکس در دسترس نیست', e.message)); });
			}
			function indexNow() {
				return HS.post('index/indexnow', {}).then(function (r) {
					HS.toast(r && r.ok ? (num(r.sent || 0) + ' نشانی ارسال شد') : 'اینکس‌ناو فعال نیست یا نشانی‌ای نبود', r && r.ok ? 'ok' : 'warn');
				}, HS.fail);
			}
			function undoLast() {
				return HS.post('index/undo', { batch: '' }).then(function (r) {
					HS.toast((r && r.message) || 'بازگردانی شد', 'ok');
					HS.cacheClear('index'); load();
				}, HS.fail);
			}
			return load();
		}
	});

	/* ---------- sitemap ---------- */
	HS.view('sitemap', {
		title: 'نقشه سایت',
		sub: 'نقشه‌های XML، پوشش آدرس‌ها و ارسال به موتورهای جست‌وجو',
		render: function (host) {
			host.appendChild(HS.skeleton(4));
			return HS.get('sitemap').then(function (res) {
				host.innerHTML = '';
				res = res || {};
				var kids = res.children || res.sitemaps || [];
				var cols = [
					{ key: 'key', label: 'نقشه', render: function (r) { return '<b>' + esc(r.label || r.key || r.name || '') + '</b>'; } },
					{ key: 'count', label: 'آدرس', align: 'num', width: '100px', render: function (r) { return num(r.count || r.urls || 0); } },
					{ key: 'lastmod', label: 'آخرین به‌روزرسانی', width: '160px', render: function (r) { return '<span class="hs-muted hs-small">' + esc(r.lastmod ? HS.ago(r.lastmod) : (r.generated ? HS.ago(r.generated) : '—')) + '</span>'; } },
					{ key: 'url', label: '', width: '170px', render: function (r) {
						return '<a class="hs-btn hs-btn--sm" href="' + esc(r.url || (C.site.home + 'sitemap-' + (r.key || r.name) + '.xml')) + '" target="_blank" rel="noopener">باز کردن</a>';
					} }
				];
				host.appendChild(HS.card({
					title: 'نقشه اصلی', icon: 'map', sub: res.cached ? ('کش‌شده · ' + HS.ago(res.generated)) : '',
					actions: [
						HS.btn(res.enabled === false ? 'نقشه سایت خاموش است — تنظیمات' : 'نقشه اصلی', { kind: res.enabled === false ? 'danger' : 'ghost', icon: 'external', href: res.url || (C.site.home + 'sitemap_index.xml'), target: '_blank' }),
						HS.btn('ارسال به موتورهای جست‌وجو', { kind: 'primary', sm: true, icon: 'upload', run: function () { return HS.post('sitemap/ping', {}).then(function (r) { HS.toast((r && r.message) || 'اعلام شد', 'ok'); }, HS.fail); } }),
						HS.btn('ساخت دوباره', { sm: true, icon: 'refresh', run: function () { HS.cacheClear('sitemap'); HS.renderRoute(); } })
					]
				}, [
					HS.kpis([
						{ label: 'کل آدرس‌ها', value: num(res.urls || res.total || 0) },
						{ label: 'نقشه‌های فرعی', value: num(kids.length) },
						{ label: 'حداکثر در هر فایل', value: num(HS.sget('sitemap.per_page', 1000)) },
						{ label: 'وضعیت', value: res.enabled === false ? 'خاموش' : 'فعال', hint: res.cached ? 'کش فعال' : 'تولید زنده' }
					]),
					HS.table({ columns: cols, rows: kids, emptyTitle: 'نقشه فرعی‌ای نیست', emptyHint: 'از تنظیمات نقشه سایت، نوع‌های دلخواه را فعال کنید.', emptyAction: HS.btn('تنظیمات نقشه سایت', { kind: 'primary', sm: true, run: function () { HS.go('settings', { group: 'sitemap' }); } }) }),
					HS.note(null, 'نشانی نقشه سایت در robots.txt اعلام شده است. اگر از CDN یا کش استفاده می‌کنید، بعد از تغییرات کش را پاک کنید.', '')
				]));
			});
		}
	});

	/* ---------- robots + access ---------- */
	HS.view('robots', {
		title: 'رباتز و دسترسی',
		sub: 'فایل robots.txt مجازی، قوانین خزنده‌ها و محافظت',
		render: function (host) {
			host.appendChild(HS.skeleton(3));
			return HS.get('robots').then(function (res) {
				host.innerHTML = '';
				res = res || {};
				var content = res.content || '';
				var ta = h('textarea', { class: 'hs-input hs-textarea hs-textarea--code', rows: 16, spellcheck: 'false' });
				ta.value = content;
				var bot = h('input', { class: 'hs-input', value: 'Googlebot', style: { maxWidth: '180px' } });
				var path = h('input', { class: 'hs-input', value: '/', style: { maxWidth: '240px' } });
				var out = h('div');
				var overrideOn = !!(res.override !== undefined ? res.override : res.override_file);
				var write = HS.switchEl(overrideOn, function (v) {
					return HS.post('robots', { content: ta.value, override: v }).then(function (r) {
						var w = r && r.written;
						if (v && w && w.ok === false) {
							HS.toast(w.error ? ('تنظیم ذخیره شد، ولی فایل نوشته نشد: ' + w.error) : 'تنظیم ذخیره شد، ولی فایل نوشته نشد', 'warn', { ms: 5200 });
						} else {
							HS.toast(v ? ('فایل robots.txt نوشته شد' + (w && w.bytes ? ' (' + HS.bytes(w.bytes) + ')' : '')) : 'از این پس نسخهٔ مجازی سرو می‌شود', 'ok');
						}
						HS.cacheClear('robots');
						HS.renderRoute();
					}, HS.fail);
				});
				host.appendChild(HS.card({
					title: 'robots.txt', icon: 'shield', sub: res.virtual ? 'مجازی (فایل واقعی ساخته نمی‌شود)' : 'فایل واقعی',
					actions: [
						HS.btn('ذخیره', { kind: 'primary', sm: true, icon: 'check', run: function () { return HS.post('robots', { content: ta.value }).then(function (r) { ta.value = (r && r.content) || ta.value; HS.toast('رباتز ذخیره شد', 'ok'); }, HS.fail); } }),
						HS.btn('باز کردن فایل', { sm: true, kind: 'ghost', icon: 'external', href: (C.site.home || '/') + 'robots.txt', target: '_blank' })
					]
				}, [ta, h('div', { class: 'hs-row' }, [
					h('span', { class: 'hs-muted hs-small', text: 'تست دسترسی:' }), bot, path,
					HS.btn('بررسی', { sm: true, run: function () { return HS.get('robots/test', { bot: bot.value, path: path.value }).then(function (r) { out.innerHTML = ''; out.appendChild(HS.note(r && r.allowed ? 'ok' : 'warn', (r && r.allowed ? 'مجاز است' : 'مسدود است'), (r && r.rule) ? ('قاعده فعال: ' + r.rule) : '')); }, HS.fail); } })
				]), h('div', { class: 'hs-srow' }, [
					h('div', { class: 'hs-srow__main' }, [
						h('div', { class: 'hs-srow__t', text: 'فایل واقعی روی سرور نوشته شود' }),
						h('div', { class: 'hs-srow__d', text: 'بعضی هاست‌ها فقط robots.txt فیزیکی را می‌خوانند؛ با روشن کردن این گزینه محتوای بالا در پوشهٔ وردپرس نوشته می‌شود. با خاموش کردنش فایل پاک نمی‌شود، فقط نسخهٔ مجازی سرو می‌شود.' })
					]),
					h('div', { class: 'hs-srow__ctl' }, [write])
				]), out]));

				var st = res.status || {};
				var bots = res.bots || res.bot_catalog || {};
				var be = HS.entries(bots);
				host.appendChild(HS.card({ title: 'خزنده‌های هوش مصنوعی و موتورهای پاسخ', icon: 'bug', sub: be.length + ' مورد' }, [
					HS.note(null, 'با افزودن یک خزنده به فهرست مسدود، User-Agent او در robots.txt بلاک می‌شود.', ''),
					(function () {
						var grid = h('div', { class: 'hs-cols hs-cols--3' });
						var blocked = (st.blocked || st.block || []);
						be.slice(0, 40).forEach(function (p) {
							var name = HS.labelOf(p[1], p[0]);
							var isBlocked = blocked.indexOf ? blocked.indexOf(p[0]) > -1 : false;
							var el = h('div', { class: 'hs-srow' });
							el.appendChild(h('div', { class: 'hs-srow__main' }, [h('div', { class: 'hs-srow__t', text: name }), h('div', { class: 'hs-srow__d', text: p[1] && p[1].note ? p[1].note : (p[1] && p[1].label ? '' : p[0]) })]));
							el.appendChild(h('div', { class: 'hs-srow__ctl' }, [HS.switchEl(!isBlocked, function (v) {
								var next = blocked.slice();
								if (v) { next = next.filter(function (x) { return x !== p[0]; }); } else { next.push(p[0]); }
								return HS.post('settings', { 'robots.ai_bots': next }).then(function () { HS.toast(v ? 'اجازه داده شد' : 'مسدود شد', 'ok'); }, HS.fail);
							})]));
							grid.appendChild(el);
						});
						return be.length ? grid : HS.empty('فهرست خزنده‌ها در دسترس نیست');
					})()
				]));
			});
		}
	});

	/* ---------- crawler watch ---------- */
	HS.view('crawl', {
		title: 'رصد خزنده‌ها',
		sub: 'چه رباتی، کِی، کدام صفحه را دیده است',
		render: function (host, params) {
			var days = parseInt(params.days, 10) || 7;
			host.appendChild(HS.skeleton(4));
			return HS.get('bots', { days: days }).then(function (res) {
				host.innerHTML = '';
				res = res || {};
				var rows = res.rows || res.log || [];
				var crawlers = res.crawlers || res.by_bot || [];
				host.appendChild(HS.card({ title: 'دو هفته اخیر', icon: 'bug', sub: res.enabled === false ? 'ثبت بازدید خزنده‌ها خاموش است' : '', actions: [
					HS.segmented([{ value: 1, label: '۱ روز' }, { value: 7, label: '۷ روز' }, { value: 30, label: '۳۰ روز' }], days, function (v) { HS.go('crawl', { days: v }); }),
					HS.btn('فعال کردن ثبت', { sm: true, kind: res.enabled === false ? 'primary' : 'ghost', run: function () { HS.markDirty('robots.log_bots', true); return HS.flushDirty().then(function () { HS.renderRoute(); }); } })
				] }, [
					HS.kpis([
						{ label: 'بازدید خزنده', value: num(res.total || (Array.isArray(rows) ? rows.length : 0)) },
						{ label: 'خزنده فعال', value: num(res.bot_count || (crawlers.length || Object.keys(crawlers).length || 0)) },
						{ label: 'میز AI', value: num(res.ai_visits || res.ai || 0) }
					]),
					(function () {
						var list = [];
						HS.entries(crawlers).slice(0, 12).forEach(function (p) {
							var v = isFinite(p[1]) ? p[1] : (p[1] && (p[1].count || p[1].visits)) || 0;
							list.push({ label: HS.labelOf(p[1], p[0]), value: v });
						});
						return list.length ? HS.bars(list) : HS.empty('داده‌ای برای نمودار نیست', 'چند روز صبر کنید تا بازدیدها ثبت شود.', true);
					})()
				]));
				var cols = [
					{ key: 'bot_name', label: 'خزنده', render: function (r) { return '<b>' + esc(r.bot_name || r.bot || r.name || '—') + '</b>'; } },
					{ key: 'path', label: 'مسیر', render: function (r) { return '<span class="hs-mono">' + esc(r.path || r.url || '') + '</span>'; } },
					{ key: 'purpose', label: 'هدف', width: '110px', render: function (r) { return '<span class="hs-tag">' + esc(({ visit: 'بازدید', sitemap: 'نقشه', robots: 'رباتز', llms: 'LLMs' })[r.purpose] || r.purpose || 'visit') + '</span>'; } },
					{ key: 'bot_ip', label: 'IP', width: '130px', render: function (r) { return '<span class="hs-muted hs-mono">' + esc(r.bot_ip || r.ip || '') + '</span>'; } },
					{ key: 'created_at', label: 'زمان', width: '150px', render: function (r) { return '<span class="hs-muted hs-small">' + esc(r.created_at ? HS.faDate(r.created_at, true) : '—') + '</span>'; } }
				];
				host.appendChild(HS.card({ title: 'آخرین بازدیدها', icon: 'clock', flush: true }, [HS.table({ columns: cols, rows: rows.slice ? rows.slice(0, 200) : [], emptyTitle: 'هنوز چیزی ثبت نشده', emptyHint: 'ثبت بازدید خزنده‌ها را از بخش رباتز فعال کنید.' })]));
			});
		}
	});

	/* ---------- images ---------- */
	HS.view('images', {
		title: 'سئوی تصاویر',
		sub: 'متن جایگزین، نام‌گذاری، حذف EXIF و پیش‌بارگذاری تصویر LCP',
		render: function (host, params) {
			var ctx = { page: 1, per: 24, type: params.type || 'alt' };
			var grid = h('div', { class: 'hs-imggrid' });
			var statHost = h('div', { class: 'hs-kpis' });
			host.appendChild(HS.card({
				title: 'تصویرهای نیازمند رسیدگی', icon: 'image',
				actions: [
					HS.btn('پر کردن alt انتخاب‌شده‌ها', { kind: 'primary', sm: true, icon: 'wand', run: function () { return run('images/fill'); } }),
					HS.btn('تولید alt با هوش مصنوعی', { sm: true, icon: 'chip', run: function () { return run('images/ai'); } }),
					HS.btn('تغییر نام فایل‌ها', { sm: true, kind: 'ghost', icon: 'text', run: function () { return run('images/rename'); } })
				]
			}, [statHost, HS.segmented([{ id: 'alt', label: 'بی‌متن جایگزین' }, { id: 'title', label: 'بی‌عنوان' }, { id: 'caption', label: 'بی‌زیرنویس' }, { id: 'description', label: 'بی‌توضیح' }, { id: 'large', label: 'سنگین' }], ctx.type, function (v) { ctx.type = v; ctx.page = 1; load(); }), grid]));

			function run(route) {
				var ids = qsa('#hs-view input[data-id]:checked').map(function (c) { return c.value; });
				return HS.post(route, { ids: ids, all: !ids.length }).then(function (r) {
					HS.toast((r && r.message) || (num((r && (r.count || r.applied)) || 0) + ' تصویر پردازش شد'), 'ok');
					HS.cacheClear('images'); load();
				}, HS.fail);
			}
			function load() {
				grid.innerHTML = '';
				grid.appendChild(HS.skeleton(3));
				HS.get('images', { per: ctx.per, paged: ctx.page, type: ctx.type }).then(function (res) {
					res = res || {};
					var rows = res.rows || [];
					var s = res.summary || res;
					statHost.replaceChildren(
						kpi('کل تصویرها', s.total || s.attachments), kpi('بدون alt', s.missing_alt !== undefined ? s.missing_alt : rows.length),
						kpi('سنگین‌تر از حد', s.large || 0), kpi('بدون ابعاد', s.no_dims || 0)
					);
					if (!rows.length) { grid.innerHTML = ''; grid.appendChild(HS.empty('تصویر مشکل‌داری نیست ✓', 'همه تصویرها متن جایگزین و ابعاد دارند.', true)); return; }
					rows.forEach(function (r) {
						var cell = h('figure', { class: 'hs-imgcell' });
						cell.appendChild(h('img', { src: r.url || r.thumbnail || '', alt: '', loading: 'lazy' }));
						var b = h('div', { class: 'hs-imgcell__b' });
						b.appendChild(h('label', { class: 'hs-row', style: { gap: '6px' } }, [h('input', { type: 'checkbox', class: 'hs-check', dataset: { id: r.id }, value: r.id }), h('span', { class: 'hs-imgcell__t', text: trunc(r.title || r.file || '', 26) })]));
						var inp = h('input', { class: 'hs-input', placeholder: 'متن جایگزین…', value: r.alt || '' });
						b.appendChild(inp);
						var acts = h('div', { class: 'hs-row' });
						if (r.suggested) { acts.appendChild(HS.btn('استفاده از پیشنهاد', { sm: true, kind: 'ghost', run: function () { inp.value = r.suggested; } })); }
						acts.appendChild(HS.btn('ذخیره', { sm: true, kind: 'primary', run: function () {
							return HS.post('images/fill', { ids: [r.id], alt: inp.value }).then(function () { HS.toast('ذخیره شد', 'ok', { ms: 1200 }); }, HS.fail);
						} }));
						if (r.file) { acts.appendChild(HS.btn('تغییر نام', { sm: true, kind: 'ghost', run: function () { return HS.post('images/rename', { id: r.id, slug: HS.sget('images.rename_lang', 'fa') === 'fa' ? (r.title || 'image') : (r.title || 'image') }).then(function () { HS.toast('نام فایل به‌روز شد', 'ok'); }, HS.fail); } })); }
						b.appendChild(acts);
						b.appendChild(h('span', { class: 'hs-muted hs-small', text: (r.width ? num(r.width) + '×' + num(r.height) + ' · ' : '') + bytes(r.size_kb ? r.size_kb * 1024 : 0) }));
						cell.appendChild(b);
						grid.appendChild(cell);
					});
					var bar = h('div', { class: 'hs-pager' }, [pager(ctx, res.total || 0, ctx.per, load)]);
					grid.parentNode.appendChild(bar);
				}, function (e) { grid.innerHTML = ''; grid.appendChild(HS.note('bad', 'کتابخانه تصویر خوانده نشد', e.message)); });
			}
			function kpi(l, v) { return h('div', { class: 'hs-kpi' }, [h('div', { class: 'hs-kpi__v', text: num(v || 0) }), h('div', { class: 'hs-kpi__l', text: l })]); }
			function trunc(s, n) { return HS.trunc(s, n); }
			function bytes(b) { return HS.bytes(b); }
			return load();
		}
	});

	/* ---------- audit / changes ---------- */
	HS.view('audit', {
		title: 'ممیزی و تغییرات',
		sub: 'هر چیزی که افزونه یا شما تغییر داده‌اید — با بازگردانی',
		render: function (host, params) {
			var ctx = { page: 1, per: 40, scope: params.scope || '', search: '', action: '', severity: params.severity || '' };
			var listHost = h('div');
			host.appendChild(HS.card({
				title: 'گزارش تغییرات', icon: 'stethoscope',
				actions: [
					HS.btn('تنظیمات نادیده‌گرفتن', { sm: true, kind: 'ghost', icon: 'filter', run: ignoreRules }),
					HS.btn(HS.I18N.export, { sm: true, kind: 'ghost', icon: 'download', href: HS.exportUrl('audit') }),
					HS.btn('فایل‌ها', { sm: true, kind: 'ghost', icon: 'document', run: filesPanel })
				]
			}, [listHost]));
			function load() {
				listHost.innerHTML = '';
				listHost.appendChild(HS.skeleton(6));
				HS.get('audit', { scope: ctx.scope, action: ctx.action, search: ctx.search, per: ctx.per, paged: ctx.page }).then(function (res) {
					res = res || {};
					var rows = res.rows || [];
					var scopes = res.scopes || {};
					var bar = h('div', { class: 'hs-row' }, [
						HS.select([{ id: '', label: 'همه حوزه‌ها' }].concat(HS.entries(scopes).map(function (p) { return { id: p[0], label: HS.labelOf(p[1], p[0]) + (isFinite(p[1]) ? ' (' + num(p[1]) + ')' : '') }; })), ctx.scope, function (v) { ctx.scope = v; ctx.page = 1; load(); }),
						(function () { var i = h('input', { class: 'hs-input', type: 'search', placeholder: 'جست‌وجو…', value: ctx.search, style: { maxWidth: '240px' } }); var t; i.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { ctx.search = i.value; ctx.page = 1; load(); }, 400); }); return i; })(),
						h('span', { class: 'hs-muted hs-small', text: num(res.total || 0) + ' ' + HS.I18N.results })
					]);
					var cols = [
						{ key: 'created_at', label: 'زمان', width: '150px', render: function (r) { return '<span class="hs-small">' + esc(HS.faDate(r.created_at, true)) + '</span>'; } },
						{ key: 'scope', label: 'حوزه', width: '110px', render: function (r) { return '<span class="hs-tag">' + esc(r.scope || '') + '</span>'; } },
						{ key: 'action', label: 'کار', width: '100px', render: function (r) { return esc(r.action || ''); } },
						{ key: 'label', label: 'شرح', render: function (r) { return '<b>' + esc(r.label || r.note || '—') + '</b>' + (r.url ? '<div class="hs-muted hs-small">' + esc(trunc(r.url, 70)) + '</div>' : ''); } },
						{ key: 'user_id', label: 'توسط', width: '100px', render: function (r) { return '<span class="hs-muted hs-small">' + (r.user_login ? esc(r.user_login) : (r.user_id ? ('کاربر ' + num(r.user_id)) : 'سیستم')) + '</span>'; } },
						{ key: '_a', label: '', width: '200px', render: function (r) {
							return '<div class="hs-row-actions"><button class="hs-btn hs-btn--sm hs-btn--ghost" data-a="diff" data-id="' + esc(r.id) + '">تفاوت</button>' +
								(r.reverted ? '<span class="hs-chip">بازگردانی شده</span>' : '<button class="hs-btn hs-btn--sm" data-a="revert" data-id="' + esc(r.id) + '">بازگردانی</button>') + '</div>';
						} }
					];
					listHost.innerHTML = '';
					listHost.appendChild(bar);
					var t2 = HS.table({ columns: cols, rows: rows, footer: pager(ctx, res.total || 0, ctx.per, load), emptyTitle: 'تغییری ثبت نشده', emptyHint: 'وقتی چیزی را تغییر دهید، اینجا با جزئیات قبل/بعد ثبت می‌شود.' });
					on(t2, 'click', '[data-a]', function (e, el) {
						e.preventDefault();
						var id = parseInt(el.dataset.id, 10);
						var row = rows.filter(function (r) { return String(r.id) === String(id); })[0] || {};
						if (el.dataset.a === 'diff') {
							HS.modal({ title: 'تفاوت', size: 'md', body: h('div', { class: 'hs-cols hs-cols--2' }, [
								h('div', { class: 'hs-col' }, [h('b', { text: 'پیش از' }), h('pre', { class: 'hs-code', text: pretty(row.before_data || row.before) })]),
								h('div', { class: 'hs-col' }, [h('b', { text: 'پس از' }), h('pre', { class: 'hs-code', text: pretty(row.after_data || row.after) })])
							]), actions: [{ label: HS.I18N.close }] });
							return;
						}
						HS.confirmBox('بازگردانی', 'مقدار قبلی بازگردانده شود؟', function () {
							return HS.post('audit/revert', { id: id }).then(function (r) { HS.toast((r && r.message) || 'بازگردانی شد', 'ok'); HS.cacheClear('audit'); load(); }, HS.fail);
						});
					});
					listHost.appendChild(t2);
				}, function (e) { listHost.innerHTML = ''; listHost.appendChild(HS.note('bad', 'گزارش تغییرات در دسترس نیست', e.message)); });
			}
			function pretty(v) {
				if (!v) { return '—'; }
				if (typeof v === 'string') { try { v = JSON.parse(v); } catch (e) { return v; } }
				return JSON.stringify(v, null, 2);
			}
			function ignoreRules() {
				var ta = h('textarea', { class: 'hs-input hs-textarea hs-textarea--code', rows: 8 });
				ta.value = (HS.sget('audit.ignore') || []).join('\n');
				HS.modal({
					title: 'نادیده‌گرفتن', size: 'sm',
					body: h('div', { class: 'hs-col' }, [h('p', { class: 'hs-muted hs-small', text: 'هر خط یک الگوی مسیر یا نام فایل؛ این موارد در ممیزی فنی و خودکارسازی نادیده گرفته می‌شوند.' }), ta]),
					actions: [{ label: HS.I18N.cancel }, { label: HS.I18N.save, kind: 'primary', run: function () {
						var rules = ta.value.split(/\r?\n/).map(function (x) { return x.trim(); }).filter(Boolean);
						return HS.post('audit/ignore', { rules: rules }).then(function () { HS.toast('ثبت شد', 'ok'); }, HS.fail);
					} }]
				});
			}
			function filesPanel() {
				HS.get('audit/files').then(function (r) {
					r = r || {};
					var rows = r.rows || r.diff || r.files || [];
					HS.modal({
						title: 'تغییرات فایل‌ها', size: 'md',
						body: h('div', { class: 'hs-col' }, [
							HS.note(null, 'تغییر فایل‌های هسته و افزونه‌ها را نشان می‌دهد. برای ساخت نقطه مرجع دوباره کلیک کنید.'),
							Array.isArray(rows) && rows.length ? HS.table({ columns: [{ key: 'path', label: 'فایل' }, { key: 'status', label: 'وضعیت', width: '110px' }, { key: 'mtime', label: 'تغییر', width: '140px' }], rows: rows }) : HS.empty('تغییری در فایل‌ها نیست', null, true)
						]),
						actions: [{ label: HS.I18N.cancel }, { label: 'ساخت نقطه مرجع', kind: 'primary', run: function () { return HS.post('audit/files/baseline', {}).then(function (r2) { HS.toast((r2 && r2.message) || 'نقطه مرجع ساخته شد', 'ok'); }, HS.fail); } }]
					});
				}, HS.fail);
			}
			HS.revertChange = function (row) {
				return HS.confirmBox('بازگردانی', 'این تغییر به حالت قبل برگردد؟', function () {
					return HS.post('audit/revert', { id: row.id }).then(function () { HS.toast('بازگردانی شد', 'ok'); HS.cacheClear('audit'); }, HS.fail);
				});
			};
			return load();
		}
	});
	function trunc(s, n) { return HS.trunc(s, n); }
})(HS);

/* =================== storefront, GEO, analytics, social, speed, autopilot =================== */
(function (HS) {
	'use strict';
	var h = HS.h, qs = HS.qs, on = HS.on, num = HS.num, esc = HS.esc, C = HS.C, safe = HS.safe;

	/* ---------- woo ---------- */
	HS.view('woo', {
		title: 'سئوی فروشگاه',
		sub: 'داده محصول، GTIN/برند، اسکیما و ایندکس صفحه‌های سبد خرید',
		render: function (host) {
			host.appendChild(HS.skeleton(3));
			return HS.get('woo').then(function (res) {
				host.innerHTML = '';
				res = res || {};
				if (res.available === false) {
					host.appendChild(HS.card({ title: 'WooCommerce نصب نیست', icon: 'cart' }, [HS.note('warn', 'این بخش وقتی فعال می‌شود که ووکامرس نصب باشد.', '')]));
					return;
				}
				var cat = res.catalog || {};
				var cols = [
					{ key: 'label', label: 'بررسی', render: function (r) { return '<b>' + esc(r.label) + '</b>'; } },
					{ key: 'state', label: 'وضعیت', width: '110px', render: function (r) { return (r.state === 'ok' || !r.count) ? HS.chip('سالم', 'ok', { dot: false }).outerHTML : HS.chip('نیازمند بررسی', 'warn', { dot: false }).outerHTML; } },
					{ key: 'count', label: 'تعداد', align: 'num', width: '100px', render: function (r) { return num(r.count || 0); } },
					{ key: '_a', label: '', width: '190px', render: function (r) {
						return r.count ? '<button class="hs-btn hs-btn--sm" data-fix="' + esc(r.key) + '">رفع خودکار</button>' : '';
					} }
				];
				var rows = HS.entries(cat).map(function (p) {
					var v = p[1] || {};
					return { key: p[0], label: v.label || p[0], count: v.count || 0, state: v.state || (v.count ? 'warn' : 'ok') };
				});
				host.appendChild(HS.card({
					title: 'سلامت کاتالوگ', icon: 'cart', sub: num(res.products || 0) + ' محصول',
					actions: [
						HS.btn('رفع همه', { kind: 'primary', sm: true, icon: 'wand', run: function () { return fixAll(rows.filter(function (r) { return r.count; }).map(function (r) { return r.key; })); } }),
						HS.btn('تنظیمات ووکامرس', { sm: true, kind: 'ghost', icon: 'settings', run: function () { HS.go('settings', { group: 'woo' }); } })
					]
				}, [
					HS.kpis([
						{ label: 'محصول', value: num(res.products || 0) },
						{ label: 'بدون GTIN', value: num(res.no_gtin || 0) },
						{ label: 'بدون برند', value: num(res.no_brand || 0) },
						{ label: 'بدون توضیح کوتاه', value: num(res.no_short || 0) },
						{ label: 'بدون تصویر', value: num(res.no_image || 0) },
						{ label: 'بدون alt', value: num(res.no_alt || 0) }
					]),
					HS.table({ columns: cols, rows: rows, emptyTitle: 'کاتالوگ سالم است ✓' }),
					HS.note(null, 'برچسب برند: ' + (res.brand_taxonomy || 'نامشخص') + ' — اگر بخواهید، یک تاکسونومی برند می‌سازیم و در اسکیما Product قرار می‌دهیم.')
				]));
				function fixAll(keys) {
					if (!keys.length) { HS.toast('چیزی برای رفع نیست', 'ok'); return Promise.resolve(); }
					return Promise.all(keys.map(function (k) { return HS.post('woo/fix', { action: k, ids: [] }).then(function (r) { return r; }, function () { return null; }); })).then(function (rs) {
						var n = rs.filter(Boolean).reduce(function (a, r) { return a + (parseInt(r.changed || r.count || 0, 10) || 0); }, 0);
						HS.toast(num(n) + ' محصول به‌روزرسانی شد', 'ok');
						HS.cacheClear('woo');
						HS.renderRoute();
					});
				}
				var t = qs('.hs-table');
				if (t) {
					on(t, 'click', '[data-fix]', function (e, el) { fixAll([el.dataset.fix]); });
				}
			});
		}
	});

	/* ---------- GEO / answer engines ---------- */
	HS.view('ai-search', {
		title: 'موتورهای پاسخ (GEO)',
		sub: 'خودتان را به ChatGPT، Perplexity و Google AI Show بگویید',
		render: function (host) {
			host.appendChild(HS.skeleton(3));
			return Promise.all([safeGet('geo'), safeGet('settings')]).then(function (r) {
				var geo = r[0] || {};
				if (r[1] && r[1].settings) { HS.state.settings = r[1].settings; }
				host.innerHTML = '';
				var tg = geo.toggles || {};
				var toggles = [
					['geo.llms_txt', 'llms.txt', 'فایل ساده‌ای که به مدل‌ها می‌گوید سایت شما چیست و کدام صفحه‌ها مهم‌اند.'],
					['geo.llms_full_txt', 'llms-full.txt', 'نسخه کامل با خلاصه هر صفحه؛ برای مدل‌هایی که فایل بزرگ می‌خواهند.'],
					['geo.answer_box', 'جعبه پاسخ', 'پاسخ ۴۰ تا ۶۰ کلمه‌ای در بالای صفحه، که مدل‌ها عیناً نقل می‌کنند.'],
					['geo.answer_auto', 'تولید خودکار پاسخ', 'اگر پاسخ دستی ننویسید، با هوش مصنوعی ساخته شود.'],
					['geo.speakable', 'Speakable', 'برای دستیارهای صوتی؛ نشانه‌گذاری بخش خواندنی.'],
					['geo.ai_visible', 'دیده‌شدن در خلاصه‌سازها', 'اجازه به موتورهای AI برای استفاده از محتوا.'],
					['geo.crawler_brief', 'راهنمای خزنده', 'خلاصه ساختار سایت و موضوع‌های اصلی برای ربات‌ها.'],
					['geo.faq_auto', 'FAQ خودکار', 'ساخت پرسش‌های متداول از محتوای صفحه برای اسکیما.'],
					['geo.schema_graph', 'گراف اسکیما', 'فرستادن JSON-LD کامل همراه با خلاصه.'],
					['geo.amp_like', 'خلاصه در ابتدای صفحه', 'نمایش خلاصه زیر عنوان (قابل تنظیم با پوسته).']
				];
				var box = h('div', { class: 'hs-srows' });
				toggles.forEach(function (t2) {
					if (t2[0] === 'geo.amp_like') { return; }
					var val = tg[t2[0].split('.')[1]] !== undefined ? !!tg[t2[0].split('.')[1]] : !!HS.sget(t2[0], false);
					var row = h('div', { class: 'hs-srow' });
					row.appendChild(h('div', { class: 'hs-srow__main' }, [h('div', { class: 'hs-srow__t', text: t2[1] }), h('div', { class: 'hs-srow__d', text: t2[2] })]));
					row.appendChild(h('div', { class: 'hs-srow__ctl' }, [HS.switchEl(val, function (v) { HS.markDirty(t2[0], v); }, { title: 'ذخیره با دکمه ذخیره' })]));
					box.appendChild(row);
				});
				host.appendChild(HS.card({
					title: 'قابلیت‌ها', icon: 'sparkle',
					actions: [HS.btn(HS.I18N.save, { kind: 'primary', sm: true, icon: 'check', run: function () { return HS.flushDirty().then(function () { HS.renderRoute(); }); } })]
				}, [box]));

				var intro = h('textarea', { class: 'hs-input hs-textarea', rows: 4 });
				intro.value = HS.sget('geo.ai_intro', '') || '';
				var prompt = h('textarea', { class: 'hs-input hs-textarea', rows: 3 });
				prompt.value = HS.sget('geo.summary_prompt', '') || '';
				var count = h('input', { class: 'hs-input', type: 'number', min: 1, max: 200, value: HS.sget('geo.pages_count', 20) });
				intro.addEventListener('change', function () { HS.markDirty('geo.ai_intro', intro.value); });
				prompt.addEventListener('change', function () { HS.markDirty('geo.summary_prompt', prompt.value); });
				count.addEventListener('change', function () { HS.markDirty('geo.pages_count', parseInt(count.value, 10)); });
				host.appendChild(HS.card({ title: 'متن معرفی و دستور خلاصه‌سازی', icon: 'text', actions: [HS.btn(HS.I18N.save, { kind: 'primary', sm: true, run: function () { return HS.flushDirty(); } })] }, [
					HS.fieldWrap({ key: 'ai_intro', label: 'معرفی سایت در llms.txt', help: 'دو تا چهار جمله: که هستید، برای چه کسی، و چه چیزی شما را متمایز می‌کند.' }, intro),
					HS.fieldWrap({ key: 'summary_prompt', label: 'دستور ساخت خلاصه صفحه' }, prompt),
					HS.fieldWrap({ key: 'pages_count', label: 'تعداد صفحه در llms-full.txt' }, count)
				]));

				var checks = [
					{ label: '/llms.txt', url: geo.url || (C.site.home + 'llms.txt'), reachable: geo.reachable, bytes: geo.bytes, lines: geo.lines },
					{ label: '/llms-full.txt', url: (geo.full && geo.full.url) || (C.site.home + 'llms-full.txt'), reachable: geo.full ? geo.full.reachable : undefined },
					{ label: '/robots.txt', url: C.site.home + 'robots.txt', reachable: true }
				];
				host.appendChild(HS.card({ title: 'آنچه مدل‌ها می‌بینند', icon: 'eye', actions: [
					HS.btn('بازبینی', { sm: true, kind: 'ghost', icon: 'refresh', run: function () { HS.cacheClear('geo'); HS.renderRoute(); } }),
					HS.btn('کپی llms.txt', { sm: true, icon: 'copy', run: function () { return safeGet('geo').then(function (r) { HS.copyText((r && (r.short && r.short.content) || r.content || '')); }); } })
				] }, [
					HS.kpis([
						{ label: 'حجم llms.txt', value: HS.bytes(geo.bytes || 0) },
						{ label: 'خط', value: num(geo.lines || 0) },
						{ label: 'دسترسی', value: geo.reachable === false ? 'بسته' : 'باز' },
						{ label: 'خزنده‌های AI اخیر', value: num((geo.crawlers && (geo.crawlers.total || geo.crawlers.length)) || 0) }
					]),
					(function () {
						var w = h('div', { class: 'hs-cols hs-cols--2' });
						checks.forEach(function (c) {
							w.appendChild(h('div', { class: 'hs-srow' }, [
								h('div', { class: 'hs-srow__main' }, [h('div', { class: 'hs-srow__t', text: c.label }), h('div', { class: 'hs-srow__d hs-mono', text: c.url })]),
								h('div', { class: 'hs-srow__ctl' }, [c.reachable === false ? HS.chip('دسترس نیست', 'bad', { dot: false }) : HS.chip('آماده', 'ok', { dot: false }), h('a', { class: 'hs-btn hs-btn--sm hs-btn--ghost', href: c.url, target: '_blank', rel: 'noopener', text: 'باز کردن' })])
							]));
						});
						return w;
					})(),
					(geo.short && geo.short.content) ? h('pre', { class: 'hs-code', text: String(geo.short.content).slice(0, 4000) }) : null
				]));

				var crawlers = geo.crawlers && (geo.crawlers.rows || geo.crawlers.list);
				if (Array.isArray(crawlers) && crawlers.length) {
					host.appendChild(HS.card({ title: 'خزنده‌های هوش مصنوعی اخیر', icon: 'bug' }, [
						HS.bars(crawlers.slice(0, 10).map(function (x) { return { label: x.bot_name || x.name || x.key, value: x.count || x.visits || 0 }; }))
					]));
				}
			});
			function safeGet(p) { return HS.get(p, {}, { force: true }).then(function (v) { return v; }, function () { return null; }); }
		}
	});

	/* ---------- search console + analytics ---------- */
	HS.view('search-console', {
		title: 'سرچ کنسول و آنالیتیکس',
		sub: 'کلیک، بازدید، پرس‌وجوها و سرعت واقعی صفحه',
		render: function (host, params) {
			var days = parseInt(params.days, 10) || 28;
			var view = params.view || 'overview';
			var wrap = h('div', { class: 'hs-col' });
			host.appendChild(wrap);
			wrap.appendChild(h('div', { class: 'hs-loading', text: HS.I18N.loading }));
			HS.get('analytics', { days: days, view: view === 'queries' || view === 'pages' || view === 'alerts' ? view : '', search: params.q || '', sort: params.sort || 'clicks' }).then(function (res) {
				wrap.innerHTML = '';
				res = res || {};
				var setup = res.status || {};
				var bar = h('div', { class: 'hs-row hs-row--between' });
				bar.appendChild(HS.segmented([
					{ value: 7, label: '۷ روز' }, { value: 28, label: '۲۸ روز' }, { value: 90, label: '۹۰ روز' }, { value: 365, label: 'یک سال' }
				], days, function (v) { HS.go('search-console', { days: v, view: view }); }));
				bar.appendChild(h('div', { class: 'hs-row' }, [
					HS.btn('نوسازی داده', { sm: true, icon: 'refresh', run: function () { return HS.post('analytics/refresh', { days: days }).then(function () { HS.cacheClear('analytics'); HS.renderRoute(); HS.toast('داده‌ها تازه شد', 'ok'); }, HS.fail); } }),
					HS.btn('درون‌ریزی CSV', { sm: true, icon: 'upload', run: importCsv }),
					HS.btn('اتصال Search Console', { sm: true, kind: setup.connected || setup.gsc ? 'ghost' : 'primary', icon: 'external', href: (res.setup && res.setup.connect_url) || '#', run: null })
				]));
				wrap.appendChild(HS.card({ title: 'داده‌های جست‌وجو', icon: 'chart', sub: res.source || '' }, [bar]));

				if (res.available === false) {
					wrap.appendChild(HS.card({ title: 'هنوز داده‌ای وصل نشده', icon: 'info' }, [
						HS.note('warn', 'یا حساب Search Console را وصل کنید، یا فایل CSV خروجی‌گرفته‌شده را درون‌ریزی کنید.', 'اتصال با OAuth یا کلید سرویس انجام می‌شود؛ هر دو مسیر در همین صفحه است.'),
						h('div', { class: 'hs-row' }, [HS.btn('درون‌ریزی CSV', { kind: 'primary', run: importCsv }), HS.btn('تنظیمات آنالیتیکس', { run: function () { HS.go('settings', { group: 'analytics' }); } })])
					]));
					return;
				}
				wrap.appendChild(HS.kpis([
					{ label: 'کلیک', value: num(res.clicks || 0), delta: res.delta && res.delta.clicks },
					{ label: 'بازدید', value: num(res.impressions || 0), delta: res.delta && res.delta.impressions },
					{ label: 'نرخ کلیک', value: HS.pct((parseFloat(res.ctr) || 0) * (parseFloat(res.ctr) < 1 ? 100 : 1)) },
					{ label: 'میانگین رتبه', value: num(res.position_avg || res.position || 0, 1) },
					{ label: 'منبع', value: res.source || 'gsc', plain: true }
				]));
				var labels = res.labels || [];
				var pts = (res.series || []).map(function (p) { return typeof p === 'object' ? (p.clicks !== undefined ? p.clicks : p.value) : p; });
				if (pts.length) {
					wrap.appendChild(HS.card({ title: 'روند کلیک', icon: 'trend' }, [HS.lineChart([{ label: 'کلیک', values: pts, labels: labels }], { height: 200 })]));
				}
				if (view === 'queries' || view === 'pages') {
					var rows = res.rows || [];
					var cols = view === 'queries'
						? [{ key: 'query', label: 'پرس‌وجو', render: function (r) { return '<b>' + esc(r.query || r.label || '') + '</b>'; } }, { key: 'clicks', label: 'کلیک', align: 'num' }, { key: 'impressions', label: 'بازدید', align: 'num' }, { key: 'ctr', label: 'نرخ', align: 'num', render: function (r) { return HS.pct((parseFloat(r.ctr) || 0) * (r.ctr < 1 ? 100 : 1)); } }, { key: 'position', label: 'رتبه', align: 'num' }]
						: [{ key: 'page', label: 'صفحه', render: function (r) { return '<span class="hs-mono">' + esc(r.page || r.url || '') + '</span>'; } }, { key: 'clicks', label: 'کلیک', align: 'num' }, { key: 'impressions', label: 'بازدید', align: 'num' }, { key: 'position', label: 'رتبه', align: 'num' }];
					wrap.appendChild(HS.card({ title: view === 'queries' ? 'پرس‌وجوهای پرتکرار' : 'صفحه‌های پربازدید', icon: 'list', flush: true }, [HS.table({ columns: cols, rows: rows, emptyTitle: 'داده‌ای نیست' })]));
				} else if (view === 'alerts') {
					var al = res.alerts || [];
					var alList = h('div', { class: 'hs-list' });
					al.forEach(function (a) {
						alList.appendChild(h('div', { class: 'hs-list__item' }, [
							h('span', { class: 'hs-dot is-' + ((a.level === 'bad' || a.level === 'critical') ? 'bad' : 'warn') }),
							h('div', { class: 'hs-list__main' }, [
								h('div', { class: 'hs-list__title', text: a.title || a.label || '' }),
								h('div', { class: 'hs-list__meta', text: a.message || a.detail || '' })
							])
						]));
					});
					wrap.appendChild(HS.card({ title: 'هشدارها', icon: 'warning' }, [al.length ? alList : HS.empty('هشدار فعالی نیست ✓', 'کاهش ناگهانی کلیک یا رتبه، اینجا اعلام می‌شود.')]));
				} else {
					var tabsEl = HS.tabs([
						{ id: 'overview', label: 'نمای کلی' },
						{ id: 'queries', label: 'پرس‌وجوها', count: (res.queries || []).length },
						{ id: 'pages', label: 'صفحه‌ها', count: (res.pages || []).length },
						{ id: 'alerts', label: 'هشدارها', count: (res.alerts || []).length }
					], view, function (id) { HS.go('search-console', { days: days, view: id }); });
					wrap.insertBefore(tabsEl, wrap.children[1] || null);
					var two = h('div', { class: 'hs-cols hs-cols--2' });
					two.appendChild(HS.card({ title: 'بهترین پرس‌وجوها', icon: 'search' }, [HS.bars((res.queries || []).slice(0, 10).map(function (q) { return { label: q.query || q.label, value: q.clicks || q.value || 0 }; }))]));
					two.appendChild(HS.card({ title: 'پربازدیدترین صفحه‌ها', icon: 'document' }, [HS.bars((res.pages || []).slice(0, 10).map(function (q) { return { label: trunc(q.page || q.url || q.label, 42), value: q.clicks || q.value || 0, href: q.url }; }))]));
					wrap.appendChild(two);
					if (res.devices && Object.keys(res.devices).length) {
						wrap.appendChild(HS.card({ title: 'دستگاه و کشور', icon: 'user' }, [h('div', { class: 'hs-cols hs-cols--2' }, [
							HS.bars(HS.entries(res.devices).slice(0, 6).map(function (p) { return { label: HS.labelOf(p[1], p[0]), value: isObj(p[1]) ? (p[1].clicks || 0) : p[1] }; })),
							HS.bars(HS.entries(res.countries || {}).slice(0, 6).map(function (p) { return { label: HS.labelOf(p[1], p[0]), value: isObj(p[1]) ? (p[1].clicks || 0) : p[1] }; }))
						])]));
					}
					var psiHost = h('div', { class: 'hs-muted hs-small', text: 'هنوز اجرا نشده — یک تست بزنید.' });
					var psi = function () {
						psiHost.innerHTML = '';
						psiHost.appendChild(h('div', { class: 'hs-loading', text: 'در حال ارسال به PageSpeed…' }));
						return HS.get('analytics/psi', { limit: 6 }).then(function (r) {
							psiHost.innerHTML = '';
							var rows = (r && (r.rows || r.pages)) || [];
							if (!rows.length) { psiHost.appendChild(HS.empty('نتیجه‌ای نبود', 'ممکن است کلید API یا دسترسی محدود باشد.')); return; }
							psiHost.appendChild(HS.table({ columns: [
								{ key: 'url', label: 'صفحه', render: function (x) { return '<span class="hs-mono">' + esc(trunc(x.url, 60)) + '</span>'; } },
								{ key: 'score', label: 'موبایل', align: 'num', width: '90px', render: function (x) { return '<span class="hs-chip ' + (x.mobile >= 80 ? 'hs-chip--ok' : x.mobile >= 50 ? 'hs-chip--warn' : 'hs-chip--bad') + '">' + num(x.mobile || 0) + '</span>'; } },
								{ key: 'desktop', label: 'دسکتاپ', align: 'num', width: '90px', render: function (x) { return num(x.desktop || 0); } },
								{ key: 'lcp', label: 'LCP', align: 'num', width: '90px', render: function (x) { return num(x.lcp || 0, 1) + 's'; } }
							], rows: rows }));
						}, function (e) { psiHost.innerHTML = ''; psiHost.appendChild(HS.note('bad', 'تست انجام نشد', e.message)); });
					}
					wrap.appendChild(HS.card({ title: 'سرعت صفحه (PageSpeed)', icon: 'bolt', actions: [HS.btn('تست چند صفحه', { sm: true, run: psi })] }, [psiHost]));
				}
				function importCsv() {
					var ta = h('textarea', { class: 'hs-input hs-textarea hs-textarea--code', rows: 8, placeholder: 'date,query,clicks,impressions,position\n2026-09-01,کفش مردانه,120,4200,8.4' });
					HS.modal({
					title: 'درون‌ریزی CSV', size: 'md', body: h('div', { class: 'hs-col' }, [HS.note(null, 'فایل خروجی Search Console (Performance → Export) را اینجا بچسبانید؛ ستون‌ها به‌هرحال تطبیق داده می‌شوند.'), ta]),
						actions: [{ label: HS.I18N.cancel }, { label: 'درون‌ریزی', kind: 'primary', run: function () {
							return HS.post('analytics/import', { content: ta.value, days: days }).then(function (r) {
								HS.toast((r && r.message) || (num((r && r.rows) || 0) + ' سطر خوانده شد'), 'ok');
								HS.cacheClear('analytics'); HS.renderRoute();
							}, HS.fail);
						} }]
					});
				}
			}, function (e) { wrap.innerHTML = ''; wrap.appendChild(HS.note('bad', 'داده‌های آنالیتیکس خوانده نشد', e.message)); });
		}
	});
	function isObj(v) { return v && typeof v === 'object'; }
	function trunc(s, n) { return HS.trunc(s, n); }

	/* ---------- social ---------- */
	HS.view('social', {
		title: 'شبکه‌های اجتماعی',
		sub: 'Open Graph، کارت توییتر و تصویر اشتراک‌گذاری',
		render: function (host) {
			var keys = [
				['opengraph.enabled', 'Open Graph', 'کارت لینک برای واتساپ، تلگرام، لینکدین و فیس بوک.'],
				['opengraph.twitter', 'کارت توییتر/X', 'نمایش عنوان و تصویر در X.'],
				['opengraph.generate_alt', 'ساخت توضیح از متن تصویر', 'اگر توضیحات خالی باشد از alt استفاده می‌شود.'],
				['opengraph.show_price', 'نمایش قیمت در کارت محصول', 'برای فروشگاه‌ها.'],
				['opengraph.sitename_prefix', 'پیشوند نام سایت در عنوان', 'اگر قالب عنوان خودش نام سایت را دارد، خاموشش کنید.']
			];
			var box = h('div', { class: 'hs-srows' });
			keys.forEach(function (k) {
				var row = h('div', { class: 'hs-srow' });
				row.appendChild(h('div', { class: 'hs-srow__main' }, [h('div', { class: 'hs-srow__t', text: k[1] }), h('div', { class: 'hs-srow__d', text: k[2] })]));
				row.appendChild(h('div', { class: 'hs-srow__ctl' }, [HS.switchEl(!!HS.sget(k[0], true), function (v) { HS.markDirty(k[0], v); })]));
				box.appendChild(row);
			});
			var f = h('div', { class: 'hs-form' });
			[['general.twitter_site', 'نام کاربری X', '@hooshseo'], ['general.facebook_app_id', 'App ID فیس‌بوک', ''], ['opengraph.default_type', 'نوع پیش‌فرض', 'website'], ['opengraph.price_currency', 'واحد قیمت', 'IRR'], ['general.twitter_card', 'نوع کارت', 'summary_large_image']]
				.forEach(function (x) {
					var fd = { key: x[0], label: x[1], placeholder: x[2] };
					var val = HS.sget(x[0], '');
					f.appendChild(HS.fieldWrap(fd, HS.input(fd, val, function (v) { HS.markDirty(x[0], v); })));
				});
			host.appendChild(HS.card({
				title: 'کارت اشتراک‌گذاری', icon: 'share',
				actions: [HS.btn(HS.I18N.save, { kind: 'primary', sm: true, run: function () { return HS.flushDirty().then(function () { HS.renderRoute(); }); } })]
			}, [box, f, h('div', { class: 'hs-row hs-row--between' }, [
				h('span', { class: 'hs-muted hs-small', text: 'پیش‌نمایش پایین، همان چیزی است که شبکه‌ها می‌بینند.' }),
				HS.btn('تازه کردن پیش‌نمایش', { sm: true, icon: 'refresh', run: function () { paint(); } })
			]), prevHost]));
			var prevHost = h('div', { class: 'hs-preview' });
			paint();
			function paint() {
				HS.get('snippet', { device: 'desktop' }, { force: true }).then(function (r) {
					var s = (r && r.desktop) || {};
					prevHost.innerHTML = '';
					var card2 = h('div', { style: { maxWidth: '480px', border: '1px solid var(--line)', borderRadius: '12px', overflow: 'hidden' } });
					card2.appendChild(h('div', { style: { height: '150px', background: 'var(--surface-2)', display: 'grid', placeItems: 'center', color: 'var(--mut)', fontSize: '12px' }, text: s.image ? '' : 'تصویر اشتراک‌گذاری' }));
					if (s.image) { card2.firstChild.style.backgroundImage = 'url(' + JSON.stringify(s.image) + ')'; card2.firstChild.style.backgroundSize = 'cover'; }
					var body = h('div', { style: { padding: '10px 12px' } }, [
						h('div', { class: 'hs-muted hs-small', text: (s.host || C.site.home).replace(/^https?:\/\//, '') }),
						h('div', { style: { fontWeight: '600', fontSize: '14px' }, text: s.title || '(عنوان صفحه)' }),
						h('div', { class: 'hs-muted hs-small', text: HS.trunc(s.description || HS.sget('general.site_name', ''), 160) })
					]);
					card2.appendChild(body);
					prevHost.appendChild(card2);
				}, function () { prevHost.innerHTML = '<div class="hs-muted hs-small">پیش‌نمایش در دسترس نیست.</div>'; });
			}
			return Promise.resolve();
		}
	});

	/* ---------- speed ---------- */
	HS.view('speed', {
		title: 'سرعت و Core Web Vitals',
		sub: 'تیک‌های بی‌خطر، کش، به‌هنگام‌سازی دیررس اسکریپت‌ها و سرنوشت فایل‌های added',
		render: function (host, params) {
			host.appendChild(HS.skeleton(4));
			return HS.get('speed', {}, { force: true }).then(function (res) {
				host.innerHTML = '';
				res = res || {};
				var rows = res.rows || [];
				var saved = res.savings || 0, possible = res.possible || 0;
				var head = h('div', { class: 'hs-row' }, [
					HS.donut(res.score || 0, 'امتیاز سرعت'),
					h('div', { class: 'hs-col' }, [
						h('b', { text: 'پس‌اندوز تخمینی: ' + HS.bytes(saved) + ' از ' + HS.bytes(possible) }),
						h('span', { class: 'hs-muted hs-small', text: (res.enabled ? 'افزونه سرعت فعال است' : 'افزونه سرعت خاموش است — از تنظیمات فعالش کنید') + (res.psi && res.psi.score ? (' · PageSpeed موبایل ' + num(res.psi.score) + '، دسکتاپ ' + num(res.psi.desktop_score || 0)) : '') })
					]),
					h('div', { class: 'hs-row hs-right' }, [
						HS.btn('اعمال همه تیک‌های بی‌خطر', { kind: 'primary', icon: 'bolt', run: function () { return run('safe'); } }),
						HS.btn('اعمال همه (پیشرفته)', { icon: 'wand', run: function () { return run('all'); } }),
						HS.btn('تنظیمات سرعت', { kind: 'ghost', icon: 'settings', run: function () { HS.go('settings', { group: 'speed' }); } })
					])
				]);
				host.appendChild(HS.card({ title: 'اقدام‌های سرعت', icon: 'bolt', sub: num((res.rows || []).filter(function (r) { return r.on; }).length) + ' از ' + num(rows.length) + ' فعال' }, [head]));

				if (res.warnings && res.warnings.length) {
					host.appendChild(HS.card({}, res.warnings.map(function (w) { return HS.note('warn', typeof w === 'string' ? w : w.message, (w && w.hint) || ''); })));
				}

				var grid = h('div', { class: 'hs-cols hs-cols--2' });
				rows.forEach(function (r) {
					var c = h('div', { class: 'hs-srow' });
					var main = h('div', { class: 'hs-srow__main' }, [
						h('div', { class: 'hs-srow__t' }, [document.createTextNode(r.label || r.key), r.safe ? HS.chip('بی‌خطر', 'ok', { dot: false }) : HS.chip('نیازمند آزمون', 'warn', { dot: false }), r.impact ? HS.chip('اثر: ' + r.impact, null, { dot: false }) : null]),
						h('div', { class: 'hs-srow__d', text: r.desc || '' })
					]);
					var ctl = h('div', { class: 'hs-srow__ctl' });
					if (r.type === 'bool' || !r.type) {
						ctl.appendChild(HS.switchEl(!!r.on, function (v) { toggle(r.key, v); }));
					} else if (r.type === 'select') {
						ctl.appendChild(HS.select(arr2(r.options), r.value, function (v) { toggle(r.key, v); }));
					} else {
						var i2 = h('input', { class: 'hs-input', style: { width: '92px' }, type: 'number', min: r.min, max: r.max, step: r.step, value: r.value });
						i2.addEventListener('change', function () { toggle(r.key, i2.value); });
						ctl.appendChild(i2);
					}
					c.appendChild(main);
					c.appendChild(ctl);
					if (r.warning) { c.appendChild(HS.note('warn', r.warning, '', [])); c.classList.add('is-off'); }
					if (!r.on) { c.classList.add('is-off'); }
					grid.appendChild(c);
				});
				host.appendChild(HS.card({ title: 'هر تیک، با توضیح', icon: 'settings', flush: true }, [grid]));

				if (res.psi && (res.psi.audits || res.psi.opportunities)) {
					var au = res.psi.audits || res.psi.opportunities || [];
					host.appendChild(HS.card({ title: 'پیشنهاد PageSpeed', icon: 'chart', flush: true }, [HS.bars((Array.isArray(au) ? au : []).slice(0, 12).map(function (a) { return { label: a.title || a.label || a.key, value: parseFloat((a.ms || a.wasted || a.value) || 0), display: a.display || '' }; }))]));
				}
				function toggle(key, value) {
					return HS.post('speed/toggle', { key: key, value: value }).then(function (r) {
						if (r && r.score !== undefined) {
							var d = qs('#hs-view .hs-donut');
							if (d) { d.replaceWith(HS.donut(r.score, 'امتیاز سرعت')); }
						}
						HS.toast(r && r.message ? r.message : 'تغییر ذخیره شد', 'ok', { ms: 1600 });
						HS.cacheClear('speed');
					}, HS.fail);
				}
				function run(scope) {
					return HS.post('speed/toggle', { run: true, scope: scope }).then(function (r) {
						HS.toast(r && r.message ? r.message : 'اعمال شد — ' + HS.bytes((r && r.speed && r.speed.bytes) || 0) + ' پس‌اندوز', 'ok');
						HS.cacheClear('speed');
						HS.renderRoute();
					}, HS.fail);
				}
				if (params.run) { run('safe'); }
			});
		}
	});
	function arr2(v) {
		if (!v) { return []; }
		if (Array.isArray(v)) { return v.map(function (x) { return typeof x === 'object' ? x : { id: x, label: String(x) }; }); }
		return HS.entries(v).map(function (p) { return { id: p[0], label: HS.labelOf(p[1], p[0]) }; });
	}

	/* ---------- autopilot ---------- */
	HS.view('autopilot', {
		title: 'خلبانی سئو',
		sub: 'کارهای خودکار: تولید توضیحات، لینک داخلی، متن جایگزین، پاکسازی ۴۰۴ و گزارش',
		render: function (host) {
			host.appendChild(HS.skeleton(4));
			return Promise.all([safe(HS.get('automation/status', {}, { force: true })), safe(HS.get('automation/pending'))]).then(function (r) {
				host.innerHTML = '';
				var st = r[0] || {};
				var pending = (r[1] && r[1].rows) || [];
				var jobs = st.jobs || {};

				var head = h('div', { class: 'hs-row' }, [
					HS.kpis([
						{ label: 'وضعیت', value: st.enabled ? 'روشن' : 'خاموش', hint: st.mode === 'suggest' ? 'فقط پیشنهاد می‌دهد' : 'مستقیم اعمال می‌کند' },
						{ label: 'بعدی', value: (st.next_daily && st.next_daily.ago) || '—', hint: 'هر روز ساعت ' + num(st.hour || 3) },
						{ label: 'در صف', value: num((st.queue && (st.queue.queued || st.queue.total)) || 0) },
						{ label: 'پیشنهاد باز', value: num(pending.length) },
						{ label: 'دسته‌های قابل بازگشت', value: num((st.batches && st.batches.rows ? st.batches.rows.length : 0)) }
					])
				]);
				host.appendChild(HS.card({
					title: 'کنترل', icon: 'robot',
					actions: [
						HS.btn('اجرا حالا', { kind: 'primary', sm: true, icon: 'play', run: function () { return HS.post('automation/run', { scope: 'manual' }).then(function (res) { HS.toast(res && res.message ? res.message : 'اجرا شد', 'ok'); HS.cacheClear('automation'); HS.renderRoute(); }, HS.fail); } }),
						HS.btn('حالت پیشنهاد', { sm: true, kind: st.mode === 'suggest' ? 'primary' : 'ghost', run: function () { HS.markDirty('automation.mode', 'suggest'); return HS.flushDirty().then(function () { HS.renderRoute(); }); } }),
						HS.btn('حالت اعمال', { sm: true, kind: st.mode === 'apply' ? 'primary' : 'ghost', run: function () { HS.markDirty('automation.mode', 'apply'); return HS.flushDirty().then(function () { HS.renderRoute(); }); } }),
						HS.switchEl(!!st.enabled, function (v) { HS.markDirty('automation.enabled', v); return HS.flushDirty().then(function () { HS.toast(v ? 'خلبانی روشن شد' : 'خلبانی خاموش شد', 'ok'); }, HS.fail); }, { title: 'روشن/خاموش' })
					]
				}, [head, h('div', { class: 'hs-row' }, [
					HS.segmented([{ value: 1, label: '۱ صفحه' }, { value: 3, label: '۳' }, { value: 5, label: '۵' }, { value: 10, label: '۱۰' }], HS.sget('automation.pages_per_run', 3), function (v) { HS.markDirty('automation.pages_per_run', parseInt(v, 10)); }),
					HS.segmented([{ value: 0, label: 'نیمه‌شب' }, { value: 3, label: '۳ صبح' }, { value: 6, label: '۶ صبح' }, { value: 21, label: '۹ شب' }], HS.sget('automation.hour', 3), function (v) { HS.markDirty('automation.hour', parseInt(v, 10)); }),
					HS.btn(HS.I18N.save, { sm: true, icon: 'check', run: function () { return HS.flushDirty(); } }),
					st.locked ? HS.chip('یک اجرا در جریان است', 'warn') : null,
					st.cli_url ? h('span', { class: 'hs-muted hs-small', text: 'اجرا از بیرون: ' + st.cli_url }) : null
				])]));

				var jl = HS.entries(jobs);
				if (jl.length) {
					var grid = h('div', { class: 'hs-cols hs-cols--2' });
					jl.forEach(function (p) {
						var j = p[1] || {};
						var c = h('article', { class: 'hs-modcard' + (j.enabled === false ? ' is-off' : '') });
						var ic = HS.icon(j.icon || 'robot');
						ic.className = 'hs-modcard__ico';
						c.appendChild(ic);
						c.appendChild(h('h3', { class: 'hs-modcard__title', text: j.label || p[0] }));
						c.appendChild(h('p', { class: 'hs-modcard__desc', text: j.desc || j.description || j.note || '' }));
						var foot = h('div', { class: 'hs-modcard__foot' }, [
							j.last_label ? h('span', { class: 'hs-muted hs-small', text: 'آخرین اجرا: ' + j.last_label }) : null,
							j.count ? HS.chip(num(j.count) + ' نتیجه', null, { dot: false }) : null,
							h('div', { class: 'hs-right' }, [HS.switchEl(j.enabled !== false, function (v) {
								var next = {};
								var cur = HS.sget('automation.jobs', {}) || {};
								next['automation.jobs'] = Object.assign({}, cur);
								next['automation.jobs'][p[0]] = !!v;
								HS.markDirty('automation.jobs', next['automation.jobs']);
								return HS.flushDirty();
							})])
						]);
						foot.appendChild(HS.btn('اجرای این کار', { sm: true, kind: 'ghost', run: function () {
							return HS.post('automation/run', { jobs: [p[0]] }).then(function (res) {
								var out = res && res.results && res.results[p[0]];
								HS.toast(out && out.message ? out.message : 'انجام شد', 'ok');
								HS.cacheClear('automation'); HS.renderRoute();
							}, HS.fail);
						} }));
						c.appendChild(foot);
						grid.appendChild(c);
					});
					host.appendChild(HS.card({ title: 'کارها', icon: 'check-list', flush: true }, [grid]));
				}

				if (pending.length) {
					var list = h('div', { class: 'hs-list' });
					pending.forEach(function (p) {
						var row = h('div', { class: 'hs-list__item' });
						row.appendChild(h('span', { class: 'hs-dot is-warn' }));
						row.appendChild(h('div', { class: 'hs-list__main' }, [
							h('div', { class: 'hs-list__title', text: p.label || p.title || p.task || 'پیشنهاد' }),
							h('div', { class: 'hs-list__meta', text: (p.note || p.message || '') + (p.post_id ? (' · نوشته ' + num(p.post_id)) : '') })
						]));
						row.appendChild(h('div', { class: 'hs-row' }, [
							HS.btn('اعمال', { sm: true, kind: 'primary', run: function () { return review(p, true); } }),
							HS.btn('رد', { sm: true, kind: 'ghost', run: function () { return review(p, false); } })
						]));
						list.appendChild(row);
					});
					host.appendChild(HS.card({ title: 'منتظر تأیید شما (' + num(pending.length) + ')', icon: 'star', actions: [
						HS.btn(HS.I18N.applyAll, { sm: true, kind: 'primary', run: function () { return Promise.all(pending.slice(0, 20).map(function (p) { return review(p, true, true); })).then(function () { HS.renderRoute(); }); } })
					] }, [list]));
				}

				var batches = (st.batches && st.batches.rows) || [];
				if (batches.length) {
					host.appendChild(HS.card({ title: 'اجراهای اخیر', icon: 'clock', flush: true }, [HS.table({ columns: [
						{ key: 'at', label: 'زمان', width: '150px', render: function (b) { return esc(b.at ? HS.faDate(b.at, true) : (b.created_at ? HS.faDate(b.created_at, true) : '—')); } },
						{ key: 'scope', label: 'حوزه', width: '110px', render: function (b) { return '<span class="hs-tag">' + esc(b.scope || 'خودکار') + '</span>'; } },
						{ key: 'jobs', label: 'کارها', render: function (b) { return esc(Array.isArray(b.jobs) ? b.jobs.join('، ') : (b.label || '')); } },
						{ key: 'changed', label: 'تغییر', align: 'num', width: '90px', render: function (b) { return num(b.changed || 0); } },
						{ key: '_a', label: '', width: '130px', render: function (b) { return b.undoable === false || b.reverted ? '<span class="hs-muted hs-small">—</span>' : '<button class="hs-btn hs-btn--sm" data-undo="' + esc(b.batch || b.id) + '">بازگردانی</button>'; } }
					], rows: batches.slice(0, 12) })]));
					on(qs('#hs-view'), 'click', '[data-undo]', function (e, el) {
						e.preventDefault();
						HS.confirmBox('بازگردانی دسته', 'تغییرهای این اجرا به حالت قبل برگردد؟', function () {
							return HS.post('automation/undo', { batch: el.dataset.undo }).then(function (r) {
								HS.toast((r && r.message) || 'بازگردانی شد', 'ok');
								HS.cacheClear('automation'); HS.renderRoute();
							}, HS.fail);
						});
					});
				}
				function review(p, apply, silent) {
					return HS.post('automation/apply', { job: p.id || p.job_id || p.job, apply: !!apply, fields: {} }).then(function (r) {
						if (!silent) { HS.toast((r && r.message) || (apply ? 'اعمال شد' : 'رد شد'), 'ok'); HS.cacheClear('automation'); HS.renderRoute(); }
					}, function (e) { HS.fail(e); });
				}
			});
		}
	});
})(HS);

/* =================== reports, AI, settings, titles, tools, help + boot =================== */
(function (HS) {
	'use strict';
	var h = HS.h, qs = HS.qs, on = HS.on, num = HS.num, esc = HS.esc, C = HS.C, safe = HS.safe;
	var GROUP_LABEL = {
		general: 'عمومی', titles: 'عنوان‌ها', sitemap: 'نقشه سایت', robots: 'رباتز', redirects: 'ریدایرکت',
		notfound: 'خطای ۴۰۴', links: 'لینک داخلی', indexnow: 'اینکس‌ناو', images: 'تصویرها', schema: 'اسکیما',
		content: 'محتوا', keywords: 'کلمات کلیدی', analytics: 'آنالیتیکس', speed: 'سرعت', security: 'امنیت',
		opengraph: 'شبکه اجتماعی', woo: 'ووکامرس', edd: 'ادد', ai: 'هوش مصنوعی', automation: 'خودکارسازی',
		migration: 'مهاجرت', geo: 'موتور پاسخ', audit: 'ممیزی', reports: 'گزارش‌ها', appearance: 'ظاهر'
	};
	var LABELS = {
		'general.site_name': { t: 'نام سایت', d: 'در عنوان‌ها، نقشه سایت و کارت اجتماعی استفاده می‌شود.' },
		'general.title_sep': { t: 'جداکننده عنوان', d: 'مثلاً «» | — یه /' },
		'general.knowledge_graph': { t: 'ثبت در Google Knowledge Panel', d: 'اگر وصل باشد، نام و لوگو از کنسول گرفته می‌شود.', adv: 1 },
		'general.remove_css_on_front': { t: 'حذف CSS افزونه در صفحه اصلی', d: 'برای سرعت؛ اگر استایل‌های شکسته شد، خاموشش کنید.' },
		'general.remove_data_on_uninstall': { t: 'حذف داده‌ها هنگام حذف افزونه', d: 'با حذف افزونه، جدول‌ها و تنظیمات هم پاک می‌شوند.' },
		'general.share_analytics': { t: 'اجازه اشتراک‌گذاری آمار', d: 'کمک به بهبود افزونه؛ هیچ داده‌ای از سایت شما ارسال نمی‌شود.', adv: 1 },
		'general.tracking_mode': { t: 'نحوه اتصال به سرچ کنسول' },
		'titles.title': { t: 'قالب عنوان خانه', d: 'برای صفحه اول؛ متغیرها کار می‌کنند.' },
		'titles.single': { t: 'قالب عنوان نوشته', adv: 0 },
		'titles.page': { t: 'قالب عنوان برگه' },
		'titles.archive': { t: 'قالب بایگانی' },
		'titles.search': { t: 'قالب صفحه جست‌وجو', adv: 1 },
		'titles.author': { t: 'قالب بایگانی نویسنده', adv: 1 },
		'titles.date': { t: 'قالب بایگانی تاریخ', adv: 1 },
		'titles.term': { t: 'قالب برچسب و دسته', adv: 1 },
		'titles.front_desc': { t: 'توضیحات صفحه خانه' },
		'titles.truncate': { t: 'کوتاه کردن خودکار عنوان', d: 'اگر از حد پیکسل‌ها بلندتر شد.' },
		'titles.auto_description': { t: 'ساخت خودکار توضیحات متا', d: 'وقتی دستی ننویسید، از متن صفحه ساخته می‌شود.' },
		'titles.slug_transliterate': { t: 'ترجیع‌نگاری نامک', d: 'نامک‌های فارسی به لاتین تبدیل شوند.' },
		'sitemap.enabled': { t: 'نقشه سایت' },
		'sitemap.per_page': { t: 'حداکثر آدرس در هر فایل', d: 'بیش از ۵۰ هزار مجاز نیست.' },
		'sitemap.ping': { t: 'اعلام خودکار به گوگل و بینگ' },
		'sitemap.ping_on_save': { t: 'اعلام هنگام ذخیره نوشته', adv: 1 },
		'sitemap.changefreq': { t: 'دوره به‌روزرسانی اعلامی' },
		'sitemap.cached': { t: 'کش کردن خروجی XML', d: 'برای سایت‌های پرترافیک پیشنهاد می‌شود.' },
		'sitemap.cache_ttl': { t: 'عمر کش (دقیقه)', adv: 1 },
		'sitemap.exclude_posts': { t: 'رد کردن این نوشته‌ها (ID)' },
		'robots.virtual': { t: 'فایل واقعی نساز', d: 'رباتز به‌صورت پویا سرو می‌شود.' },
		'robots.override_file': { t: 'بازنویسی فایل robots.txt موجود', adv: 1 },
		'robots.block_draft': { t: 'جلوگیری از خواندن پیش‌نویس‌ها', adv: 1 },
		'robots.block_search': { t: 'بستن صفحه جست‌وجو', d: 'جلو از اسکن بی‌مورد نتایج جست‌وجو.' },
		'robots.sitemap_line': { t: 'درج آدرس نقشه سایت', adv: 1 },
		'robots.log_bots': { t: 'ثبت بازدید خزنده‌ها', d: 'نشان می‌دهد کدام ربات، کدام صفحه را دیده است.' },
		'robots.crawler_hints': { t: 'راهنمای خزنده در robots.txt', adv: 1 },
		'robots.ai_bots': { t: 'خزنده‌های هوش مصنوعی', d: 'لیست User-Agentهایی که اجازه دارند.', adv: 1 },
		'redirects.monitor': { t: 'پایش تغییر نامک و آدرس' },
		'redirects.type': { t: 'نوع ریدایرکت پیش‌فرض' },
		'redirects.log_hits': { t: 'ثبت بازدید ریدایرکت‌ها' },
		'redirects.auto_post_delete': { t: 'ساخت ریدایرکت هنگام حذف نوشته' },
		'redirects.force_https': { t: 'اجبار به HTTPS', d: 'فقط روی سروری که گواهی دارد.', adv: 1 },
		'redirects.trailing_slash': { t: 'یکسان‌سازی اسلش پایان' },
		'redirects.www_mode': { t: 'www' },
		'redirects.strip_query': { t: 'حذف پارامترهای تبلیغاتی' },
		'redirects.utm_names': { t: 'پارامترهای قابل حذف', adv: 1 },
		'redirects.smart_404': { t: 'هدایت هوشمند ۴۰۴' },
		'notfound.enabled': { t: 'ثبت خطاهای ۴۰۴' },
		'notfound.retention_days': { t: 'نگهداری گزارش (روز)' },
		'notfound.notify_threshold': { t: 'آستانه هشدار (تکرار در ساعت)', adv: 1 },
		'notfound.ignore_bots': { t: 'ثبت نکردن بازدید ربات‌ها' },
		'notfound.ignore_browsers': { t: 'ثبت نکردن خطای مرورگر', adv: 1 },
		'notfound.auto_redirect': { t: 'پیشنهادهای خودکار ریدایرکت', adv: 1 },
		'links.enabled': { t: 'لینک داخلی خودکار' },
		'links.autolink': { t: 'اجرای خودکار روی محتوای ذخیره‌شده' },
		'links.max_per_page': { t: 'حداکثر لینک در هر نوشته' },
		'links.direct': { t: 'لینک مستقیم (بدون صفحه میانی)', adv: 1 },
		'links.new_tab_external': { t: 'باز شدن لینک بیرونی در تب جدید' },
		'links.nofollow_external': { t: 'nofollow برای لینک‌های بیرونی', adv: 1 },
		'links.track_clicks': { t: 'ردیابی کلیک' },
		'links.skip_archives': { t: 'رد کردن بایگانی‌ها', adv: 1 },
		'links.report_email': { t: 'گزارش هفتگی لینک‌های شکسته', adv: 1 },
		'indexnow.enabled': { t: 'فعال', d: 'آدرس‌های تازه را سریع به بینگ، یاندکس و… می‌فرستد.' },
		'indexnow.auto': { t: 'ارسال خودکار هنگام انتشار' },
		'indexnow.batch': { t: 'اندازه هر دسته', adv: 1 },
		'indexnow.casual': { t: 'حالت آرام', d: 'برای سایت‌های کم‌ترافیک؛ تا ۵۰۰ آدرس در روز.', adv: 1 },
		'indexnow.endpoint': { t: 'نشانی اختصاصی endpoint', adv: 1 },
		'images.missing_only': { t: 'فقط تصویرهای بی‌alt', adv: 1 },
		'images.autofill': { t: 'پر کردن خودکار alt', d: 'وقتی خالی باشد، از عنوان تصویر.' },
		'images.alt_source': { t: 'منبع متن جایگزین' },
		'images.ai_alt': { t: 'استفاده از هوش مصنوعی برای alt' },
		'images.strip_exif': { t: 'حذف اطلاعات مکانی EXIF' },
		'images.rename_upload': { t: 'تغییر نام فایل هنگام بارگذاری', d: 'نام فایل از عنوان تصویر ساخته می‌شود.' },
		'images.rename_lang': { t: 'زبان نام فایل' },
		'images.webp_hints': { t: 'راهنمای فرمت مدرن', adv: 1 },
		'images.lazy_below_fold': { t: 'بارگذاری تنبل تصویرها' },
		'images.lcp_preload': { t: 'پیش‌بارگذاری تصویر LCP' },
		'schema.enable_meta': { t: 'برچسب‌های noindex در HTML' },
		'schema.article': { t: 'اسکیما Article' },
		'schema.website': { t: 'اسکیما WebSite' },
		'schema.breadcrumbs': { t: 'اسکیما BreadcrumbList' },
		'schema.faq': { t: 'اسکیما FAQ' },
		'schema.howto': { t: 'اسکیما HowTo', adv: 1 },
		'schema.person': { t: 'اسکیما Person (نویسنده)', adv: 1 },
		'schema.org': { t: 'اسکیما Organization' },
		'schema.video': { t: 'اسکیما VideoObject', adv: 1 },
		'schema.local_business': { t: 'اسکیما LocalBusiness' },
		'content.enabled': { t: 'فعال', d: 'بررسی‌های سئو و خوانایی روی هر نوشته.' },
		'content.keyphrase': { t: 'بررسی کلمه کلیدی' },
		'content.readability': { t: 'بررسی خوانایی' },
		'content.title_max': { t: 'حداکثر طول عنوان' },
		'content.desc_min': { t: 'حداقل طول توضیحات', adv: 1 },
		'content.desc_max': { t: 'حداکثر طول توضیحات', adv: 1 },
		'content.min_words': { t: 'حداقل واژه برای نمره کامل' },
		'content.external_nofollow': { t: 'nofollow لینک‌های بیرونی', adv: 1 },
		'content.tax_noindex': { t: 'noindex روی بایگانی‌ها' },
		'content.page_score': { t: 'نمره هر نوشته را در ستون‌ها نشان بده', adv: 1 },
		'keywords.auto_extract': { t: 'استخراج خودکار کلمات' },
		'keywords.min_length': { t: 'حداقل طول عبارت', adv: 1 },
		'keywords.max_per_post': { t: 'حداکثر کلمه در هر نوشته', adv: 1 },
		'keywords.stopwords': { t: 'کلمات بی‌اهمیت', adv: 1 },
		'keywords.prefixes': { t: 'پیشوندهای تحقیق' },
		'keywords.suffixes': { t: 'پسوندهای تحقیق' },
		'keywords.max_results': { t: 'حداکثر نتیجه هر اجرا', adv: 1 },
		'analytics.enabled': { t: 'فعال' },
		'analytics.import_mode': { t: 'شیوه دریافت داده' },
		'analytics.days': { t: 'دایره گزارش (روز)', adv: 1 },
		'analytics.page_limit': { t: 'حداکثر صفحه در هر درخواست', adv: 1 },
		'analytics.cache': { t: 'کش نتایج', adv: 1 },
		'analytics.gsc_property': { t: 'ملک Search Console', adv: 1 },
		'analytics.gsc_json': { t: 'فایل کلید سرویس', adv: 1 },
		'analytics.ga4_property': { t: 'ملک GA4', adv: 1 },
		'analytics.psi_key': { t: 'کلید PageSpeed API' },
		'speed.enabled': { t: 'فعال' },
		'speed.scope': { t: 'دامنه اعمال', adv: 1 },
		'speed.minify_html': { t: 'کوچک‌سازی HTML' },
		'speed.critical_css': { t: 'CSS بحرانی' },
		'speed.delay_js': { t: 'به تعویق انداختن JS' },
		'speed.remove_query': { t: 'حذف نسخه از فایل‌های استاتیک' },
		'speed.preconnect': { t: 'پیش‌اتصال به میزبان‌های بیرونی' },
		'speed.dns_prefetch': { t: 'پیش‌گیری DNS', adv: 1 },
		'speed.lazy': { t: 'بارگذاری تنبل تصویرها' },
		'speed.emoji': { t: 'حذف اسکریپت ایموجی' },
		'speed.jquery': { t: 'بارگذاری jQuery در پاورقی', adv: 1 },
		'speed.oembed': { t: 'حذف oEmbed', adv: 1 },
		'speed.heartbeat': { t: 'محدود کردن Heartbeat' },
		'speed.cache': { t: 'کش صفحه' },
		'speed.cache_ttl': { t: 'عمر کش (ساعت)', adv: 1 },
		'security.block_file_edit': { t: 'بستن ویرایشر فایل در پیشخوان' },
		'security.hide_login_errors': { t: 'پنهان کردن دلیل خطای ورود' },
		'security.xmlrpc': { t: 'محدود کردن XML-RPC' },
		'security.rest_user_enum': { t: 'بستن فهرست کاربران از REST' },
		'security.hsts': { t: 'هدر HSTS', adv: 1 },
		'security.login_limit': { t: 'محدود کردن تلاش ورود', adv: 1 },
		'opengraph.enabled': { t: 'Open Graph' },
		'opengraph.twitter': { t: 'کارت توییتر/X' },
		'opengraph.default_image': { t: 'تصویر پیش‌فرض اشتراک‌گذاری' },
		'opengraph.generate_alt': { t: 'ساخت توضیح از متن تصویر', adv: 1 },
		'opengraph.default_type': { t: 'نوع پیش‌فرض', adv: 1 },
		'opengraph.sitename_prefix': { t: 'پیشوند نام سایت در عنوان', adv: 1 },
		'woo.enabled': { t: 'فعال' },
		'woo.product_schema': { t: 'اسکیما Product' },
		'woo.brand_taxonomy': { t: 'ساخت تاکسونومی برند' },
		'woo.noindex_pages': { t: 'noindex روی سبد/حساب/پایان‌چین' },
		'woo.autoload_variations': { t: 'مدیریت اسکریپت تنوع‌ها', adv: 1 },
		'woo.alt_auto': { t: 'پر کردن خودکار alt محصول', adv: 1 },
		'woo.report_stock': { t: 'گزارش موجودی به موتورهای پاسخ', adv: 1 },
		'edd.enabled': { t: 'فعال', adv: 1 },
		'edd.download_schema': { t: 'اسکیما SoftwareApplication', adv: 1 },
		'ai.enabled': { t: 'استفاده از هوش مصنوعی' },
		'ai.driver': { t: 'سرویس' },
		'ai.model': { t: 'مدل' },
		'ai.base_url': { t: 'نشانی پایه (برای سرویس‌های سازگار با OpenAI)', adv: 1 },
		'ai.language': { t: 'زبان خروجی' },
		'ai.tone': { t: 'لحن' },
		'ai.length': { t: 'طول خروجی' },
		'ai.cache_days': { t: 'عمر کش خروجی (روز)', adv: 1 },
		'ai.monthly_limit': { t: 'سقف هزینه ماهانه ($)' },
		'ai.allow_apply': { t: 'اعمال خودکار نتیجه روی نوشته', d: 'با احتیاط روشن کنید؛ نتیجه قبل از ذخیره قابل دیدن است.' },
		'ai.proxy': { t: 'پراکسی', adv: 1 },
		'ai.timeout': { t: 'محدودیت زمان (ثانیه)', adv: 1 },
		'automation.enabled': { t: 'روشن', d: 'بدون روشن کردن، هیچ کاری اجرا نمی‌شود.' },
		'automation.mode': { t: 'حالت', d: 'در «پیشنهاد» هیچ چیزی روی سایت تغییر نمی‌کند.' },
		'automation.pages_per_run': { t: 'صفحه در هر اجرا', adv: 1 },
		'automation.hour': { t: 'ساعت اجرای روزانه', adv: 1 },
		'automation.undo_enabled': { t: 'امکان بازگردانی', d: 'قبل از اعمال، نسخه قبل ذخیره می‌شود.', adv: 1 },
		'automation.review_before_apply': { t: 'نیاز به تأیید دستی', adv: 1 },
		'automation.notify_email': { t: 'ایمیل گزارش اجرا', adv: 1 },
		'migration.enabled': { t: 'فعال', adv: 1 },
		'migration.auto_detect': { t: 'تشخیص خودکار افزونه مبدأ' },
		'migration.policy': { t: 'راهبرد ادغام' },
		'migration.keep_old_meta': { t: 'نگه داشتن متادیتای افزونه قبلی', adv: 'danger' },
		'migration.delete_source': { t: 'حذف داده افزونه مبدأ', adv: 'danger' },
		'migration.convert_redirection': { t: 'تبدیل ریدایرکت‌های افزونه Redirection' },
		'migration.cleanup': { t: 'پاک‌سازی داده مبدأ', adv: 'danger' },
		'geo.enabled': { t: 'فعال' },
		'geo.llms_txt': { t: 'ساخت llms.txt', d: 'فایل راهنمای ساده برای مدل‌ها.' },
		'geo.llms_full_txt': { t: 'ساخت llms-full.txt', adv: 1 },
		'geo.answer_box': { t: 'جعبه پاسخ', d: 'پاسخ ۴۰ تا ۶۰ کلمه‌ای در بالای صفحه.' },
		'geo.answer_auto': { t: 'تولید خودکار پاسخ', adv: 1 },
		'geo.speakable': { t: 'Speakable', adv: 1 },
		'geo.ai_visible': { t: 'دیده‌شدن در خلاصه‌سازها' },
		'geo.crawler_brief': { t: 'راهنمای خزنده', adv: 1 },
		'geo.schema_graph': { t: 'گراف اسکیما', adv: 1 },
		'geo.faq_auto': { t: 'FAQ خودکار', adv: 1 },
		'geo.pages_count': { t: 'تعداد صفحه در llms-full.txt', adv: 1 },
		'geo.retain': { t: 'یادداشت‌های دستی حفظ شود', adv: 1 },
		'audit.enabled': { t: 'ثبت تغییرات', d: 'هر کاری که افزونه می‌کند بازنویسی‌پذیر می‌ماند.' },
		'audit.track_files': { t: 'پایش تغییر فایل‌ها', adv: 1 },
		'audit.retention_days': { t: 'نگهداری گزارش (روز)', adv: 1 },
		'reports.enabled': { t: 'فعال' },
		'reports.email': { t: 'ایمیل گزارش' },
		'reports.schedule': { t: 'زمان‌بندی' },
		'reports.weekly_hour': { t: 'ساعت گزارش هفتگی', adv: 1 },
		'reports.include_psi': { t: 'نتیجه PageSpeed در گزارش', adv: 1 },
		'appearance.theme': { t: 'پوسته' },
		'appearance.accent': { t: 'رنگ اصلی' },
		'appearance.sidebar': { t: 'ستون کناری' },
		'appearance.dashboard_widgets': { t: 'ویجت‌های داشبورد', adv: 1 },
		'appearance.density': { t: 'تراکم' },
		'appearance.font': { t: 'فونت' },
		'appearance.custom_css': { t: 'CSS دلخواه', adv: 1 },
		'appearance.context_help': { t: 'راهنمای متنی کنار هر تنظیم', adv: 1 }
	};
	var OPTIONS = {
		'titles.truncate': [{ id: 1, label: 'بله' }, { id: 0, label: 'خیر' }],
		'sitemap.changefreq': [{ id: 'daily', label: 'روزانه' }, { id: 'weekly', label: 'هفتگی' }, { id: 'monthly', label: 'ماهانه' }, { id: '', label: 'بدون اعلام' }],
		'redirects.type': [{ id: '301', label: '301 — همیشگی' }, { id: '302', label: '302 — موقت' }, { id: '307', label: '307 — موقت با متد' }],
		'redirects.www_mode': [{ id: 'keep', label: 'همانطور که هست' }, { id: 'www', label: 'همیشه با www' }, { id: 'nowww', label: 'بدون www' }],
		'images.alt_source': [{ id: 'title', label: 'عنوان فایل' }, { id: 'caption', label: 'زیرنویس' }, { id: 'description', label: 'توضیح پیوست' }, { id: 'ai', label: 'هوش مصنوعی' }],
		'images.rename_lang': [{ id: 'fa', label: 'فارسی' }, { id: 'en', label: 'لاتین' }],
		'analytics.import_mode': [{ id: 'oauth', label: 'اتصال OAuth' }, { id: 'key', label: 'کلید سرویس' }, { id: 'csv', label: 'درون‌ریزی CSV' }, { id: 'off', label: 'خاموش' }],
		'speed.scope': [{ id: 'safe', label: 'فقط بی‌خطر' }, { id: 'front', label: 'فقط صفحه اصلی' }, { id: 'posts', label: 'نوشته‌ها' }, { id: 'all', label: 'همه' }],
		'speed.heartbeat': [{ id: 'reduce', label: 'کاهش' }, { id: 'disable', label: 'خاموش' }, { id: 'keep', label: 'بدون تغییر' }],
		'ai.driver': [{ id: 'openai', label: 'OpenAI' }, { id: 'anthropic', label: 'Claude' }, { id: 'gemini', label: 'Gemini' }, { id: 'deepseek', label: 'DeepSeek' }, { id: 'groq', label: 'Groq' }, { id: 'mistral', label: 'Mistral' }, { id: 'perplexity', label: 'Perplexity' }, { id: 'cohere', label: 'Cohere' }, { id: 'ollama', label: 'Ollama (محلی)' }, { id: 'openai_compat', label: 'سازگار با OpenAI' }],
		'ai.language': [{ id: 'fa', label: 'فارسی' }, { id: 'en', label: 'انگلیسی' }, { id: 'ar', label: 'عربی' }, { id: 'auto', label: 'زبان سایت' }],
		'ai.tone': [{ id: 'neutral', label: 'خنثی' }, { id: 'friendly', label: 'دوستانه' }, { id: 'formal', label: 'رسمی' }, { id: 'sales', label: 'فروشی' }],
		'ai.length': [{ id: 'short', label: 'کوتاه' }, { id: 'medium', label: 'متوسط' }, { id: 'long', label: 'بلند' }],
		'automation.mode': [{ id: 'suggest', label: 'پیشنهاد بده' }, { id: 'apply', label: 'اعمال کن' }],
		'migration.policy': [{ id: 'skip', label: 'رد کردن موارد موجود' }, { id: 'overwrite', label: 'بازنویسی' }, { id: 'longer', label: 'هر کدام کامل‌تر بود' }],
		'reports.schedule': [{ id: 'weekly', label: 'هفتگی' }, { id: 'monthly', label: 'ماهانه' }, { id: 'off', label: 'خاموش' }],
		'appearance.theme': [{ id: 'dark', label: 'تیره' }, { id: 'light', label: 'روشن' }, { id: 'auto', label: 'هم‌رنگ سیستم' }],
		'appearance.accent': [{ id: 'firouzeh', label: 'فیروزه‌ای' }, { id: 'lajevard', label: 'لاجوردی' }, { id: 'zahresh', label: 'زهره‌ای' }, { id: 'anabi', label: 'بنفش' }, { id: 'sormeh', label: 'سرمه‌ای' }, { id: 'tajalli', label: 'طلایی' }],
		'appearance.sidebar': [{ id: 'pinned', label: 'باز' }, { id: 'collapsed', label: 'جمع' }],
		'appearance.density': [{ id: 'cozy', label: 'راحت' }, { id: 'compact', label: 'فشرده' }],
		'appearance.font': [{ id: 'system', label: 'فونت سیستم' }, { id: 'local', label: 'وزیرمتن (محلی)' }, { id: 'cdn', label: 'وزیرمتن (CDN)' }]
	};

	/* ---------- reports ---------- */
	HS.view('reports', {
		title: 'گزارش‌ها',
		sub: 'یک بریده‌مفید از وضعیت سایت، قابل چاپ و ارسال',
		render: function (host, params) {
			var days = parseInt(params.days, 10) || 28;
			var frame = h('iframe', { class: 'hs-iframe', title: 'گزارش', loading: 'lazy' });
			var body = h('div', { class: 'hs-col' }, [h('div', { class: 'hs-loading', text: 'ساخت گزارش…' })]);
			var url = null;
			host.appendChild(HS.card({
				title: 'گزارش سئو', icon: 'printer',
				actions: [
					HS.segmented([{ value: 7, label: '۷ روز' }, { value: 28, label: '۲۸ روز' }, { value: 90, label: '۹۰ روز' }], days, function (v) { HS.go('reports', { days: v }); }),
					HS.btn('باز در تب جدید', { sm: true, kind: 'ghost', icon: 'external', href: url || '#', run: null }),
					HS.btn('چاپ', { sm: true, icon: 'printer', run: function () { if (url) { window.open(url, '_blank'); } } }),
					HS.btn('ارسال ایمیل', { sm: true, kind: 'primary', icon: 'send', run: email })
				]
			}, [body]));
			HS.get('report', { days: days }).then(function (res) {
				res = res || {};
				url = res.url || null;
				body.innerHTML = '';
				if (res.html) {
					frame.srcdoc = res.html;
					body.appendChild(frame);
				} else if (url) {
					frame.src = url;
					body.appendChild(frame);
				} else {
					body.appendChild(HS.note('warn', 'گزارشی ساخته نشد', 'ممکن است هنوز داده‌ای برای این بازه ثبت نشده باشد.'));
				}
				var a = qs('#hs-view a[href="#"]');
				if (a && url) { a.setAttribute('href', url); }
				var kpis = res.payload || res;
				if (kpis && kpis.kpis) {
					body.insertBefore(HS.kpis(kpis.kpis.map(function (k) { return { label: k.label, value: k.value, plain: true }; })), body.firstChild);
				}
			}, function (e) { body.innerHTML = ''; body.appendChild(HS.note('bad', 'گزارش ساخته نشد', e.message)); });
			function email() {
				var to = h('input', { class: 'hs-input', type: 'email', value: HS.sget('reports.email', '') || C.user.email, placeholder: 'you@example.com' });
				var subject = h('input', { class: 'hs-input', value: 'گزارش سئو — ' + (C.site && C.site.name) });
				HS.modal({
					title: 'ارسال گزارش', size: 'sm',
					body: h('div', { class: 'hs-col' }, [HS.note(null, 'گزارش در یک صفحه HTML خودکفا ساخته و ایمیل می‌شود.'), h('label', { class: 'hs-field' }, [h('span', { class: 'hs-field__l', text: 'گیرنده' }), to]), h('label', { class: 'hs-field' }, [h('span', { class: 'hs-field__l', text: 'موضوع' }), subject])]),
					actions: [{ label: HS.I18N.cancel }, { label: 'ارسال', kind: 'primary', run: function () {
						return HS.post('report/email', { to: to.value, subject: subject.value, format: 'html' }).then(function (r) {
							HS.toast((r && r.ok === false) ? ((r && r.message) || 'ارسال نشد') : 'گزارش ایمیل شد ✓', (r && r.ok === false) ? 'bad' : 'ok');
						}, HS.fail);
					} }]
				});
			}
			return Promise.resolve();
		}
	});

	/* ---------- AI ---------- */
	HS.view('ai', {
		title: 'دستیار هوش مصنوعی',
		sub: 'تولید توضیحات متا، عنوان، alt و برنامه محتوایی — با سقف هزینه',
		render: function (host) {
			host.appendChild(HS.skeleton(4));
			return Promise.all([HS.get('ai/overview', {}, { force: true }), HS.get('ai/jobs', { state: 'queued', per: 20 })]).then(function (r) {
				host.innerHTML = '';
				var ov = r[0] || {};
				var jobs = (r[1] && r[1].rows) || [];
				var tasks = ov.tasks || {};
				var providers = C.ai && C.ai.providers || {};
				var provList = HS.entries(providers);

				var keyHost = h('div');
				var driver = HS.sget('ai.driver', ov.driver || 'openai');
				function paintKeys() {
					keyHost.innerHTML = '';
					var g = h('div', { class: 'hs-form' });
					var list = provList.length ? provList : Object.keys({}).map(function () { return null; });
					if (!list.length) { g.appendChild(HS.empty('سرویس ثبت نشده', null, true)); }
					list.forEach(function (p) {
						var id = p[0], meta = p[1] || {};
						var cur = (ov.providers && ov.providers[id]) || {};
						var fd = { key: id, label: meta.label || id, type: 'secret', help: (meta.note || '') + (meta.share ? (' — با ' + meta.share) : ''), placeholder: cur.set ? ('••••' + cur.masked) : (meta.key_hint || 'کلید API') };
						var inp = HS.input(fd, '', function (v) {
							if (!v) { return; }
							HS.markDirty('ai.keys.' + id, v);
						});
						inp.querySelectorAll('input').forEach(function (x) { x.setAttribute('autocomplete', 'new-password'); });
						g.appendChild(HS.fieldWrap(fd, inp));
						if (cur.set) {
							var row = h('div', { class: 'hs-row' }, [HS.chip('کلید ذخیره شده', 'ok', { dot: false }), HS.btn('آزمون', { sm: true, run: function () { return test(id); } }), HS.btn('پاک کردن', { sm: true, kind: 'ghost', run: function () { return HS.post('ai/keys', { keys: (function () { var o = {}; o[id] = ''; return o; })() }).then(function () { HS.cacheClear('ai'); HS.renderRoute(); }, HS.fail); } })]);
							g.appendChild(row);
						} else {
							g.appendChild(HS.btn('آزمون این سرویس', { sm: true, kind: 'ghost', run: function () { return test(id); } }));
						}
					});
					keyHost.appendChild(g);
				}
				// GapGPT documents more than one base URL, so the endpoint is a
				// setting rather than something baked in. Only show it when it
				// is the provider actually in use.
				var gapGptWrap = h('div');
				function gapGptBase() {
					var meta = (C.ai && C.ai.providers && C.ai.providers.gapgpt) || {};
					var field = { key: 'gapgpt_base', label: 'نشانی گپ جی‌پی‌تی', type: 'text', help: 'پیش‌فرض ' + (meta.base || 'https://api.gapgpt.app/v1') + ' است. اگر سرویس شما نشانی دیگری می‌دهد، همان را بنویسید (بدون /chat/completions).' };
					var inp = HS.input(field, HS.sget('ai.gapgpt_base', '') || '', function (v) { HS.markDirty('ai.gapgpt_base', String(v).trim()); });
					gapGptWrap.appendChild(HS.fieldWrap(field, inp));
					syncGapGpt();
					return gapGptWrap;
				}
				function syncGapGpt() {
					gapGptWrap.style.display = (driver === 'gapgpt') ? '' : 'none';
				}
				function test(drv) {
					return HS.post('ai/test', { driver: drv || driver }).then(function (res) {
						HS.toast((res && res.ok ? ('✓ ' + (res.reply || res.message || 'پاسخ گرفتیم')) : ((res && res.message) || 'پاسخی نگرفتیم')), res && res.ok ? 'ok' : 'warn', { ms: 4000 });
					}, HS.fail);
				}
				host.appendChild(HS.card({
					title: 'اتصال', icon: 'chip',
					actions: [
						HS.btn(HS.I18N.save, { kind: 'primary', sm: true, icon: 'check', run: function () { return HS.flushDirty().then(function () { HS.cacheClear('ai'); HS.renderRoute(); }); } }),
						HS.btn('آزمون سرویس فعال', { sm: true, icon: 'bolt', run: function () { return test(); } })
					]
				}, [
					h('div', { class: 'hs-form' }, [
						HS.fieldWrap({ key: 'driver', label: 'سرویس', help: 'پشتیبانان متنوع: ابری، محلی و سازگار با OpenAI.' }, HS.select(arrToOpts(OPTIONS['ai.driver']), driver, function (v) { driver = v; HS.markDirty('ai.driver', v); syncGapGpt(); })),
						HS.fieldWrap({ key: 'model', label: 'مدل' }, (function () { var i = h('input', { class: 'hs-input', value: HS.sget('ai.model', '') || ov.model || '' }); i.addEventListener('change', function () { HS.markDirty('ai.model', i.value); }); return i; })())
					]),
					keyHost,
					gapGptBase(),
					HS.kpis([
						{ label: 'پیکربندی', value: ov.configured ? 'آماده' : 'ناتمام' },
						{ label: 'هزینه این ماه', value: '$' + num(ov.spend && ov.spend.cost || ov.spend || 0, 2) },
						{ label: 'توکن مصرفی', value: num(ov.spend && ov.spend.tokens || 0) },
						{ label: 'در صف', value: num(ov.queued || 0) },
						{ label: 'در حال اجرا', value: num(ov.running || 0) },
						{ label: 'ناموفق', value: num(ov.failed || 0) }
					])
				]));
				paintKeys();

				var taskList = h('div', { class: 'hs-srows' });
				HS.entries(tasks).forEach(function (p) {
					var t = p[1] || {};
					var row = h('div', { class: 'hs-srow' });
					row.appendChild(h('div', { class: 'hs-srow__main' }, [
						h('div', { class: 'hs-srow__t', text: t.label || p[0] }),
						h('div', { class: 'hs-srow__d', text: (t.desc || '') + (t.count ? (' · ' + num(t.count) + ' بار استفاده') : '') })
					]));
					row.appendChild(h('div', { class: 'hs-srow__ctl' }, [HS.btn('تست روی اولین نوشته', { sm: true, kind: 'ghost', run: function () {
						return HS.get('content/pages', { per: 1, orderby: 'score', order: 'asc' }).then(function (res) {
							var pid = ((res && res.rows) || [])[0] || {};
							return HS.post('ai/run', { task: p[0], post_id: pid.post_id || pid.id || 0, dry_run: true }).then(function (out) {
								HS.modal({ title: t.label || p[0], size: 'md', body: h('pre', { class: 'hs-code', text: (out && out.content) || (out && out.error) || 'خروجی‌ای نبود' }), actions: [{ label: HS.I18N.close }] });
							}, HS.fail);
						}, HS.fail);
					} })]));
					taskList.appendChild(row);
				});
				host.appendChild(HS.card({ title: 'کارهای آماده', icon: 'wand', flush: true }, [taskList]));

				var chat = h('div', { class: 'hs-col' });
				var ta = h('textarea', { class: 'hs-input hs-textarea', rows: 3, placeholder: 'سؤال سئویی خود را بپرسید… مثلاً: برای صفحه «کفش چرم» چه توضیح متایی بنویسم؟' });
				chat.appendChild(ta);
				chat.appendChild(h('div', { class: 'hs-row hs-row--end' }, [HS.btn('پرسش', { kind: 'primary', sm: true, icon: 'send', run: function () { return ask(); } })]));
				var chatOut = h('div', { class: 'hs-code', text: 'پاسخ اینجا نمایش داده می‌شود.' });
				chat.appendChild(chatOut);
				function ask() {
					chatOut.textContent = '…';
					return HS.post('ai/chat', { messages: [{ role: 'user', content: ta.value }], task: 'general' }).then(function (r) {
						chatOut.textContent = (r && (r.reply || r.content || r.message)) || JSON.stringify(r);
					}, function (e) { chatOut.textContent = 'خطا: ' + e.message; });
				}
				host.appendChild(HS.card({ title: 'گفت‌وگو', icon: 'chat' }, [chat]));

				if (jobs.length) {
					host.appendChild(HS.card({ title: 'صف پردازش (' + num(jobs.length) + ')', icon: 'clock', actions: [HS.btn('پردازش صف', { sm: true, run: function () { return HS.post('ai/process', {}).then(function (res) { HS.toast(num((res && res.processed) || 0) + ' کار انجام شد', 'ok'); HS.cacheClear('ai'); HS.renderRoute(); }, HS.fail); } })], flush: true }, [HS.table({ columns: [
						{ key: 'task', label: 'کار', width: '150px' },
						{ key: 'post_id', label: 'نوشته', align: 'num', width: '90px', render: function (j) { return j.post_id ? ('<a href="' + esc((C.site.admin || '') + 'post.php?post=' + num(j.post_id) + '&action=edit') + '" target="_blank" rel="noopener">' + num(j.post_id) + '</a>') : '—'; } },
						{ key: 'created_at', label: 'زمان', width: '140px', render: function (j) { return esc(j.created_at ? HS.ago(j.created_at) : ''); } },
						{ key: '_a', label: '', width: '130px', render: function (j) { return '<button class="hs-btn hs-btn--sm hs-btn--danger" data-cancel="' + esc(j.id) + '">لغو</button>'; } }
					], rows: jobs })]));
					on(qs('#hs-view'), 'click', '[data-cancel]', function (e, el) {
						e.preventDefault();
						HS.post('ai/cancel', { id: parseInt(el.dataset.cancel, 10) }).then(function () { HS.toast('لغو شد', 'ok'); HS.cacheClear('ai'); HS.renderRoute(); }, HS.fail);
					});
				}
			}, function (e) { host.innerHTML = ''; host.appendChild(HS.note('bad', 'بخش هوش مصنوعی خوانده نشد', e.message)); });
		}
	});
	function arrToOpts(list) { return (list || []).map(function (x) { return { id: x.id, label: x.label }; }); }

	/* ---------- titles ---------- */
	HS.view('titles', {
		title: 'قالب عنوان‌ها',
		sub: 'الگوی ساخت عنوان و توضیحات برای هر نوع صفحه',
		render: function (host) {
			var vars = ['title', 'sep', 'site', 'tagline', 'excerpt', 'category', 'term', 'keyword', 'date', 'modified', 'author', 'page', 'post_type', 'year', 'term_description', 'searchphrase', 'regular_price'];
			var tplKeys = ['title', 'single', 'page', 'archive', 'search', 'author', 'date', 'term'];
			var f = h('div', { class: 'hs-form hs-form--1' });
			var focused = null;
			tplKeys.forEach(function (k) {
				var fd = Object.assign({ key: k, type: 'textarea', rows: 2, full: true }, LABELS['titles.' + k] || { t: k });
				var ta = h('textarea', { class: 'hs-input hs-textarea', rows: 2 });
				ta.value = HS.sget('titles.' + k, '') || '';
				ta.addEventListener('focus', function () { focused = ta; });
				ta.addEventListener('change', function () { HS.markDirty('titles.' + k, ta.value); });
				f.appendChild(HS.fieldWrap(fd, ta));
			});
			var chips = h('div', { class: 'hs-row', style: { gap: '6px' } });
			vars.forEach(function (v) {
				var b = h('button', { class: 'hs-chip', type: 'button', dataset: { hs: 'var' }, text: '[[' + v + ']]', title: 'برای «' + v + '»' });
				b.addEventListener('click', function () {
					var t2 = focused || f.querySelector('textarea');
					if (!t2) { return; }
					var s2 = t2.selectionStart || t2.value.length;
					t2.value = t2.value.slice(0, s2) + '[[' + v + ']]' + t2.value.slice(s2);
					t2.dispatchEvent(new Event('change', { bubbles: true }));
					t2.focus();
				});
				chips.appendChild(b);
			});
			host.appendChild(HS.card({
				title: 'الگوها', icon: 'text',
				actions: [HS.btn(HS.I18N.save, { kind: 'primary', sm: true, icon: 'check', run: function () { return HS.flushDirty().then(function () { HS.toast('ذخیره شد', 'ok'); }); } })]
			}, [HS.note(null, 'روی یک متغیر کلیک کنید تا در کادر فعال درج شود.'), chips, f]));
			host.appendChild(HS.card({ title: 'قوانین کلی', icon: 'sliders' }, [(function () {
				var g = h('div', { class: 'hs-form' });
				[['general.title_sep', 'جداکننده'], ['titles.truncate', 'کوتاه کردن خودکار'], ['titles.auto_description', 'توضیح خودکار'], ['titles.slug_transliterate', 'ترجیع‌نگاری نامک']].forEach(function (x) {
					var fd = LABELS[x[0]] || { t: x[0] };
					var cur = HS.sget(x[0], '');
					var ctl = (typeof cur === 'boolean') ? HS.switchEl(cur, function (v) { HS.markDirty(x[0], v); }) : HS.input({ key: x[0] }, cur, function (v) { HS.markDirty(x[0], v); });
					g.appendChild(HS.fieldWrap(Object.assign({ key: x[1] }, fd), ctl));
				});
				return g;
			})()]));
			return Promise.resolve();
		}
	});

	/* ---------- settings ---------- */
	HS.view('settings', {
		title: 'تنظیمات',
		sub: 'هر چیزی که در افزونه قابل تغییر است',
		render: function (host, params) {
			var groups = HS.entries(C.settings || {});
			function walk(obj, prefix, out) {
				HS.entries(obj).forEach(function (p) {
					var key = prefix + '.' + p[0];
					var v = p[1];
					var isPlain = v && typeof v === 'object' && !Array.isArray(v) && !(v.set !== undefined && v.masked !== undefined);
					if (isPlain && String(key).split('.').length < 3) {
						walk(v, key, out);
					} else {
						out.push({ key: key, leaf: String(key).split('.').slice(2).join('.') || p[0], value: v });
					}
				});
			}
			var mode = HS.state.mode || 'easy';
			var cur = params.group && params.group !== 'general' ? params.group : (params.tab || 'general');
			if (!groups.filter(function (p) { return p[0] === cur; }).length && groups.length) { cur = groups[0][0]; }
			var bar = h('div', { class: 'hs-row hs-row--between' });
			bar.appendChild(HS.segmented([{ value: 'easy', label: 'مهم' }, { value: 'all', label: 'همه تنظیمات' }], mode, function (v) { HS.state.mode = v; HS.go('settings', { group: cur }); }));
			bar.appendChild(h('div', { class: 'hs-row' }, [
				HS.btn(HS.I18N.save, { kind: 'primary', sm: true, icon: 'check', run: function () { return HS.flushDirty().then(function () { HS.renderRoute(); }); } }),
				HS.btn('تنظیمات این بخش به حالت اول', { sm: true, kind: 'ghost', icon: 'refresh', run: function () { resetGroup(cur); } }),
				HS.btn('برون‌بری همه', { sm: true, kind: 'ghost', icon: 'download', run: function () { window.open(HS.C.rest + '/settings/export?_locale=user', '_blank'); } }),
				HS.btn('درون‌ریزی', { sm: true, kind: 'ghost', icon: 'upload', run: importJson })
			]));
			host.appendChild(bar);
			var tabsEl = HS.tabs(groups.map(function (p) { return { id: p[0], label: GROUP_LABEL[p[0]] || HS.labelOf(p[1], p[0]) }; }), cur, function (id) { HS.go('settings', { group: id }); });
			host.appendChild(tabsEl);
			var bodyHost = h('div');
			host.appendChild(bodyHost);
			paint(cur);

			function paint(group) {
				bodyHost.innerHTML = '';
				var vals = (C.settings && C.settings[group]) || {};
				var flat = [];
				walk(vals, group, flat);
				var named = [], rest = [];
				flat.forEach(function (it) {
					if (LABELS[it.key]) { named.push(it); } else { rest.push(it); }
				});
				if (named.length) { bodyHost.appendChild(section(group, named, 'تنظیمات ' + (GROUP_LABEL[group] || group))); }
				if (rest.length && mode === 'all') {
					bodyHost.appendChild(h('div', { class: 'hs-group__h', text: 'سایر تنظیمات (' + num(rest.length) + ')' }));
					bodyHost.appendChild(section(group, rest, null));
				} else if (rest.length) {
					bodyHost.appendChild(h('details', { class: 'hs-details' }, [
						h('summary', { text: 'سایر تنظیمات این بخش (' + num(rest.length) + ')' }),
						section(group, rest, null)
					]));
				}
				if (!named.length && !rest.length) {
					bodyHost.appendChild(HS.empty('تنظیمی در این بخش نیست', 'این بخش با گزینه‌های دیگر کار می‌کند.', true));
				}
			}
			function section(group, items, title) {
				var wrap = h('div', { class: 'hs-col' });
				if (title) { wrap.appendChild(h('div', { class: 'hs-group__h', text: title })); }
				items.forEach(function (it) {
					var lab = LABELS[it.key] || {};
					var row = h('div', { class: 'hs-srow' });
					var main = h('div', { class: 'hs-srow__main' }, [
						h('div', { class: 'hs-srow__t', text: lab.t || HS.labelOf(it.value, it.leaf) }),
						h('div', { class: 'hs-srow__d', text: (lab.d || '') + (lab.adv === 'danger' ? ' ⚠' : '') })
					]);
					if (HS.state.mode === 'all') { main.appendChild(h('div', { class: 'hs-srow__k hs-mono', text: it.key })); }
					var ctl = h('div', { class: 'hs-srow__ctl' });
					ctl.appendChild(control(it, lab));
					row.appendChild(main);
					row.appendChild(ctl);
					wrap.appendChild(row);
				});
				return h('div', { class: 'hs-card' }, [wrap]);
			}
			function control(it, lab) {
				var v = it.value;
				if (v && typeof v === 'object' && v.set !== undefined && v.masked !== undefined) {
					var fd = { key: it.leaf, label: lab.t || it.key, type: 'secret', placeholder: v.set ? ('••••' + v.masked) : '••••' };
					var box = HS.input(fd, '', function (nv) { if (nv) { HS.markDirty(it.key, nv); } });
					box.querySelectorAll('input').forEach(function (x) { x.setAttribute('autocomplete', 'new-password'); });
					return box;
				}
				if (OPTIONS[it.key]) {
					return HS.select(OPTIONS[it.key].map(function (o) { return { id: o.id, label: o.label }; }), v, function (nv) { HS.markDirty(it.key, nv); });
				}
				if (typeof v === 'boolean') {
					return HS.switchEl(v, function (nv) { HS.markDirty(it.key, nv); });
				}
				if (typeof v === 'number') {
					return HS.input({ key: it.leaf, type: 'number' }, v, function (nv) { HS.markDirty(it.key, nv); });
				}
				if (v && typeof v === 'object' && !Array.isArray(v)) {
					var oj = h('textarea', { class: 'hs-input hs-textarea hs-textarea--code', rows: 5 });
					oj.value = JSON.stringify(v, null, 1);
					oj.addEventListener('change', function () {
						var parsed;
						try { parsed = JSON.parse(oj.value); } catch (e) { HS.toast('JSON نامعتبر است', 'bad'); return; }
						HS.markDirty(it.key, parsed);
					});
					return oj;
				}
				if (Array.isArray(v)) {
					var isList = v.every(function (x) { return typeof x !== 'object'; });
					var ta = h('textarea', { class: 'hs-input hs-textarea', rows: Math.min(8, Math.max(2, v.length)) });
					ta.value = isList ? v.join('\n') : JSON.stringify(v, null, 1);
					ta.addEventListener('change', function () {
						var out;
						if (isList) { out = ta.value.split(/\r?\n/).map(function (x) { return x.trim(); }).filter(Boolean); }
						else { try { out = JSON.parse(ta.value); } catch (e) { HS.toast('JSON نامعتبر است', 'bad'); return; } }
						HS.markDirty(it.key, out);
					});
					return ta;
				}
				var str = String(v === null || v === undefined ? '' : v);
				if (str.length > 70 || /custom_css|summary_prompt|intro/.test(it.key)) {
					var t2 = h('textarea', { class: 'hs-input hs-textarea', rows: 5 });
					t2.value = str;
					t2.addEventListener('change', function () { HS.markDirty(it.key, t2.value); });
					return t2;
				}
				return HS.input({ key: it.leaf }, str, function (nv) { HS.markDirty(it.key, nv); });
			}
			function resetGroup(group) {
				HS.confirmBox('بازگردانی', 'تنظیمات بخش «' + (GROUP_LABEL[group] || group) + '» به مقادیر پیش‌فرض برگردد؟', function () {
					return HS.post('settings/reset', { group: group }).then(function (r) {
						HS.toast((r && r.message) || 'بازگردانی شد', 'ok');
						HS.cacheClear(''); HS.renderRoute();
					}, HS.fail);
				});
			}
			function importJson() {
				var ta = h('textarea', { class: 'hs-input hs-textarea hs-textarea--code', rows: 10, placeholder: '{"general":{"site_name":"…"}}' });
				HS.modal({
					title: 'درون‌ریزی تنظیمات', size: 'md',
					body: h('div', { class: 'hs-col' }, [HS.note('warn', 'مقادیر موجود بازنویسی می‌شود. بهتر است اول برون‌بری بگیرید.', ''), ta]),
					actions: [{ label: HS.I18N.cancel }, { label: 'درون‌ریزی', kind: 'primary', run: function () {
						return HS.post('settings/import', { content: ta.value }).then(function (r) {
							HS.toast((r && r.message) || 'درون‌ریزی شد', 'ok');
							HS.cacheClear(''); HS.renderRoute();
						}, HS.fail);
					} }]
				});
			}
			return Promise.resolve();
		}
	});

	/* ---------- tools ---------- */
	HS.view('tools', {
		title: 'ابزارها',
		sub: 'کش، فایل‌ها، مهاجرت، زمان‌بندی و عیب‌یابی',
		render: function (host) {
			host.appendChild(HS.skeleton(4));
			return Promise.all([safe(HS.get('tools/system')), safe(HS.get('migration')), safe(HS.get('audit/stats'))]).then(function (r) {
				host.innerHTML = '';
				var sys = r[0] || {};
				var mig = r[1] || {};
				var st = r[2] || {};
				host.appendChild(HS.card({ title: 'کارهای سریع', icon: 'tools' }, [
					h('div', { class: 'hs-row' }, [
						HS.btn('پاک کردن همه کش‌ها', { kind: 'primary', icon: 'refresh', run: function () { return HS.post('tools/flush', {}).then(function (r2) { HS.toast((r2 && r2.message) || 'کش پاک شد ✓', 'ok'); }, HS.fail); } }),
						HS.btn('ساخت دوباره نقشه سایت', { icon: 'map', run: function () { return HS.post('sitemap/ping', {}).then(function () { HS.toast('اعلام شد', 'ok'); }, HS.fail); } }),
						HS.btn('ارسال به اینکس‌ناو', { icon: 'upload', run: function () { return HS.post('index/indexnow', {}).then(function (r2) { HS.toast(r2 && r2.ok ? 'ارسال شد' : 'فعال نیست', r2 && r2.ok ? 'ok' : 'warn'); }, HS.fail); } }),
						HS.btn('اجرای کران حالا', { icon: 'clock', run: function () {
							return HS.post('tools/cron', { action: 'run', job: 'hoosh_seo_daily_batch' }).then(function () { HS.toast('اجرای دسته‌ها شروع شد', 'ok'); }, HS.fail);
						} }),
						HS.btn('نصب/تعمیر جدول‌ها', { kind: 'ghost', icon: 'shield', run: function () { return HS.post('tools/cron', { action: 'install' }).then(function () { HS.toast('جدول‌ها بررسی و تازه شدند', 'ok'); }, HS.fail); } })
					])
				]));

				var cols = [
					{ key: 'plugin', label: 'افزونه مبدأ', render: function (s) { return '<b>' + esc(s.label || s.key) + '</b>' + (s.active ? ' <span class="hs-tag">فعال</span>' : ''); } },
					{ key: 'total', label: 'قابل مهاجرت', align: 'num', width: '110px', render: function (s) { return num(s.total || 0); } },
					{ key: 'done', label: 'منتقل‌شده', align: 'num', width: '110px', render: function (s) { return num(s.done || 0); } },
					{ key: 'last', label: 'آخرین اجرا', width: '130px', render: function (s) { return '<span class="hs-muted hs-small">' + esc(s.last ? HS.ago(s.last) : '—') + '</span>'; } },
					{ key: '_a', label: '', width: '230px', render: function (s) {
						return '<div class="hs-row-actions"><button class="hs-btn hs-btn--sm" data-mg="preview" data-k="' + esc(s.key) + '">پیش‌نمایش</button>' +
							(s.total && !s.done ? '<button class="hs-btn hs-btn--sm hs-btn--primary" data-mg="run" data-k="' + esc(s.key) + '">انتقال</button>' : '') +
							(s.done ? '<button class="hs-btn hs-btn--sm hs-btn--danger" data-mg="undo" data-k="' + esc(s.key) + '">بازگردانی</button>' : '') + '</div>';
					} }
				];
				var srcRows = (mig.sources || mig.rows || []);
				var wrap = h('div');
				wrap.appendChild(HS.note(mig.enabled === false ? 'warn' : 'ok', mig.enabled === false ? 'مهاجرت خاموش است' : 'مهاجرت فعال است', 'قبل از انتقال، یک بکاپ بگیرید. در حالت «پیشنهاد» چیزی نوشته نمی‌شود.'));
				wrap.appendChild(HS.table({ columns: cols, rows: srcRows, emptyTitle: 'افزونه سازگاری پیدا نشد', emptyHint: 'Rank Math، Yoast، AIOSEO، SEOPress و… پشتیبانی می‌شوند.' }));
				wrap.appendChild(h('div', { class: 'hs-row hs-row--end' }, [
					HS.btn('پاک کردن متادیتای افزونه مبدأ', { kind: 'danger', sm: true, icon: 'trash', run: function () {
						HS.confirmBox('حذف داده مبدأ', 'مقادیر افزونه قبلی حذف شود؟ اول بکاپ بگیرید.', function () {
							return HS.post('migration/cleanup', { source: '' }).then(function (res) { HS.toast((res && res.message) || 'پاک شد', 'ok'); HS.renderRoute(); }, HS.fail);
						}, 'danger');
					} })
				]));
				on(wrap, 'click', '[data-mg]', function (e, el) {
					e.preventDefault();
					var k = el.dataset.k, act = el.dataset.mg;
					if (act === 'preview') {
						HS.get('migration/preview', { source: k, limit: 20 }).then(function (res) {
							res = res || {};
							HS.modal({ title: 'پیش‌نمایش انتقال', size: 'md', body: h('div', { class: 'hs-col' }, [
								h('div', { class: 'hs-row' }, [res.title && res.title.count ? HS.chip('عنوان: ' + num(res.title.count), null, { dot: false }) : null, res.description && res.description.count ? HS.chip('توضیح: ' + num(res.description.count), null, { dot: false }) : null, res.focus && res.focus.count ? HS.chip('کلمه کلیدی: ' + num(res.focus.count), null, { dot: false }) : null, res.social && res.social.count ? HS.chip('شبکه: ' + num(res.social.count), null, { dot: false }) : null]),
								h('pre', { class: 'hs-code', text: JSON.stringify((res.preview || res.rows || []).slice(0, 12), null, 1) })
							]), actions: [{ label: HS.I18N.close }, { label: 'شروع انتقال', kind: 'primary', run: function () { return runMig(k); } }] });
						}, HS.fail);
					} else if (act === 'run') { runMig(k); }
					else {
						HS.confirmBox('بازگردانی مهاجرت', 'نوشته‌ها و مقادیر منتقل‌شده از این افزونه پاک شوند؟', function () {
							return HS.post('migration/undo', { source: k }).then(function (res) { HS.toast((res && res.message) || 'بازگردانی شد', 'ok'); HS.renderRoute(); }, HS.fail);
						}, 'danger');
					}
				});
				function runMig(k) {
					return HS.post('migration/run', { source: k, limit: 100, policy: HS.sget('migration.policy', 'skip'), only: 'all' }).then(function (res) {
						HS.toast((res && res.message) || (num((res && res.migrated) || 0) + ' نوشته منتقل شد'), 'ok');
						HS.cacheClear('migration'); HS.renderRoute();
					}, HS.fail);
				}
				host.appendChild(HS.card({ title: 'مهاجرت از افزونه‌های دیگر', icon: 'download', flush: true }, [wrap]));

				var info = h('div', { class: 'hs-kvs' });
				var rows = [];
				if (sys.rows && sys.rows.length) { rows = sys.rows; }
				else {
					[['نسخه افزونه', C.version], ['WordPress', C.site.wp], ['PHP', C.site.php], ['قالب', C.site.theme], ['میزبان', C.site.host], ['SSL', C.features.isSsl ? 'فعال' : 'خاموش'], ['Nginx', C.features.nginx ? 'بله' : 'خیر'], ['cron', (C.features.cron ? (C.features.cron.healthy === false ? 'ناسالم' : 'سالم') : '—')], ['حافظه', sys.php && sys.php.memory], ['جدول‌ها', sys.module && sys.module.tables ? HS.entries(sys.module.tables).length : '—'], ['کاربر', C.user.name + ' (' + num(C.user.id) + ')']]
						.forEach(function (p) { if (p[1] !== undefined && p[1] !== null && p[1] !== '') { rows.push({ label: p[0], value: String(p[1]) }); } });
				}
				rows.forEach(function (r) {
					info.appendChild(h('div', { class: 'hs-kv' }, [h('span', { class: 'hs-kv__k', text: r.label || '' }), h('span', { class: 'hs-kv__v', text: r.value || (r[1] || '') })]));
				});
				host.appendChild(HS.card({ title: 'اطلاعات سامانه', icon: 'info', actions: [HS.btn('کپی گزارش', { sm: true, run: function () { HS.copyText(rows.map(function (x) { return (x.label || x[0]) + ': ' + (x.value || x[1]); }).join('\n')); } })] }, [info]));
				host.appendChild(HS.card({ title: 'پاکسازی', icon: 'trash' }, [
					HS.note(null, 'داده‌های کهنایی که ارزش نگه‌داشتن ندارند از جدول‌ها حذف می‌شوند.', ''),
					h('div', { class: 'hs-row' }, [
						HS.btn('حذف بازدیدهای قدیمی ریدایرکت', { sm: true, run: function () { return HS.post('redirects/prune', {}).then(function (r) { HS.toast(num((r && r.pruned) || 0) + ' بازدید پاک شد', 'ok'); }, HS.fail); } }),
						HS.btn('حذف گزارش ۴۰۴ قدیمی', { sm: true, run: function () { return HS.post('notfound/prune', {}).then(function (r) { HS.toast(num((r && r.pruned) || 0) + ' مورد پاک شد', 'ok'); }, HS.fail); } }),
						HS.btn('بستن کارهای مانده هوش مصنوعی', { sm: true, run: function () { return HS.post('ai/cancel', { id: 0 }).then(function () { HS.toast('بررسی شد', 'ok'); }, HS.fail); } })
					]),
					h('div', { class: 'hs-muted hs-small', text: num(st.recent_404 || 0) + ' خطای ۴۰۴ در ۷ روز اخیر · ' + num(st.reverted || 0) + ' بازگردانی انجام‌شده' })
				]));
				return undefined;
			});
		}
	});

	/* ---------- help ---------- */
	HS.view('help', {
		title: 'راهنما و عیب‌یابی',
		sub: 'پرسش‌های رایج، وضعیت سامانه و راه‌های تماس',
		render: function (host) {
			var faq = [
				['چرا تغییرات در سایت اعمال نمی‌شود؟', 'اگر افزونه کش (WP Rocket، LiteSpeed، W3TC) دارید، کش را پاک کنید. برای ریدایرکت‌ها، یک «پاک کردن همه کش‌ها» از بخش ابزارها کافی است.'],
				['نقشه سایت باز نمی‌شود', 'در تنظیمات → پیوندهای یکتا، «بازنویسی» را ذخیره کنید تا قوانین .htaccess بازسازی شوند. اگر Nginx دارید، باید rule نگاشت برای sitemap_index.xml اضافه شود.'],
				['توضیحات متای خودکار کوتاه/بلند است', 'در تنظیمات → محتوا، حداقل و حداکثر طول توضیحات و «کوتاه کردن خودکار عنوان» را ببینید.'],
				['هوش مصنوعی کار نمی‌کند', 'کلید سرویس را در بخش هوش مصنوعی وارد و با دکمه «آزمون» بررسی کنید. اگر سقف هزینه ماهانه پر شده، تا ابتدای ماه بعد انتظار می‌ماند.'],
				['رتبه‌ها به‌روز نمی‌شود', 'اتصال رتبه نیازمند سرویس SERP است؛ اگر وصل نیست، افزونه رتبه را از Search Console می‌خواند و با تأخیر به‌روز می‌شود.'],
				['چطور مطمئن شوم چیزی خراب نشده؟', 'هر تغییر در جدول‌ها یا فایل‌ها، در بخش ممیزی با «پیش/پس» ثبت می‌شود و قابل بازگردانی است.']
			];
			var list = h('div', { class: 'hs-col' });
			faq.forEach(function (p) {
				var d = h('div', { class: 'hs-dcard' });
				d.appendChild(h('div', { class: 'hs-dcard__t', text: p[0] }));
				d.appendChild(h('div', { class: 'hs-dcard__d', text: p[1] }));
				list.appendChild(d);
			});
			host.appendChild(HS.card({ title: 'پرسش‌های رایج', icon: 'help' }, [list]));
			host.appendChild(HS.card({ title: 'کوتاه‌راه‌ها', icon: 'keyboard' }, [
				(function () {
					var t = h('table', { class: 'hs-table__simple' });
					[['Ctrl/⌘ + K', 'جست‌وجوی سراسری و کوتاه‌راه‌ها'], ['g سپس d', 'رفتن به داشبورد'], ['Esc', 'بستن پنجره باز'], ['ترک کردن فیلد', 'ذخیره خودکار همان گزینه']].forEach(function (r) {
						var tr = document.createElement('tr');
						tr.appendChild(h('td', { html: '<code class="hs-kbd">' + esc(r[0]) + '</code>' }));
						tr.appendChild(h('td', { text: r[1] }));
						t.appendChild(tr);
					});
					return t;
				})()
			]));
			host.appendChild(HS.card({ title: 'مستندات و فایل‌ها', icon: 'document' }, [
				h('div', { class: 'hs-row' }, [
					HS.btn('نصب و راه‌اندازی (فارسی)', { kind: 'primary', sm: true, icon: 'document', run: function () { HS.get('docs/install').then(function (r) { HS.modal({ title: 'نصب و راه‌اندازی', size: 'lg', body: h('div', { class: 'hs-doc', html: (r && r.html) || '<p>در دسترس نیست</p>' }), actions: [{ label: HS.I18N.close }] }); }, function () { HS.toast('فایل راهنما پیدا نشد', 'warn'); }); } }),
					HS.btn('تنظیمات', { sm: true, kind: 'ghost', icon: 'settings', run: function () { HS.go('settings'); } })
				]),
				HS.note(null, 'پرونده‌های افزونه در ' + (C.dir || 'wp-content/plugins/hoosh-seo') + ' قرار دارد. جدول‌ها با پیشوند hoosh_ در پایگاه داده ساخته می‌شوند.')
			]));
			host.appendChild(HS.card({ title: 'وضعیت نهایی', icon: 'stethoscope' }, [
				HS.kpis([
					{ label: 'نسخه', value: C.version, plain: true },
					{ label: 'پوسته', value: C.appearance && C.appearance.theme, plain: true },
					{ label: 'هوش مصنوعی', value: C.features && C.features.aiReady ? 'آماده' : 'تنظیم‌نشده' },
					{ label: 'اینکس‌ناو', value: C.features && C.features.indexnow ? 'فعال' : 'خاموش' }
				])
			]));
			return Promise.resolve();
		}
	});


/* =================== schema studio =================== */
(function (HS) {
	'use strict';
	var h = HS.h, on = HS.on, num = HS.num, esc = HS.esc, C = HS.C;

	var AUTO = [
		{ key: 'schema.website', label: 'WebSite', note: 'هویت سایت، نام و جست‌وجو' },
		{ key: 'schema.article', label: 'Article', note: 'نویسنده، تاریخ‌ها، تصویر' },
		{ key: 'schema.breadcrumbs', label: 'BreadcrumbList', note: 'مسیر راهنما' },
		{ key: 'schema.faq', label: 'FAQPage', note: 'پرسش‌های ثبت‌شده در هر نوشته' },
		{ key: 'schema.howto', label: 'HowTo', note: 'مراحل آموزش' },
		{ key: 'schema.person', label: 'Person', note: 'پروفایل نویسنده' },
		{ key: 'schema.org', label: 'Organization', note: 'شرکت، لوگو، همان‌آدرس‌ها' },
		{ key: 'schema.video', label: 'VideoObject', note: 'ویدیوی جاسازی‌شده' },
		{ key: 'schema.local_business', label: 'LocalBusiness', note: 'آدرس، ساعت، تلفن' }
	];

	HS.view('schema', {
		title: 'استودیوی اسکیما',
		sub: 'گراف JSON-LD، قوانین سفارشی و قالب‌های آمادهٔ صنفی',
		render: function (host) {
			host.appendChild(HS.skeleton(4));
			return HS.get('schema').then(function (res) {
				host.innerHTML = '';
				res = res || {};
				var rows = res.rows || [];
				var types = res.types || {};
				var templates = res.templates || [];
				var tEntries = HS.entries(templates);

				/* ---- auto graph ---- */
				var box = h('div', { class: 'hs-srows' });
				AUTO.forEach(function (a) {
					var row = h('div', { class: 'hs-srow' });
					row.appendChild(h('div', { class: 'hs-srow__main' }, [
						h('div', { class: 'hs-srow__t' }, [document.createTextNode(a.label), types[a.label] && types[a.label].rich ? HS.chip('نتیجهٔ غنی', 'info', { dot: false }) : null]),
						h('div', { class: 'hs-srow__d', text: a.note })
					]));
					row.appendChild(h('div', { class: 'hs-srow__ctl' }, [HS.switchEl(!!HS.sget(a.key, true), function (v) {
						HS.markDirty(a.key, v);
						return HS.flushDirty().then(function () { HS.toast(v ? 'به گراف اضافه شد' : 'از گراف حذف شد', 'ok', { ms: 1400 }); }, HS.fail);
					})]));
					box.appendChild(row);
				});
				host.appendChild(HS.card({
					title: 'گراف خودکار', icon: 'braces',
					sub: 'هر نوع محتوا که فعال باشد، برای همهٔ صفحه‌های مناسب ساخته می‌شود',
					actions: [
						HS.btn('پیش‌نمایش گراف', { kind: 'primary', sm: true, icon: 'eye', run: function () { preview(0); } }),
						HS.btn('اعتبارسنجی قوانین', { sm: true, icon: 'stethoscope', run: function () { validateAll(); } })
					]
				}, [box, res.foreign && res.foreign.length ? HS.note('warn', 'افزونهٔ اسکیماى دیگر فعال است', res.foreign.join('، ') + ' — برای جلوگیری از تکرار، آن را غیرفعال کنید یا از تنظیمات، گراف ما را در `suppress` بگذارید.') : null]));

				/* ---- custom rules ---- */
				var cols = [
					{ key: 'name', label: 'نام', render: function (r) { return '<b>' + esc(r.name || '—') + '</b>'; } },
					{ key: 'schema_type', label: 'نوع', width: '170px', render: function (r) { return '<span class="hs-tag">' + esc(r.schema_type || '') + '</span>' + ((types[r.schema_type] && types[r.schema_type].label) ? ' <span class="hs-muted hs-small">' + esc(types[r.schema_type].label) + '</span>' : ''); } },
					{ key: 'location', label: 'محل چاپ', width: '110px', render: function (r) { return '<span class="hs-muted hs-small">' + esc(({ head: 'هد', body_start: 'ابتدای بدنه', footer: 'پاورقی' })[r.location] || r.location || 'head') + '</span>'; } },
					{ key: 'print_count', label: 'چاپ‌ها', align: 'num', width: '90px', render: function (r) { return num(r.print_count || 0); } },
					{ key: 'status', label: 'وضعیت', width: '90px', render: function (r) { return r.status === 'active' ? HS.chip('فعال', 'ok', { dot: false }).outerHTML : HS.chip('غیرفعال', null, { dot: false }).outerHTML; } },
					{ key: 'updated_at', label: 'به‌روزرسانی', width: '130px', render: function (r) { return '<span class="hs-muted hs-small">' + esc(r.updated_at ? HS.ago(r.updated_at) : '—') + '</span>'; } },
					{ key: '_a', label: '', width: '170px', render: function (r) {
						return '<div class="hs-row-actions"><button class="hs-btn hs-btn--sm" data-a="edit" data-id="' + esc(r.id) + '">ویرایش</button>' +
							'<button class="hs-btn hs-btn--sm hs-btn--danger" data-a="del" data-id="' + esc(r.id) + '">×</button></div>';
					} }
				];
				var tableHost = h('div');
				function paintTable() {
					tableHost.innerHTML = '';
					if (!rows.length) {
						tableHost.appendChild(HS.empty('قاعدهٔ سفارشی ندارید', 'یک نوع اسکیما انتخاب کنید و روی چه صفحه‌هایی چاپ شود را مشخص کنید؛ مثلاً Event برای رویدادها یا Service برای خدمات.', false,
							h('div', { class: 'hs-row' }, [HS.btn('افزودن قانون', { kind: 'primary', run: function () { edit(null); } }), HS.btn('قالب‌های آماده', { run: gallery })])));
						return;
					}
					var t = HS.table({ columns: cols, rows: rows, picky: true, rowKey: 'id',
						onPick: function (ids) { pick.replaceChildren(
							HS.btn('حذف (' + num(ids.length) + ')', { sm: true, kind: 'danger', run: function () { return HS.post('schema/delete', { ids: ids }).then(function () { HS.cacheClear('schema'); HS.renderRoute(); }, HS.fail); } }),
							HS.btn('اعتبارسنجی (' + num(ids.length) + ')', { sm: true, run: function () { validateAll(ids); } })
						); } });
					var pick = h('div', { class: 'hs-row', style: { padding: '10px 16px' } });
					on(t, 'click', '[data-a]', function (e, el) {
						e.preventDefault(); e.stopPropagation();
						var id = parseInt(el.dataset.id, 10);
						var row = rows.filter(function (r) { return String(r.id) === String(id); })[0] || {};
						if (el.dataset.a === 'del') {
							HS.confirmBox('حذف قانون', 'این قانون اسکیما حذف شود؟', function () {
								return HS.post('schema/delete', { ids: [id] }).then(function () { HS.cacheClear('schema'); HS.renderRoute(); }, HS.fail);
							}, 'danger');
						} else { edit(row); }
					});
					tableHost.appendChild(t);
					tableHost.appendChild(pick);
				}
				paintTable();
				host.appendChild(HS.card({
					title: 'قوانین سفارشی (' + num(rows.length) + ')', icon: 'list', flush: true,
					actions: [
						HS.btn('افزودن قانون', { kind: 'primary', sm: true, icon: 'plus', run: function () { edit(null); } }),
						HS.btn('قالب‌های آماده', { sm: true, icon: 'grid', run: gallery }),
						HS.btn('از یک آدرس بردار', { sm: true, kind: 'ghost', icon: 'download', run: importUrl })
					]
				}, [tableHost]));

				/* ---- preview / validate ---- */
				function preview(postId) {
					var hostModal = h('div', { class: 'hs-col' }, [h('div', { class: 'hs-loading', text: 'ساخت گراف…' })]);
					HS.modal({ title: 'گراف JSON-LD' + (postId ? (' · نوشته ' + num(postId)) : ' · صفحهٔ اصلی'), size: 'lg', body: hostModal, actions: [{ label: HS.I18N.close }] });
					HS.get('schema/preview', postId ? { post_id: postId } : {}).then(function (r) {
						r = r || {};
						hostModal.innerHTML = '';
						var issues = (r.valid && r.valid.issues) || r.issues || [];
						hostModal.appendChild(HS.note(issues.length ? 'warn' : 'ok',
							issues.length ? (num(issues.length) + ' نکتهٔ اعتبارسنجی') : 'گراف بدون خطاست ✓',
							issues.slice(0, 8).map(function (i) { return (i.message || '') + (i.node !== undefined ? (' (گره ' + num(i.node) + ')') : ''); }).join(' · ')));
						hostModal.appendChild(h('div', { class: 'hs-row hs-row--between' }, [
							h('span', { class: 'hs-muted hs-small', text: 'محل چاپ: ' + ((r.location || 'head').replace('_', ' ')) + ' · ' + num((r.nodes || []).length) + ' گره' }),
							h('div', { class: 'hs-row' }, [
								HS.btn('کپی JSON', { sm: true, run: function () { HS.copyText(r.json || JSON.stringify({ '@graph': r.nodes }, null, 2)); } }),
								HS.btn('تست در گوگل', { sm: true, kind: 'ghost', icon: 'external', href: 'https://search.google.com/test/rich-results?url=' + encodeURIComponent(C.site.home || ''), run: null })
							])
						]));
						hostModal.appendChild(h('pre', { class: 'hs-code', text: r.json || JSON.stringify({ '@context': 'https://schema.org', '@graph': r.nodes || [] }, null, 2) }));
						if (r.suppress && HS.entries(r.suppress).length) {
							hostModal.appendChild(HS.note(null, 'انواع سرکوب‌شده در تنظیمات:', HS.entries(r.suppress).map(function (p) { return p[0]; }).join('، ')));
						}
					}, function (e) { hostModal.innerHTML = ''; hostModal.appendChild(HS.note('bad', 'پیش‌نمایش ساخته نشد', e.message)); });
				}
				function validateAll(ids) {
					var nodes = rows.filter(function (r) { return !ids || !ids.length || ids.map(String).indexOf(String(r.id)) > -1; }).map(function (r) {
						return Object.assign({ '@type': r.schema_type }, r.data || {});
					});
					if (!nodes.length) { HS.toast('قاعده‌ای برای بررسی نیست', 'info'); return; }
					HS.post('schema/validate', { nodes: nodes }).then(function (r) {
						var issues = (r && r.issues) || [];
						HS.modal({
							title: 'اعتبارسنجی ' + num(nodes.length) + ' قانون', size: 'md',
							body: issues.length ? h('div', { class: 'hs-list' }, issues.map(function (i) {
								return h('div', { class: 'hs-list__item' }, [h('span', { class: 'hs-dot is-' + (i.level === 'error' ? 'bad' : 'warn') }), h('div', { class: 'hs-list__main' }, [h('div', { class: 'hs-list__title', text: i.message || '' }), h('div', { class: 'hs-list__meta', text: 'گره ' + num(i.node || 0) })])]);
							})) : HS.empty('همه چیز درست است ✓', 'هیچ خطای ساختاری پیدا نشد.'),
							actions: [{ label: HS.I18N.close }]
						});
					}, HS.fail);
				}
				function gallery() {
					var body = h('div', { class: 'hs-cols hs-cols--3' });
					if (!tEntries.length) { body.appendChild(HS.empty('قالبی نیست', null, true)); }
					tEntries.forEach(function (p) {
						var t = p[1] || {};
						var c = h('article', { class: 'hs-modcard' });
						c.appendChild(h('h3', { class: 'hs-modcard__title', text: t.label || (t.type + ' · ' + p[0]) }));
						c.appendChild(h('p', { class: 'hs-modcard__desc', text: t.description || '' }));
						c.appendChild(h('div', { class: 'hs-row', style: { flexWrap: 'wrap' } }, [
							(t.required || []).slice(0, 4).map(function (f) { return h('span', { class: 'hs-tag', text: f }); }),
							(t.recommended || []).slice(0, 3).map(function (f) { return h('span', { class: 'hs-tag hs-tag--mut', text: f }); })
						]));
						c.appendChild(h('div', { class: 'hs-modcard__foot' }, [HS.btn('استفاده', { sm: true, kind: 'primary', run: function () { edit({ name: t.label || t.type, type: t.type, data: t.data || {}, conditions: {}, location: 'head', status: 'active' }, t); } })]));
						body.appendChild(c);
					});
					HS.modal({ title: 'قالب‌های آماده', size: 'lg', body: body, actions: [{ label: HS.I18N.close }] });
				}
				function importUrl() {
					var inp = h('input', { class: 'hs-input', placeholder: 'https://example.com/competitor-page' });
					var out = h('div');
					HS.modal({
						title: 'برداری اسکیما از یک آدرس', size: 'md',
						body: h('div', { class: 'hs-col' }, [HS.note(null, 'JSON-LD آن صفحه خوانده می‌شود؛ می‌توانید هر گره را به‌عنوان قانون سفارشی ذخیره کنید.'), inp, out]),
						actions: [{ label: HS.I18N.cancel }, { label: 'بخوان', kind: 'primary', keepOpen: true, run: function () {
							return HS.post('schema/import', { url: inp.value }).then(function (r) {
								out.innerHTML = '';
								var found = (r && (r.nodes || r.rows || r.found)) || [];
								if (!found.length) { out.appendChild(HS.empty('گرفی پیدا نشد', 'آن صفحه JSON-LD ندارد یا دسترسی بسته است.')); return; }
								out.appendChild(HS.note('ok', num(found.length) + ' گره پیدا شد', ''));
								found.forEach(function (n) {
									out.appendChild(h('div', { class: 'hs-list__item' }, [
										h('div', { class: 'hs-list__main' }, [h('div', { class: 'hs-list__title', text: (n['@type'] || 'گره') }), h('div', { class: 'hs-list__meta hs-mono', text: JSON.stringify(n).slice(0, 160) })]),
										HS.btn('ذخیره', { sm: true, run: function () {
											return HS.post('schema', { name: (n['@type'] || 'Schema') + ' (برداشت‌شده)', type: Array.isArray(n['@type']) ? n['@type'][0] : (n['@type'] || 'WebPage'), data: n, location: 'head', status: 'active' }).then(function () {
												HS.toast('به قوانین اضافه شد', 'ok');
												HS.cacheClear('schema');
											}, HS.fail);
										} })
									]));
								});
							}, function (e) { out.innerHTML = ''; out.appendChild(HS.note('bad', 'خوانده نشد', e.message)); });
						} }]
					});
				}

				/* ---- rule editor ---- */
				function edit(row, tpl) {
					var d = row && row.id ? Object.assign({}, row, { type: row.schema_type }) : Object.assign({ name: '', type: 'WebPage', data: {}, conditions: { logic: 'all', rules: [] }, location: 'head', status: 'active' }, row || {});
					var typeOpts = HS.entries(types).map(function (p) { return { id: p[0], label: (p[1] && p[1].label ? p[1].label + ' — ' : '') + p[0] }; });
					if (!typeOpts.length) { typeOpts = ['WebPage', 'Article', 'Product', 'FAQPage', 'HowTo', 'Event', 'Service', 'LocalBusiness', 'Person', 'Organization'].map(function (x) { return { id: x, label: x }; }); }
					var fields = h('div', { class: 'hs-form hs-form--1' });
					fields.appendChild(HS.fieldWrap({ key: 'name', label: 'نام قانون', help: 'فقط برای شناسایی در این فهرست.' }, HS.input({ key: 'name' }, d.name, function (v) { d.name = v; })));
					fields.appendChild(HS.fieldWrap({ key: 'type', label: 'نوع اسکیما', help: (tpl && tpl.description) || '' }, HS.select(typeOpts, d.type, function (v) { d.type = v; syncHints(); })));

					var json = h('textarea', { class: 'hs-input hs-textarea hs-textarea--code', rows: 12, spellcheck: 'false' });
					json.value = JSON.stringify(d.data && Object.keys(d.data).length ? d.data : seedFor(d.type, tpl), null, 2);
					var hints = h('div', { class: 'hs-muted hs-small' });
					function syncHints() {
						var def = types[json.dataset.type || d.type] || types[d.type] || {};
						hints.innerHTML = '';
						if ((def.required || []).length) { hints.appendChild(h('div', { html: 'پر کردن این‌ها لازم است: ' + (def.required || []).map(function (x) { return '<code>' + esc(x) + '</code>'; }).join(' ') })); }
						if ((def.recommended || []).length) { hints.appendChild(h('div', { html: 'و این‌ها کمک می‌کند: ' + (def.recommended || []).map(function (x) { return '<code>' + esc(x) + '</code>'; }).join(' ') })); }
					}
					json.addEventListener('change', function () {
						try { d.data = JSON.parse(json.value || '{}'); json.classList.remove('is-bad-input'); }
						catch (e) { json.classList.add('is-bad-input'); HS.toast('JSON نامعتبر است', 'bad'); }
					});
					fields.appendChild(HS.fieldWrap({ key: 'data', label: 'محتوای گره (JSON)', help: 'متغیرها کار می‌کنند: {{permalink}}، {{title}}، {{date}}، {{modified}}، {{excerpt}} و [[title]]، [[site]]، [[author]]، [[primary_cat]]' }, json));
					fields.appendChild(hints);

					var cond = h('div', { class: 'hs-col' });
					function paintCond() {
						cond.innerHTML = '';
						((d.conditions && d.conditions.rules) || []).forEach(function (r, i) {
							var rowEl = h('div', { class: 'hs-row' });
							rowEl.appendChild(HS.select([
								{ id: 'post_type', label: 'نوع محتوا' },
								{ id: 'term', label: 'دسته/برچسب' },
								{ id: 'specific_post', label: 'نوشتهٔ خاص' },
								{ id: 'front', label: 'صفحهٔ خانه' },
								{ id: 'archive', label: 'بایگانی' }
							], r.type, function (v) { r.type = v; }));
							rowEl.appendChild(HS.select([{ id: 'is', label: 'هست' }, { id: 'not', label: 'نیست' }], r.compare, function (v) { r.compare = v; }));
							var val = h('input', { class: 'hs-input', placeholder: 'post، page … یا ID', value: (r.value || []).join(',') });
							val.addEventListener('change', function () { r.value = val.value.split(',').map(function (x) { return x.trim(); }).filter(Boolean); });
							rowEl.appendChild(val);
							rowEl.appendChild(HS.btn('×', { sm: true, kind: 'ghost', run: function () { d.conditions.rules.splice(i, 1); paintCond(); } }));
							cond.appendChild(rowEl);
						});
						cond.appendChild(h('div', { class: 'hs-row' }, [
							HS.btn('افزودن شرط', { sm: true, icon: 'plus', run: function () { d.conditions = d.conditions || {}; d.conditions.rules = (d.conditions.rules || []).concat([{ type: 'post_type', compare: 'is', value: ['post'] }]); paintCond(); } }),
							HS.segmented([{ id: 'all', label: 'همهٔ شرط‌ها' }, { id: 'any', label: 'یکی از شرط‌ها' }], (d.conditions && d.conditions.logic) || 'all', function (v) { d.conditions = d.conditions || {}; d.conditions.logic = v; })
						]));
					}
					fields.appendChild(HS.fieldWrap({ key: 'conditions', label: 'کجا چاپ شود', help: 'بدون شرط، روی همهٔ صفحه‌های تکی چاپ می‌شود.' }, cond));
					paintCond();
					fields.appendChild(h('div', { class: 'hs-form' }, [
						HS.fieldWrap({ key: 'location', label: 'محل چاپ' }, HS.select([{ id: 'head', label: 'هد (پیشنهادی)' }, { id: 'body_start', label: 'ابتدای بدنه' }, { id: 'footer', label: 'پاورقی' }], d.location, function (v) { d.location = v; })),
						HS.fieldWrap({ key: 'status', label: 'وضعیت' }, HS.select([{ id: 'active', label: 'فعال' }, { id: 'inactive', label: 'غیرفعال' }], d.status, function (v) { d.status = v; }))
					]));
					HS.drawer({
						title: row && row.id ? 'ویرایش قانون اسکیما' : 'قانون جدید',
						body: fields,
						actions: [
							{ label: HS.I18N.cancel },
							{ label: HS.I18N.save, kind: 'primary', run: function () {
								try { d.data = JSON.parse(json.value || '{}'); } catch (e) { HS.toast('JSON را اصلاح کنید', 'bad'); return Promise.reject(new Error('json')); }
								return HS.post('schema', d).then(function (r) {
									HS.toast((r && r.message) || 'قانون ذخیره شد', 'ok');
									HS.cacheClear('schema');
									HS.renderRoute();
								}, HS.fail);
							} }
						]
					});
					syncHints();
				}
				function seedFor(type, tpl) {
					var def = types[type] || {};
					var out = {};
					(tpl && tpl.data ? Object.keys(tpl.data) : (def.required || ['name'])).forEach(function (k) { out[k] = (tpl && tpl.data && tpl.data[k]) || ''; });
					if (type === 'BreadcrumbList' || type === 'FAQPage') { out['@type'] = undefined; }
					return out;
				}

				host.appendChild(HS.note(null, 'این گراف در <head> چاپ می‌شود و با داده‌های هر نوشته پر می‌شود؛ قوانین سفارشی روی آن سوار می‌شوند.', 'اگر نمی‌خواهید افزونه گراف بسازد، همهٔ کلیدها را در بخش تنظیمات → اسکیما خاموش کنید.'));
			}, function (e) { host.innerHTML = ''; host.appendChild(HS.note('bad', 'اسکیما خوانده نشد', e.message)); });
		}
	});
})(HS);

/* =================== mission control: autonomous agent =================== */
(function (HS) {
	'use strict';
	var h = HS.h, num = HS.num, trunc = HS.trunc, esc = HS.esc;

	var RISK = { low: 'کم', medium: 'متوسط', high: 'بالا' };
	var RISK_KIND = { low: 'ok', medium: 'warn', high: 'bad' };
	var STATUS = { done: 'انجام شد', review: 'در انتظار تأیید', skipped: 'رد شد', failed: 'ناموفق', planned: 'برنامه‌ریزی شده' };
	var STATUS_KIND = { done: 'ok', review: 'warn', skipped: null, failed: 'bad', planned: null };

	function chip(text, kind) { return HS.chip(text, kind || null, { dot: false }); }

	function ago(ts) {
		if (!ts) { return '—'; }
		var d = (Date.now() / 1000) - parseInt(ts, 10);
		if (d < 60) { return 'همین حالا'; }
		if (d < 3600) { return num(Math.round(d / 60)) + ' دقیقه پیش'; }
		if (d < 86400) { return num(Math.round(d / 3600)) + ' ساعت پیش'; }
		return num(Math.round(d / 86400)) + ' روز پیش';
	}

	function scoreRing(score) {
		var v = Math.max(0, Math.min(100, parseFloat(score) || 0));
		var kind = v >= 75 ? 'ok' : (v >= 50 ? 'warn' : 'bad');
		var box = h('div', { class: 'hs-scorebox hs-scorebox--' + kind });
		box.appendChild(h('strong', { text: num(Math.round(v)) }));
		box.appendChild(h('span', { text: 'از ۱۰۰' }));
		box.appendChild(h('div', { class: 'hs-scorebox__bar' }, [h('i', { style: { width: v + '%' } })]));
		return box;
	}

	/* ---------- run ledger drawer ---------- */
	function openRun(id) {
		var body = h('div', {}, [HS.skeleton(3)]);
		HS.drawer({ title: 'جزئیات اجرا #' + num(id), body: body });
		HS.get('agent/run/' + num(id), {}, { force: true }).then(function (res) {
			body.innerHTML = '';
			if (!res || !res.ok) {
				body.appendChild(HS.note('bad', 'خوانده نشد', (res && res.message) || ''));
				return;
			}
			var run = res.run || {};
			var steps = res.steps || [];

			body.appendChild(HS.kpis([
				{ label: 'وضعیت', value: run.status || '—' },
				{ label: 'گام‌ها', value: num(run.steps_done || 0) + ' از ' + num(run.steps_total || 0) },
				{ label: 'هزینه', value: '$' + num(parseFloat(run.cost_usd || 0).toFixed(4)) },
				{ label: 'امتیاز', value: num(run.score_before || 0) + ' ← ' + num(run.score_after || 0) }
			]));
			if (run.summary) { body.appendChild(HS.note(null, run.summary)); }

			var plan = run.plan;
			if (typeof plan === 'string') { try { plan = JSON.parse(plan); } catch (e) { plan = null; } }
			if (plan && plan.rationale && plan.rationale.length) {
				body.appendChild(HS.card({ title: 'چرا این کارها', icon: 'sparkle' }, [
					h('ul', { class: 'hs-list' }, plan.rationale.map(function (r) { return h('li', { text: r }); }))
				]));
			}

			body.appendChild(HS.card({ title: 'گام‌ها', icon: 'check-list', flush: true }, [
				HS.table({
					columns: [
						{ key: 'seq', label: '#', width: '48px', render: function (r) { return num(r.seq); } },
						{ key: 'skill', label: 'مهارت', render: function (r) { return h('span', { text: r.skill || '' }); } },
						{ key: 'title', label: 'کار', render: function (r) { return h('span', { text: r.title || '' }); } },
						{ key: 'status', label: 'وضعیت', width: '120px', render: function (r) { return chip(STATUS[r.status] || r.status, STATUS_KIND[r.status]); } },
						{ key: 'ms', label: 'زمان', width: '80px', render: function (r) { return num(r.ms || 0) + 'ms'; } },
						{ key: 'act', label: '', width: '90px', render: function (r) {
							if (String(r.reverted) === '1') { return chip('بازگشت داده شد'); }
							return HS.btn('بازگشت', { sm: true, kind: 'ghost', run: function () {
								return HS.post('agent/revert/' + num(r.id), {}).then(function () {
									HS.toast('بازگردانده شد', 'ok'); openRun(id);
								}, HS.fail);
							} });
						} }
					],
					rows: steps,
					rowKey: 'id',
					empty: { title: 'گامی ثبت نشده' }
				})
			]));
		}, function (e) { body.innerHTML = ''; body.appendChild(HS.note('bad', 'خطا', e.message)); });
	}

	HS.view('agent', {
		title: 'ایجنت خودکار سئو',
		sub: 'سایت را می‌سنجد، تصمیم می‌گیرد، اصلاح می‌کند و اگر ایرادی نبود محتوا می‌سازد',
		render: function (host) {
			host.appendChild(HS.skeleton(4));
			HS.get('agent/status', {}, { force: true }).then(function (st) {
				host.innerHTML = '';
				if (!st) { host.appendChild(HS.note('bad', 'ایجنت خوانده نشد')); return; }

				var totals = st.totals || {};
				var limits = st.limits || {};
				var gate = st.can_run || {};

				/* ---- header ---- */
				host.appendChild(h('div', { class: 'hs-row hs-row--between hs-agenthead' }, [
					h('div', { class: 'hs-row' }, [
						scoreRing((st.totals && st.totals.last_score) || 0),
						h('div', {}, [
							h('h2', { class: 'hs-agenthead__title', text: st.enabled ? (st.halted ? 'متوقف شده' : 'آمادهٔ کار') : 'خاموش' }),
							h('p', { class: 'hs-muted', text: st.enabled
								? ('سطح خودمختاری: ' + ((st.autonomy_levels || []).filter(function (a) { return a.id === st.autonomy; })[0] || {}).label)
								: 'برای شروع، ایجنت را روشن کنید و یک سرویس هوش مصنوعی تنظیم کنید.' })
						])
					]),
					h('div', { class: 'hs-row' }, [
						HS.btn('اجرای آزمایشی', { sm: true, icon: 'eye', title: 'بدون تغییر چیزی، فقط می‌گوید چه می‌کرد', run: function () {
							return HS.post('agent/run', { dry_run: true }).then(function (res) {
								var n = (res && res.plan && res.plan.count) || 0;
								HS.toast('برنامه: ' + num(n) + ' گام (چیزی تغییر نکرد)', 'ok');
								showPlan(res);
							}, HS.fail);
						} }),
						HS.btn('اجرای واقعی', { kind: 'primary', sm: true, icon: 'play', run: function () {
							HS.confirmBox('ایجنت الان کار کند؟', 'طبق سطح خودمختاری فعلی، تغییرات ممکن است مستقیم اعمال شوند. همهٔ گام‌ها در دفتر کار ثبت می‌شوند و قابل بازگشت‌اند.', function () {
								HS.toast('ایجنت شروع به کار کرد…', 'ok');
								HS.post('agent/run', {}).then(function (res) {
									HS.toast((res && res.summary) || 'تمام شد', res && res.ok ? 'ok' : 'warn');
									HS.cacheClear('agent'); HS.renderRoute();
								}, HS.fail);
							});
						} }),
						HS.btn(st.halted ? 'از سرگیری' : 'توقف اضطراری', { sm: true, kind: st.halted ? 'primary' : 'ghost', icon: st.halted ? 'play' : 'warning', run: function () {
							return HS.post('agent/halt', { halt: !st.halted }).then(function () {
								HS.toast(st.halted ? 'ایجنت از سر گرفته شد' : 'ایجنت متوقف شد', 'ok');
								HS.cacheClear('agent'); HS.renderRoute();
							}, HS.fail);
						} })
					])
				]));

				if (st.halted) { host.appendChild(HS.note('bad', 'کلید توقف اضطراری فعال است', 'تا وقتی آن را برنگردانید هیچ اجرای خودکاری انجام نمی‌شود.')); }
				else if (st.enabled && !gate.allowed) { host.appendChild(HS.note('warn', 'آمادهٔ اجرا نیست', gate.why || '')); }
				else if (st.enabled && !st.ai_ready) { host.appendChild(HS.note('bad', 'هوش مصنوعی تنظیم نشده', 'در بخش «تنظیمات هوش مصنوعی» یک سرویس و کلید وارد کنید.')); }

				/* ---- KPIs ---- */
				host.appendChild(HS.card({ title: 'کارنامه', icon: 'gauge' }, [
					HS.kpis([
						{ label: 'اجراها', value: num(totals.runs || 0) },
						{ label: 'کارهای انجام‌شده', value: num(totals.wins || 0), hint: 'از آغاز نصب' },
						{ label: 'گام‌های ثبت‌شده', value: num(totals.steps || 0) },
						{ label: 'در انتظار تأیید', value: num(totals.review || 0) },
						{ label: 'هزینهٔ هوش مصنوعی', value: '$' + num(parseFloat(totals.cost_usd || 0).toFixed(3)) },
						{ label: 'اجرای بعدی', value: st.next_run ? ago(st.next_run).replace('پیش', '') : '—', hint: 'برنامه: ' + (st.schedule || '—') }
					])
				]));

				/* ---- settings ---- */
				var auto = h('div', { class: 'hs-agentauto' });
				(st.autonomy_levels || []).forEach(function (a) {
					var on = a.id === st.autonomy;
					var card = h('button', { type: 'button', class: 'hs-autocard' + (on ? ' is-on' : '') }, [
						h('strong', { text: a.label }),
						h('span', { text: a.about })
					]);
					card.addEventListener('click', function () {
						HS.markDirty('agent.autonomy', a.id);
						HS.flushDirty().then(function () { HS.toast('سطح خودمختاری: ' + a.label, 'ok'); HS.cacheClear('agent'); HS.renderRoute(); }, HS.fail);
					});
					auto.appendChild(card);
				});

				host.appendChild(HS.card({
					title: 'کنترل', icon: 'robot',
					actions: [
						HS.switchEl(!!st.enabled, function (v) {
							HS.markDirty('agent.enabled', v);
							return HS.flushDirty().then(function () { HS.toast(v ? 'ایجنت روشن شد' : 'ایجنت خاموش شد', 'ok'); HS.cacheClear('agent'); HS.renderRoute(); }, HS.fail);
						}, { title: 'روشن/خاموش' })
					]
				}, [
					auto,
					h('div', { class: 'hs-row hs-row--wrap' }, [
						h('label', { class: 'hs-field' }, [
							h('span', { text: 'برنامهٔ اجرا' }),
							HS.segmented([{ value: 'hourly', label: 'هر ساعت' }, { value: 'daily', label: 'هر روز' }, { value: 'weekly', label: 'هر هفته' }, { value: 'off', label: 'خاموش' }], st.schedule, function (v) { HS.markDirty('agent.schedule', v); HS.flushDirty().then(function () { HS.cacheClear('agent'); HS.renderRoute(); }); })
						]),
						h('label', { class: 'hs-field' }, [
							h('span', { text: 'نتیجهٔ مقاله‌ها' }),
							HS.segmented([{ value: 'draft', label: 'پیش‌نویس' }, { value: 'review', label: 'با تأیید من' }, { value: 'publish', label: 'انتشار مستقیم' }], limits.publish, function (v) { HS.markDirty('agent.publish', v); return HS.flushDirty(); })
						]),
						h('label', { class: 'hs-field' }, [
							h('span', { text: 'سقف هزینه در هر اجرا (دلار)' }),
							HS.input({ key: 'budget', type: 'number', step: '0.1', min: '0' }, limits.budget_usd || 0, function (v) { HS.markDirty('agent.budget_usd', parseFloat(v) || 0); })
						]),
						h('label', { class: 'hs-field' }, [
							h('span', { text: 'حداکثر گام در هر اجرا' }),
							HS.input({ key: 'max_steps', type: 'number', step: '1', min: '1' }, limits.max_steps || 25, function (v) { HS.markDirty('agent.max_steps', parseInt(v, 10) || 1); })
						]),
						h('label', { class: 'hs-field' }, [
							h('span', { text: 'درخواست‌های موازی' }),
							HS.input({ key: 'parallel', type: 'number', step: '1', min: '1', max: '12' }, limits.parallel || 4, function (v) { HS.markDirty('agent.parallel', parseInt(v, 10) || 1); })
						]),
						HS.btn('ذخیره', { sm: true, icon: 'check', run: function () { return HS.flushDirty(); } })
					]),
					HS.note(null, 'درخواست‌های موازی فقط برای بخش‌های مستقل یک مقاله به کار می‌رود؛ هرچه بیشتر، سریع‌تر — ولی ممکن است سرویس هوش مصنوعی شما را محدود کند.', '')
				]));

				/* ---- skills ---- */
				var grid = h('div', { class: 'hs-cols hs-cols--3' });
				(st.skills || []).forEach(function (sk) {
					var c = h('article', { class: 'hs-modcard' + (sk.enabled ? '' : ' is-off') });
					c.appendChild(h('h3', { class: 'hs-modcard__title', text: sk.label }));
					c.appendChild(h('p', { class: 'hs-modcard__desc', text: sk.about || '' }));
					var foot = h('div', { class: 'hs-modcard__foot' }, [
						chip('خطر: ' + (RISK[sk.risk] || sk.risk), RISK_KIND[sk.risk]),
						sk.cost ? chip('هزینهٔ AI', 'warn') : null,
						h('div', { class: 'hs-right' }, [HS.switchEl(!!sk.enabled, function (v) {
							var cur = HS.sget('agent.skills', {}) || {};
							var next = Object.assign({}, cur);
							next[sk.id] = !!v;
							HS.markDirty('agent.skills', next);
							return HS.flushDirty();
						})])
					]);
					c.appendChild(foot);
					grid.appendChild(c);
				});
				host.appendChild(HS.card({ title: 'مهارت‌ها', icon: 'chip', flush: true }, [grid]));

				/* ---- diagnosis ---- */
				var diagHost = h('div', {});
				host.appendChild(HS.card({
					title: 'تحلیل زندهٔ سایت', icon: 'stethoscope',
					actions: [HS.btn('سنجش دوباره', { sm: true, icon: 'refresh', run: function () {
						diagHost.innerHTML = ''; diagHost.appendChild(HS.skeleton(3));
						HS.get('agent/diagnose', {}, { force: true }).then(function (d) { renderDiag(diagHost, d); }, HS.fail);
					} })]
				}, [diagHost]));
				diagHost.appendChild(HS.skeleton(3));
				HS.get('agent/diagnose', {}, { force: true }).then(function (d) { renderDiag(diagHost, d); }, function () { diagHost.innerHTML = ''; });

				/* ---- opportunities + writer ---- */
				var oppHost = h('div', {});
				host.appendChild(HS.card({
					title: 'فرصت‌های محتوا', icon: 'sparkle',
					actions: [HS.btn('نوشتن مقالهٔ دلخواه', { sm: true, kind: 'primary', icon: 'edit', run: function () { writeArticle(); } })]
				}, [oppHost]));
				oppHost.appendChild(HS.skeleton(2));
				HS.get('agent/opportunities', { limit: 12 }, { force: true }).then(function (o) {
					oppHost.innerHTML = '';
					var rows = (o && o.rows) || [];
					if (!rows.length) {
						oppHost.appendChild(HS.note(null, 'فرصت بی‌پوششی پیدا نشد', 'برای نتیجهٔ بهتر سرچ کنسول را وصل کنید یا در «تحقیق کلمات کلیدی» کلمه اضافه کنید.'));
						return;
					}
					oppHost.appendChild(HS.table({
						columns: [
							{ key: 'keyword', label: 'کلیدواژه', render: function (r) { return h('strong', { text: r.keyword }); } },
							{ key: 'intent', label: 'نیت', width: '110px', render: function (r) { return chip(intentLabel(r.intent)); } },
							{ key: 'volume', label: 'تقاضا', width: '90px', render: function (r) { return num(r.volume || 0); } },
							{ key: 'difficulty', label: 'سختی', width: '80px', render: function (r) { return num(Math.round(r.difficulty || 0)); } },
							{ key: 'words', label: 'طول پیشنهادی', width: '110px', render: function (r) { return num(r.words || 0) + ' کلمه'; } },
							{ key: 'score', label: 'امتیاز', width: '80px', render: function (r) { return chip(num(r.score || 0), (r.score || 0) > 80 ? 'ok' : null); } },
							{ key: 'src', label: 'منبع', width: '120px', render: function (r) { return h('span', { class: 'hs-muted hs-small', text: sourceLabel(r.source) }); } },
							{ key: 'act', label: '', width: '90px', render: function (r) {
								return HS.btn('بنویس', { sm: true, kind: 'ghost', run: function () { return writeArticle(r.keyword); } });
							} }
						],
						rows: rows,
						empty: { title: 'چیزی نیست' }
					}));
				}, function () { oppHost.innerHTML = ''; });

				/* ---- articles ---- */
				var artHost = h('div', {});
				host.appendChild(HS.card({ title: 'مقاله‌های تولیدشده', icon: 'document' }, [artHost]));
				artHost.appendChild(HS.skeleton(2));
				HS.get('agent/articles', {}, { force: true }).then(function (a) {
					artHost.innerHTML = '';
					var rows = (a && a.rows) || [];
					if (!rows.length) {
						artHost.appendChild(HS.note(null, 'هنوز مقاله‌ای نوشته نشده', 'مهارت «نگارش مقالهٔ تازه» را روشن کنید یا دستی یک مقاله بسازید.'));
						return;
					}
					artHost.appendChild(HS.table({
						columns: [
							{ key: 'keyword', label: 'کلیدواژه', render: function (r) { return h('strong', { text: r.keyword }); } },
							{ key: 'title', label: 'عنوان', render: function (r) { return h('span', { text: r.title || '—' }); } },
							{ key: 'words', label: 'کلمه', width: '80px', render: function (r) { return num(r.words || 0); } },
							{ key: 'quality', label: 'کیفیت', width: '80px', render: function (r) { return chip(num(Math.round(r.quality || 0)), (r.quality || 0) >= 70 ? 'ok' : 'warn'); } },
							{ key: 'status', label: 'وضعیت', width: '110px', render: function (r) { return chip(articleStatus(r.status), r.status === 'publish' ? 'ok' : (r.status === 'rejected' || r.status === 'failed' ? 'bad' : 'warn')); } },
							{ key: 'link', label: '', width: '80px', render: function (r) {
								if (!r.post_id || String(r.post_id) === '0') { return null; }
								var u = (HS.C && HS.C.site && HS.C.site.admin) ? (HS.C.site.admin + 'post.php?post=' + num(r.post_id) + '&action=edit') : '#';
								return h('a', { class: 'hs-btn hs-btn--sm hs-btn--ghost', href: u, text: 'ویرایش' });
							} }
						],
						rows: rows,
						rowKey: 'id',
						empty: { title: 'چیزی نیست' }
					}));
				}, function () { artHost.innerHTML = ''; });

				/* ---- history ---- */
				host.appendChild(HS.card({ title: 'دفتر کار', icon: 'report', flush: true }, [
					HS.table({
						columns: [
							{ key: 'id', label: '#', width: '56px', render: function (r) { return num(r.id); } },
							{ key: 'created_at', label: 'زمان', width: '150px', render: function (r) { return h('span', { text: (r.created_at || '').replace('T', ' ') }); } },
							{ key: 'goal', label: 'هدف', render: function (r) { return h('span', { text: r.goal || '' }); } },
							{ key: 'status', label: 'وضعیت', width: '100px', render: function (r) { return chip(runStatus(r.status), r.status === 'done' ? 'ok' : (r.status === 'failed' ? 'bad' : 'warn')); } },
							{ key: 'steps_done', label: 'گام', width: '70px', render: function (r) { return num(r.steps_done || 0) + '/' + num(r.steps_total || 0); } },
							{ key: 'score', label: 'امتیاز', width: '100px', render: function (r) {
								var b = parseFloat(r.score_before || 0), a = parseFloat(r.score_after || 0);
								if (!b && !a) { return '—'; }
								var d = a - b;
								return h('span', { text: num(Math.round(b)) + ' → ' + num(Math.round(a)) + (d ? ' (' + (d > 0 ? '+' : '') + num(Math.round(d * 10) / 10) + ')' : '') });
							} },
							{ key: 'summary', label: 'خلاصه', render: function (r) { return h('span', { class: 'hs-muted', text: trunc(r.summary || '', 60) }); } },
							{ key: 'act', label: '', width: '80px', render: function (r) { return HS.btn('جزئیات', { sm: true, kind: 'ghost', run: function () { openRun(r.id); } }); } }
						],
						rows: st.runs || [],
						rowKey: 'id',
						empty: { title: 'هنوز اجرایی ثبت نشده', body: 'با «اجرای آزمایشی» شروع کنید تا ببینید ایجنت چه می‌کرد.' }
					})
				]));

				function renderDiag(el, d) {
					el.innerHTML = '';
					if (!d) { return; }
					var findings = d.findings || [];
					el.appendChild(h('div', { class: 'hs-row' }, [
						scoreRing(d.score || 0),
						h('div', { class: 'hs-muted' }, [
							h('div', { text: 'منابع داده: ' + ((d.sources || []).map(sourceLabel).join('، ') || 'فقط دادهٔ داخلی سایت') }),
							h('div', { text: num(findings.length) + ' مورد پیدا شد' })
						])
					]));
					if (!findings.length) {
						el.appendChild(HS.note('ok', 'سایت سالم است', 'ایراد فنی مهمی پیدا نشد. ایجنت در این حالت بودجهٔ خود را صرف تولید محتوا می‌کند.'));
						return;
					}
					var list = h('div', { class: 'hs-findings' });
					findings.forEach(function (f) {
						list.appendChild(h('div', { class: 'hs-finding hs-finding--' + (f.severity || 'low') }, [
							h('span', { class: 'hs-finding__sev', text: sevLabel(f.severity) }),
							h('div', { class: 'hs-finding__body' }, [
								h('p', { text: f.message }),
								h('span', { class: 'hs-muted hs-small', text: 'مهارت پیشنهادی: ' + skillLabel(st, f.skill) })
							]),
							h('div', { class: 'hs-finding__impact' }, [
								h('span', { text: num(Math.round(f.impact || 0)) }),
								h('small', { text: 'اثر' })
							])
						]));
					});
					el.appendChild(list);
				}
			}, function (e) {
				host.innerHTML = '';
				host.appendChild(HS.note('bad', 'ایجنت بالا نیامد', e.message));
			});
		}
	});

	function showPlan(res) {
		var plan = (res && res.plan) || {};
		var steps = (plan.steps) || [];
		var body = h('div', {});
		if (plan.rationale && plan.rationale.length) {
			body.appendChild(HS.note(null, 'استدلال', plan.rationale.join(' — ')));
		}
		if (!steps.length) {
			body.appendChild(HS.note('ok', 'کاری نبود', 'ایراد فنی و فرصت محتوایی تازه‌ای پیدا نشد.'));
		} else {
			body.appendChild(HS.table({
				columns: [
					{ key: 'seq', label: '#', width: '48px', render: function (r) { return num(r.seq); } },
					{ key: 'skill', label: 'مهارت', width: '140px', render: function (r) { return h('strong', { text: r.skill }); } },
					{ key: 'why', label: 'چرا', render: function (r) { return h('span', { text: r.why || '' }); } },
					{ key: 'severity', label: 'اهمیت', width: '90px', render: function (r) { return chip(sevLabel(r.severity), r.severity === 'critical' ? 'bad' : (r.severity === 'high' ? 'warn' : null)); } }
				],
				rows: steps,
				empty: { title: 'خالی' }
			}));
		}
		HS.modal({ title: 'برنامهٔ اجرا (بدون تغییر)', body: body });
	}

	function writeArticle(keyword) {
		var kw = { value: keyword || '' };
		var input = HS.input({ key: 'agent_kw', placeholder: 'مثلاً: خرید لپ تاپ دانشجویی' }, keyword || '', function (v) { kw.value = v; });
		var body = h('div', {}, [
			HS.note(null, 'مقالهٔ کامل با ساختار، لینک داخلی، اسکیمای پرسش‌های متداول و متای بهینه ساخته می‌شود.', 'چند دقیقه طول می‌کشد چون بخش‌ها جداگانه نوشته می‌شوند.'),
			h('label', { class: 'hs-field' }, [h('span', { text: 'کلیدواژهٔ هدف' }), input])
		]);
		HS.modal({
			title: 'نگارش مقاله',
			body: body,
			actions: [{ label: 'شروع نگارش', kind: 'primary', keepOpen: true, run: function (el, o) {
				var v = String(kw.value || '').trim();
				if (v.length < 3) { HS.toast('کلیدواژه را وارد کنید', 'warn'); return; }
				HS.toast('نگارش شروع شد…', 'ok');
				o.close();
				HS.post('agent/article', { keyword: v }).then(function (res) {
					if (res && res.ok) {
						HS.toast('مقاله ساخته شد: ' + (res.title || ''), 'ok');
						HS.cacheClear('agent'); HS.renderRoute();
					} else {
						HS.toast((res && res.message) || 'ناموفق بود', 'bad');
					}
				}, function (e) { HS.toast(e.message || 'خطا', 'bad'); });
			} }]
		});
	}

	function skillLabel(st, id) {
		var found = (st.skills || []).filter(function (s) { return s.id === id; })[0];
		return found ? found.label : (id || '—');
	}
	function sevLabel(s) {
		return { critical: 'بحرانی', high: 'مهم', medium: 'متوسط', low: 'کم' }[s] || (s || '—');
	}
	function intentLabel(i) {
		return { transactional: 'خرید', commercial: 'مقایسه', informational: 'آموزشی', navigational: 'یافتن سایت' }[i] || (i || '—');
	}
	function sourceLabel(s) {
		return { research: 'تحقیق کلمات', 'search-console': 'سرچ کنسول', 'rank-tracker': 'ردیاب رتبه', keywords: 'کلمات کلیدی', pagespeed: 'سرعت' }[s] || (s || '—');
	}
	function articleStatus(s) {
		return { brief: 'بریف', drafting: 'در حال نگارش', draft: 'پیش‌نویس', publish: 'منتشرشده', review: 'در انتظار تأیید', rejected: 'رد شده', failed: 'ناموفق' }[s] || (s || '—');
	}
	function runStatus(s) {
		return { running: 'در جریان', done: 'انجام شد', failed: 'ناموفق', halted: 'متوقف', queued: 'در صف' }[s] || (s || '—');
	}
})(HS);

	/* ---------- start ---------- */
	HS.boot();
})(HS);
