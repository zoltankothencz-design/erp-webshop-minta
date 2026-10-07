<?php /** @var string $content @var string $title */ ?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-token" content="<?= e(\App\Auth::csrfToken()) ?>">
  <title><?= e($title) ?> | Admin</title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="admin-nav">
  <strong>Mintabolt admin</strong>
  <a href="/admin">Áttekintés</a>
  <a href="/" target="_blank" rel="noopener">Webshop &nearr;</a>
  <?php if (\App\Auth::check()): ?>
    <form action="/admin/logout" method="post" style="margin-left:auto">
      <input type="hidden" name="_csrf" value="<?= e(\App\Auth::csrfToken()) ?>">
      <button class="btn secondary" type="submit">Kilépés</button>
    </form>
  <?php endif; ?>
</div>
<main class="wrap">
  <?= $content ?>
</main>
<script src="/assets/js/vendor/jquery-3.7.1.min.js"></script>
<script src="/assets/js/admin.js"></script>
</body>
</html>
