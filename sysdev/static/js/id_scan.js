"use strict";

const ID_TEXT_LABELS = {
  lastName: ["Last Name", "Surname", "Apelyido"],
  givenNames: ["Given Names", "First Name", "First Names", "Mga Pangalan"],
  middleName: ["Middle Name", "Gitnang Apelyido"],
  address: [
    "Residential Address", "Permanent Address", "Present Address", "Current Address",
    "Home Address", "Residence Address", "Address", "Addresss", "Addres", "Adress",
    "Add ress", "A ddress", "Addr", "Tirahan",
  ],
  addressStops: [
    "Last Name", "Surname", "Given Names", "First Name", "First Names",
    "Middle Name", "Name",
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
  const inlinePattern = new RegExp("^\\s*(?:" + alternatives + ")\\s*[:\\-]\\s*(.*)$", "i");
  const barePattern = new RegExp("^\\s*(?:" + alternatives + ")\\s*[:\\-]?\\s*$", "i");
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
      if (inlineMatch[1].trim()) return inlineMatch[1].trim();
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
  const stopAlternatives = labelPattern(ID_TEXT_LABELS.addressStops);
  const stopPattern = new RegExp(
    "^\\s*(?:" + stopAlternatives + ")\\s*[:\\-]?.*$",
    "i"
  );
  const inlineStopPattern = new RegExp(
    "\\s+(?:" + stopAlternatives + ")\\s*[:\\-]?.*$",
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
    if (inlineValue) {
      addressLines.push(inlineValue);
    }
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
      if (!candidate || stopPattern.test(candidate)) break;
      if (addressCuePattern.test(candidate)) {
        addressLines.push(candidate);
      } else {
        break;
      }
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

function parsedFieldScore(text) {
  const parsed = parseIdText(text);
  return Number(Boolean(parsed.address)) * 100 +
    Math.min(parsed.address.length, 100) +
    Number(Boolean(parsed.name)) * 10 +
    Number(Boolean(parsed.mobile)) * 5;
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

  function applyParsedDetails(text) {
    const parsed = parseIdText(text);
    visitorName.value = parsed.name;
    visitorMobile.value = parsed.mobile;
    visitorAddress.value = parsed.address;

    const filledCount = [parsed.name, parsed.mobile, parsed.address].filter(Boolean).length;
    if (!parsed.address) {
      scanMessage.textContent = filledCount > 0
        ? "Filled " + filledCount + " of 3 fields. Address wasn't detected; enter it manually before recording."
        : "No name, mobile number, or address was detected. Enter the details manually before recording.";
      return;
    }
    scanMessage.textContent = "Filled " + filledCount + " of 3 fields. Please verify against the ID before saving.";
  }

  async function readImage(source) {
    if (isScanning || pageIsLeaving) return;
    clearDetectedDetails();
    if (!window.Tesseract || typeof window.Tesseract.createWorker !== "function") {
      scanMessage.textContent = "Text recognition could not be loaded. Refresh the page or enter the details manually.";
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
      let text = "";
      let bestScore = -1;
      for (let index = 0; index < ocrAttempts.length; index += 1) {
        const attempt = ocrAttempts[index];
        if (index > 0) progress.value = 5;
        await ocrWorker.setParameters({
          tessedit_pageseg_mode: attempt.mode,
          preserve_interword_spaces: "1",
        });
        const result = await ocrWorker.recognize(attempt.image);
        const candidateText = String(result.data.text || "").replace(/\r\n?/g, "\n").trim();
        const candidateScore = parsedFieldScore(candidateText);
        if (candidateScore > bestScore) {
          text = candidateText;
          bestScore = candidateScore;
        }

        const bestAddress = parseIdText(text).address;
        if (index === 1 && bestAddress.length < 20) {
          const binaryImage = makeBinaryOcrCanvas(preparedImage);
          ocrAttempts.push(
            { image: binaryImage, mode: "11" },
            { image: binaryImage, mode: "6" }
          );
        }
        if (index >= 1 && bestAddress.length >= 20) break;
      }

      applyParsedDetails(text);
    } catch (error) {
      if (!pageIsLeaving) {
        console.error("Could not read the visitor ID text.", error);
        scanMessage.textContent = "Could not read this ID. Try better lighting or enter the details manually.";
      }
    } finally {
      isScanning = false;
      progress.hidden = true;
      if (!pageIsLeaving) syncControls();
    }
  }

  startButton.addEventListener("click", async () => {
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
      scanMessage.textContent = "Camera access requires HTTPS or localhost. Choose an ID image or enter details manually.";
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
          : "Camera unavailable. Choose an image or enter details manually.";
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
