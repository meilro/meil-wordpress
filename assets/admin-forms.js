/**
 * meil.ro > Forms: the checkboxes section of the form editor.
 *
 * Each checkbox text gets WordPress's own editor with only bold, italic and
 * links (wp.editor, loaded by wp_enqueue_editor()). "Add checkbox" adds a row
 * from the <template> and gives it an editor too; "Remove" takes one away.
 */
(function () {
	'use strict';

	function makeEditor(textarea) {
		if (!textarea || !window.wp || !window.wp.editor || !window.wp.editor.initialize) {
			return; // the plain text box still works, with HTML links
		}
		window.wp.editor.initialize(textarea.id, {
			tinymce: {
				wpautop: true,
				toolbar1: 'bold,italic,link,unlink',
				toolbar2: '',
				height: 80
			},
			quicktags: { buttons: 'strong,em,link' },
			mediaButtons: false
		});
	}

	function removeEditor(textarea) {
		if (textarea && window.wp && window.wp.editor && window.wp.editor.remove) {
			window.wp.editor.remove(textarea.id);
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var box = document.querySelector('[data-meil-checkboxes]');
		var template = document.querySelector('[data-meil-checkbox-template]');
		var add = document.querySelector('[data-meil-add]');
		if (!box || !template || !add) {
			return;
		}
		var max = parseInt(box.getAttribute('data-max'), 10) || 5;

		function refreshAddButton() {
			add.disabled = box.querySelectorAll('[data-meil-checkbox]').length >= max;
		}

		Array.prototype.forEach.call(box.querySelectorAll('[data-meil-editor]'), makeEditor);
		refreshAddButton();

		add.addEventListener('click', function () {
			var count = box.querySelectorAll('[data-meil-checkbox]').length;
			if (count >= max) {
				return;
			}
			var index = parseInt(box.getAttribute('data-next'), 10) || 0;
			box.setAttribute('data-next', String(index + 1));
			box.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__i__/g, String(index)).replace(/__n__/g, String(count + 1)));
			var row = box.lastElementChild;
			makeEditor(row.querySelector('[data-meil-editor]'));
			refreshAddButton();
		});

		box.addEventListener('click', function (event) {
			var button = event.target.closest('[data-meil-remove]');
			if (!button) {
				return;
			}
			var row = button.closest('[data-meil-checkbox]');
			removeEditor(row.querySelector('[data-meil-editor]'));
			row.parentNode.removeChild(row);
			refreshAddButton();
		});

		// The editors write back into their text boxes before the form is saved.
		var post = document.getElementById('post');
		if (post) {
			post.addEventListener('submit', function () {
				if (window.tinymce) {
					window.tinymce.triggerSave();
				}
			});
		}
	});
}());
