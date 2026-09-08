/**
 * HooshSEO — block editor integration.
 *
 * Adds a sidebar panel (and a compact score row in the document settings) that
 * reuses the exact metabox component from assets/editor.js, so the classic and
 * block editors cannot drift apart.
 */
(function (window, document) {
	'use strict';

	var wp = window.wp;
	if (!wp || !wp.element || !wp.plugins || !wp.data || !wp.components) {
		return;
	}
	if (!window.HooshEditor || !window.HooshEditor.create) {
		return;
	}

	var h = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var PluginSidebar = wp.plugins.PluginSidebar;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var PanelRow = wp.components.PanelRow;
	var Spinner = wp.components.Spinner;
	var Disabled = wp.components.Disabled;

	var E = window.HOOSH_EDITOR || {};
	var T = E.i18n || {};
	var G = window.HOOSH_GUTENBERG || {};

	function useHoosh() {
		var postId = useSelect(function (sel) { return sel.select('core/editor').getCurrentPostId(); }, []);
		var postType = useSelect(function (sel) { return sel.select('core/editor').getCurrentPostType(); }, []);
		var meta = useSelect(function (sel) { return sel.select('core/editor').getEditedPostAttribute('hoosh') || {}; }, []);
		var title = useSelect(function (sel) { return sel.select('core/editor').getEditedPostAttribute('title') || ''; }, []);
		var content = useSelect(function (sel) { return sel.select('core/editor').getEditedPostContent ? sel.select('core/editor').getEditedPostContent() : ''; }, []);
		var excerpt = useSelect(function (sel) { return sel.select('core/editor').getEditedPostAttribute('excerpt') || ''; }, []);
		var status = useSelect(function (sel) { return sel.select('core/editor').getEditedPostAttribute('status') || 'draft'; }, []);
		var isSaving = useSelect(function (sel) { return sel.select('core/editor').isSavingPost(); }, []);
		var setMeta = useDispatch('core/editor').editPost;
		return { postId: postId, postType: postType, meta: meta, title: title, content: content, excerpt: excerpt, status: status, isSaving: isSaving, set: setMeta };
	}

	function Studio() {
		var st = useHoosh();
		var hostRef = useRef(null);
		var instRef = useRef(null);
		var _s = useState(false), mounted = _s[0], setMounted = _s[1];
		var _a = useState(0), attempts = _a[0], setAttempts = _a[1];

		useEffect(function () {
			if (!hostRef.current || instRef.current) {
				return;
			}
			var value = Object.assign({}, st.meta || {});
			try {
				instRef.current = window.HooshEditor.create({
					root: hostRef.current,
					postId: st.postId || E.postId || 0,
					value: value,
					compact: true,
					live: false,
					source: function () { return { title: st.title, content: st.content, excerpt: st.excerpt }; },
					onChange: function (patch) {
						var next = Object.assign({}, st.meta || {}, patch);
						st.set({ hoosh: next });
					}
				});
				setMounted(true);
			} catch (e) {
				/* Retry on the next render: the editor store may still be booting. */
				if (attempts < 3) { setTimeout(function () { setAttempts(attempts + 1); }, 400); }
			}
			return function () {
				if (instRef.current && instRef.current.destroy) {
					instRef.current.destroy();
				}
				instRef.current = null;
			};
		}, [hostRef.current]);

		useEffect(function () {
			if (instRef.current && instRef.current.refresh) {
				instRef.current.refresh(st.meta || {});
			}
		}, [st.meta && st.meta.title, st.meta && st.meta.description, st.meta && st.meta.score]);

		var body = h('div', { className: 'hoosh-mb', style: { padding: '12px 16px 22px' } }, [
			G.hasYoast ? h('div', { className: 'hs-mb-note hs-mb-note--warn', style: { marginBottom: '10px' } }, 'Yoast SEO فعال است؛ برای جلوگیری از دوباره‌کاری، توضیحات متا را فقط در یکی از دو افزونه بنویسید.') : null,
			h('div', { ref: hostRef }),
			!mounted ? h(PanelRow, null, h(Spinner)) : null,
			st.isSaving ? h('div', { className: 'hs-mb-f-h', style: { padding: '6px 0' } }, T.saving || 'در حال ذخیره…') : null
		]);

		return h(PluginSidebar, {
			name: 'hoosh-seo-studio',
			title: 'هوش‌سئو',
			icon: 'chart-line'
		}, h(Disabled, { isDisabled: false }, body));
	}

	function ScorePanel() {
		var st = useHoosh();
		var score = parseInt((st.meta && st.meta.score) || 0, 10);
		var grade = score >= 90 ? 'A' : score >= 75 ? 'B' : score >= 60 ? 'C' : score >= 40 ? 'D' : 'E';
		var tone = score >= 80 ? 'var(--hs-ok, #1a7f4b)' : score >= 55 ? 'var(--hs-warn, #946600)' : 'var(--hs-bad, #b32d2e)';
		return h(PluginDocumentSettingPanel, {
			name: 'hoosh-seo-score',
			title: 'امتیاز سئو',
			className: 'hoosh-score-panel',
			icon: 'chart-line'
		}, h(PanelRow, null, h('div', { style: { display: 'flex', alignItems: 'center', gap: '10px', width: '100%' } }, [
			h('span', { style: { fontSize: '21px', fontWeight: 700, color: tone, lineHeight: 1 } }, score ? String(score) : '—'),
			h('span', { style: { fontSize: '12px', opacity: .7 } }, score ? ('رتبه ' + grade + ' · ' + (st.meta && st.meta.analysis && st.meta.analysis.issues ? (st.meta.analysis.issues.length + ' بررسی') : '')) : 'بررسی نشده'),
			h('span', { style: { marginInlineStart: 'auto' } }, h('a', {
				href: (E.studio || '#').replace(/[\?&]post=\d+/, '?post=' + (st.postId || 0)),
				target: '_blank',
				rel: 'noopener',
				style: { fontSize: '11.5px' },
				onClick: function (ev) {
					if (!E.studio) { ev.preventDefault(); return; }
					ev.preventDefault();
					window.open(E.studio + '&post=' + (st.postId || 0), '_blank', 'noopener');
				}
			}, T.studio || 'استودیو'))
		])));
	}

	function AnswerPanel() {
		var st = useHoosh();
		var geo = (st.meta && st.meta.geo) || {};
		var _q = useState(geo.question || ''), question = _q[0], setQuestion = _q[1];
		var _a = useState(geo.answer || ''), answer = _a[0], setAnswer = _a[1];
		useEffect(function () {
			setQuestion((geo.question) || '');
			setAnswer((geo.answer) || '');
		}, [st.postId]);
		function save(patch) {
			st.set({ hoosh: Object.assign({}, st.meta || {}, { geo: Object.assign({}, geo, patch) }) });
		}
		return h(PluginDocumentSettingPanel, {
			name: 'hoosh-seo-answer',
			title: 'جعبه پاسخ (برای موتورهای AI)',
			className: 'hoosh-answer-panel'
		}, h(PanelRow, null, h('div', { style: { width: '100%', display: 'grid', gap: '8px' } }, [
			h('input', {
				className: 'components-text-control__input',
				type: 'text',
				value: question,
				placeholder: 'پرسشی که این صفحه پاسخ می‌دهد',
				onChange: function (ev) { setQuestion(ev.target.value); save({ question: ev.target.value }); }
			}),
			h('textarea', {
				className: 'components-textarea-control__input',
				rows: 4,
				value: answer,
				placeholder: 'پاسخ ۴۰ تا ۶۰ کلمه‌ای و مستقل از متن صفحه',
				onChange: function (ev) { setAnswer(ev.target.value); save({ answer: ev.target.value }); }
			}),
			h('label', { style: { display: 'flex', gap: '6px', alignItems: 'center', fontSize: '12px' } }, [
				h('input', {
					type: 'checkbox',
					checked: geo.ai_visible !== false,
					onChange: function (ev) { save({ ai_visible: !!ev.target.checked }); }
				}),
				'نمایش این پاسخ در بالای محتوا و در دسترس مدل‌های پاسخ‌دهنده'
			])
		])));
	}

	function mount() {
		wp.plugins.registerPlugin('hoosh-seo-sidebar', Studio, { icon: 'chart-line', isPinnable: true });
		wp.plugins.registerPlugin('hoosh-seo-score', ScorePanel);
		wp.plugins.registerPlugin('hoosh-seo-answer', AnswerPanel);
		/* Also expose the fields in the classic meta box when both editors are used. */
		if (document.getElementById('hoosh-seo-classic')) {
			var box = document.getElementById('hoosh-seo-classic');
			box.classList.add('hs-mb');
		}
	}
	mount();
})(window, document);
