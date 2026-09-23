/* global UsaepayPayJs, Stripe */
/**
 * Embed Forms payment fields: amount, product, frequency, total and the
 * card field: USAePay Pay.js hosted fields (plus Apple Pay for one-time
 * payments) or the Stripe card element, whichever processor the form uses.
 * Loaded before form.js, which renders these types through
 * window.EmbedForms.types.
 *
 * The total shown here is a courtesy: the server computes the amount it
 * charges from the answers (EmbedForms\Payments\Pricing) and never trusts
 * this one.
 */
(function (window, document) {
  'use strict';

  var config = window.EmbedFormsConfig;
  if (!config || !config.payment) {
    return;
  }
  var P = config.payment;
  var i18n = P.i18n || {};
  var fields = (config.form.schema && config.form.schema.fields) || [];

  var EF = window.EmbedForms = window.EmbedForms || {};
  EF.types = EF.types || {};
  EF.beforeSubmit = EF.beforeSubmit || [];
  EF.onChange = EF.onChange || [];
  EF.ready = EF.ready || [];

  var card = { handles: null, applePayKey: '', applePayEntry: null, field: null, error: null, applePayBox: null };
  var isStripe = P.processor === 'stripe';
  // Stripe: the card element, and whether the next submit finishes a
  // payment that stopped for the bank's check (3D Secure).
  var stripe = { client: null, element: null, resume: false };
  var totals = [];

  // ---------------------------------------------------------------- money
  // Mirrors EmbedForms\Payments\Money.

  function toCents(value) {
    if (typeof value === 'number') {
      value = String(value);
    }
    if (typeof value !== 'string') {
      return null;
    }
    value = value.replace(/[,$\s]/g, '');
    if (!/^\d{1,9}(\.\d{0,2})?$/.test(value)) {
      return null;
    }
    var parts = value.split('.');
    return parseInt(parts[0], 10) * 100 + parseInt(((parts[1] || '') + '00').slice(0, 2), 10);
  }

  function fromCents(cents) {
    return Math.floor(cents / 100) + '.' + ('0' + (cents % 100)).slice(-2);
  }

  function money(cents) {
    return '$' + (cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function format(text) {
    var args = Array.prototype.slice.call(arguments, 1);
    var n = 0;
    return String(text).replace(/%s/g, function () { return args[n++]; });
  }

  function changed(node) {
    var event;
    try {
      event = new Event('change', { bubbles: true });
    } catch (e) {
      event = document.createEvent('Event');
      event.initEvent('change', true, false);
    }
    node.dispatchEvent(event);
  }

  // Mirrors EmbedForms\Payments\Pricing::compute().
  function pricing(api) {
    var values = api.visibleValues();
    var cents = 0;
    var frequency = 'once';
    fields.forEach(function (f) {
      if (!(f.id in values)) {
        return;
      }
      var v = values[f.id];
      if (f.type === 'amount') {
        cents += toCents(String(v || '')) || 0;
      } else if (f.type === 'product') {
        cents += Math.max(0, parseInt(v, 10) || 0) * (toCents(String(f.price || '0')) || 0);
      } else if (f.type === 'frequency' && (f.frequencies || []).indexOf(v) !== -1) {
        frequency = v;
      }
    });
    return { cents: cents, total: fromCents(cents), frequency: frequency };
  }

  // ---------------------------------------------------------------- amount

  function choiceButton(api, label, pressed, onClick) {
    return api.el('button', { type: 'button', className: 'ef-chip', 'aria-pressed': pressed ? 'true' : 'false', text: label, onclick: onClick });
  }

  EF.types.amount = {
    render: function (field, api) {
      var mode = field.amount_mode || 'choices';
      var box = api.el('div', { className: 'ef-amount ef-amount-' + mode, role: mode === 'choices' ? 'group' : null });
      var selected = '';
      var buttons = [];
      var otherInput = null;
      var otherWrap = null;

      if (mode === 'fixed') {
        box.appendChild(api.el('div', { className: 'ef-amount-fixed', text: money(toCents(field.fixed_amount) || 0) }));
        return {
          el: box,
          group: true,
          get: function () { return field.fixed_amount; },
          set: function () {},
          focus: function () {}
        };
      }

      function pick(value, button) {
        selected = value;
        buttons.forEach(function (b) { b.setAttribute('aria-pressed', b === button ? 'true' : 'false'); });
        if (otherWrap) {
          otherWrap.hidden = value !== '__other';
          if (value === '__other') {
            otherInput.focus();
          }
        }
        changed(box);
      }

      if (mode === 'choices') {
        var row = api.el('div', { className: 'ef-chips' });
        (field.amounts || []).forEach(function (a) {
          var b = choiceButton(api, a.label || money(toCents(a.amount) || 0), false, function () { pick(a.amount, b); });
          b.setAttribute('data-value', a.amount);
          buttons.push(b);
          row.appendChild(b);
        });
        if (field.allow_other) {
          var other = choiceButton(api, i18n.other || 'Other', false, function () { pick('__other', other); });
          other.setAttribute('data-value', '__other');
          buttons.push(other);
          row.appendChild(other);
        }
        box.appendChild(row);
      }
      if (mode === 'custom' || field.allow_other) {
        otherInput = api.el('input', { type: 'text', inputmode: 'decimal', className: 'ef-input', id: api.inputId(field, mode === 'custom' ? null : 'other'), 'aria-label': i18n.otherAmount || 'Other amount', placeholder: '0.00', autocomplete: 'off' });
        otherWrap = api.el('div', { className: 'ef-money-input', hidden: mode !== 'custom' }, [api.el('span', { className: 'ef-money-prefix', text: '$', 'aria-hidden': 'true' }), otherInput]);
        box.appendChild(otherWrap);
      }
      return {
        el: box,
        group: mode === 'choices',
        get: function () {
          if (mode === 'custom' || selected === '__other') {
            var raw = otherInput.value.trim();
            var c = toCents(raw);
            return c === null ? raw : fromCents(c);
          }
          return selected;
        },
        set: function (v) {
          var c = toCents(String(v === null || v === undefined ? '' : v));
          var value = c === null ? '' : fromCents(c);
          if (mode === 'custom') {
            otherInput.value = value;
            return;
          }
          var match = buttons.filter(function (b) { return b.getAttribute('data-value') === value; })[0];
          if (match) {
            pick(value, match);
          } else if (value && field.allow_other) {
            otherInput.value = value;
            pick('__other', buttons[buttons.length - 1]);
          }
        },
        focus: function () { (buttons[0] || otherInput).focus(); }
      };
    },
    // Mirrors Validator::checkAmount().
    validate: function (field, value) {
      if (value === '' || value === '0.00' || value === undefined) {
        return field.required ? (i18n.chooseAmount || 'Please choose or enter an amount.') : null;
      }
      var c = toCents(String(value));
      if (c === null) {
        return i18n.amountFormat || 'Please enter an amount, like 25 or 25.50.';
      }
      if ((field.amount_mode || 'choices') === 'fixed') {
        return null;
      }
      var isChoice = (field.amounts || []).some(function (a) { return a.amount === value; });
      if (!isChoice) {
        var min = toCents(String(field.min || ''));
        var max = toCents(String(field.max || ''));
        if (min !== null && c < min) {
          return format(i18n.min || 'The minimum amount is %s.', money(min));
        }
        if (max !== null && max > 0 && c > max) {
          return format(i18n.max || 'The maximum amount is %s.', money(max));
        }
      }
      return null;
    }
  };

  // --------------------------------------------------------------- product

  EF.types.product = {
    render: function (field, api) {
      var price = api.el('span', { className: 'ef-product-price', text: money(toCents(field.price) || 0) });
      if (!field.quantity) {
        return { el: api.el('div', { className: 'ef-product' }, [price]), get: function () { return '1'; }, set: function () {}, focus: function () {} };
      }
      var input = api.el('input', { type: 'number', min: '0', max: String(field.max_quantity || 10), step: '1', inputmode: 'numeric', className: 'ef-input ef-qty', id: api.inputId(field), 'aria-describedby': api.describedBy(field) });
      input.value = field['default'] !== undefined && field['default'] !== '' ? String(field['default']) : '0';
      return {
        el: api.el('div', { className: 'ef-product' }, [price, api.el('span', { className: 'ef-times', 'aria-hidden': 'true', text: '×' }), input]),
        get: function () { return input.value; },
        set: function (v) { input.value = v === null || v === undefined ? '' : String(v); },
        focus: function () { input.focus(); }
      };
    },
    validate: function (field, value) {
      if (value !== '' && !/^\d{1,4}$/.test(String(value))) {
        return i18n.quantity || 'Please enter a quantity.';
      }
      var q = parseInt(value, 10) || 0;
      if (q > (field.max_quantity || 1)) {
        return format(i18n.max || 'The maximum is %s.', field.max_quantity);
      }
      return field.required && q < 1 ? (i18n.atLeastOne || 'Please choose at least one.') : null;
    }
  };

  // ------------------------------------------------------------- frequency

  EF.types.frequency = {
    render: function (field, api) {
      var labels = i18n.frequencies || {};
      var options = field.frequencies || ['once'];
      var value = field['default'] || options[0];
      if (field.display === 'select') {
        var select = api.el('select', { className: 'ef-input ef-select', id: api.inputId(field), 'aria-describedby': api.describedBy(field) }, options.map(function (f) {
          return api.el('option', { value: f, text: labels[f] || f });
        }));
        select.value = value;
        return { el: select, get: function () { return select.value; }, set: function (v) { if (options.indexOf(v) !== -1) { select.value = v; } }, focus: function () { select.focus(); } };
      }
      var row = api.el('div', { className: 'ef-chips', role: 'group' });
      var buttons = options.map(function (f) {
        var b = choiceButton(api, labels[f] || f, f === value, function () {
          value = f;
          buttons.forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
          changed(row);
        });
        row.appendChild(b);
        return b;
      });
      return {
        el: row,
        group: true,
        get: function () { return value; },
        set: function (v) {
          var i = options.indexOf(v);
          if (i !== -1) {
            value = v;
            buttons.forEach(function (x, j) { x.setAttribute('aria-pressed', j === i ? 'true' : 'false'); });
          }
        },
        focus: function () { buttons[0].focus(); }
      };
    },
    validate: function () { return null; }
  };

  // ----------------------------------------------------------------- total

  EF.types.total = {
    render: function (field, api) {
      var amount = api.el('div', { className: 'ef-total-amount', 'aria-live': 'polite', text: money(0) });
      totals.push(amount);
      return { el: amount, get: function () { return undefined; }, set: function () {}, focus: function () {} };
    },
    validate: function () { return null; }
  };

  // ------------------------------------------------------------------ card

  EF.types.payment = {
    render: function (field, api) {
      card.field = field;
      var box = api.el('div', { className: 'ef-payment' });
      if (P.sandbox) {
        box.appendChild(api.el('p', { className: 'ef-sandbox-note', text: i18n.sandboxNote || 'Test mode.' }));
      }
      if (P.applePay && P.applePay.enabled && field.apple_pay !== false) {
        card.applePayBox = api.el('div', { className: 'ef-apple-pay', hidden: true }, [
          api.el('div', { id: 'ef-apple-pay-button', className: 'ef-apple-pay-button' }),
          api.el('div', { className: 'ef-divider' }, [api.el('span', { text: i18n.orCard || 'or pay by card' })])
        ]);
        box.appendChild(card.applePayBox);
      }
      card.container = api.el('div', { className: 'ef-card-element', id: api.inputId(field, 'card') });
      box.appendChild(card.container);
      card.error = api.el('p', { className: 'ef-error ef-card-error', role: 'alert', hidden: true });
      box.appendChild(card.error);
      box.appendChild(api.el('p', { className: 'ef-help ef-secure', text: i18n.secureNote || '' }));
      return { el: box, group: true, get: function () { return undefined; }, set: function () {}, focus: function () { card.container.scrollIntoView({ block: 'center' }); } };
    },
    validate: function () {
      if (!P.available || !P.configured) {
        return i18n.notConfigured || 'Payments are not set up.';
      }
      return null;
    }
  };

  function cardError(text) {
    if (!card.error) {
      return;
    }
    card.error.textContent = text || '';
    card.error.hidden = !text;
  }

  function mountStripe() {
    if (!P.configured || !P.publishableKey || typeof Stripe !== 'function') {
      cardError(i18n.notConfigured || 'Payments are not set up.');
      return;
    }
    stripe.client = Stripe(P.publishableKey);
    stripe.element = stripe.client.elements().create('card', {
      // Matches .ef-input.
      style: {
        base: { fontSize: '16px', lineHeight: '46px', color: '#1d1b18', fontFamily: 'system-ui, -apple-system, "Segoe UI", sans-serif', '::placeholder': { color: '#8a857d' } },
        invalid: { color: '#b32228', iconColor: '#b32228' }
      }
    });
    card.container.classList.add('ef-card-stripe');
    stripe.element.mount(card.container);
    stripe.element.on('change', function (event) {
      cardError(event.error ? event.error.message : '');
    });
    card.handles = stripe.element;
  }

  // Name and email for Stripe's billing details, from the first name and
  // email fields answered.
  function billing(api) {
    var values = api.visibleValues();
    var out = {};
    fields.forEach(function (f) {
      var v = values[f.id];
      if (!v) {
        return;
      }
      if (f.type === 'email' && !out.email) {
        out.email = String(v);
      } else if (f.type === 'name' && !out.name && typeof v === 'object') {
        out.name = [v.first, v.last].filter(Boolean).join(' ') || undefined;
      } else if (f.type === 'phone' && !out.phone) {
        out.phone = String(v);
      }
    });
    return out;
  }

  function mountCard(api) {
    if (!card.field || !card.container) {
      return;
    }
    if (isStripe) {
      mountStripe();
      return;
    }
    if (!P.available || !P.configured || !P.publicKey || !window.UsaepayPayJs) {
      cardError(i18n.notConfigured || 'Payments are not set up.');
      return;
    }
    UsaepayPayJs.mount({
      publicKey: P.publicKey,
      payJsUrl: P.payJsUrl,
      container: card.container,
      // Matches .ef-input: 48px box less its border.
      styles: {
        base: { 'font-size': '16px', 'height': '46px', 'line-height': '46px', 'color': '#1d1b18', 'background': 'transparent' },
        valid: { 'color': '#1d1b18' },
        invalid: { 'color': '#b32228' }
      },
      onFieldError: function (text) { cardError(text); }
    }).then(function (handles) {
      card.handles = handles;
      if (card.applePayBox) {
        UsaepayPayJs.applePay({
          client: handles.client,
          targetDiv: 'ef-apple-pay-button',
          displayName: P.applePay.displayName,
          countryCode: P.applePay.countryCode || 'US',
          currencyCode: P.currency || 'USD',
          buttonType: 'plain',
          getAmount: function () {
            var p = pricing(api);
            return p.frequency === 'once' ? p.total : '0.00';
          },
          onKey: function (key) {
            cardError('');
            card.applePayKey = key;
            api.submit();
          },
          onError: function (text) { cardError(text); },
          onCancel: function () { cardError(''); }
        }).then(function (entry) {
          card.applePayEntry = entry;
          update(api);
        });
      }
    }).catch(function (error) {
      cardError(UsaepayPayJs.errorText(error) || i18n.notConfigured);
    });
  }

  // ------------------------------------------------------------- behaviour

  function update(api) {
    var p = pricing(api);
    var suffix = p.frequency !== 'once' && i18n.per && i18n.per[p.frequency] ? ' ' + i18n.per[p.frequency] : '';
    totals.forEach(function (node) { node.textContent = money(p.cents) + suffix; });
    var paying = card.field && api.isVisible(card.field.id) && p.cents > 0;
    api.setSubmitLabel(paying ? format(i18n.payNow || 'Pay %s', money(p.cents) + suffix) : null);
    if (card.applePayBox) {
      // Apple Pay keys return no saved card, so it is one-time only.
      card.applePayBox.hidden = !(card.applePayEntry && paying && p.frequency === 'once');
    }
  }

  EF.ready.push(function (api) {
    mountCard(api);
    update(api);
  });

  EF.onChange.push(function (values, api) {
    update(api);
  });

  EF.beforeSubmit.push(function (payload, api) {
    if (!card.field || !api.isVisible(card.field.id)) {
      return null;
    }
    var p = pricing(api);
    if (p.cents <= 0) {
      return null;
    }
    cardError('');
    if (isStripe) {
      payload.payment_method = 'card';
      if (stripe.resume) {
        // The server finishes the payment it already started.
        stripe.resume = false;
        return null;
      }
      if (!stripe.client) {
        throw new Error(i18n.notConfigured || 'Payments are not set up.');
      }
      return stripe.client.createPaymentMethod({ type: 'card', card: stripe.element, billing_details: billing(api) }).then(function (result) {
        if (result.error) {
          cardError(result.error.message);
          throw new Error(result.error.message || i18n.checkCard);
        }
        payload.payment_key = result.paymentMethod.id;
      });
    }
    if (card.applePayKey && p.frequency === 'once') {
      payload.payment_key = card.applePayKey;
      payload.payment_method = 'applepay';
      card.applePayKey = '';
      return null;
    }
    card.applePayKey = '';
    if (!card.handles) {
      throw new Error(i18n.notConfigured || 'Payments are not set up.');
    }
    payload.payment_method = 'card';
    return UsaepayPayJs.tokenize(card.handles).then(function (key) {
      payload.payment_key = key;
    }, function (error) {
      cardError(error.message);
      throw error;
    });
  });

  EF.afterFailure = function (result, api) {
    // Keys are single use: the next attempt mints a new one.
    card.applePayKey = '';
    if (isStripe && stripe.client && result && result.code === 'payment_action' && result.action && result.action.client_secret) {
      // Show the bank's check, then submit again whatever its outcome: the
      // server reads the payment's state and approves or reports the decline.
      stripe.client.handleNextAction({ clientSecret: result.action.client_secret }).then(function () {
        stripe.resume = true;
        api.submit();
      });
    }
  };
}(window, document));
