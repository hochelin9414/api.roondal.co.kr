<?php
/**
 * 모든 요청이 먼저 거치는 공통 함수 파일
 * 인증, 로깅, 초기화 등의 공통 작업을 수행합니다.
 * Front Controller: index.php를 통해 모든 요청을 수집 후 실제 스크립트 실행
 */

header('Content-Type: application/json; charset=utf-8');

// 프로젝트 루트 디렉터리 상수
DEFINE('WEB_SOURCE_DIR', "/usr/local/web_source/api.roondal.co.kr/");

// 공통 클래스 로드
require_once WEB_SOURCE_DIR . 'common_class/whitelist.php';
require_once WEB_SOURCE_DIR . 'common_class/connection.php';

// 허용 IP 체크
$o_whitelist = new Whitelist();
// $o_whitelist->check();

// 여기에 공통 로직 추가 (예: 인증, 로깅, 초기화 등)
// 함수 정의 클래스 (전역 변수로 설정)
require_once WEB_SOURCE_DIR . 'common_class/functions.php';
$GLOBALS['o_functions'] = new Functions();
$o_functions = $GLOBALS['o_functions'];

// BaseController 로드 (컨트롤러에서 사용)
require_once WEB_SOURCE_DIR . 'Controllers/BaseController.php';

/**
 * 모델 자동 로딩 함수
 * new Category_model() 형태로 사용 가능하도록 지원
 * CategoryModel.php 파일의 CategoryModel 클래스를 Category_model 별칭으로 사용
 */
spl_autoload_register(function ($className) {
    // _model로 끝나는 클래스명인 경우
    if (substr($className, -6) === '_model') {
        // Category_model -> CategoryModel
        $baseName = str_replace('_model', '', $className);
        $modelName = ucfirst($baseName) . 'Model';
        $modelFile = WEB_SOURCE_DIR . 'Models/' . $modelName . '.php';
        
        if (file_exists($modelFile)) {
            require_once $modelFile;
            // CategoryModel 클래스가 존재하고 Category_model 클래스가 없으면 별칭 생성
            if (class_exists($modelName) && !class_exists($className)) {
                // 클래스 별칭 생성 (더 안전한 방법)
                $aliasCode = "class {$className} extends {$modelName} {}";
                if (function_exists('token_get_all')) {
                    // PHP 코드 검증 후 실행
                    $tokens = @token_get_all('<?php ' . $aliasCode);
                    if ($tokens !== false) {
                        eval($aliasCode);
                    }
                }
            }
        }
    }
});

/**
 * Front Controller 라우팅
 * - PATH_INFO (/index.php/foo) 또는 ?route=foo 사용
 * - 나머지는 REQUEST_URI 기준으로 index.php prefix 제거
 * - ../ 경로 차단, 매칭 실패 시 404
 */
$s_base_dir = '/usr/local/web_source/api.roondal.co.kr'; // 라우팅 기준이 되는 베이스 디렉터리
$s_route_path = ''; // 요청에서 해석된 라우트 경로

// PATH_INFO 우선
if (!empty($_SERVER['PATH_INFO'])) {
    $s_route_path = $_SERVER['PATH_INFO'];
}
// ?route=foo/bar.php 지원
elseif (isset($_GET['route'])) {
    $s_route_path = $_GET['route'];
}
// fallback: REQUEST_URI에서 index.php prefix 제거
elseif (isset($_SERVER['REQUEST_URI'])) {
    $s_request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $s_route_path = preg_replace('#^/index\\.php#', '', $s_request_uri);
}

// 기본 라우트
if ($s_route_path === '' || $s_route_path === false || $s_route_path === '/') {
    $s_route_path = '/index';
}

// 경로 정규화 및 보안
$s_route_path = '/' . ltrim($s_route_path, '/');
// 트레일링 슬래시 제거 (루트 제외)
if ($s_route_path !== '/' && substr($s_route_path, -1) === '/') {
    $s_route_path = rtrim($s_route_path, '/');
}
if (strpos($s_route_path, '..') !== false) {
    header('HTTP/1.1 404 Not Found');
    exit;
}

/**
 * 컨트롤러 라우팅 (CI4 유사 형태)
 * 예) /get_ip -> /Controllers/Get_ip.php (index 메서드)
 *     /get_ip/method_name -> /Controllers/Get_ip.php (method_name 메서드)
 *     /admin/user -> /Controllers/Admin/User.php (index 메서드)
 *     /admin/user/list -> /Controllers/Admin/User.php (list 메서드)
 * 
 * CI 스타일: 첫 글자가 대문자인 파일명 사용
 * URL은 소문자로만 접근 가능 (대문자로 시작하는 URL은 404)
 */
$s_controller_script = '';
$s_method_name = 'index'; // 기본 메서드는 index

if (substr($s_route_path, -4) !== '.php') {
    // 경로에서 컨트롤러 이름과 메서드명 추출
    $s_path_parts = explode('/', ltrim($s_route_path, '/'));
    
    // 경로가 비어있지 않은 경우
    if (!empty($s_path_parts[0]) && $s_path_parts[0] !== 'index.php') {
        // 첫 번째 경로 부분이 대문자로 시작하면 404 반환
        if (ctype_upper(substr($s_path_parts[0], 0, 1))) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
        
        // 하위 디렉터리 지원: 경로의 모든 부분을 확인하여 파일 찾기
        // 예: /admin/user/list -> Admin/User.php (list 메서드)
        // 예: /admin/user -> Admin/User.php (index 메서드)
        
        $s_controller_found = false;
        $s_controllers_dir = rtrim($s_base_dir, '/') . '/Controllers/';
        
        // 경로의 모든 부분을 순회하며 파일 찾기
        for ($i = count($s_path_parts); $i > 0; $i--) {
            // 경로의 처음 i개 부분을 컨트롤러 경로로 시도
            $s_controller_path_parts = array_slice($s_path_parts, 0, $i);
            
            // 각 부분의 첫 글자를 대문자로 변환
            $s_controller_path_ucfirst = array_map('ucfirst', $s_controller_path_parts);
            $s_controller_file_path = $s_controllers_dir . implode('/', $s_controller_path_ucfirst) . '.php';
            
            // 파일이 존재하면 컨트롤러로 인식
            if (file_exists($s_controller_file_path) && is_file($s_controller_file_path)) {
                $s_controller_script = $s_controller_file_path;
                $s_controller_found = true;
                
                // 남은 경로 부분이 있으면 메서드명으로 사용
                if ($i < count($s_path_parts)) {
                    $s_method_name = $s_path_parts[$i];
                }
                
                break;
            }
        }
        
        // 컨트롤러를 찾았으면 원본 스크립트 경로 설정
        if ($s_controller_found) {
            $s_original_script = $s_controller_script;
        }
    }
}

// 실제 스크립트 경로 결정
if (empty($s_original_script)) {
    $s_original_script = rtrim($s_base_dir, '/') . $s_route_path;
}

// 디렉터리면 index.php
if (is_dir($s_original_script)) {
    $s_original_script = rtrim($s_original_script, '/') . '/index.php';
}

// 확장자 없으면 .php 시도
if (!file_exists($s_original_script) && substr($s_original_script, -4) !== '.php') {
    $s_original_script .= '.php';
}

// 실행
if (!empty($s_original_script) && file_exists($s_original_script) && is_file($s_original_script)) {
    $s_current_script = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : '';
    $s_target_script = realpath($s_original_script);

    // 현재 스크립트와 동일하면 재실행하지 않음
    if ($s_current_script && $s_target_script && $s_current_script === $s_target_script) {
        return;
    }

    // function.php 자체를 다시 실행하는 것을 방지
    if (basename($s_original_script) !== 'function.php') {
        // Controllers 디렉터리의 컨트롤러인지 확인
        if (strpos($s_original_script, '/Controllers/') !== false) {
            // 컨트롤러 파일 로드
            require $s_original_script;
            
            // 컨트롤러 클래스 이름 추출 (파일명에서)
            $s_controller_class = basename($s_original_script, '.php');
            
            // 클래스가 존재하는지 확인
            if (class_exists($s_controller_class)) {
                // 컨트롤러 인스턴스 생성
                $controller = new $s_controller_class();
                
                // 메서드명이 있으면 해당 메서드 호출, 없으면 index 메서드 호출
                $s_method_to_call = isset($s_method_name) ? $s_method_name : 'index';
                
                if (method_exists($controller, $s_method_to_call)) {
                    $controller->$s_method_to_call();
                } else {
                    // 메서드가 없으면 404 반환
                    header('HTTP/1.1 404 Not Found');
                    exit;
                }
            }
        } else {
            // 일반 PHP 파일은 그대로 실행
            require $s_original_script;
        }
        exit;
    }
}

// 매칭 실패 시 404
header('HTTP/1.1 404 Not Found');
exit;
