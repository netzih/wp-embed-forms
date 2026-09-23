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

    var del = event.target.closest('.ef-confirm-delete');
    if (del && !window.confirm(strings.confirmDelete || 'Delete permanently?')) {
      event.preventDefault();
    }
  });
}(document));
