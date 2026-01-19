<?php
// CLI/디버그 모드 에러 노출
if (php_sapi_name() === 'cli' || (isset($_GET['debug']) && $_GET['debug'] === '1')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

header('Content-Type: text/html; charset=utf-8');


/**
 * DB 연결 테스트 실행
 */
function test_db_connection()
{
    // DB 연결 객체
    require_once __DIR__ . '/common_class/connection.php';
    $o_connection = new Connection();
    $o_db = $o_connection->db_connect();
    if (!$o_db) {
        echo 'DB 연결 실패: ' . $o_connection->get_db_error();
        return;
    }

    echo 'DB 연결 성공';
    mysqli_close($o_db);
}

test_db_connection();


?>