from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.staticfiles import StaticFiles

from app.database import mysql_engine, pg_engine
from app.routers import clear, get_ip, index, oauth, reserve


@asynccontextmanager
async def lifespan(app: FastAPI):
    yield
    await mysql_engine.dispose()
    await pg_engine.dispose()


app = FastAPI(title="Roondal API", version="2.0", lifespan=lifespan)

app.include_router(index.router)
app.include_router(get_ip.router)
app.include_router(reserve.router)
app.include_router(clear.router)
app.include_router(oauth.router)

# Static files (기존 apps/ 디렉토리)
try:
    app.mount("/apps", StaticFiles(directory="apps"), name="static")
except Exception:
    pass
