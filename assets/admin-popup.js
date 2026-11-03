/**
 * meil.ro > Settings > Popup: the preview. A frame of its own, so the admin's
 * styles stay out, with the plugin's form styles (when on) and popup styles
 * over a stand-in page, open the way a visitor sees it. It follows the form,
 * the kind, the title and the text as they change; Computer and Phone show it
 * at both widths (on phones a popup shows at the bottom). The forms come
 * ready drawn from PHP, in window.meilPopupPreview.
 */
(function () {
	'use strict';

	var data = window.meilPopupPreview;
	var frame = document.querySelector('[data-meil-popup-preview]');
	if (!data || !frame) {
		return;
	}

	function field(name) {
		return document.getElementById('meil-mailer-' + name);
	}

	function escapeAttr(text) {
		return String(text).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
	}

	var page = '' +
		'html,body{margin:0;min-height:100%;background:#fff;color:#1e1e1e;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.5}' +
		'.pp-page{padding:28px 32px}.pp-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:36px}' +
		'.pp-logo{width:110px;height:14px;border-radius:7px;background:#d6d4cc}.pp-nav{display:flex;gap:14px}.pp-nav i{display:block;width:44px;height:9px;border-radius:5px;background:#e6e4dd}' +
		'.pp-h{width:55%;height:22px;border-radius:8px;background:#dedcd4;margin-bottom:22px}.pp-l{height:10px;border-radius:5px;background:#eceae4;margin-bottom:12px}' +
		'.pp-l:nth-child(3n){width:82%}.pp-l:nth-child(4n){width:64%}' +
		'.meil-popup__box{pointer-events:none}.meil-popup__close{pointer-events:none}' +
		'.pp-empty{position:fixed;inset:auto 0 40% 0;text-align:center;color:#6b6b6b;font-size:15px}' +
		'@media (max-width:480px){.pp-page{padding:24px 20px}.pp-nav{display:none}}';
	var lines = '';
	for (var i = 0; i < 14; i++) {
		lines += '<div class="pp-l"></div>';
	}

	frame.srcdoc = '<!doctype html><html><head><meta charset="utf-8">' +
		(data.formsCss ? '<link rel="stylesheet" href="' + escapeAttr(data.formsCss) + '">' : '') +
		'<link rel="stylesheet" href="' + escapeAttr(data.popupCss) + '">' +
		'<style>' + page + '</style></head><body>' +
		'<div class="pp-page"><div class="pp-bar"><div class="pp-logo"></div><div class="pp-nav"><i></i><i></i><i></i><i></i></div></div><div class="pp-h"></div>' + lines + '</div>' +
		'<p class="pp-empty" hidden></p>' +
		'<div class="meil-popup meil-popup--modal meil-popup--open">' +
		'<div class="meil-popup__backdrop"></div>' +
		'<div class="meil-popup__box" role="dialog">' +
		'<button type="button" class="meil-popup__close" tabindex="-1" aria-hidden="true"><span>&times;</span></button>' +
		'<p class="meil-popup__title"></p><p class="meil-popup__text"></p><div class="pp-form"></div>' +
		'</div></div></body></html>';

	function update() {
		var doc = frame.contentDocument;
		if (!doc || !doc.querySelector('.meil-popup')) {
			return;
		}
		var popup = doc.querySelector('.meil-popup');
		var kind = field('popup_kind') ? field('popup_kind').value : 'modal';
		var formId = field('popup_form') ? field('popup_form').value : '0';
		var title = field('popup_title') ? field('popup_title').value.trim() : '';
		var text = field('popup_text') ? field('popup_text').value.trim() : '';
		var html = data.forms[formId];
		var empty = doc.querySelector('.pp-empty');

		popup.hidden = !html;
		empty.hidden = !!html;
		empty.textContent = data.noForm;
		if (!html) {
			return;
		}
		popup.className = 'meil-popup meil-popup--' + kind + ' meil-popup--open';
		doc.querySelector('.meil-popup__backdrop').hidden = kind !== 'modal';
		doc.querySelector('.meil-popup__title').textContent = title || data.defaultTitle;
		var line = doc.querySelector('.meil-popup__text');
		line.textContent = text;
		line.hidden = text === '';
		var holder = doc.querySelector('.pp-form');
		if (holder.getAttribute('data-form') !== formId) {
			holder.innerHTML = html;
			holder.setAttribute('data-form', formId);
		}
	}

	frame.addEventListener('load', update);
	['popup_form', 'popup_kind', 'popup_title', 'popup_text'].forEach(function (name) {
		var input = field(name);
		if (input) {
			input.addEventListener('input', update);
			input.addEventListener('change', update);
		}
	});

	// Computer or phone width: on phones a popup in the middle shows at the bottom.
	var stage = frame.parentNode;
	document.querySelectorAll('[data-meil-preview-width]').forEach(function (button) {
		button.addEventListener('click', function () {
			var phone = button.getAttribute('data-meil-preview-width') === 'phone';
			stage.classList.toggle('meil-popup-preview__stage--phone', phone);
			document.querySelectorAll('[data-meil-preview-width]').forEach(function (other) {
				var on = other === button;
				other.classList.toggle('meil-tab--active', on);
				other.setAttribute('aria-pressed', on ? 'true' : 'false');
			});
		});
	});
}());
