/**
 * Index 페이지 JavaScript 모듈
 * 재사용 가능한 모듈로 작성
 */

const IndexPage = (function() {
    'use strict';

    /**
     * 초기화 함수
     */
    function init() {
        bindEvents();
        loadEndpoints();
    }

    /**
     * 이벤트 바인딩
     */
    function bindEvents() {
        const endpoints = document.querySelectorAll('.endpoint');
        endpoints.forEach(endpoint => {
            endpoint.addEventListener('click', handleEndpointClick);
        });
    }

    /**
     * 엔드포인트 클릭 핸들러
     */
    function handleEndpointClick(event) {
        const urlElement = event.currentTarget.querySelector('.endpoint-url');
        if (urlElement) {
            const url = urlElement.textContent.trim();
            console.log('Endpoint clicked:', url);
            // 필요시 추가 동작 구현
        }
    }

    /**
     * 엔드포인트 로드 (필요시 동적 로드)
     */
    function loadEndpoints() {
        // 엔드포인트 데이터가 동적으로 필요한 경우 여기서 처리
        console.log('Endpoints loaded');
    }

    // Public API
    return {
        init: init
    };
})();

// DOM 로드 완료 시 초기화
document.addEventListener('DOMContentLoaded', function() {
    IndexPage.init();
});
