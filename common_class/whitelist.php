<?php

class Whitelist {

    /**
     * @var array 화이트리스트로 허용할 IP/CIDR 목록
     */
    private $a_whitelist_ip = array(
        '127.0.0.1',
        '192.168.0.0/24',
        '121.166.140.142/32'
    );

    /**
     * 요청 IP가 허용 목록에 없으면 404를 반환한다.
     *
     * @return bool 허용 시 true
     */
    public function check() {
        $s_remote_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

        if (!$this->isAllowed($s_remote_ip)) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        return true;
    }

    /**
     * IP 유효성을 확인하고 화이트리스트에 포함되는지 검사한다.
     *
     * @param string $s_remote_ip 클라이언트 IP
     * @return bool 허용 여부
     */
    private function isAllowed($s_remote_ip) {
        if (!filter_var($s_remote_ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach ($this->a_whitelist_ip as $s_allowed_ip) {
            if ($this->matchIp($s_remote_ip, $s_allowed_ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 단일 IP 또는 CIDR 블록과의 매칭을 수행한다.
     *
     * @param string $s_remote_ip 클라이언트 IP
     * @param string $s_allowed_ip 허용 IP 또는 CIDR
     * @return bool 매칭 결과
     */
    private function matchIp($s_remote_ip, $s_allowed_ip) {
        if (strpos($s_allowed_ip, '/') === false) {
            return $s_remote_ip === $s_allowed_ip;
        }

        list($s_subnet, $s_mask) = explode('/', $s_allowed_ip, 2);
        $i_mask_bits = (int)$s_mask;

        if ($i_mask_bits < 0 || $i_mask_bits > 32) {
            return false;
        }

        $i_remote_ip = ip2long($s_remote_ip);
        $i_subnet_ip = ip2long($s_subnet);

        if ($i_remote_ip === false || $i_subnet_ip === false) {
            return false;
        }

        $i_mask = -1 << (32 - $i_mask_bits);
        return ($i_remote_ip & $i_mask) === ($i_subnet_ip & $i_mask);
    }
}
