/**
 * Embed Forms builder: palette, drag-and-drop canvas and field settings.
 *
 * Runs on WordPress's own React (wp.element) and components, so there is no
 * build step. The schema lives in the editor's #ef-schema textarea, which is
 * what the Save button posts; the builder only ever rewrites that JSON.
 */
(function (wp, config) {
  'use strict';

  var root = document.getElementById('ef-builder');
  var store = document.getElementById('ef-schema');
  if (!root || !store || !wp || !wp.element || !wp.components) {
    return;
  }

  var h = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var useRef = wp.element.useRef;
  var C = wp.components;
  var __ = (wp.i18n && wp.i18n.__) || function (s) { return s; };

  var palette = config.palette || {};
  var groups = config.groups || {};
  var CHOICE = ['select', 'radio', 'checkbox'];
  var INPUT = ['text', 'textarea', 'email', 'phone', 'number', 'select', 'radio', 'checkbox', 'date', 'name', 'address', 'hidden', 'amount', 'product', 'frequency'];
  var SINGLE = ['frequency', 'payment'];
  var OPERATORS = [
    { value: 'is', label: __('is', 'embed-forms') },
    { value: 'isnot', label: __('is not', 'embed-forms') },
    { value: 'contains', label: __('contains', 'embed-forms') },
    { value: 'notcontains', label: __('does not contain', 'embed-forms') },
    { value: 'gt', label: __('is greater than', 'embed-forms') },
    { value: 'lt', label: __('is less than', 'embed-forms') },
    { value: 'empty', label: __('is empty', 'embed-forms') },
    { value: 'notempty', label: __('is not empty', 'embed-forms') }
  ];
  var FREQUENCIES = config.frequencies || { once: 'One time', week: 'Weekly', month: 'Monthly', year: 'Yearly' };
  var PARTS = {
    name: [['prefix', __('Prefix', 'embed-forms')], ['first', __('First', 'embed-forms')], ['middle', __('Middle', 'embed-forms')], ['last', __('Last', 'embed-forms')], ['suffix', __('Suffix', 'embed-forms')]],
    address: [['line1', __('Street address', 'embed-forms')], ['line2', __('Line 2', 'embed-forms')], ['city', __('City', 'embed-forms')], ['state', __('State', 'embed-forms')], ['postcode', __('ZIP', 'embed-forms')], ['country', __('Country', 'embed-forms')]]
  };

  // ------------------------------------------------------------- helpers

  function readStore() {
    try {
      var parsed = JSON.parse(store.value || '{}');
      return Array.isArray(parsed.fields) ? parsed.fields : [];
    } catch (e) {
      return [];
    }
  }

  function writeStore(fields) {
    store.value = JSON.stringify({ fields: fields }, null, 2);
  }

  function isInput(type) {
    return INPUT.indexOf(type) !== -1;
  }

  function slug(text) {
    return String(text || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40);
  }

  function uniqueId(base, fields, except) {
    base = slug(base) || 'field';
    var taken = {};
    fields.forEach(function (f) { if (f.id !== except) { taken[f.id] = true; } });
    if (!taken[base]) {
      return base;
    }
    var n = 2;
    while (taken[base + '_' + n]) {
      n++;
    }
    return base + '_' + n;
  }

  function newField(type, fields) {
    var info = palette[type] || { label: type };
    var field = { id: uniqueId(type, fields), type: type, label: info.label };
    if (isInput(type)) {
      field.required = false;
      field.width = 'full';
    }
    if (CHOICE.indexOf(type) !== -1) {
      field.options = [{ label: __('Option 1', 'embed-forms'), value: __('Option 1', 'embed-forms') }, { label: __('Option 2', 'embed-forms'), value: __('Option 2', 'embed-forms') }];
    }
    if (type === 'name') {
      field.parts = ['first', 'last'];
      field.required = true;
    }
    if (type === 'address') {
      field.parts = ['line1', 'line2', 'city', 'state', 'postcode'];
    }
    if (type === 'html') {
      field.label = '';
      field.content = '<p>' + __('Write something here.', 'embed-forms') + '</p>';
    }
    if (type === 'page') {
      field.label = '';
    }
    if (type === 'amount') {
      field.label = __('Amount', 'embed-forms');
      field.amount_mode = 'choices';
      field.amounts = [{ amount: '25.00', label: '' }, { amount: '50.00', label: '' }, { amount: '100.00', label: '' }];
      field.allow_other = true;
      field.min = '1.00';
      field.required = true;
    }
    if (type === 'product') {
      field.price = '10.00';
      field.quantity = true;
      field.max_quantity = 10;
      field['default'] = '1';
    }
    if (type === 'frequency') {
      field.label = __('How often?', 'embed-forms');
      field.frequencies = ['once', 'month'];
      field['default'] = 'once';
      field.recurring_times = 0;
      field.display = 'buttons';
    }
    if (type === 'payment') {
      field.label = __('Payment details', 'embed-forms');
      field.apple_pay = true;
    }
    if (type === 'total') {
      field.label = __('Total', 'embed-forms');
    }
    return field;
  }

  function move(list, from, to) {
    var copy = list.slice();
    var item = copy.splice(from, 1)[0];
    copy.splice(to > from ? to - 1 : to, 0, item);
    return copy;
  }

  // --------------------------------------------------------------- canvas

  function preview(field) {
    var box = function (text) { return h('div', { className: 'efb-fake-input' }, text || ''); };
    switch (field.type) {
      case 'page':
        return h('div', { className: 'efb-page-break' }, h('span', null, __('Page break', 'embed-forms') + (field.label ? ': ' + field.label : '')));
      case 'section':
        return h('div', { className: 'efb-section' }, h('strong', null, field.label || __('Section', 'embed-forms')), field.help ? h('p', null, field.help) : null);
      case 'html':
        return h('div', { className: 'efb-html', dangerouslySetInnerHTML: { __html: field.content || '' } });
      case 'textarea':
        return h('div', { className: 'efb-fake-input efb-tall' }, field.placeholder || '');
      case 'select':
        return box(field.placeholder || __('Choose…', 'embed-forms') + ' ▾');
      case 'radio':
      case 'checkbox':
        return h('div', { className: 'efb-options' }, (field.options || []).slice(0, 6).map(function (o, i) {
          return h('span', { key: i, className: 'efb-option efb-option-' + field.type }, o.label);
        }));
      case 'name':
      case 'address':
        return h('div', { className: 'efb-parts' }, (field.parts || []).map(function (p) {
          var label = (PARTS[field.type].filter(function (x) { return x[0] === p; })[0] || [p, p])[1];
          return h('div', { key: p, className: 'efb-part' }, box(), h('small', null, label));
        }));
      case 'hidden':
        return h('div', { className: 'efb-hint' }, field.prefill ? __('Filled from link parameter:', 'embed-forms') + ' ?' + field.prefill + '=' : __('Hidden value', 'embed-forms'));
      case 'amount':
        if (field.amount_mode === 'fixed') {
          return h('div', { className: 'efb-amount-fixed' }, '$' + (field.fixed_amount || '0.00'));
        }
        return h('div', { className: 'efb-options' }, (field.amount_mode === 'custom' ? [] : (field.amounts || [])).map(function (a, i) {
          return h('span', { key: i, className: 'efb-chip' }, a.label || '$' + a.amount);
        }).concat(field.allow_other || field.amount_mode === 'custom' ? [h('span', { key: 'o', className: 'efb-chip efb-chip-other' }, __('Other amount', 'embed-forms'))] : []));
      case 'product':
        return h('div', { className: 'efb-product' }, h('span', null, '$' + (field.price || '0.00')), field.quantity ? h('span', { className: 'efb-fake-input efb-qty' }, '1') : null);
      case 'frequency':
        return h('div', { className: 'efb-options' }, (field.frequencies || []).map(function (f) {
          return h('span', { key: f, className: 'efb-chip' + (field['default'] === f ? ' efb-chip-on' : '') }, FREQUENCIES[f] || f);
        }));
      case 'total':
        return h('div', { className: 'efb-total' }, '$0.00');
      case 'payment':
        return h('div', { className: 'efb-card' }, h('div', { className: 'efb-fake-input' }, '1234 1234 1234 1234     MM/YY   CVC'), field.apple_pay ? h('small', null, __('Apple Pay button shown when available (one-time payments)', 'embed-forms')) : null);
      default:
        return box(field.placeholder || '');
    }
  }

  function FieldCard(props) {
    var field = props.field;
    var info = palette[field.type] || { label: field.type, icon: 'admin-generic' };
    var classes = ['efb-card-field', 'efb-type-' + field.type, 'efb-width-' + (field.width || 'full')];
    if (props.selected) {
      classes.push('is-selected');
    }
    if (props.dropBefore) {
      classes.push('efb-drop-before');
    }
    if (props.dropAfter) {
      classes.push('efb-drop-after');
    }
    return h('div', {
      className: classes.join(' '),
      draggable: true,
      tabIndex: 0,
      role: 'button',
      'aria-pressed': props.selected ? 'true' : 'false',
      'aria-label': (field.label || info.label) + ' (' + info.label + ')',
      onClick: function () { props.onSelect(field.id); },
      onKeyDown: function (e) {
        if (e.target !== e.currentTarget) {
          return;
        }
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          props.onSelect(field.id);
        } else if (e.key === 'ArrowUp' && e.altKey) {
          e.preventDefault();
          props.onMove(-1);
        } else if (e.key === 'ArrowDown' && e.altKey) {
          e.preventDefault();
          props.onMove(1);
        } else if (e.key === 'Delete') {
          e.preventDefault();
          props.onRemove();
        }
      },
      onDragStart: function (e) {
        e.dataTransfer.setData('text/x-ef-move', field.id);
        e.dataTransfer.effectAllowed = 'move';
        props.onDragStart();
      },
      onDragEnd: props.onDragEnd,
      onDragOver: props.onDragOver,
      onDrop: props.onDrop
    },
    h('div', { className: 'efb-card-head' },
      h('span', { className: 'efb-grip dashicons dashicons-move', 'aria-hidden': 'true' }),
      h('span', { className: 'efb-card-label' }, field.label || h('em', null, info.label), field.required ? h('span', { className: 'efb-req' }, ' *') : null),
      field.conditions ? h('span', { className: 'efb-badge', title: __('Has conditional logic', 'embed-forms') }, h('span', { className: 'dashicons dashicons-randomize' })) : null,
      h('span', { className: 'efb-type' }, info.label),
      h('span', { className: 'efb-actions' },
        h(C.Button, { icon: 'arrow-up-alt2', label: __('Move up', 'embed-forms'), size: 'small', onClick: function (e) { e.stopPropagation(); props.onMove(-1); } }),
        h(C.Button, { icon: 'arrow-down-alt2', label: __('Move down', 'embed-forms'), size: 'small', onClick: function (e) { e.stopPropagation(); props.onMove(1); } }),
        SINGLE.indexOf(field.type) === -1 ? h(C.Button, { icon: 'admin-page', label: __('Duplicate', 'embed-forms'), size: 'small', onClick: function (e) { e.stopPropagation(); props.onDuplicate(); } }) : null,
        h(C.Button, { icon: 'trash', label: __('Delete', 'embed-forms'), size: 'small', isDestructive: true, onClick: function (e) { e.stopPropagation(); props.onRemove(); } })
      )
    ),
    h('div', { className: 'efb-card-body' }, preview(field))
    );
  }

  // ------------------------------------------------------------ inspector

  function Row(props) {
    return h('div', { className: 'efb-row' + (props.className ? ' ' + props.className : '') }, props.children);
  }

  function OptionsEditor(props) {
    var options = props.options || [];
    var set = function (next) { props.onChange(next); };
    return h('div', { className: 'efb-options-editor' },
      h('div', { className: 'efb-options-head' }, h('span', null, __('Label', 'embed-forms')), h('span', null, __('Stored value', 'embed-forms'))),
      options.map(function (o, i) {
        return h('div', { key: i, className: 'efb-option-row' },
          h('input', { type: 'text', value: o.label, 'aria-label': __('Option label', 'embed-forms'), onChange: function (e) {
            var next = options.slice();
            var sameAsLabel = next[i].value === next[i].label;
            next[i] = { label: e.target.value, value: sameAsLabel ? e.target.value : next[i].value };
            set(next);
          } }),
          h('input', { type: 'text', value: o.value, 'aria-label': __('Option value', 'embed-forms'), onChange: function (e) {
            var next = options.slice();
            next[i] = { label: next[i].label, value: e.target.value };
            set(next);
          } }),
          h(C.Button, { icon: 'arrow-up-alt2', size: 'small', label: __('Move up', 'embed-forms'), disabled: i === 0, onClick: function () { set(move(options, i, i - 1)); } }),
          h(C.Button, { icon: 'no-alt', size: 'small', label: __('Remove', 'embed-forms'), onClick: function () { set(options.filter(function (x, j) { return j !== i; })); } })
        );
      }),
      h(C.Button, { variant: 'secondary', size: 'small', onClick: function () {
        var label = __('Option', 'embed-forms') + ' ' + (options.length + 1);
        set(options.concat([{ label: label, value: label }]));
      } }, __('Add option', 'embed-forms')),
      h(C.Button, { variant: 'tertiary', size: 'small', onClick: function () {
        var text = window.prompt(__('One option per line:', 'embed-forms'), options.map(function (o) { return o.label; }).join('\n'));
        if (text !== null) {
          set(text.split('\n').map(function (s) { return s.trim(); }).filter(Boolean).map(function (s) { return { label: s, value: s }; }));
        }
      } }, __('Bulk edit', 'embed-forms'))
    );
  }

  function AmountsEditor(props) {
    var amounts = props.amounts || [];
    var set = props.onChange;
    return h('div', { className: 'efb-options-editor' },
      h('div', { className: 'efb-options-head' }, h('span', null, __('Amount', 'embed-forms')), h('span', null, __('Label (optional)', 'embed-forms'))),
      amounts.map(function (a, i) {
        return h('div', { key: i, className: 'efb-option-row' },
          h('input', { type: 'text', inputMode: 'decimal', value: a.amount, 'aria-label': __('Amount', 'embed-forms'), onChange: function (e) {
            var next = amounts.slice();
            next[i] = { amount: e.target.value, label: next[i].label };
            set(next);
          } }),
          h('input', { type: 'text', value: a.label || '', placeholder: '$' + a.amount, 'aria-label': __('Label', 'embed-forms'), onChange: function (e) {
            var next = amounts.slice();
            next[i] = { amount: next[i].amount, label: e.target.value };
            set(next);
          } }),
          h(C.Button, { icon: 'arrow-up-alt2', size: 'small', label: __('Move up', 'embed-forms'), disabled: i === 0, onClick: function () { set(move(amounts, i, i - 1)); } }),
          h(C.Button, { icon: 'no-alt', size: 'small', label: __('Remove', 'embed-forms'), onClick: function () { set(amounts.filter(function (x, j) { return j !== i; })); } })
        );
      }),
      h(C.Button, { variant: 'secondary', size: 'small', onClick: function () { set(amounts.concat([{ amount: '', label: '' }])); } }, __('Add amount', 'embed-forms'))
    );
  }

  function ConditionsEditor(props) {
    var field = props.field;
    var others = props.fields.filter(function (f) { return f.id !== field.id && isInput(f.type); });
    var conditions = field.conditions;
    var update = function (next) { props.onChange(next); };
    if (!conditions) {
      return h('div', null,
        h('p', { className: 'efb-muted' }, __('Show or hide this field depending on other answers.', 'embed-forms')),
        h(C.Button, { variant: 'secondary', disabled: !others.length, onClick: function () {
          update({ action: 'show', match: 'all', rules: [{ field: others[0].id, op: 'is', value: '' }] });
        } }, __('Add a condition', 'embed-forms')),
        !others.length ? h('p', { className: 'efb-muted' }, __('Add another question first.', 'embed-forms')) : null
      );
    }
    var setRule = function (i, patch) {
      var rules = conditions.rules.slice();
      rules[i] = Object.assign({}, rules[i], patch);
      update(Object.assign({}, conditions, { rules: rules }));
    };
    return h('div', { className: 'efb-conditions' },
      h('div', { className: 'efb-inline' },
        h('select', { value: conditions.action, 'aria-label': __('Show or hide', 'embed-forms'), onChange: function (e) { update(Object.assign({}, conditions, { action: e.target.value })); } },
          h('option', { value: 'show' }, __('Show', 'embed-forms')), h('option', { value: 'hide' }, __('Hide', 'embed-forms'))),
        h('span', null, field.type === 'page' ? __('this page if', 'embed-forms') : field.type === 'section' ? __('this section if', 'embed-forms') : __('this field if', 'embed-forms')),
        h('select', { value: conditions.match, 'aria-label': __('Match', 'embed-forms'), onChange: function (e) { update(Object.assign({}, conditions, { match: e.target.value })); } },
          h('option', { value: 'all' }, __('all', 'embed-forms')), h('option', { value: 'any' }, __('any', 'embed-forms'))),
        h('span', null, __('of these match:', 'embed-forms'))
      ),
      conditions.rules.map(function (rule, i) {
        var target = others.filter(function (f) { return f.id === rule.field; })[0];
        var choices = null;
        if (target && CHOICE.indexOf(target.type) !== -1) {
          choices = (target.options || []).map(function (o) { return o.value; });
        } else if (target && target.type === 'frequency') {
          choices = target.frequencies || [];
        }
        var needsValue = rule.op !== 'empty' && rule.op !== 'notempty';
        return h('div', { key: i, className: 'efb-rule' },
          h('select', { value: rule.field, 'aria-label': __('Question', 'embed-forms'), onChange: function (e) { setRule(i, { field: e.target.value, value: '' }); } },
            others.map(function (f) { return h('option', { key: f.id, value: f.id }, (f.label || f.id)); })),
          h('select', { value: rule.op, 'aria-label': __('Comparison', 'embed-forms'), onChange: function (e) { setRule(i, { op: e.target.value }); } },
            OPERATORS.map(function (o) { return h('option', { key: o.value, value: o.value }, o.label); })),
          needsValue ? (choices && (rule.op === 'is' || rule.op === 'isnot')
            ? h('select', { value: rule.value, 'aria-label': __('Value', 'embed-forms'), onChange: function (e) { setRule(i, { value: e.target.value }); } },
              [h('option', { key: '', value: '' }, '—')].concat(choices.map(function (c) { return h('option', { key: c, value: c }, target.type === 'frequency' ? (FREQUENCIES[c] || c) : c); })))
            : h('input', { type: 'text', value: rule.value, 'aria-label': __('Value', 'embed-forms'), onChange: function (e) { setRule(i, { value: e.target.value }); } })) : null,
          h(C.Button, { icon: 'no-alt', size: 'small', label: __('Remove rule', 'embed-forms'), onClick: function () {
            var rules = conditions.rules.filter(function (x, j) { return j !== i; });
            update(rules.length ? Object.assign({}, conditions, { rules: rules }) : null);
          } })
        );
      }),
      h(C.Button, { variant: 'secondary', size: 'small', onClick: function () {
        update(Object.assign({}, conditions, { rules: conditions.rules.concat([{ field: others[0].id, op: 'is', value: '' }]) }));
      } }, __('Add rule', 'embed-forms')),
      h(C.Button, { variant: 'tertiary', size: 'small', isDestructive: true, onClick: function () { update(null); } }, __('Remove conditions', 'embed-forms'))
    );
  }

  function Inspector(props) {
    var field = props.field;
    if (!field) {
      return h('div', { className: 'efb-inspector-empty' },
        h('p', null, __('Select a field to edit it, or add one from the left.', 'embed-forms')),
        h('p', { className: 'efb-muted' }, __('Tip: drag fields to reorder them. Alt + arrow keys move the selected field.', 'embed-forms')));
    }
    var set = function (patch) { props.onChange(Object.assign({}, field, patch)); };
    var info = palette[field.type] || { label: field.type };
    var input = isInput(field.type);
    var controls = [];
    var add = function (node) { controls.push(node); };

    if (field.type !== 'html' && field.type !== 'page') {
      add(h(C.TextControl, { key: 'label', label: field.type === 'section' ? __('Heading', 'embed-forms') : __('Label', 'embed-forms'), value: field.label || '', onChange: function (v) { set({ label: v }); } }));
    }
    if (field.type === 'page') {
      add(h(C.TextControl, { key: 'label', label: __('Title of the next page (optional)', 'embed-forms'), value: field.label || '', onChange: function (v) { set({ label: v }); } }));
    }
    if (field.type === 'html') {
      add(h(C.TextareaControl, { key: 'content', label: __('Content (HTML allowed)', 'embed-forms'), rows: 8, value: field.content || '', onChange: function (v) { set({ content: v }); } }));
    }
    if (input && field.type !== 'hidden') {
      add(h(C.ToggleControl, { key: 'required', label: field.type === 'product' ? __('Required (at least one)', 'embed-forms') : __('Required', 'embed-forms'), checked: !!field.required, onChange: function (v) { set({ required: v }); } }));
    }
    if (['text', 'textarea', 'email', 'phone', 'number', 'select'].indexOf(field.type) !== -1) {
      add(h(C.TextControl, { key: 'placeholder', label: __('Placeholder', 'embed-forms'), value: field.placeholder || '', onChange: function (v) { set({ placeholder: v }); } }));
    }
    if (field.type !== 'html' && field.type !== 'hidden') {
      add(h(C.TextareaControl, { key: 'help', label: __('Help text', 'embed-forms'), rows: 2, value: field.help || '', onChange: function (v) { set({ help: v }); } }));
    }
    if (CHOICE.indexOf(field.type) !== -1) {
      add(h('div', { key: 'options', className: 'efb-group' }, h('h4', null, __('Options', 'embed-forms')), h(OptionsEditor, { options: field.options, onChange: function (v) { set({ options: v }); } })));
    }
    if (field.type === 'name' || field.type === 'address') {
      add(h('div', { key: 'parts', className: 'efb-group' }, h('h4', null, __('Parts', 'embed-forms')),
        PARTS[field.type].map(function (p) {
          var on = (field.parts || []).indexOf(p[0]) !== -1;
          return h(C.CheckboxControl, { key: p[0], label: p[1], checked: on, onChange: function (v) {
            var parts = PARTS[field.type].map(function (x) { return x[0]; }).filter(function (x) { return x === p[0] ? v : (field.parts || []).indexOf(x) !== -1; });
            set({ parts: parts });
          } });
        })));
    }
    if (field.type === 'number') {
      add(h('div', { key: 'num', className: 'efb-inline-controls' },
        h(C.TextControl, { label: __('Minimum', 'embed-forms'), type: 'number', value: field.min === undefined ? '' : field.min, onChange: function (v) { set({ min: v }); } }),
        h(C.TextControl, { label: __('Maximum', 'embed-forms'), type: 'number', value: field.max === undefined ? '' : field.max, onChange: function (v) { set({ max: v }); } }),
        h(C.TextControl, { label: __('Step', 'embed-forms'), type: 'number', value: field.step === undefined ? '' : field.step, onChange: function (v) { set({ step: v }); } })));
    }
    if (field.type === 'text' || field.type === 'textarea') {
      add(h(C.TextControl, { key: 'max', label: __('Maximum length', 'embed-forms'), type: 'number', value: field.max || '', onChange: function (v) { set({ max: v }); } }));
    }
    if (field.type === 'textarea') {
      add(h(C.TextControl, { key: 'rows', label: __('Rows', 'embed-forms'), type: 'number', value: field.rows || 4, onChange: function (v) { set({ rows: v }); } }));
    }
    if (field.type === 'amount') {
      add(h(C.SelectControl, { key: 'mode', label: __('Amount', 'embed-forms'), value: field.amount_mode || 'choices', options: [
        { value: 'choices', label: __('Choose from amounts', 'embed-forms') },
        { value: 'custom', label: __('Payer enters any amount', 'embed-forms') },
        { value: 'fixed', label: __('Fixed amount', 'embed-forms') }
      ], onChange: function (v) { set({ amount_mode: v }); } }));
      if (field.amount_mode === 'fixed') {
        add(h(C.TextControl, { key: 'fixed', label: __('Fixed amount ($)', 'embed-forms'), inputMode: 'decimal', value: field.fixed_amount || '', onChange: function (v) { set({ fixed_amount: v }); } }));
      } else {
        if (field.amount_mode !== 'custom') {
          add(h('div', { key: 'amounts', className: 'efb-group' }, h('h4', null, __('Amounts', 'embed-forms')), h(AmountsEditor, { amounts: field.amounts, onChange: function (v) { set({ amounts: v }); } })));
          add(h(C.ToggleControl, { key: 'other', label: __('Allow another amount', 'embed-forms'), checked: !!field.allow_other, onChange: function (v) { set({ allow_other: v }); } }));
        }
        add(h('div', { key: 'minmax', className: 'efb-inline-controls' },
          h(C.TextControl, { label: __('Minimum ($)', 'embed-forms'), inputMode: 'decimal', value: field.min || '', onChange: function (v) { set({ min: v }); } }),
          h(C.TextControl, { label: __('Maximum ($)', 'embed-forms'), inputMode: 'decimal', value: field.max || '', onChange: function (v) { set({ max: v }); } })));
      }
    }
    if (field.type === 'product') {
      add(h(C.TextControl, { key: 'price', label: __('Price ($)', 'embed-forms'), inputMode: 'decimal', value: field.price || '', onChange: function (v) { set({ price: v }); } }));
      add(h(C.ToggleControl, { key: 'qty', label: __('Payer chooses the quantity', 'embed-forms'), checked: !!field.quantity, onChange: function (v) { set({ quantity: v }); } }));
      if (field.quantity) {
        add(h(C.TextControl, { key: 'maxqty', label: __('Maximum quantity', 'embed-forms'), type: 'number', value: field.max_quantity || 10, onChange: function (v) { set({ max_quantity: v }); } }));
      }
    }
    if (field.type === 'frequency') {
      add(h('div', { key: 'freq', className: 'efb-group' }, h('h4', null, __('Offer', 'embed-forms')),
        Object.keys(FREQUENCIES).map(function (f) {
          var on = (field.frequencies || []).indexOf(f) !== -1;
          return h(C.CheckboxControl, { key: f, label: FREQUENCIES[f], checked: on, onChange: function (v) {
            var list = Object.keys(FREQUENCIES).filter(function (x) { return x === f ? v : (field.frequencies || []).indexOf(x) !== -1; });
            var patch = { frequencies: list };
            if (list.indexOf(field['default']) === -1) {
              patch['default'] = list[0] || 'once';
            }
            set(patch);
          } });
        })));
      add(h(C.SelectControl, { key: 'fdefault', label: __('Selected by default', 'embed-forms'), value: field['default'] || 'once', options: (field.frequencies || []).map(function (f) { return { value: f, label: FREQUENCIES[f] || f }; }), onChange: function (v) { set({ 'default': v }); } }));
      add(h(C.TextControl, { key: 'times', label: __('Number of payments for recurring (0 = until cancelled)', 'embed-forms'), type: 'number', min: 0, value: field.recurring_times || 0, onChange: function (v) { set({ recurring_times: v }); } }));
      add(h(C.SelectControl, { key: 'display', label: __('Show as', 'embed-forms'), value: field.display || 'buttons', options: [{ value: 'buttons', label: __('Buttons', 'embed-forms') }, { value: 'select', label: __('Dropdown', 'embed-forms') }], onChange: function (v) { set({ display: v }); } }));
    }
    if (field.type === 'payment') {
      add(h(C.ToggleControl, { key: 'apple', label: __('Offer Apple Pay for one-time payments (USAePay only)', 'embed-forms'), checked: field.apple_pay !== false, onChange: function (v) { set({ apple_pay: v }); } }));
      add(h('p', { key: 'processor', className: 'description' }, __('Choose USAePay or Stripe, and the account, under Settings > Payments (after saving the form with this field).', 'embed-forms')));
      if (!config.paymentsAvailable) {
        add(h(C.Notice, { key: 'nopay', status: 'warning', isDismissible: false }, __('No payment processor is set up (activate USAePay Payments or add a Stripe account under Embed Forms > Settings), so this form cannot take payments yet.', 'embed-forms')));
      }
    }
    if (input && ['name', 'address', 'hidden', 'amount', 'product', 'frequency'].indexOf(field.type) === -1 || field.type === 'hidden') {
      add(h(C.TextControl, { key: 'default', label: __('Default value', 'embed-forms'), value: typeof field['default'] === 'string' ? field['default'] : '', onChange: function (v) { set({ 'default': v }); } }));
    }
    if (input && field.type !== 'product' && field.type !== 'frequency') {
      add(h(C.TextControl, { key: 'prefill', label: __('Prefill from link parameter', 'embed-forms'), help: field.prefill ? '…?' + field.prefill + '=value' : __('e.g. email, to fill from ?email=…', 'embed-forms'), value: field.prefill || '', onChange: function (v) { set({ prefill: v.replace(/[^A-Za-z0-9_-]/g, '') }); } }));
    }
    if (input && field.type !== 'hidden') {
      add(h(C.SelectControl, { key: 'width', label: __('Width', 'embed-forms'), value: field.width || 'full', options: [{ value: 'full', label: __('Full row', 'embed-forms') }, { value: 'half', label: __('Half row', 'embed-forms') }], onChange: function (v) { set({ width: v }); } }));
    }
    add(h(C.TextControl, { key: 'id', label: __('Field ID', 'embed-forms'), help: __('Used in merge tags as {field:ID} and in exports. Changing it keeps conditions pointing at this field.', 'embed-forms'), value: field.id, onChange: function (v) { props.onRename(v); } }));

    return h('div', { className: 'efb-inspector-body' },
      h('h3', { className: 'efb-inspector-title' }, h('span', { className: 'dashicons dashicons-' + (info.icon || 'admin-generic') }), ' ', info.label),
      h(C.PanelBody, { title: __('Field', 'embed-forms'), initialOpen: true }, controls),
      h(C.PanelBody, { title: __('Conditional logic', 'embed-forms'), initialOpen: !!field.conditions },
        h(ConditionsEditor, { field: field, fields: props.fields, onChange: function (c) {
          var next = Object.assign({}, field);
          if (c) {
            next.conditions = c;
          } else {
            delete next.conditions;
          }
          props.onChange(next);
        } }))
    );
  }

  // ------------------------------------------------------------------ app

  function Palette(props) {
    var types = Object.keys(palette);
    return h('div', { className: 'efb-palette' },
      Object.keys(groups).map(function (group) {
        var items = types.filter(function (t) { return palette[t].group === group; });
        if (!items.length) {
          return null;
        }
        return h('div', { key: group, className: 'efb-palette-group' },
          h('h4', null, groups[group]),
          h('div', { className: 'efb-palette-items' }, items.map(function (type) {
            var used = SINGLE.indexOf(type) !== -1 && props.fields.some(function (f) { return f.type === type; });
            return h('button', {
              key: type,
              type: 'button',
              className: 'efb-palette-item',
              disabled: used,
              title: used ? __('Already on the form', 'embed-forms') : __('Click or drag onto the form', 'embed-forms'),
              draggable: !used,
              onDragStart: function (e) { e.dataTransfer.setData('text/x-ef-new', type); e.dataTransfer.effectAllowed = 'copy'; props.onDragStart(); },
              onDragEnd: props.onDragEnd,
              onClick: function () { props.onAdd(type); }
            }, h('span', { className: 'dashicons dashicons-' + palette[type].icon, 'aria-hidden': 'true' }), h('span', null, palette[type].label));
          }))
        );
      })
    );
  }

  function Problems(props) {
    var list = [];
    var fields = props.fields;
    var hasPriced = fields.some(function (f) { return f.type === 'amount' || f.type === 'product'; });
    var paymentIndex = -1;
    fields.forEach(function (f, i) { if (f.type === 'payment') { paymentIndex = i; } });
    if (hasPriced && paymentIndex === -1) {
      list.push(__('This form has prices but no Card payment field, so nothing will be charged.', 'embed-forms'));
    }
    if (paymentIndex !== -1 && fields.slice(paymentIndex).some(function (f) { return f.type === 'page'; })) {
      list.push(__('Move the Card payment field after the last page break: the form will not save otherwise.', 'embed-forms'));
    }
    if (paymentIndex !== -1 && !hasPriced) {
      list.push(__('The Card payment field needs an Amount or Product field to charge.', 'embed-forms'));
    }
    if (paymentIndex !== -1 && !fields.some(function (f) { return f.type === 'email'; })) {
      list.push(__('Add an Email field so payers get a receipt.', 'embed-forms'));
    }
    var ids = {};
    fields.forEach(function (f) { ids[f.id] = (ids[f.id] || 0) + 1; });
    Object.keys(ids).forEach(function (id) {
      if (ids[id] > 1) {
        list.push(__('Two fields share the ID', 'embed-forms') + ' "' + id + '".');
      }
    });
    if (!list.length) {
      return null;
    }
    return h('div', { className: 'efb-problems', role: 'status' }, list.map(function (p, i) { return h('p', { key: i }, h('span', { className: 'dashicons dashicons-warning' }), ' ', p); }));
  }

  function JsonEditor(props) {
    var _s = useState(JSON.stringify({ fields: props.fields }, null, 2));
    var text = _s[0];
    var setText = _s[1];
    var _e = useState('');
    var error = _e[0];
    var setError = _e[1];
    return h('div', { className: 'efb-json' },
      h('p', { className: 'efb-muted' }, __('Advanced: the form definition as JSON. Invalid JSON is not applied.', 'embed-forms')),
      h('textarea', { className: 'large-text code', rows: 24, value: text, spellCheck: false, onChange: function (e) { setText(e.target.value); } }),
      error ? h('p', { className: 'efb-error' }, error) : null,
      h('div', { className: 'efb-inline' },
        h(C.Button, { variant: 'primary', onClick: function () {
          try {
            var parsed = JSON.parse(text);
            if (!parsed || !Array.isArray(parsed.fields)) {
              throw new Error(__('"fields" must be a list.', 'embed-forms'));
            }
            props.onApply(parsed.fields);
          } catch (e) {
            setError(e.message);
          }
        } }, __('Apply', 'embed-forms')),
        h(C.Button, { variant: 'tertiary', onClick: props.onClose }, __('Cancel', 'embed-forms')))
    );
  }

  function App() {
    var _f = useState(readStore);
    var fields = _f[0];
    var setFields = _f[1];
    var _sel = useState(null);
    var selected = _sel[0];
    var setSelected = _sel[1];
    var _drop = useState(null);
    var drop = _drop[0];
    var setDrop = _drop[1];
    var _drag = useState(false);
    var dragging = _drag[0];
    var setDragging = _drag[1];
    var _json = useState(false);
    var json = _json[0];
    var setJson = _json[1];
    var first = useRef(true);

    useEffect(function () {
      writeStore(fields);
      if (first.current) {
        first.current = false;
        return;
      }
      window.EmbedFormsBuilderDirty = true;
    }, [fields]);

    var update = function (next) { setFields(next); };
    var indexOf = function (id) { return fields.findIndex(function (f) { return f.id === id; }); };

    var insert = function (type, at) {
      var field = newField(type, fields);
      var next = fields.slice();
      var index = at === undefined || at === null ? (selected !== null && indexOf(selected) !== -1 ? indexOf(selected) + 1 : fields.length) : at;
      next.splice(index, 0, field);
      update(next);
      setSelected(field.id);
    };

    var onDropAt = function (e, index) {
      e.preventDefault();
      var type = e.dataTransfer.getData('text/x-ef-new');
      var id = e.dataTransfer.getData('text/x-ef-move');
      setDrop(null);
      setDragging(false);
      if (type) {
        insert(type, index);
      } else if (id) {
        var from = indexOf(id);
        if (from !== -1 && from !== index && from + 1 !== index) {
          update(move(fields, from, index));
        }
      }
    };

    var overCard = function (e, i) {
      e.preventDefault();
      var rect = e.currentTarget.getBoundingClientRect();
      var index = e.clientY < rect.top + rect.height / 2 ? i : i + 1;
      if (drop !== index) {
        setDrop(index);
      }
    };

    var replace = function (id, field) {
      update(fields.map(function (f) { return f.id === id ? field : f; }));
    };

    var rename = function (id, raw) {
      var wanted = slug(raw);
      if (!wanted) {
        return;
      }
      var next = uniqueId(wanted, fields, id);
      update(fields.map(function (f) {
        var copy = f.id === id ? Object.assign({}, f, { id: next }) : f;
        if (copy.conditions) {
          copy = Object.assign({}, copy, { conditions: Object.assign({}, copy.conditions, { rules: copy.conditions.rules.map(function (r) { return r.field === id ? Object.assign({}, r, { field: next }) : r; }) }) });
        }
        return copy;
      }));
      setSelected(next);
    };

    var remove = function (id) {
      update(fields.filter(function (f) { return f.id !== id; }).map(function (f) {
        if (!f.conditions) {
          return f;
        }
        var rules = f.conditions.rules.filter(function (r) { return r.field !== id; });
        var copy = Object.assign({}, f);
        if (rules.length) {
          copy.conditions = Object.assign({}, f.conditions, { rules: rules });
        } else {
          delete copy.conditions;
        }
        return copy;
      }));
      if (selected === id) {
        setSelected(null);
      }
    };

    var duplicate = function (id) {
      var i = indexOf(id);
      var copy = JSON.parse(JSON.stringify(fields[i]));
      copy.id = uniqueId(copy.id, fields);
      var next = fields.slice();
      next.splice(i + 1, 0, copy);
      update(next);
      setSelected(copy.id);
    };

    var shift = function (id, delta) {
      var i = indexOf(id);
      var j = i + delta;
      if (j < 0 || j >= fields.length) {
        return;
      }
      update(move(fields, i, delta > 0 ? j + 1 : j));
    };

    var current = fields.filter(function (f) { return f.id === selected; })[0] || null;

    return h('div', { className: 'efb' + (dragging ? ' is-dragging' : '') },
      h('aside', { className: 'efb-left' },
        h(Palette, { fields: fields, onAdd: function (t) { insert(t); }, onDragStart: function () { setDragging(true); }, onDragEnd: function () { setDragging(false); setDrop(null); } }),
        h('div', { className: 'efb-left-foot' }, h(C.Button, { variant: 'tertiary', size: 'small', icon: 'editor-code', onClick: function () { setJson(!json); } }, json ? __('Close JSON', 'embed-forms') : __('Edit as JSON', 'embed-forms')))
      ),
      h('main', { className: 'efb-canvas', onDragOver: function (e) { e.preventDefault(); }, onDrop: function (e) { onDropAt(e, drop === null ? fields.length : drop); } },
        h(Problems, { fields: fields }),
        json ? h(JsonEditor, { fields: fields, onApply: function (f) { update(f); setJson(false); setSelected(null); }, onClose: function () { setJson(false); } }) :
        h(Fragment, null,
          fields.length ? null : h('div', { className: 'efb-empty' }, __('Drag fields here, or click a field type on the left.', 'embed-forms')),
          h('div', { className: 'efb-fields' }, fields.map(function (field, i) {
            return h(FieldCard, {
              key: field.id,
              field: field,
              selected: field.id === selected,
              dropBefore: dragging && drop === i,
              dropAfter: dragging && drop === i + 1 && i === fields.length - 1,
              onSelect: setSelected,
              onMove: function (d) { shift(field.id, d); },
              onRemove: function () { remove(field.id); },
              onDuplicate: function () { duplicate(field.id); },
              onDragStart: function () { setDragging(true); setSelected(field.id); },
              onDragEnd: function () { setDragging(false); setDrop(null); },
              onDragOver: function (e) { overCard(e, i); },
              onDrop: function (e) { e.stopPropagation(); onDropAt(e, drop === null ? i : drop); }
            });
          })),
          h('div', { className: 'efb-submit-preview' }, h('span', { className: 'efb-fake-button' }, config.submitLabel || __('Submit', 'embed-forms')))
        )
      ),
      h('aside', { className: 'efb-right' },
        h(Inspector, {
          field: current,
          fields: fields,
          onChange: function (f) { replace(current.id, f); },
          onRename: function (v) { rename(current.id, v); }
        }))
    );
  }

  // Leaving with unsaved changes asks first; saving clears the flag.
  window.addEventListener('beforeunload', function (e) {
    if (window.EmbedFormsBuilderDirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });
  var editorForm = document.getElementById('ef-editor-form');
  if (editorForm) {
    editorForm.addEventListener('submit', function () { window.EmbedFormsBuilderDirty = false; });
    // Settings and title edits count as unsaved changes too.
    editorForm.addEventListener('input', function (e) {
      if (!root.contains(e.target)) {
        window.EmbedFormsBuilderDirty = true;
      }
    });
  }

  if (wp.element.createRoot) {
    wp.element.createRoot(root).render(h(App));
  } else {
    wp.element.render(h(App), root);
  }
}(window.wp, window.EmbedFormsBuilder || {}));
