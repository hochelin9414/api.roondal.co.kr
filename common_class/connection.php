<?php

class Connection
{
    // DB 설정 파일 경로
    private $s_config_path;
    // DB 연결 오류 메시지
    private $s_db_error = '';

    // DB 설정 경로 초기화
    public function __construct()
    {
        $this->s_config_path = __DIR__ . '/config/db.ini';
    }

    // 레거시: DB 설정 파일 내용 반환
    public function connect()
    {
        $s_contents = @file_get_contents($this->s_config_path);
        if ($s_contents === false) {
            return '파일을 읽을 수 없습니다.';
        }

        return $s_contents;
    }

    // DB 연결 함수
    public function db_connect()
    {
        // DB 연결 오류 초기화
        $this->s_db_error = '';

        // DB 키 파일 경로(숨김 파일)
        $s_key_path = __DIR__ . '/config/.db_ini.key';
        // DB 키 파일 보조 경로
        $s_key_fallback_path = __DIR__ . '/config/db_ini.key';
        // DB 설정 배열
        $a_db_config = @parse_ini_file($this->s_config_path);

        if (!is_array($a_db_config)) {
            $this->s_db_error = 'DB 설정을 읽을 수 없습니다.';
            return false;
        }

        // DB 호스트
        $s_db_host = isset($a_db_config['HOST']) ? $a_db_config['HOST'] : '';
        // DB 사용자
        $s_db_user = isset($a_db_config['USER']) ? $a_db_config['USER'] : '';
        // DB 비밀번호(암호문)
        $s_db_password = isset($a_db_config['PASSWORD']) ? $a_db_config['PASSWORD'] : '';
        // DB 이름
        $s_db_name = isset($a_db_config['DATABASE']) ? $a_db_config['DATABASE'] : '';
        // DB 포트
        $i_db_port = isset($a_db_config['PORT']) ? intval($a_db_config['PORT']) : 3306;

        // 키 파일 경로 보정
        if ((!file_exists($s_key_path) || !is_readable($s_key_path)) && file_exists($s_key_fallback_path)) {
            $s_key_path = $s_key_fallback_path;
        }

        // mysqli 확장 확인
        if (!function_exists('mysqli_connect')) {
            $this->s_db_error = 'mysqli 확장이 활성화되지 않았습니다.';
            return false;
        }

        // DB 비밀번호 복호화
        $s_db_password = $this->decrypt_db_password($s_db_password, $s_key_path);
        if ($s_db_password === '') {
            if ($this->s_db_error === '') {
                $this->s_db_error = 'DB 비밀번호 복호화에 실패했습니다.';
            }
            return false;
        }
        // 복호화 결과 개행 제거
        $s_db_password = rtrim($s_db_password, "\r\n");

        // mysqli 예외 비활성화
        mysqli_report(MYSQLI_REPORT_OFF);
        // 값을 받아와서 연결
        try {
            $o_db = @mysqli_connect($s_db_host, $s_db_user, $s_db_password, $s_db_name, $i_db_port);
        } catch (mysqli_sql_exception $o_exception) {
            $this->s_db_error = $o_exception->getMessage();
            return false;
        }
        // 연결 실패 시 false 반환
        if (!$o_db) {
            $this->s_db_error = mysqli_connect_error();
            return false;
        }

        mysqli_set_charset($o_db, 'utf8mb4');
        // 연결 성공 시 반환
        return $o_db;
    }

    // DB 연결 오류 메시지 반환
    public function get_db_error()
    {
        return $this->s_db_error;
    }

    // DB 비밀번호 복호화 (AES-256)
    private function decrypt_db_password($s_encrypted_password, $s_key_path)
    {
        // 암호문이 비어 있으면 중단
        if (empty($s_encrypted_password)) {
            $this->s_db_error = 'DB 비밀번호가 비어 있습니다.';
            return '';
        }

        // openssl 확장 확인
        if (!function_exists('openssl_decrypt')) {
            $this->s_db_error = 'openssl 확장이 활성화되지 않았습니다.';
            return '';
        }

        // 암호문 문자열 정리
        $s_encrypted_password = trim($s_encrypted_password);
        $s_encrypted_password = trim($s_encrypted_password, "\"'");

        // 키 파일 존재 여부 확인
        if (!file_exists($s_key_path)) {
            $this->s_db_error = 'DB 키 파일이 없습니다: ' . $s_key_path;
            return '';
        }

        // 키 파일 권한 확인
        if (!is_readable($s_key_path)) {
            $this->s_db_error = 'DB 키 파일을 읽을 수 없습니다(권한): ' . $s_key_path;
            return '';
        }

        // 키 파일 내용
        $s_key_contents = @file_get_contents($s_key_path);
        if ($s_key_contents === false) {
            $this->s_db_error = 'DB 키 파일을 읽을 수 없습니다: ' . $s_key_path;
            return '';
        }

        // 키 파일 JSON 데이터
        $a_key_json = json_decode($s_key_contents, true);
        // 키 파일 줄 배열
        $a_key_lines = preg_split('/\r\n|\r|\n/', trim($s_key_contents));
        // 키 파일 첫 줄
        $s_first_line = isset($a_key_lines[0]) ? trim($a_key_lines[0]) : '';
        // 키 문자열 후보
        $s_key_candidate = '';
        // IV 문자열 후보
        $s_iv_candidate = '';
        // 패스프레이즈 문자열 후보
        $s_passphrase = '';

        // JSON에서 key/iv/패스프레이즈 추출
        if (is_array($a_key_json)) {
            if (isset($a_key_json['key'])) {
                $s_key_candidate = trim((string)$a_key_json['key']);
            }
            if (isset($a_key_json['iv'])) {
                $s_iv_candidate = trim((string)$a_key_json['iv']);
            }
            if (isset($a_key_json['passphrase'])) {
                $s_passphrase = trim((string)$a_key_json['passphrase']);
            } elseif (isset($a_key_json['password'])) {
                $s_passphrase = trim((string)$a_key_json['password']);
            } elseif (isset($a_key_json['pass'])) {
                $s_passphrase = trim((string)$a_key_json['pass']);
            }
        }

        // key=, iv=, pass= 라인 파싱
        $a_key_pairs = array();
        if (is_array($a_key_lines)) {
            foreach ($a_key_lines as $s_line) {
                $s_line = trim($s_line);
                if ($s_line === '') {
                    continue;
                }
                if (preg_match('/^(key|iv|passphrase|password|pass)\s*[:=]\s*(.+)$/i', $s_line, $a_matches)) {
                    $s_pair_key = strtolower($a_matches[1]);
                    $a_key_pairs[$s_pair_key] = trim($a_matches[2]);
                }
            }
        }

        if ($s_key_candidate === '' && isset($a_key_pairs['key'])) {
            $s_key_candidate = $a_key_pairs['key'];
        }
        if ($s_iv_candidate === '' && isset($a_key_pairs['iv'])) {
            $s_iv_candidate = $a_key_pairs['iv'];
        }

        if ($s_passphrase === '' && isset($a_key_pairs['passphrase'])) {
            $s_passphrase = $a_key_pairs['passphrase'];
        } elseif ($s_passphrase === '' && isset($a_key_pairs['password'])) {
            $s_passphrase = $a_key_pairs['password'];
        } elseif ($s_passphrase === '' && isset($a_key_pairs['pass'])) {
            $s_passphrase = $a_key_pairs['pass'];
        }

        if ($s_passphrase === '' && $s_first_line !== '') {
            $s_passphrase = $s_first_line;
        }

        if ($s_passphrase === '') {
            $this->s_db_error = 'DB 키 파일이 비어 있습니다.';
            return '';
        }

        // base64 정리 문자열
        $s_base64 = preg_replace('/\s+/', '', $s_encrypted_password);
        // base64 디코딩 데이터(엄격)
        $s_cipher_data = base64_decode($s_base64, true);
        if ($s_cipher_data === false) {
            // base64 디코딩 데이터(완화)
            $s_cipher_data = base64_decode($s_base64, false);
            if ($s_cipher_data === false) {
                $this->s_db_error = 'DB 비밀번호 base64 디코딩에 실패했습니다.';
                return '';
            }
        }

        // 솔트 헤더
        $s_header = substr($s_cipher_data, 0, 8);
        if ($s_header === 'Salted__') {
            // 솔트 값
            $s_salt = substr($s_cipher_data, 8, 8);
            // 암호문
            $s_ciphertext = substr($s_cipher_data, 16);

            // 키/IV 생성 데이터
            $s_key_iv = '';
            // 이전 해시
            $s_prev = '';
            while (strlen($s_key_iv) < 48) {
                $s_prev = md5($s_prev . $s_passphrase . $s_salt, true);
                $s_key_iv .= $s_prev;
            }

            // AES 키
            $s_key = substr($s_key_iv, 0, 32);
            // AES IV
            $s_iv = substr($s_key_iv, 32, 16);

            // 복호화 결과(MD5)
            $s_decrypted = openssl_decrypt($s_ciphertext, 'aes-256-cbc', $s_key, OPENSSL_RAW_DATA, $s_iv);
            if ($s_decrypted !== false) {
                return $s_decrypted;
            }

            // SHA256 키/IV 생성 데이터
            $s_key_iv = '';
            // 이전 해시
            $s_prev = '';
            while (strlen($s_key_iv) < 48) {
                $s_prev = hash('sha256', $s_prev . $s_passphrase . $s_salt, true);
                $s_key_iv .= $s_prev;
            }

            // AES 키(SHA256)
            $s_key = substr($s_key_iv, 0, 32);
            // AES IV(SHA256)
            $s_iv = substr($s_key_iv, 32, 16);

            // 복호화 결과(SHA256)
            $s_decrypted = openssl_decrypt($s_ciphertext, 'aes-256-cbc', $s_key, OPENSSL_RAW_DATA, $s_iv);
            if ($s_decrypted !== false) {
                return $s_decrypted;
            }

            // PBKDF2 복호화 시도
            if (function_exists('hash_pbkdf2')) {
                // PBKDF2 키/IV 생성 데이터
                $s_key_iv = hash_pbkdf2('sha256', $s_passphrase, $s_salt, 10000, 48, true);
                // PBKDF2 AES 키
                $s_key = substr($s_key_iv, 0, 32);
                // PBKDF2 AES IV
                $s_iv = substr($s_key_iv, 32, 16);

                // 복호화 결과(PBKDF2)
                $s_decrypted = openssl_decrypt($s_ciphertext, 'aes-256-cbc', $s_key, OPENSSL_RAW_DATA, $s_iv);
                if ($s_decrypted !== false) {
                    return $s_decrypted;
                }
            }
        }

        // 키/IV 후보 문자열 보정
        $s_key_part = trim($s_key_candidate);
        $s_iv_part = trim($s_iv_candidate);

        // 키/IV 후보가 없으면 분리 문자열 사용
        if ($s_key_part === '' || $s_iv_part === '') {
            // 키 파일 분리 문자열
            $a_key_parts = preg_split('/[\r\n:|,]+/', trim($s_key_contents));
            if (is_array($a_key_parts) && count($a_key_parts) >= 2) {
                // 키 문자열
                $s_key_part = trim($a_key_parts[0]);
                // IV 문자열
                $s_iv_part = trim($a_key_parts[1]);
            }
        }

        if ($s_key_part !== '' && $s_iv_part !== '') {
            // HEX 키 여부
            $b_key_hex = ctype_xdigit($s_key_part) && (strlen($s_key_part) % 2 === 0);
            // HEX IV 여부
            $b_iv_hex = ctype_xdigit($s_iv_part) && (strlen($s_iv_part) % 2 === 0);

            // 키 바이너리
            $s_key = $b_key_hex ? hex2bin($s_key_part) : $s_key_part;
            if ($s_key === false) {
                $s_key = '';
            }
            // IV 바이너리
            $s_iv = $b_iv_hex ? hex2bin($s_iv_part) : $s_iv_part;
            if ($s_iv === false) {
                $s_iv = '';
            }

            // base64 키 여부
            if ($s_key === '') {
                $s_key = base64_decode($s_key_part, true);
                if ($s_key === false) {
                    $s_key = '';
                }
            }

            // base64 IV 여부
            if ($s_iv === '') {
                $s_iv = base64_decode($s_iv_part, true);
                if ($s_iv === false) {
                    $s_iv = '';
                }
            }

            if (!empty($s_key) && !empty($s_iv)) {
                // 복호화 대상 데이터
                $s_ciphertext = ($s_header === 'Salted__') ? substr($s_cipher_data, 16) : $s_cipher_data;
                // 복호화 결과(키/IV 직접)
                $s_decrypted = openssl_decrypt($s_ciphertext, 'aes-256-cbc', $s_key, OPENSSL_RAW_DATA, $s_iv);
                if ($s_decrypted !== false) {
                    return $s_decrypted;
                }
            }
        }

        $this->s_db_error = 'DB 비밀번호 복호화에 실패했습니다.';
        return '';
    }

    /**
     * PostgreSQL 연결 함수
     *
     * @return resource|false PostgreSQL 연결 리소스 또는 false
     */
    public function pg_connect()
    {
        // PostgreSQL 연결 오류 초기화
        $this->s_db_error = '';

        // PostgreSQL 설정 파일 경로
        $s_pg_config_path = __DIR__ . '/config/pg.ini';
        // DB 키 파일 경로
        $s_key_path = __DIR__ . '/config/.db_ini.key';
        // DB 키 파일 보조 경로
        $s_key_fallback_path = __DIR__ . '/config/db_ini.key';

        // PostgreSQL 설정 배열
        $a_pg_config = @parse_ini_file($s_pg_config_path);

        if (!is_array($a_pg_config)) {
            $this->s_db_error = 'PostgreSQL 설정을 읽을 수 없습니다.';
            return false;
        }

        // PostgreSQL 호스트
        $s_pg_host = isset($a_pg_config['HOST']) ? $a_pg_config['HOST'] : '';
        // PostgreSQL 사용자
        $s_pg_user = isset($a_pg_config['USER']) ? $a_pg_config['USER'] : '';
        // PostgreSQL 비밀번호(암호문 또는 평문)
        $s_pg_password = isset($a_pg_config['PASSWORD']) ? $a_pg_config['PASSWORD'] : '';
        // PostgreSQL DB 이름
        $s_pg_dbname = isset($a_pg_config['DATABASE']) ? $a_pg_config['DATABASE'] : '';
        // PostgreSQL 포트
        $i_pg_port = isset($a_pg_config['PORT']) ? intval($a_pg_config['PORT']) : 5432;

        // 키 파일 경로 보정
        if ((!file_exists($s_key_path) || !is_readable($s_key_path)) && file_exists($s_key_fallback_path)) {
            $s_key_path = $s_key_fallback_path;
        }

        // pgsql 확장 확인
        if (!function_exists('pg_connect')) {
            $this->s_db_error = 'pgsql 확장이 활성화되지 않았습니다.';
            return false;
        }

        // PostgreSQL 비밀번호 복호화 시도 (실패하면 평문 사용)
        $s_decrypted_password = $this->decrypt_db_password($s_pg_password, $s_key_path);
        if ($s_decrypted_password !== '') {
            $s_pg_password = rtrim($s_decrypted_password, "\r\n");
        } else {
            // 복호화 실패 시 평문으로 시도
            $this->s_db_error = '';
            $s_pg_password = trim($s_pg_password, "\"'");
        }

        // PostgreSQL 연결 문자열
        $s_connection_string = sprintf(
            "host=%s port=%d dbname=%s user=%s password=%s",
            $s_pg_host,
            $i_pg_port,
            $s_pg_dbname,
            $s_pg_user,
            $s_pg_password
        );

        // PostgreSQL 연결
        $o_pg = @pg_connect($s_connection_string);
        if (!$o_pg) {
            $this->s_db_error = 'PostgreSQL 연결 실패: ' . (pg_last_error() ?: '알 수 없는 오류');
            return false;
        }

        return $o_pg;
    }
}

