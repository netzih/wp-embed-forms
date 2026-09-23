/**
 * Embed Forms renderer: builds the form from window.EmbedFormsConfig,
 * applies conditional logic, walks multi-step forms, validates, and submits
 * to the REST endpoint. Inside the embed iframe it keeps the parent informed
 * of its height and asks to be scrolled into view when that helps.
 *
 * Extension points on window.EmbedForms (used by payment support):
 *   types[type] = { render(field, api) -> {el, get(), set(v), focus()},
 *                   validate(field, value) -> message|null }
 *   beforeSubmit.push(async function (payload, api) {})  may add to payload
 *   or throw an Error whose message is shown to the payer.
 *   onChange.push(function (values, api) {})
 */
(function (window, document) {
  'use strict';

  var config = window.EmbedFormsConfig;
  var root = document.getElementById('ef-root');
  if (!config || !root) {
    return;
  }

  var i18n = config.i18n || {};
  var schema = config.form.schema || { fields: [] };
  var fields = schema.fields || [];
  var LIST_TYPES = ['checkbox'];
  var OBJECT_TYPES = ['name', 'address'];
  var OPTIONAL_PARTS = ['prefix', 'middle', 'suffix', 'line2'];

  var EF = window.EmbedForms = window.EmbedForms || {};
  EF.types = EF.types || {};
  EF.beforeSubmit = EF.beforeSubmit || [];
  EF.onChange = EF.onChange || [];
  EF.ready = EF.ready || [];

  var controls = {};
  var wrappers = {};
  var pages = [];
  var currentPage = 0;
  var visible = {};
  var turnstileWidget = null;
  var turnstileToken = '';
  var submitting = false;
  var submitLabel = config.form.submitLabel || 'Submit';
  // One key per page view, sent with every attempt: a resubmit after a
  // declined card or a lost answer continues the same entry on the server.
  var submissionKey = (function () {
    var bytes = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }());

  // ---------------------------------------------------------------- helpers

  function t(key, fallback) {
    return i18n[key] || fallback || key;
  }

  function format(text) {
    var args = Array.prototype.slice.call(arguments, 1);
    var n = 0;
    return String(text).replace(/%(?:(\d+)\$)?[sd]/g, function (m, pos) {
      var value = pos ? args[parseInt(pos, 10) - 1] : args[n++];
      return value === undefined ? '' : String(value);
    });
  }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      var value = attrs[key];
      if (value === null || value === undefined || value === false) {
        return;
      }
      if (key === 'className') {
        node.className = value;
      } else if (key === 'text') {
        node.textContent = value;
      } else if (key === 'html') {
        node.innerHTML = value;
      } else if (key.indexOf('on') === 0 && typeof value === 'function') {
        node.addEventListener(key.slice(2).toLowerCase(), value);
      } else {
        node.setAttribute(key, value === true ? '' : value);
      }
    });
    (children || []).forEach(function (child) {
      if (child) {
        node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
      }
    });
    return node;
  }

  function inputId(field, part) {
    return 'ef-' + field.id + (part ? '-' + part : '');
  }

  function isEmpty(value) {
    if (value === null || value === undefined) {
      return true;
    }
    if (Array.isArray(value)) {
      return value.every(isEmpty);
    }
    if (typeof value === 'object') {
      return Object.keys(value).every(function (k) { return isEmpty(value[k]); });
    }
    return String(value).trim() === '';
  }

  function parts(field) {
    if (field.parts && field.parts.length) {
      return field.parts;
    }
    return field.type === 'name' ? ['first', 'last'] : ['line1', 'line2', 'city', 'state', 'postcode', 'country'];
  }

  function post(message) {
    if (!config.embedded || window.parent === window) {
      return;
    }
    message.source = 'embed-forms';
    message.frame = config.frameId;
    window.parent.postMessage(message, config.parentOrigin || '*');
  }

  function requestScroll() {
    if (config.embedded) {
      post({ type: 'scroll' });
    } else {
      var top = root.getBoundingClientRect().top + window.pageYOffset - 16;
      if (root.getBoundingClientRect().top < 0) {
        window.scrollTo({ top: top, behavior: 'smooth' });
      }
    }
  }

  // ------------------------------------------------------ conditional logic
  // Mirrors EmbedForms\Schema\Conditions (PHP); keep the two in step.

  function candidates(value) {
    if (value === null || value === undefined) {
      return [''];
    }
    if (Array.isArray(value)) {
      return value.length ? value.map(function (v) { return String(v).trim().toLowerCase(); }) : [''];
    }
    if (typeof value === 'object') {
      return [Object.keys(value).map(function (k) { return String(value[k] || '').trim(); }).filter(Boolean).join(' ').toLowerCase()];
    }
    return [String(value).trim().toLowerCase()];
  }

  function ruleMatches(rule, value) {
    var list = candidates(value);
    var target = String(rule.value || '').trim().toLowerCase();
    var empty = list.every(function (v) { return v === ''; });
    switch (rule.op) {
      case 'empty': return empty;
      case 'notempty': return !empty;
      case 'is': return list.indexOf(target) !== -1 || (target === '' && empty);
      case 'isnot': return !(list.indexOf(target) !== -1 || (target === '' && empty));
      case 'contains': return target !== '' && list.some(function (v) { return v.indexOf(target) !== -1; });
      case 'notcontains': return !(target !== '' && list.some(function (v) { return v.indexOf(target) !== -1; }));
      case 'gt':
      case 'lt':
        var number = list[0];
        if (number === '' || isNaN(Number(number)) || target === '' || isNaN(Number(target))) {
          return false;
        }
        return rule.op === 'gt' ? Number(number) > Number(target) : Number(number) < Number(target);
    }
    return false;
  }

  function applies(conditions, values) {
    var results = (conditions.rules || []).map(function (rule) { return ruleMatches(rule, values[rule.field]); });
    if (!results.length) {
      return true;
    }
    var matched = conditions.match === 'any' ? results.indexOf(true) !== -1 : results.indexOf(false) === -1;
    return conditions.action === 'hide' ? !matched : matched;
  }

  function visibility(values) {
    var state = {};
    fields.forEach(function (f) { state[f.id] = true; });
    for (var pass = 0; pass < 10; pass++) {
      var effective = {};
      fields.forEach(function (f) { effective[f.id] = state[f.id] ? values[f.id] : null; });
      var next = {};
      var pageShown = true;
      var sectionShown = true;
      fields.forEach(function (f) {
        var own = !f.conditions || applies(f.conditions, effective);
        if (f.type === 'page') {
          pageShown = own;
          sectionShown = true;
          next[f.id] = own;
        } else if (f.type === 'section') {
          sectionShown = own;
          next[f.id] = pageShown && own;
        } else {
          next[f.id] = pageShown && sectionShown && own;
        }
      });
      var changed = fields.some(function (f) { return next[f.id] !== state[f.id]; });
      state = next;
      if (!changed) {
        break;
      }
    }
    return state;
  }

  // ------------------------------------------------------------ field types

  function describedBy(field) {
    return [field.help ? inputId(field) + '-help' : '', inputId(field) + '-error'].filter(Boolean).join(' ');
  }

  function textControl(field, type) {
    var attrs = {
      id: inputId(field),
      name: field.id,
      type: type,
      className: 'ef-input',
      placeholder: field.placeholder || null,
      'aria-describedby': describedBy(field),
      required: field.required ? true : null,
      maxlength: field.max && (field.type === 'text' || field.type === 'textarea') ? String(field.max) : null
    };
    if (field.type === 'email') {
      attrs.autocomplete = 'email';
      attrs.inputmode = 'email';
    }
    if (field.type === 'phone') {
      attrs.autocomplete = 'tel';
      attrs.inputmode = 'tel';
    }
    if (field.type === 'number') {
      attrs.inputmode = 'decimal';
      ['min', 'max', 'step'].forEach(function (k) {
        if (field[k] !== undefined) {
          attrs[k] = String(field[k]);
        }
      });
      if (field.step === undefined) {
        attrs.step = 'any';
      }
    }
    var input = field.type === 'textarea'
      ? el('textarea', Object.assign(attrs, { type: null, rows: String(field.rows || 4) }))
      : el('input', attrs);
    return {
      el: input,
      get: function () { return input.value; },
      set: function (v) { input.value = v === null || v === undefined ? '' : String(v); },
      focus: function () { input.focus(); }
    };
  }

  function selectControl(field) {
    var select = el('select', { id: inputId(field), name: field.id, className: 'ef-input ef-select', 'aria-describedby': describedBy(field), required: field.required ? true : null }, [
      el('option', { value: '', text: field.placeholder || t('choose', 'Choose…') })
    ].concat((field.options || []).map(function (o) {
      return el('option', { value: o.value, text: o.label });
    })));
    return {
      el: select,
      get: function () { return select.value; },
      set: function (v) { select.value = v === null || v === undefined ? '' : String(v); },
      focus: function () { select.focus(); }
    };
  }

  function choiceControl(field, multiple) {
    var inputs = [];
    var list = el('div', { className: 'ef-choices', role: multiple ? 'group' : 'radiogroup', 'aria-describedby': describedBy(field) });
    (field.options || []).forEach(function (o, i) {
      var input = el('input', { type: multiple ? 'checkbox' : 'radio', name: field.id + (multiple ? '[]' : ''), value: o.value, id: inputId(field, String(i)) });
      inputs.push(input);
      list.appendChild(el('label', { className: 'ef-choice', 'for': inputId(field, String(i)) }, [input, el('span', { text: o.label })]));
    });
    return {
      el: list,
      group: true,
      get: function () {
        var chosen = inputs.filter(function (input) { return input.checked; }).map(function (input) { return input.value; });
        return multiple ? chosen : (chosen[0] || '');
      },
      set: function (v) {
        var wanted = Array.isArray(v) ? v.map(String) : (v === null || v === undefined || v === '' ? [] : [String(v)]);
        inputs.forEach(function (input) { input.checked = wanted.indexOf(input.value) !== -1; });
      },
      focus: function () { if (inputs[0]) { inputs[0].focus(); } }
    };
  }

  function partsControl(field) {
    var partNames = parts(field);
    var inputs = {};
    var autocomplete = {
      prefix: 'honorific-prefix', first: 'given-name', middle: 'additional-name', last: 'family-name', suffix: 'honorific-suffix',
      line1: 'address-line1', line2: 'address-line2', city: 'address-level2', state: 'address-level1', postcode: 'postal-code', country: 'country-name'
    };
    var grid = el('div', { className: 'ef-parts ef-parts-' + field.type });
    partNames.forEach(function (part) {
      var input = el('input', {
        type: 'text',
        id: inputId(field, part),
        name: field.id + '[' + part + ']',
        className: 'ef-input',
        autocomplete: autocomplete[part] || null,
        'aria-describedby': describedBy(field),
        required: field.required && OPTIONAL_PARTS.indexOf(part) === -1 ? true : null
      });
      inputs[part] = input;
      grid.appendChild(el('div', { className: 'ef-part ef-part-' + part }, [
        input,
        el('label', { className: 'ef-part-label', 'for': inputId(field, part), text: (i18n.parts && i18n.parts[part]) || part })
      ]));
    });
    return {
      el: grid,
      group: true,
      get: function () {
        var out = {};
        partNames.forEach(function (p) { out[p] = inputs[p].value; });
        return out;
      },
      set: function (v) {
        partNames.forEach(function (p) { inputs[p].value = v && v[p] !== undefined ? String(v[p]) : ''; });
      },
      focus: function () { inputs[partNames[0]].focus(); }
    };
  }

  function builtInControl(field) {
    switch (field.type) {
      case 'textarea': return textControl(field, 'text');
      case 'email': return textControl(field, 'email');
      case 'phone': return textControl(field, 'tel');
      case 'number': return textControl(field, 'number');
      case 'date': return textControl(field, 'date');
      case 'hidden': return textControl(field, 'hidden');
      case 'select': return selectControl(field);
      case 'radio': return choiceControl(field, false);
      case 'checkbox': return choiceControl(field, true);
      case 'name':
      case 'address': return partsControl(field);
      default: return textControl(field, 'text');
    }
  }

  function builtInError(field, value) {
    if (OBJECT_TYPES.indexOf(field.type) !== -1) {
      if (field.required) {
        var missing = parts(field).some(function (p) { return OPTIONAL_PARTS.indexOf(p) === -1 && String(value[p] || '').trim() === ''; });
        if (missing) {
          return t('requiredParts', 'Please fill in every part of this field.');
        }
      }
      return null;
    }
    if (isEmpty(value)) {
      return field.required ? t('required', 'This field is required.') : null;
    }
    var text = String(value).trim();
    switch (field.type) {
      case 'email':
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text) ? null : t('email', 'Please enter a valid email address.');
      case 'phone':
        var digits = text.replace(/\D/g, '');
        return /^[0-9+().\-\s]+(\s*(x|ext\.?)\s*\d+)?$/i.test(text) && digits.length >= 7 && digits.length <= 20 ? null : t('phone', 'Please enter a valid phone number.');
      case 'number':
        if (isNaN(Number(text))) {
          return t('number', 'Please enter a number.');
        }
        if (field.min !== undefined && Number(text) < Number(field.min)) {
          return format(t('min', 'Please enter %s or more.'), field.min);
        }
        if (field.max !== undefined && Number(text) > Number(field.max)) {
          return format(t('max', 'Please enter %s or less.'), field.max);
        }
        return null;
      case 'date':
        return /^\d{4}-\d{2}-\d{2}$/.test(text) ? null : t('date', 'Please enter a valid date.');
    }
    return null;
  }

  // ---------------------------------------------------------------- values

  function values() {
    var out = {};
    fields.forEach(function (f) {
      if (controls[f.id]) {
        out[f.id] = controls[f.id].get();
      }
    });
    return out;
  }

  function visibleValues() {
    var all = values();
    var out = {};
    Object.keys(all).forEach(function (id) {
      if (visible[id]) {
        out[id] = all[id];
      }
    });
    return out;
  }

  function prefill() {
    var params = new URLSearchParams(window.location.search);
    fields.forEach(function (f) {
      var control = controls[f.id];
      if (!control) {
        return;
      }
      if (f['default'] !== undefined && !isEmpty(f['default'])) {
        control.set(f['default']);
      }
      if (f.prefill && params.has(f.prefill)) {
        var raw = params.get(f.prefill);
        if (LIST_TYPES.indexOf(f.type) !== -1) {
          control.set(raw.split(',').map(function (s) { return s.trim(); }));
        } else if (OBJECT_TYPES.indexOf(f.type) !== -1) {
          var obj = {};
          parts(f).forEach(function (p) { obj[p] = params.get(f.prefill + '_' + p) || ''; });
          control.set(obj);
        } else {
          control.set(raw);
        }
      } else if (f.prefill && OBJECT_TYPES.indexOf(f.type) !== -1) {
        var any = false;
        var partsValue = {};
        parts(f).forEach(function (p) {
          var key = f.prefill + '_' + p;
          partsValue[p] = params.get(key) || '';
          any = any || params.has(key);
        });
        if (any) {
          control.set(partsValue);
        }
      }
    });
  }

  // --------------------------------------------------------------- layout

  function renderField(field, api) {
    if (field.type === 'page') {
      return null;
    }
    if (field.type === 'html') {
      return el('div', { className: 'ef-field ef-html', 'data-id': field.id, html: field.content || '' });
    }
    if (field.type === 'section') {
      return el('div', { className: 'ef-field ef-section', 'data-id': field.id }, [
        field.label ? el('h2', { className: 'ef-section-title', text: field.label }) : null,
        field.help ? el('p', { className: 'ef-help', text: field.help }) : null
      ]);
    }
    var custom = EF.types[field.type];
    var control = custom ? custom.render(field, api) : builtInControl(field);
    if (!control) {
      return null;
    }
    controls[field.id] = control;
    if (field.type === 'hidden') {
      return el('div', { className: 'ef-field ef-hidden', 'data-id': field.id, hidden: true }, [control.el]);
    }
    var labelText = [field.label || ''];
    var label = control.group
      ? el('legend', { className: 'ef-label' }, labelText.concat(field.required ? [el('span', { className: 'ef-required', 'aria-hidden': 'true', text: ' *' })] : []))
      : el('label', { className: 'ef-label', 'for': inputId(field) }, labelText.concat(field.required ? [el('span', { className: 'ef-required', 'aria-hidden': 'true', text: ' *' })] : []));
    var children = [
      field.label || field.required ? label : null,
      control.el,
      field.help ? el('p', { className: 'ef-help', id: inputId(field) + '-help', text: field.help }) : null,
      el('p', { className: 'ef-error', id: inputId(field) + '-error', role: 'alert', hidden: true })
    ];
    var wrapper = el(control.group ? 'fieldset' : 'div', { className: 'ef-field ef-type-' + field.type + ' ef-width-' + (field.width || 'full'), 'data-id': field.id }, children);
    wrapper.addEventListener('input', onChange);
    wrapper.addEventListener('change', onChange);
    return wrapper;
  }

  function showError(id, message) {
    var wrapper = wrappers[id];
    if (!wrapper) {
      return;
    }
    var box = wrapper.querySelector('.ef-error');
    wrapper.classList.toggle('ef-has-error', !!message);
    if (box) {
      box.textContent = message || '';
      box.hidden = !message;
    }
    Array.prototype.forEach.call(wrapper.querySelectorAll('input, select, textarea'), function (input) {
      if (message) {
        input.setAttribute('aria-invalid', 'true');
      } else {
        input.removeAttribute('aria-invalid');
      }
    });
  }

  function fieldError(field, value) {
    var custom = EF.types[field.type];
    if (custom && custom.validate) {
      return custom.validate(field, value, api);
    }
    return builtInError(field, value);
  }

  function validatePage(index) {
    var current = values();
    var firstBad = null;
    pages[index].fields.forEach(function (f) {
      if (!controls[f.id] || !visible[f.id]) {
        return;
      }
      var error = fieldError(f, current[f.id]);
      showError(f.id, error);
      if (error && !firstBad) {
        firstBad = f;
      }
    });
    if (firstBad) {
      controls[firstBad.id].focus();
    }
    return !firstBad;
  }

  function updateVisibility() {
    visible = visibility(values());
    fields.forEach(function (f) {
      if (wrappers[f.id]) {
        wrappers[f.id].hidden = !visible[f.id] || f.type === 'hidden';
      }
    });
    renderNav();
  }

  function onChange(event) {
    var wrapper = event.currentTarget;
    var id = wrapper.getAttribute('data-id');
    if (wrapper.classList.contains('ef-has-error')) {
      var field = fields.filter(function (f) { return f.id === id; })[0];
      if (field) {
        showError(id, fieldError(field, controls[id].get()));
      }
    }
    updateVisibility();
    // The "correct the highlighted fields" banner goes once nothing is highlighted.
    if (messageEl && !messageEl.hidden && messageEl.getAttribute('data-kind') === 'fix' && !formEl.querySelector('.ef-has-error')) {
      setMessage('');
    }
    var current = values();
    EF.onChange.forEach(function (fn) { fn(current, api); });
  }

  // ---------------------------------------------------------------- pages

  var formEl;
  var navEl;
  var progressEl;
  var messageEl;
  var submitButton;
  var turnstileBox;

  function visiblePages() {
    return pages.map(function (p, i) { return i; }).filter(function (i) { return !pages[i].pageField || visible[pages[i].pageField.id]; });
  }

  function goTo(index) {
    currentPage = index;
    pages.forEach(function (p, i) { p.el.hidden = i !== index; });
    renderNav();
    requestScroll();
  }

  function renderNav() {
    if (!navEl) {
      return;
    }
    var order = visiblePages();
    var position = order.indexOf(currentPage);
    if (position === -1 && order.length) {
      position = 0;
      currentPage = order[0];
      pages.forEach(function (p, i) { p.el.hidden = i !== currentPage; });
    }
    var isLast = position === order.length - 1;
    navEl.querySelector('.ef-prev').hidden = position <= 0;
    navEl.querySelector('.ef-next').hidden = isLast;
    submitButton.hidden = !isLast;
    if (turnstileBox) {
      turnstileBox.hidden = !isLast;
    }
    if (progressEl) {
      renderProgress(order, position);
    }
  }

  // One bar per visible step. When every step has a title the bars carry
  // them and the page headings move to screen readers only; otherwise a
  // "Step 1 of 3" line sits under the bars.
  function renderProgress(order, position) {
    progressEl.hidden = order.length < 2;
    var titles = order.map(function (i) { return pages[i].pageField && pages[i].pageField.label ? pages[i].pageField.label : ''; });
    var labeled = titles.every(Boolean);
    formEl.classList.toggle('ef-progress-labeled', labeled);
    var bars = el('ol', { className: 'ef-progress-bars', 'aria-hidden': 'true' }, titles.map(function (title, i) {
      return el('li', { className: i < position ? 'ef-progress-done' : (i === position ? 'ef-progress-current' : null), text: labeled ? title : null });
    }));
    var status = format(t('stepOf', 'Step %1$d of %2$d'), position + 1, order.length);
    progressEl.innerHTML = '';
    progressEl.appendChild(bars);
    progressEl.appendChild(el('p', { className: 'ef-progress-text' + (labeled ? ' ef-visually-hidden' : ''), text: labeled ? status + ': ' + titles[position] : status }));
  }

  function next() {
    if (!validatePage(currentPage)) {
      requestScroll();
      return;
    }
    var order = visiblePages();
    var position = order.indexOf(currentPage);
    if (position < order.length - 1) {
      goTo(order[position + 1]);
    }
  }

  function previous() {
    var order = visiblePages();
    var position = order.indexOf(currentPage);
    if (position > 0) {
      goTo(order[position - 1]);
    }
  }

  // --------------------------------------------------------------- submit

  function setMessage(text, kind, tag) {
    messageEl.setAttribute('data-kind', tag || '');
    messageEl.textContent = text || '';
    messageEl.className = 'ef-message' + (kind ? ' ef-message-' + kind : '');
    messageEl.hidden = !text;
  }

  function setBusy(busy) {
    submitting = busy;
    submitButton.disabled = busy;
    submitButton.textContent = busy ? t('sending', 'Sending…') : submitLabel;
    formEl.classList.toggle('ef-busy', busy);
  }

  function showServerErrors(errors) {
    var firstPage = null;
    Object.keys(errors).forEach(function (id) {
      showError(id, errors[id]);
      pages.forEach(function (p, i) {
        if (firstPage === null && p.fields.some(function (f) { return f.id === id; })) {
          firstPage = i;
        }
      });
    });
    if (firstPage !== null && firstPage !== currentPage) {
      goTo(firstPage);
    }
  }

  function confirmation(result) {
    post({ type: 'submitted', entry: result.entry || null });
    var c = result.confirmation || {};
    if (c.type === 'redirect' && c.url) {
      if (config.embedded && window.parent !== window) {
        post({ type: 'redirect', url: c.url });
      } else {
        window.location.href = c.url;
      }
      return;
    }
    var box = el('div', { className: 'ef-confirmation', role: 'status', tabindex: '-1', html: c.message || '' });
    root.innerHTML = '';
    root.appendChild(box);
    box.focus();
    requestScroll();
  }

  function submit(event) {
    event.preventDefault();
    if (submitting) {
      return;
    }
    // A step's Enter key moves on instead of submitting early.
    var order = visiblePages();
    if (order.indexOf(currentPage) < order.length - 1) {
      next();
      return;
    }
    // Every visible field on every visible page, in case an earlier answer
    // changed what later pages ask.
    for (var i = 0; i < order.length; i++) {
      if (!validatePage(order[i])) {
        if (order[i] !== currentPage) {
          goTo(order[i]);
        }
        setMessage(t('fixErrors', 'Please correct the highlighted fields.'), 'error', 'fix');
        requestScroll();
        return;
      }
    }
    setMessage('');
    setBusy(true);

    var payload = {
      token: config.token,
      values: visibleValues(),
      hp: formEl.querySelector('.ef-hp input').value,
      turnstile: turnstileToken,
      source_url: config.sourceUrl || (config.embedded ? '' : window.location.href),
      submission_key: submissionKey
    };

    EF.beforeSubmit.reduce(function (chain, fn) {
      return chain.then(function () { return fn(payload, api); });
    }, Promise.resolve()).then(function () {
      var headers = { 'Content-Type': 'application/json' };
      if (config.restNonce) {
        headers['X-WP-Nonce'] = config.restNonce;
      }
      return fetch(config.submitUrl, { method: 'POST', headers: headers, body: JSON.stringify(payload), credentials: 'same-origin' });
    }).then(function (response) {
      return response.json().catch(function () { return { ok: false, message: t('network') }; });
    }).then(function (result) {
      if (result && result.ok) {
        confirmation(result);
        return;
      }
      setBusy(false);
      if (result && result.errors) {
        showServerErrors(result.errors);
      }
      if (result && result.code === 'turnstile' && window.turnstile && turnstileWidget !== null) {
        window.turnstile.reset(turnstileWidget);
        turnstileToken = '';
      }
      EF.afterFailure && EF.afterFailure(result, api);
      setMessage((result && result.message) || t('network'), 'error');
      requestScroll();
    }).catch(function (error) {
      setBusy(false);
      setMessage(error && error.message ? error.message : t('network'), 'error');
      requestScroll();
    });
  }

  function mountTurnstile() {
    if (!config.turnstileSiteKey) {
      return;
    }
    turnstileBox = el('div', { className: 'ef-turnstile' });
    navEl.parentNode.insertBefore(turnstileBox, navEl);
    var attempts = 0;
    (function wait() {
      if (window.turnstile && window.turnstile.render) {
        turnstileWidget = window.turnstile.render(turnstileBox, {
          sitekey: config.turnstileSiteKey,
          callback: function (token) { turnstileToken = token; },
          'expired-callback': function () { turnstileToken = ''; },
          'error-callback': function () { turnstileToken = ''; }
        });
        renderNav();
      } else if (attempts++ < 100) {
        window.setTimeout(wait, 100);
      }
    }());
  }

  // ----------------------------------------------------------------- build

  var api = {
    config: config,
    fields: fields,
    values: values,
    visibleValues: visibleValues,
    isVisible: function (id) { return !!visible[id]; },
    showError: showError,
    setMessage: setMessage,
    el: el,
    t: t,
    format: format,
    inputId: inputId,
    describedBy: describedBy,
    refresh: function () { updateVisibility(); },
    submit: function () {
      if (formEl.requestSubmit) {
        formEl.requestSubmit(submitButton);
      } else {
        submitButton.click();
      }
    },
    setSubmitLabel: function (label) {
      submitLabel = label || config.form.submitLabel || 'Submit';
      if (!submitting && submitButton) {
        submitButton.textContent = submitLabel;
      }
    },
    isSubmitting: function () { return submitting; },
    requestScroll: requestScroll
  };
  EF.api = api;

  function build() {
    formEl = el('form', { className: 'ef-form', novalidate: true, onsubmit: submit });
    progressEl = el('div', { className: 'ef-progress', 'aria-live': 'polite', hidden: true });
    formEl.appendChild(progressEl);

    var page = { pageField: null, fields: [], el: el('div', { className: 'ef-step' }) };
    pages.push(page);
    fields.forEach(function (field) {
      if (field.type === 'page') {
        page = { pageField: field, fields: [], el: el('div', { className: 'ef-step', hidden: true }) };
        if (field.label) {
          page.el.appendChild(el('h2', { className: 'ef-step-title', text: field.label }));
        }
        if (field.help) {
          page.el.appendChild(el('p', { className: 'ef-help', text: field.help }));
        }
        pages.push(page);
        return;
      }
      var node = renderField(field, api);
      if (node) {
        wrappers[field.id] = node;
        page.el.appendChild(node);
      }
      page.fields.push(field);
    });
    // A page break with nothing after it adds an empty step; drop those.
    pages = pages.filter(function (p) { return p.fields.length; });
    if (!pages.length) {
      pages = [{ pageField: null, fields: [], el: el('div', { className: 'ef-step' }) }];
    }
    var grid = el('div', { className: 'ef-steps' });
    pages.forEach(function (p) { grid.appendChild(p.el); });
    formEl.appendChild(grid);

    formEl.appendChild(el('div', { className: 'ef-hp', 'aria-hidden': 'true' }, [
      el('label', { text: 'Leave this empty' }, [el('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' })])
    ]));

    messageEl = el('div', { className: 'ef-message', role: 'alert', hidden: true });
    submitButton = el('button', { type: 'submit', className: 'ef-button ef-submit', text: config.form.submitLabel || 'Submit' });
    navEl = el('div', { className: 'ef-nav' }, [
      el('button', { type: 'button', className: 'ef-button ef-button-secondary ef-prev', text: t('previous', 'Back'), onclick: previous, hidden: true }),
      el('button', { type: 'button', className: 'ef-button ef-next', text: t('next', 'Next'), onclick: next, hidden: true }),
      submitButton
    ]);
    formEl.appendChild(messageEl);
    formEl.appendChild(navEl);

    root.innerHTML = '';
    root.appendChild(formEl);
    prefill();
    currentPage = 0;
    updateVisibility();
    goToFirst();
    mountTurnstile();
    EF.ready.forEach(function (fn) { fn(api); });
    var initial = values();
    EF.onChange.forEach(function (fn) { fn(initial, api); });
  }

  function goToFirst() {
    var order = visiblePages();
    currentPage = order.length ? order[0] : 0;
    pages.forEach(function (p, i) { p.el.hidden = i !== currentPage; });
    renderNav();
  }

  var lastHeight = 0;

  function reportHeight() {
    if (!config.embedded) {
      return;
    }
    // The document is never shorter than the iframe, so measure the page
    // content itself or the frame could only ever grow.
    var page = document.querySelector('.ef-page') || document.body;
    var style = window.getComputedStyle(page);
    var height = page.getBoundingClientRect().height + parseFloat(style.marginTop || 0) + parseFloat(style.marginBottom || 0);
    if (height !== lastHeight) {
      lastHeight = height;
      post({ type: 'height', height: Math.ceil(height) });
    }
  }

  build();

  if (config.embedded) {
    document.documentElement.classList.add('ef-embedded');
    if (window.ResizeObserver) {
      new ResizeObserver(reportHeight).observe(document.body);
    } else {
      window.setInterval(reportHeight, 500);
    }
    window.addEventListener('load', reportHeight);
    reportHeight();
  }
}(window, document));
