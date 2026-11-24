<?php
$title = 'Check in visitor · SafeGate';
$scannerPage = true;
$entrances = isset($entrances) && is_array($entrances) && $entrances ? $entrances : ENTRANCES;
$selectedEntrance = $entrance ?? null;
$entrance = in_array($selectedEntrance, $entrances, true)
  ? $selectedEntrance
  : ($entrances[0] ?? ENTRANCES[0]);
$existingFormData = isset($form_data) && is_array($form_data) ? $form_data : [];
$form_data = array_merge([
  'visitor_name' => '',
  'mobile_number' => '',
  'visitor_address' => '',
  'destination' => '',
  'purpose' => '',
], $existingFormData);
$errors = isset($errors) && is_array($errors) ? $errors : [];
$duplicates = isset($duplicates) && is_array($duplicates) ? $duplicates : [];
?>
<section class="narrow-page wide-narrow">
  <a class="back-link" href="<?= h(url('/guard', ['entrance' => $entrance])) ?>">← Back to visitor log</a>
  <p class="eyebrow">Gate operations / check in</p>
  <h1>Scan visitor ID</h1>
  <p class="lead">Capture the printed ID with the guard's device. OCR runs locally in this browser; the ID image is not uploaded or saved. Review and edit the recognized text below, then enter the visitor details into the visit form.</p>
  <?php if (!empty($errors)): ?><div class="flash flash-error"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <section class="card scanner-card">
    <div class="scanner-preview"><video id="idVideo" autoplay muted playsinline></video><div id="idViewfinder" class="id-viewfinder"><span class="id-viewfinder-hint">Align the full ID inside the frame</span></div><p id="cameraMessage">Start the camera, keep the full ID inside the frame, then capture.</p></div>
    <div class="form-actions">
      <button class="button button-secondary" id="startCamera" type="button">Open camera</button>
      <button class="button button-secondary" id="captureId" type="button" disabled>Capture and read ID</button>
      <button class="button button-secondary" id="chooseImage" type="button">Choose ID image</button>
      <button class="button button-secondary" id="stopCamera" type="button" disabled>Stop camera</button>
    </div>
    <input id="idImageInput" type="file" accept="image/*" capture="environment" hidden>
    <progress id="ocrProgress" max="100" value="0" hidden></progress>
    <p class="muted" id="scanMessage" aria-live="polite"></p>
    <label class="ocr-text-label" for="recognizedIdText">Recognized ID text (editable)
      <textarea id="recognizedIdText" rows="8" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="Recognized ID text. Edit this text as needed."></textarea>
    </label>
  </section>
  <form class="card form-card stack-form" id="checkInForm" method="post" action="<?= h(url('/guard/verify', ['entrance' => $entrance])) ?>">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="check_in">
    <h2>Review and record visit</h2>
    <p class="muted">OCR text is not copied into these record fields. Review it above and enter the visitor name, mobile number, address, person/office, and purpose here. Verify all details against the ID.</p>
    <label>Visitor name<input id="visitorName" name="visitor_name" maxlength="120" required autocomplete="off" value="<?= h($form_data['visitor_name']) ?>"></label>
    <label>Mobile number <span class="muted">(optional)</span><input id="mobileNumber" name="mobile_number" maxlength="20" autocomplete="tel" value="<?= h($form_data['mobile_number']) ?>"></label>
    <label>Address <span class="muted">(optional; verify against the ID)</span><textarea id="visitorAddress" name="visitor_address" maxlength="500" rows="2" autocomplete="street-address"><?= h($form_data['visitor_address']) ?></textarea></label>
    <label>Person or office visited<input name="destination" maxlength="100" required value="<?= h($form_data['destination']) ?>" autocomplete="off"></label>
    <label>Purpose of visit<input name="purpose" maxlength="255" required value="<?= h($form_data['purpose']) ?>" autocomplete="off"></label>
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
