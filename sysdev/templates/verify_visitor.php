<?php $title = 'Check in visitor · SafeGate'; $scannerPage = true; ?>
<section class="narrow-page wide-narrow">
  <a class="back-link" href="<?= h(url('/guard', ['entrance' => $entrance])) ?>">← Back to visitor log</a>
  <p class="eyebrow">Gate operations / check in</p>
  <h1>Scan visitor ID</h1>
  <p class="lead">Capture the ID with the guard's device. Text recognition runs in this browser; the ID image is not uploaded or saved. Review the suggested name and mobile before recording the visit.</p>
  <?php if (!empty($errors)): ?><div class="flash flash-error"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <section class="card scanner-card">
    <div class="scanner-preview"><video id="idVideo" autoplay muted playsinline></video><p id="cameraMessage">Open the camera, frame the ID, then capture it to read printed text.</p></div>
    <div class="form-actions">
      <button class="button button-secondary" id="startCamera" type="button">Open camera</button>
      <button class="button button-secondary" id="captureId" type="button" disabled>Capture and read ID</button>
      <button class="button button-secondary" id="chooseImage" type="button">Choose ID image</button>
      <button class="button button-secondary" id="stopCamera" type="button" disabled>Stop camera</button>
    </div>
    <input id="idImageInput" type="file" accept="image/*" capture="environment" hidden>
    <progress id="ocrProgress" max="100" value="0" hidden></progress>
    <p class="muted" id="scanMessage" aria-live="polite"></p>
  </section>
  <form class="card form-card stack-form" id="checkInForm" method="post" action="<?= h(url('/guard/verify', ['entrance' => $entrance])) ?>">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="check_in">
    <h2>Review and record visit</h2>
    <p class="muted">OCR suggestions can be incorrect. Verify them against the ID. The person/office and purpose are not on the ID, so the guard must enter those.</p>
    <label>Visitor name<input id="visitorName" name="visitor_name" maxlength="120" required autocomplete="off" value="<?= h($form_data['visitor_name'] ?? '') ?>"></label>
    <label>Mobile number <span class="muted">(optional)</span><input id="mobileNumber" name="mobile_number" maxlength="20" autocomplete="tel" value="<?= h($form_data['mobile_number'] ?? '') ?>"></label>
    <label>Person or office visited<input name="destination" maxlength="100" required value="<?= h($form_data['destination'] ?? '') ?>" autocomplete="off"></label>
    <label>Purpose of visit<input name="purpose" maxlength="255" required value="<?= h($form_data['purpose'] ?? '') ?>" autocomplete="off"></label>
    <label>Entrance<select name="entrance" required><?php foreach (($entrances ?? [$entrance]) as $item): ?><option value="<?= h($item) ?>" <?= $item === $entrance ? 'selected' : '' ?>><?= h($item) ?></option><?php endforeach; ?></select></label>
    <?php if (!empty($duplicates)): ?>
      <div class="notice">
        <strong>Possible duplicate visitor</strong>
        <p>This visitor already has <?= count($duplicates) ?> active visit(s). Confirm that this is a separate visit before continuing.</p>
      </div>
      <label class="check-row"><input type="checkbox" name="duplicate_confirmed" value="yes" required><span>I confirmed that this is a separate visit.</span></label>
    <?php endif; ?>
    <div class="notice"><strong>School pass</strong><p>Continue the school's existing visitor-pass and flap-barrier process separately. SafeGate only records the visit and does not issue or scan the school's pass.</p></div>
    <label class="check-row"><input type="checkbox" name="identity_confirmed" value="yes" required><span>I checked the suggested name and mobile against the physical ID.</span></label>
    <button class="button button-primary" type="submit">Record check-in</button>
  </form>
</section>
