<?php
/**
 * Category 컨트롤러 예시
 * 사용자가 원하는 형태로 모델을 사용하는 예시
 */

class Category extends BaseController
{
    protected $db;
    protected $model;

    public function __construct()
    {
        parent::__construct();
        // 모델 로드 방법 1: loadModel 메서드 사용
        // $this->model = $this->loadModel('category');
        
        // 모델 로드 방법 2: 직접 인스턴스 생성 (자동 로딩 지원)
        // CategoryModel.php 파일이 있고 CategoryModel 클래스가 있어야 함
        // $this->model = new Category_model();
    }

    /**
     * 기본 메서드: 카테고리 목록 반환
     */
    public function index()
    {
        // 모델이 로드된 경우
        if (isset($this->model)) {
            // $data = $this->model->get_category();
            // return $this->response->setJSON($data);
        }
        
        // 예시 데이터
        $data = array('message' => 'Category controller is working');
        return $this->response->setJSON($data);
    }

    /**
     * AJAX 카테고리 메서드
     */
    public function ajax_category() {
        // AJAX 요청 처리 로직
        $data = array('message' => 'ajax_category method');
        return $this->response->setJSON($data);
    }
}

?>
