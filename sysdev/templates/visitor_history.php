<?php
$title = 'Visitor history · SafeGate';
$name = is_string($name ?? null) ? trim((string) $name) : '';
$mobile = is_string($mobile ?? null) ? trim((string) $mobile) : '';
$history = is_array($history ?? null) ? $history : [];
$history = array_values(array_filter($history, 'is_array'));
?>
<section class="narrow-page wide-narrow">
  <a class="back-link" href="/guard">← Back to visitor log</a>
  <p class="eyebrow">Guard operations / visitor history</p>
  <h1>Visitor history</h1>
  <form method="get" action="/guard/visitor-history" class="card lookup-form filter-bar">
    <label>Visitor details or OCR text<input name="name" type="search" value="<?= h($name) ?>" maxlength="120" required></label>
    <label>Mobile number <span class="muted">(legacy records, optional)</span><input name="mobile" type="tel" value="<?= h($mobile) ?>" maxlength="20"></label>
    <button class="button button-primary" type="submit">Search history</button>
  </form>
  <section class="card table-card">
    <?php if ($history): ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Entry / exit</th><th>ID text / legacy address</th><th>Destination / purpose</th><th>Entrance</th><th>Status</th><th>Pass reference</th></tr></thead>
        <tbody>
          <?php foreach ($history as $visit): ?>
            <tr>
              <td><strong><?= h($visit['entry_time'] ?? '') ?></strong><small><?= h(($visit['exit_time'] ?? '') ?: 'Not exited') ?></small></td>
              <td>
                <?php if (!empty($visit['visitor_id_text'])): ?><details><summary>View saved OCR text</summary><pre class="ocr-record-text"><?= h($visit['visitor_id_text']) ?></pre></details>
                <?php else: ?><?= h($visit['visitor_address'] ?? '—') ?><?php endif; ?>
              </td>
              <td><?= h($visit['destination'] ?? '') ?><small><?= h($visit['purpose'] ?? '') ?></small></td>
              <td><?= h($visit['entrance_name'] ?? '') ?><?php if (!empty($visit['exit_entrance_name'])): ?><small>Out: <?= h($visit['exit_entrance_name']) ?></small><?php endif; ?></td>
              <td><span class="badge badge-<?= h(status_class((string) ($visit['status'] ?? ''))) ?>"><?= h($visit['status'] ?? '') ?></span></td>
              <td><?= h(($visit['pass_reference'] ?? '') ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php elseif ($name !== ''): ?>
      <p class="empty-state">No prior visits match these visitor details.</p>
    <?php else: ?>
      <p class="empty-state">Enter a visitor detail or OCR text fragment to search prior visits.</p>
    <?php endif; ?>
  </section>
</section>
