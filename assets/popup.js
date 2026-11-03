/**
 * meil.ro popups: shows the form drawn by Meil_Popups when its moment comes,
 * after some seconds, after scrolling some of the page, or when the mouse
 * leaves the page towards the top (on phones, which have no mouse: after
 * scrolling half the page).
 *
 * The browser remembers, in localStorage, when it was closed (it stays away
 * for the form's days) and that the visitor signed up through any meil.ro
 * form (forms.js notes that; it stays away for good). Escape, the close
 * button and a click beside a popup close it.
 */
(function () {
	'use strict';

	var box = document.querySelector('[data-meil-popup]');
	if (!box) {
		return;
	}

	var store;
	try {
		store = window.localStorage;
		store.setItem('meil_test', '1');
		store.removeItem('meil_test');
	} catch (e) {
		store = null; // Private mode or storage turned off: show it, remember nothing.
	}

	var id = box.getAttribute('data-meil-popup');
	var kind = box.getAttribute('data-meil-kind');
	var trigger = box.getAttribute('data-meil-trigger');
	var value = parseInt(box.getAttribute('data-meil-value'), 10) || 0;
	var days = parseInt(box.getAttribute('data-meil-days'), 10) || 14;
	var closedKey = 'meil_popup_closed_' + id;
	var noMouse = window.matchMedia && window.matchMedia('(hover: none)').matches;

	if (store) {
		var closedAt = parseInt(store.getItem(closedKey), 10) || 0;
		if (store.getItem('meil_subscribed') || Date.now() - closedAt < days * 86400000) {
			return;
		}
	}

	var shown = false;
	var before = null;

	function onKey(event) {
		if (event.key === 'Escape') {
			close();
		}
	}

	function show() {
		if (shown) {
			return;
		}
		shown = true;
		before = document.activeElement;
		box.hidden = false;
		window.requestAnimationFrame(function () {
			box.classList.add('meil-popup--open');
		});
		document.addEventListener('keydown', onKey);
		// A popup takes the keyboard to its form; a slide-in or bar leaves it
		// where it was. Not on phones, where that would open the keyboard.
		if (kind === 'modal' && !noMouse) {
			var field = box.querySelector('input:not([type="hidden"]):not([tabindex="-1"])');
			if (field) {
				field.focus({ preventScroll: true });
			}
		}
	}

	function close() {
		box.classList.remove('meil-popup--open');
		box.hidden = true;
		document.removeEventListener('keydown', onKey);
		if (store && !store.getItem('meil_subscribed')) {
			store.setItem(closedKey, String(Date.now()));
		}
		if (before && before.focus && kind === 'modal') {
			before.focus({ preventScroll: true });
		}
	}

	box.addEventListener('click', function (event) {
		if (event.target.closest('[data-meil-popup-close]')) {
			close();
		}
	});

	function scrolled(percent) {
		var room = document.documentElement.scrollHeight - window.innerHeight;
		return room <= 0 || (window.scrollY / room) * 100 >= percent;
	}

	function onScroll(percent) {
		var check = function () {
			if (scrolled(percent)) {
				window.removeEventListener('scroll', check);
				show();
			}
		};
		window.addEventListener('scroll', check, { passive: true });
	}

	if (trigger === 'scroll') {
		onScroll(Math.min(100, value));
	} else if (trigger === 'exit' && !noMouse) {
		document.addEventListener('mouseout', function (event) {
			if (!event.relatedTarget && event.clientY <= 0) {
				show();
			}
		});
	} else if (trigger === 'exit') {
		onScroll(50);
	} else {
		window.setTimeout(show, value * 1000);
	}
}());
