<?php
$title = 'Visitor record · SafeGate';
$visit = array_merge([
  'visit_id' => '',
  'visitor_name' => '',

  'destination' => '',
  'purpose' => '',
  'entrance_name' => '',
  'entry_time' => '',
  'exit_entrance_name' => '',
  'exit_time' => '',
  'status' => 'Unknown',
], is_array($visit ?? null) ? $visit : []);
$formatVisitTime = static function (string $value): string {
  $timestamp = strtotime($value);
  return $timestamp === false ? $value : date('M j, Y, g:i A', $timestamp);
};
$entryTime = (string) ($visit['entry_time'] ?? '');
$exitTime = (string) ($visit['exit_time'] ?? '');
?>
<section class="narrow-page">
  <a class="back-link" href="/guard">← Back to visitor log</a>
  <p class="eyebrow">Visitor record</p>
  <section class="card pass-card">
    <div class="pass-heading">
      <h2>SafeGate visitor record</h2>
      <p><?= h($visit['status']) ?></p>
    </div>
    <div class="pass-body">
      <div class="detail-grid">
        <dt>Visitor</dt><dd><?= h(($visit['visitor_name'] ?? '') ?: 'Name not recorded') ?></dd>
        <dt>Destination</dt><dd><?= h($visit['destination']) ?></dd>
        <dt>Purpose</dt><dd><?= h($visit['purpose']) ?></dd>
        <dt>Entrance</dt><dd><?= h($visit['entrance_name']) ?></dd>
        <dt>Entry time</dt><dd><?= h($entryTime !== '' ? $formatVisitTime($entryTime) : '—') ?></dd>
        <dt>Check-out time</dt><dd><?= h($exitTime !== '' ? $formatVisitTime($exitTime) : 'Not checked out') ?></dd>
        <?php if (!empty($visit['exit_entrance_name'])): ?><dt>Exit entrance</dt><dd><?= h($visit['exit_entrance_name']) ?></dd><?php endif; ?>
      </div>
      <div class="notice"><strong>School pass process remains separate.</strong><p>This is a SafeGate visitor record only. Use the school's existing visitor-pass and flap-barrier process.</p></div>
    </div>
  </section>
</section>
