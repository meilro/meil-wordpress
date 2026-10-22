/**
 * meil.ro forms: sends [meil_form] and [meil_preferences] in the background
 * (admin-ajax.php), so the page does not reload.
 *
 * While a form is being sent its button is disabled and both get a --loading
 * class (.meil-form__button--loading, .meil-prefs__form--loading, ...). The
 * answer is shown in the block's message line; a subscription form that
 * succeeded is hidden, the preferences tick boxes are set from what meil.ro
 * saved. Without fetch (very old browsers) the form posts to the page as usual.
 */
(function () {
	'use strict';

	if (!window.fetch || !window.FormData || !document.querySelectorAll) {
		return;
	}

	function showMessage(block, prefix, type, text) {
		var line = block.querySelector('[data-meil-message]');
		if (!line) {
			return;
		}
		line.className = prefix + '__message ' + prefix + '__message--' + type;
		line.textContent = text;
		line.hidden = false;
	}

	// The preferences as meil.ro saved them: ticks, state classes, and the
	// "waiting for confirmation" note only where it still applies.
	function updateChoices(block, choices) {
		(choices || []).forEach(function (choice) {
			var label = block.querySelector('[data-meil-choice="' + String(choice.id).replace(/"/g, '\\"') + '"]');
			if (!label) {
				return;
			}
			var input = label.querySelector('input[type="checkbox"]');
			if (input) {
				input.checked = !!choice.checked;
			}
			['subscribed', 'pending', 'unsubscribed', 'checked'].forEach(function (state) {
				label.classList.remove('meil-prefs__choice--' + state);
			});
			label.classList.add('meil-prefs__choice--' + choice.state);
			if (choice.checked) {
				label.classList.add('meil-prefs__choice--checked');
			}
			var note = label.querySelector('.meil-prefs__choice-status');
			if (note && choice.state !== 'pending') {
				note.parentNode.removeChild(note);
			}
		});
	}

	function setBusy(form, prefix, busy) {
		var button = form.querySelector('[type="submit"]');
		form.classList.toggle(prefix + '__form--loading', busy);
		form.setAttribute('aria-busy', busy ? 'true' : 'false');
		if (button) {
			button.disabled = busy;
			button.classList.toggle(prefix + '__button--loading', busy);
		}
	}

	function send(form, event) {
		var block = form.closest('[data-meil-block]');
		if (!block) {
			return;
		}
		event.preventDefault();
		var prefix = block.getAttribute('data-meil-block');
		if (form.getAttribute('aria-busy') === 'true') {
			return; // already on its way
		}

		var data = new FormData(form);
		data.append('action', form.getAttribute('data-meil-action'));
		setBusy(form, prefix, true);
		var line = block.querySelector('[data-meil-message]');
		if (line) {
			line.hidden = true; // the last answer no longer applies
		}

		var done = false;
		fetch(form.getAttribute('data-meil-ajax'), { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (response) {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}
				return response.json();
			})
			.then(function (answer) {
				showMessage(block, prefix, answer.type || 'error', answer.message || form.getAttribute('data-meil-error'));
				if (answer.choices) {
					updateChoices(block, answer.choices);
				}
				if (answer.done) {
					done = true;
					block.classList.add(prefix + '--done');
					form.hidden = true;
				}
			})
			.catch(function () {
				showMessage(block, prefix, 'error', form.getAttribute('data-meil-error'));
			})
			.then(function () {
				if (!done) {
					setBusy(form, prefix, false);
				}
			});
	}

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (form && form.matches && form.matches('form[data-meil-ajax]')) {
			send(form, event);
		}
	});
}());
