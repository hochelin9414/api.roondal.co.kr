# 알림 발송

## 목적

DB에 예약된 알림을 정해진 시각에 Mattermost로 자동 발송한다.
FastAPI 서버 외부에서 크론잡으로 매분 실행되어 독립적으로 동작한다.

## 실행 방식

crontab으로 매분 `alarm_sender_py.py`를 실행한다.

```cron
* * * * * cd /usr/local/web_source/api.roondal.co.kr && \
          .venv/bin/python alarm_sender_py.py \
          >> /var/log/roondal_alarm_sender.log 2>&1
```

## 처리 흐름

```
매분 실행 (crontab)
    |
    v
현재 시각(KST)과 일치하는 미완료 알림 조회
(alarm_date = 현재 분, complete_flag = 'N')
    |
    v
각 알림에 대해:
    |
    +-- 웹훅 URL로 Mattermost 메시지 전송
    |   (content, address 포함)
    |
    +-- 전송 성공 시:
        - complete_flag = 'Y' 업데이트
        - cron_num = 0 처리
```

## 알림 메시지 형식

```
[알림] 회의 시간입니다.
주소: 서울시 강남구 테헤란로 123  ← address가 있을 때만 포함
```

## 로그

- 실행 로그: `/var/log/roondal_alarm_sender.log`

## 관련 파일

| 파일 | 역할 |
|------|------|
| `alarm_sender_py.py` | 알림 발송 스크립트 본체 |
| `crontab_alarm_sender.txt` | 크론탭 설정 내용 |
| `app/models/alarm.py` | g5_alarm 테이블 ORM 모델 |
