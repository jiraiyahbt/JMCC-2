<?php $title = 'Visitor history · SafeGate'; ?>
<section class="narrow-page wide-narrow">
  <a class="back-link" href="/guard">← Back to visitor log</a>
  <p class="eyebrow">Guard operations / visitor history</p>
  <h1>Visitor history</h1>
  <form method="get" action="/guard/visitor-history" class="card lookup-form filter-bar">
    <label>Visitor name<input name="name" value="<?= h($name) ?>" maxlength="120" required></label>
    <label>Mobile number <span class="muted">(optional)</span><input name="mobile" value="<?= h($mobile) ?>" maxlength="20"></label>
    <button class="button button-primary" type="submit">Search history</button>
  </form>
  <section class="card table-card">
    <?php if ($history): ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Entry / exit</th><th>Destination / purpose</th><th>Entrance</th><th>Status</th><th>Pass reference</th></tr></thead>
        <tbody>
          <?php foreach ($history as $visit): ?>
            <tr>
              <td><strong><?= h($visit['entry_time']) ?></strong><small><?= h($visit['exit_time'] ?: 'Not exited') ?></small></td>
              <td><?= h($visit['destination']) ?><small><?= h($visit['purpose']) ?></small></td>
              <td><?= h($visit['entrance_name']) ?><?php if ($visit['exit_entrance_name']): ?><small>Out: <?= h($visit['exit_entrance_name']) ?></small><?php endif; ?></td>
              <td><span class="badge badge-<?= h(status_class($visit['status'])) ?>"><?= h($visit['status']) ?></span></td>
              <td><?= h($visit['pass_reference'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php elseif ($name !== ''): ?>
      <p class="empty-state">No prior visits match this visitor.</p>
    <?php else: ?>
      <p class="empty-state">Enter a visitor name to view previous visits.</p>
    <?php endif; ?>
  </section>
</section>
