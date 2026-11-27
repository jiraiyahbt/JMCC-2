<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title ?? 'SafeGate · Universidad de Zamboanga') ?></title>
  <link rel="stylesheet" href="/static/css/app.css">
  <?php if (!empty($scannerPage)): ?>
    <script defer src="/static/vendor/tesseract/tesseract.min.js"></script>
    <script defer src="/static/js/id_scan.js"></script>
  <?php endif; ?>
</head>
<body>
  <header class="topbar">
    <a class="brand" href="/"><img class="brand-mark" src="/static/img/jmcc-logo.png" alt=""><span>SafeGate</span></a>
    <nav class="topnav" aria-label="Main navigation">
      <?php if (isset($user) && $user): ?>
        <?php if ($user['role'] === 'Administrator'): ?>
          <a href="/admin/records">Visitor records</a>
          <a href="/admin/users">User accounts</a>
          <a href="/admin/audit">Audit log</a>
        <?php else: ?>
          <a href="/guard">Monitoring</a>
          <a href="/guard/verify">Scan visitor ID</a>
          <a href="/guard/visitor-history">Visitor history</a>
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
    <?= $content ?? '' ?>
  </main>
  <footer class="site-footer">
    <span>SafeGate · Guard-operated visitor log</span>
    <span>For outside visitors at participating Universidad de Zamboanga entrances</span>
  </footer>
</body>
</html>
