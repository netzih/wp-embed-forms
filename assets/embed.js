/**
 * Embed Forms loader for other websites.
 *
 *   <div data-embed-form="FORM-ID"></div>
 *   <script src="https://example.org/wp-content/plugins/embed-forms/assets/embed.js" async></script>
 *
 * Turns every placeholder into an iframe showing the form, sized to its
 * content. The parent page's query string is passed on so fields can be
 * prefilled from the link (and campaign parameters recorded). The iframe
 * reports its height, asks to be scrolled into view when it moves to another
 * step or shows errors, and can ask the parent to navigate after a
 * submission (a cross-origin frame cannot do that itself).
 *
 * Optional attributes on the placeholder: data-base (form address prefix,
 * default: this script's site + "/f/"), data-min-height (pixels).
 * A "embedforms:submitted" event bubbles from the placeholder on success.
 */
(function (window, document) {
  'use strict';

  var script = document.currentScript || (function () {
    var scripts = document.getElementsByTagName('script');
    for (var i = scripts.length - 1; i >= 0; i--) {
      if (/\/embed-forms\/assets\/embed\.js/.test(scripts[i].src)) {
        return scripts[i];
      }
    }
    return null;
  }());

  if (window.EmbedFormsLoader) {
    // Loaded twice on one page: the first copy handles everything.
    window.EmbedFormsLoader.scan();
    return;
  }

  var scriptUrl = script && script.src ? new URL(script.src, window.location.href) : null;
  var defaultBase = script && script.getAttribute('data-base')
    ? script.getAttribute('data-base')
    : (scriptUrl ? scriptUrl.href.replace(/\/wp-content\/plugins\/.*$/, '') + '/f/' : '');
  var frames = {};

  function frameId() {
    return 'ef' + Math.random().toString(36).slice(2, 10);
  }

  function iframeSrc(base, ref, id) {
    var params = new URLSearchParams(window.location.search);
    // Our own parameters are never taken from the parent's address.
    Array.from(params.keys()).forEach(function (key) {
      if (key.indexOf('ef_') === 0) {
        params.delete(key);
      }
    });
    params.set('ef_embed', '1');
    params.set('ef_frame', id);
    params.set('ef_parent', window.location.href.split('#')[0]);
    return base.replace(/\/?$/, '/') + encodeURIComponent(ref) + '/?' + params.toString();
  }

  function mount(el) {
    if (!el || el.getAttribute('data-embed-form-mounted')) {
      return;
    }
    var ref = el.getAttribute('data-embed-form');
    var base = el.getAttribute('data-base') || defaultBase;
    if (!ref || !base) {
      return;
    }
    el.setAttribute('data-embed-form-mounted', '1');
    var id = frameId();
    var iframe = document.createElement('iframe');
    iframe.src = iframeSrc(base, ref, id);
    iframe.title = el.getAttribute('data-title') || 'Form';
    iframe.setAttribute('allow', 'payment');
    iframe.setAttribute('allowpaymentrequest', 'true');
    iframe.setAttribute('scrolling', 'no');
    iframe.style.cssText = 'display:block;width:100%;border:0;overflow:hidden;background:transparent;min-height:' + (parseInt(el.getAttribute('data-min-height'), 10) || 200) + 'px;height:' + (parseInt(el.getAttribute('data-min-height'), 10) || 200) + 'px';
    el.innerHTML = '';
    el.appendChild(iframe);
    frames[id] = { iframe: iframe, el: el, origin: new URL(base, window.location.href).origin };
  }

  function scan() {
    var nodes = document.querySelectorAll('[data-embed-form]');
    for (var i = 0; i < nodes.length; i++) {
      mount(nodes[i]);
    }
  }

  function scrollIntoView(iframe) {
    var rect = iframe.getBoundingClientRect();
    if (rect.top < 0 || rect.top > window.innerHeight * 0.6) {
      window.scrollTo({ top: rect.top + window.pageYOffset - 24, behavior: 'smooth' });
    }
  }

  window.addEventListener('message', function (event) {
    var data = event.data;
    if (!data || data.source !== 'embed-forms' || !frames[data.frame]) {
      return;
    }
    var frame = frames[data.frame];
    if (event.origin !== frame.origin || event.source !== frame.iframe.contentWindow) {
      return;
    }
    switch (data.type) {
      case 'height':
        if (typeof data.height === 'number' && data.height > 0) {
          frame.iframe.style.height = Math.ceil(data.height) + 'px';
        }
        break;

      case 'scroll':
        scrollIntoView(frame.iframe);
        break;

      case 'redirect':
        if (typeof data.url === 'string' && /^https?:\/\//i.test(data.url)) {
          window.location.href = data.url;
        }
        break;

      case 'submitted':
        var detail = { form: frame.el.getAttribute('data-embed-form'), entry: data.entry || null };
        var custom;
        try {
          custom = new CustomEvent('embedforms:submitted', { bubbles: true, detail: detail });
        } catch (e) {
          custom = document.createEvent('CustomEvent');
          custom.initCustomEvent('embedforms:submitted', true, false, detail);
        }
        frame.el.dispatchEvent(custom);
        break;
    }
  });

  window.EmbedFormsLoader = { scan: scan, mount: mount };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scan);
  } else {
    scan();
  }
}(window, document));
