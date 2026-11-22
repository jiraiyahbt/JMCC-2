from __future__ import annotations

import csv
import hashlib
import hmac
import io
import os
import re
import secrets
import sqlite3
from datetime import datetime, timedelta, timezone
from functools import wraps
from pathlib import Path
from typing import Any, Callable

import click
from flask import (
    Flask,
    abort,
    flash,
    g,
    make_response,
    redirect,
    render_template,
    request,
    session,
    url_for,
)
from werkzeug.security import check_password_hash, generate_password_hash


ROOT = Path(__file__).resolve().parent
SCHEMA_PATH = ROOT / "schema.sql"
STAFF_ROLES = ("Administrator", "Security Guard")
ENTRANCES = ("Gate 1 - Main entrance", "Gate 2 - North entrance")
CAMPUS_TIMEZONE = timezone(timedelta(hours=8))
MOBILE_PATTERN = re.compile(r"^[+0-9][0-9()\-\s]{6,19}$")
EMAIL_PATTERN = re.compile(r"^[^@\s]{1,64}@[^@\s]{1,190}\.[^@\s]{2,}$")


def campus_now() -> datetime:
    return datetime.now(CAMPUS_TIMEZONE).replace(microsecond=0)


def timestamp() -> str:
    return campus_now().isoformat()


def token_hash(token: str) -> str:
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


def get_db() -> sqlite3.Connection:
    if "db" not in g:
        db_path = Path(current_app_config()["DATABASE"])
        db_path.parent.mkdir(parents=True, exist_ok=True)
        connection = sqlite3.connect(db_path, timeout=10)
        connection.row_factory = sqlite3.Row
        connection.execute("PRAGMA foreign_keys = ON")
        connection.execute("PRAGMA busy_timeout = 10000")
        g.db = connection
    return g.db


def current_app_config() -> dict[str, Any]:
    from flask import current_app

    return current_app.config


def close_db(_error: BaseException | None = None) -> None:
    connection = g.pop("db", None)
    if connection is not None:
        connection.close()


def init_db() -> None:
    db = get_db()
    db.executescript(SCHEMA_PATH.read_text(encoding="utf-8"))


def csrf_value() -> str:
    if "csrf_token" not in session:
        session["csrf_token"] = secrets.token_urlsafe(32)
    return str(session["csrf_token"])


def login_required(view: Callable[..., Any]) -> Callable[..., Any]:
    @wraps(view)
    def wrapped(*args: Any, **kwargs: Any) -> Any:
        if g.user is None:
            flash("Sign in to continue.", "warning")
            return redirect(url_for("login"))
        return view(*args, **kwargs)

    return wrapped


def role_required(role: str) -> Callable[[Callable[..., Any]], Callable[..., Any]]:
    def decorate(view: Callable[..., Any]) -> Callable[..., Any]:
        @wraps(view)
        def wrapped(*args: Any, **kwargs: Any) -> Any:
            if g.user is None:
                flash("Sign in to continue.", "warning")
                return redirect(url_for("login"))
            if g.user["role"] != role:
                abort(403)
            return view(*args, **kwargs)

        return wrapped

    return decorate


def record_rows(filters: dict[str, str]) -> list[sqlite3.Row]:
    clauses: list[str] = []
    values: list[Any] = []
    if filters.get("q"):
        term = f"%{filters['q']}%"
        clauses.append("(vl.visitor_name LIKE ? OR vl.mobile_number LIKE ?)")
        values.extend((term, term))
    if filters.get("visit_date"):
        clauses.append("date(vl.entry_time) = ?")
        values.append(filters["visit_date"])
    if filters.get("status") in ("Inside campus", "Exited"):
        clauses.append("vl.status = ?")
        values.append(filters["status"])
    if filters.get("entrance") in ENTRANCES:
        clauses.append("vl.entrance_name = ?")
        values.append(filters["entrance"])

    where = f"WHERE {' AND '.join(clauses)}" if clauses else ""
    return get_db().execute(
        f"""
        SELECT vl.visit_id, vl.visitor_name, vl.mobile_number, vl.destination,
               vl.purpose, vl.status, vl.entrance_name, vl.exit_entrance_name,
               vl.entry_time, vl.exit_time,
               entry_guard.full_name AS entry_guard_name,
               exit_guard.full_name AS exit_guard_name
        FROM visitor_log_entries AS vl
        JOIN staff_accounts AS entry_guard ON entry_guard.staff_id = vl.entry_guard_id
        LEFT JOIN staff_accounts AS exit_guard ON exit_guard.staff_id = vl.exit_guard_id
        {where}
        ORDER BY vl.entry_time DESC
        LIMIT 500
        """,
        values,
    ).fetchall()


def csv_safe(value: Any) -> Any:
    if isinstance(value, str) and value.startswith(("=", "+", "-", "@", "\t", "\r")):
        return "'" + value
    return value


def valid_csrf() -> bool:
    expected = session.get("csrf_token")
    submitted = request.form.get("_csrf_token") or request.headers.get("X-CSRF-Token")
    return bool(expected and submitted and hmac.compare_digest(str(expected), submitted))


def create_app(test_config: dict[str, Any] | None = None) -> Flask:
    app = Flask(__name__, instance_relative_config=True)
    app.config.from_mapping(
        SECRET_KEY=os.environ.get("SAFEGATE_SECRET_KEY") or secrets.token_urlsafe(32),
        DATABASE=str(Path(app.instance_path) / "safegate.sqlite3"),
        SESSION_COOKIE_HTTPONLY=True,
        SESSION_COOKIE_SAMESITE="Lax",
        SESSION_COOKIE_SECURE=os.environ.get("SAFEGATE_COOKIE_SECURE") == "1",
        PERMANENT_SESSION_LIFETIME=timedelta(hours=8),
        ENTRANCES=ENTRANCES,
    )
    if test_config:
        app.config.update(test_config)
    Path(app.instance_path).mkdir(parents=True, exist_ok=True)

    app.teardown_appcontext(close_db)

    @app.before_request
    def enforce_csrf() -> None:
        if request.method == "POST" and not valid_csrf():
            abort(400, description="The form expired. Reload the page and try again.")

    @app.before_request
    def load_user() -> None:
        g.user = None
        staff_id = session.get("staff_id")
        if staff_id is None:
            return
        user = get_db().execute(
            "SELECT staff_id, full_name, email, role, account_status FROM staff_accounts WHERE staff_id = ?",
            (staff_id,),
        ).fetchone()
        if user is None or user["account_status"] != "Active":
            session.clear()
            return
        g.user = user

    @app.after_request
    def prevent_sensitive_page_caching(response: Any) -> Any:
        response.headers["Cache-Control"] = "no-store"
        response.headers["X-Content-Type-Options"] = "nosniff"
        response.headers["Referrer-Policy"] = "strict-origin-when-cross-origin"
        response.headers["X-Frame-Options"] = "DENY"
        return response

    @app.context_processor
    def inject_template_values() -> dict[str, Any]:
        return {
            "csrf_token": csrf_value,
            "entrances": app.config["ENTRANCES"],
            "staff_roles": STAFF_ROLES,
            "today": campus_now().date().isoformat(),
        }

    @app.template_filter("status_class")
    def status_class(value: str) -> str:
        return value.lower().replace(" ", "-")

    @app.errorhandler(400)
    @app.errorhandler(403)
    @app.errorhandler(404)
    def handle_http_error(error: Any) -> tuple[str, int]:
        return render_template("error.html", error=error), error.code

    with app.app_context():
        init_db()

    @app.cli.command("init-db")
    def init_db_command() -> None:
        init_db()
        click.echo("SafeGate database is ready.")

    @app.cli.command("create-admin")
    @click.option("--email", prompt=True)
    @click.option("--name", prompt="Full name")
    def create_admin_command(email: str, name: str) -> None:
        normalized_email = email.strip().lower()
        if not EMAIL_PATTERN.fullmatch(normalized_email):
            raise click.ClickException("Enter a valid email address.")
        password = click.prompt("Password (at least 12 characters)", hide_input=True, confirmation_prompt=True)
        if len(password) < 12:
            raise click.ClickException("Password must contain at least 12 characters.")
        try:
            get_db().execute(
                """
                INSERT INTO staff_accounts
                  (full_name, email, role, account_status, password_hash, created_at)
                VALUES (?, ?, 'Administrator', 'Active', ?, ?)
                """,
                (name.strip(), normalized_email, generate_password_hash(password), timestamp()),
            )
            get_db().commit()
        except sqlite3.IntegrityError as error:
            raise click.ClickException("That email address already has an account.") from error
        click.echo("Administrator account created.")

    @app.get("/")
    def home() -> Any:
        if g.user is not None:
            endpoint = "admin_dashboard" if g.user["role"] == "Administrator" else "guard_dashboard"
            return redirect(url_for(endpoint))
        return redirect(url_for("login"))

    @app.route("/login", methods=["GET", "POST"])
    def login() -> Any:
        if g.user is not None:
            return redirect(url_for("admin_dashboard" if g.user["role"] == "Administrator" else "guard_dashboard"))
        if request.method == "GET":
            return render_template("login.html")

        email = request.form.get("email", "").strip().lower()
        password = request.form.get("password", "")
        staff = get_db().execute(
            "SELECT * FROM staff_accounts WHERE email = ? COLLATE NOCASE AND account_status = 'Active'",
            (email,),
        ).fetchone()
        if staff is None or not staff["password_hash"] or not check_password_hash(staff["password_hash"], password):
            flash("Email or password is incorrect, or the account is inactive.", "error")
            return render_template("login.html", email=email), 401

        session.clear()
        session["staff_id"] = staff["staff_id"]
        session.permanent = True
        csrf_value()
        flash(f"Welcome, {staff['full_name']}.", "success")
        return redirect(url_for("admin_dashboard" if staff["role"] == "Administrator" else "guard_dashboard"))

    @app.post("/logout")
    @login_required
    def logout() -> Any:
        session.clear()
        flash("You have signed out.", "info")
        return redirect(url_for("home"))

    @app.get("/guard")
    @role_required("Security Guard")
    def guard_dashboard() -> str:
        entrance = request.args.get("entrance", "")
        if entrance not in app.config["ENTRANCES"]:
            entrance = app.config["ENTRANCES"][0]
        today_value = campus_now().date().isoformat()
        db = get_db()
        stats = {
            "inside": db.execute(
                "SELECT COUNT(*) FROM visitor_log_entries WHERE status = 'Inside campus'"
            ).fetchone()[0],
            "exited": db.execute(
                "SELECT COUNT(*) FROM visitor_log_entries WHERE date(entry_time) = ? AND status = 'Exited'",
                (today_value,),
            ).fetchone()[0],
            "visits": db.execute(
                "SELECT COUNT(*) FROM visitor_log_entries WHERE date(entry_time) = ?",
                (today_value,),
            ).fetchone()[0],
        }
        visits = db.execute(
            """
            SELECT visit_id, visitor_name, mobile_number, destination, purpose,
                   entrance_name, exit_entrance_name, entry_time, exit_time, status
            FROM visitor_log_entries
            WHERE date(entry_time) = ? OR status = 'Inside campus'
            ORDER BY entry_time DESC LIMIT 100
            """,
            (today_value,),
        ).fetchall()
        return render_template(
            "guard_dashboard.html",
            entrance=entrance,
            stats=stats,
            visits=visits,
            today=today_value,
        )

    @app.route("/guard/verify", methods=["GET", "POST"])
    @role_required("Security Guard")
    def verify_visitor() -> Any:
        entrance_value = request.values.get("entrance", app.config["ENTRANCES"][0])
        entrance = entrance_value if entrance_value in app.config["ENTRANCES"] else app.config["ENTRANCES"][0]
        errors: list[str] = []
        form_data = {
            "visitor_name": request.form.get("visitor_name", "").strip(),
            "mobile_number": request.form.get("mobile_number", "").strip(),
            "destination": request.form.get("destination", "").strip(),
            "purpose": request.form.get("purpose", "").strip(),
        }

        if request.method == "POST":
            entrance = request.form.get("entrance", "")
            if request.form.get("action") != "check_in":
                abort(400, description="Choose a valid check-in action.")
            if not 1 <= len(form_data["visitor_name"]) <= 120:
                errors.append("Visitor name is required and must be 120 characters or fewer.")
            if form_data["mobile_number"] and not MOBILE_PATTERN.fullmatch(form_data["mobile_number"]):
                errors.append("Enter a valid mobile number or leave it blank.")
            if not 1 <= len(form_data["destination"]) <= 100:
                errors.append("Person or office visited is required and must be 100 characters or fewer.")
            if not 1 <= len(form_data["purpose"]) <= 255:
                errors.append("Visit purpose is required and must be 255 characters or fewer.")
            if entrance not in app.config["ENTRANCES"]:
                errors.append("Choose a participating entrance.")
            if request.form.get("identity_confirmed") != "yes":
                errors.append("Confirm that you reviewed the visitor details against their ID.")

            if not errors:
                get_db().execute(
                    """
                    INSERT INTO visitor_log_entries
                      (visitor_name, mobile_number, destination, purpose, entrance_name,
                       entry_guard_id, entry_time, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Inside campus')
                    """,
                    (
                        form_data["visitor_name"],
                        form_data["mobile_number"] or None,
                        form_data["destination"],
                        form_data["purpose"],
                        entrance,
                        g.user["staff_id"],
                        timestamp(),
                    ),
                )
                get_db().commit()
                flash("Visitor check-in recorded. Continue the school's existing pass process.", "success")
                return redirect(url_for("guard_dashboard", entrance=entrance))

        return render_template(
            "verify_visitor.html",
            errors=errors,
            entrance=entrance,
            form_data=form_data,
        ), (400 if errors else 200)

    @app.post("/guard/visits/<int:visit_id>/checkout")
    @role_required("Security Guard")
    def checkout_visitor(visit_id: int) -> Any:
        entrance = request.form.get("exit_entrance", "")
        if entrance not in app.config["ENTRANCES"]:
            abort(400, description="Choose a participating exit entrance.")
        if request.form.get("checkout_confirmed") != "yes":
            flash("Confirm that the visitor has returned the school's pass and left campus.", "error")
            return redirect(url_for("guard_dashboard", entrance=entrance))

        updated = get_db().execute(
            """
            UPDATE visitor_log_entries
            SET status = 'Exited', exit_time = ?, exit_guard_id = ?, exit_entrance_name = ?
            WHERE visit_id = ? AND status = 'Inside campus'
            """,
            (timestamp(), g.user["staff_id"], entrance, visit_id),
        ).rowcount
        if updated != 1:
            flash("This visit is already checked out or could not be found.", "warning")
        else:
            get_db().commit()
            flash("Visitor checkout recorded.", "success")
        return redirect(url_for("guard_dashboard", entrance=entrance))

    @app.get("/admin")
    @role_required("Administrator")
    def admin_dashboard() -> Any:
        return redirect(url_for("admin_records"))

    @app.get("/admin/records")
    @role_required("Administrator")
    def admin_records() -> str:
        filters = {
            "q": request.args.get("q", "").strip(),
            "visit_date": request.args.get("visit_date", "").strip(),
            "status": request.args.get("status", "").strip(),
            "entrance": request.args.get("entrance", "").strip(),
        }
        records = record_rows(filters)
        today_value = campus_now().date().isoformat()
        totals = get_db().execute(
            """
            SELECT
              (SELECT COUNT(*) FROM visitor_log_entries WHERE date(entry_time) = ?) AS all_visits,
              (SELECT COUNT(*) FROM visitor_log_entries WHERE status = 'Inside campus') AS inside,
              (SELECT COUNT(*) FROM visitor_log_entries
               WHERE date(entry_time) = ? AND status = 'Exited') AS exited
            """,
            (today_value, today_value),
        ).fetchone()
        return render_template("admin_records.html", records=records, filters=filters, totals=totals)

    @app.get("/admin/records.csv")
    @role_required("Administrator")
    def export_records() -> Any:
        filters = {
            key: request.args.get(key, "").strip()
            for key in ("q", "visit_date", "status", "entrance")
        }
        records = record_rows(filters)
        output = io.StringIO(newline="")
        writer = csv.writer(output)
        writer.writerow(
            [
                "Visitor",
                "Mobile",
                "Person or office visited",
                "Visit purpose",
                "Check-in",
                "Check-in entrance",
                "Check-in guard",
                "Check-out",
                "Check-out entrance",
                "Check-out guard",
                "Status",
            ]
        )
        for record in records:
            writer.writerow(
                [csv_safe(value) for value in [
                    record["visitor_name"],
                    record["mobile_number"] or "",
                    record["destination"],
                    record["purpose"],
                    record["entry_time"] or "",
                    record["entrance_name"] or "",
                    record["entry_guard_name"] or "",
                    record["exit_time"] or "",
                    record["exit_entrance_name"] or "",
                    record["exit_guard_name"] or "",
                    record["status"],
                ]]
            )
        response = make_response("\ufeff" + output.getvalue())
        response.headers["Content-Type"] = "text/csv; charset=utf-8"
        response.headers["Content-Disposition"] = "attachment; filename=safegate-visitor-records.csv"
        return response

    @app.route("/admin/users", methods=["GET", "POST"])
    @role_required("Administrator")
    def admin_users() -> Any:
        invitation_url = None
        if request.method == "POST":
            full_name = request.form.get("full_name", "").strip()
            email = request.form.get("email", "").strip().lower()
            role = request.form.get("role", "")
            if not 1 <= len(full_name) <= 120:
                flash("Full name is required and must be 120 characters or fewer.", "error")
            elif not EMAIL_PATTERN.fullmatch(email):
                flash("Enter a valid email address.", "error")
            elif role not in STAFF_ROLES:
                flash("Choose an administrator or security guard role.", "error")
            else:
                invitation_token = secrets.token_urlsafe(32)
                db = get_db()
                try:
                    db.execute(
                        """
                        INSERT INTO staff_accounts
                          (full_name, email, role, account_status, invite_token_hash,
                           invite_expires_at, created_at)
                        VALUES (?, ?, ?, 'Invited', ?, ?, ?)
                        """,
                        (
                            full_name,
                            email,
                            role,
                            token_hash(invitation_token),
                            (campus_now() + timedelta(hours=48)).isoformat(),
                            timestamp(),
                        ),
                    )
                    db.commit()
                    invitation_url = url_for("accept_invite", token=invitation_token)
                    flash("Staff invitation created. Share the one-time link with the staff member.", "success")
                except sqlite3.IntegrityError:
                    db.rollback()
                    flash("An account already uses that email address.", "error")

        accounts = get_db().execute(
            """
            SELECT staff_id, full_name, email, role, account_status, created_at
            FROM staff_accounts ORDER BY created_at DESC
            """
        ).fetchall()
        return render_template("admin_users.html", accounts=accounts, invitation_url=invitation_url)

    @app.post("/admin/users/<int:staff_id>/update")
    @role_required("Administrator")
    def update_staff(staff_id: int) -> Any:
        account = get_db().execute("SELECT * FROM staff_accounts WHERE staff_id = ?", (staff_id,)).fetchone()
        if account is None:
            abort(404)

        requested_role = request.form.get("role", account["role"])
        requested_status = request.form.get("account_status", account["account_status"])
        if requested_role not in STAFF_ROLES or requested_status not in ("Active", "Inactive"):
            abort(400, description="Choose a valid role and account status.")
        if account["account_status"] == "Invited":
            flash("Invited accounts must accept their invitation before they can be activated.", "warning")
            return redirect(url_for("admin_users"))
        if staff_id == g.user["staff_id"] and requested_status == "Inactive":
            flash("You cannot deactivate your own account.", "error")
            return redirect(url_for("admin_users"))
        if account["role"] == "Administrator" and (
            requested_role != "Administrator" or requested_status != "Active"
        ):
            active_admins = get_db().execute(
                "SELECT COUNT(*) FROM staff_accounts WHERE role = 'Administrator' AND account_status = 'Active'"
            ).fetchone()[0]
            if active_admins <= 1:
                flash("The last active administrator cannot be deactivated or demoted.", "error")
                return redirect(url_for("admin_users"))

        get_db().execute(
            "UPDATE staff_accounts SET role = ?, account_status = ? WHERE staff_id = ?",
            (requested_role, requested_status, staff_id),
        )
        get_db().commit()
        flash("Staff account updated.", "success")
        return redirect(url_for("admin_users"))

    @app.route("/accept-invite/<token>", methods=["GET", "POST"])
    def accept_invite(token: str) -> Any:
        db = get_db()
        invited = db.execute(
            """
            SELECT staff_id, full_name, email FROM staff_accounts
            WHERE invite_token_hash = ? AND account_status = 'Invited' AND invite_expires_at >= ?
            """,
            (token_hash(token), timestamp()),
        ).fetchone()
        if invited is None:
            abort(404, description="This invitation is invalid or expired.")

        if request.method == "POST":
            password = request.form.get("password", "")
            confirmation = request.form.get("password_confirmation", "")
            if len(password) < 12:
                flash("Password must contain at least 12 characters.", "error")
            elif password != confirmation:
                flash("The passwords do not match.", "error")
            else:
                db.execute(
                    """
                    UPDATE staff_accounts
                    SET password_hash = ?, account_status = 'Active',
                        invite_token_hash = NULL, invite_expires_at = NULL
                    WHERE staff_id = ? AND account_status = 'Invited'
                    """,
                    (generate_password_hash(password), invited["staff_id"]),
                )
                db.commit()
                flash("Account activated. You can now sign in.", "success")
                return redirect(url_for("login"))

        return render_template("accept_invite.html", invited=invited)

    @app.route("/account/password", methods=["GET", "POST"])
    @login_required
    def change_password() -> Any:
        if request.method == "POST":
            current_password = request.form.get("current_password", "")
            new_password = request.form.get("new_password", "")
            confirmation = request.form.get("password_confirmation", "")
            account = get_db().execute(
                "SELECT password_hash FROM staff_accounts WHERE staff_id = ?",
                (g.user["staff_id"],),
            ).fetchone()
            if not account or not check_password_hash(account["password_hash"], current_password):
                flash("Current password is incorrect.", "error")
            elif len(new_password) < 12:
                flash("New password must contain at least 12 characters.", "error")
            elif new_password != confirmation:
                flash("The new passwords do not match.", "error")
            else:
                get_db().execute(
                    "UPDATE staff_accounts SET password_hash = ? WHERE staff_id = ?",
                    (generate_password_hash(new_password), g.user["staff_id"]),
                )
                get_db().commit()
                flash("Password updated.", "success")
                return redirect(url_for("change_password"))
        return render_template("change_password.html")

    return app


app = create_app()


if __name__ == "__main__":
    app.run()
