#!/usr/bin/env python3
"""
알림 발송 크론잡 스크립트
매분 실행: * * * * * cd /usr/local/web_source/api.roondal.co.kr && python alarm_sender_py.py
"""

import asyncio
import os
import sys
from datetime import datetime
from zoneinfo import ZoneInfo

import httpx
from dotenv import load_dotenv
from sqlalchemy import text
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

KST = ZoneInfo("Asia/Seoul")


def format_alarm_date(alarm_date: str) -> str:
    """알림 날짜를 한국어 형식으로 포맷"""
    import re

    alarm_date = alarm_date.strip()
    if not alarm_date:
        return ""
    m = re.match(r"^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})", alarm_date)
    if m:
        return f"{m.group(1)}년 {m.group(2)}월 {m.group(3)}일 {m.group(4)}시 {m.group(5)}분"
    return alarm_date


def build_alarm_message(content: str, address: str, alarm_date: str) -> str:
    """알림 메시지 생성"""
    title = content.strip() if content.strip() else "알림입니다."
    formatted_date = format_alarm_date(alarm_date)

    message = f"### \U0001f514 {title}\n\n"
    if formatted_date:
        message += f"\u23f0 **예약 시간:** {formatted_date}\n"
    if address.strip():
        message += f"\U0001f3e0 **주소:** {address}\n"
    return message


async def send_mattermost_message(
    webhook_url: str, message: str, bot_name: str = "", icon_url: str = ""
) -> bool:
    """Mattermost 웹훅으로 메시지 전송"""
    payload: dict = {"text": message}
    if bot_name:
        payload["username"] = bot_name
    if icon_url:
        payload["icon_url"] = icon_url

    try:
        async with httpx.AsyncClient(timeout=10) as client:
            resp = await client.post(webhook_url, json=payload)
            return 200 <= resp.status_code < 300
    except Exception as e:
        print(f"Webhook failed: {e}", file=sys.stderr)
        return False


async def dispatch_alarm() -> int:
    """메인 알림 발송 로직"""
    load_dotenv()

    mysql_host = os.getenv("MYSQL_HOST", "localhost")
    mysql_port = os.getenv("MYSQL_PORT", "3306")
    mysql_user = os.getenv("MYSQL_USER", "root")
    mysql_password = os.getenv("MYSQL_PASSWORD", "")
    mysql_database = os.getenv("MYSQL_DATABASE", "wp_home_roondal")
    bot_name = os.getenv("MATTERMOST_BOT_NAME", "")
    icon_url = os.getenv("MATTERMOST_ICON_URL", "")

    mysql_url = f"mysql+asyncmy://{mysql_user}:{mysql_password}@{mysql_host}:{mysql_port}/{mysql_database}"

    try:
        engine = create_async_engine(mysql_url, pool_pre_ping=True)
    except Exception as e:
        print(f"DB 엔진 생성 실패: {e}", file=sys.stderr)
        return 1

    Session = async_sessionmaker(engine, expire_on_commit=False)

    now = datetime.now(KST)
    now_date = now.strftime("%Y-%m-%d")
    now_time = now.strftime("%H:%M")

    sent = 0

    try:
        async with Session() as session:
            # 매칭되는 알림 조회
            result = await session.execute(
                text(
                    "SELECT cron_num, content, address, alarm_date, webhook_url "
                    "FROM g5_alarm "
                    "WHERE complete_flag = 'N' AND DATE(alarm_date) = :now_date "
                    "AND DATE_FORMAT(alarm_date, '%H:%i') = :now_time"
                ),
                {"now_date": now_date, "now_time": now_time},
            )
            rows = result.fetchall()

            for row in rows:
                cron_num, content, address, alarm_date, webhook_url = row

                if not webhook_url:
                    print(f"Webhook URL이 비어있습니다: cron_num={cron_num}", file=sys.stderr)
                    continue

                alarm_date_str = alarm_date.strftime("%Y-%m-%d %H:%M:%S") if hasattr(alarm_date, "strftime") else str(alarm_date)
                message = build_alarm_message(content, address, alarm_date_str)

                if await send_mattermost_message(webhook_url, message, bot_name, icon_url):
                    await session.execute(
                        text("UPDATE g5_alarm SET complete_flag = 'Y', cron_num = 0 WHERE cron_num = :cron_num"),
                        {"cron_num": cron_num},
                    )
                    await session.commit()
                    sent += 1
                else:
                    print(f"알림 전송 실패: cron_num={cron_num}, webhook={webhook_url}", file=sys.stderr)
    except Exception as e:
        print(f"알림 발송 중 오류 발생: {e}", file=sys.stderr)
        return 1
    finally:
        await engine.dispose()

    print(f"sent={sent}")
    return 0


if __name__ == "__main__":
    sys.exit(asyncio.run(dispatch_alarm()))
