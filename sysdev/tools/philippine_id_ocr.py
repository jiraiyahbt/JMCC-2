import argparse
import json
import os
import re
from pathlib import Path

import cv2
import pytesseract


PHONE_DIGIT_FIXES = str.maketrans({
    "O": "0", "o": "0",
    "I": "1", "i": "1", "l": "1", "|": "1",
    "S": "5", "s": "5",
    "B": "8", "b": "8",
})

PHONE_CHARS = r"0-9OoIi|lSsBb"
PHONE_CANDIDATE_RE = re.compile(
    rf"(?<![A-Za-z0-9])\+?[{PHONE_CHARS}]"
    rf"[{PHONE_CHARS}\s().-]{{7,22}}[{PHONE_CHARS}](?![A-Za-z0-9])"
)
MOBILE_LABEL_RE = re.compile(
    r"\b(?:MOBILE|CELLPHONE|CELL\s*PHONE|CONTACT|TELEPHONE|PHONE)\b",
    re.IGNORECASE,
)
ADDRESS_LABEL_RE = re.compile(
    r"\b(?:RESIDENTIAL\s+ADDRESS|PERMANENT\s+ADDRESS|"
    r"PLACE\s+OF\s+RESIDENCE|ADDRESS|TIRAHAN)\b",
    re.IGNORECASE,
)
ADDRESS_END_RE = re.compile(
    r"\b(?:FULL\s+NAME|GIVEN\s+NAME|FIRST\s+NAME|MIDDLE\s+NAME|"
    r"LAST\s+NAME|SURNAME|APELYIDO|MGA\s+PANGALAN|"
    r"MOBILE|CELLPHONE|CELL\s*PHONE|CONTACT|TELEPHONE|PHONE|"
    r"DATE\s+OF\s+(?:BIRTH|ISSUE|EXPIRY)|PLACE\s+OF\s+BIRTH|"
    r"BIRTHDATE|SEX|CIVIL\s+STATUS|NATIONALITY|CITIZENSHIP|SIGNATURE|"
    r"PASSPORT|DRIVER|LICENSE|UMID|POSTAL|ID\s*(?:NO|NUMBER))\b",
    re.IGNORECASE,
)

NAME_LABELS = [
    ("full", re.compile(r"\b(?:FULL\s+NAME|BUONG\s+PANGALAN)\b", re.IGNORECASE)),
    ("middle", re.compile(
        r"\b(?:MIDDLE\s+NAME|GITNANG\s+APELYIDO)\b", re.IGNORECASE
    )),
    ("given", re.compile(
        r"\b(?:GIVEN\s+NAMES?|FIRST\s+NAMES?|MGA\s+PANGALAN)\b",
        re.IGNORECASE,
    )),
    ("last", re.compile(
        r"\b(?:LAST\s+NAMES?|SURNAME|APELYIDO)\b", re.IGNORECASE
    )),
]

HEADER_RE = re.compile(
    r"\bREPUBLIC\s+OF\s+(?:THE\s+)?PHILIPPINES\b"
    r"|\bPAMBANSANG\s+PAGKAKAKILANLAN\b",
    re.IGNORECASE,
)
NON_NAME_RE = re.compile(
    r"\b(?:REPUBLIC|PHILIPPINES|PAMBANSANG|PAGKAKAKILANLAN|"
    r"NATIONAL|IDENTITY|PHILSYS|ADDRESS|BIRTH|DATE|SEX|CITIZENSHIP|"
    r"SIGNATURE|MOBILE|CONTACT|PASSPORT|DRIVER|LICENSE|UMID|POSTAL|"
    r"CARD|BARANGAY|PROVINCE|CITY|VALID|UNTIL|HEIGHT|BLOOD|TYPE|"
    r"MALE|FEMALE|FILIPINO|FILIPINA)\b",
    re.IGNORECASE,
)


def preprocess_id_image(image_path):
    """Create OCR-friendly variants of an ID image."""
    image = cv2.imread(str(image_path), cv2.IMREAD_COLOR)
    if image is None:
        raise FileNotFoundError(f"Cannot read image: {image_path}")

    height, width = image.shape[:2]
    scale = min(2.5, 1400 / max(height, width))
    if scale > 1:
        image = cv2.resize(
            image,
            None,
            fx=scale,
            fy=scale,
            interpolation=cv2.INTER_CUBIC,
        )

    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    denoised = cv2.fastNlMeansDenoising(gray, None, 10, 7, 21)
    enhanced = cv2.createCLAHE(
        clipLimit=2.0,
        tileGridSize=(8, 8),
    ).apply(denoised)

    adaptive = cv2.adaptiveThreshold(
        enhanced,
        255,
        cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
        cv2.THRESH_BINARY,
        31,
        9,
    )
    blurred = cv2.GaussianBlur(enhanced, (3, 3), 0)
    _, otsu = cv2.threshold(
        blurred,
        0,
        255,
        cv2.THRESH_BINARY + cv2.THRESH_OTSU,
    )

    return [
        ("enhanced", enhanced),
        ("adaptive", adaptive),
        ("otsu", otsu),
    ]


def _normalize_label_line(line):
    return re.sub(
        r"(?i)(\b(?:LAST|GIVEN|MIDDLE|FULL)\s+NAMES?)"
        r"\s*(?:/|1|l|I|\|)\s*",
        r"\1 / ",
        line,
    )


def _find_name_label(line):
    normalized = _normalize_label_line(line)
    for field, pattern in NAME_LABELS:
        match = pattern.search(normalized)
        if match:
            return field, match
    return None


def _clean_name(value):
    value = value.replace(",", " ")
    value = re.sub(r"[^A-Za-zÀ-ÖØ-öø-ÿ.'’\-]+", " ", value)
    tokens = re.findall(
        r"[A-Za-zÀ-ÖØ-öø-ÿ]+"
        r"(?:['’\-][A-Za-zÀ-ÖØ-öø-ÿ]+)*\.?",
        value,
    )

    cleaned = []
    for token in tokens:
        letters = re.sub(r"[^A-Za-zÀ-ÖØ-öø-ÿ]", "", token)
        if len(letters) == 1 and not token.endswith("."):
            continue
        cleaned.append(token)

    return " ".join(cleaned)


def _looks_like_name(value):
    return bool(value) and not NON_NAME_RE.search(value)


def _value_after_label(line, match):
    tail = line[match.end():]
    tail = re.sub(
        r"^\s*(?:[/1lI|]\s*)?"
        r"(?:APELYIDO|MGA\s+PANGALAN|GITNANG\s+APELYIDO|BUONG\s+PANGALAN)\b",
        "",
        tail,
        flags=re.IGNORECASE,
    )
    return _clean_name(tail.lstrip(" \t:;|/.-"))


def _name_beneath_header(lines):
    for index, line in enumerate(lines):
        if not HEADER_RE.search(line):
            continue

        name_parts = []
        for candidate_line in lines[index + 1:index + 6]:
            if _find_name_label(candidate_line):
                break
            if not _looks_like_name(candidate_line):
                break

            candidate = _clean_name(candidate_line)
            if not candidate:
                if re.search(r"\d", candidate_line):
                    break
                continue
            name_parts.extend(candidate.split())

        if len(name_parts) >= 2:
            return " ".join(name_parts[:6])

    return None


def extract_full_name(ocr_text):
    lines = [line.strip() for line in ocr_text.splitlines() if line.strip()]
    fields = {}

    for index, line in enumerate(lines):
        label = _find_name_label(line)
        if not label:
            continue

        field, match = label
        value = _value_after_label(line, match)

        if not value:
            for next_line in lines[index + 1:index + 3]:
                if _find_name_label(next_line):
                    break
                candidate = _clean_name(next_line)
                if candidate and _looks_like_name(next_line):
                    value = candidate
                    break

        if value and _looks_like_name(value):
            fields.setdefault(field, value)

    if fields.get("full"):
        return fields["full"]

    components = [
        fields.get("given"),
        fields.get("middle"),
        fields.get("last"),
    ]
    combined = " ".join(part for part in components if part)
    if combined:
        return combined

    return _name_beneath_header(lines)


def normalize_ph_mobile(candidate):
    """Return a Philippine mobile number as +639XXXXXXXXX, or None."""
    corrected = candidate.translate(PHONE_DIGIT_FIXES)
    digits = re.sub(r"\D", "", corrected)

    if len(digits) == 11 and digits.startswith("09"):
        return "+63" + digits[1:]
    if len(digits) == 12 and digits.startswith("639"):
        return "+63" + digits[2:]
    if len(digits) == 10 and digits.startswith("9"):
        return "+63" + digits
    return None


def extract_mobile_number(ocr_text):
    lines = ocr_text.splitlines()
    matches = []

    for index, line in enumerate(lines):
        has_mobile_label = bool(MOBILE_LABEL_RE.search(line))
        if index > 0:
            has_mobile_label |= bool(MOBILE_LABEL_RE.search(lines[index - 1]))

        for candidate in PHONE_CANDIDATE_RE.findall(line):
            normalized = normalize_ph_mobile(candidate)
            if normalized:
                matches.append((
                    0 if has_mobile_label else 1,
                    index,
                    normalized,
                ))

    if not matches:
        return None
    matches.sort()
    return matches[0][2]


def _clean_address(value):
    value = re.sub(r"[^A-Za-z0-9À-ÖØ-öø-ÿ#.,'’/\-]+", " ", value)
    return re.sub(r"\s+", " ", value).strip(" \t:;|/.,-")


def extract_address(ocr_text):
    lines = [line.strip() for line in ocr_text.splitlines() if line.strip()]

    for index, line in enumerate(lines):
        label = ADDRESS_LABEL_RE.search(line)
        if not label:
            continue

        tail = line[label.end():]
        tail = re.sub(
            r"^\s*(?:[/1lI|]\s*)?TIRAHAN\b",
            "",
            tail,
            flags=re.IGNORECASE,
        )
        tail = ADDRESS_END_RE.split(tail, maxsplit=1)[0]
        address_parts = []
        inline_address = _clean_address(tail)
        if inline_address:
            address_parts.append(inline_address)

        for next_line in lines[index + 1:index + 4]:
            if (
                ADDRESS_LABEL_RE.search(next_line)
                or ADDRESS_END_RE.search(next_line)
                or PHONE_CANDIDATE_RE.search(next_line)
            ):
                break
            continuation = _clean_address(next_line)
            if continuation:
                address_parts.append(continuation)

        return " ".join(address_parts) or None

    return None


def parse_id_text(ocr_text):
    return {
        "full_name": extract_full_name(ocr_text),
        "mobile_number": extract_mobile_number(ocr_text),
        "address": extract_address(ocr_text),
    }


def _candidate_score(parsed):
    score = 100 * bool(parsed["full_name"])
    score += 100 * bool(parsed["mobile_number"])
    score += 100 * bool(parsed["address"])
    return score


def read_id(image_path, language="eng", tesseract_cmd=None):
    command = tesseract_cmd or os.environ.get("TESSERACT_CMD")
    if command:
        pytesseract.pytesseract.tesseract_cmd = command

    best = None
    for _, image in preprocess_id_image(image_path):
        for psm in (6, 11):
            config = f"--oem 3 --psm {psm} -c preserve_interword_spaces=1"
            text = pytesseract.image_to_string(
                image,
                lang=language,
                config=config,
            )
            parsed = parse_id_text(text)
            score = _candidate_score(parsed)

            if best is None or score > best["score"]:
                best = {
                    "score": score,
                    "result": parsed,
                }

    return best["result"]


def main():
    parser = argparse.ArgumentParser(
        description="Extract a name, mobile number, and address from an ID image."
    )
    parser.add_argument("image", type=Path, help="Path to the ID image")
    parser.add_argument(
        "--lang",
        default="eng",
        help="Tesseract language(s), e.g. eng or eng+tgl if installed",
    )
    parser.add_argument(
        "--tesseract-cmd",
        help="Path to Tesseract; alternatively set the TESSERACT_CMD environment variable",
    )
    args = parser.parse_args()

    try:
        result = read_id(args.image, args.lang, args.tesseract_cmd)
    except (OSError, RuntimeError, ValueError, pytesseract.TesseractError) as error:
        parser.exit(2, f"Error: {error}\n")

    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
