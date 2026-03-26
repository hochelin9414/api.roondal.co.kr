from datetime import datetime

from sqlalchemy import DateTime, Integer, String
from sqlalchemy.orm import Mapped, mapped_column

from app.database import Base


class G5Alarm(Base):
    __tablename__ = "g5_alarm"

    cron_num: Mapped[int] = mapped_column(Integer, primary_key=True)
    content: Mapped[str] = mapped_column(String(500), default="")
    address: Mapped[str] = mapped_column(String(500), default="")
    alarm_date: Mapped[datetime] = mapped_column(DateTime)
    complete_flag: Mapped[str] = mapped_column(String(1), default="N")
    channelid: Mapped[str] = mapped_column(String(100), default="")
    webhook_url: Mapped[str] = mapped_column(String(500), default="")
