from datetime import datetime

from pydantic import BaseModel


class AlarmResponse(BaseModel):
    cron_num: int
    content: str
    address: str
    alarm_date: datetime
    complete_flag: str
    channelid: str
    webhook_url: str

    model_config = {"from_attributes": True}


class AlarmListResponse(BaseModel):
    b_success: bool
    a_list: list[AlarmResponse] = []
    s_error: str = ""


class ReserveResult(BaseModel):
    success: bool = False
    date: str = ""
    time: str = ""
    content: str = ""
    address: str = ""
    error: str = ""
