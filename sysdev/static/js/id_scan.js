"use strict";

document.addEventListener("DOMContentLoaded", () => {
  const video = document.getElementById("idVideo");
  const startButton = document.getElementById("startCamera");
  const captureButton = document.getElementById("captureId");
  const chooseButton = document.getElementById("chooseImage");
  const stopButton = document.getElementById("stopCamera");
  const imageInput = document.getElementById("idImageInput");
  const viewfinder = document.getElementById("idViewfinder");
  const nameInput = document.getElementById("visitorName");
  const mobileInput = document.getElementById("mobileNumber");
  const addressInput = document.getElementById("visitorAddress");
  const ocrTextDetails = document.getElementById("ocrTextDetails");
  const ocrText = document.getElementById("recognizedIdText");
  const cameraMessage = document.getElementById("cameraMessage");
  const scanMessage = document.getElementById("scanMessage");
  const progress = document.getElementById("ocrProgress");

  if (!video || !startButton || !captureButton || !chooseButton || !stopButton ||
      !imageInput || !nameInput || !mobileInput || !addressInput ||
      !ocrTextDetails || !ocrText || !cameraMessage || !scanMessage || !progress) {
    return;
  }

  let stream = null;
  let isScanning = false;

  function syncControls() {
    if (isScanning) {
      startButton.disabled = true;
      captureButton.disabled = true;
      chooseButton.disabled = true;
      stopButton.disabled = true;
      return;
    }

    const hasStream = Boolean(stream);
    startButton.disabled = hasStream;
    captureButton.disabled = !hasStream;
    stopButton.disabled = !hasStream;
    chooseButton.disabled = false;
  }

  function stopCamera() {
    if (stream) {
      stream.getTracks().forEach((track) => track.stop());
      stream = null;
    }
    video.srcObject = null;
    syncControls();
    cameraMessage.textContent = "Camera is off. You can choose an ID image instead.";
  }

  function buildCroppedOcrCanvas() {
    const videoWidth = video.videoWidth;
    const videoHeight = video.videoHeight;
    if (!videoWidth || !videoHeight) return null;

    const videoRect = video.getBoundingClientRect();
    if (!videoRect.width || !videoRect.height) return null;

    const boxRect = viewfinder
      ? viewfinder.getBoundingClientRect()
      : {
          left: videoRect.left + videoRect.width * 0.12,
          top: videoRect.top + videoRect.height * 0.22,
          width: videoRect.width * 0.76,
          height: videoRect.height * 0.56,
        };

    const coverScale = Math.max(
      videoRect.width / videoWidth,
      videoRect.height / videoHeight
    );
    const renderedWidth = videoWidth * coverScale;
    const renderedHeight = videoHeight * coverScale;
    const offsetX = (renderedWidth - videoRect.width) / 2;
    const offsetY = (renderedHeight - videoRect.height) / 2;

    const sx = Math.max(0, Math.min(
      videoWidth - 1,
      Math.round((boxRect.left - videoRect.left + offsetX) / coverScale)
    ));
    const sy = Math.max(0, Math.min(
      videoHeight - 1,
      Math.round((boxRect.top - videoRect.top + offsetY) / coverScale)
    ));
    const sw = Math.max(1, Math.min(videoWidth - sx, Math.round(boxRect.width / coverScale)));
    const sh = Math.max(1, Math.min(videoHeight - sy, Math.round(boxRect.height / coverScale)));

    const canvas = document.createElement("canvas");
    canvas.width = sw;
    canvas.height = sh;
    const context = canvas.getContext("2d");
    if (!context) return null;
    context.drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);
    return canvas;
  }

  function extractName(lines) {
    const usable = lines.map((line) => line.replace(/\s+/g, " ").trim()).filter(Boolean);
    const labeled = usable.find((line) => /^(?:(?:full|complete)\s+)?name\s*[:=\-]\s*\S/i.test(line));
    if (labeled) {
      return labeled.replace(/^(?:(?:full|complete)\s+)?name\s*[:=\-]\s*/i, "").slice(0, 120);
    }
    const labelIndex = usable.findIndex((line) => /^(?:(?:full|complete)\s+)?name\s*[:=\-]?$/i.test(line));
    if (labelIndex >= 0 && usable[labelIndex + 1]) {
      return usable[labelIndex + 1].slice(0, 120);
    }

    const headerWords = /^(republic|philippines|identification|identity|card|license|licence|passport|national|government|date|birth|address|signature|surname|first|middle|name|sex|citizen|valid|until|mobile|phone|contact|number|nationality|citizenship)\b/i;
    const nameParts = {};
    for (let index = 0; index < usable.length; index += 1) {
      const part = usable[index].match(/^(surname|last name|given name|first name|middle name)\s*[:=\-]?\s*(.*)$/i);
      if (!part) continue;
      const value = part[2] || usable[index + 1] || "";
      if (value && !headerWords.test(value)) nameParts[part[1].toLowerCase()] = value;
    }
    const composedName = [
      nameParts["given name"] || nameParts["first name"],
      nameParts["middle name"],
      nameParts.surname || nameParts["last name"],
    ].filter(Boolean).join(" ");
    if (composedName) return composedName.slice(0, 120);

    return usable.find((line) =>
      /^[A-ZÀ-ÖØ-Þ][A-ZÀ-ÖØ-Þ ,.'-]{3,}$/i.test(line) &&
      line.split(/\s+/).length >= 2 &&
      !headerWords.test(line)
    )?.slice(0, 120) || "";
  }

  function extractMobile(text) {
    const lines = text.split(/\r?\n/);
    const mobileLabel = /\b(?:mobile|cell(?:ular)?|phone|telephone|contact|tel\.?|cp|number|no\.?)\b/i;
    const orderedLines = [
      ...lines.filter((line) => mobileLabel.test(line)),
      ...lines.filter((line) => !mobileLabel.test(line)),
    ];

    for (const line of orderedLines) {
      const candidates = line.match(/[+()0-9OoQqIl|.\s-]{9,28}/g) || [];
      for (const candidate of candidates) {
        const digits = candidate
          .replace(/[OoQq]/g, "0")
          .replace(/[Il|]/g, "1")
          .replace(/\D/g, "");
        if (digits.length === 11 && digits.startsWith("09")) return digits;
        if (digits.length === 12 && digits.startsWith("639")) return `+${digits}`;
        if (digits.length === 10 && digits.startsWith("9")) return `0${digits}`;
      }
    }
    return "";
  }

  function extractAddress(lines) {
    const usable = lines.map((line) => line.replace(/\s+/g, " ").trim()).filter(Boolean);
    const addressLabel = /^(?:(?:residential|present|permanent|current|home|residence|mailing|kasalukuyang|permanenteng)\s+)*(?:address|addr\.?|tirahan|residing\s+at)\b\s*[:=\-]?\s*(.*)$/i;
    const nextFieldLabel = /^(?:full\s+name|complete\s+name|name|surname|last\s+name|given\s+name|first\s+name|middle\s+name|mobile|cell(?:ular)?|phone|contact|tel|cp|date\s+of\s+birth|birth\s+date|dob|petsa\s+ng\s+kapanganakan|sex|gender|kasarian|nationality|citizenship|nasyonalidad|id\s*(?:no|number)|license\s*(?:no|number)|expiry|expiration|valid\s+until)\b\s*[:=\-]?\s*/i;

    for (let index = 0; index < usable.length; index += 1) {
      const match = usable[index].match(addressLabel);
      if (!match) continue;

      const parts = match[1] ? [match[1]] : [];
      for (let next = index + 1; next < usable.length; next += 1) {
        if (addressLabel.test(usable[next]) || nextFieldLabel.test(usable[next])) break;
        parts.push(usable[next]);
      }

      return parts.join(", ").replace(/[\u0000-\u001f\u007f]/g, "").slice(0, 500);
    }

    const addressClue = /\b(?:brgy|barangay|purok|sitio|street|st|road|rd|avenue|ave|subdivision|village|municipality|city|province|district|zip)\b\.?/i;
    const addressIndex = usable.findIndex((line) =>
      addressClue.test(line) && !nextFieldLabel.test(line) &&
      !/^(?:republic|philippines|national|government|identification)\b/i.test(line)
    );
    if (addressIndex >= 0) {
      const parts = [usable[addressIndex]];
      for (let next = addressIndex + 1; next < usable.length; next += 1) {
        if (addressLabel.test(usable[next]) || nextFieldLabel.test(usable[next])) break;
        if (!addressClue.test(usable[next])) break;
        parts.push(usable[next]);
      }
      return parts.join(", ").slice(0, 500);
    }
    return "";
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

      const scale = Math.min(3, 2400 / Math.max(width, height));
      const canvas = document.createElement("canvas");
      canvas.width = Math.max(1, Math.round(width * scale));
      canvas.height = Math.max(1, Math.round(height * scale));
      const context = canvas.getContext("2d");
      if (!context) throw new Error("Image enhancement is unavailable in this browser.");
      context.imageSmoothingEnabled = true;
      context.imageSmoothingQuality = "high";
      context.filter = "grayscale(1) contrast(1.45)";
      context.drawImage(loaded.image, 0, 0, canvas.width, canvas.height);
      context.filter = "none";
      return canvas;
    } finally {
      if (loaded.close) loaded.close();
    }
  }

  async function readImage(source) {
    if (isScanning) return;
    if (!window.Tesseract?.recognize) {
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
      const result = await window.Tesseract.recognize(preparedImage, "eng", {
        workerPath: "/static/vendor/tesseract/worker.min.js",
        corePath: "/static/vendor/tesseract/core/",
        langPath: "/static/vendor/tesseract/lang/",
        workerBlobURL: false,
        gzip: true,
        logger: (event) => {
          if (event.status === "recognizing text" && Number.isFinite(event.progress)) {
            progress.value = Math.round(event.progress * 100);
          }
        },
      });
      const text = result.data.text.replace(/\u0000/g, "").trim();
      const lines = text.split(/\r?\n/);
      const detectedName = extractName(lines);
      const detectedMobile = extractMobile(text);
      const detectedAddress = extractAddress(lines);
      ocrText.value = text.slice(0, 4000);
      ocrTextDetails.hidden = !ocrText.value;
      if (detectedName) nameInput.value = detectedName;
      if (detectedMobile) mobileInput.value = detectedMobile;
      if (detectedAddress) addressInput.value = detectedAddress;
      const found = [
        detectedName && "name",
        detectedMobile && "mobile number",
        detectedAddress && "address",
      ].filter(Boolean);
      scanMessage.textContent = found.length
        ? `Suggested ${found.join(", ")}. Verify and correct the fields before saving.`
        : text
          ? "ID text was read, but the visitor details could not be matched automatically. Review the recognized text and fill in the fields."
          : "No text was recognized. Try a clearer, well-lit image or enter the details manually.";
    } catch (error) {
      console.error("Could not read the visitor ID text.", error);
      ocrTextDetails.hidden = true;
      scanMessage.textContent = "Could not read this ID. Try better lighting or enter the details manually.";
    } finally {
      isScanning = false;
      progress.hidden = true;
      syncControls();
    }
  }

  startButton.addEventListener("click", async () => {
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
      scanMessage.textContent = "Camera access requires HTTPS or localhost. Choose an ID image or enter details manually.";
      return;
    }
    startButton.disabled = true;
    cameraMessage.textContent = "Allow camera access, then frame the ID.";
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: "environment" } },
        audio: false,
      });
      video.srcObject = stream;
      await video.play();
      syncControls();
      cameraMessage.textContent = "Keep the ID flat and readable, then capture.";
    } catch (error) {
      console.error("Could not start the visitor ID camera.", error);
      stopCamera();
      scanMessage.textContent = error.name === "NotAllowedError"
        ? "Camera permission was denied. Allow it in browser settings or choose an image."
        : "Camera unavailable. Choose an image or enter details manually.";
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
    stopCamera();
    scanMessage.textContent = "Camera stopped.";
  });
  window.addEventListener("pagehide", stopCamera);
});
