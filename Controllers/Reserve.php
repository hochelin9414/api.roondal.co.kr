<?php

/**
 * Reserve - 예약 관련 컨트롤러
 * Mattermost 슬래시 명령어로 예약 알림 처리
 */
class Reserve extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $a_data = array(
            's_message' => 'Hello World',
            'a_data' => array(
                's_name' => 'John',
                'i_age' => 30,
            ),
        );
        return $this->view('reserve/index', $a_data);
    }

    /**
     * Mattermost 슬래시 명령어 /예약 처리
     * 예: /예약 내일 오후 3시에 회의 알림
     */
    public function mattermost_reserve()
    {
        // 요청 데이터 수집
        $a_request_data = $this->getRequestData();
        $s_text = isset($a_request_data['text']) ? trim($a_request_data['text']) : '';
        $s_response_url = isset($a_request_data['response_url']) ? $a_request_data['response_url'] : '';
        $s_user_name = isset($a_request_data['user_name']) ? $a_request_data['user_name'] : '사용자';

        // 입력이 비어있으면 안내 메시지
        if ($s_text === '') {
            $this->sendResponse($s_response_url, array(
                'response_type' => 'ephemeral',
                'text' => '사용법: `/예약 [날짜/시간] [내용]`\n예: `/예약 내일 오후 3시에 회의 알림`',
            ));
            return;
        }

        // 예약 완료 요청 확인
        $a_complete_request = $this->parseCompleteRequest($s_text);
        if ($a_complete_request['b_complete']) {
            // 예약번호
            $i_cron_num = $a_complete_request['i_cron_num'];
            // 예약 완료 처리
            $a_complete_result = $this->completeAlarmByCronNum($i_cron_num);
            if (!$a_complete_result['b_success']) {
                $this->sendResponse($s_response_url, array(
                    'response_type' => 'ephemeral',
                    'text' => $a_complete_result['s_error'],
                ));
                return;
            }

            $this->sendResponse($s_response_url, array(
                'response_type' => 'ephemeral',
                'text' => "예약번호 {$i_cron_num}번 예약을 완료 처리했습니다.",
                'code' => '0000',
            ));
            return;
        }

        // response_url이 있으면 먼저 대기 메시지 전송
        if (!empty($s_response_url)) {
            $this->sendSlashResponse(array(
                'response_type' => 'ephemeral',
                'text' => '요청하신 내용을 AI에게 분석을 맡겼습니다...',
            ), false);
            
            // FastCGI 환경에서 클라이언트 연결 종료 후 백그라운드 처리
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                @ob_flush();
                flush();
            }
        }

        // Perplexity API 호출 (사용자 이름 전달)
        try {
            $a_result = $this->requestPerplexity($s_text, array(
                's_user_name' => $s_user_name,
            ));
        } catch (Exception $e) {
            $this->sendResponse($s_response_url, array(
                'response_type' => 'ephemeral',
                'text' => '오류가 발생했습니다: ' . $e->getMessage(),
            ));
            return;
        }

        // 파싱 실패 시 에러 메시지
        if (!$a_result['b_success'] || !empty($a_result['s_error'])) {
            $this->sendResponse($s_response_url, array(
                'response_type' => 'ephemeral',
                'text' => !empty($a_result['s_error']) ? $a_result['s_error'] : '예약 정보를 파싱할 수 없습니다.',
            ));
            return;
        }

        // 파싱된 데이터 추출
        $a_parsed = $a_result['a_parsed'];

        // 알림 내용
        $s_alarm_content = isset($a_parsed['content']) ? $a_parsed['content'] : '';
        // 알림 내용 문장 보정
        $s_alarm_content = $this->normalizeAlarmContent($s_alarm_content);
        $a_parsed['content'] = $s_alarm_content;
        $a_result['a_parsed'] = $a_parsed;
        // 일반 알림은 주소 비움
        $s_alarm_address = '';
        // 알림 날짜
        $s_alarm_date = isset($a_parsed['date']) ? $a_parsed['date'] : '';
        // 알림 시간
        $s_alarm_time = isset($a_parsed['time']) ? $a_parsed['time'] : '';
        // 알림 시간 조합
        $s_alarm_datetime = $this->buildAlarmDateTime($s_alarm_date, $s_alarm_time);

        // g5_alarm 저장
        $a_insert_result = $this->insertAlarmRecord($s_alarm_content, $s_alarm_address, $s_alarm_datetime);
        if (!$a_insert_result['b_success']) {
            $this->sendResponse($s_response_url, array(
                'response_type' => 'ephemeral',
                'text' => $a_insert_result['s_error'],
            ));
            return;
        }

        // 성공 메시지 생성
        $s_message = $this->buildSuccessMessage($a_result, $s_user_name, $a_insert_result['i_cron_num']);

        // 응답 전송
        $this->sendResponse($s_response_url, array(
            'response_type' => 'in_channel',
            'text' => $s_message,
            'code' => '0000',
        ));
    }

    /**
     * 예약 목록 조회 (complete_flag = N)
     */
    public function reserve_list()
    {
        // DB 연결 객체
        $o_connection = new Connection();
        $o_db = $o_connection->db_connect();
        if (!$o_db) {
            return $this->o_response->setJSON(array(
                'b_success' => false,
                's_error' => $o_connection->get_db_error(),
            ));
        }

        // 예약 목록 쿼리 (channelid, webhook_url 포함)
        $s_query = "SELECT cron_num, content, address, alarm_date, complete_flag, channelid, webhook_url FROM g5_alarm WHERE complete_flag = 'N' ORDER BY alarm_date ASC";
        $o_result = mysqli_query($o_db, $s_query);
        if (!$o_result) {
            mysqli_close($o_db);
            return $this->o_response->setJSON(array(
                'b_success' => false,
                's_error' => '예약 목록 조회에 실패했습니다.',
            ));
        }

        // 예약 목록 배열
        $a_list = array();
        while ($a_row = mysqli_fetch_assoc($o_result)) {
            $a_list[] = $a_row;
        }

        mysqli_free_result($o_result);
        mysqli_close($o_db);

        return $this->o_response->setJSON(array(
            'b_success' => true,
            'a_list' => $a_list,
        ));
    }

    /**
     * 예약 성공 메시지 생성
     * 
     * @param array  $a_result    파싱된 결과
     * @param string $s_user_name 사용자 이름
     * @return string 포맷된 메시지
     */
    private function buildSuccessMessage($a_result, $s_user_name, $i_cron_num = 0)
    {
        $a_data = $a_result['a_parsed'];
        
        $s_date = isset($a_data['date']) ? $a_data['date'] : '';
        $s_time = isset($a_data['time']) ? $a_data['time'] : '';
        $s_content = isset($a_data['content']) ? $a_data['content'] : '';
        
        // 날짜/시간 포맷팅
        $s_formatted_datetime = $this->formatDateTime($s_date, $s_time);
        
        $s_message = "✅ **{$s_user_name}**님이 알림 예약을 등록하셨습니다.\n\n";
        $s_message .= "- **날짜** : {$s_date}\n";
        $s_message .= "- **시간** : {$s_time}\n";
        if (!empty($i_cron_num)) {
            $s_message .= "- **예약번호** : {$i_cron_num}\n";
        }
        $s_message .= "- **내용** : {$s_content}\n\n";
        $s_message .= "📢 **{$s_formatted_datetime}**에 알림 보내드리겠습니다.";
        
        return $s_message;
    }

    /**
     * 날짜/시간을 "X월 X일 X시 X분" 형식으로 포맷
     */
    private function formatDateTime($s_date, $s_time)
    {
        $s_result = '';
        
        // 날짜 파싱 (YYYY-MM-DD 형식)
        if (!empty($s_date) && preg_match('/(\d{4})-(\d{2})-(\d{2})/', $s_date, $a_date_matches)) {
            $i_month = intval($a_date_matches[2]);
            $i_day = intval($a_date_matches[3]);
            $s_result .= "{$i_month}월 {$i_day}일 ";
        }
        
        // 시간 파싱 (HH:MM 형식)
        if (!empty($s_time) && preg_match('/(\d{1,2}):(\d{2})/', $s_time, $a_time_matches)) {
            $i_hour = intval($a_time_matches[1]);
            $i_minute = intval($a_time_matches[2]);
            $s_result .= "{$i_hour}시";
            if ($i_minute > 0) {
                $s_result .= " {$i_minute}분";
            }
        }
        
        return trim($s_result);
    }

    /**
     * DB 저장용 날짜/시간 조합
     *
     * @param string $s_date 날짜 (YYYY-MM-DD)
     * @param string $s_time 시간 (HH:MM 또는 HH:MM:SS)
     * @return string DB 저장용 datetime
     */
    private function buildAlarmDateTime($s_date, $s_time)
    {
        $s_datetime = trim($s_date . ' ' . $s_time);

        // HH:MM 형식이면 초 추가
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{1,2}:\d{2}$/', $s_datetime)) {
            $s_datetime .= ':00';
        }

        return $s_datetime;
    }

    /**
     * 알림 내용 문장 보정
     * - 간결한 명사구를 자연스러운 알림 문구로 변환
     *
     * @param string $s_content 알림 내용
     * @return string 보정된 알림 내용
     */
    private function normalizeAlarmContent($s_content)
    {
        // 공백 정리
        $s_content = trim($s_content);
        if ($s_content === '') {
            return '알림 시간입니다.';
        }

        // 선행 날짜/시간 표현 제거
        $s_content = preg_replace('/^(오늘|내일|모레|금일|익일|이따가|잠시후|나중에)\s*/u', '', $s_content);
        $s_content = preg_replace('/^(오전|오후|아침|저녁|밤|새벽)\s*/u', '', $s_content);
        $s_content = preg_replace('/^\d{1,2}시(\s*\d{1,2}분)?(에)?\s*/u', '', $s_content);
        $s_content = preg_replace('/^\d{1,2}:\d{2}(에)?\s*/u', '', $s_content);
        $s_content = preg_replace('/^\d{1,2}분\s*(뒤에|후에|뒤|후)\s*/u', '', $s_content);
        $s_content = trim($s_content);

        // 시스템형 표현 제거
        $s_content = preg_replace('/보내드(리겠습니다|릴게요|립니다)/u', '', $s_content);
        $s_content = preg_replace('/알려드(리겠습니다|릴게요|립니다)/u', '', $s_content);

        // 요청형 표현 제거
        $s_content = preg_replace('/(알려주세요|알려줘요|보내주세요|보내줘요|해\s*주세요|해주세요|해줘요|하세요|해요|주세요)$/u', '', $s_content);
        $s_content = trim($s_content);
        if ($s_content === '') {
            return '알림 시간입니다.';
        }

        // 불필요한 조사 제거 (~을, ~를, ~에, ~이, ~가 등)
        $s_content = preg_replace('/(을|를|에|이|가)\s*$/u', '', $s_content);
        $s_content = trim($s_content);

        // 알림이 울립니다 문장 정리
        $s_content = preg_replace('/(알림|알람)이?\s*울립니다$/u', '$1', $s_content);
        $s_content = trim($s_content);

        // 이미 완성된 문장이면 그대로 반환
        if (preg_match('/입니다\.?$/u', $s_content) || preg_match('/시간입니다\.?$/u', $s_content)) {
            if (!preg_match('/\.$/u', $s_content)) {
                $s_content .= '.';
            }
            return $s_content;
        }

        // 문장 종결 부호 제거
        $s_content = preg_replace('/[.!?]$/u', '', $s_content);
        $s_content = trim($s_content);

        // "~하기" 형태면 "~할 시간입니다."로 변환
        if (preg_match('/(.+)하기$/u', $s_content, $a_matches)) {
            return $a_matches[1] . '할 시간입니다.';
        }

        // "~해야 해", "~해야 함" 같은 표현 제거
        $s_content = preg_replace('/(해야\s*(해|함|한다|하다))$/u', '', $s_content);
        $s_content = trim($s_content);

        // 명사형으로 끝나면 "시간입니다." 추가
        return $s_content . ' 시간입니다.';
    }

    /**
     * 예약용 Perplexity 룰 정의 (오버라이드)
     * - 날짜/시간 파싱을 위한 시스템 프롬프트 설정
     * - JSON 형식으로 응답 요청
     */
    protected function buildPerplexityRules($s_user_input, $a_options = array())
    {
        $s_current_datetime = date('Y-m-d H:i');
        
        $s_system_prompt = implode("\n", array(
            "너는 사용자의 한국어 요청에서 날짜와 시간을 추출하는 파서다.",
            "현재 시간: {$s_current_datetime} (한국 시간, Asia/Seoul)",
            "",
            "반드시 아래 JSON 형식으로만 응답해. 다른 텍스트 없이 JSON만 출력해:",
            "{",
            '  "success": true 또는 false,',
            '  "date": "YYYY-MM-DD" (추출된 날짜, 없으면 빈 문자열),',
            '  "time": "HH:MM" (24시간 형식, 없으면 빈 문자열),',
            '  "content": "예약 내용 요약",',
            '  "error": "에러 메시지 (success가 false일 때만)"',
            "}",
            "",
            "규칙:",
            "1. '오늘', '내일', '모레' 등은 현재 날짜 기준으로 계산",
            "2. '오후 3시' → '15:00', '아침 9시' → '09:00'",
            "3. 요청 시간이 현재보다 과거면 success: false, error: '이미 지난 시간입니다.'",
            "4. 날짜가 없으면 date는 빈 문자열로 반환",
            "5. content는 간결한 명사구로 작성한다",
            "   - 좋은 예: '업무 시작', '약 복용', '청약', '회의 준비', '운동'",
            "   - 나쁜 예: '업무를 시작해야 합니다', '약을 먹어야 합니다', '청약을 해야 합니다'",
            "   - 동사를 쓰더라도 간결하게: '업무하기', '약 먹기', '청약하기'",
            "6. content에는 날짜/시간 표현을 넣지 않는다",
            "7. 시간이 없거나 모호하면 success: false, error: '날짜/시간을 더 구체적으로 입력해 주세요.'",
            "8. '이따가' 같은 추상 시간은 오늘 기준으로 해석한다",
            "9. 'NN분에'만 있고 시 표현(NN시, 오전/오후, HH:MM 등)이 전혀 없는 경우:",
            "   - 현재 시간을 기준으로 해석",
            "   - 예1: 현재 14:45, '50분에 알림' → time: '14:50' (아직 안 지난 시간)",
            "   - 예2: 현재 14:45, '40분에 알림' → time: '15:40' (이미 지난 시간이므로 다음 시)",
            "   - 'NN분 뒤/후'는 이 규칙에서 제외 (아래 규칙 10 적용)",
            "10. 'NN분 뒤/후'는 상대 시간이므로 현재 시간에서 NN분을 더한 절대 시각으로 변환",
            "   - 예: 현재 14:45, '10분 뒤에 알림' → time: '14:55'",
        ));

        return array(
            's_system_prompt' => $s_system_prompt,
            'a_output_fields' => array('date', 'time', 'content'),
            's_user_input' => $s_user_input,
        );
    }

    /**
     * Perplexity 응답 처리 (오버라이드)
     * - JSON 파싱 및 유효성 검사
     */
    protected function processPerplexityResponse($a_api_result, $a_rules = array())
    {
        // 기본 응답 구조
        $a_return = array(
            'b_success' => false,
            'i_status_code' => $a_api_result['i_status_code'],
            's_content' => '',
            's_error' => '',
            'a_parsed' => array(),
            'a_raw' => $a_api_result['a_body'],
        );

        // API 호출 실패
        if ($a_api_result['i_status_code'] < 200 || $a_api_result['i_status_code'] >= 300) {
            $a_return['s_error'] = 'API 호출에 실패했습니다.';
            return $a_return;
        }

        // content 추출
        $s_content = '';
        if (isset($a_api_result['a_body']['choices'][0]['message']['content'])) {
            $s_content = $a_api_result['a_body']['choices'][0]['message']['content'];
        }
        $a_return['s_content'] = $s_content;

        // JSON 파싱 시도 (```json ... ``` 래핑 제거)
        $s_json = $s_content;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $s_content, $a_matches)) {
            $s_json = $a_matches[1];
        }
        $s_json = trim($s_json);

        $a_parsed = json_decode($s_json, true);
        if (!is_array($a_parsed)) {
            $a_return['s_error'] = 'AI 응답을 파싱할 수 없습니다.';
            return $a_return;
        }

        $a_return['a_parsed'] = $a_parsed;

        // 분 단위 표현 보정
        if (isset($a_rules['s_user_input'])) {
            $a_parsed = $this->applyMinuteOnlyExpression($a_rules['s_user_input'], $a_parsed);
            $a_return['a_parsed'] = $a_parsed;
        }

        // 날짜가 없으면 오늘 날짜로 보정
        if (empty($a_parsed['date'])) {
            $a_parsed['date'] = date('Y-m-d');
            $a_return['a_parsed'] = $a_parsed;
        }

        // success 필드 확인
        if (isset($a_parsed['success']) && $a_parsed['success'] === false) {
            $a_return['s_error'] = isset($a_parsed['error']) ? $a_parsed['error'] : '예약 정보를 확인할 수 없습니다.';
            return $a_return;
        }

        // 필수 필드 검증
        if (empty($a_parsed['time'])) {
            $a_return['s_error'] = '날짜/시간을 더 구체적으로 입력해 주세요.';
            return $a_return;
        }

        $a_return['b_success'] = true;
        return $a_return;
    }

    /**
     * 분 단위 시간 표현 보정
     * - "NN분에" 표현만 있고 시간 명시가 없을 때 현재 시를 기준으로 처리
     * - 요청한 분이 현재 분보다 이전이면 다음 시간으로 자동 변경
     * 
     * 예시:
     *   현재 14:45일 때
     *   - "50분에 알림" → 14:50 (아직 안 지남)
     *   - "40분에 알림" → 15:40 (이미 지났으므로 다음 시)
     *
     * @param string $s_text   사용자 원문
     * @param array  $a_parsed 파싱 결과
     * @return array 보정된 파싱 결과
     */
    private function applyMinuteOnlyExpression($s_text, $a_parsed)
    {
        $s_text = trim($s_text);

        // 'NN분에' 패턴 확인
        if (!preg_match('/(\d{1,2})\s*분에/u', $s_text, $a_matches)) {
            return $a_parsed;
        }

        // 상대 시간('NN분 뒤/후') 제외
        if (preg_match('/\d{1,2}\s*분\s*(뒤|후)/u', $s_text)) {
            return $a_parsed;
        }

        // 시각 표기('NN시' 또는 'HH:MM')가 있으면 제외
        if (preg_match('/\d{1,2}\s*시/u', $s_text) || preg_match('/\d{1,2}:\d{2}/', $s_text)) {
            return $a_parsed;
        }

        // 오전/오후 표기가 있으면 제외
        if (preg_match('/오전|오후|아침|저녁|밤|새벽/u', $s_text)) {
            return $a_parsed;
        }

        // 추출된 분 값
        $i_minute = intval($a_matches[1]);
        if ($i_minute < 0 || $i_minute > 59) {
            return $a_parsed;
        }

        // 현재 시간 기준으로 목표 시각 결정
        $o_timezone = new DateTimeZone('Asia/Seoul');
        $o_now = new DateTime('now', $o_timezone);
        $i_now_minute = intval($o_now->format('i'));

        // 목표 시각 객체 생성
        $o_target = clone $o_now;
        
        // 요청한 분이 현재 분보다 이전이면 다음 시간으로 설정
        if ($i_minute < $i_now_minute) {
            $o_target->modify('+1 hour');
        }
        
        // 시각 설정 (현재 시 또는 다음 시의 NN분)
        $o_target->setTime(intval($o_target->format('H')), $i_minute, 0);

        // 파싱 결과에 날짜/시간 반영
        $a_parsed['date'] = $o_target->format('Y-m-d');
        $a_parsed['time'] = $o_target->format('H:i');

        return $a_parsed;
    }

    /**
     * 예약 완료 요청 파싱
     *
     * @param string $s_text 사용자 입력
     * @return array 파싱 결과 (b_complete, i_cron_num)
     */
    private function parseCompleteRequest($s_text)
    {
        $a_return = array(
            'b_complete' => false,
            'i_cron_num' => 0,
        );

        // 예약번호 완료/취소 패턴
        if (preg_match('/예약번호\s*(\d{4})\s*번?\s*(완료|완료처리|취소|삭제|삭제요청)/u', $s_text, $a_matches)) {
            $a_return['b_complete'] = true;
            $a_return['i_cron_num'] = intval($a_matches[1]);
            return $a_return;
        }

        // 번호 기반 완료/취소 패턴
        if (preg_match('/(\d{4})\s*번?\s*예약\s*(완료|완료처리|취소|삭제)/u', $s_text, $a_matches)) {
            $a_return['b_complete'] = true;
            $a_return['i_cron_num'] = intval($a_matches[1]);
            return $a_return;
        }

        return $a_return;
    }

    /**
     * 예약번호로 알림 삭제
     *
     * @param int $i_cron_num 예약번호
     * @return array 처리 결과 (b_success, s_error)
     */
    private function completeAlarmByCronNum($i_cron_num)
    {
        // DB 연결 객체
        $o_connection = new Connection();
        $o_db = $o_connection->db_connect();
        if (!$o_db) {
            return array(
                'b_success' => false,
                's_error' => $o_connection->get_db_error(),
            );
        }

        // 완료 처리 쿼리
        $s_query = "UPDATE g5_alarm SET complete_flag = 'Y' WHERE cron_num = ?";
        $o_stmt = mysqli_prepare($o_db, $s_query);
        if (!$o_stmt) {
            mysqli_close($o_db);
            return array(
                'b_success' => false,
                's_error' => '예약 완료 처리 쿼리를 준비할 수 없습니다.',
            );
        }

        mysqli_stmt_bind_param($o_stmt, 'i', $i_cron_num);
        $b_result = mysqli_stmt_execute($o_stmt);
        $i_affected = mysqli_stmt_affected_rows($o_stmt);
        mysqli_stmt_close($o_stmt);
        mysqli_close($o_db);

        if (!$b_result) {
            return array(
                'b_success' => false,
                's_error' => '예약 완료 처리에 실패했습니다.',
            );
        }

        if ($i_affected === 0) {
            return array(
                'b_success' => false,
                's_error' => '해당 예약번호를 찾을 수 없습니다.',
            );
        }

        return array(
            'b_success' => true,
            's_error' => '',
        );
    }

    /**
     * 중복 없는 예약번호 생성
     *
     * @param object $o_db DB 객체
     * @return array 처리 결과 (b_success, i_cron_num, s_error)
     */
    private function generateUniqueCronNum($o_db)
    {
        $i_try = 0;
        $i_max_try = 30;

        while ($i_try < $i_max_try) {
            // 예약번호 후보
            $i_candidate = random_int(1000, 9999);

            // 중복 확인 쿼리
            $s_query = "SELECT COUNT(*) AS i_count FROM g5_alarm WHERE cron_num = ?";
            $o_stmt = mysqli_prepare($o_db, $s_query);
            if (!$o_stmt) {
                return array(
                    'b_success' => false,
                    'i_cron_num' => 0,
                    's_error' => '예약번호 확인 쿼리를 준비할 수 없습니다.',
                );
            }

            mysqli_stmt_bind_param($o_stmt, 'i', $i_candidate);
            mysqli_stmt_execute($o_stmt);
            mysqli_stmt_bind_result($o_stmt, $i_count);
            mysqli_stmt_fetch($o_stmt);
            mysqli_stmt_close($o_stmt);

            if (intval($i_count) === 0) {
                return array(
                    'b_success' => true,
                    'i_cron_num' => $i_candidate,
                    's_error' => '',
                );
            }

            $i_try++;
        }

        return array(
            'b_success' => false,
            'i_cron_num' => 0,
            's_error' => '예약번호 생성에 실패했습니다.',
        );
    }

    /**
     * g5_alarm 테이블에 알림 예약 저장
     *
     * @param string $s_content    알림 내용
     * @param string $s_address    알림 주소 (없으면 빈 문자열)
     * @param string $s_alarm_date 알림 시간
     * @return array 처리 결과 (b_success, s_error, i_cron_num)
     */
    private function insertAlarmRecord($s_content, $s_address, $s_alarm_date)
    {
        // DB 연결 객체
        $o_connection = new Connection();
        $o_db = $o_connection->db_connect();
        if (!$o_db) {
            return array(
                'b_success' => false,
                's_error' => $o_connection->get_db_error(),
                'i_cron_num' => 0,
            );
        }

        // 예약번호 생성
        $a_cron_num_result = $this->generateUniqueCronNum($o_db);
        if (!$a_cron_num_result['b_success']) {
            mysqli_close($o_db);
            return array(
                'b_success' => false,
                's_error' => $a_cron_num_result['s_error'],
                'i_cron_num' => 0,
            );
        }
        // 예약번호
        $i_cron_num = $a_cron_num_result['i_cron_num'];

        // Mattermost 채널 ID 조회 (PostgreSQL)
        $a_channel_result = $this->getLatestMattermostChannelId();
        if (!$a_channel_result['b_success']) {
            mysqli_close($o_db);
            return array(
                'b_success' => false,
                's_error' => $a_channel_result['s_error'],
                'i_cron_num' => 0,
            );
        }
        // 채널 ID
        $s_channel_id = $a_channel_result['s_channel_id'];

        // Webhook URL 조회 (mattermost_channel_list.txt)
        $a_webhook_result = $this->getWebhookUrlByChannelId($s_channel_id);
        if (!$a_webhook_result['b_success']) {
            mysqli_close($o_db);
            return array(
                'b_success' => false,
                's_error' => $a_webhook_result['s_error'],
                'i_cron_num' => 0,
            );
        }
        // Webhook URL
        $s_webhook_url = $a_webhook_result['s_webhook_url'];

        // 알림 데이터 저장 (channelid, webhook_url 포함)
        $s_query = "INSERT INTO g5_alarm (cron_num, content, address, alarm_date, complete_flag, channelid, webhook_url) VALUES (?, ?, ?, ?, 'N', ?, ?)";
        $o_stmt = mysqli_prepare($o_db, $s_query);
        if (!$o_stmt) {
            mysqli_close($o_db);
            return array(
                'b_success' => false,
                's_error' => '알림 저장 쿼리를 준비할 수 없습니다.',
                'i_cron_num' => 0,
            );
        }

        mysqli_stmt_bind_param($o_stmt, 'isssss', $i_cron_num, $s_content, $s_address, $s_alarm_date, $s_channel_id, $s_webhook_url);
        $b_result = mysqli_stmt_execute($o_stmt);
        $s_error = $b_result ? '' : mysqli_stmt_error($o_stmt);
        mysqli_stmt_close($o_stmt);
        mysqli_close($o_db);

        if (!$b_result) {
            return array(
                'b_success' => false,
                's_error' => !empty($s_error) ? $s_error : '알림 저장에 실패했습니다.',
                'i_cron_num' => 0,
            );
        }

        return array(
            'b_success' => true,
            's_error' => '',
            'i_cron_num' => $i_cron_num,
        );
    }

    /**
     * Mattermost PostgreSQL에서 최신 채널 ID 조회
     *
     * @return array 처리 결과 (b_success, s_channel_id, s_error)
     */
    private function getLatestMattermostChannelId()
    {
        // PostgreSQL 연결 객체
        $o_connection = new Connection();
        $o_pg = $o_connection->pg_connect();
        if (!$o_pg) {
            return array(
                'b_success' => false,
                's_channel_id' => '',
                's_error' => 'PostgreSQL 연결 실패: ' . $o_connection->get_db_error(),
            );
        }

        // commandwebhooks 테이블에서 createat이 가장 큰 레코드의 채널 ID 조회
        $s_query = "SELECT channelid FROM commandwebhooks ORDER BY createat DESC LIMIT 1";
        $o_result = pg_query($o_pg, $s_query);
        if (!$o_result) {
            pg_close($o_pg);
            return array(
                'b_success' => false,
                's_channel_id' => '',
                's_error' => 'commandwebhooks 조회 실패: ' . pg_last_error($o_pg),
            );
        }

        // 결과 행
        $a_row = pg_fetch_assoc($o_result);
        pg_free_result($o_result);
        pg_close($o_pg);

        if (!$a_row || !isset($a_row['channelid'])) {
            return array(
                'b_success' => false,
                's_channel_id' => '',
                's_error' => 'commandwebhooks에 데이터가 없습니다.',
            );
        }

        return array(
            'b_success' => true,
            's_channel_id' => $a_row['channelid'],
            's_error' => '',
        );
    }

    /**
     * 채널 ID로 webhook URL 조회 (mattermost_channel_list.txt)
     *
     * @param string $s_channel_id 채널 ID
     * @return array 처리 결과 (b_success, s_webhook_url, s_error)
     */
    private function getWebhookUrlByChannelId($s_channel_id)
    {
        // mattermost_channel_list.txt 파일 경로
        $s_list_file = __DIR__ . '/../mattermost_channel_list.txt';

        if (!file_exists($s_list_file)) {
            return array(
                'b_success' => false,
                's_webhook_url' => '',
                's_error' => 'mattermost_channel_list.txt 파일이 없습니다.',
            );
        }

        // 파일 내용 읽기
        $s_contents = file_get_contents($s_list_file);
        if ($s_contents === false) {
            return array(
                'b_success' => false,
                's_webhook_url' => '',
                's_error' => 'mattermost_channel_list.txt 파일을 읽을 수 없습니다.',
            );
        }

        // 줄 단위로 분리
        $a_lines = preg_split('/\r\n|\r|\n/', $s_contents);
        foreach ($a_lines as $s_line) {
            $s_line = trim($s_line);
            // 주석이나 빈 줄 건너뛰기
            if ($s_line === '' || strpos($s_line, '#') === 0) {
                continue;
            }

            // channelid=webhook_url 형식 파싱
            if (strpos($s_line, '=') !== false) {
                $a_parts = explode('=', $s_line, 2);
                $s_file_channel_id = trim($a_parts[0]);
                $s_webhook_url = isset($a_parts[1]) ? trim($a_parts[1]) : '';

                if ($s_file_channel_id === $s_channel_id && $s_webhook_url !== '') {
                    return array(
                        'b_success' => true,
                        's_webhook_url' => $s_webhook_url,
                        's_error' => '',
                    );
                }
            }
        }

        return array(
            'b_success' => false,
            's_webhook_url' => '',
            's_error' => '채널 ID(' . $s_channel_id . ')에 해당하는 webhook URL을 찾을 수 없습니다.',
        );
    }

    /**
     * 요청 데이터 수집 (JSON/POST 병합)
     */
    private function getRequestData()
    {
        $s_input = file_get_contents('php://input');
        $a_data = json_decode($s_input, true);
        
        if ($a_data === null) {
            $a_data = array();
        }
        
        if (!empty($_POST)) {
            $a_data = array_merge($a_data, $_POST);
        }
        
        return $a_data;
    }

    /**
     * 응답 전송 헬퍼
     * - response_url이 있으면 후속 응답으로 전송
     * - 없으면 직접 응답
     */
    private function sendResponse($s_response_url, $a_response)
    {
        if (!empty($s_response_url)) {
            $this->sendResponseUrl($s_response_url, $a_response);
        } else {
            $this->sendSlashResponse($a_response);
        }
    }
}
