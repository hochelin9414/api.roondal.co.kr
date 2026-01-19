<?php
/**
 * BaseController - 모든 컨트롤러의 기본 클래스
 * CodeIgniter 스타일의 공통 기능 제공
 */

class BaseController {
    protected $o_db;
    protected $o_response;
    protected $o_functions;

    public function __construct() {
        // 공통 함수 인스턴스
        $this->o_functions = $GLOBALS['o_functions'];
        
        // 데이터베이스 연결 (필요시)
        $this->o_db = new Connection();
        
        // Response 객체 초기화
        $this->o_response = new Response();
    }

    /**
     * 슬래시 명령어 응답 처리 (Mattermost 등)
     * 하위 컨트롤러에서 applySlashRules/renderSlashMessage를 오버라이드해
     * 추가 룰을 적용하거나 응답 포맷을 변경할 수 있다.
     *
     * @param array $a_request_data 입력 파라미터(바디/POST 병합 결과)
     * @param array $a_options      s_title, response_type 등 옵션
     */
    protected function handleSlashCommand($a_request_data = array(), $a_options = array()) {
        $a_processed_data = $this->applySlashRules($a_request_data, $a_options);
        $s_message = $this->renderSlashMessage($a_processed_data, $a_options);

        $a_response = array(
            'response_type' => isset($a_options['response_type']) ? $a_options['response_type'] : 'ephemeral',
            'text' => $s_message,
        );

        $this->sendSlashResponse($a_response);
    }

    /**
     * 슬래시 명령어 룰 적용 지점
     * 기본은 그대로 반환하며, 하위 클래스에서 오버라이드하여
     * 텍스트 파싱/검증/필터링 등을 수행한다.
     */
    protected function applySlashRules($a_request_data, $a_options = array()) {
        return $a_request_data;
    }

    /**
     * 슬래시 명령어 메시지 렌더링 기본 구현
     * 하위 클래스에서 오버라이드해 메시지 포맷을 변경할 수 있다.
     */
    protected function renderSlashMessage($a_request_data, $a_options = array()) {
        $s_title = isset($a_options['s_title']) ? $a_options['s_title'] : '**요청이 처리되었습니다.**';
        $s_message_text = $s_title . "\n\n";

        if (!empty($a_request_data)) {
            $s_message_text .= "**받은 데이터:**\n```json\n" . json_encode($a_request_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```";
        }

        return $s_message_text;
    }

    /**
     * 슬래시 명령어 응답 전송
     */
    protected function sendSlashResponse($a_response, $b_exit = true) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($a_response, JSON_UNESCAPED_UNICODE);
        if ($b_exit) {
            exit;
        }
    }

    /**
     * Perplexity API 요청 메인 메서드
     * - 기본: 룰 없이 바로 응답값 전달
     * - 상속 컨트롤러에서 buildPerplexityRules()를 오버라이드하여 룰 적용 가능
     * 
     * @param string $s_user_input 사용자 입력 텍스트
     * @param array  $a_options    추가 옵션 (s_model, temperature 등)
     * @return array 처리된 응답 데이터
     */
    protected function requestPerplexity($s_user_input, $a_options = array()) {
        // 1. 룰 빌드 (상속 클래스에서 오버라이드 가능)
        $a_rules = $this->buildPerplexityRules($s_user_input, $a_options);
        
        // 2. 메시지 구성
        $a_messages = $this->buildPerplexityMessages($s_user_input, $a_rules);
        
        // 3. API 호출
        $s_model = isset($a_options['s_model']) ? $a_options['s_model'] : 'sonar-reasoning-pro';
        $a_api_result = $this->callPerplexityApi($a_messages, $s_model, $a_options);
        
        // 4. 응답 처리 (상속 클래스에서 오버라이드 가능)
        return $this->processPerplexityResponse($a_api_result, $a_rules);
    }

    /**
     * Perplexity 룰 빌드 - 상속 클래스에서 오버라이드하여 커스텀 룰 정의
     * 
     * @param string $s_user_input 사용자 입력
     * @param array  $a_options    옵션
     * @return array 룰 배열 (s_system_prompt, a_output_fields 등)
     */
    protected function buildPerplexityRules($s_user_input, $a_options = array()) {
        // 기본: 룰 없음 (그대로 전달)
        return array(
            's_system_prompt' => '',
            'a_output_fields' => array(),
        );
    }

    /**
     * Perplexity API용 메시지 배열 구성
     * 
     * @param string $s_user_input 사용자 입력
     * @param array  $a_rules      룰 배열
     * @return array OpenAI 호환 messages 배열
     */
    protected function buildPerplexityMessages($s_user_input, $a_rules = array()) {
        $a_messages = array();
        
        // 시스템 프롬프트가 있으면 추가
        if (!empty($a_rules['s_system_prompt'])) {
            $a_messages[] = array(
                'role' => 'system',
                'content' => $a_rules['s_system_prompt'],
            );
        }
        
        // 사용자 메시지 추가
        $a_messages[] = array(
            'role' => 'user',
            'content' => $s_user_input,
        );
        
        return $a_messages;
    }

    /**
     * Perplexity API 실제 호출 (내부용)
     * 
     * @param array  $a_messages 메시지 배열
     * @param string $s_model    모델명
     * @param array  $a_options  추가 옵션
     * @return array API 응답 [i_status_code, a_body, s_raw]
     * @throws Exception cURL 오류나 응답 파싱 오류 시
     */
    protected function callPerplexityApi($a_messages, $s_model = 'sonar-reasoning-pro', $a_options = array()) {
        $s_token = $this->o_functions->perplexity_token();
        $s_url = isset($a_options['s_url']) ? $a_options['s_url'] : 'https://api.perplexity.ai/chat/completions';

        $a_payload = array(
            'model' => $s_model,
            'messages' => $a_messages,
        );

        // temperature/top_p 등 추가 옵션 병합 (s_url, s_model 제외)
        $a_exclude_keys = array('s_url' => true, 's_model' => true);
        $a_payload = array_merge($a_payload, array_diff_key($a_options, $a_exclude_keys));

        $o_ch = curl_init();
        curl_setopt_array($o_ch, array(
            CURLOPT_URL => $s_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($a_payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $s_token,
                'Content-Type: application/json',
            ),
            CURLOPT_TIMEOUT => 30,
        ));

        $s_raw = curl_exec($o_ch);
        if ($s_raw === false) {
            $s_error = curl_error($o_ch);
            curl_close($o_ch);
            throw new Exception('Perplexity request failed: ' . $s_error);
        }

        $i_status_code = curl_getinfo($o_ch, CURLINFO_HTTP_CODE);
        curl_close($o_ch);

        $a_body = json_decode($s_raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Perplexity response JSON decode failed: ' . json_last_error_msg());
        }

        return array(
            'i_status_code' => $i_status_code,
            'a_body' => $a_body,
            's_raw' => $s_raw,
        );
    }

    /**
     * Perplexity 응답 처리 - 상속 클래스에서 오버라이드하여 커스텀 처리
     * 
     * @param array $a_api_result API 응답 결과
     * @param array $a_rules      적용된 룰
     * @return array 처리된 결과
     */
    protected function processPerplexityResponse($a_api_result, $a_rules = array()) {
        // 기본: API 응답에서 content만 추출하여 반환
        $s_content = '';
        if (isset($a_api_result['a_body']['choices'][0]['message']['content'])) {
            $s_content = $a_api_result['a_body']['choices'][0]['message']['content'];
        }

        return array(
            'b_success' => ($a_api_result['i_status_code'] >= 200 && $a_api_result['i_status_code'] < 300),
            'i_status_code' => $a_api_result['i_status_code'],
            's_content' => $s_content,
            'a_raw' => $a_api_result['a_body'],
        );
    }

    /**
     * JSON 응답 반환
     */
    protected function jsonResponse($a_data, $i_status_code = 200) {
        http_response_code($i_status_code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($a_data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 모델 로드
     * 사용 예: $this->loadModel('category') -> CategoryModel.php의 CategoryModel 클래스
     * 또는: $this->loadModel('category_model') -> CategoryModel.php의 CategoryModel 클래스
     */
    protected function loadModel($s_model_name) {
        // _model 접미사 제거 (있는 경우)
        $s_model_name = str_replace('_model', '', strtolower($s_model_name));
        
        // 첫 글자 대문자로 변환
        $s_model_name = ucfirst($s_model_name);
        
        $s_model_file = WEB_SOURCE_DIR . 'Models/' . $s_model_name . 'Model.php';
        if (file_exists($s_model_file)) {
            require_once $s_model_file;
            $s_class_name = $s_model_name . 'Model';
            if (class_exists($s_class_name)) {
                return new $s_class_name();
            }
        }
        throw new Exception("Model {$s_model_name} not found");
    }

    /**
     * Mattermost로 메시지 전송
     * @param string $webhookUrl Mattermost 웹훅 URL
     * @param string $message 전송할 메시지
     * @param array $options 추가 옵션 (channel, username, icon_url 등)
     * @return bool 성공 여부
     */
    protected function sendMattermostMessage($s_webhook_url, $s_message, $a_options = array()) {
        $a_payload = array(
            'text' => $s_message
        );
        
        // 추가 옵션 병합
        if (!empty($a_options)) {
            $a_payload = array_merge($a_payload, $a_options);
        }
        
        $ch = curl_init($s_webhook_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($a_payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $s_response = curl_exec($ch);
        $i_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $i_http_code >= 200 && $i_http_code < 300;
    }

    /**
     * response_url로 후속 메시지 전송 (Mattermost 슬래시 명령 delayed response)
     * @param string $s_url  Mattermost response_url
     * @param array  $a_response 전송할 응답 배열
     * @return bool 성공 여부
     */
    protected function sendResponseUrl($s_url, $a_response) {
        if (empty($s_url)) {
            return false;
        }
        
        $s_body = json_encode($a_response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $o_ch = curl_init($s_url);
        curl_setopt_array($o_ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $s_body,
            CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 10,
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
        ]);
        
        $s_result   = curl_exec($o_ch);
        $i_httpcode = curl_getinfo($o_ch, CURLINFO_HTTP_CODE);
        $i_errno    = curl_errno($o_ch);
        $s_errmsg   = curl_error($o_ch);
        curl_close($o_ch);
        
        // 로그 기록
        $s_log = sprintf(
            "[%s] response_url=%s  http_code=%d  curl_errno=%d  curl_error=%s  response_body=%s
",
            date('Y-m-d H:i:s'), $s_url, $i_httpcode, $i_errno, $s_errmsg, $s_result
        );
        $s_logPath = '/usr/local/web_source/api.roondal.co.kr/logs/response_url.log';
        // 디렉토리가 없으면 생성
        $s_logDir = dirname($s_logPath);
        if (!is_dir($s_logDir)) {
            mkdir($s_logDir, 0777, true);
        }
        error_log($s_log, 3, $s_logPath);
        
        return $i_errno === 0 && $i_httpcode >= 200 && $i_httpcode < 300;
    }

    /**
     * 뷰 호출
     * 사용 예: $this->view('test') -> Views/test.php
     * common.inc.php에서 이미 정의된 $o_functions를 뷰에서 사용 가능
     * JSON 출력을 자동으로 감지하여 Content-Type과 인코딩 옵션을 처리합니다.
     */
    protected function view($s_view_name, $a_data = array()) {
        // 뷰에 데이터 전달 (extract를 사용하여 변수로 사용 가능)
        if (!empty($a_data)) {
            extract($a_data);
        }
        
        // common.inc.php에서 이미 정의된 전역 변수를 뷰에서 사용할 수 있도록
        // require는 현재 스코프에서 실행되므로, 함수 내부에서 require하면
        // 함수의 로컬 스코프에서 실행됩니다. 따라서 전역 변수를 로컬 변수로 할당
        if (isset($GLOBALS['o_functions'])) {
            $o_functions = $GLOBALS['o_functions'];
        }
        
        // Views 디렉터리에서 PHP 뷰 파일 확인
        $s_view_file = WEB_SOURCE_DIR . 'Views/' . $s_view_name . '.php';
        if (!file_exists($s_view_file)) {
            throw new Exception("View {$s_view_name} not found");
        }
        
        // 출력 버퍼 시작
        ob_start();
        require $s_view_file;
        $s_output = ob_get_clean();
        
        // 출력이 JSON인지 확인 (json_decode로 검증)
        $a_json_data = json_decode($s_output, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($a_json_data)) {
            // JSON인 경우 Content-Type을 application/json으로 설정하고
            // JSON_UNESCAPED_UNICODE 옵션으로 다시 인코딩
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($a_json_data, JSON_UNESCAPED_UNICODE);
        } else {
            // JSON이 아닌 경우 HTML로 처리
            header('Content-Type: text/html; charset=utf-8');
            echo $s_output;
        }
        
        return;
    }
}

/**
 * Response 클래스 - CodeIgniter 스타일 응답 처리
 */
class Response {
    public function setJSON($a_data) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($a_data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function setStatusCode($i_code) {
        http_response_code($i_code);
        return $this;
    }
}

?>
