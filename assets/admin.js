/**
 * The plugin's admin screens: a button that sends a form shows a spinner and
 * is then disabled until the next page opens, so the form is not sent twice.
 * Saving the settings asks meil.ro about the key, and a test email or "Send
 * now" waits for meil.ro too, so this can take a few seconds.
 *
 * Only forms inside .meil-app (Settings, Form plugins, Unsent). The form
 * editor keeps WordPress's own spinner beside its Update button.
 */
(function () {
	'use strict';

	function reset(form) {
		form.removeAttribute('aria-busy');
		Array.prototype.forEach.call(form.querySelectorAll('.meil-is-loading'), function (button) {
			button.classList.remove('meil-is-loading');
			button.disabled = false;
		});
	}

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (event.defaultPrevented || !form || !form.closest || !form.closest('.meil-app')) {
			return;
		}
		if (form.getAttribute('aria-busy') === 'true') {
			event.preventDefault(); // already on its way
			return;
		}
		form.setAttribute('aria-busy', 'true');
		var button = event.submitter || form.querySelector('[type="submit"]');
		if (!button) {
			return;
		}
		button.classList.add('meil-is-loading');
		// Disabled only once the browser has read the form: a disabled button
		// would leave its own name and value ("save" or "disconnect") out.
		window.setTimeout(function () {
			button.disabled = true;
		}, 0);
	});

	// Back on this page with the browser's Back button: it may be shown as it
	// was left, with the spinner still going.
	window.addEventListener('pageshow', function (event) {
		if (event.persisted) {
			Array.prototype.forEach.call(document.querySelectorAll('.meil-app form[aria-busy="true"]'), reset);
		}
	});
}());
