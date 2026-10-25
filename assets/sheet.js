/* Embed Forms: the shared entries page and the store manager page. Find,
   filter and sort entries, with totals for what is shown; change a white
   cell in place: Enter or a click opens it, Enter saves, Escape cancels,
   Tab saves and moves on. Each change is saved on its own. */
(function (document) {
  'use strict';

  var config = window.EmbedFormsSheet || {};
  var strings = config.strings || {};
  var table = document.querySelector('.sh-grid');
  var toast = document.querySelector('.sh-toast');
  var toastTimer = null;
  var api = window.EmbedFormsGrid = {};

  function say(message, isError) {
    if (!toast) {
      return;
    }
    toast.textContent = message;
    toast.classList.toggle('is-error', !!isError);
    toast.hidden = false;
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(function () { toast.hidden = true; }, isError ? 7000 : 2500);
  }
  api.say = say;

  if (!table) {
    return;
  }
  var tbody = table.tBodies[0];

  function money(cents) {
    return '$' + (cents / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Find, filter and totals.
  var search = document.querySelector('.sh-search');
  var filters = Array.prototype.slice.call(document.querySelectorAll('.sh-filter'));
  var totals = document.querySelector('.sh-totals');
  var none = document.querySelector('.sh-none');

  function matches(row, query) {
    if (query !== '' && row.textContent.toLowerCase().indexOf(query) === -1) {
      return false;
    }
    return filters.every(function (select) {
      var want = select.value;
      if (want === '') {
        return true;
      }
      var have = row.getAttribute('data-' + select.getAttribute('data-attr')) || '';
      if (select.getAttribute('data-attr') === 'order') {
        if (want === 'open') {
          return have === '' || have === 'ready';
        }
        return want === 'new' ? have === '' : have === want;
      }
      return have === want;
    });
  }

  function refresh() {
    var query = search ? search.value.trim().toLowerCase() : '';
    var rows = Array.prototype.slice.call(tbody.rows);
    var shown = 0;
    var cancelled = 0;
    var net = 0;
    var due = 0;
    var credit = 0;
    var counts = {};
    rows.forEach(function (row) {
      var match = matches(row, query);
      row.hidden = !match;
      if (!match) {
        return;
      }
      shown++;
      net += parseInt(row.getAttribute('data-net'), 10) || 0;
      due += parseInt(row.getAttribute('data-due'), 10) || 0;
      credit += parseInt(row.getAttribute('data-credit'), 10) || 0;
      if (row.getAttribute('data-order') === 'cancelled') {
        cancelled++;
        return;
      }
      var q = {};
      try { q = JSON.parse(row.getAttribute('data-q') || '{}'); } catch (e) { q = {}; }
      Object.keys(q).forEach(function (id) {
        counts[id] = (counts[id] || 0) + (parseInt(q[id], 10) || 0);
      });
    });
    if (none) {
      none.hidden = shown > 0 || rows.length === 0;
    }
    if (!totals) {
      return;
    }
    var parts = [];
    var noun = config.store ? strings.orders : strings.entries;
    parts.push('<strong>' + (shown === rows.length ? shown : (strings.showing || '%1$s of %2$s').replace('%1$s', shown).replace('%2$s', rows.length)) + '</strong> ' + noun);
    (config.products || []).forEach(function (product) {
      if (counts[product.id]) {
        parts.push('<strong>' + counts[product.id] + '</strong> ' + escapeHtml(product.label));
      }
    });
    if (net !== 0) {
      parts.push('<strong>' + money(net) + '</strong> ' + strings.received);
    }
    if (due > 0) {
      parts.push('<strong class="sh-warn">' + money(due) + '</strong> ' + strings.due);
    }
    if (credit > 0) {
      parts.push('<strong class="sh-warn">' + money(credit) + '</strong> ' + strings.credit);
    }
    if (cancelled > 0) {
      parts.push(cancelled + ' ' + strings.cancelled);
    }
    totals.innerHTML = parts.map(function (p) { return '<span>' + p + '</span>'; }).join('');
  }
  api.refresh = refresh;

  if (search) {
    search.addEventListener('input', refresh);
  }
  filters.forEach(function (select) {
    select.addEventListener('change', refresh);
  });

  // Sorting: a heading sorts by its column; again reverses.
  var sortState = { col: -1, dir: 1 };
  function sortValue(row, col, numeric) {
    var cell = row.cells[col];
    var text = cell ? cell.textContent.trim() : '';
    if (numeric) {
      var n = parseFloat(text.replace(/[^0-9.\-]/g, ''));
      return isNaN(n) ? null : n;
    }
    return text === '' ? null : text.toLowerCase();
  }
  function sortRows() {
    if (sortState.col < 0) {
      return;
    }
    var button = table.querySelector('.sh-sort[data-col="' + sortState.col + '"]');
    var numeric = button && button.hasAttribute('data-numeric');
    var rows = Array.prototype.slice.call(tbody.rows);
    rows.sort(function (a, b) {
      var x = sortValue(a, sortState.col, numeric);
      var y = sortValue(b, sortState.col, numeric);
      if (x === y) {
        return 0;
      }
      // Empty cells last either way.
      if (x === null) {
        return 1;
      }
      if (y === null) {
        return -1;
      }
      return (x < y ? -1 : 1) * sortState.dir;
    });
    rows.forEach(function (row) { tbody.appendChild(row); });
  }
  table.querySelectorAll('.sh-sort').forEach(function (button) {
    button.addEventListener('click', function () {
      var col = parseInt(button.getAttribute('data-col'), 10);
      sortState.dir = sortState.col === col ? -sortState.dir : (button.hasAttribute('data-numeric') ? -1 : 1);
      sortState.col = col;
      table.querySelectorAll('th').forEach(function (th) { th.setAttribute('aria-sort', 'none'); });
      button.parentNode.setAttribute('aria-sort', sortState.dir === 1 ? 'ascending' : 'descending');
      sortRows();
    });
  });

  /**
   * Put a row as the server now renders it in place of the shown one (or
   * at the top, for a new entry).
   */
  api.replaceRow = function (html) {
    var holder = document.createElement('tbody');
    holder.innerHTML = html;
    var fresh = holder.rows[0];
    if (!fresh) {
      return null;
    }
    var old = tbody.querySelector('tr[data-entry="' + fresh.getAttribute('data-entry') + '"]');
    if (old) {
      tbody.replaceChild(fresh, old);
    }
    else {
      tbody.insertBefore(fresh, tbody.firstChild);
    }
    refresh();
    return fresh;
  };

  refresh();

  if (!config.editable) {
    return;
  }

  function columnOf(cell) {
    return config.columns[parseInt(cell.getAttribute('data-col'), 10)] || {};
  }

  function editableCells(row) {
    return Array.prototype.slice.call(row.querySelectorAll('td.is-edit'));
  }

  function open(cell) {
    if (cell.classList.contains('is-editing') || cell.classList.contains('is-saving')) {
      return;
    }
    var column = columnOf(cell);
    var before = cell.textContent;
    var field;
    if (column.edit === 'select') {
      field = document.createElement('select');
      // An order always has a status; an answer may be left empty.
      if (column.key !== 'order') {
        var blank = document.createElement('option');
        blank.value = '';
        blank.textContent = strings.choose || '(none)';
        field.appendChild(blank);
      }
      (column.options || []).forEach(function (option) {
        var el = document.createElement('option');
        el.value = option[0];
        el.textContent = option[1];
        field.appendChild(el);
      });
      field.value = cell.getAttribute('data-value') || '';
    } else if (column.edit === 'textarea') {
      field = document.createElement('textarea');
      field.value = before;
    } else {
      field = document.createElement('input');
      field.type = ['email', 'number', 'date'].indexOf(column.edit) !== -1 ? column.edit : 'text';
      field.value = before;
    }
    field.setAttribute('aria-label', column.label || '');
    cell.classList.remove('is-error');
    cell.classList.add('is-editing');
    cell.textContent = '';
    cell.appendChild(field);
    field.focus();
    if (field.select && field.tagName !== 'SELECT') {
      field.select();
    }

    var done = false;
    function finish(save, moveBy) {
      if (done) {
        return;
      }
      done = true;
      var value = field.value;
      var shown = field.tagName === 'SELECT' ? (field.selectedIndex < 0 || (value === '' && column.key !== 'order') ? '' : field.options[field.selectedIndex].textContent) : value;
      cell.classList.remove('is-editing');
      cell.textContent = before;
      var unchanged = field.tagName === 'SELECT' ? value === (cell.getAttribute('data-value') || '') : value === before;
      if (save && !unchanged) {
        cell.textContent = shown;
        commit(cell, column, before, value);
      }
      if (moveBy) {
        var cells = editableCells(cell.parentNode);
        var next = cells[cells.indexOf(cell) + moveBy];
        if (next) {
          next.focus();
          open(next);
          return;
        }
      }
      cell.focus();
    }

    field.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        finish(false);
      } else if (event.key === 'Enter' && (field.tagName !== 'TEXTAREA' || event.ctrlKey || event.metaKey)) {
        event.preventDefault();
        finish(true);
      } else if (event.key === 'Tab') {
        event.preventDefault();
        finish(true, event.shiftKey ? -1 : 1);
      }
    });
    field.addEventListener('blur', function () {
      finish(true);
    });
  }

  function commit(cell, column, before, value) {
    var row = cell.parentNode;
    var col = cell.getAttribute('data-col');
    var body = new FormData();
    body.append('ef_action', 'save');
    body.append('entry', row.getAttribute('data-entry'));
    body.append('column', column.key);
    body.append('before', before);
    body.append('value', value);
    cell.classList.add('is-saving');
    fetch(config.url, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'X-EF-Sheet': config.csrf }
    }).then(function (response) {
      return response.json().catch(function () { return { ok: false }; });
    }).then(function (answer) {
      cell.classList.remove('is-saving');
      if (answer.ok && answer.row) {
        // The whole row as it now is: status, balance and totals follow.
        var fresh = api.replaceRow(answer.row);
        var target = fresh ? fresh.querySelector('td[data-col="' + col + '"]') : null;
        if (target) {
          target.classList.add('is-saved');
          if (document.activeElement === cell || document.activeElement === document.body) {
            target.focus();
          }
        }
        say(answer.message || strings.saved || 'Saved');
        return;
      }
      if (typeof answer.text === 'string') {
        cell.textContent = answer.text;
        cell.title = answer.text;
        if (column.edit === 'select') {
          cell.setAttribute('data-value', answer.value || '');
        }
      } else if (!answer.ok) {
        cell.textContent = before;
      }
      if (answer.ok) {
        cell.classList.remove('is-saved');
        void cell.offsetWidth;
        cell.classList.add('is-saved');
        say(strings.saved || 'Saved');
      } else {
        cell.classList.add('is-error');
        say((strings.notSaved || 'Not saved: %s').replace('%s', answer.message || ''), true);
      }
    }).catch(function () {
      cell.classList.remove('is-saving');
      cell.classList.add('is-error');
      cell.textContent = before;
      say(strings.offline || 'Not saved.', true);
    });
  }

  table.addEventListener('click', function (event) {
    var cell = event.target.closest('td.is-edit');
    if (cell && !event.target.closest('input, select, textarea')) {
      open(cell);
    }
  });
  table.addEventListener('keydown', function (event) {
    var cell = event.target.closest('td.is-edit');
    if (!cell || cell.classList.contains('is-editing')) {
      return;
    }
    if (event.key === 'Enter' || event.key === 'F2') {
      event.preventDefault();
      open(cell);
    }
  });
}(document));
