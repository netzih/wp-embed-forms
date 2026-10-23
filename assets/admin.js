/* Embed Forms admin: editor tabs, copy buttons, delete confirmation. */
(function (document) {
  'use strict';

  var strings = window.EmbedFormsAdmin || {};

  document.addEventListener('click', function (event) {
    var tab = event.target.closest('.ef-tabs [data-tab]');
    if (tab) {
      event.preventDefault();
      var name = tab.getAttribute('data-tab');
      document.querySelectorAll('.ef-tabs [data-tab]').forEach(function (t) {
        t.classList.toggle('nav-tab-active', t === tab);
      });
      document.querySelectorAll('.ef-tab-panel').forEach(function (panel) {
        panel.hidden = panel.getAttribute('data-panel') !== name;
      });
      var input = document.getElementById('ef-current-tab');
      if (input) {
        input.value = name;
      }
      if (window.history && window.history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.set('tab', name);
        window.history.replaceState(null, '', url.toString());
      }
      return;
    }

    var copy = event.target.closest('.ef-copy');
    if (copy) {
      var area = copy.parentNode.querySelector('textarea');
      var done = function () {
        var label = copy.textContent;
        copy.textContent = strings.copied || 'Copied';
        window.setTimeout(function () { copy.textContent = label; }, 1500);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(area.value).then(done);
      } else {
        area.select();
        document.execCommand('copy');
        done();
      }
      return;
    }

    var refund = event.target.closest('.ef-confirm-refund');
    if (refund && !window.confirm(strings.confirmRefund || 'Refund now?')) {
      event.preventDefault();
      return;
    }

    var del = event.target.closest('.ef-confirm-delete');
    if (del && !window.confirm(strings.confirmDelete || 'Delete permanently?')) {
      event.preventDefault();
    }
  });

  // Settings sections (Admin\Sections): a list on the left with each
  // section's state, one section at a time on the right.
  function sectionState(section) {
    var toggle = section.getAttribute('data-toggle');
    if (toggle) {
      var box = section.querySelector('input[type="checkbox"][name="' + toggle + '"]');
      if (box) {
        return box.checked ? (strings.on || 'On') : (strings.off || 'Off');
      }
    }
    return section.getAttribute('data-state') || '';
  }

  function showSection(root, key, remember) {
    root.querySelectorAll('.ef-section').forEach(function (section) {
      section.classList.toggle('is-active', section.getAttribute('data-section') === key);
    });
    root.querySelectorAll('.ef-section-nav button').forEach(function (button) {
      var active = button.getAttribute('data-section') === key;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-current', active ? 'true' : 'false');
    });
    var input = root.querySelector('.ef-section-input');
    if (input) {
      input.value = key;
    }
    if (!remember) {
      return;
    }
    var url = new URL(window.location.href);
    url.searchParams.set('section', key);
    if (window.history && window.history.replaceState) {
      window.history.replaceState(null, '', url.toString());
    }
    // options.php sends the browser back to this address after saving.
    var referer = document.querySelector('input[name="_wp_http_referer"]');
    if (referer) {
      var back = new URL(referer.value, window.location.origin);
      back.searchParams.set('section', key);
      referer.value = back.pathname + back.search;
    }
  }

  function buildSections(root) {
    var nav = root.querySelector('.ef-section-nav');
    var sections = root.querySelectorAll('.ef-section');
    if (!nav || !sections.length) {
      return;
    }
    var list = document.createElement('ul');
    sections.forEach(function (section) {
      var item = document.createElement('li');
      var button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('data-section', section.getAttribute('data-section'));
      var name = document.createElement('span');
      name.className = 'ef-section-name';
      name.textContent = section.getAttribute('data-label');
      var state = document.createElement('span');
      state.className = 'ef-section-state';
      state.textContent = sectionState(section);
      button.appendChild(name);
      button.appendChild(state);
      button.addEventListener('click', function () {
        showSection(root, section.getAttribute('data-section'), true);
      });
      var tone = function () {
        var on = section.getAttribute('data-toggle') ? state.textContent === (strings.on || 'On') : section.getAttribute('data-tone') === 'good';
        button.classList.toggle('is-good', on);
        button.classList.toggle('is-bad', section.getAttribute('data-tone') === 'bad');
      };
      section.addEventListener('change', function () {
        state.textContent = sectionState(section);
        tone();
      });
      tone();
      item.appendChild(button);
      list.appendChild(item);
    });
    nav.appendChild(list);
    var wanted = root.getAttribute('data-current');
    var first = sections[0].getAttribute('data-section');
    var exists = wanted && root.querySelector('.ef-section[data-section="' + wanted + '"]');
    showSection(root, exists ? wanted : first, false);
  }

  // data-ef-show-if="NAME" (a checkbox is ticked) or "NAME=VALUE" (a radio
  // has that value).
  function applyShowIf(scope) {
    scope.querySelectorAll('[data-ef-show-if]').forEach(function (el) {
      var rule = el.getAttribute('data-ef-show-if');
      var eq = rule.indexOf('=');
      var name = eq < 0 ? rule : rule.slice(0, eq);
      var show;
      if (eq < 0) {
        var box = document.querySelector('input[type="checkbox"][name="' + name + '"]');
        show = !box || box.checked;
      } else {
        var picked = document.querySelector('input[type="radio"][name="' + name + '"]:checked');
        show = !!picked && picked.value === rule.slice(eq + 1);
      }
      el.hidden = !show;
    });
  }

  function ready() {
    document.querySelectorAll('.ef-sections').forEach(buildSections);
    applyShowIf(document);
    document.addEventListener('change', function (event) {
      applyShowIf(document);
      // A key file chosen on the settings page fills its text box.
      var picker = event.target.closest && event.target.closest('.ef-key-file');
      if (picker && picker.files && picker.files[0]) {
        var target = document.getElementById(picker.getAttribute('data-target'));
        picker.files[0].text().then(function (text) {
          if (target) {
            target.value = text;
          }
        });
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  } else {
    ready();
  }
}(document));
