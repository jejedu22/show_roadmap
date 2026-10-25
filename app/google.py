"""Connexion Google : flux « authorization code » ; l'identité est lue via l'endpoint userinfo."""
from __future__ import annotations

import json
from urllib.error import URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

from . import config

AUTH_URL = "https://accounts.google.com/o/oauth2/v2/auth"
TOKEN_URL = "https://oauth2.googleapis.com/token"
USERINFO_URL = "https://openidconnect.googleapis.com/v1/userinfo"


class GoogleError(Exception):
    pass


def enabled() -> bool:
    return bool(config.GOOGLE_CLIENT_ID and config.GOOGLE_CLIENT_SECRET)


def authorize_url(redirect_uri: str, state: str) -> str:
    return AUTH_URL + "?" + urlencode({
        "client_id": config.GOOGLE_CLIENT_ID, "redirect_uri": redirect_uri, "response_type": "code",
        "scope": "openid email profile", "state": state, "prompt": "select_account",
    })


def fetch_identity(code: str, redirect_uri: str) -> dict:
    """Échange le code contre un jeton puis retourne {email, email_verified, name}."""
    try:
        body = urlencode({"code": code, "client_id": config.GOOGLE_CLIENT_ID, "client_secret": config.GOOGLE_CLIENT_SECRET,
                          "redirect_uri": redirect_uri, "grant_type": "authorization_code"}).encode()
        with urlopen(Request(TOKEN_URL, data=body), timeout=10) as r:
            token = json.load(r)["access_token"]
        with urlopen(Request(USERINFO_URL, headers={"Authorization": f"Bearer {token}"}), timeout=10) as r:
            info = json.load(r)
    except (URLError, TimeoutError, ValueError, KeyError):
        raise GoogleError("Échec de la connexion avec Google")
    return {"email": (info.get("email") or "").lower(), "email_verified": info.get("email_verified") is True,
            "name": info.get("name", "")}
