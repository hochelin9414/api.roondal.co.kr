<?php

$a_response = array(
    'message' => $s_message,
    'received_data' => $a_received_data
);
echo json_encode($a_response);