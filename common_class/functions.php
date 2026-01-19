<?php

class Functions
{
    // 퍼플렉시티 토큰 캐시
    private $s_perplexity_token = '';

    // 퍼플렉시티 토큰 반환(perplexity.key에서 key 파싱)
    public function perplexity_token()
    {
        if (!empty($this->s_perplexity_token)) {
            return $this->s_perplexity_token;
        }

        // 퍼플렉시티 키 파일 경로 후보
        $a_key_paths = array(
            WEB_SOURCE_DIR . 'common_class/config/perplexity.key',
            WEB_SOURCE_DIR . 'perplexity.key',
        );

        foreach ($a_key_paths as $s_key_path) {
            $s_key = $this->parsePerplexityKeyFile($s_key_path);
            if (!empty($s_key)) {
                $this->s_perplexity_token = $s_key;
                return $this->s_perplexity_token;
            }
        }

        return '';
    }

    // perplexity.key 파일에서 key 파싱
    private function parsePerplexityKeyFile($s_key_path)
    {
        if (empty($s_key_path) || !file_exists($s_key_path) || !is_readable($s_key_path)) {
            return '';
        }

        // 키 파일 내용
        $s_contents = @file_get_contents($s_key_path);
        if ($s_contents === false) {
            return '';
        }

        $s_contents = trim($s_contents);
        if ($s_contents === '') {
            return '';
        }

        // JSON 형식 지원: {"key":"..."}
        $a_json = json_decode($s_contents, true);
        if (is_array($a_json) && isset($a_json['key'])) {
            $s_key = trim((string)$a_json['key']);
            return trim($s_key, "\"'");
        }

        // key=... 또는 key: ... 형식 지원
        $a_lines = preg_split('/\r\n|\r|\n/', $s_contents);
        foreach ($a_lines as $s_line) {
            $s_line = trim($s_line);
            if ($s_line === '' || strpos($s_line, '#') === 0) {
                continue;
            }
            if (preg_match('/^key\s*[:=]\s*(.+)$/i', $s_line, $a_matches)) {
                $s_key = trim($a_matches[1]);
                return trim($s_key, "\"'");
            }
        }

        // 파일이 키 문자열만 있는 경우 지원
        return trim($s_contents, "\"'");
    }

    // 클라이언트 IP 반환
    public function get_ip()
    {
        return $_SERVER['REMOTE_ADDR'];
    }

    // 모델 호출
    public function call_model($s_model_name)
    {
        require_once WEB_SOURCE_DIR . 'models/' . $s_model_name . '.php';
        $o_model = new $s_model_name();
        return $o_model;
    }

    // 뷰 호출
    public function html_view($s_view_name)
    {
        require_once WEB_SOURCE_DIR . 'apps/html/' . $s_view_name . '.html';
        // echo $s_view_name;
    }
}

?>