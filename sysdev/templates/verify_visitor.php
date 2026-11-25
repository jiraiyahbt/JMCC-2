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
  'visitor_id_text' => '',
  'destination' => '',
  'purpose' => '',
], $existingFormData);
$errors = isset($errors) && is_array($errors) ? $errors : [];
?>
<section class="narrow-page wide-narrow">
  <a class="back-link" href="<?= h(url('/guard', ['entrance' => $entrance])) ?>">← Back to visitor log</a>
  <p class="eyebrow">Gate operations / check in</p>
  <h1>Scan visitor ID</h1>
  <p class="lead">Capture the printed ID with the guard's device. OCR runs locally in this browser; the image is not uploaded. All recognized text is copied into the visit form for review and is saved with the visit record.</p>
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
  </section>
  <form class="card form-card stack-form" id="recordVisitForm" method="POST" action="<?= h(url('/record_visit.php', ['entrance' => $entrance])) ?>">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="check_in">
    <h2>Review and record visit</h2>
    <p class="muted">Review or edit the complete OCR text before submitting. It is saved with the visit record and may contain sensitive ID details. Enter the person/office and purpose below.</p>
    <label class="ocr-text-label" for="ocrRawOutput">Complete text recognized from the ID (editable; saved with the visit)
      <textarea id="ocrRawOutput" name="id_details" rows="8" required autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="Complete text recognized from the ID. Edit this text as needed."><?= h($form_data['visitor_id_text']) ?></textarea>
    </label>
    <label for="personVisited">Person or office visited<input id="personVisited" name="person_visited" maxlength="100" required value="<?= h($form_data['destination']) ?>" autocomplete="off"></label>
    <label for="purposeOfVisit">Purpose of visit<input id="purposeOfVisit" name="purpose_of_visit" maxlength="255" required value="<?= h($form_data['purpose']) ?>" autocomplete="off"></label>
    <label for="entranceGate">Entrance<select id="entranceGate" name="entrance" required><?php foreach (($entrances ?? [$entrance]) as $item): ?><option value="<?= h($item) ?>" <?= $item === $entrance ? 'selected' : '' ?>><?= h($item) ?></option><?php endforeach; ?></select></label>
    <div class="notice"><strong>School pass</strong><p>Continue the school's existing visitor-pass and flap-barrier process separately. SafeGate only records the visit and does not issue or scan the school's pass.</p></div>
    <label class="check-row"><input type="checkbox" name="id_details_confirmed" value="yes" required><span>I reviewed the complete OCR text against the physical ID.</span></label>
    <button class="button button-primary" id="recordVisitBtn" type="submit">Record Visit</button>
  </form>
</section>
