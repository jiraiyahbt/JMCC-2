<?php
$title = 'Visitor log · SafeGate';
$today = $today ?? date('Y-m-d');
$entrance = $entrance ?? ENTRANCES[0];
$entrances = isset($entrances) && is_array($entrances) ? $entrances : ENTRANCES;
$stats = array_merge([
  'inside' => 0,
  'visits' => 0,
  'exited' => 0,
  'long_stays' => 0,
], isset($stats) && is_array($stats) ? $stats : []);
$visits = isset($visits) && is_array($visits) ? $visits : [];
?>
<section class="page-heading">
  <div>
    <p class="eyebrow">Gate operations / today</p>
    <h1>Visitor log</h1>
    <p class="muted"><?= h($today) ?> · Selected entrance: <?= h($entrance) ?></p>
  </div>
  <a class="button button-primary" href="<?= h(url('/guard/verify', ['entrance' => $entrance])) ?>">Scan visitor ID</a>
</section>
<nav class="form-actions"><a class="button button-secondary" href="/guard/visitor-history">Search visitor history</a></nav>
<form class="filter-bar compact-filter" method="get" action="/guard">
  <label>Selected entrance
    <select name="entrance" onchange="this.form.submit()">
      <?php foreach ($entrances as $item): ?><option value="<?= h($item) ?>" <?= $item === $entrance ? 'selected' : '' ?>><?= h($item) ?></option><?php endforeach; ?>
    </select>
  </label>
</form>
<section class="stats-grid">
  <article class="card stat-card"><span>Currently inside</span><strong><?= h($stats['inside']) ?></strong><small>Check-out not recorded</small></article>
  <article class="card stat-card"><span>Visits today</span><strong><?= h($stats['visits']) ?></strong><small>Check-in recorded</small></article>
  <article class="card stat-card"><span>Checked out today</span><strong><?= h($stats['exited']) ?></strong><small>Visit completed</small></article>
</section>
<?php if (!empty($stats['long_stays'])): ?>
  <div class="notice"><strong>Long-stay alert</strong><p><?= h($stats['long_stays']) ?> active visit(s) have exceeded the configured stay threshold.</p></div>
<?php endif; ?>
<section class="card table-card">
  <div class="section-heading"><div><h2>Today's and active visits</h2><p class="muted"><?= count($visits) ?> recent visit(s), including anyone still inside</p></div><a class="button button-secondary" href="<?= h(url('/guard/verify', ['entrance' => $entrance])) ?>">New check-in</a></div>
  <?php if ($visits): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Visitor ID details</th><th>Person or office / purpose</th><th>Check-in</th><th>Check-out</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($visits as $visit): ?>
            <tr>
              <td>
                <strong><?= h(($visit['visitor_name'] ?? '') ?: 'ID details recorded') ?></strong>
                <?php if (!empty($visit['visitor_id_text'])): ?><details><summary>View saved OCR text</summary><pre class="ocr-record-text"><?= h($visit['visitor_id_text']) ?></pre></details><?php endif; ?>
                <?php if (!empty($visit['mobile_number'])): ?><small><?= h($visit['mobile_number']) ?></small><?php endif; ?>
                <?php if (!empty($visit['visitor_address'])): ?><small><?= h($visit['visitor_address']) ?></small><?php endif; ?>
              </td>
              <td><?= h($visit['destination']) ?><small><?= h($visit['purpose']) ?></small></td>
              <td><?= h($visit['entry_time']) ?><small><?= h($visit['entrance_name']) ?></small></td>
              <td><?= h($visit['exit_time'] ?: '—') ?><?php if ($visit['exit_entrance_name']): ?><small><?= h($visit['exit_entrance_name']) ?></small><?php endif; ?></td>
              <td><span class="badge badge-<?= h(status_class($visit['status'])) ?>"><?= h($visit['status']) ?></span></td>
              <td>
                <?php if ($visit['status'] === 'Inside campus'): ?>
                  <form method="post" action="/guard/visits/<?= h($visit['visit_id']) ?>/checkout" class="checkout-form">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="exit_entrance" value="<?= h($entrance) ?>">
                    <label class="check-row"><input type="checkbox" name="checkout_confirmed" value="yes" required><span>School pass returned and visitor exited</span></label>
                    <button class="button button-secondary" type="submit">Record check-out</button>
                  </form>
                <?php else: ?>Complete<?php endif; ?>
                <a class="button button-secondary" href="/guard/visits/<?= h($visit['visit_id']) ?>/pass">Pass reference</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="empty-state">No visits have been logged today.</p>
  <?php endif; ?>
</section>
