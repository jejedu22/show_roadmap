from fastapi import APIRouter, Depends, Form, HTTPException, Request
from fastapi.responses import RedirectResponse
from sqlalchemy import select
from sqlalchemy.orm import Session

from ..auth import admin_required, csrf_protect, hash_password
from ..db import get_db
from ..models import Company, User
from ..web import render

router = APIRouter(prefix="/admin", dependencies=[Depends(csrf_protect)])


@router.get("/users")
def users(request: Request, admin: User = Depends(admin_required), db: Session = Depends(get_db)):
    return render(request, "admin_users.html", user=admin, users=db.scalars(select(User).order_by(User.email)).all(),
                  companies=db.scalars(select(Company).order_by(Company.name)).all(), error=None)


@router.post("/users")
def create_user(request: Request, email: str = Form(...), name: str = Form(""), password: str = Form(...),
                is_admin: str = Form(""), admin: User = Depends(admin_required), db: Session = Depends(get_db)):
    email = email.strip().lower()
    if len(password) < 10 or "@" not in email or db.scalar(select(User).where(User.email == email)):
        raise HTTPException(400, "E-mail invalide ou déjà utilisé, ou mot de passe < 10 caractères")
    db.add(User(email=email, name=name.strip(), password_hash=hash_password(password), is_admin=bool(is_admin)))
    db.commit()
    return RedirectResponse("/admin/users", status_code=303)


@router.post("/users/{uid}")
async def update_user(uid: int, request: Request, admin: User = Depends(admin_required), db: Session = Depends(get_db)):
    u = db.get(User, uid)
    if not u:
        raise HTTPException(404)
    form = await request.form()
    if u.id != admin.id:  # on ne peut pas se retirer ses propres droits
        u.is_admin = "is_admin" in form
        u.is_active = "is_active" in form
    ids = {int(x) for x in form.getlist("companies") if str(x).isdigit()}
    u.companies = list(db.scalars(select(Company).where(Company.id.in_(ids)))) if ids else []
    if form.get("password"):
        if len(form["password"]) < 10:
            raise HTTPException(400, "Mot de passe < 10 caractères")
        u.password_hash = hash_password(form["password"])
    db.commit()
    return RedirectResponse("/admin/users", status_code=303)
