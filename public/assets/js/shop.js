/* Webshop frontend: vanilla JavaScript, függőség nélkül.
 * - a kosár a böngésző localStorage-ában él ({sku: darab}); az árat soha nem tároljuk itt, azt a szerver mondja meg
 * - a hirdetési azonosítókat (utm_*, gclid) az első látogatáskor megjegyezzük, és a rendeléssel együtt elküldjük
 */
(function () {
  'use strict';

  var CART_KEY = 'wsm_cart';
  var ATTR_KEY = 'wsm_attr';
  var ATTR_TTL = 30 * 24 * 3600 * 1000; // 30 nap

  function read(key, fallback) {
    try { var v = JSON.parse(localStorage.getItem(key)); return v === null ? fallback : v; } catch (e) { return fallback; }
  }
  function write(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* privát mód: nincs mentés */ }
  }
  function money(n) { return new Intl.NumberFormat('hu-HU').format(n) + ' Ft'; }

  // --- Marketing-attribúció: az utolsó kattintás számít
  (function captureAttribution() {
    var params = new URLSearchParams(window.location.search);
    var keys = ['utm_source', 'utm_medium', 'utm_campaign', 'gclid'];
    var found = {}, any = false;
    keys.forEach(function (k) { if (params.get(k)) { found[k] = params.get(k); any = true; } });
    if (any) { found.ts = Date.now(); write(ATTR_KEY, found); }
  })();
  function attribution() {
    var a = read(ATTR_KEY, null);
    if (!a || Date.now() - a.ts > ATTR_TTL) { return {}; }
    var copy = {}; Object.keys(a).forEach(function (k) { if (k !== 'ts') { copy[k] = a[k]; } });
    return copy;
  }

  // --- Kosár
  function getCart() { return read(CART_KEY, {}); }
  function setCart(c) { write(CART_KEY, c); updateBadge(); }
  function updateBadge() {
    var n = 0, c = getCart();
    Object.keys(c).forEach(function (k) { n += c[k]; });
    var el = document.getElementById('cart-count');
    if (el) { el.textContent = String(n); }
  }
  updateBadge();

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-add-to-cart]');
    if (!btn) { return; }
    var sku = btn.getAttribute('data-add-to-cart');
    var max = parseInt(btn.getAttribute('data-max'), 10) || 1;
    var c = getCart();
    c[sku] = Math.min((c[sku] || 0) + 1, max);
    setCart(c);
    var old = btn.textContent;
    btn.textContent = 'Kosárban (' + c[sku] + ')';
    setTimeout(function () { btn.textContent = old; }, 1200);
  });

  // --- Kosár oldal
  var app = document.getElementById('cart-app');
  if (!app) { return; }

  function show(id) {
    ['cart-loading', 'cart-empty', 'cart-content', 'cart-done'].forEach(function (x) {
      document.getElementById(x).hidden = (x !== id);
    });
  }
  function cell(tag, text) { var el = document.createElement(tag); el.textContent = text; return el; } // textContent: nincs XSS

  function render() {
    var cart = getCart(), skus = Object.keys(cart);
    if (!skus.length) { show('cart-empty'); return; }
    fetch('/api/products?skus=' + encodeURIComponent(skus.join(',')))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var items = data.products || [], known = {};
        items.forEach(function (p) { known[p.sku] = true; });
        var cleaned = {};
        skus.forEach(function (s) { if (known[s]) { cleaned[s] = cart[s]; } }); // már nem létező terméket eldobunk
        if (Object.keys(cleaned).length !== skus.length) { setCart(cleaned); }
        if (!items.length) { show('cart-empty'); return; }

        var tbody = document.getElementById('cart-rows'), total = 0;
        tbody.textContent = '';
        items.forEach(function (p) {
          var qty = Math.min(cleaned[p.sku], Math.max(p.max_qty, 1));
          total += p.price * qty;
          var tr = document.createElement('tr');
          var nameTd = document.createElement('td');
          var a = document.createElement('a'); a.href = '/product/' + encodeURIComponent(p.slug); a.textContent = p.name;
          nameTd.appendChild(a); tr.appendChild(nameTd);
          tr.appendChild(cell('td', money(p.price)));
          var qtyTd = document.createElement('td'), input = document.createElement('input');
          input.type = 'number'; input.min = '1'; input.max = String(Math.max(p.max_qty, 1)); input.value = String(qty);
          input.addEventListener('change', function () {
            var c = getCart(), v = parseInt(input.value, 10);
            c[p.sku] = (v >= 1 && v <= p.max_qty) ? v : Math.min(Math.max(1, v || 1), p.max_qty);
            setCart(c); render();
          });
          qtyTd.appendChild(input); tr.appendChild(qtyTd);
          tr.appendChild(cell('td', money(p.price * qty)));
          var rmTd = document.createElement('td'), rm = document.createElement('button');
          rm.className = 'link'; rm.type = 'button'; rm.textContent = 'Törlés';
          rm.addEventListener('click', function () { var c = getCart(); delete c[p.sku]; setCart(c); render(); });
          rmTd.appendChild(rm); tr.appendChild(rmTd);
          tbody.appendChild(tr);
        });
        document.getElementById('cart-total').textContent = money(total);
        show('cart-content');
      })
      .catch(function () { show('cart-empty'); });
  }

  document.getElementById('checkout-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var form = e.target, err = document.getElementById('form-error'), btn = form.querySelector('button[type=submit]');
    err.hidden = true;
    var cart = getCart();
    var body = {
      customer_name: form.customer_name.value, email: form.email.value,
      phone: form.phone.value, address: form.address.value,
      items: Object.keys(cart).map(function (sku) { return { sku: sku, qty: cart[sku] }; }),
      attribution: attribution()
    };
    btn.disabled = true;
    fetch('/api/orders', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }); })
      .then(function (res) {
        if (res.status === 201) {
          setCart({});
          document.getElementById('order-no').textContent = res.json.order_no;
          show('cart-done');
          return;
        }
        var msgs = res.json.errors ? Object.keys(res.json.errors).map(function (k) { return res.json.errors[k]; }) : [res.json.error || 'Ismeretlen hiba'];
        err.textContent = msgs.join(' ');
        err.hidden = false;
      })
      .catch(function () { err.textContent = 'Hálózati hiba, kérjük próbálja újra.'; err.hidden = false; })
      .then(function () { btn.disabled = false; });
  });

  render();
})();
