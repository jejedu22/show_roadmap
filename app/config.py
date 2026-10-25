import os


def _bool(name: str, default: bool) -> bool:
    return os.getenv(name, str(default)).strip().lower() in ("1", "true", "yes", "on")


DATABASE_URL = os.getenv("DATABASE_URL", "sqlite:////data/app.db")
SECRET_KEY = os.getenv("SECRET_KEY", "")
# Cookie "Secure" : à laisser actif derrière Traefik en HTTPS.
COOKIE_SECURE = _bool("COOKIE_SECURE", True)
SESSION_MAX_AGE = int(os.getenv("SESSION_MAX_AGE", str(60 * 60 * 12)))
ADMIN_EMAIL = os.getenv("ADMIN_EMAIL", "")
ADMIN_PASSWORD = os.getenv("ADMIN_PASSWORD", "")

# Recherche d'horaires (API SNCF / Navitia). Jeton gratuit : https://numerique.sncf.com/startup/api/
SNCF_API_TOKEN = os.getenv("SNCF_API_TOKEN", "")
SNCF_API_URL = os.getenv("SNCF_API_URL", "https://api.sncf.com/v1/coverage/sncf")
# Lien « Ouvrir SNCF Connect » ; placeholders possibles : {origin} {destination} {date} (AAAA-MM-JJ), déjà encodés.
SNCF_CONNECT_URL = os.getenv("SNCF_CONNECT_URL", "https://www.sncf-connect.com/")
