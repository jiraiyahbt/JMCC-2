<?php
$title = 'Visitor pass reference · SafeGate';
$visit = array_merge([
  'visit_id' => '',
  'pass_reference' => '',
  'visitor_name' => '',
  'visitor_id_text' => '',
  'destination' => '',
  'purpose' => '',
  'entrance_name' => '',
  'entry_time' => '',
  'status' => 'Unknown',
], is_array($visit ?? null) ? $visit : []);
$qrValue = (string) (($visit['pass_reference'] ?? '') ?: ($visit['visit_id'] ?? ''));
?>
<section class="narrow-page">
  <a class="back-link" href="/guard">← Back to visitor log</a>
  <p class="eyebrow">Visitor pass reference</p>
  <section class="card pass-card">
    <div class="pass-heading">
      <h2>SafeGate visitor record</h2>
      <p><?= h($visit['status']) ?></p>
    </div>
    <div class="pass-body">
      <?php if ($qrValue !== ''): ?>
        <div id="visitorQr" aria-label="QR reference"></div>
      <?php else: ?>
        <p class="notice">QR reference is unavailable for this visit.</p>
      <?php endif; ?>
      <p><strong><?= h($visit['pass_reference'] ?: 'Pass reference pending') ?></strong></p>
      <div class="detail-grid">
        <dt>Visitor</dt><dd><?= h(($visit['visitor_name'] ?? '') ?: 'ID details recorded') ?></dd>
        <?php if (!empty($visit['visitor_id_text'])): ?><dt>ID details</dt><dd><details><summary>View saved OCR text</summary><pre class="ocr-record-text"><?= h($visit['visitor_id_text']) ?></pre></details></dd><?php endif; ?>
        <dt>Destination</dt><dd><?= h($visit['destination']) ?></dd>
        <dt>Purpose</dt><dd><?= h($visit['purpose']) ?></dd>
        <dt>Entrance</dt><dd><?= h($visit['entrance_name']) ?></dd>
        <dt>Entry time</dt><dd><?= h($visit['entry_time']) ?></dd>
      </div>
      <div class="notice"><strong>School pass process remains separate.</strong><p>This reference identifies the SafeGate record only. Use the school's existing visitor-pass and flap-barrier process.</p></div>
    </div>
  </section>
</section>
<script>
window.addEventListener("DOMContentLoaded", function () {
  const target = document.getElementById("visitorQr");
  if (target && window.QRCode) {
    new QRCode(document.getElementById("visitorQr"), {
      text: <?= json_encode($qrValue, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>,
      width: 220,
      height: 220
    });
  }
});
</script>
