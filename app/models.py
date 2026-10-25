from __future__ import annotations

from datetime import date, timedelta

from sqlalchemy import Boolean, Column, Date, Float, ForeignKey, Integer, String, Table, Text, UniqueConstraint
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, relationship


class Base(DeclarativeBase):
    pass


user_company = Table(
    "user_company",
    Base.metadata,
    Column("user_id", ForeignKey("users.id", ondelete="CASCADE"), primary_key=True),
    Column("company_id", ForeignKey("companies.id", ondelete="CASCADE"), primary_key=True),
)


class User(Base):
    __tablename__ = "users"
    id: Mapped[int] = mapped_column(primary_key=True)
    email: Mapped[str] = mapped_column(String(255), unique=True, index=True)
    name: Mapped[str] = mapped_column(String(255), default="")
    password_hash: Mapped[str] = mapped_column(String(255))
    is_admin: Mapped[bool] = mapped_column(Boolean, default=False)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True)
    companies: Mapped[list[Company]] = relationship(secondary=user_company, back_populates="users")


class Company(Base):
    __tablename__ = "companies"
    id: Mapped[int] = mapped_column(primary_key=True)
    name: Mapped[str] = mapped_column(String(255))
    notes: Mapped[str] = mapped_column(Text, default="")
    users: Mapped[list[User]] = relationship(secondary=user_company, back_populates="companies")
    shows: Mapped[list[Show]] = relationship(back_populates="company", cascade="all, delete-orphan", order_by="Show.title")
    people: Mapped[list[Person]] = relationship(
        back_populates="company", cascade="all, delete-orphan", order_by="Person.last_name"
    )


class Show(Base):
    """Un spectacle du répertoire de la compagnie."""

    __tablename__ = "shows"
    id: Mapped[int] = mapped_column(primary_key=True)
    company_id: Mapped[int] = mapped_column(ForeignKey("companies.id", ondelete="CASCADE"), index=True)
    title: Mapped[str] = mapped_column(String(255))
    description: Mapped[str] = mapped_column(Text, default="")
    company: Mapped[Company] = relationship(back_populates="shows")
    events: Mapped[list[Event]] = relationship(back_populates="show", cascade="all, delete-orphan")


class Person(Base):
    """Membre de la compagnie (artiste, technicien, production). Réutilisable sur plusieurs dates."""

    __tablename__ = "people"
    id: Mapped[int] = mapped_column(primary_key=True)
    company_id: Mapped[int] = mapped_column(ForeignKey("companies.id", ondelete="CASCADE"), index=True)
    last_name: Mapped[str] = mapped_column(String(120))
    first_name: Mapped[str] = mapped_column(String(120), default="")
    function: Mapped[str] = mapped_column(String(120), default="")
    phone: Mapped[str] = mapped_column(String(60), default="")
    email: Mapped[str] = mapped_column(String(255), default="")
    diet: Mapped[str] = mapped_column(String(255), default="")
    home_city: Mapped[str] = mapped_column(String(120), default="")
    company: Mapped[Company] = relationship(back_populates="people")

    @property
    def full_name(self) -> str:
        return f"{self.last_name.upper()} {self.first_name}".strip()


class Event(Base):
    """Une date de tournée : un spectacle dans un lieu pour une période."""

    __tablename__ = "events"
    id: Mapped[int] = mapped_column(primary_key=True)
    show_id: Mapped[int] = mapped_column(ForeignKey("shows.id", ondelete="CASCADE"), index=True)
    venue: Mapped[str] = mapped_column(String(255))
    city: Mapped[str] = mapped_column(String(120), default="")
    address: Mapped[str] = mapped_column(String(255), default="")
    venue_contact: Mapped[str] = mapped_column(Text, default="")
    status: Mapped[str] = mapped_column(String(20), default="confirmed")  # option / confirmed / cancelled
    first_date: Mapped[date] = mapped_column(Date)  # 1re représentation
    last_date: Mapped[date] = mapped_column(Date)  # dernière représentation
    tech_arrival: Mapped[date | None] = mapped_column(Date, nullable=True)
    artistic_arrival: Mapped[date | None] = mapped_column(Date, nullable=True)
    departure: Mapped[date | None] = mapped_column(Date, nullable=True)
    performances: Mapped[int] = mapped_column(Integer, default=1)
    notes: Mapped[str] = mapped_column(Text, default="")
    # Tarifs du devis
    meal_rate: Mapped[float] = mapped_column(Float, default=20.7)
    breakfast_rate: Mapped[float] = mapped_column(Float, default=7.0)
    night_rate: Mapped[float] = mapped_column(Float, default=0.0)  # 0 = nuitées prises en charge par l'organisateur
    truck_rate: Mapped[float] = mapped_column(Float, default=1.1)  # € HT / km
    vat_rate: Mapped[float] = mapped_column(Float, default=5.5)
    fee: Mapped[float] = mapped_column(Float, default=0.0)  # cachet HT
    lighting: Mapped[float] = mapped_column(Float, default=0.0)  # location lumière HT
    posters: Mapped[float] = mapped_column(Float, default=0.0)

    show: Mapped[Show] = relationship(back_populates="events")
    participations: Mapped[list[Participation]] = relationship(
        back_populates="event", cascade="all, delete-orphan", order_by="Participation.id"
    )
    lodgings: Mapped[list[Lodging]] = relationship(back_populates="event", cascade="all, delete-orphan")
    trucks: Mapped[list[TruckLeg]] = relationship(back_populates="event", cascade="all, delete-orphan")
    extras: Mapped[list[ExtraCost]] = relationship(back_populates="event", cascade="all, delete-orphan")
    sections: Mapped[list[RoadmapSection]] = relationship(
        back_populates="event", cascade="all, delete-orphan", order_by="RoadmapSection.position"
    )
    schedule: Mapped[list[ScheduleItem]] = relationship(
        back_populates="event", cascade="all, delete-orphan", order_by="ScheduleItem.day, ScheduleItem.time"
    )

    @property
    def start(self) -> date:
        return min(d for d in (self.tech_arrival, self.artistic_arrival, self.first_date) if d)

    @property
    def end(self) -> date:
        return max(d for d in (self.departure, self.last_date) if d)

    @property
    def span_dates(self) -> list[date]:
        return [self.start + timedelta(days=i) for i in range((self.end - self.start).days + 1)]

    @property
    def title(self) -> str:
        return f"{self.show.title} — {self.venue}"


class Participation(Base):
    """Une personne rattachée à une date (équipe de la date)."""

    __tablename__ = "participations"
    __table_args__ = (UniqueConstraint("event_id", "person_id"),)
    id: Mapped[int] = mapped_column(primary_key=True)
    event_id: Mapped[int] = mapped_column(ForeignKey("events.id", ondelete="CASCADE"), index=True)
    person_id: Mapped[int] = mapped_column(ForeignKey("people.id", ondelete="CASCADE"), index=True)
    role: Mapped[str] = mapped_column(String(120), default="")
    room_type: Mapped[str] = mapped_column(String(40), default="single")
    departure_city: Mapped[str] = mapped_column(String(120), default="")
    lodging_id: Mapped[int | None] = mapped_column(ForeignKey("lodgings.id", ondelete="SET NULL"), nullable=True)
    note: Mapped[str] = mapped_column(String(255), default="")

    event: Mapped[Event] = relationship(back_populates="participations")
    person: Mapped[Person] = relationship()
    lodging: Mapped[Lodging | None] = relationship()
    days: Mapped[list[Day]] = relationship(back_populates="participation", cascade="all, delete-orphan")
    legs: Mapped[list[Leg]] = relationship(
        back_populates="participation", cascade="all, delete-orphan", order_by="Leg.day, Leg.dep_time"
    )

    def day(self, d: date) -> Day | None:
        return next((x for x in self.days if x.day == d), None)


class Day(Base):
    """Nuitée / repas / petit-déjeuner d'une personne pour un jour donné."""

    __tablename__ = "days"
    __table_args__ = (UniqueConstraint("participation_id", "day"),)
    id: Mapped[int] = mapped_column(primary_key=True)
    participation_id: Mapped[int] = mapped_column(ForeignKey("participations.id", ondelete="CASCADE"), index=True)
    day: Mapped[date] = mapped_column(Date)
    night: Mapped[int] = mapped_column(Integer, default=0)  # nuit du jour J au J+1
    meals: Mapped[int] = mapped_column(Integer, default=0)  # midi + soir
    breakfast: Mapped[int] = mapped_column(Integer, default=0)
    participation: Mapped[Participation] = relationship(back_populates="days")


class Lodging(Base):
    __tablename__ = "lodgings"
    id: Mapped[int] = mapped_column(primary_key=True)
    event_id: Mapped[int] = mapped_column(ForeignKey("events.id", ondelete="CASCADE"), index=True)
    name: Mapped[str] = mapped_column(String(255))
    address: Mapped[str] = mapped_column(String(255), default="")
    phone: Mapped[str] = mapped_column(String(60), default="")
    details: Mapped[str] = mapped_column(Text, default="")  # horaires, accès, code, distances…
    event: Mapped[Event] = relationship(back_populates="lodgings")


class Leg(Base):
    """Un trajet (billet). Prix négatif = échange / remboursement."""

    __tablename__ = "legs"
    id: Mapped[int] = mapped_column(primary_key=True)
    participation_id: Mapped[int] = mapped_column(ForeignKey("participations.id", ondelete="CASCADE"), index=True)
    direction: Mapped[str] = mapped_column(String(10), default="outbound")  # outbound / return
    day: Mapped[date] = mapped_column(Date)
    origin: Mapped[str] = mapped_column(String(120), default="")
    destination: Mapped[str] = mapped_column(String(120), default="")
    dep_time: Mapped[str] = mapped_column(String(20), default="")
    arr_time: Mapped[str] = mapped_column(String(20), default="")
    mode: Mapped[str] = mapped_column(String(60), default="Train")
    reference: Mapped[str] = mapped_column(String(60), default="")
    price: Mapped[float] = mapped_column(Float, default=0.0)
    booked_on: Mapped[date | None] = mapped_column(Date, nullable=True)
    note: Mapped[str] = mapped_column(String(255), default="")
    participation: Mapped[Participation] = relationship(back_populates="legs")


class TruckLeg(Base):
    __tablename__ = "truck_legs"
    id: Mapped[int] = mapped_column(primary_key=True)
    event_id: Mapped[int] = mapped_column(ForeignKey("events.id", ondelete="CASCADE"), index=True)
    label: Mapped[str] = mapped_column(String(255), default="")
    km: Mapped[float] = mapped_column(Float, default=0.0)
    event: Mapped[Event] = relationship(back_populates="trucks")


class ExtraCost(Base):
    __tablename__ = "extra_costs"
    id: Mapped[int] = mapped_column(primary_key=True)
    event_id: Mapped[int] = mapped_column(ForeignKey("events.id", ondelete="CASCADE"), index=True)
    label: Mapped[str] = mapped_column(String(255))
    amount: Mapped[float] = mapped_column(Float, default=0.0)
    kind: Mapped[str] = mapped_column(String(20), default="other")  # team_transport / other
    event: Mapped[Event] = relationship(back_populates="extras")


class RoadmapSection(Base):
    """Bloc libre de la feuille de route (ateliers, accès, wifi, repas…)."""

    __tablename__ = "roadmap_sections"
    id: Mapped[int] = mapped_column(primary_key=True)
    event_id: Mapped[int] = mapped_column(ForeignKey("events.id", ondelete="CASCADE"), index=True)
    title: Mapped[str] = mapped_column(String(255))
    body: Mapped[str] = mapped_column(Text, default="")
    position: Mapped[int] = mapped_column(Integer, default=0)
    event: Mapped[Event] = relationship(back_populates="sections")


class ScheduleItem(Base):
    __tablename__ = "schedule_items"
    id: Mapped[int] = mapped_column(primary_key=True)
    event_id: Mapped[int] = mapped_column(ForeignKey("events.id", ondelete="CASCADE"), index=True)
    day: Mapped[date] = mapped_column(Date)
    time: Mapped[str] = mapped_column(String(20), default="")
    label: Mapped[str] = mapped_column(String(255))
    event: Mapped[Event] = relationship(back_populates="schedule")
