import os
import tempfile

_tmp = tempfile.mkdtemp()
os.environ.update(DATABASE_URL=f"sqlite:///{_tmp}/test.db", SECRET_KEY="test-secret", COOKIE_SECURE="false",
                  ADMIN_EMAIL="admin@test.io", ADMIN_PASSWORD="admin-password-123")

import re  # noqa: E402

import pytest  # noqa: E402
from fastapi.testclient import TestClient  # noqa: E402

from app.main import app  # noqa: E402


def token(client, path="/login"):
    html = client.get(path).text
    return re.search(r'name="csrf" value="([^"]+)"', html).group(1)


class Client:
    def __init__(self):
        self.c = TestClient(app, follow_redirects=False)

    def __enter__(self):
        self.c.__enter__()
        return self

    def __exit__(self, *a):
        self.c.__exit__(*a)

    def get(self, path):
        return self.c.get(path)

    def post(self, path, data=None, page="/account"):
        data = dict(data or {})
        data["csrf"] = token(self.c, page)
        return self.c.post(path, data=data)

    def login(self, email, pw):
        return self.post("/login", {"email": email, "password": pw}, page="/login")


@pytest.fixture(scope="session")
def admin():
    with Client() as c:
        assert c.login("admin@test.io", "admin-password-123").status_code == 303
        yield c
