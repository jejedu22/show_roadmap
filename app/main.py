from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from sqlalchemy import select
from starlette.middleware.sessions import SessionMiddleware

from . import config
from .auth import LoginRequired, hash_password
from .db import SessionLocal, engine
from .models import Base, User
from .routes import admin, auth_routes, core, events
from .web import render


def bootstrap_admin() -> None:
    with SessionLocal() as db:
        if db.scalar(select(User).limit(1)):
            return
        if config.ADMIN_EMAIL and config.ADMIN_PASSWORD:
            db.add(User(email=config.ADMIN_EMAIL.lower(), name="Administrateur",
                        password_hash=hash_password(config.ADMIN_PASSWORD), is_admin=True))
            db.commit()
            print(f"[bootstrap] administrateur créé : {config.ADMIN_EMAIL}")
        else:
            print("[bootstrap] aucun utilisateur : définissez ADMIN_EMAIL et ADMIN_PASSWORD")


@asynccontextmanager
async def lifespan(app: FastAPI):
    if config.DATABASE_URL.startswith("sqlite:///"):
        Path(config.DATABASE_URL.removeprefix("sqlite:///")).parent.mkdir(parents=True, exist_ok=True)
    Base.metadata.create_all(engine)
    bootstrap_admin()
    yield


def create_app() -> FastAPI:
    if not config.SECRET_KEY:
        raise RuntimeError("SECRET_KEY est obligatoire (voir .env.example)")
    app = FastAPI(title="Show Roadmap", lifespan=lifespan, docs_url=None, redoc_url=None, openapi_url=None)
    app.add_middleware(
        SessionMiddleware, secret_key=config.SECRET_KEY, max_age=config.SESSION_MAX_AGE,
        same_site="lax", https_only=config.COOKIE_SECURE, session_cookie="roadmap_session",
    )
    app.mount("/static", StaticFiles(directory=Path(__file__).parent / "static"), name="static")

    @app.exception_handler(LoginRequired)
    async def _login_required(request: Request, exc: LoginRequired):
        return RedirectResponse("/login", status_code=303)

    @app.exception_handler(404)
    @app.exception_handler(403)
    @app.exception_handler(400)
    async def _http_error(request: Request, exc):
        resp = render(request, "error.html", code=exc.status_code, detail=getattr(exc, "detail", ""))
        resp.status_code = exc.status_code
        return resp

    @app.get("/healthz", response_class=HTMLResponse, include_in_schema=False)
    def healthz():
        return "ok"

    for r in (auth_routes.router, core.router, events.router, admin.router):
        app.include_router(r)
    return app


app = create_app() if config.SECRET_KEY else None
