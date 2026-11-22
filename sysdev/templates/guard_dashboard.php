<?php $title = 'Visitor log · SafeGate'; ?>
<section class="page-heading">
  <div>
    <p class="eyebrow">Gate operations / today</p>
    <h1>Visitor log</h1>
    <p class="muted"><?= h($today) ?> · Selected entrance: <?= h($entrance) ?></p>
  </div>
  <a class="button button-primary" href="<?= h(url('/guard/verify', ['entrance' => $entrance])) ?>">Scan visitor ID</a>
</section>
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
<section class="card table-card">
  <div class="section-heading"><div><h2>Today's and active visits</h2><p class="muted"><?= count($visits) ?> recent visit(s), including anyone still inside</p></div><a class="button button-secondary" href="<?= h(url('/guard/verify', ['entrance' => $entrance])) ?>">New check-in</a></div>
  <?php if ($visits): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Visitor / mobile</th><th>Person or office / purpose</th><th>Check-in</th><th>Check-out</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($visits as $visit): ?>
            <tr>
              <td><strong><?= h($visit['visitor_name']) ?></strong><small><?= h($visit['mobile_number'] ?: 'Mobile not recorded') ?></small></td>
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
