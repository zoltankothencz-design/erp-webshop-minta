/* Statikus demo: a szerver nélküli GitHub Pages-en pótolja a bolt két API-hívását és a keresést.
 * - GET  /api/products?skus=...  -> a pillanatkép api/products.json-ból szűr
 * - POST /api/orders             -> nem küld sehova, hamis rendelésszámot ad (az ellenőrzés a valódi szabályokat követi)
 * - ?q=...                       -> a főoldalon a kártyák szűrése böngészőben
 * A valódi alkalmazás kódja (src/, templates/) érintetlen; ez csak a pillanatkép része.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  var base = new URL('.', script.src).href;   // a pillanatkép gyökere, záró perjellel
  window.DEMO_BASE = base;

  var realFetch = window.fetch.bind(window);
  var catalog = null;
  function loadCatalog() {
    if (!catalog) {
      catalog = realFetch(base + 'api/products.json').then(function (r) { return r.json(); });
    }
    return catalog;
  }
  function json(status, body) {
    return new Response(JSON.stringify(body), { status: status, headers: { 'Content-Type': 'application/json' } });
  }

  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : input.url;
    var method = ((init && init.method) || 'GET').toUpperCase();

    if (/\/api\/products\?skus=/.test(url) && method === 'GET') {
      var wanted = decodeURIComponent(url.split('skus=')[1] || '').split(',');
      return loadCatalog().then(function (data) {
        return json(200, { products: (data.products || []).filter(function (p) { return wanted.indexOf(p.sku) !== -1; }) });
      });
    }

    if (/\/api\/orders$/.test(url) && method === 'POST') {
      var body = {};
      try { body = JSON.parse(init.body); } catch (e) { /* üres */ }
      var errors = {};
      if (!body.customer_name || body.customer_name.trim().length < 2) { errors.customer_name = 'Kérjük, adja meg a nevét.'; }
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(body.email || '')) { errors.email = 'Érvényes e-mail címet adjon meg.'; }
      if (!body.phone || body.phone.trim().length < 6) { errors.phone = 'Kérjük, adja meg a telefonszámát.'; }
      if (!body.address || body.address.trim().length < 5) { errors.address = 'Kérjük, adja meg a szállítási címet.'; }
      if (!body.items || !body.items.length) { errors.items = 'A kosár üres.'; }
      if (Object.keys(errors).length) { return Promise.resolve(json(422, { errors: errors })); }
      var no = 'DEMO-' + Date.now().toString(36).toUpperCase();
      return Promise.resolve(json(201, { order_no: no }));
    }

    return realFetch(input, init);
  };

  // Keresés a főoldalon és a kategóriaoldalakon (a valódi bolt ezt szerveroldalon végzi)
  var q = new URLSearchParams(window.location.search).get('q');
  if (q) {
    document.addEventListener('DOMContentLoaded', function () {
      var needle = q.toLowerCase();
      var cards = document.querySelectorAll('.card');
      var shown = 0;
      cards.forEach(function (card) {
        var hit = card.textContent.toLowerCase().indexOf(needle) !== -1;
        card.hidden = !hit;
        if (hit) { shown++; }
      });
      var input = document.querySelector('input[name=q]');
      if (input) { input.value = q; }
      var h1 = document.querySelector('h1');
      if (h1) { h1.textContent = 'Keresés: ' + q + ' (' + shown + ' találat)'; }
    });
  }
})();
