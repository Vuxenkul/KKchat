<?php if (!defined('ABSPATH')) exit; ?>
<div class="kk-wrap" id="kkchat-root">
  <?php if (!$me_logged): ?>
    <?php include __DIR__ . '/login.php'; ?>
  <?php else: ?>
    <?php include __DIR__ . '/app.php'; ?>
  <?php endif; ?>
</div>
