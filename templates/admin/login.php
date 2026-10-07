<?php /** @var ?string $error */ ?>
<h1>Belépés</h1>
<form method="post" action="/admin/login" autocomplete="on">
  <input type="hidden" name="_csrf" value="<?= e(\App\Auth::csrfToken()) ?>">
  <label>Felhasználónév <input name="username" autocomplete="username" required autofocus></label>
  <label>Jelszó <input name="password" type="password" autocomplete="current-password" required></label>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <button class="btn" type="submit">Belépés</button>
</form>
