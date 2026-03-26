from contextlib import asynccontextmanager
from typing import AsyncGenerator

from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker, create_async_engine
from sqlalchemy.orm import DeclarativeBase

from app.config import settings

mysql_engine = create_async_engine(settings.mysql_url, pool_pre_ping=True, pool_size=5)
pg_engine = create_async_engine(settings.pg_url, pool_pre_ping=True, pool_size=3)

MySQLSession = async_sessionmaker(mysql_engine, expire_on_commit=False)
PGSession = async_sessionmaker(pg_engine, expire_on_commit=False)


class Base(DeclarativeBase):
    pass


@asynccontextmanager
async def get_mysql_session() -> AsyncGenerator[AsyncSession, None]:
    async with MySQLSession() as session:
        yield session


@asynccontextmanager
async def get_pg_session() -> AsyncGenerator[AsyncSession, None]:
    async with PGSession() as session:
        yield session
