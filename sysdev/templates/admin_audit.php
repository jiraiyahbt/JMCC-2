<?php $title = 'Audit log · SafeGate'; ?>
<section class="page-heading">
  <div><p class="eyebrow">Administration / audit log</p><h1>Recent security activity</h1><p class="muted">The latest 200 account and visitor workflow events.</p></div>
</section>
<section class="card table-card">
  <?php if (!empty($entries)): ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Record</th><th>Details</th><th>IP address</th></tr></thead>
      <tbody>
        <?php foreach ($entries as $entry): ?>
          <tr>
            <td><?= h($entry['created_at']) ?></td>
            <td><?= h($entry['actor_name'] ?: 'System / signed-out user') ?></td>
            <td><?= h($entry['action']) ?></td>
            <td><?= h($entry['entity_type']) ?><?= $entry['entity_id'] ? ' #' . h($entry['entity_id']) : '' ?></td>
            <td><?= h($entry['details'] ?: '—') ?></td>
            <td><?= h($entry['ip_address'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php else: ?>
    <p class="empty-state">No audit events have been recorded.</p>
  <?php endif; ?>
</section>
