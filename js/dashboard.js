/*
 +-------------------------------------------------------------------------+
 | Dashboard grid/card/render conventions adapted from                     |
 | Cacti plugin_syslog js/dashboard.js at                                  |
 | be1a8151c21ff81684d0e8ca0f9f0a78a8d48f27.                               |
 | Copyright (C) 2004-2026 The Cacti Group. Adaptation copyright 2026      |
 | Sean Mancini, distributed under GPL-2.0-or-later.                       |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | Uses Cacti's locally bundled d3/billboard; no syslog runtime dependency.|
 | https://github.com/bmfmancini/plugin_alarmmanager                       |
 +-------------------------------------------------------------------------+
*/
/** Initialize Alarm Manager editors and dashboard behavior in an isolated scope. */
(() => {
	'use strict';
	const sourceFields = [
		'event.id',
		'event.source',
		'event.source_instance',
		'event.collector_id',
		'event.occurred_at',
		'alarm.resource_id',
		'alarm.key',
		'alarm.episode_id',
		'alarm.sequence',
		'alarm.transition',
		'alarm.severity',
		'alarm.summary',
		'alarm.details',
		'device.id',
		'device.hostname',
		'device.description',
		'device.location',
		'device.contact',
		'device.sysname',
		'device.tags',
		'context.rule_name',
		'context.rule_type',
		'context.metric',
		'context.metric_value',
		'context.threshold',
		'context.suppression_reason',
		'context.source_suppressed',
		'context.source_notification_channels',
		'context.ownership_epoch',
		'integration.id',
		'integration.url',
		'device.cacti_location',
		'device.collector_name',
		'device.external_id',
		'device.snmp_sysUpTimeInstance',
		'device.uptime',
		'device.status_fail_date',
		'device.status_rec_date',
		'device.status_last_error'
	];
	/** Create an element with optional text and a CSS class. */
	function node(tag, text, className) {
		const el = document.createElement(tag);
		if (text !== undefined) el.textContent = text;
		if (className) el.className = className;
		return el;
	}
	const palette = [
		'#2278ad',
		'#e67e22',
		'#329c60',
		'#d43f3a',
		'#8e63aa',
		'#19a2a5',
		'#ad795b',
		'#7b8994',
		'#c4a132',
		'#5d88b3'
	];
	const colorMaps = {
		transitions: {
			raised: '#d43f3a',
			recovered: '#329c60',
			occurrence: '#2278ad',
			updated: '#8e63aa',
			reminder: '#e67e22'
		},
		severity: {
			critical: '#d43f3a',
			major: '#e67e22',
			minor: '#b89822',
			warning: '#c39b13',
			info: '#2278ad',
			clear: '#329c60'
		},
		deliveries: {
			completed: '#329c60',
			failed: '#d43f3a',
			pending: '#7b8994',
			leased: '#2278ad'
		},
		attempts: {
			success: '#329c60',
			failure: '#d43f3a',
			retry: '#e67e22',
			abandoned: '#7b8994',
			in_progress: '#2278ad'
		}
	};
	/** Resolve a panel label's configured color or use the fallback palette. */
	function color(panel, label, index) {
		const value = colorMaps[panel]?.[label];
		return typeof value === 'string' ? value : palette[index % palette.length];
	}
	const fieldLabels = [
		'Event ID',
		'Alarm source',
		'Source instance',
		'Collector ID',
		'Time of alarm',
		'Resource ID',
		'Alarm key',
		'Alarm episode ID',
		'Alarm sequence',
		'Alarm state',
		'Severity',
		'Alarm summary',
		'Alarm details',
		'Device ID',
		'Hostname',
		'Device description',
		'SNMP location',
		'Device contact',
		'Device system name',
		'Device tags',
		'Rule name',
		'Rule type',
		'Metric',
		'Metric value',
		'Threshold',
		'Suppression reason',
		'Source suppressed',
		'Source notification channels',
		'Ownership version',
		'Integration record ID',
		'Integration record URL',
		'Cacti location',
		'Collector name',
		'Device external ID',
		'Last known SNMP uptime (ticks)',
		'Last known SNMP uptime',
		'Last failed datetime',
		'Last restored datetime',
		'Last status error'
	];
	/** Build a combined-text editor with field chips and a sample preview. */
	function messageEditor(template, summary) {
		template.setAttribute('aria-label', 'Template syntax');
		const group = node('div', undefined, 'alarmmanagerCombinedText'),
			label = node('label', 'Combined text'),
			editor = node('div', undefined, 'alarmmanagerMessageEditor');
		editor.contentEditable = 'true';
		editor.setAttribute('role', 'textbox');
		editor.setAttribute('aria-label', 'Combined text');
		editor.setAttribute('aria-multiline', 'true');
		label.append(editor);
		const picker = node('details'),
			toggle = node('summary', 'Insert field'),
			search = node('input'),
			choices = node('div', undefined, 'alarmmanagerFieldChoices');
		search.type = 'search';
		search.placeholder = 'Search fields';
		search.setAttribute('aria-label', 'Search message fields');
		picker.append(toggle, search, choices);
		const preview = node('pre', undefined, 'alarmmanagerMessagePreview');
		preview.setAttribute('aria-label', 'Sample message preview');
		const raw = node('details');
		raw.append(node('summary', 'Template syntax'), template.parentElement);
		group.append(
			label,
			picker,
			node('small', 'Sample preview — example values, not live device data'),
			preview,
			raw
		);
		let caret = null;
		const labelFor =
			/** Resolve the display label for a template field. */
			(field) => fieldLabels[sourceFields.indexOf(field)] || field;
		const textOf =
			/** Serialize editor content back to template syntax, preserving line breaks. */
			(element) =>
				Array.from(element.childNodes)
					.map(
						/** Convert a text node, field chip, or nested element to template text. */
						(child) => {
							if (child.nodeType === Node.TEXT_NODE) return child.textContent;
							if (child.dataset?.field)
								return '{{' + child.dataset.field + '}}';
							if (child.nodeName === 'BR')
								return child === element.lastChild ? '' : '\n';
							return (
								(['DIV', 'P'].includes(child.nodeName) && child.previousSibling
									? '\n'
									: '') + textOf(child)
							);
						}
					)
					.join('');
		/** Remember the editor selection so field insertion restores the caret. */
		function remember() {
			const selection = getSelection();
			if (selection.rangeCount && editor.contains(selection.anchorNode))
				caret = selection.getRangeAt(0).cloneRange();
		}
		/** Create an accessible, removable template-field chip. */
		function chip(field) {
			const tag = node('span', labelFor(field), 'alarmmanagerMessageChip');
			tag.dataset.field = field;
			tag.contentEditable = 'false';
			tag.tabIndex = 0;
			tag.setAttribute('role', 'button');
			tag.setAttribute(
				'aria-label',
				labelFor(field) + '. Press Delete to remove'
			);
			tag.addEventListener(
				'keydown',
				/** Remove the focused chip when Delete or Backspace is pressed. */
				(event) => {
					if (['Backspace', 'Delete'].includes(event.key)) {
						event.preventDefault();
						tag.remove();
						sync();
						editor.focus();
					}
				}
			);
			return tag;
		}
		/** Rebuild editor text and chips from the raw template value. */
		function draw() {
			editor.replaceChildren();
			const pattern = /{{([^{}]*)}}/g;
			let last = 0;
			for (const match of template.value.matchAll(pattern)) {
				editor.append(
					document.createTextNode(template.value.slice(last, match.index))
				);
				const field = match[1].trim();
				editor.append(
					sourceFields.includes(field)
						? chip(field)
						: document.createTextNode(match[0])
				);
				last = match.index + match[0].length;
			}
			editor.append(document.createTextNode(template.value.slice(last)));
			caret = null;
			showPreview();
		}
		/** Render the template with example values for the preview. */
		function showPreview() {
			const samples = {
				'device.hostname': 'router.example.test',
				'device.description': 'Core router',
				'device.location': 'Toronto DC',
				'device.contact': 'Network team',
				'device.sysname': 'edge-01',
				'device.id': '42',
				'alarm.summary': summary,
				'alarm.transition': 'raised',
				'alarm.severity': 'major',
				'event.occurred_at': '2026-10-14T12:00:00Z'
			};
			preview.textContent = template.value.replace(
				/{{([^{}]*)}}/g,
				/** Substitute a sample value or a labeled placeholder for a template field. */
				(_, field) =>
					samples[field.trim()] ?? '[' + labelFor(field.trim()) + ' sample]'
			);
		}
		/** Save editor text, notify listeners, and refresh the preview and caret. */
		function sync() {
			template.value = textOf(editor);
			template.dispatchEvent(new Event('input', { bubbles: true }));
			showPreview();
			remember();
		}
		/** Insert content at the remembered caret and synchronize the template. */
		function insert(content) {
			editor.focus();
			const selection = getSelection(),
				range =
					caret && editor.contains(caret.startContainer)
						? caret
						: document.createRange();
			if (!caret || !editor.contains(range.startContainer)) {
				range.selectNodeContents(editor);
				range.collapse(false);
			}
			range.deleteContents();
			range.insertNode(content);
			range.setStartAfter(content);
			range.collapse(true);
			selection.removeAllRanges();
			selection.addRange(range);
			caret = range.cloneRange();
			sync();
		}
		/** Rebuild the field picker using the current search text. */
		function filter() {
			choices.replaceChildren();
			sourceFields
				.filter(
					/** Match searchable scalar fields against their display labels. */
					(field) =>
						!['device.tags', 'context.source_notification_channels'].includes(
							field
						) &&
						labelFor(field).toLowerCase().includes(search.value.toLowerCase())
				)
				.forEach(
					/** Create a picker button for an available template field. */
					(field) => {
						const button = node('button', labelFor(field));
						button.type = 'button';
						button.addEventListener(
							'click',
							/** Insert the selected field chip and close the picker. */
							() => {
								insert(chip(field));
								picker.open = false;
							}
						);
						choices.append(button);
					}
				);
			if (!choices.children.length)
				choices.append(node('p', 'No matching fields'));
		}
		editor.addEventListener('input', sync);
		editor.addEventListener('keyup', remember);
		editor.addEventListener('mouseup', remember);
		editor.addEventListener('blur', remember);
		editor.addEventListener(
			'paste',
			/** Paste clipboard content as plain text at the current selection. */
			(event) => {
				event.preventDefault();
				remember();
				insert(
					document.createTextNode(event.clipboardData.getData('text/plain'))
				);
			}
		);
		editor.addEventListener(
			'drop',
			/** Insert dropped content as plain text. */
			(event) => {
				event.preventDefault();
				insert(
					document.createTextNode(event.dataTransfer.getData('text/plain'))
				);
			}
		);
		template.addEventListener(
			'input',
			/** Refresh chips after an external change to the raw template. */
			() => {
				if (document.activeElement !== editor) draw();
			}
		);
		search.addEventListener('input', filter);
		picker.addEventListener(
			'toggle',
			/** Focus the search input when the field picker opens. */
			() => {
				if (picker.open) search.focus();
			}
		);
		filter();
		draw();
		return {
			group,
			refresh: draw,
			/** Focus the combined-text editor. */ focus: () => editor.focus()
		};
	}
	/** Initialize structured message mappings for integration and rule forms. */
	function initMappings(app) {
		app.querySelectorAll('.alarmmanagerMapping').forEach(
			/** Build and bind the mapping editor for one fieldset. */
			(fieldset) => {
				const textarea = fieldset.querySelector('textarea[name]'),
					holder = fieldset.querySelector('.alarmmanagerMappingBuilder'),
					form = fieldset.closest('form'),
					endpointType = fieldset.dataset.mappingType
						? { value: fieldset.dataset.mappingType }
						: form.querySelector('[name=type]');
				const add = node('button', 'Add field'),
					status = node('p'),
					rows = node('div');
				add.type = 'button';
				holder.append(status, rows, add);
				const selectedState =
					/** Get the alarm state whose mapping is being edited. */
					() => fieldset.dataset.mappingState || 'default';
				let mapping = {};
				const parseValue =
					/** Parse a JSON value, retaining plain text when parsing fails. */
					(text) => {
						try {
							return JSON.parse(text);
						} catch {
							return text;
						}
					};
				const save =
					/** Serialize the mapping into the form's backing textarea. */
					() => {
						textarea.value = JSON.stringify(mapping, null, 2);
					};
				/** Render the selected state's mapping fields and email-specific controls. */
				function render() {
					rows.replaceChildren();
					const selected = selectedState(),
						email = endpointType.value === 'email';
					add.hidden = email;
					status.textContent = '';
					const items = mapping[selected] || [],
						extraEmail = node('details');
					extraEmail.append(node('summary', 'Additional message fields'));
					items.forEach(
						/** Build controls for one destination-field mapping. */
						(item, index) => {
							const row = node('div', undefined, 'alarmmanagerMappingRow');
							/** Wrap a control in a label and append it to its parent. */
							function control(label, element, parent = row) {
								const wrap = node('label', label);
								wrap.append(element);
								parent.append(wrap);
								return element;
							}
							const path = control('Destination Field Name', node('input'));
							path.value = item.to || '';
							path.maxLength = 191;
							if (email && ['subject', 'body'].includes(item.to)) {
								path.parentElement.hidden = true;
								row.append(
									node('strong', item.to === 'subject' ? 'Subject' : 'Message')
								);
							}
							const source = control('Cacti Value', node('select'));
							source.setAttribute('aria-label', 'Cacti Value');
							['constant', 'template', ...sourceFields].forEach(
								/** Add an available source field to the mapping selector. */
								(key, i) => {
									const o = node(
										'option',
										i === 0
											? 'Custom value'
											: i === 1
												? 'Combined text'
												: fieldLabels[i - 2] || key
									);
									o.value = key;
									source.append(o);
								}
							);
							source.value =
								'template' in item ? 'template' : item.source || 'constant';
							const constant = control(
								email && item.to === 'body' ? 'Message text' : 'Custom value',
								node(email && item.to === 'body' ? 'textarea' : 'input')
							);
							constant.value =
								'constant' in item
									? typeof item.constant === 'string'
										? item.constant
										: JSON.stringify(item.constant)
									: '';
							const template = control('Combined text', node('textarea'));
							template.value = item.template || '';
							template.maxLength = 16384;
							template.placeholder =
								'Device {{device.id}} with description {{device.description}} went down at {{event.occurred_at}}';
							const chips = messageEditor(
									template,
									fieldset.dataset.alarmSummary
								),
								templateGroup = chips.group;
							row.append(templateGroup);
							const deviceMessage = node('button', 'Use device message');
							deviceMessage.type = 'button';
							row.append(deviceMessage);
							deviceMessage.addEventListener(
								'click',
								/** Apply the shared device-message template and focus its editor. */
								() => {
									source.value = 'template';
									template.value = fieldset.dataset.messageTemplate;
									type.value = 'string';
									update();
									chips.refresh();
									chips.focus();
								}
							);
							const options = node('details');
							options.className = 'alarmmanagerFieldOptions';
							options.append(node('summary', 'Field options'));
							row.append(options);
							const type = control('Value format', node('select'), options);
							[
								'identity',
								'string',
								'integer',
								'number',
								'boolean',
								'html'
							].forEach(
								/** Add a supported value conversion to the format selector. */
								(key, i) => {
									const o = node(
										'option',
										[
											'Keep original value',
											'Text',
											'Whole number',
											'Number',
											'Yes / no',
											'HTML-escaped text'
										][i]
									);
									o.value = key;
									type.append(o);
								}
							);
							type.value = item.type || 'identity';
							const fallback = control(
								'Use when missing',
								node('input'),
								options
							);
							fallback.value =
								'default' in item ? JSON.stringify(item.default) : '';
							const values = control(
								'Value replacements (JSON)',
								node('input'),
								options
							);
							values.value = item.values ? JSON.stringify(item.values) : '';
							const required = control(
								'Fail delivery if missing',
								node('input'),
								options
							);
							required.type = 'checkbox';
							required.checked = !!item.required;
							const remove = node('button', 'Remove field');
							remove.type = 'button';
							remove.hidden = email && ['subject', 'body'].includes(item.to);
							row.append(remove);
							/** Update the mapping from its controls and validate replacement JSON. */
							function update(event) {
								constant.parentElement.hidden = source.value !== 'constant';
								templateGroup.hidden = source.value !== 'template';
								const fresh = {
									...item,
									to: path.value,
									type: type.value,
									required: required.checked
								};
								delete fresh.source;
								delete fresh.constant;
								delete fresh.template;
								delete fresh.default;
								delete fresh.values;
								if (source.value === 'constant')
									fresh.constant =
										event?.target === constant
											? typeof item.constant === 'string' ||
												!('constant' in item)
												? constant.value
												: parseValue(constant.value)
											: 'constant' in item
												? item.constant
												: parseValue(constant.value);
								else if (source.value === 'template')
									fresh.template = template.value;
								else fresh.source = source.value;
								if (fallback.value !== '')
									fresh.default = parseValue(fallback.value);
								if (values.value !== '') {
									try {
										fresh.values = JSON.parse(values.value);
										values.setCustomValidity('');
									} catch {
										values.setCustomValidity(
											'Use valid JSON for the value replacements.'
										);
										options.open = true;
										return;
									}
								}
								mapping[selected][index] = fresh;
								item = fresh;
								save();
							}
							constant.parentElement.hidden = source.value !== 'constant';
							templateGroup.hidden = source.value !== 'template';
							[
								path,
								source,
								constant,
								template,
								type,
								fallback,
								values,
								required
							].forEach(
								/** Synchronize a mapping whenever one of its controls changes. */
								(el) => el.addEventListener('input', update)
							);
							jQuery(row).on(
								'change',
								'select',
								/** Handle select changes forwarded through jQuery. */
								(event) => {
									update(event);
								}
							);
							remove.addEventListener(
								'click',
								/** Remove the mapping field and refresh the saved configuration. */
								() => {
									mapping[selected].splice(index, 1);
									save();
									render();
								}
							);
							if (email && !['subject', 'body'].includes(item.to)) {
								extraEmail.append(row);
							} else rows.append(row);
						}
					);
					if (extraEmail.children.length > 1) rows.append(extraEmail);
				}
				/** Validate and load the raw mapping JSON into the structured editor. */
				function read() {
					try {
						const value = JSON.parse(textarea.value);
						if (
							!value ||
							typeof value !== 'object' ||
							Array.isArray(value) ||
							Object.values(value).some(
								/** Detect state mappings that are not arrays. */
								(v) => !Array.isArray(v)
							)
						)
							throw Error();
						mapping = value;
						textarea.setCustomValidity('');
						render();
					} catch {
						status.textContent =
							'Invalid message configuration. Correct the advanced configuration to continue.';
						fieldset.querySelector('.alarmmanagerRawMapping').open = true;
						textarea.setCustomValidity('Invalid message configuration.');
					}
				}
				/** Append a mapping field unless the per-message limit is reached. */
				function addField(to, source) {
					const key = selectedState();
					mapping[key] ??= [];
					if (mapping[key].length >= 64) {
						status.textContent = 'Maximum 64 fields per message.';
						return;
					}
					mapping[key].push({ to, source, type: 'identity', required: false });
					save();
					render();
				}
				add.addEventListener(
					'click',
					/** Add a destination field with a generated name and hostname source. */
					() =>
						addField(
							'field_' + ((mapping[selectedState()] || []).length + 1),
							'device.hostname'
						)
				);
				/** Ensure email mappings have subject and body fields after a type change. */
				function changeType() {
					if (endpointType.value === 'email') {
						mapping.default ??= [];
						for (const item of [
							{
								to: 'subject',
								source: 'alarm.summary',
								type: 'string',
								required: true
							},
							{
								to: 'body',
								template: fieldset.dataset.messageTemplate,
								type: 'string',
								required: false
							}
						]) {
							if (
								!mapping.default.some(
									/** Check whether an email field or one of its nested fields already exists. */
									(existing) =>
										existing.to === item.to ||
										existing.to.startsWith(item.to + '.')
								)
							)
								mapping.default.push(item);
						}
						save();
					}
					render();
				}
				jQuery(form).on('change', '[name=type]', changeType);
				textarea.addEventListener('input', read);
				read();
				if (!fieldset.dataset.mappingType && !form.querySelector('[name=id]'))
					changeType();
			}
		);
	}
	/** Initialize per-rule destination-template override controls. */
	function initRuleTemplates(app) {
		app.querySelectorAll('[data-rule-template]').forEach(
			/** Bind one rule-template section to its destination and override toggles. */
			(section) => {
				const form = section.closest('form'),
					destination = form.querySelector(
						'[data-alarm-destination][value="' +
							section.dataset.ruleTemplate +
							'"]'
					),
					toggle = section.querySelector('[name="template_enabled[]"]'),
					fields = section.querySelector('.alarmmanagerMapping');
				const update =
					/** Update template visibility and editability from the selected destination. */
					() => {
						section.hidden = !destination.checked;
						toggle.disabled = !destination.checked;
						fields.disabled = !destination.checked || !toggle.checked;
						fields.inert = fields.disabled;
					};
				destination.addEventListener('change', update);
				toggle.addEventListener('change', update);
				update();
			}
		);
	}
	/** Initialize structured editors for headers, recipients, tags, and arguments. */
	function initLists(app) {
		app.querySelectorAll('[data-list-editor]').forEach(
			/** Build a list editor from one backing input or textarea. */
			(target) => {
				const kind = target.dataset.listEditor,
					jsonList = ['arguments', 'tags'].includes(kind),
					input = target.matches('input')
						? target
						: target.querySelector('textarea'),
					container = node('div', undefined, 'alarmmanagerListEditor'),
					rows = node('div'),
					add = node(
						'button',
						kind === 'headers'
							? 'Add header'
							: kind === 'recipients'
								? 'Add recipient'
								: kind === 'tags'
									? 'Add tag'
									: 'Add argument'
					);
				add.type = 'button';
				let entries;
				try {
					if (kind === 'headers')
						entries = Object.entries(JSON.parse(input.value));
					else if (jsonList) entries = JSON.parse(input.value);
					else {
						entries =
							input.value.trim().startsWith('[') ||
							input.value.trim().startsWith('{')
								? JSON.parse(input.value)
								: input.value
										.split(',')
										.map(
											/** Trim a comma-separated list entry. */
											(v) => v.trim()
										)
										.filter(Boolean);
					}
					if (
						!Array.isArray(entries) ||
						entries.some(
							/** Reject malformed header pairs or non-string list values. */
							(v) =>
								kind === 'headers'
									? !Array.isArray(v) || typeof v[1] !== 'string'
									: typeof v !== 'string'
						)
					)
						return;
				} catch {
					return;
				}
				const originalLabel = input.closest('label'),
					raw = node('details'),
					rawSummary = node('summary', 'Advanced configuration');
				originalLabel.before(container);
				container.append(
					node(
						'strong',
						kind === 'headers'
							? 'Authentication headers'
							: kind === 'recipients'
								? 'Recipients'
								: kind === 'tags'
									? 'Device tags'
									: 'Arguments'
					),
					rows,
					add,
					raw
				);
				raw.append(rawSummary, input);
				originalLabel.remove();
				input.hidden = true;
				/** Save list entries in the backing field's expected format. */
				function save() {
					input.value =
						kind === 'headers'
							? JSON.stringify(Object.fromEntries(entries))
							: jsonList
								? JSON.stringify(entries)
								: entries.join(', ');
					input.dispatchEvent(new Event('input', { bubbles: true }));
				}
				/** Render editable list rows from the current entries. */
				function render() {
					rows.replaceChildren();
					entries.forEach(
						/** Create the controls for one list entry. */
						(entry, index) => {
							const row = node('div', undefined, 'alarmmanagerMappingRow');
							const name = node('input'),
								value = node('input');
							value.type =
								kind === 'headers'
									? 'password'
									: kind === 'recipients'
										? 'email'
										: 'text';
							value.value = kind === 'headers' ? entry[1] : entry;
							const label = node(
								'label',
								kind === 'headers'
									? 'Secret value'
									: kind === 'recipients'
										? 'Email address'
										: kind === 'tags'
											? 'Tag'
											: 'Argument'
							);
							label.append(value);
							if (kind === 'headers') {
								name.value = entry[0];
								name.setCustomValidity(
									entries.filter(
										/** Match existing header names without case sensitivity. */
										(v) => v[0].toLowerCase() === entry[0].toLowerCase()
									).length > 1
										? 'Header names must be unique.'
										: ''
								);
								const keyLabel = node('label', 'Header name');
								keyLabel.append(name);
								row.append(keyLabel);
							}
							row.append(label);
							const remove = node('button', 'Remove');
							remove.type = 'button';
							row.append(remove);
							rows.append(row);
							/** Save an edited entry and validate header-name uniqueness. */
							function update() {
								if (kind === 'headers') {
									entries[index] = [name.value, value.value];
									const keys = entries.map(
										/** Normalize a header name for duplicate detection. */
										(v) => v[0].toLowerCase()
									);
									name.setCustomValidity(
										keys.filter(
											/** Match a normalized name against the current header name. */
											(v) => v === name.value.toLowerCase()
										).length > 1
											? 'Header names must be unique.'
											: ''
									);
								} else entries[index] = value.value;
								save();
							}
							name.addEventListener('input', update);
							value.addEventListener('input', update);
							remove.addEventListener(
								'click',
								/** Remove a list entry and refresh the saved value and rows. */
								() => {
									entries.splice(index, 1);
									save();
									render();
								}
							);
						}
					);
				}
				add.addEventListener(
					'click',
					/** Append an empty entry and focus its first input. */
					() => {
						entries.push(kind === 'headers' ? ['', ''] : '');
						render();
						rows.lastElementChild.querySelector('input').focus();
					}
				);
				render();
				// The structured editor owns this value; advanced fallback remains available without JavaScript.
				raw.hidden = true;
			}
		);
	}
	/** Display delivery intervals in seconds while retaining millisecond storage. */
	function initDeliveryIntervals(app) {
		app.querySelectorAll('[name=min_interval_ms]').forEach(
			/** Create a seconds input for one stored millisecond interval. */
			(stored) => {
				const seconds = stored.cloneNode();
				seconds.removeAttribute('name');
				seconds.removeAttribute('value');
				seconds.value = Number(stored.value) / 1000;
				seconds.max = Number(stored.max) / 1000;
				seconds.step = '0.001';
				stored.before(seconds);
				stored.hidden = true;
				stored.required = false;
				stored.parentElement.firstChild.textContent =
					'Minimum time between deliveries (seconds) ';
				seconds.addEventListener(
					'input',
					/** Convert a valid seconds value back to integer milliseconds. */
					() => {
						if (seconds.validity.valid)
							stored.value = Math.round(Number(seconds.value) * 1000);
					}
				);
			}
		);
	}
	/** Initialize credential fields for the selected authentication types. */
	function initAuthentication(app) {
		app.querySelectorAll('.alarmmanagerAuth').forEach(
			/** Bind authentication controls within one fieldset. */
			(fieldset) => {
				const select = fieldset.querySelector('[name=auth_type]');
				/** Refresh authentication groups after the selected type changes. */
				function update() {
					fieldset.querySelectorAll('.alarmmanagerAuthFields').forEach(
						/** Show the matching authentication group and update its fields. */
						(group) => {
							const active = group.dataset.authType === select.value;
							group.hidden = !active;
							group.querySelectorAll('input,textarea').forEach(
								/** Enable and require credential inputs only for the active group. */
								(input) => {
									input.disabled = !active;
									input.required =
										active &&
										input.name !== 'basic_password' &&
										!fieldset.dataset.authOptional;
								}
							);
						}
					);
				}
				jQuery(fieldset)
					.off('change.alarmmanagerAuth')
					.on('change.alarmmanagerAuth', '[name=auth_type]', update);
				update();
			}
		);
	}
	/** Initialize fields that depend on the selected endpoint type. */
	function initEndpointTypes(app) {
		app.querySelectorAll('form.alarmmanagerEditor').forEach(
			/** Bind the endpoint-type selector in one editor form. */
			(form) => {
				const select = form.querySelector('[name=type]');
				if (!select) return;
				/** Refresh endpoint-specific fields and HTTP authentication controls. */
				function update() {
					form.querySelectorAll('[data-endpoint-types]').forEach(
						/** Show the field group supported by the selected endpoint type. */
						(group) => {
							const active = group.dataset.endpointTypes
								.split(' ')
								.includes(select.value);
							group.hidden = !active;
							group.querySelectorAll('input,select,textarea').forEach(
								/** Disable controls in inactive endpoint-type groups. */
								(input) => {
									input.disabled = !active;
								}
							);
						}
					);
					if (['restapi', 'webhook'].includes(select.value))
						initAuthentication(form);
				}
				jQuery(form).on('change', '[name=type]', update);
				update();
			}
		);
	}
	/** Initialize ServiceNow incident and event preset switching. */
	function initServiceNow(app) {
		app.querySelectorAll('[data-servicenow-presets]').forEach(
			/** Bind a ServiceNow preset selector to its endpoint form. */
			(select) => {
				const form = select.closest('form'),
					presets = JSON.parse(select.dataset.servicenowPresets),
					mapping = form.querySelector('[name=mapping_json]'),
					fieldset = form.querySelector('.alarmmanagerMapping');
				/** Select the message state appropriate to the ServiceNow record type. */
				function showFields() {
					fieldset.dataset.mappingState =
						select.value === 'incident' ? 'raised' : 'default';
					mapping.dispatchEvent(new Event('input', { bubbles: true }));
				}
				jQuery(form).on(
					'change',
					'[name=servicenow_table]',
					/** Apply the chosen preset while preserving the configured instance URL. */
					() => {
						const preset = presets[select.value],
							url = form.querySelector('[name=url]'),
							raw = form.querySelector('[name=transport_json]');
						let transport;
						try {
							transport = JSON.parse(raw.value);
						} catch {
							return;
						}
						const destination = new URL(preset.transport.url);
						try {
							const current = new URL(url.value);
							destination.protocol = current.protocol;
							destination.host = current.host;
						} catch {}
						url.value = destination.href;
						delete transport.transition_overrides;
						delete transport.response_reference;
						if (preset.transport.transition_overrides)
							transport.transition_overrides = Object.fromEntries(
								Object.entries(preset.transport.transition_overrides).map(
									/** Rebase a transition override on the selected instance URL. */
									([key, target]) => [
										key,
										{ ...target, url: url.value + '/{{integration.id}}' }
									]
								)
							);
						if (preset.transport.response_reference)
							transport.response_reference =
								preset.transport.response_reference;
						raw.value = JSON.stringify(transport, null, 2);
						form.querySelector('[name=http_method]').value = 'POST';
						mapping.value = JSON.stringify(preset.transport.mapping, null, 2);
						mapping.dispatchEvent(new Event('input', { bubbles: true }));
						showFields();
						const hint = form.querySelector('[data-integration-hint]');
						if (hint) hint.textContent = preset.hint;
					}
				);
				showFields();
			}
		);
	}
	// The Syslog version-1 document keeps grouped AND/OR/NOT conditions parameterized on the server.
	/** Initialize grouped report and rule query builders. */
	function initReportQuery(app) {
		app.querySelectorAll('.alarmmanagerQueryBuilder').forEach(
			/** Build an editor for one serialized query document. */
			(builder) => {
				const form = builder.closest('form'),
					raw = builder.querySelector('textarea'),
					holder = builder.querySelector('.alarmmanagerQueryRows'),
					fields = JSON.parse(builder.dataset.queryFields);
				const defaultField = Object.keys(fields)[0],
					defaultOperator = fields[defaultField].operators[0];
				const blank =
					/** Create a blank condition using the default field and operator. */
					() => ({
						join: 'AND',
						field: defaultField,
						operator: defaultOperator,
						value: '',
						negative: false
					});
				let rows = raw.value ? JSON.parse(raw.value).conditions : [blank()];
				const status = node('p');
				status.setAttribute('role', 'status');
				builder.append(status);
				/** Check whether the query consists of a single untouched condition. */
				function empty() {
					return (
						rows.length === 1 &&
						!rows[0].rows &&
						rows[0].field === defaultField &&
						rows[0].operator === defaultOperator &&
						!rows[0].negative &&
						rows[0].value === ''
					);
				}
				/** Serialize enabled query conditions and enforce the byte limit. */
				function sync() {
					if (builder.disabled) return true;
					raw.value = empty()
						? ''
						: JSON.stringify({ version: 1, conditions: rows });
					status.textContent =
						new TextEncoder().encode(raw.value).length > 8192
							? 'The search exceeds 8192 bytes. Remove a condition or shorten a value.'
							: '';
					return !status.textContent;
				}
				/** Create an accessible selector that saves changes to the query. */
				function select(options, value, label, change) {
					const control = node('select');
					control.setAttribute('aria-label', label);
					options.forEach(
						/** Append one labeled option to the selector. */
						([key, text]) => {
							const option = node('option', text);
							option.value = key;
							control.append(option);
						}
					);
					control.value = value;
					jQuery(control).on(
						'change',
						/** Apply the selected value and synchronize the query document. */
						() => {
							change(control.value);
							sync();
						}
					);
					return control;
				}
				/** Render query conditions and nested groups with their action controls. */
				function render(container, conditions, depth = 0) {
					// Remove Cacti's body-appended dropdown menus before replacing their controls.
					jQuery(container)
						.find('select')
						.each(
							/** Destroy Select2 menus before their controls are replaced. */
							function () {
								if (jQuery(this).data('select2'))
									jQuery(this).select2('destroy');
							}
						);
					container.replaceChildren();
					conditions.forEach(
						/** Render one condition or nested group with its connector and controls. */
						(condition, index) => {
							const line = node('div', undefined, 'alarmmanagerQueryRow'),
								connector = node(
									'div',
									undefined,
									'alarmmanagerQueryConnector'
								);
							if (index)
								connector.append(
									select(
										[
											['AND', 'AND'],
											['OR', 'OR']
										],
										condition.join,
										'AND / OR',
										/** Update the condition's AND/OR connector. */
										(value) => (condition.join = value)
									)
								);
							else connector.textContent = depth ? 'Group' : 'Where';
							line.append(connector);
							if (condition.negative)
								connector.append(node('strong', 'NOT', 'alarmmanagerQueryNot'));
							if (condition.rows) {
								const group = node('div', undefined, 'alarmmanagerQueryGroup');
								line.classList.add('alarmmanagerQueryGroupRow');
								render(group, condition.rows, depth + 1);
								line.append(group);
							} else {
								line.append(
									select(
										Object.entries(fields).map(
											/** Convert a field definition to a labeled selector option. */
											([key, field]) => [key, field.label]
										),
										condition.field,
										'Search field',
										/** Reset the condition for a new field and schedule a control refresh. */
										(value) => {
											condition.field = value;
											condition.operator = fields[value].operators[0];
											condition.value = '';
											queueMicrotask(
												/** Rebuild controls after the field-selection event completes. */
												() => render(container, conditions, depth)
											);
										}
									)
								);
								const definition = fields[condition.field];
								line.append(
									select(
										definition.operators.map(
											/** Convert a query operator into its display label. */
											(op) => [
												op,
												{
													'contains': 'contains',
													'begins': 'begins with',
													'ends': 'ends with',
													'=': 'equals',
													'!=': 'does not equal',
													'has': 'has tag'
												}[op] || op
											]
										),
										condition.operator,
										'Search operator',
										/** Update the condition's comparison operator. */
										(value) => {
											condition.operator = value;
										}
									)
								);
								let input;
								if (definition.choices && !Array.isArray(definition.choices)) {
									const choices = { ...definition.choices };
									if (condition.value !== '' && !(condition.value in choices))
										choices[condition.value] =
											`#${condition.value} (not listed)`;
									input = select(
										[['', 'Choose a value'], ...Object.entries(choices)],
										String(condition.value),
										'Search value',
										/** Store the selected predefined condition value. */
										(value) => (condition.value = value)
									);
									input.required = true;
								} else {
									input = node('input');
									input.type = 'text';
									input.value = condition.value;
									input.maxLength = 2048;
									input.setAttribute('aria-label', 'Search value');
									input.placeholder =
										definition.type === 'integer'
											? 'Enter a nonnegative integer'
											: 'Enter a value';
									input.required = !(depth === 0 && empty());
									if (definition.type === 'integer') {
										input.pattern = '[0-9]+';
										input.inputMode = 'numeric';
									}
									input.addEventListener(
										'input',
										/** Save a typed condition value and synchronize the query. */
										() => {
											condition.value = input.value;
											sync();
										}
									);
									if (definition.choices) {
										const list = node('datalist');
										list.id =
											'am-query-values-' + Math.random().toString(36).slice(2);
										definition.choices.forEach(
											/** Add a suggested value to the condition's datalist. */
											(value) => {
												const option = node('option');
												option.value = value;
												list.append(option);
											}
										);
										input.setAttribute('list', list.id);
										line.append(list);
									}
								}
								line.append(input);
							}
							const remove = node('button', 'Remove');
							remove.type = 'button';
							remove.setAttribute('aria-label', 'Remove condition');
							remove.addEventListener(
								'click',
								/** Remove a condition, retaining a blank row when the group becomes empty. */
								() => {
									conditions.splice(index, 1);
									if (!conditions.length) conditions.push(blank());
									render(container, conditions, depth);
									sync();
								}
							);
							line.append(remove);
							container.append(line);
						}
					);
					const actions = node('div', undefined, 'alarmmanagerQueryActions');
					['AND', 'OR', 'NOT', 'Group'].forEach(
						/** Create an action button for a connector, negation, or nested group. */
						(action) => {
							const button = node('button', action);
							button.type = 'button';
							button.setAttribute('aria-label', 'Add ' + action);
							button.disabled = depth >= 15 && action === 'Group';
							button.addEventListener(
								'click',
								/** Append a condition or group, render it, and focus its value input. */
								() => {
									conditions.push(
										action === 'Group'
											? { join: 'AND', negative: false, rows: [blank()] }
											: {
													...blank(),
													join: action === 'OR' ? 'OR' : 'AND',
													negative: action === 'NOT'
												}
									);
									render(container, conditions, depth);
									sync();
									container
										.querySelectorAll('[aria-label="Search value"]')
										[
											container.querySelectorAll('[aria-label="Search value"]')
												.length - 1
										]?.focus();
								}
							);
							actions.append(button);
						}
					);
					container.append(actions);
				}
				const clear = node('button', 'Clear conditions');
				clear.type = 'button';
				clear.addEventListener(
					'click',
					/** Reset the query to a single blank condition. */
					() => {
						rows = [blank()];
						render(holder, rows);
						sync();
					}
				);
				builder.append(clear);
				raw.hidden = true;
				const rawGroup = builder.querySelector('.alarmmanagerQueryRaw');
				if (rawGroup) rawGroup.hidden = true;
				form.addEventListener(
					'submit',
					/** Prevent form submission when the query exceeds its size limit. */
					(event) => {
						if (!sync()) {
							event.preventDefault();
							event.stopImmediatePropagation();
						}
					}
				);
				render(holder, rows);
				sync();
				const quick = app.querySelector('.alarmmanagerQuickFilters');
				if (
					quick &&
					Array.from(quick.querySelectorAll('input,select')).some(
						/** Detect an active quick filter so its controls can be expanded. */
						(input) => input.value !== '' && input.value !== '0'
					)
				)
					quick.open = true;
			}
		);
	}

	/** Initialize rule filters, destination validation, and optional settings. */
	function initRuleEditors(app) {
		app.querySelectorAll('.alarmmanagerRule form').forEach(
			/** Initialize device and alarm-filter toggles within one rule form. */
			(form) => {
				[
					['all_devices', 'device_query'],
					['all_alarm_types', 'alarm_query']
				].forEach(
					/** Bind an all-values toggle to its corresponding query builder. */
					([name, query]) => {
						const toggle = form.querySelector(`[name=${name}]`),
							builder = form
								.querySelector(`[name=${query}]`)
								?.closest('.alarmmanagerQueryBuilder');
						if (!toggle || !builder) return;
						const update =
							/** Hide and disable a query builder when all values are selected. */
							() => {
								builder.hidden = toggle.checked;
								builder.disabled = toggle.checked;
							};
						toggle.addEventListener('change', update);
						update();
					}
				);
			}
		);
		app.querySelectorAll('.alarmmanagerDestinations').forEach(
			/** Require at least one destination in a destination checkbox group. */
			(group) => {
				const boxes = Array.from(
					group.querySelectorAll('[data-alarm-destination]')
				);
				if (!boxes.length) return;
				const update =
					/** Update the destination group's validation message. */
					() =>
						boxes[0].setCustomValidity(
							boxes.some(
								/** Check whether a destination is selected. */
								(box) => box.checked
							)
								? ''
								: 'Select at least one alarm destination.'
						);
				boxes.forEach(
					/** Revalidate the destination group when a checkbox changes. */
					(box) => box.addEventListener('change', update)
				);
				update();
			}
		);
		app.querySelectorAll('.alarmmanagerRuleOption').forEach(
			/** Bind an optional rule setting to its checkbox. */
			(option) => {
				const toggle = option.querySelector('input[type=checkbox]'),
					fields = option.querySelector('[data-rule-fields]');
				if (!toggle || !fields) return;
				const update =
					/** Show and enable optional rule fields when their checkbox is selected. */
					() => {
						fields.hidden = !toggle.checked;
						fields.disabled = !toggle.checked;
					};
				toggle.addEventListener('change', update);
				update();
			}
		);
	}
	/** Initialize the current Alarm Manager page once and load its dashboard. */
	function init() {
		const app = document.getElementById('alarmmanager_app');
		if (!app || app.dataset.initialized) return;
		app.dataset.initialized = 'yes';
		initRuleEditors(app);
		initReportQuery(app);
		initMappings(app);
		initRuleTemplates(app);
		initLists(app);
		initDeliveryIntervals(app);
		initAuthentication(app);
		initEndpointTypes(app);
		initServiceNow(app);
		app.querySelectorAll('.alarmmanagerEndpointEditor').forEach(
			/** Bind endpoint submissions to preserve explicit test-send intent. */
			(form) => {
				form.addEventListener(
					'submit',
					/** Preserve the test action before Cacti disables submit buttons. */
					(event) => {
						form.querySelector('[data-test-intent]')?.remove();
						// Cacti disables submit buttons before the browser serializes their values.
						if (event.submitter?.name === 'test_destination') {
							const intent = node('input');
							intent.type = 'hidden';
							intent.name = 'test_destination';
							intent.value = 'yes';
							intent.dataset.testIntent = 'yes';
							form.append(intent);
						}
					},
					true
				);
			}
		);
		const dashboard = app.querySelector('#alarmmanager_dashboard');
		if (!dashboard) return;
		const form = app.querySelector('#alarmmanager_filters'),
			status = app.querySelector('#alarmmanager_dashboard_status'),
			refresh = app.querySelector('#alarmmanager_refresh');
		const charts = {};
		let inFlight = false,
			timer = null;
		/** Build a filtered report URL, mapping legacy job tabs to Events. */
		function url(tab, extra = {}, filters = {}) {
			if (['deliveries', 'processing', 'attempts'].includes(tab)) {
				extra = {
					...extra,
					job: {
						deliveries: 'delivery',
						processing: 'processing',
						attempts: 'attempt'
					}[tab]
				};
				tab = 'events';
			}
			const values = new URLSearchParams();
			for (const [key, value] of Object.entries(filters)) {
				if (
					[
						'range',
						'until',
						'mode',
						'source',
						'severity',
						'transition',
						'status',
						'reason',
						'device_id',
						'endpoint_id',
						'event_id',
						'acknowledged',
						'query'
					].includes(key)
				)
					values.set(key, String(value));
			}
			values.set('tab', tab);
			for (const [key, value] of Object.entries(extra))
				values.set(key, String(value));
			return 'alarmmanager.php?' + values.toString();
		}
		/** Create a report link with the requested filters and extra parameters. */
		function link(text, tab, extra, filters) {
			const a = node('a', text);
			a.href = url(tab, extra, filters);
			return a;
		}
		/** Render a panel legend with colored, filtered report links. */
		function legend(panel, rows, tab, field, filters) {
			const holder = app.querySelector('#alarmmanager_legend_' + panel);
			holder.replaceChildren();
			const list = node('ul');
			rows.forEach(
				/** Append a colored legend entry for one result row. */
				(row, index) => {
					const li = node('li'),
						swatch = node('span', undefined, 'alarmmanagerSwatch');
					swatch.style.backgroundColor = color(panel, row.label, index);
					swatch.setAttribute('aria-hidden', 'true');
					li.append(
						swatch,
						link(
							`${row.label}: ${Number(row.count).toLocaleString()}`,
							tab,
							{ [field]: row.label },
							filters
						)
					);
					list.append(li);
				}
			);
			holder.append(list);
			if (!rows.length) holder.append(node('p', 'No matching records.'));
		}
		/** Replace a panel's donut chart when chart data and the library are available. */
		function chart(panel, rows) {
			if (charts[panel]) {
				charts[panel].destroy();
				delete charts[panel];
			}
			app.querySelector('#alarmmanager_chart_' + panel).hidden =
				typeof window.bb === 'undefined' || !rows.length;
			if (typeof window.bb === 'undefined' || !rows.length) return;
			// Developer-owned series IDs; user labels appear only in textContent links.
			charts[panel] = window.bb.generate({
				render: { lazy: false },
				transition: { duration: 0 },
				bindto: '#alarmmanager_chart_' + panel,
				data: {
					columns: rows.map(
						/** Convert a result row to a chart series with a stable internal ID. */
						(r, i) => ['slice_' + i, Number(r.count)]
					),
					colors: Object.fromEntries(
						rows.map(
							/** Associate a stable series ID with its panel color. */
							(r, i) => ['slice_' + i, color(panel, r.label, i)]
						)
					),
					type: 'donut'
				},
				donut: { label: { show: false } },
				legend: { show: false },
				tooltip: { show: false },
				size: { height: 230 },
				padding: { right: 12 }
			});
		}
		/** Refresh current-condition, queue, and historical-event summary links. */
		function summaries(result) {
			const f = result.filters,
				current = app.querySelector('#alarmmanager_current'),
				history = app.querySelector('#alarmmanager_historical');
			current.replaceChildren(node('strong', 'Current conditions: '));
			history.replaceChildren(node('strong', 'Historical events: '));
			current.append(
				link(`Active ${result.current.conditions.active}`, 'conditions', {}, f),
				link(
					`Acknowledged ${result.current.conditions.acknowledged}`,
					'conditions',
					{ acknowledged: 1 },
					f
				)
			);
			const waiting = (result.current.queues.delivery || [])
				.filter(
					/** Select pending-delivery counts for the queue summary. */
					(row) => row.label === 'pending'
				)
				.reduce(
					/** Add a row's count to the pending-delivery total. */
					(total, row) => total + Number(row.count),
					0
				);
			current.append(
				link(
					`Queued messages ${waiting}`,
					'deliveries',
					{ status: 'pending', current: 1 },
					f
				),
				node(
					'span',
					result.notifications_paused
						? ' · Delivery paused'
						: ' · Delivery running'
				)
			);
			if (result.can_admin) {
				const control = node('a', 'Manage queue');
				control.href = 'alarmmanager_admin.php?section=queue';
				current.append(control);
			}
			const events = result.historical.events;
			[
				['All', events.events, ''],
				['Raised', events.raised, 'raised'],
				['Recovered', events.recovered, 'recovered'],
				['Occurrences', events.occurrences, 'occurrence']
			].forEach(
				/** Append a historical-event total and its transition-filtered link. */
				([title, count, transition]) =>
					history.append(
						link(
							`${title} ${Number(count).toLocaleString()}`,
							'events',
							{ transition },
							f
						)
					)
			);
		}
		/** Render dashboard summaries, charts, queues, endpoints, and worker status. */
		function render(result) {
			const f = result.filters;
			summaries(result);
			[
				['severity', 'events', 'severity'],
				['source', 'events', 'source'],
				['deliveries', 'deliveries', 'status'],
				['attempts', 'attempts', 'status'],
				['suppression', 'decisions', 'reason']
			].forEach(
				/** Refresh a panel's legend and donut chart. */
				([panel, tab, field]) => {
					legend(panel, result.panels[panel], tab, field, f);
					chart(panel, result.panels[panel]);
				}
			);
			const rows = result.panels.transitions,
				kinds = ['raised', 'recovered', 'occurrence', 'updated', 'reminder'];
			legend(
				'transitions',
				kinds.map(
					/** Build a total count for one alarm-transition kind. */
					(kind) => ({
						label: kind,
						count: rows
							.filter(
								/** Select rows belonging to the current transition kind. */
								(r) => r.label === kind
							)
							.reduce(
								/** Accumulate numeric result counts. */
								(n, r) => n + Number(r.count),
								0
							)
					})
				),
				'events',
				'transition',
				f
			);
			if (charts.transitions) {
				charts.transitions.destroy();
				delete charts.transitions;
			}
			app.querySelector('#alarmmanager_chart_transitions').hidden =
				typeof window.bb === 'undefined' || !rows.length;
			if (typeof window.bb !== 'undefined' && rows.length) {
				const buckets = [];
				for (
					let epoch =
						Math.floor((f.until - f.range) / result.bucket_seconds) *
						result.bucket_seconds;
					epoch < f.until;
					epoch += result.bucket_seconds
				)
					buckets.push(epoch);
				const columns = [
					[
						'x',
						...buckets.map(
							/** Format a UTC time bucket label for the chart's time scale. */
							(epoch) =>
								result.bucket_seconds >= 86400
									? new Date(epoch * 1000).toISOString().slice(5, 10)
									: new Date(epoch * 1000)
											.toISOString()
											.slice(8, 16)
											.replace('T', ' ')
						)
					],
					...kinds.map(
						/** Build the time-series values for one transition kind. */
						(kind) => [
							kind,
							...buckets.map(
								/** Calculate a transition's count within one time bucket. */
								(epoch) =>
									rows
										.filter(
											/** Select rows matching both the time bucket and transition kind. */
											(r) => Number(r.bucket) === epoch && r.label === kind
										)
										.reduce(
											/** Accumulate numeric counts within a time bucket. */
											(n, r) => n + Number(r.count),
											0
										)
							)
						]
					)
				];
				charts.transitions = window.bb.generate({
					render: { lazy: false },
					transition: { duration: 0 },
					bindto: '#alarmmanager_chart_transitions',
					data: {
						x: 'x',
						columns,
						type: 'bar',
						groups: [kinds],
						colors: {
							raised: '#d43f3a',
							recovered: '#329c60',
							occurrence: '#2278ad',
							updated: '#8e63aa',
							reminder: '#e67e22'
						}
					},
					axis: {
						x: {
							type: 'category',
							tick: { culling: { max: 6 }, multiline: false }
						},
						y: { min: 0, padding: { bottom: 0 } }
					},
					tooltip: { show: false },
					size: { height: 230 }
				});
			}
			const endpoints = app.querySelector('#alarmmanager_legend_endpoints');
			endpoints.replaceChildren();
			result.panels.endpoints.forEach(
				/** Append an endpoint's delivery success and latency summary link. */
				(row) =>
					endpoints.append(
						link(
							`${row.label}: ${row.successes}/${row.attempts} successful attempts; average ${row.average_ms} ms`,
							'attempts',
							{ endpoint_id: row.endpoint_id },
							f
						),
						node('br')
					)
			);
			if (!result.panels.endpoints.length)
				endpoints.append(node('p', 'No attempts in this window.'));
			const queues = app.querySelector('#alarmmanager_legend_queues');
			queues.replaceChildren();
			Object.entries(result.current.queues).forEach(
				/** Render the heading and result rows for one worker queue. */
				([queue, items]) => {
					queues.append(node('h4', queue));
					items.forEach(
						/** Append a queue-state link with its count and oldest message age. */
						(row) =>
							queues.append(
								link(
									`${row.label}: ${row.count}; oldest ${row.oldest_age_seconds}s`,
									queue === 'processing' ? 'processing' : 'deliveries',
									{ status: row.label, current: 1 },
									f
								),
								node('br')
							)
					);
				}
			);
			app.querySelector('#alarmmanager_panel_workers').hidden =
				!result.can_admin;
			const workers = app.querySelector('#alarmmanager_legend_workers');
			workers.replaceChildren();
			result.workers.forEach(
				/** Append a worker's identity, status, and heartbeat age. */
				(row) =>
					workers.append(
						node(
							'p',
							`${row.role} · poller ${row.poller_id} · PID ${row.pid} · ${row.status} · heartbeat ${row.age_seconds}s ago${row.age_seconds > 30 ? ' (stale)' : ''}`
						)
					)
			);
			if (!result.workers.length)
				workers.append(node('p', 'No recent workers.'));
			status.textContent =
				`Updated ${new Date().toLocaleTimeString()} · historical UTC window ${f.from} to ${f.to} · ${f.mode} mode` +
				(typeof window.bb === 'undefined'
					? ' · chart library unavailable; linked counts remain available.'
					: '');
		}
		/** Fetch dashboard statistics while preventing overlapping or detached-page requests. */
		async function load() {
			if (inFlight || !app.isConnected) return;
			inFlight = true;
			status.textContent = 'Loading statistics…';
			const params = new URLSearchParams(new FormData(form));
			params.set('action', 'dashboard_data');
			try {
				const response = await fetch('alarmmanager.php?' + params, {
					credentials: 'same-origin',
					cache: 'no-store'
				});
				const result = await response.json();
				if (!response.ok || result.error) throw Error();
				if (app.isConnected) render(result);
			} catch {
				status.textContent =
					'Statistics unavailable. Check permissions and refresh.';
			} finally {
				inFlight = false;
			}
		}
		form.addEventListener(
			'submit',
			/** Reload dashboard data without navigating on filter submission. */
			(event) => {
				event.preventDefault();
				void load();
			}
		);
		refresh.addEventListener(
			'change',
			/** Replace the refresh timer when its selected interval changes. */
			() => {
				if (timer) clearInterval(timer);
				const seconds = Number(refresh.value);
				if (seconds)
					timer = setInterval(
						/** Refresh connected dashboards and stop the timer after page removal. */
						() => {
							if (!app.isConnected) {
								clearInterval(timer);
								return;
							}
							void load();
						},
						seconds * 1000
					);
			}
		);
		app.querySelectorAll('.alarmmanagerCardToggle').forEach(
			/** Bind the expand/collapse control for one dashboard card. */
			(button) =>
				button.addEventListener(
					'click',
					/** Toggle a card body and resize visible charts after expansion. */
					() => {
						const body = button
							.closest('article')
							.querySelector('.alarmmanagerCardBody');
						body.hidden = !body.hidden;
						button.setAttribute('aria-expanded', String(!body.hidden));
						button.textContent = body.hidden ? 'Expand' : 'Collapse';
						if (!body.hidden) window.dispatchEvent(new Event('resize'));
					}
				)
		);
		void load();
	}
	if (document.readyState === 'loading')
		document.addEventListener('DOMContentLoaded', init, { once: true });
	else init();
})();
