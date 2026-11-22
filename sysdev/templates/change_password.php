<?php $title = 'Account security · SafeGate'; ?>
<section class="narrow-page">
  <div class="card form-card">
    <p class="eyebrow">Account security</p>
    <h1>Change password</h1>
    <form method="post" action="/account/password" class="stack-form">
      <?php csrf_field(); ?>
      <label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
      <label>New password<input type="password" name="new_password" minlength="12" autocomplete="new-password" required><small>Use at least 12 characters.</small></label>
      <label>Confirm new password<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required></label>
      <button class="button button-primary" type="submit">Update password</button>
    </form>
  </div>
</section>
