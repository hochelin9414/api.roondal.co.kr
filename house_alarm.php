<?php
exit;
require_once __DIR__ . '/common.inc.php';
$token = $o_functions->perplexity_token();
if (empty($token)) {
    exit;
}

$data = [
    "model" => "sonar-reasoning-pro",
    "messages" => [
        [
            "role" => "system",
            "content" => "
                당신은 Ubuntu 24.04 환경에서 systemd service와 timer를 작성하는 리눅스 전문가입니다.
                모든 답변은 반드시 systemd 유닛 파일 형식의 예시만 출력합니다.
                - 불필요한 설명 문장은 쓰지 않습니다.
                - Service 유닛과 Timer 유닛 두 개만 보여줍니다.
                - WantedBy는 service=multi-user.target, timer=timers.target 으로 설정합니다.
                - 모든 답변은 아래 형식을 그대로 따릅니다.
                    [service]
                    <service unit 파일 내용>

                    [timer]
                    <timer unit 파일 내용>
            "
        ],
        [
            "role" => "user",
            "content" => "2025년 12월 23일 오후 3시에 청약하라고 말해줘"
        ]
    ]
];


$response = curl_init();
curl_setopt($response, CURLOPT_URL, "https://api.perplexity.ai/chat/completions");
curl_setopt($response, CURLOPT_RETURNTRANSFER, true);
curl_setopt($response, CURLOPT_POST, true);
// JSON 인코딩
curl_setopt($response, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));

// 헤더 설정
curl_setopt($response, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $token",
    "Content-Type: application/json"
]);

$result = curl_exec($response);
if ($result === false) {
    echo 'cURL Error: ' . curl_error($response);
} else {
    echo $result;
}
curl_close($response);
