<?php
// Allow CLI only
if (php_sapi_name() !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

// Timezone for alarm matching
date_default_timezone_set('Asia/Seoul');

// DB connection class
require_once __DIR__ . '/common_class/connection.php';

/**
 * Mattermost message sender
 *
 * @param string $s_webhook_url Webhook URL
 * @param string $s_message     Message body
 * @param string $s_bot_name    Bot name
 * @param string $s_icon_url    Bot icon URL
 * @return bool Send result
 */
function send_mattermost_message($s_webhook_url, $s_message, $s_bot_name = '', $s_icon_url = '')
{
    // Payload data
    $a_payload = array(
        'text' => $s_message,
    );

    // Bot name
    if (trim($s_bot_name) !== '') {
        $a_payload['username'] = $s_bot_name;
    }

    // Bot icon URL
    if (trim($s_icon_url) !== '') {
        $a_payload['icon_url'] = $s_icon_url;
    }

    // Payload JSON
    $s_body = json_encode($a_payload, JSON_UNESCAPED_UNICODE);
    // CURL handle
    $o_ch = curl_init($s_webhook_url);
    curl_setopt($o_ch, CURLOPT_POST, true);
    curl_setopt($o_ch, CURLOPT_POSTFIELDS, $s_body);
    curl_setopt($o_ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($o_ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($o_ch, CURLOPT_TIMEOUT, 10);

    // CURL result
    $s_result = curl_exec($o_ch);
    // HTTP status code
    $i_http_code = curl_getinfo($o_ch, CURLINFO_HTTP_CODE);
    curl_close($o_ch);

    return ($s_result !== false && $i_http_code >= 200 && $i_http_code < 300);
}

/**
 * Alarm message builder
 *
 * @param string $s_content    Alarm content
 * @param string $s_address    Alarm address
 * @param string $s_alarm_date Alarm datetime
 * @return string Formatted message
 */
function build_alarm_message($s_content, $s_address, $s_alarm_date)
{
    // Message title
    $s_title = trim($s_content) !== '' ? trim($s_content) : '알림입니다.';
    // Formatted alarm date
    $s_formatted_date = format_alarm_date($s_alarm_date);

    // Message text
    $s_message = "### 🔔 " . $s_title . "\n\n";
    if ($s_formatted_date !== '') {
        $s_message .= "⏰ **예약 시간:** " . $s_formatted_date . "\n";
    }
    if (trim($s_address) !== '') {
        $s_message .= "🏠 **주소:** " . $s_address . "\n";
    }

    return $s_message;
}

/**
 * Alarm date formatter
 *
 * @param string $s_alarm_date Alarm datetime
 * @return string Formatted datetime string
 */
function format_alarm_date($s_alarm_date)
{
    // Alarm date value
    $s_alarm_date = trim($s_alarm_date);
    if ($s_alarm_date === '') {
        return '';
    }

    // Datetime format parse
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/', $s_alarm_date, $a_matches)) {
        $s_year = $a_matches[1];
        $s_month = $a_matches[2];
        $s_day = $a_matches[3];
        $s_hour = $a_matches[4];
        $s_minute = $a_matches[5];
        return $s_year . '년 ' . $s_month . '월 ' . $s_day . '일 ' . $s_hour . '시 ' . $s_minute . '분';
    }

    return $s_alarm_date;
}

/**
 * Alarm dispatch main
 */
function dispatch_alarm()
{
    // Bot name (optional)
    $s_bot_name = getenv('MATTERMOST_BOT_NAME');
    if ($s_bot_name === false) {
        $s_bot_name = '';
    }

    // Bot icon URL (optional)
    $s_icon_url = getenv('MATTERMOST_ICON_URL');
    if ($s_icon_url === false) {
        $s_icon_url = '';
    }

    // DB connection
    $o_connection = new Connection();
    $o_db = $o_connection->db_connect();
    if (!$o_db) {
        fwrite(STDERR, "DB 연결 실패: " . $o_connection->get_db_error() . "\n");
        return 1;
    }

    // Current time string
    $s_now_time = date('H:i');

    // Query for matching alarms by HH:MM (complete_flag = N, webhook_url 포함)
    $s_query = "SELECT cron_num, content, address, alarm_date, webhook_url FROM g5_alarm WHERE complete_flag = 'N' AND DATE_FORMAT(alarm_date, '%H:%i') = ?";
    // Prepared statement
    $o_stmt = mysqli_prepare($o_db, $s_query);
    if (!$o_stmt) {
        fwrite(STDERR, "쿼리 준비 실패\n");
        mysqli_close($o_db);
        return 1;
    }

    // Update query for completion flag
    $s_update_query = "UPDATE g5_alarm SET complete_flag = 'Y' WHERE cron_num = ?";
    $o_update_stmt = mysqli_prepare($o_db, $s_update_query);
    if (!$o_update_stmt) {
        fwrite(STDERR, "완료 플래그 업데이트 준비 실패\n");
        mysqli_stmt_close($o_stmt);
        mysqli_close($o_db);
        return 1;
    }

    mysqli_stmt_bind_param($o_stmt, 's', $s_now_time);
    mysqli_stmt_execute($o_stmt);
    mysqli_stmt_bind_result($o_stmt, $i_cron_num, $s_content, $s_address, $s_alarm_date, $s_webhook_url);

    // 알림 데이터 버퍼
    $a_alarm_rows = array();
    while (mysqli_stmt_fetch($o_stmt)) {
        $a_alarm_rows[] = array(
            'i_cron_num' => $i_cron_num,
            's_content' => $s_content,
            's_address' => $s_address,
            's_alarm_date' => $s_alarm_date,
            's_webhook_url' => $s_webhook_url,
        );
    }

    mysqli_stmt_close($o_stmt);

    // Send count
    $i_sent = 0;
    foreach ($a_alarm_rows as $a_row) {
        // Webhook URL 확인
        if (empty($a_row['s_webhook_url'])) {
            fwrite(STDERR, "Webhook URL이 비어있습니다: cron_num=" . $a_row['i_cron_num'] . "\n");
            continue;
        }

        // Message body
        $s_message = build_alarm_message($a_row['s_content'], $a_row['s_address'], $a_row['s_alarm_date']);
        // Send to Mattermost (각 레코드의 webhook_url 사용)
        $b_sent = send_mattermost_message($a_row['s_webhook_url'], $s_message, $s_bot_name, $s_icon_url);
        if ($b_sent) {
            // 완료 플래그 업데이트
            $i_cron_num = $a_row['i_cron_num'];
            mysqli_stmt_bind_param($o_update_stmt, 'i', $i_cron_num);
            if (!mysqli_stmt_execute($o_update_stmt)) {
                fwrite(STDERR, "완료 플래그 업데이트 실패: cron_num=" . $i_cron_num . "\n");
                continue;
            }
            $i_sent++;
        } else {
            fwrite(STDERR, "알림 전송 실패: cron_num=" . $a_row['i_cron_num'] . ", webhook=" . $a_row['s_webhook_url'] . "\n");
        }
    }

    mysqli_stmt_close($o_update_stmt);
    mysqli_close($o_db);

    // Output result
    echo "sent=" . $i_sent . "\n";
    return 0;
}

exit(dispatch_alarm());
