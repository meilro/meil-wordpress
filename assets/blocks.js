/**
 * meil.ro blocks for the block editor: "meil.ro form" and "meil.ro email
 * preferences". Both are drawn by PHP (includes/class-meil-blocks.php), the
 * same as their shortcodes; this file only adds them to the editor. The forms
 * to pick from and every text come from PHP, in window.meilBlocks.
 */
(function (wp, data) {
	'use strict';

	if (!wp || !wp.blocks || !wp.element || !data) {
		return;
	}

	var el = wp.element.createElement;
	var editor = wp.blockEditor || wp.editor;
	var c = wp.components;
	var ServerSideRender = wp.serverSideRender;
	var s = data.strings;

	var options = [{ value: 0, label: s.choose }].concat(data.forms.map(function (form) {
		return { value: form.id, label: form.title };
	}));

	function picker(props) {
		return el(c.SelectControl, {
			label: s.form,
			value: props.attributes.formId,
			options: options,
			onChange: function (value) {
				props.setAttributes({ formId: parseInt(value, 10) || 0 });
			}
		});
	}

	// A plain link that looks like the editor's button, on every WordPress version.
	function linkButton(href, text, primary) {
		return el('a', { className: 'components-button ' + (primary ? 'is-primary' : 'is-secondary'), href: href, target: '_blank', rel: 'noopener noreferrer' }, text);
	}

	wp.blocks.registerBlockType('meil/form', {
		apiVersion: 2,
		title: s.formTitle,
		description: s.formDescription,
		icon: 'email-alt',
		category: 'widgets',
		keywords: s.keywords,
		attributes: { formId: { type: 'number', default: 0 } },
		supports: { html: false },
		edit: function (props) {
			var blockProps = editor.useBlockProps();
			var body;

			if (!data.forms.length) {
				body = el(c.Placeholder, { icon: 'email-alt', label: s.formTitle, instructions: data.canManage ? s.noForms : s.noFormsEditor },
					data.canManage ? linkButton(data.newUrl, s.create, true) : null);
			} else if (!props.attributes.formId) {
				body = el(c.Placeholder, { icon: 'email-alt', label: s.formTitle, instructions: s.pick }, picker(props));
			} else {
				// The real form, drawn by PHP; not clickable in the editor.
				body = el(c.Disabled, null, el(ServerSideRender, { block: 'meil/form', attributes: props.attributes }));
			}

			var settings = el(editor.InspectorControls, null,
				el(c.PanelBody, { title: s.form },
					data.forms.length ? picker(props) : null,
					data.canManage ? el('p', null, linkButton(data.manageUrl, s.manage, false)) : null
				)
			);

			return el('div', blockProps, settings, body);
		},
		save: function () {
			return null;
		}
	});

	wp.blocks.registerBlockType('meil/preferences', {
		apiVersion: 2,
		title: s.prefsTitle,
		description: s.prefsDescription,
		icon: 'admin-users',
		category: 'widgets',
		keywords: s.keywords,
		supports: { html: false, multiple: false },
		edit: function () {
			return el('div', editor.useBlockProps(),
				el(c.Placeholder, { icon: 'admin-users', label: s.prefsTitle, instructions: s.prefsPreview })
			);
		},
		save: function () {
			return null;
		}
	});
}(window.wp, window.meilBlocks));
