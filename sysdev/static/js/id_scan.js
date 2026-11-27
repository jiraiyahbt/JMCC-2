"use strict";

const ID_TEXT_LABELS = {
  lastName: ["Last Name", "Surname", "Family Name", "Apelyido"],
  givenNames: ["Given Names", "Given Name", "First Name", "First Names", "Mga Pangalan"],
  middleName: ["Middle Name", "Middle Names", "Gitnang Apelyido"],
  address: [
    "Residential Address", "Permanent Address", "Present Address", "Current Address",
    "Home Address", "Residence Address", "Address", "Addresss", "Addres", "Adress",
    "Add ress", "A ddress", "Addr", "Tirahan",
  ],
  addressStops: [
    "Last Name", "Surname", "Family Name", "Given Names", "Given Name",
    "First Name", "First Names", "Middle Name", "Middle Names", "Name",
    "Birth Date", "Date of Birth", "DOB", "Petsa ng Kapanganakan",
    "Place of Birth", "Birth Place", "City of Birth",
    "Sex", "Gender", "Kasarian", "License Number", "License No",
    "Driver's License Number", "Driver's License No", "ID Number", "ID No",
    "Nationality", "Blood Type", "Height", "Weight", "Restriction Code",
    "Restrictions", "Conditions", "Expiration Date", "Expiry Date",
    "License Classification", "Date Issued", "Issue Date", "Civil Status",
  ],
};

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function labelPattern(labels) {
  return labels.map(escapeRegExp).join("|");
}

function readLabeledValue(lines, labels) {
  const alternatives = labelPattern(labels);
  const inlinePattern = new RegExp(
    "(?:^|\\s)(?:" + alternatives + ")(?:\\s*[:|\\-]\\s*|\\s+)(.+?)\\s*$",
    "i"
  );
  const barePattern = new RegExp("^\\s*(?:" + alternatives + ")\\s*[:|\\-]?\\s*$", "i");
  const nameLabels = labelPattern(
    ID_TEXT_LABELS.lastName.concat(ID_TEXT_LABELS.givenNames, ID_TEXT_LABELS.middleName)
  );
  const followingNameLabelPattern = new RegExp(
    "\\s+(?:" + nameLabels + ")(?=\\s|[:|\\-]|$)",
    "i"
  );
  const allLabels = labelPattern(
    ID_TEXT_LABELS.lastName.concat(
      ID_TEXT_LABELS.givenNames,
      ID_TEXT_LABELS.middleName,
      ID_TEXT_LABELS.address,
      ID_TEXT_LABELS.addressStops
    )
  );
  const anyLabelPattern = new RegExp("^\\s*(?:" + allLabels + ")\\s*[:\\-]?.*$", "i");

  for (let index = 0; index < lines.length; index += 1) {
    const inlineMatch = lines[index].match(inlinePattern);
    if (inlineMatch) {
      const value = inlineMatch[1].trim();
      const nextLabelIndex = value.search(followingNameLabelPattern);
      const inlineValue = nextLabelIndex === -1
        ? value
        : value.slice(0, nextLabelIndex).trim();
      if (inlineValue) return inlineValue;
      continue;
    }

    if (barePattern.test(lines[index])) {
      for (let nextIndex = index + 1; nextIndex < lines.length; nextIndex += 1) {
        const candidate = lines[nextIndex].trim();
        if (!candidate) continue;
        if (anyLabelPattern.test(candidate)) break;
        return candidate;
      }
    }
  }
  return "";
}

function toTitleCase(value) {
  return value
    .toLowerCase()
    .replace(/(^|[\s'-])([a-zà-öø-ÿ])/g, (match, separator, letter) =>
      separator + letter.toUpperCase()
    )
    .trim();
}

function extractCommaName(lines) {
  const nameLine = lines.find((line) =>
    /^[A-Za-zÀ-ÖØ-öø-ÿ][A-Za-zÀ-ÖØ-öø-ÿ .'-]*,\s*[A-Za-zÀ-ÖØ-öø-ÿ][A-Za-zÀ-ÖØ-öø-ÿ .'-]*$/.test(line)
  );
  if (!nameLine) return "";

  const parts = nameLine.split(",");
  const lastName = parts.shift().trim();
  const givenNames = parts.join(",").trim();
  return toTitleCase(givenNames + " " + lastName);
}

function extractMobile(text) {
  const pattern = /(^|[^0-9])(\+?63[\s-]?9(?:[\s-]?\d){9}|09(?:[\s-]?\d){9})(?!\d)/;
  const match = text.match(pattern);
  if (!match) return "";

  const digits = match[2].replace(/\D/g, "");
  const normalized = digits.indexOf("63") === 0
    ? "0" + digits.slice(2)
    : digits;
  return /^09\d{9}$/.test(normalized) ? normalized : "";
}

function extractAddress(lines) {
  const alternatives = labelPattern(ID_TEXT_LABELS.address);
  const addressLinePattern = new RegExp(
    "(?:^|\\s)(?:" + alternatives + ")(?:\\s*(?:/|[1lI|])\\s*(?:" + alternatives + "))?(.*)$",
    "i"
  );
  const stopPattern = new RegExp(
    "^\\s*(?:" + labelPattern(ID_TEXT_LABELS.addressStops) + ")\\s*[:\\-]?.*$",
    "i"
  );
  const inlineStopPattern = new RegExp(
    "\\s+(?:" + labelPattern(ID_TEXT_LABELS.addressStops) + ")\\s*[:\\-]?.*$",
    "i"
  );

  for (let index = 0; index < lines.length; index += 1) {
    const labelMatch = lines[index].match(addressLinePattern);
    if (!labelMatch) continue;

    const addressLines = [];
    let inlineValue = labelMatch[1].replace(/^\s*[:\-.,/|]?\s*/, "").trim();
    const inlineStopIndex = inlineValue.search(inlineStopPattern);
    if (inlineStopIndex !== -1) {
      inlineValue = inlineValue.slice(0, inlineStopIndex).trim();
    }
    if (inlineValue) addressLines.push(inlineValue);

    for (let nextIndex = index + 1; nextIndex < lines.length && addressLines.length < 3; nextIndex += 1) {
      let candidate = lines[nextIndex].trim();
      if (!candidate) continue;
      if (stopPattern.test(candidate)) break;
      const repeatedAddressLabel = candidate.match(addressLinePattern);
      if (repeatedAddressLabel) {
        candidate = repeatedAddressLabel[1].replace(/^\s*[:\-.,/|]?\s*/, "").trim();
        if (!candidate) continue;
      }
      const candidateStopIndex = candidate.search(inlineStopPattern);
      if (candidateStopIndex !== -1) {
        candidate = candidate.slice(0, candidateStopIndex).trim();
        if (candidate) addressLines.push(candidate);
        break;
      }
      addressLines.push(candidate);
    }
    const address = toTitleCase(addressLines.join(" "));
    if (address) return address;
  }
  return extractUnlabeledAddress(lines);
}

function extractUnlabeledAddress(lines) {
  const addressCuePattern = /\b(?:brgy|barangay|bgy|purok|sitio|subd|subdivision|village|phase|block|blk|lot|street|st|road|rd|avenue|ave|highway|hwy|district|city|municipality|province|postal|zip)\b/i;
  const stopPattern = new RegExp(
    "^\\s*(?:" + labelPattern(
      ID_TEXT_LABELS.lastName.concat(
        ID_TEXT_LABELS.givenNames,
        ID_TEXT_LABELS.middleName,
        ID_TEXT_LABELS.address,
        ID_TEXT_LABELS.addressStops
      )
    ) + ")\\s*[:\\-]?.*$",
    "i"
  );

  for (let index = 0; index < lines.length; index += 1) {
    const firstLine = lines[index].trim();
    if (!addressCuePattern.test(firstLine) || stopPattern.test(firstLine)) continue;

    const addressLines = [firstLine];
    for (let nextIndex = index + 1; nextIndex < lines.length && addressLines.length < 3; nextIndex += 1) {
      const candidate = lines[nextIndex].trim();
      if (!candidate || stopPattern.test(candidate) || !addressCuePattern.test(candidate)) break;
      addressLines.push(candidate);
    }
    return toTitleCase(addressLines.join(" "));
  }
  return "";
}

function parseIdText(text) {
  const lines = String(text || "")
    .replace(/\r\n?/g, "\n")
    .split("\n")
    .map((line) => line.replace(/\s+/g, " ").trim())
    .filter(Boolean);

  const lastName = readLabeledValue(lines, ID_TEXT_LABELS.lastName);
  const givenNames = readLabeledValue(lines, ID_TEXT_LABELS.givenNames);
  const middleName = readLabeledValue(lines, ID_TEXT_LABELS.middleName);
  const labeledName = [givenNames, middleName, lastName].filter(Boolean).join(" ");

  return {
    name: toTitleCase(labeledName || extractCommaName(lines)),
    mobile: extractMobile(lines.join("\n")),
    address: extractAddress(lines),
  };
}

function fieldCandidateScore(field, value, text, confidence, region) {
  const labels = field === "name"
    ? ID_TEXT_LABELS.lastName.concat(ID_TEXT_LABELS.givenNames, ID_TEXT_LABELS.middleName)
    : field === "address"
      ? ID_TEXT_LABELS.address
      : [];
  const hasFieldLabel = labels.length > 0 && new RegExp(
    "(?:^|\\s)(?:" + labelPattern(labels) + ")(?=\\s|[:|\\-]|$)",
    "i"
  ).test(text);
  const confidenceScore = Number.isFinite(confidence) ? confidence : 0;
  const regionScore = region === field ? 100 : 0;
  return confidenceScore + (hasFieldLabel ? 100 : 0) +
    Math.min(value.length, 100) + regionScore;
}

document.addEventListener("DOMContentLoaded", () => {
  const video = document.getElementById("idVideo");
  const viewfinder = document.getElementById("idViewfinder");
  const startButton = document.getElementById("startCamera");
  const captureButton = document.getElementById("captureId");
  const chooseButton = document.getElementById("chooseImage");
  const stopButton = document.getElementById("stopCamera");
  const imageInput = document.getElementById("idImageInput");
  const visitorName = document.getElementById("visitorName");
  const visitorMobile = document.getElementById("visitorMobile");
  const visitorAddress = document.getElementById("visitorAddress");
  const recordVisitForm = document.getElementById("recordVisitForm");
  const cameraMessage = document.getElementById("cameraMessage");
  const scanMessage = document.getElementById("scanMessage");
  const progress = document.getElementById("ocrProgress");
  const recordVisitButton = document.getElementById("recordVisitBtn");

  const requiredElements = {
    idVideo: video,
    idViewfinder: viewfinder,
    startCamera: startButton,
    captureId: captureButton,
    chooseImage: chooseButton,
    stopCamera: stopButton,
    idImageInput: imageInput,
    visitorName: visitorName,
    visitorMobile: visitorMobile,
    visitorAddress: visitorAddress,
    recordVisitForm: recordVisitForm,
    cameraMessage: cameraMessage,
    scanMessage: scanMessage,
    ocrProgress: progress,
    recordVisitBtn: recordVisitButton,
  };
  const missingElements = Object.keys(requiredElements).filter((id) => !requiredElements[id]);
  if (missingElements.length) {
    missingElements.forEach((id) => console.warn("ID scanner is missing required element #" + id + "."));
    return;
  }

  let stream = null;
  let isScanning = false;
  let cameraPending = false;
  let cameraRequestId = 0;
  let pageIsLeaving = false;
  let worker = null;
  let workerPromise = null;
  let workerTerminated = false;

  function syncControls() {
    startButton.disabled = isScanning || cameraPending || Boolean(stream);
    captureButton.disabled = isScanning || !stream;
    chooseButton.disabled = isScanning || cameraPending;
    stopButton.disabled = isScanning || !stream;
    recordVisitButton.disabled = isScanning;
  }

  function clearDetectedDetails() {
    visitorName.value = "";
    visitorMobile.value = "";
    visitorAddress.value = "";
  }

  function stopTracks(activeStream) {
    if (activeStream) {
      activeStream.getTracks().forEach((track) => track.stop());
    }
  }

  function stopCamera(message) {
    cameraRequestId += 1;
    cameraPending = false;
    stopTracks(stream);
    stream = null;
    video.srcObject = null;
    syncControls();
    if (message && !pageIsLeaving) cameraMessage.textContent = message;
  }

  function enhanceCanvas(canvas, context, image, sx, sy, sw, sh) {
    if ("filter" in context) {
      try {
        context.filter = "grayscale(1) contrast(1.45)";
        context.drawImage(image, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
        context.filter = "none";
        return;
      } catch (error) {
        context.filter = "none";
      }
    }

    context.drawImage(image, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
    const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
    const pixels = imageData.data;
    const contrast = 1.45;
    for (let index = 0; index < pixels.length; index += 4) {
      const gray = pixels[index] * 0.299 + pixels[index + 1] * 0.587 + pixels[index + 2] * 0.114;
      const enhanced = Math.max(0, Math.min(255, (gray - 128) * contrast + 128));
      pixels[index] = enhanced;
      pixels[index + 1] = enhanced;
      pixels[index + 2] = enhanced;
    }
    context.putImageData(imageData, 0, 0);
  }

  function makeBinaryOcrCanvas(sourceCanvas) {
    const canvas = document.createElement("canvas");
    canvas.width = sourceCanvas.width;
    canvas.height = sourceCanvas.height;
    const context = canvas.getContext("2d");
    if (!context) throw new Error("Image thresholding is unavailable in this browser.");
    context.drawImage(sourceCanvas, 0, 0);

    const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
    const pixels = imageData.data;
    const histogram = new Uint32Array(256);
    let totalIntensity = 0;
    const pixelCount = pixels.length / 4;
    for (let index = 0; index < pixels.length; index += 4) {
      const intensity = pixels[index];
      histogram[intensity] += 1;
      totalIntensity += intensity;
    }

    let backgroundCount = 0;
    let backgroundIntensity = 0;
    let maxVariance = -1;
    let threshold = 128;
    for (let level = 0; level < histogram.length; level += 1) {
      backgroundCount += histogram[level];
      if (!backgroundCount) continue;

      const foregroundCount = pixelCount - backgroundCount;
      if (!foregroundCount) break;
      backgroundIntensity += level * histogram[level];
      const backgroundMean = backgroundIntensity / backgroundCount;
      const foregroundMean = (totalIntensity - backgroundIntensity) / foregroundCount;
      const variance = backgroundCount * foregroundCount *
        (backgroundMean - foregroundMean) * (backgroundMean - foregroundMean);
      if (variance > maxVariance) {
        maxVariance = variance;
        threshold = level;
      }
    }

    for (let index = 0; index < pixels.length; index += 4) {
      const value = pixels[index] > threshold ? 255 : 0;
      pixels[index] = value;
      pixels[index + 1] = value;
      pixels[index + 2] = value;
    }
    context.putImageData(imageData, 0, 0);
    return canvas;
  }

  function buildFieldCrop(sourceCanvas, words, labelPattern, cuePattern, linesBelow, fieldName) {
    if (!Array.isArray(words)) return null;

    const matchedWord = words.find((word) => {
      const text = String(word.text || "").replace(/[^a-z]/gi, "");
      return labelPattern.test(text);
    }) || (cuePattern && words.find((word) => {
      const text = String(word.text || "").replace(/[^a-z]/gi, "");
      return cuePattern.test(text);
    }));
    if (!matchedWord || !matchedWord.bbox) return null;

    const y0 = Number(matchedWord.bbox.y0);
    const y1 = Number(matchedWord.bbox.y1);
    const lineHeight = y1 - y0;
    if (!Number.isFinite(y0) || !Number.isFinite(y1) || lineHeight <= 0) return null;

    const cropTop = Math.max(0, Math.floor(y0 - lineHeight * 2));
    const cropBottom = Math.min(
      sourceCanvas.height,
      Math.ceil(y1 + lineHeight * linesBelow)
    );
    const cropHeight = cropBottom - cropTop;
    if (cropHeight < lineHeight) return null;

    const scale = Math.min(2, 3400 / sourceCanvas.width);
    const canvas = document.createElement("canvas");
    canvas.width = Math.max(1, Math.round(sourceCanvas.width * scale));
    canvas.height = Math.max(1, Math.round(cropHeight * scale));
    const context = canvas.getContext("2d");
    if (!context) throw new Error(fieldName + " image enhancement is unavailable in this browser.");
    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = "high";
    enhanceCanvas(
      canvas,
      context,
      sourceCanvas,
      0,
      cropTop,
      sourceCanvas.width,
      cropHeight
    );
    return canvas;
  }

  function buildAddressCrop(sourceCanvas, words) {
    const labelPattern = /^(?:residential|permanent|present|current|home|residence|address|addresss|addres|adress|addr|tirahan)$/i;
    const cuePattern = /^(?:barangay|brgy|bgy|purok|sitio|subd|subdivision|village|phase|block|blk|lot|street|st|road|rd|avenue|ave|highway|hwy|district|city|municipality|province|postal|zip)$/i;
    return buildFieldCrop(sourceCanvas, words, labelPattern, cuePattern, 8, "Address");
  }

  function buildNameCrop(sourceCanvas, words) {
    const labelPattern = /^(?:last|surname|family|given|first|middle|apelyido|pangalan)$/i;
    return buildFieldCrop(sourceCanvas, words, labelPattern, null, 7, "Name");
  }

  function buildCroppedOcrCanvas() {
    const videoWidth = video.videoWidth;
    const videoHeight = video.videoHeight;
    if (!videoWidth || !videoHeight) return null;

    const videoRect = video.getBoundingClientRect();
    if (!videoRect.width || !videoRect.height) return null;

    const boxRect = viewfinder.getBoundingClientRect();
    const cropLeft = Math.max(boxRect.left, videoRect.left);
    const cropTop = Math.max(boxRect.top, videoRect.top);
    const cropRight = Math.min(boxRect.right, videoRect.right);
    const cropBottom = Math.min(boxRect.bottom, videoRect.bottom);
    if (cropRight <= cropLeft || cropBottom <= cropTop) return null;

    const coverScale = Math.max(
      videoRect.width / videoWidth,
      videoRect.height / videoHeight
    );
    const renderedWidth = videoWidth * coverScale;
    const renderedHeight = videoHeight * coverScale;
    const offsetX = (renderedWidth - videoRect.width) / 2;
    const offsetY = (renderedHeight - videoRect.height) / 2;

    const sx = Math.max(0, Math.ceil((cropLeft - videoRect.left + offsetX) / coverScale));
    const sy = Math.max(0, Math.ceil((cropTop - videoRect.top + offsetY) / coverScale));
    const ex = Math.min(videoWidth, Math.floor((cropRight - videoRect.left + offsetX) / coverScale));
    const ey = Math.min(videoHeight, Math.floor((cropBottom - videoRect.top + offsetY) / coverScale));
    const sw = ex - sx;
    const sh = ey - sy;
    if (sw <= 0 || sh <= 0) return null;

    const canvas = document.createElement("canvas");
    canvas.width = sw;
    canvas.height = sh;
    const context = canvas.getContext("2d");
    if (!context) return null;
    context.drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);
    return canvas;
  }

  async function loadImage(source) {
    if (source instanceof HTMLCanvasElement ||
        (typeof ImageBitmap !== "undefined" && source instanceof ImageBitmap)) {
      return { image: source, close: null };
    }

    if (typeof window.createImageBitmap === "function") {
      const bitmap = await window.createImageBitmap(source);
      return { image: bitmap, close: () => bitmap.close() };
    }

    const objectUrl = URL.createObjectURL(source);
    try {
      const image = await new Promise((resolve, reject) => {
        const element = new Image();
        element.onload = () => resolve(element);
        element.onerror = () => reject(new Error("The selected image could not be loaded."));
        element.src = objectUrl;
      });
      return { image, close: null };
    } finally {
      URL.revokeObjectURL(objectUrl);
    }
  }

  async function prepareOcrImage(source) {
    const loaded = await loadImage(source);
    try {
      const width = loaded.image.width || loaded.image.naturalWidth;
      const height = loaded.image.height || loaded.image.naturalHeight;
      if (!width || !height) throw new Error("The selected image has no readable dimensions.");

      const scale = Math.min(4, 2600 / Math.max(width, height));
      const canvas = document.createElement("canvas");
      canvas.width = Math.max(1, Math.round(width * scale));
      canvas.height = Math.max(1, Math.round(height * scale));
      const context = canvas.getContext("2d");
      if (!context) throw new Error("Image enhancement is unavailable in this browser.");
      context.imageSmoothingEnabled = true;
      context.imageSmoothingQuality = "high";
      enhanceCanvas(canvas, context, loaded.image, 0, 0, width, height);
      return canvas;
    } finally {
      if (loaded.close) loaded.close();
    }
  }

  function updateOcrProgress(event) {
    if (event.status === "recognizing text" && Number.isFinite(event.progress)) {
      progress.value = Math.max(0, Math.min(100, Math.round(5 + event.progress * 95)));
    }
  }

  async function getOcrWorker() {
    if (workerTerminated || pageIsLeaving) {
      throw new Error("The OCR worker has already been terminated.");
    }
    if (worker) return worker;
    if (workerPromise) return workerPromise;
    if (!window.Tesseract || typeof window.Tesseract.createWorker !== "function") {
      throw new Error("The local OCR engine could not be loaded.");
    }

    const pendingWorker = window.Tesseract.createWorker("eng", 1, {
      workerPath: "/static/vendor/tesseract/worker.min.js",
      corePath: "/static/vendor/tesseract/core/",
      langPath: "/static/vendor/tesseract/lang/",
      workerBlobURL: false,
      gzip: true,
      logger: updateOcrProgress,
    }).then(async (createdWorker) => {
      if (workerTerminated || pageIsLeaving) {
        await createdWorker.terminate();
        throw new Error("The OCR worker was stopped because the page is closing.");
      }
      worker = createdWorker;
      return createdWorker;
    });
    workerPromise = pendingWorker;

    try {
      return await pendingWorker;
    } catch (error) {
      if (workerPromise === pendingWorker) workerPromise = null;
      throw error;
    }
  }

  function applyParsedDetails(parsed) {
    visitorName.value = parsed.name;
    visitorMobile.value = parsed.mobile;
    visitorAddress.value = parsed.address;

    const missingFields = [];
    if (!parsed.name) missingFields.push("name");
    if (!parsed.address) missingFields.push("address");
    if (missingFields.length) {
      scanMessage.textContent = "Could not read the " + missingFields.join(" and ") +
        ". Retake the full ID scan with the card flat and in focus.";
      return;
    }
    scanMessage.textContent = parsed.mobile
      ? "Read name, mobile number, and address. Verify them against the ID before saving."
      : "Read name and address. The mobile number wasn't detected; verify the details before saving.";
  }

  async function readImage(source) {
    if (isScanning || pageIsLeaving) return;
    clearDetectedDetails();
    if (!window.Tesseract || typeof window.Tesseract.createWorker !== "function") {
      scanMessage.textContent = "Text recognition could not be loaded. Refresh the page before scanning.";
      return;
    }

    isScanning = true;
    syncControls();
    scanMessage.textContent = "Reading printed ID text locally in this browser…";
    progress.hidden = false;
    progress.value = 0;

    try {
      const preparedImage = await prepareOcrImage(source);
      const ocrAttempts = [
        { image: preparedImage, mode: "11" },
        { image: preparedImage, mode: "6" },
      ];
      progress.value = 5;
      const ocrWorker = await getOcrWorker();
      const bestFields = {
        name: { value: "", score: -1 },
        mobile: { value: "", score: -1 },
        address: { value: "", score: -1 },
      };
      const considerCandidate = (candidateText, confidence, region) => {
        const candidateFields = parseIdText(candidateText);
        for (const field of ["name", "mobile", "address"]) {
          const value = candidateFields[field];
          if (!value) continue;

          const score = fieldCandidateScore(field, value, candidateText, confidence, region);
          if (score > bestFields[field].score) {
            bestFields[field] = { value: value, score: score };
          }
        }
      };
      const recognizedWords = [];
      for (let index = 0; index < ocrAttempts.length; index += 1) {
        const attempt = ocrAttempts[index];
        if (index > 0) progress.value = 5;
        await ocrWorker.setParameters({
          tessedit_pageseg_mode: attempt.mode,
          preserve_interword_spaces: "1",
        });
        const result = await ocrWorker.recognize(attempt.image);
        if (Array.isArray(result.data.words)) {
          for (const word of result.data.words) recognizedWords.push(word);
        }
        const candidateText = String(result.data.text || "").replace(/\r\n?/g, "\n").trim();
        considerCandidate(candidateText, Number(result.data.confidence), "");

        if (index === 1 && bestFields.address.value.length < 20) {
          const binaryImage = makeBinaryOcrCanvas(preparedImage);
          ocrAttempts.push(
            { image: binaryImage, mode: "11" },
            { image: binaryImage, mode: "6" }
          );
        }
        if (index >= 1 && bestFields.address.value.length >= 20 && bestFields.name.value) break;
      }

      if (bestFields.address.value.length < 20) {
        const addressCrop = buildAddressCrop(preparedImage, recognizedWords);
        if (addressCrop) {
          const cropAttempts = [
            { image: addressCrop, mode: "6" },
            { image: addressCrop, mode: "11" },
          ];
          for (let index = 0; index < cropAttempts.length; index += 1) {
            progress.value = 5;
            await ocrWorker.setParameters({
              tessedit_pageseg_mode: cropAttempts[index].mode,
              preserve_interword_spaces: "1",
            });
            const result = await ocrWorker.recognize(cropAttempts[index].image);
            const candidateText = String(result.data.text || "").replace(/\r\n?/g, "\n").trim();
            considerCandidate(candidateText, Number(result.data.confidence), "address");
            if (bestFields.address.value.length >= 20) break;
          }

          if (bestFields.address.value.length < 20) {
            const binaryAddressCrop = makeBinaryOcrCanvas(addressCrop);
            progress.value = 5;
            await ocrWorker.setParameters({
              tessedit_pageseg_mode: "6",
              preserve_interword_spaces: "1",
            });
            const result = await ocrWorker.recognize(binaryAddressCrop);
            const candidateText = String(result.data.text || "").replace(/\r\n?/g, "\n").trim();
            considerCandidate(candidateText, Number(result.data.confidence), "address");
          }
        }
      }

      if (!bestFields.name.value) {
        const nameCrop = buildNameCrop(preparedImage, recognizedWords);
        if (nameCrop) {
          for (const mode of ["6", "11"]) {
            progress.value = 5;
            await ocrWorker.setParameters({
              tessedit_pageseg_mode: mode,
              preserve_interword_spaces: "1",
            });
            const result = await ocrWorker.recognize(nameCrop);
            const candidateText = String(result.data.text || "").replace(/\r\n?/g, "\n").trim();
            considerCandidate(candidateText, Number(result.data.confidence), "name");
            if (bestFields.name.value) break;
          }
        }
      }

      applyParsedDetails({
        name: bestFields.name.value,
        mobile: bestFields.mobile.value,
        address: bestFields.address.value,
      });
    } catch (error) {
      if (!pageIsLeaving) {
        console.error("Could not read the visitor ID text.", error);
        scanMessage.textContent = "Could not read this ID. Try a clear, straight-on image and scan again.";
      }
    } finally {
      isScanning = false;
      progress.hidden = true;
      if (!pageIsLeaving) syncControls();
    }
  }

  recordVisitForm.addEventListener("submit", (event) => {
    if (!visitorAddress.value.trim()) {
      event.preventDefault();
      scanMessage.textContent = "The address must be detected by scanning the ID. Retake the scan before recording the visit.";
      chooseButton.focus();
    }
  });

  startButton.addEventListener("click", async () => {
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
      scanMessage.textContent = "Camera access requires HTTPS or localhost. Choose a clear ID image to scan.";
      return;
    }

    const requestId = ++cameraRequestId;
    cameraPending = true;
    syncControls();
    cameraMessage.textContent = "Allow camera access, then frame the ID.";
    try {
      const requestedStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: "environment" } },
        audio: false,
      });
      if (requestId !== cameraRequestId || document.hidden || pageIsLeaving) {
        stopTracks(requestedStream);
        return;
      }

      stream = requestedStream;
      video.srcObject = stream;
      await video.play();
      if (requestId === cameraRequestId && !document.hidden && !pageIsLeaving) {
        cameraMessage.textContent = "Keep the ID flat and readable, then capture.";
      } else {
        stopCamera("Camera stopped because the page is hidden.");
      }
    } catch (error) {
      if (requestId === cameraRequestId && !pageIsLeaving) {
        console.error("Could not start the visitor ID camera.", error);
        stopCamera();
        scanMessage.textContent = error.name === "NotAllowedError"
          ? "Camera permission was denied. Allow it in browser settings or choose an image."
          : "Camera unavailable. Choose a clear ID image to scan.";
      }
    } finally {
      if (requestId === cameraRequestId && !pageIsLeaving) {
        cameraPending = false;
        syncControls();
      }
    }
  });

  captureButton.addEventListener("click", () => {
    const canvas = buildCroppedOcrCanvas();
    if (!canvas) {
      scanMessage.textContent = "Wait for the camera image, then try capturing again.";
      return;
    }
    readImage(canvas);
  });

  chooseButton.addEventListener("click", () => imageInput.click());
  imageInput.addEventListener("change", () => {
    const image = imageInput.files?.[0];
    if (image) readImage(image);
    imageInput.value = "";
  });
  stopButton.addEventListener("click", () => {
    stopCamera("Camera is off. You can choose an ID image instead.");
    scanMessage.textContent = "Camera stopped.";
  });

  document.addEventListener("visibilitychange", () => {
    if (document.hidden) {
      stopCamera("Camera is off while this page is hidden.");
    }
  });

  window.addEventListener("pagehide", () => {
    pageIsLeaving = true;
    workerTerminated = true;
    stopCamera();
    if (worker) {
      const workerToTerminate = worker;
      worker = null;
      workerPromise = null;
      workerToTerminate.terminate().catch((error) => {
        console.error("Could not terminate the local OCR worker.", error);
      });
    }
  });

  window.addEventListener("pageshow", (event) => {
    if (event.persisted) {
      pageIsLeaving = false;
      workerTerminated = false;
      syncControls();
    }
  });
});
