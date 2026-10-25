"""Helpers partagés : templates Jinja, parsing de formulaires."""
from __future__ import annotations

from datetime import date, datetime
from pathlib import Path

from fastapi import HTTPException, Request
from fastapi.templating import Jinja2Templates

from . import google
from .auth import csrf_token

BASE = Path(__file__).parent
templates = Jinja2Templates(directory=str(BASE / "templates"))

DAYS_FR = ["lun.", "mar.", "mer.", "jeu.", "ven.", "sam.", "dim."]
MONTHS_FR = ["janv.", "févr.", "mars", "avr.", "mai", "juin", "juil.", "août", "sept.", "oct.", "nov.", "déc."]


def fdate(d: date | None, short: bool = False) -> str:
    if not d:
        return ""
    if short:
        return f"{DAYS_FR[d.weekday()]} {d.day} {MONTHS_FR[d.month - 1]}"
    return f"{DAYS_FR[d.weekday()]} {d.day} {MONTHS_FR[d.month - 1]} {d.year}"


def fperiod(a: date, b: date) -> str:
    if a == b:
        return fdate(a)
    if (a.year, a.month) == (b.year, b.month):
        return f"{a.day} au {b.day} {MONTHS_FR[b.month - 1]} {b.year}"
    return f"{a.day} {MONTHS_FR[a.month - 1]} au {b.day} {MONTHS_FR[b.month - 1]} {b.year}"


def money(x: float | None) -> str:
    if x is None:
        return ""
    return f"{x:,.2f}".replace(",", " ").replace(".", ",") + " €"


def num(x: float | None) -> str:
    return "" if x is None else f"{x:g}"


templates.env.filters.update(fdate=fdate, money=money, num=num, fperiod=fperiod)
templates.env.globals["csrf"] = csrf_token
templates.env.globals["google_enabled"] = google.enabled


def render(request: Request, name: str, **ctx):
    return templates.TemplateResponse(request, name, ctx)


def p_date(v) -> date | None:
    v = (v or "").strip()
    if not v:
        return None
    try:
        return datetime.strptime(v, "%Y-%m-%d").date()
    except ValueError:
        raise HTTPException(400, f"Date invalide : {v}")


def p_float(v, default: float = 0.0) -> float:
    v = (str(v) if v is not None else "").strip().replace(",", ".").replace(" ", "")
    if not v:
        return default
    try:
        return float(v)
    except ValueError:
        raise HTTPException(400, f"Nombre invalide : {v}")


def p_int(v, default: int = 0) -> int:
    return int(p_float(v, default))


def p_str(v) -> str:
    return (v or "").strip() if isinstance(v, str) else ""
