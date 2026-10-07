/* Admin felület: jQuery. Minden szöveget .text()-tel írunk be, soha nem .html()-lel, így az adatbázisból jövő tartalom nem tud kódot futtatni (XSS). */
(function ($) {
  'use strict';
  var csrf = $('meta[name="csrf-token"]').attr('content');
  if (!$('#kpis').length) { return; } // csak a dashboard oldalon fut

  function api(method, url) {
    return $.ajax({ url: url, method: method, dataType: 'json', headers: { 'X-CSRF-Token': csrf } });
  }
  function money(n) { return new Intl.NumberFormat('hu-HU').format(Math.round(n)) + ' Ft'; }
  function num(n) { return new Intl.NumberFormat('hu-HU').format(n); }
  function td(text) { return $('<td>').text(text === null || text === undefined ? '' : text); }
  function pill(status) { return $('<td>').append($('<span>').addClass('pill ' + status).text(status)); }
  function toast(text) { $('#toast').empty().append($('<div>').addClass('toast').text(text)); }

  function kpi(label, value, note) {
    var $d = $('<div>').addClass('kpi');
    $d.append($('<span>').addClass('muted').text(label), $('<b>').text(value));
    if (note) { $d.append($('<span>').addClass('muted').text(note)); }
    return $d;
  }

  function render(d) {
    $('#range').text('(utolsó ' + d.days + ' nap)');
    var orders = 0, revenue = 0;
    d.orders_by_source.forEach(function (s) { orders += +s.orders; revenue += +s.revenue; });
    var a = d.ads;
    $('#kpis').empty().append(
      kpi('Rendelések', num(orders), d.orders_by_source.map(function (s) { return s.source + ': ' + s.orders; }).join(', ')),
      kpi('Bevétel', money(revenue)),
      kpi('Hirdetési költség', money(a.cost), num(a.clicks) + ' kattintás'),
      kpi('ROAS (saját rendelések)', a.roas_shop === null ? '-' : a.roas_shop.toFixed(2), 'hirdetésnek tulajdonított bevétel: ' + money(a.revenue_shop)),
      kpi('ROAS (Google szerint)', a.roas_google === null ? '-' : a.roas_google.toFixed(2), 'a két szám eltérése maga is információ')
    );

    $('#campaigns').empty();
    d.campaigns.forEach(function (c) {
      $('#campaigns').append($('<tr>').append(td(c.name), td(num(c.impressions)), td(num(c.clicks)), td(money(c.cost)), td((+c.conversions).toFixed(1)), td(money(c.conv_value))));
    });

    $('#orders').empty();
    d.recent_orders.forEach(function (o) {
      $('#orders').append($('<tr>').append(td(o.order_no), td(o.source), td(o.customer_name), td(money(o.total)), pill(o.status), td(o.erp_order_id), td(+o.has_gclid ? 'gclid' : (o.utm_campaign || '')), td(o.created_at)));
    });

    $('#drafts').empty();
    if (!d.ai_drafts.length) { $('#drafts').append($('<tr>').append($('<td colspan="4">').addClass('muted').text('Nincs jóváhagyásra váró piszkozat.'))); }
    d.ai_drafts.forEach(function (p) {
      var $actions = $('<td>').append(
        $('<button>').addClass('btn ai-act').data({ id: p.id, action: 'approve' }).text('Jóváhagy'), ' ',
        $('<button>').addClass('btn secondary ai-act').data({ id: p.id, action: 'reject' }).text('Elutasít')
      );
      $('#drafts').append($('<tr>').append(td(p.sku), td(p.name), $('<td>').css({ 'white-space': 'normal', 'min-width': '320px' }).text(p.description_ai), $actions));
    });

    $('#synclog').empty();
    d.sync_log.forEach(function (l) {
      $('#synclog').append($('<tr>').append(td(l.id), td(l.job), pill(l.status), td(l.message), td(l.items), td(l.started_at)));
    });
  }

  function load() {
    api('GET', '/admin/api/summary').done(render).fail(function (x) {
      if (x.status === 401) { window.location = '/admin/login'; }
    });
  }

  $(document).on('click', '.job-btn', function () {
    var $b = $(this), job = $b.data('job');
    $b.prop('disabled', true);
    api('POST', '/admin/api/jobs/' + encodeURIComponent(job))
      .done(function (r) { toast(job + ': ' + r.status + ' - ' + r.message); })
      .fail(function () { toast(job + ': a kérés nem sikerült'); })
      .always(function () { $b.prop('disabled', false); load(); });
  });

  $(document).on('click', '.ai-act', function () {
    var $b = $(this);
    $b.prop('disabled', true);
    api('POST', '/admin/api/ai/' + $b.data('id') + '/' + $b.data('action')).always(load);
  });

  load();
})(jQuery);
