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

  function parseQrFields(payload) {
    const value = payload.trim();
    if (/^(?:https?:\/\/|www\.)/i.test(value)) {
      return { name: "", mobile: "", isLink: true };
    }

    let fields = {};
    try {
      const parsed = JSON.parse(value);
      if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) {
        fields = Object.fromEntries(
          Object.entries(parsed).map(([key, item]) => [
            key.toLowerCase().replace(/[^a-z0-9]/g, ""),
            typeof item === "string" || typeof item === "number" ? String(item) : "",
          ])
        );
      }
    } catch {
      // QR contents may be plain labeled text rather than JSON.
    }

    const pick = (aliases) => {
      for (const alias of aliases) {
        if (fields[alias]) return fields[alias].trim();
      }
      return "";
    };
    const nameMatch = value.match(/^(?:full[\s_-]*name|visitor[\s_-]*name|name)\s*[:=]\s*(.+)$/im);
    const mobileMatch = value.match(/^(?:mobile(?:[\s_-]*number)?|phone(?:[\s_-]*number)?|tel)\s*[:=]\s*(.+)$/im);
    const name = (pick(["fullname", "visitorname", "name"]) || nameMatch?.[1] || "")
      .replace(/[\u0000-\u001f\u007f]/g, "")
      .trim()
      .slice(0, 120);
    let mobile = (pick(["mobilenumber", "mobile", "phonenumber", "phone", "tel"]) ||
      mobileMatch?.[1] || "").replace(/[\u0000-\u001f\u007f]/g, "").trim();
    if (!mobile) mobile = extractMobile(value);
    if (!/^[+0-9][0-9()\-\s]{6,19}$/.test(mobile)) mobile = "";

    return { name, mobile, isLink: false };
  }

  async function decodeQr(source) {
    if (typeof window.jsQR !== "function") return null;

    let image = source;
    let bitmap = null;
    try {
      if (source instanceof Blob) {
        if (typeof createImageBitmap !== "function") return null;
        bitmap = await createImageBitmap(source);
        image = bitmap;
      }
      const width = image.videoWidth || image.naturalWidth || image.width;
      const height = image.videoHeight || image.naturalHeight || image.height;
      if (!width || !height) return null;

      const scale = Math.min(1, 1600 / Math.max(width, height));
      const canvas = document.createElement("canvas");
      canvas.width = Math.max(1, Math.round(width * scale));
      canvas.height = Math.max(1, Math.round(height * scale));
      const context = canvas.getContext("2d", { willReadFrequently: true });
      if (!context) return null;
      context.drawImage(image, 0, 0, canvas.width, canvas.height);
      const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
      return window.jsQR(imageData.data, imageData.width, imageData.height, {
        inversionAttempts: "attemptBoth",
      })?.data ?? null;
    } finally {
      if (bitmap) bitmap.close();
    }
  }

  async function readImage(source) {
    scanMessage.textContent = "Checking the QR code and reading the ID locally…";
    progress.hidden = false;
    progress.value = 0;
    startButton.disabled = true;
    captureButton.disabled = true;
    chooseButton.disabled = true;

    let qrFound = false;
    let qrFields = { name: "", mobile: "", isLink: false };
    try {
      try {
        const payload = await decodeQr(source);
        if (payload !== null) {
          qrFound = true;
          qrFields = parseQrFields(payload);
        }
      } catch (error) {
        console.error("Could not decode the visitor ID QR code.", error);
      }

      if (qrFields.name) nameInput.value = qrFields.name;
      if (qrFields.mobile) mobileInput.value = qrFields.mobile;

      let detectedName = "";
      let detectedMobile = "";
      if (window.Tesseract?.recognize) {
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
          detectedName = extractName(lines);
          detectedMobile = extractMobile(result.data.text);
        } catch (error) {
          console.error("Could not read the visitor ID text.", error);
          if (!qrFields.name && !qrFields.mobile) {
            scanMessage.textContent = "Could not read this ID. Try better lighting or enter the details manually.";
          }
        }
      } else if (!qrFields.name && !qrFields.mobile) {
        scanMessage.textContent = "Text recognition could not be loaded. Refresh the page or enter the details manually.";
      }

      if (detectedName && !qrFields.name) nameInput.value = detectedName;
      if (detectedMobile && !qrFields.mobile) mobileInput.value = detectedMobile;
      const found = [
        (qrFields.name || detectedName) && "name",
        (qrFields.mobile || detectedMobile) && "mobile number",
      ].filter(Boolean);
      if (found.length) {
        scanMessage.textContent = `Suggested ${found.join(" and ")}. Verify and correct the fields before saving.`;
      } else if (qrFound && qrFields.isLink) {
        scanMessage.textContent = "A QR link was detected but not opened. Verify the ID and enter details manually.";
      } else if (qrFound) {
        scanMessage.textContent = "A QR code was found, but its data format was not recognized. Verify the ID and enter details manually.";
      } else if (window.Tesseract?.recognize) {
        scanMessage.textContent = "No name or mobile number was recognized. Fill in the fields and verify them against the ID.";
      }
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
