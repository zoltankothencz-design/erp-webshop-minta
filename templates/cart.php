<h1>Kosár</h1>
<div id="cart-app">
  <p id="cart-loading">Betöltés...</p>

  <div id="cart-empty" hidden>
    <p>A kosár üres. <a href="/">Vissza a termékekhez</a></p>
  </div>

  <div id="cart-content" hidden>
    <table class="cart-table">
      <thead><tr><th>Termék</th><th>Egységár</th><th>Db</th><th>Összesen</th><th></th></tr></thead>
      <tbody id="cart-rows"></tbody>
      <tfoot><tr><th colspan="3">Végösszeg</th><th id="cart-total"></th><th></th></tr></tfoot>
    </table>

    <h2>Szállítási adatok</h2>
    <form id="checkout-form" novalidate>
      <label>Név <input name="customer_name" autocomplete="name" required></label>
      <label>E-mail <input name="email" type="email" autocomplete="email" required></label>
      <label>Telefon <input name="phone" type="tel" autocomplete="tel" required></label>
      <label>Szállítási cím <input name="address" autocomplete="street-address" required></label>
      <p id="form-error" class="error" role="alert" hidden></p>
      <button class="btn" type="submit">Megrendelés</button>
    </form>
  </div>

  <div id="cart-done" hidden>
    <h2>Köszönjük a rendelését!</h2>
    <p>Rendelésszáma: <strong id="order-no"></strong></p>
    <p><a href="/">Vissza a termékekhez</a></p>
  </div>
</div>
