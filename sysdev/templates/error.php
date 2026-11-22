<?php
$error = array_merge([
  'code' => 404,
  'name' => 'Not Found',
  'description' => 'The requested page could not be found.',
], isset($error) && is_array($error) ? $error : []);
$title = (string) $error['code'] . ' · SafeGate';
?>
<section class="narrow-page card form-card">
  <p class="eyebrow">SafeGate</p>
  <h1><?= h($error['code']) ?> · <?= h($error['name']) ?></h1>
  <p class="lead"><?= h($error['description'] ?: 'The requested page could not be found.') ?></p>
  <a class="button button-primary" href="/">Return home</a>
</section>
