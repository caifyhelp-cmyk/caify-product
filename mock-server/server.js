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

const app = express();
const PORT = process.env.PORT || 3030;

app.use(express.json());
app.use(express.urlencoded({ extended: true }));

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
    fs.writeFileSync(DB_FILE, JSON.stringify({ posts, messages, nextPostId, nextMsgId }, null, 2));
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
    html: '<h2>임플란트란?</h2><p>치아를 잃었을 때 가장 자연스럽고 효과적인 대체 방법입니다.</p><p>임플란트는 자연치아와 가장 유사한 기능을 회복할 수 있는 시술로, 저작 기능과 심미성을 동시에 만족시킵니다.</p><h2>임플란트 시술 과정</h2><p>1단계: 정밀 검진 및 CT 촬영으로 뼈 상태를 확인합니다.</p><p>2단계: 잇몸 뼈에 티타늄 픽스처를 식립합니다.</p><p>3단계: 3~6개월 골유착 기간을 거칩니다.</p><p>4단계: 지대주와 크라운을 연결하여 최종 보철을 완성합니다.</p><img src="https://picsum.photos/seed/implant2026/800/400" alt="임플란트 시술 과정" style="max-width:100%;height:auto;display:block;margin:16px 0"><h2>비용 및 주의사항</h2><p>임플란트 비용은 1개당 100~150만 원 수준이며, 건강보험 적용 시 본인부담금이 대폭 줄어듭니다.</p><p>당뇨·골다공증 환자는 시술 전 반드시 전문의 상담이 필요합니다.</p><p>📞 [Caify 자동입력 테스트] 타이틀·본문·태그·이미지가 모두 정상 삽입됐는지 확인하세요.</p>',
    naver_html: '<h2>임플란트란?</h2><p>치아를 잃었을 때 가장 자연스럽고 효과적인 대체 방법입니다.</p><p>임플란트는 자연치아와 가장 유사한 기능을 회복할 수 있는 시술로, 저작 기능과 심미성을 동시에 만족시킵니다.</p><h2>임플란트 시술 과정</h2><p>1단계: 정밀 검진 및 CT 촬영으로 뼈 상태를 확인합니다.</p><p>2단계: 잇몸 뼈에 티타늄 픽스처를 식립합니다.</p><p>3단계: 3~6개월 골유착 기간을 거칩니다.</p><p>4단계: 지대주와 크라운을 연결하여 최종 보철을 완성합니다.</p><img src="https://picsum.photos/seed/implant2026/800/400" alt="임플란트 시술 과정" style="max-width:100%;height:auto;display:block;margin:16px 0"><h2>비용 및 주의사항</h2><p>임플란트 비용은 1개당 100~150만 원 수준이며, 건강보험 적용 시 본인부담금이 대폭 줄어듭니다.</p><p>당뇨·골다공증 환자는 시술 전 반드시 전문의 상담이 필요합니다.</p><p>📞 [Caify 자동입력 테스트] 타이틀·본문·태그·이미지가 모두 정상 삽입됐는지 확인하세요.</p>',
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
    html: '<h2>스케일링이란?</h2><p>스케일링은 치아 표면과 잇몸 사이에 쌓인 치석을 전문 기구로 제거하는 시술입니다.</p><p>치석은 칫솔질만으로는 제거되지 않으며, 방치하면 잇몸 질환과 충치의 원인이 됩니다.</p><img src="https://picsum.photos/seed/scaling2026/800/400" alt="스케일링 시술" style="max-width:100%;height:auto;display:block;margin:16px 0"><h2>스케일링 권장 주기</h2><p>건강한 성인은 6개월에 1회, 잇몸 질환이 있는 경우 3개월에 1회 권장합니다.</p><p>건강보험 적용 시 연 1회 본인부담금 약 1만 5천 원으로 받을 수 있습니다.</p><p>🦷 [Caify 자동입력 테스트 v2]</p>',
    naver_html: '<h2>스케일링이란?</h2><p>스케일링은 치아 표면과 잇몸 사이에 쌓인 치석을 전문 기구로 제거하는 시술입니다.</p><p>치석은 칫솔질만으로는 제거되지 않으며, 방치하면 잇몸 질환과 충치의 원인이 됩니다.</p><img src="https://picsum.photos/seed/scaling2026/800/400" alt="스케일링 시술" style="max-width:100%;height:auto;display:block;margin:16px 0"><h2>스케일링 권장 주기</h2><p>건강한 성인은 6개월에 1회, 잇몸 질환이 있는 경우 3개월에 1회 권장합니다.</p><p>건강보험 적용 시 연 1회 본인부담금 약 1만 5천 원으로 받을 수 있습니다.</p><p>🦷 [Caify 자동입력 테스트 v2]</p>',
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

// 파일에서 복원하거나 기본값 사용
const savedDb = loadDb();
let posts = savedDb?.posts ?? DEFAULT_POSTS;
let messages = savedDb?.messages ?? DEFAULT_MESSAGES;
let nextPostId = savedDb?.nextPostId ?? 5;
let nextMsgId = savedDb?.nextMsgId ?? 4;

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
  },
  {
    id: 2,
    member_id: 'dental2',
    passwd: 'password123',
    company_name: '강남 치과의원',
    api_token: 'mock-token-dental2',
  },
  {
    id: 10, // 관리자 (실서버: id=10)
    member_id: 'admin',
    passwd: 'adminpass',
    company_name: 'Caify 관리자',
    api_token: 'mock-token-admin',
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
  });
});

// ════════════════════════════════════════════════════════════════
// GET /api/posts?status=ready&member_pk=X
// 발행 대기 목록: status=1(승인) AND posting_date IS NULL
// Electron tray: 60초마다 폴링 / Flutter: 목록 화면
// ════════════════════════════════════════════════════════════════

app.get('/api/posts', (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });

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

app.post('/api/posts', (req, res) => {
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

app.post('/api/posts/:id/published', (req, res) => {
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

app.post('/api/posts/:id/failed', (req, res) => {
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

app.get('/api/posts/:id', (req, res) => {
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

app.get('/api/messages', (req, res) => {
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

// ════════════════════════════════════════════════════════════════
// POST /api/messages — 고객이 직접 텍스트 입력 시
// ════════════════════════════════════════════════════════════════

app.post('/api/messages', (req, res) => {
  const member = getMemberByToken(req);
  if (!member) return res.status(401).json({ ok: false, error: '인증이 필요합니다.' });

  const { member_pk, text } = req.body;
  if (!text?.trim()) return res.status(422).json({ ok: false, error: 'text is required' });

  const pk = parseInt(member_pk || '0', 10) || member.id;

  const msg = {
    id: nextMsgId++,
    member_pk: pk,
    type: 'user_text',
    is_system: false,
    text: text.trim(),
    post_id: null,
    post_title: null,
    post_html: null,
    meta: null,
    actions: [],
    read: true,
    created_at: new Date().toISOString(),
  };

  messages.push(msg);

  // 자동 응답 (개발 편의)
  const autoReply = {
    id: nextMsgId++,
    member_pk: pk,
    type: 'user_text',
    is_system: true,
    text: `말씀 주신 내용 확인했습니다!\n"${text.trim().substring(0, 30)}" 요청을 반영해 다음 포스팅에 적용하겠습니다.`,
    post_id: null, post_title: null, post_html: null,
    meta: null, actions: [], read: false,
    created_at: new Date(Date.now() + 1000).toISOString(),
  };

  messages.push(autoReply);
  saveDb();
  console.log(` [msg] member=${pk} text="${text.trim().substring(0, 40)}"`);

  res.status(201).json({ ok: true, id: msg.id });
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
    ],
  });
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
