import re

from conftest import Client


def loc(r):
    return r.headers["location"]


def test_auth_required_and_csrf(admin):
    with Client() as anon:
        assert anon.get("/").status_code == 303 and loc(anon.get("/")) == "/login"
        assert anon.get("/events/1").status_code == 303
        r = anon.c.post("/login", data={"email": "admin@test.io", "password": "admin-password-123"})
        assert r.status_code == 403  # pas de jeton CSRF
        assert anon.login("admin@test.io", "mauvais").status_code == 200


def build_company(admin, name="Cie Test"):
    r = admin.post("/companies", {"name": name})
    cid = int(re.search(r"/companies/(\d+)", loc(r)).group(1))
    for ln, fn, fc in [("DURAND", "Anna", "Danseuse"), ("MARTIN", "Luc", "Régisseur lumière")]:
        admin.post(f"/companies/{cid}/people", {"last_name": ln, "first_name": fn, "function": fc, "home_city": "Paris"})
    admin.post(f"/companies/{cid}/shows", {"title": "Spectacle A"})
    admin.post(f"/companies/{cid}/shows", {"title": "Spectacle B"})
    page = admin.get(f"/companies/{cid}").text
    sids = [int(x) for x in re.findall(r"/shows/(\d+)/events", page)]
    return cid, sids


def test_overlap_and_quote(admin):
    cid, (a, b) = build_company(admin)
    r = admin.post(f"/shows/{a}/events", {"venue": "Théâtre X", "city": "Beauvais", "first_date": "2026-05-19", "last_date": "2026-05-21"})
    e1 = int(loc(r).split("/")[-1])
    r = admin.post(f"/shows/{b}/events", {"venue": "Salle Y", "first_date": "2026-05-20", "last_date": "2026-05-20"})
    e2 = int(loc(r).split("/")[-1])
    people = re.findall(r'name="person_id" value="(\d+)"', admin.get(f"/events/{e1}/team").text)
    assert len(people) == 2
    admin.post(f"/events/{e1}/team/add", {"person_id": people})
    admin.post(f"/events/{e2}/team/add", {"person_id": people[:1]})
    # la même personne sur deux dates simultanées → alerte
    assert "aussi sur" in admin.get(f"/events/{e1}/team").text
    assert "chevauche" in admin.get(f"/events/{e2}").text
    assert "simultané" in admin.get(f"/companies/{cid}").text

    # devis : 1 billet de 100 €, cachet 1000, repas 20,7
    part = re.search(r'name="role_(\d+)"', admin.get(f"/events/{e1}/team").text).group(1)
    admin.post(f"/events/{e1}/item/leg", {"participation_id": part, "direction": "outbound", "day": "2026-05-19", "price": "100,00", "origin": "Paris", "destination": "Beauvais"})
    admin.post(f"/events/{e1}/quote", {"fee": "1000", "meal_rate": "20,7", "breakfast_rate": "0", "night_rate": "0", "truck_rate": "1,1", "vat_rate": "5,5", "lighting": "0", "posters": "0"})
    admin.post(f"/events/{e1}/item/truck", {"label": "A/R", "km": "100"})
    from app.db import SessionLocal
    from app.models import Event
    from app.services import compute_quote
    with SessionLocal() as db:
        ev = db.get(Event, e1)
        q = compute_quote(ev)
        meals = sum(d.meals for p in ev.participations for d in p.days)
        assert q.truck == 110 and q.travel_tickets == 100
        assert q.meals == round(meals * 20.7, 2) and meals > 0
        assert q.total_ht == round(1000 + 110 + 100 + q.meals, 2)
        assert q.total_ttc == round(q.total_ht * 1.055, 2)
    for doc in ("roadmap", "rooming", "perdiem", "travelcost", "contacts", "quote"):
        assert admin.get(f"/events/{e1}/docs/{doc}").status_code == 200
    r = admin.get(f"/events/{e1}/export.xlsx")
    assert r.status_code == 200 and r.content[:2] == b"PK"
    assert admin.get(f"/events/{e1}/stay").status_code == 200
    assert admin.get(f"/events/{e1}/travel").status_code == 200
    assert admin.get(f"/events/{e1}/quote").status_code == 200
    assert admin.get(f"/events/{e1}/roadmap").status_code == 200
    assert admin.post(f"/events/{e1}/duplicate").status_code == 303


def test_tenant_isolation(admin):
    cid, (a, _) = build_company(admin, "Cie Privée")
    ev = int(loc(admin.post(f"/shows/{a}/events", {"venue": "V", "first_date": "2026-06-01"})).split("/")[-1])
    admin.post("/admin/users", {"email": "bob@test.io", "name": "Bob", "password": "bob-password-123"})
    with Client() as bob:
        assert bob.login("bob@test.io", "bob-password-123").status_code == 303
        assert bob.get(f"/events/{ev}").status_code == 404
        assert bob.get(f"/companies/{cid}").status_code == 404
        assert bob.get(f"/events/{ev}/export.xlsx").status_code == 404
        assert bob.post(f"/events/{ev}/delete").status_code == 404
        assert bob.get("/admin/users").status_code == 403
    # donner l'accès à Bob
    users = admin.get("/admin/users").text
    bob_id = re.search(r'/admin/users/(\d+)"[^>]*>\s*<input[^>]*>\s*<b>Bob', users).group(1)
    admin.post(f"/admin/users/{bob_id}", {"companies": str(cid), "is_active": "on"}, page="/admin/users")
    with Client() as bob:
        bob.login("bob@test.io", "bob-password-123")
        assert bob.get(f"/events/{ev}").status_code == 200
