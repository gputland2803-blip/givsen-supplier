/* Givsen Supplier admin: copy buttons and confirm prompts. Delegated on document so re-rendered markup keeps working. */
(function () {
	'use strict';

	function fallbackCopy(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.select();
		try { document.execCommand('copy'); } catch (e) { /* ignore */ }
		document.body.removeChild(ta);
	}

	function flash(btn) {
		if (!btn.dataset.gsupLabel) { btn.dataset.gsupLabel = btn.textContent; }
		btn.textContent = 'Copied';
		btn.classList.add('gsup-copied');
		clearTimeout(btn._gsupTimer);
		btn._gsupTimer = setTimeout(function () {
			btn.textContent = btn.dataset.gsupLabel;
			btn.classList.remove('gsup-copied');
		}, 1200);
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.gsup-copy') : null;
		if (!btn) { return; }
		e.preventDefault();
		var text = btn.getAttribute('data-copy') || '';
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(function () { flash(btn); }, function () { fallbackCopy(text); flash(btn); });
		} else {
			fallbackCopy(text);
			flash(btn);
		}
	});

	document.addEventListener('click', function (e) {
		var el = e.target.closest ? e.target.closest('[data-gsup-confirm]') : null;
		if (el && !window.confirm(el.getAttribute('data-gsup-confirm'))) {
			e.preventDefault();
		}
	});

	// Add to store: select-all box, and a busy state while photos download.
	document.addEventListener('change', function (e) {
		if (!e.target.classList || !e.target.classList.contains('gsup-check-all')) { return; }
		var boxes = document.querySelectorAll('.gsup-create-form input[name="sku_ids[]"]');
		Array.prototype.forEach.call(boxes, function (b) { b.checked = e.target.checked; });
	});
	document.addEventListener('submit', function (e) {
		var form = e.target;
		if (!form.classList || !form.classList.contains('gsup-create-form')) { return; }
		if (!form.querySelector('input[name="sku_ids[]"]:checked')) {
			e.preventDefault();
			window.alert('Tick at least one option to add.');
			return;
		}
		var btn = form.querySelector('.gsup-create-btn');
		if (btn) {
			setTimeout(function () { btn.disabled = true; btn.textContent = 'Creating product and copying photos…'; }, 0);
		}
	});
})();
