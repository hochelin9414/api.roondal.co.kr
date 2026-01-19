<?php
header('Content-Type: application/json');

    $i_salary = 5600000;
    $i_subscription = 35;

    echo json_encode(
        [
            '월급' => $i_salary,
            '청약횟수' => $i_subscription,
            'IP' => $o_functions->get_ip()
        ],
        JSON_UNESCAPED_UNICODE
    );

?>