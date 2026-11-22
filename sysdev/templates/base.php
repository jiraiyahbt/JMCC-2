<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title ?? 'SafeGate · Universidad de Zamboanga') ?></title>
  <link rel="stylesheet" href="/static/css/app.css">
  <?php if (!empty($scannerPage)): ?>
    <script defer src="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js"></script>
    <script defer src="/static/js/id_scan.js"></script>
  <?php endif; ?>
</head>
<body>
  <header class="topbar">
    <a class="brand" href="/"><span class="brand-mark">S</span><span>SafeGate</span></a>
    <nav class="topnav" aria-label="Main navigation">
      <?php if ($user): ?>
        <?php if ($user['role'] === 'Administrator'): ?>
          <a href="/admin/records">Visitor records</a>
          <a href="/admin/users">User accounts</a>
        <?php else: ?>
          <a href="/guard">Monitoring</a>
          <a href="/guard/verify">Scan visitor ID</a>
        <?php endif; ?>
        <a href="/account/password">Account</a>
        <form action="/logout" method="post" class="inline-form">
          <?php csrf_field(); ?>
          <button class="nav-button" type="submit">Sign out</button>
        </form>
        <span class="user-chip"><?= h($user['full_name']) ?> · <?= h($user['role']) ?></span>
      <?php else: ?>
        <a href="/login">Staff sign in</a>
      <?php endif; ?>
    </nav>
  </header>
  <main class="page-shell">
    <?php if (!empty($messages)): ?>
      <section class="flash-stack" aria-live="polite">
        <?php foreach ($messages as [$category, $message]): ?>
          <p class="flash flash-<?= h($category) ?>"><?= h($message) ?></p>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
    <?= $content ?>
  </main>
  <footer class="site-footer">
    <span>SafeGate · Guard-operated visitor log</span>
    <span>For outside visitors at participating Universidad de Zamboanga entrances</span>
  </footer>
</body>
</html>
