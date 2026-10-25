"""Données de démonstration (fictives) : `python -m app.seed`.

Deux spectacles de la même compagnie jouent sur des dates qui se chevauchent,
avec une personne commune, pour illustrer la détection de conflit.
"""
from datetime import date

from sqlalchemy import select

from .db import SessionLocal, engine
from .models import (Base, Company, Event, ExtraCost, Leg, Lodging, Participation, Person, RoadmapSection,
                     ScheduleItem, Show, TruckLeg, User)
from .services import fill_default_days


def main() -> None:
    Base.metadata.create_all(engine)
    with SessionLocal() as db:
        if db.scalar(select(Company).where(Company.name == "Compagnie Démo")):
            print("Données de démo déjà présentes.")
            return
        c = Company(name="Compagnie Démo")
        c.users = list(db.scalars(select(User)))
        murmure, prelude = Show(title="Le Murmure des songes"), Show(title="Prélude")
        c.shows = [murmure, prelude]
        people = [
            Person(last_name="MARTIN", first_name="Alice", function="Admin tournée", home_city="Lyon", phone="06 00 00 00 01", email="alice@example.org"),
            Person(last_name="DURAND", first_name="Bruno", function="Régisseur lumière", home_city="Marseille", phone="06 00 00 00 02", email="bruno@example.org"),
            Person(last_name="LEROY", first_name="Chloé", function="Danseuse", home_city="Paris", phone="06 00 00 00 03", email="chloe@example.org", diet="Végétarienne"),
            Person(last_name="PETIT", first_name="David", function="Danseur", home_city="Lille", phone="06 00 00 00 04", email="david@example.org"),
            Person(last_name="MOREAU", first_name="Emma", function="Danseuse", home_city="Paris", phone="06 00 00 00 05", email="emma@example.org"),
        ]
        c.people = people
        db.add(c)
        db.flush()

        ev1 = Event(show_id=murmure.id, venue="Théâtre du Beauvaisis — Scène nationale", city="Beauvais",
                    address="3 place Georges Brassens, 60000 Beauvais", first_date=date(2026, 5, 19), last_date=date(2026, 5, 23),
                    tech_arrival=date(2026, 5, 16), artistic_arrival=date(2026, 5, 18), departure=date(2026, 5, 23),
                    performances=3, fee=9000, lighting=450, venue_contact="Accueil : Jeanne Exemple — 03 00 00 00 00")
        ev2 = Event(show_id=prelude.id, venue="Festival Exemple", city="Strasbourg", first_date=date(2026, 5, 21),
                    last_date=date(2026, 5, 22), artistic_arrival=date(2026, 5, 20), departure=date(2026, 5, 23), performances=2, fee=5000)
        db.add_all([ev1, ev2])
        db.flush()
        hotel = Lodging(event_id=ev1.id, name="Hôtel Cathédrale", address="11 rue Chambiges, 60000 Beauvais",
                        details="Check-in 15h / check-out 10h30. Petit-déjeuner 7h–9h.")
        db.add(hotel)
        db.flush()
        for p in people[:4]:
            part = Participation(event_id=ev1.id, person_id=p.id, role=p.function, departure_city=p.home_city, lodging_id=hotel.id)
            db.add(part)
            db.flush()
            db.refresh(part)
            fill_default_days(db, part)
        for p in (people[3], people[4]):  # David est aussi sur Prélude → conflit signalé
            part = Participation(event_id=ev2.id, person_id=p.id, role=p.function, departure_city=p.home_city)
            db.add(part)
            db.flush()
            db.refresh(part)
            fill_default_days(db, part)
        first = db.scalars(select(Participation).where(Participation.event_id == ev1.id)).first()
        db.add_all([
            Leg(participation_id=first.id, direction="outbound", day=date(2026, 5, 16), origin="Lyon", destination="Paris",
                dep_time="9h00", arr_time="11h00", mode="TGV", reference="ABC123", price=79),
            Leg(participation_id=first.id, direction="return", day=date(2026, 5, 23), origin="Beauvais", destination="Paris",
                dep_time="19h39", arr_time="20h57", mode="TER", reference="XYZ789", price=12.4),
            TruckLeg(event_id=ev1.id, label="Paris → Beauvais", km=90),
            ExtraCost(event_id=ev1.id, label="Métro Paris (2 × 4)", amount=8, kind="team_transport"),
            RoadmapSection(event_id=ev1.id, title="ACCÈS AU THÉÂTRE", position=10,
                           body="Entrée par l'arrière du bâtiment, quai de déchargement."),
            ScheduleItem(event_id=ev1.id, day=date(2026, 5, 18), time="10h–18h", label="Montage et réglages"),
        ])
        db.commit()
        print("Données de démo créées.")


if __name__ == "__main__":
    main()
