<?php /** @var list<string> $jobs */ ?>
<h1>Áttekintés <small class="muted" id="range"></small></h1>

<div class="kpis" id="kpis"></div>

<div class="panel">
  <h2>Integrációs feladatok</h2>
  <p class="muted">Ugyanezeket futtatná éles környezetben a cron (<code>php bin/console.php job &lt;név&gt;</code>). Minden futás naplózódik.</p>
  <div class="jobs">
    <?php foreach ($jobs as $j): ?>
      <button class="btn secondary job-btn" data-job="<?= e($j) ?>"><?= e($j) ?></button>
    <?php endforeach; ?>
  </div>
  <div id="toast"></div>
</div>

<div class="panel">
  <h2>Kampányok (Google Ads)</h2>
  <table><thead><tr><th>Kampány</th><th>Megjelenés</th><th>Kattintás</th><th>Költség</th><th>Konverzió</th><th>Google szerinti érték</th></tr></thead>
  <tbody id="campaigns"></tbody></table>
</div>

<div class="panel">
  <h2>Legutóbbi rendelések</h2>
  <table><thead><tr><th>Szám</th><th>Forrás</th><th>Vevő</th><th>Összeg</th><th>Állapot</th><th>ERP</th><th>Hirdetés</th><th>Idő</th></tr></thead>
  <tbody id="orders"></tbody></table>
</div>

<div class="panel">
  <h2>AI leírás-piszkozatok jóváhagyásra</h2>
  <table><thead><tr><th>Cikkszám</th><th>Termék</th><th>Javasolt leírás</th><th></th></tr></thead>
  <tbody id="drafts"></tbody></table>
</div>

<div class="panel">
  <h2>Integrációs napló</h2>
  <table><thead><tr><th>#</th><th>Feladat</th><th>Állapot</th><th>Üzenet</th><th>Elem</th><th>Kezdés</th></tr></thead>
  <tbody id="synclog"></tbody></table>
</div>
