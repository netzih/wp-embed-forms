/* Embed Forms: the manager page, on top of sheet.js. An order's
   panel opens from its number; its forms post to the page and answer with
   the panel and the row as they now are. "Add order" opens a blank one. */
(function (document) {
  'use strict';

  var config = window.EmbedFormsSheet || {};
  var strings = config.strings || {};
  var grid = window.EmbedFormsGrid || {};
  var panel = document.querySelector('.sh-panel');
  var body = panel ? panel.querySelector('.sh-panel-body') : null;
  var backdrop = document.querySelector('.sh-backdrop');
  var opener = null;
  var busy = false;

  // The form switcher.
  var switcher = document.querySelector('.sh-form-switch');
  if (switcher) {
    switcher.addEventListener('change', function () {
      window.location.href = switcher.value;
    });
  }

  if (!panel || !body) {
    return;
  }

  function say(message, isError) {
    if (grid.say && message) {
      grid.say(message, isError);
    }
  }

  function request(params, form) {
    var url = config.url;
    var options = { credentials: 'same-origin', headers: { 'X-EF-Sheet': config.csrf } };
    if (form) {
      var data = new FormData(form);
      Object.keys(params).forEach(function (key) { data.append(key, params[key]); });
      options.method = 'POST';
      options.body = data;
    } else {
      url += (url.indexOf('?') === -1 ? '?' : '&') + Object.keys(params).map(function (key) {
        return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
      }).join('&');
    }
    return fetch(url, options).then(function (response) {
      return response.json().catch(function () { return { ok: false, message: strings.offline }; });
    });
  }

  function show(html) {
    body.innerHTML = html;
    panel.hidden = false;
    backdrop.hidden = false;
    document.body.classList.add('has-panel');
    wire();
  }

  function close() {
    panel.hidden = true;
    backdrop.hidden = true;
    document.body.classList.remove('has-panel');
    body.innerHTML = '';
    if (opener && document.body.contains(opener)) {
      opener.focus();
    }
  }

  function load(params, focusPanel) {
    body.innerHTML = '<p class="sh-loading">' + (strings.loading || 'Loading…') + '</p>';
    panel.hidden = false;
    backdrop.hidden = false;
    document.body.classList.add('has-panel');
    return request(params).then(function (answer) {
      if (!answer.ok) {
        close();
        say(answer.message, true);
        return;
      }
      show(answer.panel);
      if (focusPanel) {
        var first = body.querySelector('input:not([type=hidden]), select, textarea');
        (first || panel).focus();
      } else {
        panel.focus();
      }
    }).catch(function () {
      close();
      say(strings.offline, true);
    });
  }

  function openEntry(id, trigger) {
    opener = trigger || null;
    load({ ef_action: 'panel', entry: id });
  }

  // Show the "email that it shipped" box only when Shipped is chosen, and
  // the payment details of a new order only when it was paid.
  function wire() {
    body.querySelectorAll('.sh-order-select').forEach(function (select) {
      var form = select.form;
      var toggle = function () {
        form.querySelectorAll('[data-show-when]').forEach(function (el) {
          el.hidden = el.getAttribute('data-show-when') !== select.value;
        });
      };
      select.addEventListener('change', toggle);
      toggle();
    });
    body.querySelectorAll('form[data-create]').forEach(function (form) {
      var row = form.querySelector('[data-show-when-paid]');
      if (!row) {
        return;
      }
      var toggle = function () {
        var paid = form.querySelector('input[name="paid"]:checked');
        row.hidden = !paid || paid.value === '';
      };
      form.querySelectorAll('input[name="paid"]').forEach(function (radio) { radio.addEventListener('change', toggle); });
      toggle();
    });
  }

  body.addEventListener('click', function (event) {
    if (event.target.closest('.sh-close')) {
      close();
    }
  });
  backdrop.addEventListener('click', close);
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !panel.hidden && !event.target.closest('.sh-grid')) {
      close();
    }
  });

  body.addEventListener('submit', function (event) {
    var form = event.target.closest('form');
    if (!form) {
      return;
    }
    event.preventDefault();
    if (busy) {
      return;
    }
    var confirmText = form.getAttribute('data-confirm');
    if (confirmText) {
      var amount = form.querySelector('.sh-amount');
      if (!window.confirm(confirmText.replace('%s', amount ? '$' + amount.value : ''))) {
        return;
      }
    }
    var head = body.querySelector('.sh-panel-head');
    var params = form.hasAttribute('data-create')
      ? { ef_action: 'create' }
      : { ef_action: 'act', op: form.getAttribute('data-op'), entry: head ? head.getAttribute('data-entry') : '' };
    var button = form.querySelector('button[type="submit"]');
    busy = true;
    if (button) {
      button.disabled = true;
      button.classList.add('is-busy');
    }
    var scroll = panel.scrollTop;
    request(params, form).then(function (answer) {
      busy = false;
      if (answer.row && grid.replaceRow) {
        grid.replaceRow(answer.row);
      }
      if (answer.panel) {
        show(answer.panel);
        panel.scrollTop = form.hasAttribute('data-create') && answer.ok ? 0 : scroll;
        if (form.hasAttribute('data-create') && !answer.ok) {
          var error = body.querySelector('.has-error input, .has-error select, .has-error textarea');
          if (error) {
            error.focus();
          }
        }
      } else if (button) {
        button.disabled = false;
        button.classList.remove('is-busy');
      }
      say(answer.message, !answer.ok);
    }).catch(function () {
      busy = false;
      if (button) {
        button.disabled = false;
        button.classList.remove('is-busy');
      }
      say(strings.offline, true);
    });
  });

  // Opening an order from the sheet.
  var table = document.querySelector('.sh-grid');
  if (table) {
    table.addEventListener('click', function (event) {
      var button = event.target.closest('.sh-open');
      if (button) {
        openEntry(button.closest('tr').getAttribute('data-entry'), button);
      }
    });
  }

  var add = document.querySelector('.sh-add');
  if (add) {
    add.addEventListener('click', function () {
      opener = add;
      load({ ef_action: 'new' }, true);
    });
  }
}(document));
