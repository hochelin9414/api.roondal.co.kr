<?php
/**
 * Get_ip 컨트롤러
 * 클라이언트 IP를 JSON으로 반환
 */

class Get_ip extends BaseController
{
    protected $db;
    protected $model;

    public function __construct()
    {
        parent::__construct();
        // 필요시 모델 로드
        // $this->model = $this->loadModel('Get_ip');
    }

    /**
     * 기본 메서드: 클라이언트 IP 반환
     */
    public function index()
    {
        $ip = $this->o_functions->get_ip();
        $data = array('ip' => $ip);
        return $this->o_response->setJSON($data);
    }
}

?>
