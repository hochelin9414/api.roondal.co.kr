<?php
/**
 * Index 컨트롤러
 * 기본 홈 화면
 */

class Index extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 기본 메서드: 홈 화면 표시
     */
    public function index()
    {
        // 사용 가능한 API 엔드포인트 목록
        $data = array(
            'title' => 'API 서버',
            'endpoints' => array(
                array(
                    'name' => 'Get IP',
                    'url' => '/get_ip',
                    'description' => '클라이언트 IP 주소를 반환합니다'
                ),
                array(
                    'name' => 'Reserve',
                    'url' => '/reserve',
                    'description' => '예약 관련 API'
                ),
                array(
                    'name' => 'Category',
                    'url' => '/category',
                    'description' => '카테고리 관련 API'
                )
            )
        );
        
        $this->view('index', $data);
    }
}

?>
