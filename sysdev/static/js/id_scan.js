"use strict";

document.addEventListener("DOMContentLoaded", () => {
  const video = document.getElementById("idVideo");
  const startButton = document.getElementById("startCamera");
  const captureButton = document.getElementById("captureId");
  const chooseButton = document.getElementById("chooseImage");
  const stopButton = document.getElementById("stopCamera");
  const imageInput = document.getElementById("idImageInput");
  const nameInput = document.getElementById("visitorName");
  const mobileInput = document.getElementById("mobileNumber");
  const cameraMessage = document.getElementById("cameraMessage");
  const scanMessage = document.getElementById("scanMessage");
  const progress = document.getElementById("ocrProgress");

  if (!video || !startButton || !captureButton || !chooseButton || !stopButton ||
      !imageInput || !nameInput || !mobileInput || !cameraMessage || !scanMessage || !progress) {
    return;
  }

  let stream = null;

  function stopCamera() {
    if (stream) {
      stream.getTracks().forEach((track) => track.stop());
      stream = null;
    }
    video.srcObject = null;
    startButton.disabled = false;
    captureButton.disabled = true;
    stopButton.disabled = true;
    cameraMessage.textContent = "Camera is off. You can choose an ID image instead.";
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

  async function readImage(source) {
    if (!window.Tesseract?.recognize) {
      scanMessage.textContent = "Text recognition did not load. Check your internet connection or fill in the fields manually.";
      return;
    }
    scanMessage.textContent = "Reading ID locally in this browser…";
    progress.hidden = false;
    progress.value = 0;
    startButton.disabled = true;
    captureButton.disabled = true;
    chooseButton.disabled = true;

    try {
      const result = await window.Tesseract.recognize(source, "eng", {
        logger: (event) => {
          if (event.status === "recognizing text" && Number.isFinite(event.progress)) {
            progress.value = Math.round(event.progress * 100);
          }
        },
      });
      const lines = result.data.text.split(/\r?\n/);
      const detectedName = extractName(lines);
      const detectedMobile = extractMobile(result.data.text);
      if (detectedName) nameInput.value = detectedName;
      if (detectedMobile) mobileInput.value = detectedMobile;
      const found = [detectedName && "name", detectedMobile && "mobile number"].filter(Boolean);
      scanMessage.textContent = found.length
        ? `Suggested ${found.join(" and ")}. Verify and correct the fields before saving.`
        : "No name or mobile number was confidently recognized. Fill in the fields and verify them against the ID.";
    } catch (error) {
      console.error("Could not read the visitor ID image.", error);
      scanMessage.textContent = "Could not read this image. Try better lighting or enter the details manually.";
    } finally {
      progress.hidden = true;
      chooseButton.disabled = false;
      startButton.disabled = Boolean(stream);
      captureButton.disabled = !stream;
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
      captureButton.disabled = false;
      stopButton.disabled = false;
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
    if (!video.videoWidth || !video.videoHeight) {
      scanMessage.textContent = "Wait for the camera image, then try capturing again.";
      return;
    }
    const canvas = document.createElement("canvas");
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext("2d").drawImage(video, 0, 0);
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
