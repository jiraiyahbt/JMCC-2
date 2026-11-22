<?php $title = 'Visitor pass reference · SafeGate'; ?>
<section class="narrow-page">
  <a class="back-link" href="/guard">← Back to visitor log</a>
  <p class="eyebrow">Visitor pass reference</p>
  <section class="card pass-card">
    <div class="pass-heading">
      <h2>SafeGate visitor record</h2>
      <p><?= h($visit['status']) ?></p>
    </div>
    <div class="pass-body">
      <div id="visitorQr" aria-label="QR reference"></div>
      <p><strong><?= h($visit['pass_reference'] ?: 'Pass reference pending') ?></strong></p>
      <div class="detail-grid">
        <dt>Visitor</dt><dd><?= h($visit['visitor_name']) ?></dd>
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
  if (window.QRCode) {
    new QRCode(document.getElementById("visitorQr"), {
      text: <?= json_encode((string) ($visit['pass_reference'] ?: $visit['visit_id']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      width: 220,
      height: 220
    });
  }
});
</script>
