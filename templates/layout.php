<?php /** @var string $content @var string $title */ ?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> | Mintabolt</title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<header class="site-header">
  <div class="wrap bar">
    <a class="brand" href="/">Mintabolt</a>
    <form class="search" action="/" method="get" role="search">
      <input type="search" name="q" placeholder="Keresés: név, cikkszám, márka" value="<?= e($_GET['q'] ?? '') ?>" aria-label="Keresés">
      <button type="submit">Keresés</button>
    </form>
    <a class="cart-link" href="/cart">Kosár <span id="cart-count" class="badge">0</span></a>
  </div>
</header>
<main class="wrap">
  <?= $content ?>
</main>
<footer class="site-footer wrap">
  <p>Mintaprojekt: kitalált termékek és cég. Nem valódi webshop.</p>
</footer>
<script src="/assets/js/shop.js" defer></script>
</body>
</html>
