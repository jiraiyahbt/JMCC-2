import unittest

from philippine_id_ocr import (
    extract_address,
    extract_full_name,
    extract_mobile_number,
    normalize_ph_mobile,
    parse_id_text,
)


class PhilippineIdOcrParserTests(unittest.TestCase):
    def test_extracts_bilingual_name_components_with_misread_slashes(self):
        text = """
        Last Name 1 Apelyido: Dela Cruz
        Given Name | Mga Pangalan: Juan Carlos
        Middle Name l Gitnang Apelyido: Santos
        """
        self.assertEqual(
            extract_full_name(text),
            "Juan Carlos Santos Dela Cruz",
        )

    def test_falls_back_to_name_under_document_header(self):
        text = """
        REPUBLIC OF THE PHILIPPINES
        JUAN CARLOS
        DELA CRUZ
        ADDRESS: Zamboanga City
        """
        self.assertEqual(extract_full_name(text), "JUAN CARLOS DELA CRUZ")

    def test_normalizes_supported_philippine_mobile_formats(self):
        examples = {
            "0917 123 4567": "+639171234567",
            "+63 917-123-4567": "+639171234567",
            "63917 123 4567": "+639171234567",
            "9171234567": "+639171234567",
            "O9I7 I23 4567": "+639171234567",
        }
        for value, expected in examples.items():
            with self.subTest(value=value):
                self.assertEqual(normalize_ph_mobile(value), expected)

    def test_prefers_number_near_mobile_label(self):
        text = """
        Reference: 09181234567
        Mobile Number: +63 917-123-4567
        """
        self.assertEqual(
            extract_mobile_number(text),
            "+639171234567",
        )

    def test_returns_none_for_invalid_mobile_number(self):
        self.assertIsNone(normalize_ph_mobile("123-456-7890"))

    def test_parses_name_and_phone_together(self):
        parsed = parse_id_text(
            "Full Name: Maria Santos\n"
            "Mobile: 0917 123 4567\n"
            "Address: 123 Rizal Street, Barangay Tetuan"
        )
        self.assertEqual(parsed["full_name"], "Maria Santos")
        self.assertEqual(parsed["mobile_number"], "+639171234567")
        self.assertEqual(
            parsed["address"],
            "123 Rizal Street, Barangay Tetuan",
        )
        self.assertEqual(
            set(parsed),
            {"full_name", "mobile_number", "address"},
        )

    def test_extracts_multiline_bilingual_address(self):
        text = """
        Address 1 Tirahan:
        123 Rizal Street, Barangay Tetuan
        Zamboanga City
        Mobile Number: 0917 123 4567
        """
        self.assertEqual(
            extract_address(text),
            "123 Rizal Street, Barangay Tetuan Zamboanga City",
        )


if __name__ == "__main__":
    unittest.main()
