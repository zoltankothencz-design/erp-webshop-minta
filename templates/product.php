<?php /** @var array $p */ ?>
<p><a href="/">&larr; Vissza a termékekhez</a></p>
<article class="detail">
  <img src="<?= e($p['image']) ?>" alt="<?= e($p['name']) ?>" width="360" height="240">
  <div>
    <h1><?= e($p['name']) ?></h1>
    <p class="muted"><?= e($p['brand']) ?> · <?= e($p['category']) ?> · cikkszám: <?= e($p['sku']) ?></p>
    <p class="price big"><?= e(money($p['price'])) ?></p>
    <p><?= e($p['description']) ?></p>
    <?php if ($p['available']): ?>
      <p class="ok">Raktáron</p>
      <button class="btn" data-add-to-cart="<?= e($p['sku']) ?>" data-max="<?= (int) $p['max_qty'] ?>">Kosárba</button>
    <?php else: ?>
      <p class="out">Jelenleg nincs raktáron</p>
    <?php endif; ?>
  </div>
</article>
