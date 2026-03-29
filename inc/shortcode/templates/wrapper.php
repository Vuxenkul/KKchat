<?php if (!defined('ABSPATH')) exit; ?>
<div id="kkchat-root">
  <?php if (!$me_logged): ?>
    <div class="kk-login-bg-floaters" aria-hidden="true">
      <span class="kk-floater kk-floater--one"></span>
      <span class="kk-floater kk-floater--two"></span>
      <span class="kk-floater kk-floater--three"></span>
      <span class="kk-floater kk-floater--four"></span>
    </div>
  <?php endif; ?>

  <div class="kk-wrap <?= $me_logged ? 'kk-wrap--app' : 'kk-wrap--login' ?>">
    <?php if (!$me_logged): ?>
      <?php include __DIR__ . '/login.php'; ?>
    <?php else: ?>
      <?php include __DIR__ . '/app.php'; ?>
    <?php endif; ?>
  </div>
</div>
