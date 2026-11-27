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
?>
<section class="narrow-page wide-narrow">
  <a class="back-link" href="<?= h(url('/guard', ['entrance' => $entrance])) ?>">← Back to visitor log</a>
  <p class="eyebrow">Gate operations / check in</p>
  <h1>Scan visitor ID</h1>
  <p class="lead">Capture the printed ID with the guard's device. OCR runs locally in this browser to fill the visitor name, mobile number, and address. The required address must be detected by scanning; retake the image if it is not detected. The image is not uploaded.</p>
  <?php if (!empty($errors)): ?><div class="flash flash-error"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <section class="card scanner-card">
    <div class="scanner-preview"><video id="idVideo" autoplay muted playsinline></video><div id="idViewfinder" class="id-viewfinder"><span class="id-viewfinder-hint">Align the full ID inside the frame</span></div><p id="cameraMessage" aria-live="polite">Start the camera, keep the full ID inside the frame, then capture.</p></div>
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
    <p class="muted">Verify all detected details against the physical ID. Retake the scan if the required address is not detected.</p>
    <label for="visitorName">Visitor name<input id="visitorName" name="visitor_name" maxlength="120" required value="<?= h($form_data['visitor_name']) ?>" autocomplete="name"></label>
    <label for="visitorMobile">Mobile number <span class="muted">(optional)</span><input id="visitorMobile" name="mobile_number" type="tel" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" title="Enter an 11-digit Philippine mobile number beginning with 09." placeholder="09XXXXXXXXX" value="<?= h($form_data['mobile_number']) ?>" autocomplete="tel"></label>
    <label for="visitorAddress">Address <span class="muted">(required; filled by scan)</span><textarea id="visitorAddress" name="visitor_address" rows="3" maxlength="500" required readonly autocomplete="street-address"><?= h($form_data['visitor_address']) ?></textarea></label>
    <label for="personVisited">Person or office visited<input id="personVisited" name="person_visited" maxlength="100" required value="<?= h($form_data['destination']) ?>" autocomplete="off"></label>
    <label for="purposeOfVisit">Purpose of visit<input id="purposeOfVisit" name="purpose_of_visit" maxlength="255" required value="<?= h($form_data['purpose']) ?>" autocomplete="off"></label>
    <label for="entranceGate">Entrance<select id="entranceGate" name="entrance" required><?php foreach (($entrances ?? [$entrance]) as $item): ?><option value="<?= h($item) ?>" <?= $item === $entrance ? 'selected' : '' ?>><?= h($item) ?></option><?php endforeach; ?></select></label>
    <div class="notice"><strong>School pass</strong><p>Continue the school's existing visitor-pass and flap-barrier process separately. SafeGate only records the visit and does not issue or scan the school's pass.</p></div>
    <label class="check-row"><input type="checkbox" name="visitor_details_confirmed" value="yes" required><span>I reviewed the visitor name, mobile number, and address against the physical ID.</span></label>
    <button class="button button-primary" id="recordVisitBtn" type="submit">Record Visit</button>
  </form>
</section>
