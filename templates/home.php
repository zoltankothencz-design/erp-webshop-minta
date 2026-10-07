<?php
/** @var array $products @var array $categories @var ?string $activeCategory @var string $q */
?>
<h1>Termékek</h1>
<nav class="chips" aria-label="Kategóriák">
  <a class="chip <?= $activeCategory === null || $activeCategory === '' ? 'on' : '' ?>" href="/">Mind</a>
  <?php foreach ($categories as $c): ?>
    <a class="chip <?= $activeCategory === $c['slug'] ? 'on' : '' ?>" href="/?category=<?= e(rawurlencode($c['slug'])) ?>">
      <?= e($c['name']) ?> <small>(<?= (int) $c['count'] ?>)</small>
    </a>
  <?php endforeach; ?>
</nav>
<?php if ($q !== ''): ?>
  <p class="muted">Keresés: „<?= e($q) ?>” - <?= count($products) ?> találat</p>
<?php endif; ?>
<?php if ($products === []): ?>
  <p>Nincs a feltételeknek megfelelő termék. Ha még üres a bolt, futtasd az ERP-szinkront: <code>job erp-products</code>.</p>
<?php endif; ?>
<div class="grid">
  <?php foreach ($products as $p): ?>
    <article class="card">
      <a href="/product/<?= e(rawurlencode($p['slug'])) ?>"><img src="<?= e($p['image']) ?>" alt="" width="240" height="160" loading="lazy"></a>
      <h2><a href="/product/<?= e(rawurlencode($p['slug'])) ?>"><?= e($p['name']) ?></a></h2>
      <p class="muted"><?= e($p['brand']) ?> · <?= e($p['category']) ?></p>
      <p class="price"><?= e(money($p['price'])) ?></p>
      <?php if ($p['available']): ?>
        <button class="btn" data-add-to-cart="<?= e($p['sku']) ?>" data-max="<?= (int) $p['max_qty'] ?>">Kosárba</button>
      <?php else: ?>
        <p class="out">Nincs raktáron</p>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</div>
