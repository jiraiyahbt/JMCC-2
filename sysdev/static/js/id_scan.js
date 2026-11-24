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
  const cameraMessage = document.getElementById("cameraMessage");
  const scanMessage = document.getElementById("scanMessage");
  const progress = document.getElementById("ocrProgress");

  if (!video || !startButton || !captureButton || !chooseButton || !stopButton ||
      !imageInput || !nameInput || !mobileInput || !addressInput ||
      !cameraMessage || !scanMessage || !progress) {
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
    context.filter = "grayscale(1) contrast(1.45)";
    context.drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);
    context.filter = "none";
    return canvas;
  }

  function extractName(lines) {
    const usable = lines.map((line) => line.replace(/\s+/g, " ").trim()).filter(Boolean);
    const labeled = usable.find((line) => /^(?:full\s+)?name\s*[:\-]\s*\S/i.test(line));
    if (labeled) {
      return labeled.replace(/^(?:full\s+)?name\s*[:\-]\s*/i, "").slice(0, 120);
    }
    const labelIndex = usable.findIndex((line) => /^(?:full\s+)?name\s*[:\-]?$/i.test(line));
    if (labelIndex >= 0 && usable[labelIndex + 1]) {
      return usable[labelIndex + 1].slice(0, 120);
    }

    const headerWords = /^(republic|philippines|identification|identity|card|license|licence|passport|national|government|date|birth|address|signature|surname|first|middle|name|sex|citizen|valid|until)\b/i;
    return usable.find((line) =>
      /^[A-Z][A-Z ,.'-]{3,}$/i.test(line) &&
      line.split(/\s+/).length >= 2 &&
      !headerWords.test(line)
    )?.slice(0, 120) || "";
  }

  function extractMobile(text) {
    const matches = text.match(/(?:\+?63|0)\s*9(?:[\s()-]*\d){9}/g) || [];
    for (const match of matches) {
      const digits = match.replace(/\D/g, "");
      if (digits.length === 11 && digits.startsWith("09")) return digits;
      if (digits.length === 12 && digits.startsWith("639")) return `+${digits}`;
    }
    return "";
  }

  function extractAddress(lines) {
    const usable = lines.map((line) => line.replace(/\s+/g, " ").trim()).filter(Boolean);
    const addressLabel = /^(?:(?:residential|present|permanent|current|kasalukuyang|permanenteng)\s+)?(?:home\s+)?(?:address|tirahan)\b\s*[:=\-]?\s*(.*)$/i;
    const nextFieldLabel = /^(?:full\s+name|name|surname|given\s+name|mobile|phone|tel|date\s+of\s+birth|birth\s+date|dob|petsa\s+ng\s+kapanganakan|sex|gender|kasarian|nationality|citizenship|nasyonalidad|id\s*(?:no|number)|license\s*(?:no|number)|expiry|expiration|valid\s+until)\b\s*[:=\-]?\s*/i;

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
    return "";
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
      const result = await window.Tesseract.recognize(source, "eng", {
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
      const lines = result.data.text.split(/\r?\n/);
      const detectedName = extractName(lines);
      const detectedMobile = extractMobile(result.data.text);
      const detectedAddress = extractAddress(lines);
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
        : "No name, mobile number, or labeled address was recognized. Fill in the fields and verify them against the ID.";
    } catch (error) {
      console.error("Could not read the visitor ID text.", error);
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
