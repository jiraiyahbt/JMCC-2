<?php $title = 'Staff sign in · SafeGate'; ?>
<section class="narrow-page">
  <div class="card form-card">
    <p class="eyebrow">Staff portal</p>
    <h1>Sign in to SafeGate</h1>
    <p class="muted">Use your individual staff account. Visitor passes are not staff accounts.</p>
    <form method="post" action="/login" class="stack-form">
      <?php csrf_field(); ?>
      <label>Email
        <input name="email" type="email" autocomplete="username" required value="<?= h($email ?? '') ?>">
      </label>
      <label>Password
        <input name="password" type="password" autocomplete="current-password" required>
      </label>
      <button class="button button-primary button-wide" type="submit">Sign in</button>
    </form>
  </div>
</section>
