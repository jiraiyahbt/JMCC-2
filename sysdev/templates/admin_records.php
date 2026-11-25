<?php
$title = 'Visitor records · SafeGate';
$filters = array_merge([
  'q' => '',
  'visit_date' => '',
  'status' => '',
  'entrance' => '',
], $filters ?? []);
$entrances = $entrances ?? [];
$records = $records ?? [];
$totals = $totals ?? [];
$today = $today ?? date('F j, Y');
$page = $page ?? 1;
$total_pages = $total_pages ?? 1;
$total_records = $total_records ?? count($records);
?>
<section class="page-heading">
  <div><p class="eyebrow">Administration / visitor records</p><h1>Every visit, in one place.</h1><p class="muted">Review guard-entered check-in and check-out records.</p></div>
  <a class="button button-primary" href="<?= h(url('/admin/records.csv', $filters)) ?>">Export filtered records</a>
</section>
<section class="stats-grid">
  <article class="card stat-card"><span>Visits today</span><strong><?= h($totals['all_visits'] ?? 0) ?></strong><small><?= h($today) ?></small></article>
  <article class="card stat-card"><span>Currently inside</span><strong><?= h($totals['inside'] ?? 0) ?></strong><small>At participating entrances</small></article>
  <article class="card stat-card"><span>Completed visits</span><strong><?= h($totals['exited'] ?? 0) ?></strong><small>Exit time recorded</small></article>
</section>
<section class="card filter-card">
  <div class="section-heading"><div><h2>Find visitor records</h2><p class="muted">Filters apply to current and past visits.</p></div><a href="/admin/records">Reset filters</a></div>
  <form method="get" action="/admin/records" class="filter-bar">
    <label>Visitor details or OCR text<input name="q" value="<?= h($filters['q']) ?>" placeholder="Search visitor records"></label>
    <label>Visit date<input type="date" name="visit_date" value="<?= h($filters['visit_date']) ?>"></label>
    <label>Status<select name="status"><option value="">All statuses</option><?php foreach (['Inside campus', 'Exited'] as $item): ?><option value="<?= h($item) ?>" <?= $filters['status'] === $item ? 'selected' : '' ?>><?= h($item) ?></option><?php endforeach; ?></select></label>
    <label>Entrance<select name="entrance"><option value="">All entrances</option><?php foreach ($entrances as $item): ?><option value="<?= h($item) ?>" <?= $filters['entrance'] === $item ? 'selected' : '' ?>><?= h($item) ?></option><?php endforeach; ?></select></label>
    <button class="button button-primary" type="submit">Apply filters</button>
  </form>
</section>
<section class="card table-card">
  <div class="section-heading"><div><h2>Visitor log entries</h2><p class="muted"><?= h($total_records) ?> matching record(s); page <?= h($page) ?> of <?= h($total_pages) ?></p></div></div>
  <?php if ($records): ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Visitor ID details</th><th>Person or office / purpose</th><th>Check-in</th><th>Check-out</th><th>Status</th><th>Entrance / guard</th></tr></thead>
      <tbody><?php foreach ($records as $record): ?>
        <tr>
          <td>
            <strong><?= h(($record['visitor_name'] ?? '') ?: 'ID details recorded') ?></strong>
            <?php if (!empty($record['visitor_id_text'])): ?><details><summary>View saved OCR text</summary><pre class="ocr-record-text"><?= h($record['visitor_id_text']) ?></pre></details><?php endif; ?>
            <?php if (!empty($record['mobile_number'])): ?><small><?= h($record['mobile_number']) ?></small><?php endif; ?>
            <?php if (!empty($record['visitor_address'])): ?><small><?= h($record['visitor_address']) ?></small><?php endif; ?>
          </td>
          <td><?= h($record['destination']) ?><small><?= h($record['purpose']) ?></small></td>
          <td><?= h($record['entry_time'] ?: '—') ?></td>
          <td><?= h($record['exit_time'] ?: '—') ?></td>
          <td><span class="badge badge-<?= h(status_class($record['status'])) ?>"><?= h($record['status']) ?></span></td>
          <td>In: <?= h($record['entrance_name'] ?: '—') ?><?php if ($record['entry_guard_name']): ?><small><?= h($record['entry_guard_name']) ?></small><?php endif; ?><?php if ($record['exit_entrance_name']): ?><small>Out: <?= h($record['exit_entrance_name']) ?><?php if ($record['exit_guard_name']): ?> · <?= h($record['exit_guard_name']) ?><?php endif; ?></small><?php endif; ?><small>Pass: <?= h($record['pass_reference'] ?: '—') ?></small></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php else: ?><p class="empty-state">No records match these filters.</p><?php endif; ?>
  <?php if ($total_pages > 1): ?>
    <nav class="form-actions" aria-label="Visitor record pages">
      <?php if ($page > 1): ?><a class="button button-secondary" href="<?= h(url('/admin/records', array_merge($filters, ['page' => $page - 1]))) ?>">Previous</a><?php endif; ?>
      <?php if ($page < $total_pages): ?><a class="button button-secondary" href="<?= h(url('/admin/records', array_merge($filters, ['page' => $page + 1]))) ?>">Next</a><?php endif; ?>
    </nav>
  <?php endif; ?>
</section>
