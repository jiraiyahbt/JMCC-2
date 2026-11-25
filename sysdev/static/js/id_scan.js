"use strict";

document.addEventListener("DOMContentLoaded", () => {
  const video = document.getElementById("idVideo");
  const startButton = document.getElementById("startCamera");
  const captureButton = document.getElementById("captureId");
  const chooseButton = document.getElementById("chooseImage");
  const stopButton = document.getElementById("stopCamera");
  const imageInput = document.getElementById("idImageInput");
  const viewfinder = document.getElementById("idViewfinder");
  const ocrText = document.getElementById("ocrRawOutput");
  const cameraMessage = document.getElementById("cameraMessage");
  const scanMessage = document.getElementById("scanMessage");
  const progress = document.getElementById("ocrProgress");

  if (!video || !startButton || !captureButton || !chooseButton || !stopButton ||
      !imageInput || !viewfinder || !ocrText || !cameraMessage || !scanMessage || !progress) {
    return;
  }

  let stream = null;
  let isScanning = false;

  function syncControls() {
    ocrText.disabled = isScanning;
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
      const text = result.data.text.replace(/\r\n?/g, "\n");
      ocrText.value = text;
      scanMessage.textContent = text
        ? "Text recognized and copied into the visit form. Review or edit it before recording the visit."
        : "No text was recognized. Try a clearer, well-lit image or enter the details manually.";
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
