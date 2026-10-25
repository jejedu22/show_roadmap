"""Calculs métier : devis, défraiements, conflits de planning, génération des grilles."""
from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, timedelta

from sqlalchemy import select
from sqlalchemy.orm import Session

from .models import Day, Event, Participation, Person, Show


def r2(x: float) -> float:
    return round(x + 0.0, 2)


# ---------- Grilles nuitées / repas / petits-déjeuners ----------

def default_days(event: Event, role: str) -> list[tuple[date, int, int, int]]:
    """Valeurs par défaut (jour, nuit, repas, petit-déj) pour un nouveau participant."""
    tech = "régi" in role.lower() or "techni" in role.lower()
    arrival = (event.tech_arrival if tech else event.artistic_arrival) or event.start
    departure = event.departure or event.last_date
    out = []
    d = arrival
    while d <= departure:
        night = 1 if d < departure else 0
        if d == arrival:
            meals = 1 if d < event.first_date else 2
        elif d == departure:
            meals = 1
        else:
            meals = 2
        bf = 0 if d == arrival else 1
        out.append((d, night, meals, bf))
        d += timedelta(days=1)
    return out


def fill_default_days(db: Session, part: Participation) -> None:
    for d, n, m, b in default_days(part.event, part.role):
        db.add(Day(participation_id=part.id, day=d, night=n, meals=m, breakfast=b))


# ---------- Devis ----------

@dataclass
class Quote:
    fee: float
    lighting: float
    truck_km: float
    truck: float
    travel_tickets: float
    team_extras: float
    team_transport: float
    meals_count: int
    meals: float
    breakfasts_count: int
    breakfasts: float
    nights_count: int
    nights: float
    posters: float
    other_extras: float
    annex_total: float
    total_ht: float
    vat: float
    total_ttc: float
    lines: list[tuple[str, float]] = field(default_factory=list)


def truck_scale(km: float, performances: int) -> str:
    """Barème indicatif repris du mémo « Transport décor » des devis."""
    if km <= 0:
        return ""
    if km <= 350:
        forfait = {1: 600, 2: 650}.get(performances, 700)
        return f"≤ 350 km : forfait {forfait} € HT ({performances} représentation(s))"
    if km <= 750:
        return f"350–750 km : 1,30 € HT/km → {r2(km * 1.3)} € HT"
    return f"750–1000 km : 1,10 € HT/km → {r2(km * 1.1)} € HT"


def compute_quote(event: Event) -> Quote:
    meals_count = sum(d.meals for p in event.participations for d in p.days)
    bf_count = sum(d.breakfast for p in event.participations for d in p.days)
    nights_count = sum(d.night for p in event.participations for d in p.days)
    tickets = sum(l.price for p in event.participations for l in p.legs)
    team_extras = sum(e.amount for e in event.extras if e.kind == "team_transport")
    other = sum(e.amount for e in event.extras if e.kind != "team_transport")
    km = sum(t.km for t in event.trucks)
    truck = km * event.truck_rate
    meals = meals_count * event.meal_rate
    breakfasts = bf_count * event.breakfast_rate
    nights = nights_count * event.night_rate
    team_transport = tickets + team_extras
    annex = event.lighting + truck + team_transport + meals + breakfasts + nights + event.posters + other
    total_ht = event.fee + annex
    vat = total_ht * event.vat_rate / 100
    q = Quote(
        fee=r2(event.fee), lighting=r2(event.lighting), truck_km=km, truck=r2(truck),
        travel_tickets=r2(tickets), team_extras=r2(team_extras), team_transport=r2(team_transport),
        meals_count=meals_count, meals=r2(meals), breakfasts_count=bf_count, breakfasts=r2(breakfasts),
        nights_count=nights_count, nights=r2(nights), posters=r2(event.posters), other_extras=r2(other),
        annex_total=r2(annex), total_ht=r2(total_ht), vat=r2(vat), total_ttc=r2(total_ht + vat),
    )
    q.lines = [
        ("Location lumière", q.lighting),
        (f"Transport décor ({km:g} km × {event.truck_rate:g} €)", q.truck),
        ("Transport équipe (billets + transports locaux)", q.team_transport),
        (f"Défraiements repas ({meals_count} × {event.meal_rate:g} €)", q.meals),
        (f"Petits-déjeuners ({bf_count} × {event.breakfast_rate:g} €)", q.breakfasts),
        (f"Nuitées ({nights_count} × {event.night_rate:g} €)", q.nights),
        ("Affiches", q.posters),
        ("Autres frais", q.other_extras),
    ]
    return q


@dataclass
class PersonCosts:
    part: Participation
    meals: int
    breakfasts: int
    nights: int
    per_diem: float
    outbound: float
    ret: float


def person_costs(event: Event) -> list[PersonCosts]:
    rows = []
    for p in event.participations:
        meals = sum(d.meals for d in p.days)
        bf = sum(d.breakfast for d in p.days)
        nights = sum(d.night for d in p.days)
        rows.append(PersonCosts(
            p, meals, bf, nights,
            r2(meals * event.meal_rate + bf * event.breakfast_rate),
            r2(sum(l.price for l in p.legs if l.direction == "outbound")),
            r2(sum(l.price for l in p.legs if l.direction == "return")),
        ))
    return rows


# ---------- Conflits de planning ----------

def company_events(db: Session, company_id: int) -> list[Event]:
    return list(db.scalars(
        select(Event).join(Show).where(Show.company_id == company_id, Event.status != "cancelled")
        .order_by(Event.first_date)
    ))


def overlapping(a: Event, b: Event) -> bool:
    return a.id != b.id and a.start <= b.end and b.start <= a.end


def event_overlaps(db: Session, event: Event) -> list[Event]:
    return [e for e in company_events(db, event.show.company_id) if overlapping(event, e)]


def person_conflicts(db: Session, event: Event) -> dict[int, list[Event]]:
    """person_id -> autres dates de la compagnie qui chevauchent celle-ci et où la personne est aussi engagée."""
    others = event_overlaps(db, event)
    out: dict[int, list[Event]] = {}
    for o in others:
        for p in o.participations:
            out.setdefault(p.person_id, []).append(o)
    mine = {p.person_id for p in event.participations}
    return {pid: evs for pid, evs in out.items() if pid in mine}


def person_label(p: Person) -> str:
    return p.full_name
