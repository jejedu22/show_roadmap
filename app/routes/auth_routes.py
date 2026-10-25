from fastapi import APIRouter, Depends, Form, Request
from fastapi.responses import RedirectResponse
from sqlalchemy import select
from sqlalchemy.orm import Session

from ..auth import (csrf_protect, current_user, hash_password, login_failed, login_ok, login_throttled,
                    verify_password)
from ..db import get_db
from ..models import User
from ..web import render

router = APIRouter(dependencies=[Depends(csrf_protect)])

_DUMMY = hash_password("dummy")  # évite de révéler l'existence d'un compte par le temps de réponse


@router.get("/login")
def login_form(request: Request):
    return render(request, "login.html", error=None)


@router.post("/login")
def login(request: Request, email: str = Form(""), password: str = Form(""), db: Session = Depends(get_db)):
    email = email.strip().lower()
    key = f"{request.client.host if request.client else '?'}|{email}"
    if login_throttled(key):
        return render(request, "login.html", error="Trop de tentatives, réessayez dans quelques minutes.")
    user = db.scalar(select(User).where(User.email == email))
    ok = verify_password(password, user.password_hash if user else _DUMMY)
    if not (user and ok and user.is_active):
        login_failed(key)
        return render(request, "login.html", error="Identifiants incorrects.")
    login_ok(key)
    request.session.clear()
    request.session["uid"] = user.id
    return RedirectResponse("/", status_code=303)


@router.post("/logout")
def logout(request: Request):
    request.session.clear()
    return RedirectResponse("/login", status_code=303)


@router.get("/account")
def account_form(request: Request, user: User = Depends(current_user)):
    return render(request, "account.html", user=user, msg=None)


@router.post("/account")
def account_save(request: Request, current: str = Form(""), new: str = Form(""), name: str = Form(""),
                 user: User = Depends(current_user), db: Session = Depends(get_db)):
    if name.strip():
        user.name = name.strip()
    msg = "Profil mis à jour."
    if new:
        if not verify_password(current, user.password_hash):
            return render(request, "account.html", user=user, msg="Mot de passe actuel incorrect.")
        if len(new) < 10:
            return render(request, "account.html", user=user, msg="Le nouveau mot de passe doit faire au moins 10 caractères.")
        user.password_hash = hash_password(new)
        msg = "Mot de passe modifié."
    db.commit()
    return render(request, "account.html", user=user, msg=msg)
