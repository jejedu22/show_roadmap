"""Client minimal de l'API SNCF (Navitia) : recherche de gares et d'itinéraires."""
from __future__ import annotations

import base64
import json
from datetime import datetime
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlencode
from urllib.request import Request, urlopen

from . import config


class SncfError(Exception):
    pass


def enabled() -> bool:
    return bool(config.SNCF_API_TOKEN)


def _get(path: str, params: list[tuple[str, str]]) -> dict:
    if not enabled():
        raise SncfError("SNCF_API_TOKEN non configuré")
    auth = base64.b64encode(f"{config.SNCF_API_TOKEN}:".encode()).decode()
    req = Request(f"{config.SNCF_API_URL}{path}?{urlencode(params)}", headers={"Authorization": f"Basic {auth}"})
    try:
        with urlopen(req, timeout=10) as r:
            return json.load(r)
    except HTTPError as e:
        if e.code in (401, 403):
            raise SncfError("Jeton SNCF refusé")
        if e.code == 404:  # Navitia : aucun itinéraire / lieu inconnu
            return {}
        raise SncfError(f"Erreur API SNCF ({e.code})")
    except (URLError, TimeoutError, ValueError):
        raise SncfError("API SNCF injoignable")


def search_places(q: str) -> list[dict]:
    data = _get("/places", [("q", q), ("type[]", "stop_area"), ("count", "8")])
    return [{"id": p["id"], "name": p["name"]} for p in data.get("places", []) if p.get("embedded_type") == "stop_area"]


def _hm(dt: str) -> str:
    d = datetime.strptime(dt, "%Y%m%dT%H%M%S")
    return f"{d.hour}h{d.minute:02d}"


def search_journeys(origin_id: str, dest_id: str, day: str, time: str = "00:00") -> list[dict]:
    """day = AAAA-MM-JJ, time = HH:MM. Retourne des itinéraires prêts à pré-remplir un trajet."""
    when = datetime.strptime(f"{day} {time or '00:00'}", "%Y-%m-%d %H:%M").strftime("%Y%m%dT%H%M%S")
    data = _get("/journeys", [("from", origin_id), ("to", dest_id), ("datetime", when),
                              ("datetime_represents", "departure"), ("count", "6"), ("data_freshness", "base_schedule")])
    out = []
    for j in data.get("journeys", []):
        rides = [s for s in j.get("sections", []) if s.get("type") == "public_transport"]
        if not rides:
            continue
        modes, numbers = [], []
        for s in rides:
            info = s.get("display_informations", {})
            mode = info.get("commercial_mode") or info.get("network") or "Train"
            if mode not in modes:
                modes.append(mode)
            if info.get("trip_short_name"):
                numbers.append(info["trip_short_name"])
        out.append({
            "dep_time": _hm(j["departure_date_time"]), "arr_time": _hm(j["arrival_date_time"]),
            "mode": " + ".join(modes), "transfers": len(rides) - 1, "duration_min": round(j.get("duration", 0) / 60),
            "numbers": " / ".join(numbers),
        })
    return out


def connect_url(origin: str = "", destination: str = "", day: str = "") -> str:
    return (config.SNCF_CONNECT_URL.replace("{origin}", quote(origin)).replace("{destination}", quote(destination))
            .replace("{date}", quote(day)))
