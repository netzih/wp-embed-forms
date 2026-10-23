/* Embed Forms: shared entries page. Find in entries, and change a white
   cell in place: Enter or a click opens it, Enter saves, Escape cancels,
   Tab saves and moves on. Each change is saved on its own. */
(function (document) {
  'use strict';

  var config = window.EmbedFormsSheet || {};
  var strings = config.strings || {};
  var table = document.querySelector('.sh-grid');
  var toast = document.querySelector('.sh-toast');
  var toastTimer = null;

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

  // Find in entries.
  var search = document.querySelector('.sh-search');
  var count = document.querySelector('.sh-count');
  if (search && table && count) {
    var total = count.getAttribute('data-total');
    var allText = count.textContent;
    search.addEventListener('input', function () {
      var query = search.value.trim().toLowerCase();
      var shown = 0;
      table.querySelectorAll('tbody tr').forEach(function (row) {
        var match = query === '' || row.textContent.toLowerCase().indexOf(query) !== -1;
        row.hidden = !match;
        if (match) {
          shown++;
        }
      });
      count.textContent = query === '' ? allText : (strings.showing || '%1$s of %2$s entries').replace('%1$s', shown).replace('%2$s', total);
    });
  }

  if (!table || !config.editable) {
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
      var blank = document.createElement('option');
      blank.value = '';
      blank.textContent = strings.choose || '(none)';
      field.appendChild(blank);
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
      var shown = field.tagName === 'SELECT' ? (value === '' ? '' : field.options[field.selectedIndex].textContent) : value;
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
