import re
import tempfile
import unittest
from pathlib import Path

from werkzeug.security import generate_password_hash

from app import create_app, get_db


class SafeGateApplicationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        database = Path(self.temporary_directory.name) / "test.sqlite3"
        self.app = create_app(
            {
                "TESTING": True,
                "SECRET_KEY": "test-secret-key",
                "DATABASE": str(database),
                "ENTRANCES": ("Gate 1 - Main entrance", "Gate 2 - North entrance"),
            }
        )
        self.client = self.app.test_client()
        with self.app.app_context():
            db = get_db()
            db.execute(
                """
                INSERT INTO staff_accounts
                  (full_name, email, role, account_status, password_hash, created_at)
                VALUES (?, ?, 'Administrator', 'Active', ?, ?)
                """,
                ("Test Admin", "admin@example.test", generate_password_hash("AdminPass12345"), "2026-01-01T08:00:00+08:00"),
            )
            db.execute(
                """
                INSERT INTO staff_accounts
                  (full_name, email, role, account_status, password_hash, created_at)
                VALUES (?, ?, 'Security Guard', 'Active', ?, ?)
                """,
                ("Test Guard", "guard@example.test", generate_password_hash("GuardPass12345"), "2026-01-01T08:00:00+08:00"),
            )
            db.commit()

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    def csrf(self) -> str:
        with self.client.session_transaction() as browser_session:
            return browser_session["csrf_token"]

    def sign_in(self, email: str, password: str) -> None:
        self.client.get("/login")
        response = self.client.post(
            "/login",
            data={"_csrf_token": self.csrf(), "email": email, "password": password},
        )
        self.assertEqual(response.status_code, 302)

    def check_in(self, visitor_name: str = "Test Visitor", mobile: str = "09123456789") -> int:
        return self.client.post(
            "/guard/verify",
            data={
                "_csrf_token": self.csrf(),
                "action": "check_in",
                "visitor_name": visitor_name,
                "mobile_number": mobile,
                "destination": "Registrar office",
                "purpose": "Enrollment inquiry",
                "entrance": "Gate 1 - Main entrance",
                "identity_confirmed": "yes",
            },
        ).status_code

    def test_public_visitor_registration_is_removed(self) -> None:
        self.assertEqual(self.client.get("/").status_code, 302)
        self.assertEqual(self.client.get("/register").status_code, 404)
        self.assertEqual(self.client.get("/pass/test-pass").status_code, 404)

    def test_guard_id_capture_form_checkin_and_checkout(self) -> None:
        self.sign_in("guard@example.test", "GuardPass12345")
        scanner = self.client.get("/guard/verify?entrance=Gate%201%20-%20Main%20entrance")
        self.assertEqual(scanner.status_code, 200)
        self.assertIn(b"Capture and read ID", scanner.data)
        self.assertIn(b"the ID image is not uploaded or saved", scanner.data)

        entry = self.check_in()
        self.assertEqual(entry, 302)
        with self.app.app_context():
            visit = get_db().execute(
                "SELECT * FROM visitor_log_entries WHERE visitor_name = ?",
                ("Test Visitor",),
            ).fetchone()
            self.assertEqual(visit["mobile_number"], "09123456789")
            self.assertEqual(visit["destination"], "Registrar office")
            self.assertEqual(visit["purpose"], "Enrollment inquiry")
            self.assertEqual(visit["status"], "Inside campus")
            self.assertIsNotNone(visit["entry_time"])
            visit_id = visit["visit_id"]

        denied = self.client.post(
            f"/guard/visits/{visit_id}/checkout",
            data={
                "_csrf_token": self.csrf(),
                "exit_entrance": "Gate 2 - North entrance",
            },
        )
        self.assertEqual(denied.status_code, 302)
        with self.app.app_context():
            self.assertEqual(
                get_db().execute(
                    "SELECT status FROM visitor_log_entries WHERE visit_id = ?", (visit_id,)
                ).fetchone()["status"],
                "Inside campus",
            )

        checkout = self.client.post(
            f"/guard/visits/{visit_id}/checkout",
            data={
                "_csrf_token": self.csrf(),
                "exit_entrance": "Gate 2 - North entrance",
                "checkout_confirmed": "yes",
            },
        )
        self.assertEqual(checkout.status_code, 302)
        with self.app.app_context():
            visit = get_db().execute(
                "SELECT * FROM visitor_log_entries WHERE visit_id = ?", (visit_id,)
            ).fetchone()
            self.assertEqual(visit["status"], "Exited")
            self.assertEqual(visit["exit_entrance_name"], "Gate 2 - North entrance")
            self.assertIsNotNone(visit["exit_time"])
            self.assertIsNotNone(visit["exit_guard_id"])

    def test_checkin_requires_review_and_accepts_missing_mobile(self) -> None:
        self.sign_in("guard@example.test", "GuardPass12345")
        response = self.client.post(
            "/guard/verify",
            data={
                "_csrf_token": self.csrf(),
                "action": "check_in",
                "visitor_name": "Visitor Without Mobile",
                "mobile_number": "",
                "destination": "Library",
                "purpose": "Meeting",
                "entrance": "Gate 1 - Main entrance",
            },
        )
        self.assertEqual(response.status_code, 400)
        self.assertIn(b"reviewed the visitor details", response.data)
        with self.app.app_context():
            self.assertEqual(get_db().execute("SELECT COUNT(*) FROM visitor_log_entries").fetchone()[0], 0)

        response = self.client.post(
            "/guard/verify",
            data={
                "_csrf_token": self.csrf(),
                "action": "check_in",
                "visitor_name": "Visitor Without Mobile",
                "mobile_number": "",
                "destination": "Library",
                "purpose": "Meeting",
                "entrance": "Gate 1 - Main entrance",
                "identity_confirmed": "yes",
            },
        )
        self.assertEqual(response.status_code, 302)
        with self.app.app_context():
            visit = get_db().execute(
                "SELECT mobile_number FROM visitor_log_entries"
            ).fetchone()
            self.assertIsNone(visit["mobile_number"])

    def test_guard_dashboard_keeps_older_active_visits_available_for_checkout(self) -> None:
        with self.app.app_context():
            db = get_db()
            guard_id = db.execute(
                "SELECT staff_id FROM staff_accounts WHERE email = ?",
                ("guard@example.test",),
            ).fetchone()["staff_id"]
            db.execute(
                """
                INSERT INTO visitor_log_entries
                  (visitor_name, destination, purpose, entrance_name, entry_guard_id,
                   entry_time, status)
                VALUES (?, ?, ?, ?, ?, ?, 'Inside campus')
                """,
                (
                    "Overnight Visitor",
                    "Office",
                    "Meeting",
                    "Gate 1 - Main entrance",
                    guard_id,
                    "2000-01-01T08:00:00+08:00",
                ),
            )
            db.commit()

        self.sign_in("guard@example.test", "GuardPass12345")
        dashboard = self.client.get("/guard")
        self.assertEqual(dashboard.status_code, 200)
        self.assertIn(b"Overnight Visitor", dashboard.data)
        self.assertIn(b"Currently inside", dashboard.data)

    def test_role_boundaries_and_csrf_are_enforced(self) -> None:
        self.assertEqual(self.client.get("/guard").status_code, 302)
        self.client.get("/login")
        response = self.client.post(
            "/login",
            data={"_csrf_token": self.csrf(), "email": "admin@example.test", "password": "AdminPass12345"},
        )
        self.assertEqual(response.status_code, 302)
        self.assertEqual(self.client.get("/guard").status_code, 403)
        self.assertEqual(self.client.post("/logout").status_code, 400)

    def test_staff_invitation_activates_password_and_role(self) -> None:
        self.sign_in("admin@example.test", "AdminPass12345")
        response = self.client.post(
            "/admin/users",
            data={
                "_csrf_token": self.csrf(),
                "full_name": "New Guard",
                "email": "new-guard@example.test",
                "role": "Security Guard",
            },
        )
        self.assertEqual(response.status_code, 200)
        token_match = re.search(rb"/accept-invite/([^<\s]+)", response.data)
        self.assertIsNotNone(token_match)
        invite_path = f"/accept-invite/{token_match.group(1).decode()}"
        self.assertEqual(self.client.get(invite_path).status_code, 200)
        activation = self.client.post(
            invite_path,
            data={
                "_csrf_token": self.csrf(),
                "password": "NewGuardPass123",
                "password_confirmation": "NewGuardPass123",
            },
        )
        self.assertEqual(activation.status_code, 302)
        logout = self.client.post("/logout", data={"_csrf_token": self.csrf()})
        self.assertEqual(logout.status_code, 302)
        self.client.get("/login")
        login = self.client.post(
            "/login",
            data={
                "_csrf_token": self.csrf(),
                "email": "new-guard@example.test",
                "password": "NewGuardPass123",
            },
        )
        self.assertEqual(login.status_code, 302)
        self.assertIn("/guard", login.headers["Location"])

    def test_admin_export_filters_and_neutralizes_spreadsheet_formulas(self) -> None:
        self.sign_in("guard@example.test", "GuardPass12345")
        self.assertEqual(self.check_in(visitor_name="=1+1 Visitor"), 302)
        self.client.post("/logout", data={"_csrf_token": self.csrf()})
        self.sign_in("admin@example.test", "AdminPass12345")
        page = self.client.get("/admin/records?q=Visitor")
        self.assertEqual(page.status_code, 200)
        self.assertIn(b"=1+1 Visitor", page.data)
        exported = self.client.get("/admin/records.csv?q=Visitor")
        self.assertEqual(exported.status_code, 200)
        self.assertIn(b"'=1+1 Visitor", exported.data)

    def test_create_admin_cli_bootstraps_an_account(self) -> None:
        result = self.app.test_cli_runner().invoke(
            args=["create-admin", "--email", "cli-admin@example.test", "--name", "CLI Admin"],
            input="LongAdminPass123!\nLongAdminPass123!\n",
        )
        self.assertEqual(result.exit_code, 0, result.output)
        with self.app.app_context():
            account = get_db().execute(
                "SELECT full_name, role, account_status FROM staff_accounts WHERE email = ?",
                ("cli-admin@example.test",),
            ).fetchone()
            self.assertEqual(account["full_name"], "CLI Admin")
            self.assertEqual(account["role"], "Administrator")
            self.assertEqual(account["account_status"], "Active")


if __name__ == "__main__":
    unittest.main()
