import hashlib
import hmac
import os
import secrets
import time
from collections import defaultdict

from fastapi import Depends, HTTPException, Request
from sqlalchemy.orm import Session

from .db import get_db
from .models import Company, User

_N, _R, _P = 2**14, 8, 1


def hash_password(password: str) -> str:
    salt = os.urandom(16)
    h = hashlib.scrypt(password.encode(), salt=salt, n=_N, r=_R, p=_P)
    return f"scrypt${salt.hex()}${h.hex()}"


def verify_password(password: str, stored: str) -> bool:
    try:
        _, salt, h = stored.split("$")
        calc = hashlib.scrypt(password.encode(), salt=bytes.fromhex(salt), n=_N, r=_R, p=_P)
        return hmac.compare_digest(calc, bytes.fromhex(h))
    except ValueError:
        return False


class LoginRequired(Exception):
    pass


def current_user(request: Request, db: Session = Depends(get_db)) -> User:
    uid = request.session.get("uid")
    user = db.get(User, uid) if uid else None
    if not user or not user.is_active:
        request.session.clear()
        raise LoginRequired()
    return user


def admin_required(user: User = Depends(current_user)) -> User:
    if not user.is_admin:
        raise HTTPException(403, "Réservé aux administrateurs")
    return user


def csrf_token(request: Request) -> str:
    tok = request.session.get("csrf")
    if not tok:
        tok = request.session["csrf"] = secrets.token_urlsafe(24)
    return tok


async def csrf_protect(request: Request):
    if request.method in ("GET", "HEAD", "OPTIONS"):
        return
    form = await request.form()
    sent = form.get("csrf") or request.headers.get("x-csrf-token", "")
    if not sent or not hmac.compare_digest(str(sent), request.session.get("csrf", "")):
        raise HTTPException(403, "Jeton CSRF invalide, rechargez la page")


def accessible_company(db: Session, user: User, company_id: int) -> Company:
    company = db.get(Company, company_id)
    if not company or not (user.is_admin or company in user.companies):
        raise HTTPException(404, "Compagnie introuvable")
    return company


# Limitation basique des tentatives de connexion (en mémoire, par IP + email).
_attempts: dict[str, list[float]] = defaultdict(list)
MAX_ATTEMPTS, WINDOW = 8, 300


def login_throttled(key: str) -> bool:
    now = time.time()
    _attempts[key] = [t for t in _attempts[key] if now - t < WINDOW]
    return len(_attempts[key]) >= MAX_ATTEMPTS


def login_failed(key: str) -> None:
    _attempts[key].append(time.time())


def login_ok(key: str) -> None:
    _attempts.pop(key, None)
