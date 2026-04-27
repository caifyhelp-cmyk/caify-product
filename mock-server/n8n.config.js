'use strict';

/**
 * n8n 인스턴스 연결 정보
 * N8N_URL 환경변수가 있으면 환경변수 우선, 없으면 여기 값 사용
 *
 * 크리덴셜 처리 정책:
 * - 워크플로우 복제 시 credential ID는 그대로 복사 (n8n 기본 동작)
 * - 모든 고객 워크플로우가 동일 credential 참조 = 공유 크리덴셜 방식
 * - 고객별 크리덴셜 분리가 필요한 경우 provision 시 /credentials API로 별도 생성 필요
 */
module.exports = {
  N8N_URL:     process.env.N8N_URL     || 'https://n8n.caify.ai',
  N8N_API_KEY: process.env.N8N_API_KEY || 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhMmNlNTVlNS01YTUwLTQyMjgtOWM5Yi1hNWM0MzBmNzM4NDEiLCJpc3MiOiJuOG4iLCJhdWQiOiJwdWJsaWMtYXBpIiwiaWF0IjoxNzc2OTgyMzIzfQ.zeIagMQxIuDN-OwQHhKuATLM0CDb-dNRCLuB5zCFzGI',

  // 복제 원본 템플릿 워크플로우 ID
  TEMPLATE_IDS: {
    info:  'DvvwnamBcqnqVgCz',  // 서브워크플로우(정보형)
    mixed: 'zUhFnjJvA7Fuz6UG',  // 서브워크플로우(혼합형)
    case:  'vUlrwTSj0b3TcIKg',  // 서브워크플로우(사례/후기형)
  },

  // 웹훅 기반 직접 트리거 ID (사례-홈페이지연동_0427 — 웹훅 URL 직접 호출)
  // /execute API가 executeWorkflowTrigger에 미지원이므로 웹훅으로 우회
  CASE_WEBHOOK_PATH: '629f30cb-f932-4330-92c5-752cc28febc3',
  CASE_WEBHOOK_WF_ID: 'yzOD6jJExSWlBi84',

  // Queue Worker ID (라우팅 연결용 — provision 시 플레이스홀더 교체)
  QUEUE_WORKER_IDS: [
    'bUXjHTh7xEecPuOr',  // CAIFY_QUEUE_WORKER1
    '2bXgAbtA3ZuAOqWc',  // CAIFY_QUEUE_WORKER2
  ],
};
