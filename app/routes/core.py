from fastapi import APIRouter, Depends, Form, HTTPException, Request
from fastapi.responses import RedirectResponse
from sqlalchemy import select
from sqlalchemy.orm import Session

from ..auth import accessible_company, csrf_protect, current_user
from ..db import get_db
from ..models import Company, Event, Person, Show, User
from ..services import company_events, overlapping
from ..web import p_date, p_int, p_str, render

router = APIRouter(dependencies=[Depends(csrf_protect)])


def user_companies(db: Session, user: User) -> list[Company]:
    if user.is_admin:
        return list(db.scalars(select(Company).order_by(Company.name)))
    return sorted(user.companies, key=lambda c: c.name)


@router.get("/")
def home(request: Request, user: User = Depends(current_user), db: Session = Depends(get_db)):
    companies = user_companies(db, user)
    if len(companies) == 1:
        return RedirectResponse(f"/companies/{companies[0].id}", status_code=303)
    return render(request, "home.html", user=user, companies=companies, nav_companies=companies)


@router.post("/companies")
def create_company(name: str = Form(...), user: User = Depends(current_user), db: Session = Depends(get_db)):
    c = Company(name=name.strip() or "Nouvelle compagnie")
    c.users.append(user)
    db.add(c)
    db.commit()
    return RedirectResponse(f"/companies/{c.id}", status_code=303)


@router.get("/companies/{cid}")
def company_page(cid: int, request: Request, user: User = Depends(current_user), db: Session = Depends(get_db)):
    c = accessible_company(db, user, cid)
    events = company_events(db, cid)
    conflicts = {e.id: [o for o in events if overlapping(e, o)] for e in events}
    return render(request, "company.html", user=user, company=c, events=events, conflicts=conflicts,
                  nav_companies=user_companies(db, user))


@router.post("/companies/{cid}")
def update_company(cid: int, name: str = Form(...), notes: str = Form(""), user: User = Depends(current_user),
                   db: Session = Depends(get_db)):
    c = accessible_company(db, user, cid)
    c.name, c.notes = name.strip() or c.name, notes
    db.commit()
    return RedirectResponse(f"/companies/{cid}", status_code=303)


# ---- Spectacles ----

@router.post("/companies/{cid}/shows")
def create_show(cid: int, title: str = Form(...), description: str = Form(""), user: User = Depends(current_user),
                db: Session = Depends(get_db)):
    accessible_company(db, user, cid)
    db.add(Show(company_id=cid, title=title.strip(), description=description))
    db.commit()
    return RedirectResponse(f"/companies/{cid}", status_code=303)


def _show(db, user, sid) -> Show:
    s = db.get(Show, sid)
    if not s:
        raise HTTPException(404)
    accessible_company(db, user, s.company_id)
    return s


@router.post("/shows/{sid}")
def update_show(sid: int, title: str = Form(...), description: str = Form(""), user: User = Depends(current_user),
                db: Session = Depends(get_db)):
    s = _show(db, user, sid)
    s.title, s.description = title.strip() or s.title, description
    db.commit()
    return RedirectResponse(f"/companies/{s.company_id}", status_code=303)


@router.post("/shows/{sid}/delete")
def delete_show(sid: int, user: User = Depends(current_user), db: Session = Depends(get_db)):
    s = _show(db, user, sid)
    cid = s.company_id
    db.delete(s)
    db.commit()
    return RedirectResponse(f"/companies/{cid}", status_code=303)


# ---- Personnes ----

PERSON_FIELDS = ("last_name", "first_name", "function", "phone", "email", "diet", "home_city")


@router.get("/companies/{cid}/people")
def people_page(cid: int, request: Request, user: User = Depends(current_user), db: Session = Depends(get_db)):
    c = accessible_company(db, user, cid)
    return render(request, "people.html", user=user, company=c, nav_companies=user_companies(db, user))


@router.post("/companies/{cid}/people")
async def save_person(cid: int, request: Request, user: User = Depends(current_user), db: Session = Depends(get_db)):
    accessible_company(db, user, cid)
    form = await request.form()
    pid = p_int(form.get("id"))
    if pid:
        p = db.get(Person, pid)
        if not p or p.company_id != cid:
            raise HTTPException(404)
    else:
        p = Person(company_id=cid, last_name="")
        db.add(p)
    for f in PERSON_FIELDS:
        setattr(p, f, p_str(form.get(f)))
    if not p.last_name:
        raise HTTPException(400, "Le nom est obligatoire")
    db.commit()
    return RedirectResponse(f"/companies/{cid}/people", status_code=303)


@router.post("/companies/{cid}/people/{pid}/delete")
def delete_person(cid: int, pid: int, user: User = Depends(current_user), db: Session = Depends(get_db)):
    accessible_company(db, user, cid)
    p = db.get(Person, pid)
    if not p or p.company_id != cid:
        raise HTTPException(404)
    db.delete(p)
    db.commit()
    return RedirectResponse(f"/companies/{cid}/people", status_code=303)


# ---- Création d'une date ----

@router.post("/shows/{sid}/events")
def create_event(sid: int, venue: str = Form(...), city: str = Form(""), first_date: str = Form(...),
                 last_date: str = Form(""), user: User = Depends(current_user), db: Session = Depends(get_db)):
    s = _show(db, user, sid)
    first = p_date(first_date)
    last = p_date(last_date) or first
    if not first or last < first:
        raise HTTPException(400, "Dates invalides")
    ev = Event(show_id=sid, venue=venue.strip(), city=city.strip(), first_date=first, last_date=last,
               performances=(last - first).days + 1)
    ev.departure = last
    db.add(ev)
    db.commit()
    return RedirectResponse(f"/events/{ev.id}", status_code=303)
