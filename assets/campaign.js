/**
 * Embed Forms campaign loader: a form's running total and donor list on a
 * campaign page, on this site or any other.
 *
 *   <div data-ef-campaign="FORM-ID">
 *     <strong data-ef-raised></strong> of <span data-ef-goal></span>
 *     <span data-ef-donors></span> donors · <span data-ef-percent></span>
 *     <div data-ef-progress></div>          (its width becomes the percent)
 *     <ul data-ef-donor-list></ul>          (optional simple list)
 *   </div>
 *   <script src="https://example.org/wp-content/plugins/embed-forms/assets/campaign.js" defer></script>
 *
 * The form's campaign must be switched on (form Settings > Campaign). The
 * data is fetched now, every 30 seconds while the page is visible
 * (data-ef-refresh="seconds" to change it), and right after a donation in
 * an embedded form. Each fetch fires "embedforms:campaign" on the element
 * (event.detail: raised, goal, percent, donors, recent); each donor that
 * appears after the first fetch fires "embedforms:donation" (event.detail:
 * id, name, anonymous, amount, frequency, city, at), so a page can draw its
 * own donor wall and "just gave" notices.
 *
 * Optional: data-ef-api on the element (the REST address of the campaign,
 * default: this script's site + /wp-json/embed-forms/v1/forms/ID/campaign).
 */
(function (window, document) {
  'use strict';

  var script = document.currentScript || (function () {
    var scripts = document.getElementsByTagName('script');
    for (var i = scripts.length - 1; i >= 0; i--) {
      if (/\/embed-forms\/assets\/campaign\.js/.test(scripts[i].src)) {
        return scripts[i];
      }
    }
    return null;
  }());
  var site = script && script.src ? new URL(script.src, window.location.href).href.replace(/\/wp-content\/plugins\/.*$/, '') : '';

  var campaigns = [];

  function money(amount, cents) {
    var n = Number(amount) || 0;
    var whole = Math.round(n * 100) % 100 === 0;
    return '$' + n.toLocaleString('en-US', { minimumFractionDigits: whole || !cents ? 0 : 2, maximumFractionDigits: whole || !cents ? 0 : 2 });
  }

  function fill(root, selector, text) {
    Array.prototype.forEach.call(root.querySelectorAll(selector), function (node) {
      node.textContent = text;
    });
  }

  function emit(root, name, detail) {
    var event;
    try {
      event = new CustomEvent(name, { detail: detail, bubbles: true });
    } catch (e) {
      event = document.createEvent('CustomEvent');
      event.initCustomEvent(name, true, false, detail);
    }
    root.dispatchEvent(event);
  }

  function renderList(list, donors) {
    var max = Number(list.getAttribute('data-ef-max')) || 20;
    list.textContent = '';
    donors.slice(0, max).forEach(function (d) {
      var item = document.createElement('li');
      item.className = 'ef-donor';
      var name = document.createElement('span');
      name.className = 'ef-donor-name';
      name.textContent = d.name;
      item.appendChild(name);
      if (d.amount) {
        var amount = document.createElement('span');
        amount.className = 'ef-donor-amount';
        amount.textContent = ' ' + money(d.amount) + (d.frequency === 'month' ? '/mo' : d.frequency === 'year' ? '/yr' : d.frequency === 'week' ? '/wk' : '');
        item.appendChild(amount);
      }
      if (d.city) {
        var city = document.createElement('span');
        city.className = 'ef-donor-city';
        city.textContent = ' · ' + d.city;
        item.appendChild(city);
      }
      list.appendChild(item);
    });
  }

  function apply(c, data) {
    var root = c.root;
    fill(root, '[data-ef-raised]', money(data.raised));
    fill(root, '[data-ef-goal]', data.goal ? money(data.goal) : '');
    fill(root, '[data-ef-donors]', Number(data.donors || 0).toLocaleString('en-US'));
    fill(root, '[data-ef-percent]', data.percent === null || data.percent === undefined ? '' : Math.round(data.percent) + '%');
    Array.prototype.forEach.call(root.querySelectorAll('[data-ef-progress]'), function (bar) {
      bar.style.width = (data.percent || 0) + '%';
      bar.setAttribute('role', 'progressbar');
      bar.setAttribute('aria-valuemin', '0');
      bar.setAttribute('aria-valuemax', '100');
      bar.setAttribute('aria-valuenow', String(Math.round(data.percent || 0)));
    });
    Array.prototype.forEach.call(root.querySelectorAll('[data-ef-donor-list]'), function (list) {
      renderList(list, data.recent || []);
    });
    root.setAttribute('data-ef-loaded', '');

    var fresh = [];
    (data.recent || []).forEach(function (d) {
      if (!c.seen[d.id]) {
        c.seen[d.id] = true;
        fresh.push(d);
      }
    });
    emit(root, 'embedforms:campaign', data);
    if (c.loaded) {
      // Oldest first, so a page showing them one by one keeps the order.
      fresh.reverse().forEach(function (d) { emit(root, 'embedforms:donation', d); });
    }
    c.loaded = true;
  }

  // Regular refreshes share a URL for 15 seconds so browsers and proxies can
  // answer them; after a donation, `fresh` skips those copies.
  function load(c, fresh) {
    if (c.busy && !fresh) {
      return;
    }
    c.busy = true;
    var url = c.api + (c.api.indexOf('?') === -1 ? '?' : '&') + '_=' + (fresh ? Date.now() : Math.floor(Date.now() / 15000));
    window.fetch(url, { credentials: 'omit' })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (data && data.ok) {
          apply(c, data);
        }
      })
      .catch(function () {})
      .then(function () { c.busy = false; });
  }

  function schedule(c) {
    window.clearTimeout(c.timer);
    c.timer = window.setTimeout(function () {
      if (!document.hidden) {
        load(c);
      }
      schedule(c);
    }, c.refresh * 1000);
  }

  function setup(root) {
    if (root.__efCampaign) {
      return;
    }
    var ref = root.getAttribute('data-ef-campaign');
    if (!ref || (!site && !root.getAttribute('data-ef-api'))) {
      return;
    }
    var c = {
      root: root,
      api: root.getAttribute('data-ef-api') || site + '/wp-json/embed-forms/v1/forms/' + encodeURIComponent(ref) + '/campaign',
      refresh: Math.max(10, Number(root.getAttribute('data-ef-refresh')) || 30),
      seen: {},
      loaded: false,
      busy: false,
      timer: null
    };
    root.__efCampaign = c;
    campaigns.push(c);
    load(c);
    schedule(c);
  }

  function scan() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-ef-campaign]'), setup);
  }

  // A donation in an embedded form: fetch now, and once more shortly after
  // in case the payment was still being recorded.
  document.addEventListener('embedforms:submitted', function () {
    campaigns.forEach(function (c) {
      load(c, true);
      window.setTimeout(function () { load(c, true); }, 4000);
    });
  });
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      campaigns.forEach(load);
    }
  });

  window.EmbedFormsCampaign = { scan: scan, refresh: function () { campaigns.forEach(load); } };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scan);
  } else {
    scan();
  }
}(window, document));
