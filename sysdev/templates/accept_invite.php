<?php $title = 'Activate staff account · SafeGate'; ?>
<section class="narrow-page">
  <div class="card form-card">
    <p class="eyebrow">Staff account invitation</p>
    <h1>Set up your account</h1>
    <p class="muted"><?= h($invited['full_name']) ?> · <?= h($invited['email']) ?></p>
    <form method="post" class="stack-form">
      <?php csrf_field(); ?>
      <label>Password<input type="password" name="password" minlength="12" autocomplete="new-password" required><small>Use at least 12 characters.</small></label>
      <label>Confirm password<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required></label>
      <button class="button button-primary" type="submit">Activate account</button>
    </form>
  </div>
</section>
