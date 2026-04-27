/**
 * Caify Mock API Server
 * 실제 PHP 서버(caify.ai) 구조를 그대로 미러링하는 테스트 서버
 *
 * 실제 DB 테이블: ai_posts (id, customer_id, prompt_id, prompt_node_id,
 * title, subject, intro, html, naver_html, status, posting_date, created_at)
 *
 * status: 0=대기(관리자 미승인), 1=승인완료, 2=발행완료(publishing done - mock 추가)
 * posting_date: Naver 발행 시각 (NULL=미발행)
 *
 * 주요 엔드포인트 (Electron tray + Flutter app 연동):
 * GET /api/posts?status=ready&member_pk=X → 발행 대기 포스트 목록
 * POST /api/posts → n8n이 AI 생성 포스트 저장
 * POST /api/posts/:id/published → 발행 완료 기록
 * POST /api/posts/:id/failed → 발행 실패 기록
 * GET /api/post_meta?id=X → 고객 프롬프트 메타데이터
 * POST /member/login → 로그인 (Bearer 토큰 발급)
 * GET /member/me → 내 정보 + 플랜/워크플로우 현황
 * POST /member/provision → (관리자) 유료 고객 워크플로우 복제·활성화
 * GET /admin/posts → 관리자: 전체 포스트 조회
 * POST /admin/posts/:id/approve → 관리자: 포스트 승인
 *
 * 사용법:
 * npm install && npm start
 * → http://localhost:3030
 */

'use strict';

const express = require('express');
const fs = require('fs');
const path = require('path');
const multer  = require('multer');
const n8nCfg = require('./n8n.config');

const app = express();
const PORT = process.env.PORT || 3030;

app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// multer: case_images 파일 업로드 처리 (메모리에 보관, 파일 저장 안 함)
const upload = multer({ storage: multer.memoryStorage(), limits: { fileSize: 10 * 1024 * 1024 } });

// ── CORS (Flutter/Electron 에서 접근 가능하도록) ────────────────
app.use((req, res, next) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type,Authorization');
  if (req.method === 'OPTIONS') return res.sendStatus(204);
  next();
});

// ── 파일 기반 영속화 (Render 재시작 시에도 데이터 유지) ──────────
// Render에서는 /tmp 또는 프로젝트 내 data/ 디렉터리에 저장
// 실서버 전환 시 이 블록을 DB 연결로 교체하면 됨
const DATA_DIR = path.join(__dirname, 'data');
const DB_FILE = path.join(DATA_DIR, 'db.json');

function loadDb() {
  try {
    if (fs.existsSync(DB_FILE)) {
      const raw = fs.readFileSync(DB_FILE, 'utf8');
      return JSON.parse(raw);
    }
  } catch (e) {
    console.warn('[db] 파일 로드 실패, 기본 데이터 사용:', e.message);
  }
  return null;
}

function saveDb() {
  try {
    if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
    fs.writeFileSync(DB_FILE, JSON.stringify(
      { posts, messages, nextPostId, nextMsgId, publishQueue, nextQueueId, cases, nextCaseId, memberWorkflows }, null, 2));
  } catch (e) {
    console.warn('[db] 파일 저장 실패:', e.message);
  }
}

// ── 기본 데이터 (DB 파일 없을 때 초기값) ─────────────────────────
const DEFAULT_POSTS = [
  {
    id: 1,
    customer_id: 1,
    prompt_id: 101,
    prompt_node_id: 'node_abc123',
    title: '[테스트] 치아 임플란트 완전 가이드 2026',
    subject: '임플란트',
    intro: '임플란트가 고민이신 분들을 위한 핵심 정보',
    html: '<h2>임플란트란?</h2><p>치아를 잃었을 때 가장 자연스럽고 효과적인 대체 방법입니다.</p><p>임플란트는 자연치아와 가장 유사한 기능을 회복할 수 있는 시술로, 저작 기능과 심미성을 동시에 만족시킵니다.</p><h2>임플란트 시술 과정</h2><p>1단계: 정밀 검진 및 CT 촬영으로 뼈 상태를 확인합니다.</p><p>2단계: 잇몸 뼈에 티타늄 픽스처를 식립합니다.</p><p>3단계: 3~6개월 골유착 기간을 거칩니다.</p><p>4단계: 지대주와 크라운을 연결하여 최종 보철을 완성합니다.</p><img src="https://images.unsplash.com/photo-1588776814546-1ffcf47267a5?w=800&q=80" alt="임플란트 시술 과정" style="max-width:100%;height:auto;display:block;margin:16px 0"><h2>비용 및 주의사항</h2><p>임플란트 비용은 1개당 100~150만 원 수준이며, 건강보험 적용 시 본인부담금이 대폭 줄어듭니다.</p><p>당뇨·골다공증 환자는 시술 전 반드시 전문의 상담이 필요합니다.</p><p>📞 [Caify 자동입력 테스트] 타이틀·본문·태그·이미지가 모두 정상 삽입됐는지 확인하세요.</p>',
    naver_html: '<h2>임플란트란?</h2><p>치아를 잃었을 때 가장 자연스럽고 효과적인 대체 방법입니다.</p><p>임플란트는 자연치아와 가장 유사한 기능을 회복할 수 있는 시술로, 저작 기능과 심미성을 동시에 만족시킵니다.</p><h2>임플란트 시술 과정</h2><p>1단계: 정밀 검진 및 CT 촬영으로 뼈 상태를 확인합니다.</p><p>2단계: 잇몸 뼈에 티타늄 픽스처를 식립합니다.</p><p>3단계: 3~6개월 골유착 기간을 거칩니다.</p><p>4단계: 지대주와 크라운을 연결하여 최종 보철을 완성합니다.</p><img src="https://images.unsplash.com/photo-1588776814546-1ffcf47267a5?w=800&q=80" alt="임플란트 시술 과정" style="max-width:100%;height:auto;display:block;margin:16px 0"><h2>비용 및 주의사항</h2><p>임플란트 비용은 1개당 100~150만 원 수준이며, 건강보험 적용 시 본인부담금이 대폭 줄어듭니다.</p><p>당뇨·골다공증 환자는 시술 전 반드시 전문의 상담이 필요합니다.</p><p>📞 [Caify 자동입력 테스트] 타이틀·본문·태그·이미지가 모두 정상 삽입됐는지 확인하세요.</p>',
    tags: ['임플란트', '강남치과', '임플란트비용', '치아임플란트', '임플란트잘하는곳'],
    status: 1,
    posting_date: null,
    created_at: new Date(Date.now() - 3 * 24 * 60 * 60 * 1000).toISOString(),
  },
  {
    id: 2,
    customer_id: 1,
    prompt_id: 101,
    prompt_node_id: 'node_abc124',
    title: '[테스트] 스케일링 꼭 받아야 할까? 치과 전문의 조언',
    subject: '스케일링',
    intro: '스케일링의 필요성과 주기 안내',
    html: '<h2>스케일링이란?</h2><p>스케일링은 치아 표면과 잇몸 사이에 쌓인 치석을 전문 기구로 제거하는 시술입니다.</p><p>치석은 칫솔질만으로는 제거되지 않으며, 방치하면 잇몸 질환과 충치의 원인이 됩니다.</p><img src="https://images.unsplash.com/photo-1606811841689-23dfddce3e95?w=800&q=80" alt="스케일링 시술" style="max-width:100%;height:auto;display:block;margin:16px 0"><h2>스케일링 권장 주기</h2><p>건강한 성인은 6개월에 1회, 잇몸 질환이 있는 경우 3개월에 1회 권장합니다.</p><p>건강보험 적용 시 연 1회 본인부담금 약 1만 5천 원으로 받을 수 있습니다.</p><p>🦷 [Caify 자동입력 테스트 v2]</p>',
    naver_html: '<h2>스케일링이란?</h2><p>스케일링은 치아 표면과 잇몸 사이에 쌓인 치석을 전문 기구로 제거하는 시술입니다.</p><p>치석은 칫솔질만으로는 제거되지 않으며, 방치하면 잇몸 질환과 충치의 원인이 됩니다.</p><img src="https://images.unsplash.com/photo-1606811841689-23dfddce3e95?w=800&q=80" alt="스케일링 시술" style="max-width:100%;height:auto;display:block;margin:16px 0"><h2>스케일링 권장 주기</h2><p>건강한 성인은 6개월에 1회, 잇몸 질환이 있는 경우 3개월에 1회 권장합니다.</p><p>건강보험 적용 시 연 1회 본인부담금 약 1만 5천 원으로 받을 수 있습니다.</p><p>🦷 [Caify 자동입력 테스트 v2]</p>',
    tags: ['스케일링', '스케일링주기', '치석제거', '잇몸치료', '강남치과'],
    status: 1,
    posting_date: null,
    created_at: new Date(Date.now() - 5 * 24 * 60 * 60 * 1000).toISOString(),
  },
  {
    id: 3,
    customer_id: 1,
    prompt_id: 101,
    prompt_node_id: 'node_abc125',
    title: '[대기중] 아직 관리자 미승인 포스트',
    subject: '치아미백',
    intro: '미백 방법 비교',
    html: '<p>미백 내용</p>',
    naver_html: '<p>미백 내용</p>',
    status: 0,
    posting_date: null,
    created_at: new Date(Date.now() - 1 * 24 * 60 * 60 * 1000).toISOString(),
  },
  {
    id: 4,
    customer_id: 2,
    prompt_id: 202,
    prompt_node_id: 'node_def456',
    title: '[고객2] 강남 교정 전문 클리닉 소개',
    subject: '교정',
    intro: '투명교정 vs 일반교정 비교',
    html: '<p>교정 내용</p>',
    naver_html: '<div class="se-main-container"><p>교정 내용 (고객2)</p></div>',
    status: 1,
    posting_date: null,
    created_at: new Date(Date.now() - 4 * 24 * 60 * 60 * 1000).toISOString(),
  },
];

const DEFAULT_MESSAGES = [
  {
    id: 1,
    member_pk: 1,
    type: 'post.created',
    is_system: true,
    text: '안녕하세요! 새 블로그 포스팅이 준비됐어요 🎉\n\n"치아 임플란트 완전 가이드 2026"\n\n내용을 확인하고 네이버 블로그에 발행해 보세요!',
    post_id: 1,
    post_title: '[샘플] 치아 임플란트 완전 가이드 2026',
    post_html: '<h2>임플란트란?</h2><p>치아를 잃었을 때 가장 자연스러운 대체 방법입니다.</p><p>임플란트는 <strong>자연치아와 가장 유사한 기능</strong>을 회복할 수 있는 시술입니다.</p>',
    meta: null,
    actions: [
      { label: '포스팅 보기', action_key: 'view_post' },
      { label: '에디터 열기', action_key: 'publish_post' },
    ],
    read: false,
    created_at: new Date(Date.now() - 2 * 60 * 60 * 1000).toISOString(),
  },
  {
    id: 2,
    member_pk: 1,
    type: 'rank.check',
    is_system: true,
    text: '📊 "임플란트 강남" 키워드 순위 결과입니다.\n\n7일 전: 측정 불가 → 현재: 12위\n\n꾸준히 포스팅하면 상위 노출이 가능합니다!',
    post_id: null,
    post_title: null,
    post_html: null,
    meta: { keyword: '임플란트 강남', rank: 12, prev_rank: null, check_day: 7 },
    actions: [],
    read: false,
    created_at: new Date(Date.now() - 1 * 60 * 60 * 1000).toISOString(),
  },
  {
    id: 3,
    member_pk: 1,
    type: 'strategy.weekly',
    is_system: true,
    text: '📋 이번 주 콘텐츠 전략 요약\n\n✅ 발행 예정 키워드\n• 스케일링 주기\n• 임플란트 비용\n• 치아교정 기간\n\n⏱ 예상 발행: 월/수/금 오전 10시',
    post_id: null,
    post_title: null,
    post_html: null,
    meta: { week: '2026-W17' },
    actions: [],
    read: true,
    created_at: new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString(),
  },
];

// 기본 사례 데이터
const DEFAULT_CASES = [
  {
    id: 1,
    member_pk: 1,
    case_title: '[샘플] 임플란트 사례 - 65세 환자',
    raw_content: '65세 여성 환자. 하악 우측 제1대구치 결손 2년. 골밀도 양호. 임플란트 식립 후 3개월 만에 골유착 완료. 최종 보철 장착 후 저작 기능 완전 회복.',
    ai_status: 'done',
    ai_title: '65세에도 성공적인 임플란트 — 골유착 3개월 완성 사례',
    ai_summary: '고령 환자의 임플란트 성공 사례로 골밀도 검사부터 최종 보철까지의 전 과정을 담았습니다.',
    post_id: 1,
    files: [],
    created_at: new Date(Date.now() - 5 * 86400000).toISOString(),
  },
];

// 파일에서 복원하거나 기본값 사용
const savedDb = loadDb();
let posts        = savedDb?.posts        ?? DEFAULT_POSTS;
let messages     = savedDb?.messages     ?? DEFAULT_MESSAGES;
let publishQueue = savedDb?.publishQueue ?? [];
let cases        = savedDb?.cases        ?? DEFAULT_CASES;
let nextPostId   = savedDb?.nextPostId   ?? 5;
let nextMsgId    = savedDb?.nextMsgId    ?? 4;
let nextQueueId  = savedDb?.nextQueueId  ?? 1;
let nextCaseId   = savedDb?.nextCaseId   ?? 2;

// ── 인메모리 DB (members는 자격증명이므로 코드에서 관리) ──────────
// caify_member 테이블 (실제: id=PK/member_pk, member_id=로그인ID)
// 실서버 전환 시: DB 조회로 교체
const members = [
  {
    id: 1,
    member_id: 'testuser',
    passwd: 'password123', // 실서버: password_hash()
    company_name: '테스트 치과',
    api_token: 'mock-token-testuser',
    tier: 1,          // 0=무료, 1=유료
    n8n_workflow_ids: {
      case:  'wf-testuser-case',
      info:  'wf-testuser-info',
      promo: 'wf-testuser-promo',
      plusA: 'wf-testuser-plus',
    },
  },
  {
    id: 2,
    member_id: 'dental2',
    passwd: 'password123',
    company_name: '강남 치과의원',
    api_token: 'mock-token-dental2',
    tier: 1,
    n8n_workflow_ids: {
      case:  'wf-dental2-case',
      info:  'wf-dental2-info',
      promo: 'wf-dental2-promo',
      plusA: 'wf-dental2-plus',
    },
  },
  {
    id: 10, // 관리자 (실서버: id=10)
    member_id: 'admin',
    passwd: 'adminpass',
    company_name: 'Caify 관리자',
    api_token: 'mock-token-admin',
    tier: 1,
    n8n_workflow_ids: null,
  },
];

// caify_prompt 테이블 (post_meta API용)
const prompts = [
  {
    id: 101,
    member_pk: 1,
    brand_name: '테스트 치과',
    product_name: '임플란트 / 스케일링',
    industry: '치과',
    inquiry_channels: '전화 02-1234-5678 / 카카오톡 채널',
    service_types: '[1]',
    address1: '서울시 강남구',
    address2: '테헤란로 123',
    address_zip: '06234',
    goal: 2,
    ages: '[3,4]',
    product_strengths: '[2,5]',
    tones: '[2]',
    postLengthModeRaw: 'medium',
    keep_style: 1,
    style_url: '',
    content_styles: '[1,2]',
    extra_strength: '20년 경력 원장 직접 진료',
    action_style: 2,
    expressions: '[1]',
    forbidden_phrases: '최고, 1등, 완벽',
    is_active: 1,
    timer: null,
    posting_mode: 'mixed',       // 'intensive' | 'mixed'
    posting_mode_next: null,     // 다음 주 전환 예정 모드
    mode_switch_week: null,      // 전환 적용 주 (ISO 'YYYY-WNN')
  },
  {
    id: 202,
    member_pk: 2,
    brand_name: '강남 치과의원',
    product_name: '교정',
    industry: '치과',
    inquiry_channels: '전화 02-9876-5432',
    service_types: '[1]',
    address1: '서울시 강남구',
    address2: '역삼로 456',
    address_zip: '06212',
    goal: 3,
    ages: '[2,3]',
    product_strengths: '[1,3]',
    tones: '[1]',
    postLengthModeRaw: 'long',
    keep_style: 1,
    style_url: '',
    content_styles: '[3,4]',
    extra_strength: '투명교정 전문',
    action_style: 3,
    expressions: '[2]',
    forbidden_phrases: '',
    is_active: 1,
    timer: null,
    posting_mode: 'intensive',
    posting_mode_next: null,
    mode_switch_week: null,
  },
];

// ── 인증 헬퍼 ────────────────────────────────────────────────────
// Electron/Flutter: Authorization: Bearer <api_token>
function getMemberByToken(req) {
  const auth = req.headers['authorization'] || '';
  const token = auth.replace(/^Bearer\s+/i, '').trim();
  if (!token) return null;
  return members.find(m => m.api_token === token) || null;
}

// 관리자 여부 (실서버: id=10)
function isAdmin(member) {
  return member && member.id === 10;
}

// 유료 여부 (tier=1 또는 관리자)
function isPaid(member) {
  return member && (member.tier === 1 || isAdmin(member));
}

// 유료 전용 미들웨어 — 무료 유저는 403
function requirePaid(req, res, next) {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });
  next();
}

// ── 로깅 미들웨어 ─────────────────────────────────────────────────
app.use((req, res, next) => {
  const token = (req.headers['authorization'] || '').replace(/^Bearer\s+/i, '').substring(0, 20);
  console.log(`[${new Date().toISOString().slice(11, 19)}] ${req.method} ${req.path} | token:${token || '-'}`);
  next();
});

// ════════════════════════════════════════════════════════════════
// POST /member/login → { ok, member_pk, member_id, api_token }
// Electron 설정창 / Flutter 설정화면에서 최초 로그인 시 사용
// ════════════════════════════════════════════════════════════════

app.post('/member/login', (req, res) => {
  const { member_id, passwd } = req.body;
  const member = members.find(m => m.member_id === member_id && m.passwd === passwd);
  if (!member) {
    return res.status(401).json({ ok: false, error: '아이디 또는 비밀번호가 올바르지 않습니다.' });
  }
  res.json({
    ok: true,
    member_pk: member.id,
    member_id: member.member_id,
    company_name: member.company_name,
    api_token: member.api_token,
    tier: member.tier ?? 0,
    has_workflows: !!(member.n8n_workflow_ids),
  });
});

// ════════════════════════════════════════════════════════════════
// GET /member/me — 내 정보 + 플랜/워크플로우 현황
// ════════════════════════════════════════════════════════════════

app.get('/member/me', (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });

  res.json({
    ok: true,
    member_pk: member.id,
    member_id: member.member_id,
    company_name: member.company_name,
    tier: member.tier ?? 0,
    has_workflows: !!(member.n8n_workflow_ids),
    n8n_workflow_ids: member.n8n_workflow_ids || null,
  });
});

// ════════════════════════════════════════════════════════════════
// POST /member/provision — (관리자) 유료 고객 n8n 워크플로우 복제·활성화
// Body: { member_pk }
// N8N_URL + N8N_API_KEY 환경변수 없으면 mock ID로 대체
// ════════════════════════════════════════════════════════════════

const WORKFLOW_TYPES = ['info', 'mixed', 'case'];

app.post('/member/provision', async (req, res) => {
  const member = getMemberByToken(req);
  if (!member || !isAdmin(member)) {
    return res.status(403).json({ ok: false, error: '관리자 권한이 필요합니다.' });
  }

  const target = members.find(m => m.id === parseInt(req.body.member_pk, 10));
  if (!target) return res.status(404).json({ ok: false, error: '회원을 찾을 수 없습니다.' });

  const N8N_URL     = n8nCfg.N8N_URL;
  const N8N_API_KEY = n8nCfg.N8N_API_KEY;
  const TEMPLATE_IDS = n8nCfg.TEMPLATE_IDS;

  const workflow_ids = {};

  if (N8N_URL && N8N_API_KEY && !N8N_URL.includes('YOUR_N8N')) {
    for (const type of WORKFLOW_TYPES) {
      try {
        const templateId = TEMPLATE_IDS[type];
        if (!templateId) { workflow_ids[type] = `no-template-${type}`; continue; }

        const tRes = await fetch(`${N8N_URL}/api/v1/workflows/${templateId}`, {
          headers: { 'X-N8N-API-KEY': N8N_API_KEY },
        });
        const template = await tRes.json();

        const cRes = await fetch(`${N8N_URL}/api/v1/workflows`, {
          method: 'POST',
          headers: { 'X-N8N-API-KEY': N8N_API_KEY, 'Content-Type': 'application/json' },
          body: JSON.stringify({
            name: `[${target.id}] ${target.company_name} - ${type}`,
            nodes: template.nodes,
            connections: template.connections,
            settings: template.settings,
          }),
        });
        const created = await cRes.json();
        workflow_ids[type] = created.id;

        await fetch(`${N8N_URL}/api/v1/workflows/${created.id}/activate`, {
          method: 'POST',
          headers: { 'X-N8N-API-KEY': N8N_API_KEY },
        });
      } catch (e) {
        console.error(`[provision] ${type} 실패:`, e.message);
        workflow_ids[type] = `err-${type}`;
      }
    }
  } else {
    // N8N 환경변수 미설정 → mock ID
    for (const type of WORKFLOW_TYPES) {
      workflow_ids[type] = `mock-${target.id}-${type}-${Date.now()}`;
    }
    console.log('[provision] N8N 환경변수 없음 — mock ID 사용');
  }

  target.tier = 1;
  target.n8n_workflow_ids = workflow_ids;

  messages.push({
    id: nextMsgId++,
    member_pk: target.id,
    type: 'workflow.provisioned',
    is_system: true,
    text: `🎉 유료 플랜으로 업그레이드됐습니다!\n\n${target.company_name}님의 맞춤 AI 워크플로우 ${WORKFLOW_TYPES.length}개가 활성화됐어요.\n\n이제 채팅으로 포스팅 톤·주제·길이 등을 자유롭게 조정할 수 있습니다. 무엇이든 말씀해 주세요!`,
    post_id: null, post_title: null, post_html: null,
    meta: { workflow_ids },
    actions: [],
    read: false,
    created_at: new Date().toISOString(),
  });
  saveDb();

  console.log(`[provision] member=${target.id} (${target.company_name}) — 워크플로우 ${WORKFLOW_TYPES.length}개 생성`);
  res.json({ ok: true, workflow_ids, message: '프로비저닝 완료' });
});

// ════════════════════════════════════════════════════════════════
// GET /api/posts?status=ready&member_pk=X
// 발행 대기 목록: status=1(승인) AND posting_date IS NULL
// Electron tray: 60초마다 폴링 / Flutter: 목록 화면
// ════════════════════════════════════════════════════════════════

app.get('/api/posts', requirePaid, (req, res) => {
  const member = getMemberByToken(req);

  const memberPk = parseInt(req.query.member_pk || '0', 10);
  const status = req.query.status || '';

  // member_pk 검증: 관리자가 아니면 본인 포스트만
  if (!isAdmin(member) && memberPk !== member.id) {
    return res.status(403).json({ ok: false, error: '권한이 없습니다.' });
  }

  let result = posts.filter(p => {
    if (!isAdmin(member) && p.customer_id !== memberPk) return false;
    if (status === 'ready') return p.status === 1 && p.posting_date === null;
    if (status === 'published') return p.posting_date !== null;
    if (status === 'pending') return p.status === 0;
    return true; // status 없으면 전체
  });

  res.json(result.map(p => ({
    id: p.id,
    title: p.title,
    html: p.naver_html, // Electron/Flutter가 주입하는 HTML = naver_html
    tags: p.tags || [],
    status: p.status,
    posting_date: p.posting_date,
    created_at: p.created_at,
  })));
});

// ════════════════════════════════════════════════════════════════
// POST /api/posts (= 실서버 /api/index.php)
// n8n이 AI 생성 포스트를 저장할 때 사용
// Body: { title, html, naverHtml, customer_id, prompt_id, promptNodeId, subject?, intro? }
// ════════════════════════════════════════════════════════════════

app.post('/api/posts', requirePaid, (req, res) => {
  const { title, html, naverHtml, customer_id, prompt_id, promptNodeId, subject, intro } = req.body;

  if (!title || !naverHtml || !customer_id || !promptNodeId) {
    return res.status(422).json({
      ok: false,
      error: 'title, naverHtml, customer_id, promptNodeId are required',
    });
  }

  const post = {
    id: nextPostId++,
    customer_id: parseInt(customer_id, 10),
    prompt_id: parseInt(prompt_id || '0', 10),
    prompt_node_id: promptNodeId,
    title,
    subject: subject || null,
    intro: intro || null,
    html: html || '',
    naver_html: naverHtml,
    status: 0, // 0=대기(관리자 승인 필요)
    posting_date: null,
    created_at: new Date().toISOString(),
  };

  posts.push(post);
  saveDb();
  console.log(` [new post] id=${post.id} customer=${customer_id} title="${title}"`);

  res.json({
    ok: true,
    message: 'Inserted successfully',
    insert_id: post.id,
  });
});

// ════════════════════════════════════════════════════════════════
// POST /api/posts/:id/published
// Electron tray / Flutter: 발행 완료 시 호출
// 실서버: posting_date = NOW() 설정 (output_publish_guard.php의 mark_posting 동일)
// ════════════════════════════════════════════════════════════════

app.post('/api/posts/:id/published', requirePaid, (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });

  const postId = parseInt(req.params.id, 10);
  const post = posts.find(p => p.id === postId);
  if (!post) return res.status(404).json({ ok: false, error: '포스트를 찾을 수 없습니다.' });

  // 관리자가 아니면 본인 포스트만
  if (!isAdmin(member) && post.customer_id !== member.id) {
    return res.status(403).json({ ok: false, error: '권한이 없습니다.' });
  }

  post.posting_date = new Date().toISOString();
  saveDb();
  console.log(` [published] id=${postId} title="${post.title}" at ${post.posting_date}`);

  res.json({ ok: true, posting_date: post.posting_date });
});

// ════════════════════════════════════════════════════════════════
// POST /api/posts/:id/failed
// Electron tray / Flutter: 발행 실패 시 호출 (재시도 가능하도록 posting_date 유지)
// ════════════════════════════════════════════════════════════════

app.post('/api/posts/:id/failed', requirePaid, (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });

  const postId = parseInt(req.params.id, 10);
  const post = posts.find(p => p.id === postId);
  if (!post) return res.status(404).json({ ok: false, error: '포스트를 찾을 수 없습니다.' });

  if (!isAdmin(member) && post.customer_id !== member.id) {
    return res.status(403).json({ ok: false, error: '권한이 없습니다.' });
  }

  const reason = req.body.reason || '알 수 없는 오류';

  // 실패: posting_date를 null로 유지 → 재시도 가능
  console.log(` [failed] id=${postId} reason="${reason}"`);

  res.json({ ok: true, message: '실패 기록 완료 (재시도 가능)' });
});

// ════════════════════════════════════════════════════════════════
// GET /api/post_meta?id=X (= 실서버 /api/post_meta/index.php?id=X)
// n8n이 고객 프롬프트 메타데이터를 가져올 때 사용
// ════════════════════════════════════════════════════════════════

app.get('/api/post_meta', (req, res) => {
  const id = parseInt(req.query.id || '0', 10);
  if (id <= 0) return res.json({ ok: false, error: 'ID가 필요합니다.' });

  const prompt = prompts.find(p => p.id === id);
  if (!prompt) return res.json({ ok: false, error: '데이터를 찾을 수 없습니다.' });

  const mapServiceTypes = { 1: '오프라인 매장', 2: '온라인 서비스', 3: '전국 서비스', 4: '프랜차이즈', 5: '기타' };
  const mapAges = { 1: '10대', 2: '20대', 3: '30대', 4: '40대', 5: '50대' };
  const mapStrengths = {
    1: '가격이 합리적이다.', 2: '결과·성과가 명확하다.', 3: '전문 인력이 직접 제공한다.',
    4: '처리 속도가 빠르다.', 5: '경험·사례가 많다.', 6: '접근성이 좋다.',
    7: '사후 관리가 잘 된다.', 8: '공식 인증·자격을 보유하고 있다.', 9: '기술력이 높다.', 10: '기타',
  };
  const mapTones = {
    1: '차분하게 설명한다.', 2: '친절하게 쉽게 설명한다.',
    3: '단호하고 확신 있게 말한다.', 4: '전문가가 조언하는 느낌.',
  };
  const mapGoal = {
    1: '매출을 늘리고 싶다', 2: '예약·방문을 늘리고 싶다.', 3: '문의·상담을 늘리고 싶다.',
    4: '브랜드를 알리고 싶다.', 5: '신뢰를 확보하고 싶다.', 6: '기타',
  };
  const mapKeepStyle = { 1: '유지한다.', 2: '유지하지 않는다.' };
  const mapContentStyles = { 1: '짧은 문장 위주', 2: '핵심 요약', 3: '질문으로 마무리', 4: '숫자·근거 강조' };
  const mapActionStyle = {
    1: '정보만 제공하고 판단은 맡긴다.',
    2: '관심이 생기도록 자연스럽게 유도한다.',
    3: '지금 바로 행동하도록 안내한다.',
  };
  const mapExpression = { 1: '과장된 표현', 2: '가격·할인 언급', 3: '타사 비교·비방 표현', 4: '기타' };

  const join = (jsonStr, map) => {
    try {
      const arr = JSON.parse(jsonStr || '[]');
      return arr.map(n => map[n]).filter(Boolean).join(' / ');
    } catch { return ''; }
  };

  const exprArr = (() => { try { return JSON.parse(prompt.expressions || '[]'); } catch { return []; } })();

  res.json({
    ok: true,
    id: prompt.id,
    member_pk: prompt.member_pk,
    brand_name: prompt.brand_name,
    product_name: prompt.product_name,
    industry: prompt.industry,
    inquiry_channels: prompt.inquiry_channels,
    service_types: join(prompt.service_types, mapServiceTypes),
    address: `${prompt.address1} ${prompt.address2}`.trim(),
    address_zip: prompt.address_zip,
    goal: mapGoal[prompt.goal] || '',
    ages: join(prompt.ages, mapAges),
    product_strengths: join(prompt.product_strengths, mapStrengths),
    tones: join(prompt.tones, mapTones),
    postLengthModeRaw: prompt.postLengthModeRaw,
    keep_style: mapKeepStyle[prompt.keep_style] || '',
    style_url: prompt.style_url,
    content_styles: join(prompt.content_styles, mapContentStyles),
    extra_strength: prompt.extra_strength,
    action_style: mapActionStyle[prompt.action_style] || '',
    expression: mapExpression[exprArr[0]] || '',
    forbidden_phrases: prompt.forbidden_phrases,
    files: [],
  });
});

// ════════════════════════════════════════════════════════════════
// GET /api/posts/:id — 단일 포스트 조회 (brain-agent 검토 UI용)
// ════════════════════════════════════════════════════════════════

app.get('/api/posts/:id', requirePaid, (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });

  const postId = parseInt(req.params.id, 10);
  const post = posts.find(p => p.id === postId);
  if (!post) return res.status(404).json({ ok: false, error: '포스트를 찾을 수 없습니다.' });

  if (!isAdmin(member) && post.customer_id !== member.id) {
    return res.status(403).json({ ok: false, error: '권한이 없습니다.' });
  }

  res.json({
    id: post.id,
    customer_id: post.customer_id,
    title: post.title,
    subject: post.subject,
    intro: post.intro,
    html: post.html,
    naver_html: post.naver_html,
    status: post.status,
    posting_date: post.posting_date,
    created_at: post.created_at,
  });
});

// ════════════════════════════════════════════════════════════════
// PATCH /api/posts/:id — 포스트 내용 수정 (brain-agent 대화형 수정 후 저장)
// Body: { title?, html? }
// ════════════════════════════════════════════════════════════════

app.patch('/api/posts/:id', (req, res) => {
  const member = getMemberByToken(req);
  if (!member || !isAdmin(member)) {
    return res.status(403).json({ ok: false, error: '관리자 권한이 필요합니다.' });
  }

  const postId = parseInt(req.params.id, 10);
  const post = posts.find(p => p.id === postId);
  if (!post) return res.status(404).json({ ok: false, error: '포스트를 찾을 수 없습니다.' });

  if (req.body.title !== undefined) post.title = req.body.title;
  if (req.body.html !== undefined) {
    post.html = req.body.html;
    post.naver_html = req.body.html;
  }
  if (req.body.tags !== undefined) post.tags = req.body.tags;

  saveDb();
  console.log(` [updated] id=${postId} title="${post.title}"`);
  res.json({ ok: true, message: '수정 완료' });
});

// ════════════════════════════════════════════════════════════════
// POST /api/posts/:id/reject — 포스트 반려 (status 0으로 되돌리기)
// brain-agent 검토 UI에서 반려 시 사용
// ════════════════════════════════════════════════════════════════

app.post('/api/posts/:id/reject', (req, res) => {
  const member = getMemberByToken(req);
  if (!member || !isAdmin(member)) {
    return res.status(403).json({ ok: false, error: '관리자 권한이 필요합니다.' });
  }

  const postId = parseInt(req.params.id, 10);
  const post = posts.find(p => p.id === postId);
  if (!post) return res.status(404).json({ ok: false, error: '포스트를 찾을 수 없습니다.' });

  const reason = req.body.reason || '';
  post.status = 0;

  saveDb();
  console.log(` [rejected] id=${postId} reason="${reason}"`);
  res.json({ ok: true, message: '반려 완료' });
});

// ════════════════════════════════════════════════════════════════
// GET /api/messages?member_pk=X&after_id=Y — 채팅 메시지 목록
// Flutter ChatScreen이 15초마다 폴링
// ════════════════════════════════════════════════════════════════

app.get('/api/messages', requirePaid, (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });

  const memberPk = parseInt(req.query.member_pk || '0', 10);
  if (!isAdmin(member) && memberPk !== member.id) {
    return res.status(403).json({ ok: false, error: '권한이 없습니다.' });
  }

  const afterId = parseInt(req.query.after_id || '0', 10);

  let result = messages.filter(m => {
    if (!isAdmin(member) && m.member_pk !== memberPk) return false;
    if (afterId > 0 && m.id <= afterId) return false;
    return true;
  });

  res.json(result.map(m => ({
    id: m.id,
    type: m.type,
    is_system: m.is_system,
    text: m.text,
    post_id: m.post_id,
    post_title: m.post_title,
    post_html: m.post_html,
    meta: m.meta,
    actions: m.actions,
    read: m.read,
    created_at: m.created_at,
  })));
});

// ─── 워크플로우 커스터마이징 인텐트 감지 ─────────────────────────
function detectWorkflowIntent(text) {
  const t = text;
  // 포스팅 모드 변경 (우선 체크)
  if (/정보성.*모드|집중.*모드|정보성.*많이|많이.*정보성|3개.*포스팅|포스팅.*3개|intensive/.test(t)) return 'mode_intensive';
  if (/믹스.*모드|혼합.*모드|균형.*모드|섞어.*쓰|mixed|순환.*모드/.test(t))                        return 'mode_mixed';
  if (/사례형.*모드|사례형.*집중|사례.*위주|case.*모드|케이스.*모드/.test(t))                       return 'mode_case';
  if (/포스팅.*모드|모드.*바꿔|모드.*변경|발행.*방식.*바꿔/.test(t))                               return 'mode_ask';
  // 콘텐츠 커스터마이징
  if (/톤|분위기|어조|친근|격식|전문적|말투|글체|문체/.test(t)) return 'tone';
  if (/길이|짧게|길게|간결|상세|분량|글자/.test(t))             return 'length';
  if (/주제|키워드|토픽|다뤄|써줘|다루/.test(t))                return 'topic';
  if (/빈도|자주|횟수|얼마나|주에|한달/.test(t))               return 'frequency';
  if (/금지|쓰지마|빼줘|사용하지|쓰면안/.test(t))              return 'forbidden';
  if (/바꿔|변경|수정|조정|설정/.test(t))                       return 'general';
  return null;
}

function workflowUpdateReply(intent, text) {
  const intentLabel = {
    tone:      '글 톤/분위기',
    length:    '포스팅 길이',
    topic:     '주제/키워드',
    frequency: '발행 빈도',
    forbidden: '금지어 설정',
    general:   '워크플로우 설정',
  }[intent] || '설정';

  return `네, 반영했습니다!\n\n${intentLabel}를 요청하신 대로 업데이트했어요.\n"${text.substring(0, 40)}"\n\n다음 포스팅부터 적용됩니다. 더 조정할 부분이 있으면 언제든 말씀해 주세요!`;
}

// ════════════════════════════════════════════════════════════════
// POST /api/messages — 고객이 직접 텍스트 입력 시
// 유료 고객 + 워크플로우 인텐트 감지 → workflow.updated 응답
// 무료 고객 + 인텐트 → 업그레이드 안내
// ════════════════════════════════════════════════════════════════

app.post('/api/messages', requirePaid, (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });

  const { member_pk, text } = req.body;
  if (!text?.trim()) return res.status(422).json({ ok: false, error: 'text is required' });

  const pk = parseInt(member_pk || '0', 10) || member.id;

  messages.push({
    id: nextMsgId++,
    member_pk: pk,
    type: 'user_text',
    is_system: false,
    text: text.trim(),
    post_id: null, post_title: null, post_html: null,
    meta: null, actions: [], read: true,
    created_at: new Date().toISOString(),
  });

  const intent = detectWorkflowIntent(text.trim());
  let replyType, replyText, replyMeta = null;

  if ((intent === 'mode_intensive' || intent === 'mode_mixed' || intent === 'mode_case') && member.tier === 1) {
    const modeKey = intent === 'mode_intensive' ? 'intensive' : intent === 'mode_mixed' ? 'mixed' : 'case';
    const prompt  = prompts.find(p => p.member_pk === member.id);
    const modeInfo = POSTING_MODES[modeKey];

    if (prompt?.posting_mode === modeKey && !prompt?.posting_mode_next) {
      replyType = 'user_text';
      replyText = `이미 [${modeInfo.label}] 모드로 운영 중이에요!\n\n• ${modeInfo.desc}`;
    } else {
      const switchWk = nextISOWeek();
      if (prompt) { prompt.posting_mode_next = modeKey; prompt.mode_switch_week = switchWk; }
      replyType = 'mode.changed';
      replyText = `✅ 다음 주(${switchWk})부터 [${modeInfo.label}] 모드로 전환됩니다!\n\n• ${modeInfo.desc}`;
      replyMeta = { mode: modeKey, switch_week: switchWk };
      saveDb();
    }
  } else if (intent === 'mode_ask' && member.tier === 1) {
    const prompt = prompts.find(p => p.member_pk === member.id);
    const cur = prompt?.posting_mode ?? 'mixed';
    const mi = POSTING_MODES[cur];
    replyType = 'user_text';
    replyText = `현재 [${mi?.label}] 모드로 운영 중이에요.\n\n변경 가능한 모드:\n• 정보성 — info+promo+plusA 평일 3개 (주 15개)\n• 믹스 — 정보성 1개/일 순환 + 사례형 주 3회\n• 사례형 — 사례형 주 5회만\n\n"정보성 모드로 바꿔줘", "믹스 모드로 바꿔줘", "사례형 모드로 바꿔줘"라고 말씀해 주세요!`;
  } else if (intent && member.tier === 1) {
    // 유료 고객 + 워크플로우 커스터마이징 요청
    // 실서버: 여기서 n8n API 호출해 워크플로우 파라미터 업데이트
    replyType = 'workflow.updated';
    replyText = workflowUpdateReply(intent, text.trim());
    replyMeta = { intent };
  } else if (intent && member.tier === 0) {
    // 무료 고객 + 커스터마이징 시도 → 업그레이드 안내
    replyType = 'user_text';
    replyText = '좋은 아이디어예요!\n\n워크플로우 커스터마이징은 유료 플랜 전용 기능입니다.\n유료 플랜으로 업그레이드하시면 포스팅 톤·주제·길이 등을 자유롭게 조정할 수 있어요.';
  } else {
    // 일반 문의
    replyType = 'user_text';
    replyText = `말씀 주신 내용 확인했습니다!\n"${text.trim().substring(0, 30)}" 요청을 반영해 다음 포스팅에 적용하겠습니다.`;
  }

  messages.push({
    id: nextMsgId++,
    member_pk: pk,
    type: replyType,
    is_system: true,
    text: replyText,
    post_id: null, post_title: null, post_html: null,
    meta: replyMeta, actions: [], read: false,
    created_at: new Date(Date.now() + 1000).toISOString(),
  });

  saveDb();
  console.log(` [msg] member=${pk} intent=${intent || '-'} text="${text.trim().substring(0, 40)}"`);

  res.status(201).json({ ok: true });
});

// ════════════════════════════════════════════════════════════════
// POST /api/messages/:id/action — 버튼 액션 (view_post, publish_post 등)
// ════════════════════════════════════════════════════════════════

app.post('/api/messages/:id/action', (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });

  const msgId = parseInt(req.params.id, 10);
  const msg = messages.find(m => m.id === msgId);
  if (!msg) return res.status(404).json({ ok: false, error: '메시지를 찾을 수 없습니다.' });

  const { action_key } = req.body;

  console.log(` [action] msg=${msgId} action=${action_key}`);

  msg.read = true;
  saveDb();

  res.json({ ok: true });
});

// ════════════════════════════════════════════════════════════════
// POST /api/messages/:id/read — 읽음 처리
// ════════════════════════════════════════════════════════════════

app.post('/api/messages/:id/read', (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });

  const msgId = parseInt(req.params.id, 10);
  const msg = messages.find(m => m.id === msgId);
  if (msg) {
    msg.read = true;
    saveDb();
  }

  res.json({ ok: true });
});

// ════════════════════════════════════════════════════════════════
// 포스팅 모드 시스템 (3가지)
//
// intensive : info+promo+plusA 매일 월~금 → 자동 15개/주
//             사례형(case)은 포함 안 됨 — 사례형은 별도 수동 제출
// mixed     : promo→info→plusA 순환 평일 1개/일 → 자동 5개/주
//             사례형 주 3회 수동 제출 가능
// case      : 자동 발행 없음, 사례형 주 5회 수동 제출만
//
// 사례형은 항상 고객이 직접 내용+이미지 입력 → case 워크플로우 실행 → 산출물 관리
// 모드 변경 → 다음 주 월요일부터 적용 (mode_switch_week에 예약 저장)
// caify_publish_queue 테이블: 매주 월요일 00시에 해당 주 row 생성
// Queue Worker(n8n)가 5분마다 publish_date=오늘인 row 픽업 → 서브워크플로우 실행
// ════════════════════════════════════════════════════════════════

const POSTING_MODES = {
  intensive: {
    label: '정보성',
    desc:  'info+promo+plusA 평일 매일 3개 (주 15개)',
    slots: [
      { type: 'info',  days: [1,2,3,4,5] },
      { type: 'promo', days: [1,2,3,4,5] },
      { type: 'plusA', days: [1,2,3,4,5] },
    ],
    casePerWeek: 0,
  },
  mixed: {
    label: '믹스',
    desc:  '정보성 1개/일 순환 (주 5개) + 사례형 주 3회',
    // 월=promo, 화=info, 수=plusA, 목=promo, 금=info 순환
    slots: [
      { type: 'promo', days: [1,4] },
      { type: 'info',  days: [2,5] },
      { type: 'plusA', days: [3]   },
    ],
    casePerWeek: 3,
  },
  case: {
    label: '사례형',
    desc:  '사례형 주 5회 (자동 발행 없음)',
    slots: [],
    casePerWeek: 5,
  },
};

// ISO 주 문자열 (예: '2026-W18')
function getISOWeek(date = new Date()) {
  const d = new Date(date);
  d.setHours(0, 0, 0, 0);
  d.setDate(d.getDate() + 3 - (d.getDay() + 6) % 7);
  const w1 = new Date(d.getFullYear(), 0, 4);
  const wn = 1 + Math.round(((d - w1) / 864e5 - 3 + (w1.getDay() + 6) % 7) / 7);
  return `${d.getFullYear()}-W${String(wn).padStart(2, '0')}`;
}

// 해당 ISO 주의 월요일 Date 반환
function mondayOfISOWeek(isoWeek) {
  const [year, w] = isoWeek.split('-W').map(Number);
  const jan4 = new Date(year, 0, 4);
  const monday = new Date(jan4);
  monday.setDate(jan4.getDate() - (jan4.getDay() + 6) % 7 + (w - 1) * 7);
  monday.setHours(0, 0, 0, 0);
  return monday;
}

// 다음 주 ISO 주 문자열
function nextISOWeek() {
  const d = new Date();
  d.setDate(d.getDate() + 7);
  return getISOWeek(d);
}

// 주어진 주의 큐 row 생성 (caify_publish_queue 삽입용)
function generateWeeklyQueue(memberPk, mode, isoWeek) {
  const slots = POSTING_MODES[mode]?.slots ?? POSTING_MODES.mixed.slots;
  const weekStart = mondayOfISOWeek(isoWeek);
  const rows = [];

  for (const slot of slots) {
    for (const dayOfWeek of slot.days) {
      // dayOfWeek: 1=월, 2=화, ..., 5=금
      const d = new Date(weekStart);
      d.setDate(d.getDate() + (dayOfWeek - 1));
      rows.push({
        id:            nextQueueId++,
        member_pk:     memberPk,
        publish_date:  d.toISOString().split('T')[0],
        publish_slot:  slot.type,
        workflow_type: slot.type,
        status:        'start',
        lock_id:       null,
        locked_at:     null,
        completed_at:  null,
        fail_reason:   null,
        created_at:    new Date().toISOString(),
        updated_at:    new Date().toISOString(),
      });
    }
  }
  return rows;
}

// 주 전환 시 대기 중인 모드 변경 적용 + 큐 생성
function applyPendingModeChanges() {
  const currentWeek = getISOWeek();
  let changed = 0;

  for (const p of prompts) {
    if (p.posting_mode_next && p.mode_switch_week === currentWeek) {
      p.posting_mode = p.posting_mode_next;
      p.posting_mode_next = null;
      p.mode_switch_week = null;
      const rows = generateWeeklyQueue(p.member_pk, p.posting_mode, currentWeek);
      publishQueue.push(...rows);
      console.log(`[mode] member=${p.member_pk} → ${p.posting_mode} 전환, 큐 ${rows.length}개 생성`);
      changed++;
    }
  }
  if (changed > 0) saveDb();
  return changed;
}

// 매주 월요일 00:10에 applyPendingModeChanges 자동 실행
function scheduleWeeklyModeApply() {
  function msUntilNextMonday() {
    const now = new Date();
    const next = new Date(now);
    next.setDate(now.getDate() + ((8 - now.getDay()) % 7 || 7));
    next.setHours(0, 10, 0, 0);
    return next - now;
  }
  setTimeout(function run() {
    applyPendingModeChanges();
    setTimeout(run, 7 * 24 * 60 * 60 * 1000);
  }, msUntilNextMonday());
}
scheduleWeeklyModeApply();

// ── GET /api/posting-mode ─────────────────────────────────────

app.get('/api/posting-mode', requirePaid, (req, res) => {
  const member = getMemberByToken(req);
  const prompt = prompts.find(p => p.member_pk === member.id);

  const mode     = prompt?.posting_mode      ?? 'mixed';
  const modeNext = prompt?.posting_mode_next ?? null;
  const switchWk = prompt?.mode_switch_week  ?? null;

  // 이번 주 + 다음 주 큐 통계
  const thisWeek = getISOWeek();
  const nxtWeek  = nextISOWeek();
  const countQueue = (week) =>
    publishQueue.filter(q => q.member_pk === member.id &&
      getISOWeek(new Date(q.publish_date)) === week).length;

  res.json({
    ok: true,
    posting_mode:      mode,
    posting_mode_next: modeNext,
    mode_switch_week:  switchWk,
    mode_label:        POSTING_MODES[mode]?.label,
    mode_next_label:   modeNext ? POSTING_MODES[modeNext]?.label : null,
    this_week_count:   countQueue(thisWeek),
    next_week_count:   modeNext ? POSTING_MODES[modeNext]?.slots.reduce((s, sl) => s + sl.days.length, 0) : null,
    modes: Object.fromEntries(Object.entries(POSTING_MODES).map(([k, v]) => [k, v.label])),
  });
});

// ── POST /api/posting-mode ────────────────────────────────────
// 모드 변경 요청 — 다음 주부터 적용

app.post('/api/posting-mode', requirePaid, (req, res) => {
  const member = getMemberByToken(req);
  const { mode } = req.body;

  if (!POSTING_MODES[mode]) {
    return res.status(422).json({ ok: false, error: '유효하지 않은 모드입니다. (intensive | mixed | case)' });
  }

  const prompt = prompts.find(p => p.member_pk === member.id);
  if (!prompt) return res.status(404).json({ ok: false, error: '고객 설정을 찾을 수 없습니다.' });

  if (prompt.posting_mode === mode && !prompt.posting_mode_next) {
    return res.json({ ok: true, message: '이미 해당 모드입니다.', posting_mode: mode });
  }

  const switchWk = nextISOWeek();
  prompt.posting_mode_next = mode;
  prompt.mode_switch_week  = switchWk;

  const modeInfo  = POSTING_MODES[mode];
  const modeLabel = modeInfo.label;
  messages.push({
    id:         nextMsgId++,
    member_pk:  member.id,
    type:       'mode.changed',
    is_system:  true,
    text:       `✅ 포스팅 모드 변경이 예약됐습니다!\n\n다음 주(${switchWk})부터 [${modeLabel}]로 전환됩니다.\n\n• ${modeInfo.desc}`,
    post_id: null, post_title: null, post_html: null,
    meta:    { mode, switch_week: switchWk },
    actions: [],
    read:    false,
    created_at: new Date().toISOString(),
  });

  saveDb();
  console.log(`[mode] member=${member.id} 예약: ${prompt.posting_mode} → ${mode} (${switchWk})`);

  res.json({
    ok:                true,
    posting_mode:      prompt.posting_mode,
    posting_mode_next: mode,
    mode_switch_week:  switchWk,
  });
});

// ── GET /api/posting-queue (디버그/관리용) ─────────────────────

app.get('/api/posting-queue', requirePaid, (req, res) => {
  const member = getMemberByToken(req);
  const week   = req.query.week || getISOWeek();

  const rows = publishQueue.filter(q =>
    q.member_pk === member.id &&
    getISOWeek(new Date(q.publish_date)) === week
  ).sort((a, b) => a.publish_date.localeCompare(b.publish_date));

  res.json({ ok: true, week, rows, total: rows.length });
});

// ── POST /admin/generate-queue (관리자: 큐 수동 생성) ──────────

app.post('/admin/generate-queue', (req, res) => {
  const member = getMemberByToken(req);
  if (!member || !isAdmin(member)) {
    return res.status(403).json({ ok: false, error: '관리자 권한이 필요합니다.' });
  }

  const { member_pk, week } = req.body;
  const targetWeek = week || nextISOWeek();
  const targets = member_pk
    ? prompts.filter(p => p.member_pk === parseInt(member_pk, 10))
    : prompts;

  let total = 0;
  for (const p of targets) {
    // 기존 해당 주 row 삭제 후 재생성
    const before = publishQueue.length;
    for (let i = publishQueue.length - 1; i >= 0; i--) {
      if (publishQueue[i].member_pk === p.member_pk &&
          getISOWeek(new Date(publishQueue[i].publish_date)) === targetWeek) {
        publishQueue.splice(i, 1);
      }
    }
    const rows = generateWeeklyQueue(p.member_pk, p.posting_mode, targetWeek);
    publishQueue.push(...rows);
    total += rows.length;
    console.log(`[queue] member=${p.member_pk} ${targetWeek} → ${rows.length}개 생성`);
  }

  saveDb();
  res.json({ ok: true, week: targetWeek, total });
});

// ════════════════════════════════════════════════════════════════
// 관리자 전용 API
// GET /admin/posts → 전체 포스트 목록
// POST /admin/posts/:id/approve → 포스트 승인 (status 0→1)
// POST /api/posts/:id/approve (별칭)
// ════════════════════════════════════════════════════════════════

app.get('/admin/posts', (req, res) => {
  const member = getMemberByToken(req);
  if (!member || !isAdmin(member)) {
    return res.status(403).json({ ok: false, error: '관리자 권한이 필요합니다.' });
  }

  const memberPk = req.query.member_pk ? parseInt(req.query.member_pk, 10) : null;
  let result = memberPk ? posts.filter(p => p.customer_id === memberPk) : [...posts];

  res.json(result.map(p => ({
    id: p.id,
    customer_id: p.customer_id,
    title: p.title,
    status: p.status,
    posting_date: p.posting_date,
    created_at: p.created_at,
  })));
});

app.post('/admin/posts/:id/approve', (req, res) => {
  const member = getMemberByToken(req);
  if (!member || !isAdmin(member)) {
    return res.status(403).json({ ok: false, error: '관리자 권한이 필요합니다.' });
  }

  const postId = parseInt(req.params.id, 10);
  const post = posts.find(p => p.id === postId);
  if (!post) return res.status(404).json({ ok: false, error: '포스트를 찾을 수 없습니다.' });

  post.status = 1;

  saveDb();
  console.log(` [approved] id=${postId} title="${post.title}"`);

  res.json({ ok: true, message: '승인 완료' });
});

// ════════════════════════════════════════════════════════════════
// GET /api/version — 앱 버전 및 APK 다운로드 URL
// Flutter 앱이 시작 시 체크. 현재 버전보다 높으면 업데이트 다이얼로그 표시.
// ════════════════════════════════════════════════════════════════

app.get('/api/version', async (req, res) => {
  try {
    const response = await fetch(
      'https://api.github.com/repos/caifyhelp-cmyk/caify-product/releases/latest',
      { headers: { 'User-Agent': 'caify-mock-server' } }
    );
    const release = await response.json();
    const tag = release.tag_name || 'v1.0.0';
    const version = tag.replace(/^v/, '');
    const apk = (release.assets || []).find(a => a.name.endsWith('.apk'));
    const apk_url = apk
      ? apk.browser_download_url
      : `https://github.com/caifyhelp-cmyk/caify-product/releases/download/${tag}/caify_${version}.apk`;

    res.json({ version, apk_url, notes: release.body ? release.body.split('\n')[0] : '', force: false });
  } catch (e) {
    res.json({ version: '1.0.1', apk_url: 'https://github.com/caifyhelp-cmyk/caify-product/releases/latest', notes: '', force: false });
  }
});

// ════════════════════════════════════════════════════════════════
// POST /admin/reset-db — db.json 삭제 후 DEFAULT 데이터로 초기화
// ════════════════════════════════════════════════════════════════

app.post('/admin/reset-db', (req, res) => {
  const member = getMemberByToken(req);
  if (!member || !isAdmin(member)) {
    return res.status(403).json({ ok: false, error: '관리자 권한이 필요합니다.' });
  }
  try {
    if (fs.existsSync(DB_FILE)) fs.unlinkSync(DB_FILE);
    posts.length = 0; DEFAULT_POSTS.forEach(p => posts.push(JSON.parse(JSON.stringify(p))));
    messages.length = 0; DEFAULT_MESSAGES.forEach(m => messages.push(JSON.parse(JSON.stringify(m))));
    nextPostId = 5; nextMsgId = 4;
    saveDb();
    console.log('[admin] DB 리셋 완료');
    res.json({ ok: true, message: 'DB 초기화 완료', posts: posts.length });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

// ════════════════════════════════════════════════════════════════
// 네이버 블로그 ID 관리
// GET  /api/naver-blog?member_pk=X   → { ok, blog_id }
// PATCH /api/naver-blog              → body { member_pk, blog_id } → { ok }
// ════════════════════════════════════════════════════════════════

const naverBlogIds = { 1: 'testblog_dental', 2: 'gangnam_dental' };

app.get('/api/naver-blog', (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });
  const pk = parseInt(req.query.member_pk || member.id, 10);
  if (!isAdmin(member) && pk !== member.id)
    return res.status(403).json({ ok: false, error: '권한이 없습니다.' });
  res.json({ ok: true, blog_id: naverBlogIds[pk] || null });
});

app.patch('/api/naver-blog', (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });
  const pk = parseInt(req.body.member_pk || member.id, 10);
  if (!isAdmin(member) && pk !== member.id)
    return res.status(403).json({ ok: false, error: '권한이 없습니다.' });
  naverBlogIds[pk] = (req.body.blog_id || '').trim() || null;
  console.log(` [naver-blog] member=${pk} blog_id=${naverBlogIds[pk]}`);
  res.json({ ok: true });
});

// ════════════════════════════════════════════════════════════════
// 워크플로우 관리
// GET  /api/workflow?member_pk=X         → { ok, provisioned, workflows, keywords, schedule }
// POST /api/workflow/provision           → body { member_pk } → { ok, workflows }
// POST /api/workflow/modify              → body { member_pk, instruction } → { ok, message }
// ════════════════════════════════════════════════════════════════

const memberWorkflows = savedDb?.memberWorkflows ?? {
  1: {
    provisioned: true,
    workflows: [
      { type: 'info',  name: '1 테스트 치과 - info',  active: true,  workflow_id: n8nCfg.TEMPLATE_IDS.info },
      { type: 'mixed', name: '1 테스트 치과 - mixed', active: true,  workflow_id: n8nCfg.TEMPLATE_IDS.mixed },
      { type: 'case',  name: '1 테스트 치과 - case',  active: true,  workflow_id: n8nCfg.TEMPLATE_IDS.case },
    ],
    schedule_days: ['월', '수', '금'],
    schedule_hour: 10,
    last_modified: null,
  },
};

// ── db.json에서 복원된 오래된 mock/wf_ 워크플로우 ID를 실제 ID로 교체 ──
// Render 재배포 후에도 db.json이 남아있으면 이전 가짜 ID가 사용되는 문제 방지
(function migrateWorkflowIds() {
  const typeMap = { info: n8nCfg.TEMPLATE_IDS.info, mixed: n8nCfg.TEMPLATE_IDS.mixed, case: n8nCfg.TEMPLATE_IDS.case };
  let changed = false;
  for (const [, mw] of Object.entries(memberWorkflows)) {
    if (!Array.isArray(mw.workflows)) continue;
    for (const wf of mw.workflows) {
      const isStale = !wf.workflow_id ||
        wf.workflow_id.startsWith('mock-') ||
        wf.workflow_id.startsWith('err-') ||
        wf.workflow_id.startsWith('wf_') ||
        wf.workflow_id.startsWith('no-template-');
      if (isStale && typeMap[wf.type]) {
        console.log(`[migrate] ${wf.type}: ${wf.workflow_id} → ${typeMap[wf.type]}`);
        wf.workflow_id = typeMap[wf.type];
        changed = true;
      }
    }
  }
  if (changed) saveDb();
})();

app.get('/api/workflow', async (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });
  const pk = parseInt(req.query.member_pk || member.id, 10);
  if (!isAdmin(member) && pk !== member.id)
    return res.status(403).json({ ok: false, error: '권한이 없습니다.' });
  const wf = memberWorkflows[pk];
  if (!wf) return res.json({ ok: true, provisioned: false, workflows: [], keywords: [], schedule: null });

  // n8n에서 실제 active 상태 동기화
  const { N8N_URL, N8N_API_KEY } = n8nCfg;
  if (N8N_URL && N8N_API_KEY && !N8N_URL.includes('YOUR_N8N')) {
    await Promise.all(wf.workflows.map(async (w) => {
      if (!w.workflow_id || w.workflow_id.startsWith('mock-') || w.workflow_id.startsWith('err-') || w.workflow_id.startsWith('wf_')) return;
      try {
        const r = await fetch(`${N8N_URL}/api/v1/workflows/${w.workflow_id}`, {
          headers: { 'X-N8N-API-KEY': N8N_API_KEY },
        });
        const data = await r.json();
        if (typeof data.active === 'boolean') w.active = data.active;
      } catch (e) {
        console.warn(`[workflow/get] n8n sync 실패 (${w.workflow_id}):`, e.message);
      }
    }));
  }

  // 주간 발행 현황 계산
  const now = new Date();
  const weekStart = new Date(now);
  weekStart.setDate(now.getDate() - now.getDay()); // 이번 주 일요일
  weekStart.setHours(0, 0, 0, 0);

  const postsThisWeek = posts.filter(p =>
    p.customer_id === pk && p.posting_date &&
    new Date(p.posting_date) >= weekStart
  ).length;

  const scheduledThisWeek = publishQueue.filter(q =>
    q.member_pk === pk && q.publish_date &&
    new Date(q.publish_date) >= weekStart && q.status === 'pending'
  ).length;

  const casesThisWeek = cases.filter(c =>
    c.member_pk === pk && new Date(c.created_at) >= weekStart
  ).length;

  const weekly_stats = {
    posts_this_week:  postsThisWeek,
    posts_scheduled:  scheduledThisWeek,
    cases_this_week:  casesThisWeek,
  };

  res.json({ ok: true, ...wf, weekly_stats });
});

app.post('/api/workflow/provision', async (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });
  const pk = parseInt(req.body.member_pk || member.id, 10);
  if (!isAdmin(member) && pk !== member.id)
    return res.status(403).json({ ok: false, error: '권한이 없습니다.' });

  // 이미 복제 완료된 경우 중복 방지
  if (memberWorkflows[pk]?.provisioned) {
    return res.status(409).json({ ok: false, error: '이미 워크플로우가 생성되어 있습니다.' });
  }

  const info = members.find(m => m.id === pk);
  const name = info?.company_name || `member_${pk}`;

  const { N8N_URL, N8N_API_KEY, TEMPLATE_IDS } = n8nCfg;
  const workflow_ids = {};
  const workflows = [];

  if (N8N_URL && N8N_API_KEY && !N8N_URL.includes('YOUR_N8N')) {
    for (const type of WORKFLOW_TYPES) {
      try {
        const templateId = TEMPLATE_IDS[type];
        if (!templateId) throw new Error(`템플릿 ID 없음: ${type}`);

        const tRes = await fetch(`${N8N_URL}/api/v1/workflows/${templateId}`, {
          headers: { 'X-N8N-API-KEY': N8N_API_KEY },
        });
        if (!tRes.ok) throw new Error(`템플릿 조회 실패: ${tRes.status}`);
        const template = await tRes.json();

        const cRes = await fetch(`${N8N_URL}/api/v1/workflows`, {
          method: 'POST',
          headers: { 'X-N8N-API-KEY': N8N_API_KEY, 'Content-Type': 'application/json' },
          body: JSON.stringify({
            name: `[${pk}] ${name} - ${type}`,
            nodes: template.nodes,
            connections: template.connections,
            settings: template.settings,
          }),
        });
        if (!cRes.ok) throw new Error(`워크플로우 생성 실패: ${cRes.status}`);
        const created = await cRes.json();
        workflow_ids[type] = created.id;

        await fetch(`${N8N_URL}/api/v1/workflows/${created.id}/activate`, {
          method: 'POST',
          headers: { 'X-N8N-API-KEY': N8N_API_KEY },
        });

        workflows.push({ type, name: `[${pk}] ${name} - ${type}`, active: true, workflow_id: created.id });
      } catch (e) {
        console.error(`[workflow/provision] ${type} 실패:`, e.message);
        // 하나라도 실패하면 전체 실패 처리
        return res.status(500).json({ ok: false, error: `워크플로우 생성 실패 (${type}): ${e.message}` });
      }
    }
  } else {
    for (const type of WORKFLOW_TYPES) {
      const wfId = `mock-${pk}-${type}-${Date.now()}`;
      workflow_ids[type] = wfId;
      workflows.push({ type, name: `${pk} ${name} - ${type}`, active: true, workflow_id: wfId });
    }
    console.log('[workflow/provision] N8N 미설정 — mock ID 사용');
  }

  // 모두 성공한 경우에만 저장
  memberWorkflows[pk] = {
    provisioned: true,
    workflows,
    schedule_days: ['월', '수', '금'],
    schedule_hour: 10,
    last_modified: null,
  };
  if (info) info.n8n_workflow_ids = workflow_ids;
  saveDb();

  console.log(` [workflow/provision] member=${pk} name="${name}"`);
  res.json({ ok: true, message: `워크플로우 ${WORKFLOW_TYPES.length}개 생성됨 (${name})`, workflows });
});

// POST /api/workflow/update — 직접 필드 수정 (키워드/스케줄/워크플로우 활성화)
app.post('/api/workflow/update', async (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });
  const pk = parseInt(req.body.member_pk || member.id, 10);
  if (!isAdmin(member) && pk !== member.id)
    return res.status(403).json({ ok: false, error: '권한이 없습니다.' });

  const wf = memberWorkflows[pk];
  if (!wf) return res.status(404).json({ ok: false, error: '워크플로우가 설정되지 않았습니다.' });

  if (Array.isArray(req.body.schedule_days)) {
    wf.schedule_days = req.body.schedule_days.filter(d => ['월','화','수','목','금','토','일'].includes(d));
  }
  if (typeof req.body.schedule_hour === 'number') {
    wf.schedule_hour = Math.max(0, Math.min(23, req.body.schedule_hour));
  }
  if (Array.isArray(req.body.workflows)) {
    req.body.workflows.forEach(({ type, active }) => {
      const target = wf.workflows.find(w => w.type === type);
      if (target) target.active = !!active;
    });
  }
  wf.last_modified = new Date().toISOString();
  saveDb();

  // n8n activate/deactivate 동기화
  const { N8N_URL, N8N_API_KEY } = n8nCfg;
  if (Array.isArray(req.body.workflows) && N8N_URL && N8N_API_KEY && !N8N_URL.includes('YOUR_N8N')) {
    await Promise.all(req.body.workflows.map(async ({ type, active }) => {
      const target = wf.workflows.find(w => w.type === type);
      if (!target?.workflow_id || target.workflow_id.startsWith('mock-') || target.workflow_id.startsWith('err-') || target.workflow_id.startsWith('wf_')) return;
      const endpoint = active ? 'activate' : 'deactivate';
      try {
        await fetch(`${N8N_URL}/api/v1/workflows/${target.workflow_id}/${endpoint}`, {
          method: 'POST',
          headers: { 'X-N8N-API-KEY': N8N_API_KEY },
        });
        console.log(` [workflow/update] n8n ${endpoint} wf=${target.workflow_id}`);
      } catch (e) {
        console.warn(` [workflow/update] n8n ${endpoint} 실패 (${target.workflow_id}):`, e.message);
      }
    }));
  }

  console.log(` [workflow/update] member=${pk} days=${JSON.stringify(wf.schedule_days)} hour=${wf.schedule_hour}`);
  res.json({ ok: true, message: '워크플로우가 저장됐습니다.' });
});

app.post('/api/workflow/modify', (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });
  const pk = parseInt(req.body.member_pk || member.id, 10);
  const { instruction } = req.body;
  if (!instruction?.trim()) return res.status(422).json({ ok: false, error: 'instruction is required' });
  if (!isAdmin(member) && pk !== member.id)
    return res.status(403).json({ ok: false, error: '권한이 없습니다.' });

  const wf = memberWorkflows[pk];
  if (!wf) return res.status(404).json({ ok: false, error: '워크플로우가 설정되지 않았습니다.' });

  wf.last_modified = new Date().toISOString();

  // 키워드 변경 파싱
  const kwMatch = instruction.match(/키워드[:\s]+([^\n]+)/);
  if (kwMatch) {
    wf.keywords = kwMatch[1].split(/[,、\s]+/).map(k => k.trim()).filter(Boolean);
  }

  // 채팅 확인 메시지 추가
  messages.push({
    id: nextMsgId++,
    member_pk: pk,
    type: 'workflow.modified',
    is_system: true,
    text: `워크플로우 수정 요청이 접수됐습니다.\n\n"${instruction.trim().substring(0, 60)}" 내용을 반영해 다음 포스팅부터 적용됩니다.`,
    post_id: null, post_title: null, post_html: null,
    meta: { instruction: instruction.trim() },
    actions: [], read: false,
    created_at: new Date().toISOString(),
  });
  saveDb();

  console.log(` [workflow/modify] member=${pk} "${instruction.substring(0, 40)}"`);
  res.json({ ok: true, message: '수정 요청이 접수됐습니다.' });
});

// ════════════════════════════════════════════════════════════════
// 키워드 순위 체크
// GET  /api/rank?member_pk=X                    → { ok, ranks: [...] }
// POST /api/rank/check                          → body { member_pk, keyword, blog_id } → { ok, rank, prev_rank }
// ════════════════════════════════════════════════════════════════

const rankData = {
  '1_임플란트': [
    { rank: 18, checked_at: new Date(Date.now() - 7 * 86400000).toISOString() },
    { rank: 12, checked_at: new Date(Date.now() - 3 * 86400000).toISOString() },
  ],
  '1_스케일링': [
    { rank: 32, checked_at: new Date(Date.now() - 5 * 86400000).toISOString() },
    { rank: 25, checked_at: new Date(Date.now() - 2 * 86400000).toISOString() },
  ],
};

app.get('/api/rank', (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });
  const pk = parseInt(req.query.member_pk || member.id, 10);
  if (!isAdmin(member) && pk !== member.id)
    return res.status(403).json({ ok: false, error: '권한이 없습니다.' });

  const prefix = `${pk}_`;
  const ranks = Object.entries(rankData)
    .filter(([k]) => k.startsWith(prefix))
    .map(([k, hist]) => {
      const kw = k.slice(prefix.length);
      const latest = hist[hist.length - 1];
      const prev   = hist.length > 1 ? hist[hist.length - 2] : null;
      return { keyword: kw, rank: latest?.rank ?? null, prev_rank: prev?.rank ?? null, checked_at: latest?.checked_at ?? null };
    });

  res.json({ ok: true, ranks });
});

// ── 네이버 블로그 검색 순위 크롤러 ──────────────────────────────────
// 네이버 블로그 검색 결과에서 blog_id 위치를 찾아 순위 반환
// 최대 50위(5페이지)까지 탐색. 발견 못 하면 { rank: null, found: false }
async function checkNaverBlogRank(keyword, blogId) {
  const MAX_RESULTS = 50;
  const PER_PAGE    = 10;
  const PAGES       = Math.ceil(MAX_RESULTS / PER_PAGE);
  const seen        = new Set();
  let   rank        = 0;

  for (let page = 1; page <= PAGES; page++) {
    const start = (page - 1) * PER_PAGE + 1;
    const url   = `https://search.naver.com/search.naver?where=blog&query=${encodeURIComponent(keyword)}&start=${start}`;

    try {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 8000);

      const res = await fetch(url, {
        signal: controller.signal,
        headers: {
          'User-Agent':      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
          'Accept':          'text/html,application/xhtml+xml',
          'Accept-Language': 'ko-KR,ko;q=0.9',
          'Referer':         'https://www.naver.com/',
        },
      });
      clearTimeout(timer);

      const html = await res.text();

      // blog.naver.com/{blogId}/{postId} 또는 m.blog.naver.com/{blogId}/{postId}
      const re = /(?:https?:\/\/)?(?:m\.)?blog\.naver\.com\/([a-zA-Z0-9_.-]+)\/(\d+)/g;
      let m;
      while ((m = re.exec(html)) !== null) {
        const foundId = m[1];
        const postId  = m[2];
        const key     = `${foundId}/${postId}`;
        if (seen.has(key)) continue;
        seen.add(key);
        rank++;
        if (foundId.toLowerCase() === blogId.toLowerCase()) {
          console.log(` [rank/crawl] "${keyword}" → ${foundId} 발견: ${rank}위 (page ${page})`);
          return { rank, found: true };
        }
        if (rank >= MAX_RESULTS) return { rank: null, found: false };
      }
    } catch (e) {
      console.error(` [rank/crawl] page=${page} 오류:`, e.message);
      if (rank === 0) return { rank: null, found: false, error: e.message };
      break;
    }

    if (page < PAGES) await new Promise(r => setTimeout(r, 300));
  }

  console.log(` [rank/crawl] "${keyword}" → ${blogId} 50위 밖`);
  return { rank: null, found: false };
}

app.post('/api/rank/check', async (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });

  const pk       = parseInt(req.body.member_pk || member.id, 10);
  const keyword  = (req.body.keyword  || '').trim();
  const blog_id  = (req.body.blog_id  || '').trim();

  if (!keyword)  return res.status(422).json({ ok: false, error: 'keyword is required' });
  if (!blog_id)  return res.status(422).json({ ok: false, error: 'blog_id is required' });
  if (!isAdmin(member) && pk !== member.id)
    return res.status(403).json({ ok: false, error: '권한이 없습니다.' });

  console.log(` [rank/check] member=${pk} keyword="${keyword}" blog_id="${blog_id}"`);

  const result = await checkNaverBlogRank(keyword, blog_id);

  const key  = `${pk}_${keyword}`;
  if (!rankData[key]) rankData[key] = [];
  const prev  = rankData[key].length > 0 ? rankData[key][rankData[key].length - 1] : null;
  const entry = { rank: result.rank, found: result.found, checked_at: new Date().toISOString() };
  rankData[key].push(entry);

  res.json({
    ok:         true,
    keyword,
    rank:       result.rank,
    found:      result.found,
    prev_rank:  prev?.rank ?? null,
    checked_at: entry.checked_at,
    message:    result.found
      ? `${result.rank}위에서 발견됐습니다.`
      : '상위 50위 내에서 발견되지 않았습니다.',
  });
});

// ── 상태 확인 ────────────────────────────────────────────────────

app.get('/', (req, res) => {
  res.json({
    ok: true,
    server: 'Caify Mock API Server',
    version: '1.0.0',
    posts_count: posts.length,
    members_count: members.length,
    endpoints: [
      'POST /member/login',
      'GET /member/me',
      'POST /member/provision',
      'GET /api/posts?status=ready&member_pk=X',
      'POST /api/posts',
      'POST /api/posts/:id/published',
      'POST /api/posts/:id/failed',
      'GET /api/post_meta?id=X',
      'GET /api/posts/:id',
      'PATCH /api/posts/:id',
      'POST /api/posts/:id/reject',
      'GET /admin/posts',
      'POST /admin/posts/:id/approve',
      'GET /api/messages?member_pk=X&after_id=Y',
      'POST /api/messages',
      'POST /api/messages/:id/action',
      'POST /api/messages/:id/read',
      'GET /api/naver-blog?member_pk=X',
      'PATCH /api/naver-blog',
      'GET /api/workflow?member_pk=X',
      'POST /api/workflow/provision',
      'POST /api/workflow/modify',
      'GET /api/rank?member_pk=X',
      'POST /api/rank/check',
      'POST /api/case/submit',
      'GET /api/case?member_pk=X',
      'GET /api/outputs?member_pk=X',
    ],
  });
});

// ════════════════════════════════════════════════════════════════
// 사례형(Case) 관리
// caify_case 테이블 미러
//
// POST /api/case/submit
//   Body: { member_pk, case_title, raw_content, images?: [{name, url}] }
//   → caify_case 저장 + n8n case 워크플로우 트리거
//   Response: { ok, case_id, message }
//
// GET /api/case?member_pk=X
//   → 내 사례 목록 (caify_case)
//   Response: [{ id, case_title, raw_content, ai_status, created_at, files }]
// ════════════════════════════════════════════════════════════════

// caify_case_file (파일 업로드 — mock은 URL 직접 수신)
// 실서버: multipart/form-data → 파일 저장 후 stored_path 기록

app.post('/api/case/submit', upload.array('case_images', 8), (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });
  if (!isPaid(member)) return res.status(403).json({ ok: false, error: '유료 플랜 전용입니다.' });

  const pk          = parseInt(req.body.member_pk || member.id, 10);
  const caseTitle   = (req.body.case_title   || '').trim();
  const rawContent  = (req.body.raw_content  || '').trim();
  // multipart 파일들 (실서버 동일 구조)
  const uploadedFiles = req.files || [];
  const images = uploadedFiles.map((f, i) => ({ name: f.originalname, url: '' }));

  if (!caseTitle)  return res.status(422).json({ ok: false, error: '사례명은 필수입니다.' });
  if (!rawContent) return res.status(422).json({ ok: false, error: '사례 내용은 필수입니다.' });

  const caseItem = {
    id:          nextCaseId++,
    member_pk:   pk,
    case_title:  caseTitle,
    raw_content: rawContent,
    ai_status:   'pending',   // n8n 처리 전
    ai_title:    null,
    ai_summary:  null,
    files:       images.map((img, i) => ({
      id:            i + 1,
      original_name: img.name || `image_${i + 1}.jpg`,
      url:           img.url  || '',
    })),
    created_at: new Date().toISOString(),
  };

  cases.push(caseItem);
  saveDb();

  // n8n case 워크플로우 실행
  const caseWf = memberWorkflows[pk]?.workflows?.find(w => w.type === 'case');
  const { N8N_URL, N8N_API_KEY } = n8nCfg;
  const isRealWf = caseWf?.workflow_id && !caseWf.workflow_id.startsWith('mock-') && !caseWf.workflow_id.startsWith('err-') && !caseWf.workflow_id.startsWith('wf_');
  console.log(` [case/submit] id=${caseItem.id} member=${pk} wf=${caseWf?.workflow_id ?? 'none'}`);

  if (isRealWf && N8N_URL && N8N_API_KEY && !N8N_URL.includes('YOUR_N8N')) {
    // /execute API는 executeWorkflowTrigger 워크플로우에 미지원 → webhook으로 우회
    const webhookPath = n8nCfg.CASE_WEBHOOK_PATH;
    const webhookWfId = n8nCfg.CASE_WEBHOOK_WF_ID;
    fetch(`${N8N_URL}/webhook/${webhookPath}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        case_id:     caseItem.id,
        member_pk:   pk,
        case_title:  caseTitle,
        raw_content: rawContent,
        files:       caseItem.files,
      }),
    }).then(r => r.json()).then(result => {
      console.log(` [case/submit] webhook 실행: ${result?.message ?? JSON.stringify(result).substring(0, 80)}`);
      // 2초 후 최신 executionId 조회 (webhook은 즉시 ID를 반환하지 않음)
      setTimeout(() => {
        fetch(`${N8N_URL}/api/v1/executions?workflowId=${webhookWfId}&limit=1`, {
          headers: { 'X-N8N-API-KEY': N8N_API_KEY },
        }).then(r => r.json()).then(execData => {
          const executionId = execData.data?.[0]?.id;
          if (executionId) {
            const c = cases.find(x => x.id === caseItem.id);
            if (c) { c.execution_id = executionId; c.n8n_workflow_id = webhookWfId; saveDb(); }
            console.log(` [case/submit] executionId=${executionId} wfId=${webhookWfId}`);
          }
        }).catch(e => console.error(` [case/submit] executionId 조회 실패:`, e.message));
      }, 2000);
    }).catch(e => {
      console.error(` [case/submit] webhook 실패:`, e.message);
    });
  } else {
    // mock: 단계별 진행률 시뮬레이션 → 3초 후 완료
    const mockStages = [
      { delay: 300,  progress: 15, step: '사례 분석 중' },
      { delay: 900,  progress: 35, step: 'AI 포스팅 생성 중' },
      { delay: 1700, progress: 65, step: '내용 최적화 중' },
      { delay: 2400, progress: 88, step: '마무리 중' },
    ];
    const ci = cases.find(x => x.id === caseItem.id);
    if (ci) { ci.mock_progress = 5; ci.mock_step = '제출됨'; saveDb(); }
    mockStages.forEach(({ delay, progress, step }) => {
      setTimeout(() => {
        const cx = cases.find(x => x.id === caseItem.id);
        if (cx && cx.ai_status === 'pending') { cx.mock_progress = progress; cx.mock_step = step; }
      }, delay);
    });
    setTimeout(() => {
      const c = cases.find(x => x.id === caseItem.id);
      if (c && c.ai_status === 'pending') {
        const aiTitle   = `[AI] ${caseTitle}`;
        const aiSummary = `${rawContent.substring(0, 60)}... (AI 요약)`;
        const aiHtml    = `<h2>${aiTitle}</h2><p>${rawContent}</p><p>${aiSummary}</p>`;

        // ai_posts에 새 포스팅 추가 (index.php와 동일 역할)
        const newPost = {
          id:             nextPostId++,
          customer_id:    pk,
          prompt_id:      null,
          prompt_node_id: 'case',
          title:          aiTitle,
          subject:        caseTitle,
          intro:          aiSummary,
          html:           aiHtml,
          naver_html:     `<div class="se-main-container"><p>${rawContent}</p></div>`,
          tags:           [],
          status:         1,
          posting_date:   null,
          created_at:     new Date().toISOString(),
        };
        posts.push(newPost);

        // caify_case.post_id 업데이트
        c.ai_status  = 'done';
        c.ai_title   = aiTitle;
        c.ai_summary = aiSummary;
        c.post_id    = newPost.id;
        saveDb();
        messages.push({
          id: nextMsgId++, member_pk: pk,
          type: 'case.done', is_system: true,
          text: `✅ 사례형 포스팅이 완성됐습니다!\n\n"${aiTitle}"\n\n산출물 탭에서 확인하세요.`,
          post_id: newPost.id, post_title: aiTitle, post_html: aiHtml,
          meta: { case_id: c.id },
          actions: [{ label: '산출물 보기', action_key: 'view_outputs' }],
          read: false, created_at: new Date().toISOString(),
        });
        saveDb();
        console.log(` [case/done] id=${caseItem.id} post_id=${newPost.id} (mock)`);
      }
    }, 3000);
  }

  res.status(201).json({
    ok:      true,
    case_id: caseItem.id,
    message: 'n8n 워크플로우 실행 중입니다. 잠시 후 산출물 탭에서 확인하세요.',
  });
});

// ── 사례 진행 상태 (앱 polling용) ────────────────────────────
app.get('/api/case/:id/status', async (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });

  const caseId = parseInt(req.params.id, 10);
  const c = cases.find(x => x.id === caseId && (x.member_pk === member.id || isAdmin(member)));
  if (!c) return res.status(404).json({ ok: false, error: '사례를 찾을 수 없습니다.' });

  if (c.ai_status === 'done') {
    return res.json({ ok: true, case_id: c.id, ai_status: 'done', post_id: c.post_id ?? null, progress: 100, step: '완료' });
  }
  if (c.ai_status === 'failed' || c.ai_status === 'error') {
    return res.json({ ok: true, case_id: c.id, ai_status: 'failed', progress: 0, step: '처리 실패' });
  }

  // n8n execution 실시간 조회
  const { N8N_URL, N8N_API_KEY } = n8nCfg;
  const caseWf = memberWorkflows[c.member_pk]?.workflows?.find(w => w.type === 'case');
  // c.n8n_workflow_id: webhook으로 실행한 경우 실제 실행 워크플로우 ID 저장됨
  const wfId = c.n8n_workflow_id ?? n8nCfg.CASE_WEBHOOK_WF_ID ?? caseWf?.workflow_id;

  if (N8N_URL && N8N_API_KEY && !N8N_URL.includes('YOUR_N8N') && wfId) {
    try {
      let execData = null;

      // 1) execution_id로 직접 조회
      if (c.execution_id) {
        const r = await fetch(`${N8N_URL}/api/v1/executions/${c.execution_id}`, {
          headers: { 'X-N8N-API-KEY': N8N_API_KEY },
          signal: AbortSignal.timeout(4000),
        });
        if (r.ok) execData = await r.json();
      }

      // 2) execution_id 없거나 조회 실패 → 워크플로우 최근 실행 목록에서 찾기
      if (!execData) {
        const r = await fetch(
          `${N8N_URL}/api/v1/executions?workflowId=${wfId}&limit=5`,
          { headers: { 'X-N8N-API-KEY': N8N_API_KEY }, signal: AbortSignal.timeout(4000) }
        );
        if (r.ok) {
          const list = await r.json();
          const items = list.data ?? list ?? [];
          // 가장 최근 running/new 실행, 없으면 첫 번째
          const recent = items.find(e => e.status === 'running' || e.status === 'new' || e.status === 'waiting') ?? items[0];
          if (recent) {
            execData = recent;
            if (!c.execution_id && recent.id) { c.execution_id = recent.id; saveDb(); }
          }
        }
      }

      if (execData) {
        if (execData.status === 'error') {
          return res.json({ ok: true, case_id: c.id, ai_status: 'failed', progress: 0, step: '처리 실패' });
        }

        const runData = execData.data?.resultData?.runData ?? {};
        const completedNodes = Object.keys(runData);
        const completedCount = completedNodes.length;
        const TOTAL = 4; // 사례 워크플로우 주요 노드 수
        const pct = completedCount === 0 ? 15 : Math.min(Math.round((completedCount / TOTAL) * 90), 90);

        const stepMap = {
          '사례 가져오기1': '사례 데이터 조회 중',
          'HTTP Request':  'AI 포스팅 생성 중',
          'HTTP Request1': '결과 저장 중',
        };
        const lastNode = completedNodes[completedNodes.length - 1] ?? '';
        const step = stepMap[lastNode] ?? (completedCount > 0 ? `${completedCount}/${TOTAL} 단계 완료` : 'AI 처리 중');

        if (execData.status === 'success') {
          return res.json({ ok: true, case_id: c.id, ai_status: 'pending', progress: 95, step: '결과 저장 중' });
        }

        return res.json({ ok: true, case_id: c.id, ai_status: 'pending', progress: pct, step });
      }
    } catch (e) {
      console.warn('[case/status] n8n 조회 실패:', e.message);
    }
  }

  // fallback: mock 또는 n8n 미설정
  res.json({
    ok: true,
    case_id: c.id,
    ai_status: c.ai_status ?? 'pending',
    progress: c.mock_progress ?? 5,
    step: c.mock_step ?? 'AI 처리 중...',
  });
});

app.get('/api/case', (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });

  const pk = parseInt(req.query.member_pk || member.id, 10);
  if (!isAdmin(member) && pk !== member.id)
    return res.status(403).json({ ok: false, error: '권한이 없습니다.' });

  const result = cases
    .filter(c => c.member_pk === pk)
    .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
    .map(c => ({
      id:          c.id,
      case_title:  c.case_title,
      raw_content: c.raw_content,
      ai_status:   c.ai_status,
      ai_title:    c.ai_title,
      ai_summary:  c.ai_summary,
      post_id:     c.post_id ?? null,
      files:       c.files,
      created_at:  c.created_at,
    }));

  res.json(result);
});

// ════════════════════════════════════════════════════════════════
// 산출물(Outputs) 관리
// ai_posts 에서 발행 대기/완료 목록 조회
//
// GET /api/outputs?member_pk=X&page=1
//   실서버: ai_posts WHERE status=1 AND DATE(created_at) <= DATE_SUB(CURDATE(), 2 DAY)
//   Response: { ok, total, page, per_page, items: [...] }
// ════════════════════════════════════════════════════════════════

app.get('/api/outputs', (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });

  const pk      = parseInt(req.query.member_pk || member.id, 10);
  const page    = Math.max(1, parseInt(req.query.page || '1', 10));
  const perPage = 12;

  if (!isAdmin(member) && pk !== member.id)
    return res.status(403).json({ ok: false, error: '권한이 없습니다.' });

  // 실서버와 동일: status=1, 2일 이상 지난 것만 (관리자는 전체)
  const twoDaysAgo = new Date(Date.now() - 2 * 86400000);
  const filtered = posts.filter(p => {
    if (isAdmin(member)) return true;
    return p.customer_id === pk &&
           p.status === 1 &&
           new Date(p.created_at) <= twoDaysAgo;
  }).sort((a, b) => new Date(b.created_at) - new Date(a.created_at));

  const total = filtered.length;
  const items = filtered.slice((page - 1) * perPage, page * perPage).map(p => ({
    id:           p.id,
    title:        p.title,
    subject:      p.subject || null,
    html:         p.html         || '',
    naver_html:   p.naver_html   || '',
    tags:         p.tags         || [],
    status:       p.posting_date ? 'published' : 'ready',
    posting_date: p.posting_date,
    created_at:   p.created_at,
    // 썸네일: naver_html에서 첫 번째 img src 추출
    thumbnail:    (() => {
      const m = (p.naver_html || '').match(/<img[^>]+src=["']([^"']+)["']/i);
      return m ? m[1] : null;
    })(),
  }));

  res.json({ ok: true, total, page, per_page: perPage, items });
});

// ════════════════════════════════════════════════════════════════
// POST /api  — n8n 완료 콜백 (= 실서버 api/index.php)
// 인증 불필요 (n8n이 직접 호출)
// Body: { title, html, naverHtml, customer_id, prompt_id, promptNodeId,
//         subject?, intro?, case_id? }
// ════════════════════════════════════════════════════════════════

app.post('/api', (req, res) => {
  const { title, html, naverHtml, customer_id, prompt_id, promptNodeId, subject, intro, case_id } = req.body || {};

  if (!title || !naverHtml || !customer_id || !promptNodeId) {
    return res.status(422).json({
      ok: false,
      error: 'title, naverHtml, customer_id, promptNodeId are required',
    });
  }

  const post = {
    id:             nextPostId++,
    customer_id:    parseInt(customer_id, 10),
    prompt_id:      parseInt(prompt_id || '0', 10),
    prompt_node_id: promptNodeId,
    title,
    subject:        subject || null,
    intro:          intro   || null,
    html:           html    || '',
    naver_html:     naverHtml,
    tags:           [],
    status:         1,
    posting_date:   null,
    created_at:     new Date().toISOString(),
  };

  posts.push(post);

  // case_id 있으면 caify_case 업데이트 (= index.php UPDATE caify_case)
  const caseIdInt = parseInt(case_id || '0', 10);
  if (caseIdInt > 0) {
    const c = cases.find(x => x.id === caseIdInt);
    if (c) {
      c.ai_status = 'done';
      c.post_id   = post.id;
      console.log(` [/api] case_id=${caseIdInt} → post_id=${post.id} ai_status=done`);
    }
  }

  saveDb();
  console.log(` [/api] new post id=${post.id} customer=${customer_id} title="${title}"`);

  res.json({ ok: true, message: 'Inserted successfully', insert_id: post.id });
});

// ── 서버 시작 ────────────────────────────────────────────────────

app.listen(PORT, () => {
  const fromFile = fs.existsSync(DB_FILE);
  console.log('');
  console.log(' ╔══════════════════════════════════════════╗');
  console.log(' ║       Caify Mock API Server              ║');
  console.log(` ║  http://localhost:${PORT}                  ║`);
  console.log(' ╚══════════════════════════════════════════╝');
  console.log('');
  console.log(` 데이터: ${fromFile ? `파일 복원 (${DB_FILE})` : '기본값 사용'}`);
  console.log(` posts=${posts.length}개, messages=${messages.length}개`);
  console.log('');
  console.log(' 테스트 계정:');
  console.log(' member_id: testuser / passwd: password123');
  console.log(' member_id: dental2 / passwd: password123');
  console.log(' member_id: admin / passwd: adminpass (관리자)');
  console.log('');
});
