# 채널 클리어

## 목적

Mattermost 채팅창을 빈 줄로 밀어내어 화면을 정리한다.
zero-width space 문자를 이용한 빈 줄 메시지를 전송하는 방식으로 동작한다.

## 엔드포인트

모든 엔드포인트는 Mattermost 슬래시 명령어 요청을 Form 데이터로 받는다.

**공통 요청 파라미터** (Form 데이터)

| 파라미터 | 타입 | 설명 |
|---------|------|------|
| response_url | string | Mattermost 후속 응답 URL |
| channel_id | string | 클리어할 채널 ID |

---

### POST /clear

기본 클리어. 채널에 50줄의 빈 메시지를 전송한다.

---

### POST /clear/quiet

자신에게만 보이는 클리어. `response_type: ephemeral`로 응답하여 요청자에게만 표시된다.

---

### POST /clear/simple

간단 클리어. 20줄의 빈 메시지를 전송한다.

---

### POST /clear/max

최대 클리어. 100줄의 빈 메시지를 전송한다.

## 옵션 비교

| 엔드포인트 | 줄 수 | 가시성 |
|-----------|------|--------|
| /clear | 50 | 채널 전체 |
| /clear/quiet | 50 | 요청자만 |
| /clear/simple | 20 | 채널 전체 |
| /clear/max | 100 | 채널 전체 |

## 관련 파일

| 파일 | 역할 |
|------|------|
| `app/routers/clear.py` | 클리어 엔드포인트 정의 |
| `app/services/mattermost.py` | `generate_clear_message()` - 빈 줄 메시지 생성 |
