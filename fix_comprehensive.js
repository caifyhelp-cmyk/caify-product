const https = require('https');

const N8N_KEY = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhMmNlNTVlNS01YTUwLTQyMjgtOWM5Yi1hNWM0MzBmNzM4NDEiLCJpc3MiOiJuOG4iLCJhdWQiOiJwdWJsaWMtYXBpIiwiaWF0IjoxNzc2OTgyMzIzfQ.zeIagMQxIuDN-OwQHhKuATLM0CDb-dNRCLuB5zCFzGI";
const WF_ID = 'daUM2xPEVyBhbyez';

function req(method, path, body) {
  return new Promise((res, rej) => {
    const bodyStr = body ? JSON.stringify(body) : null;
    const r = https.request({
      hostname: 'n8n.caify.ai', path, method,
      headers: {
        'X-N8N-API-KEY': N8N_KEY,
        'Content-Type': 'application/json',
        ...(bodyStr ? { 'Content-Length': Buffer.byteLength(bodyStr) } : {})
      }
    }, resp => {
      const c = [];
      resp.on('data', d => c.push(d));
      resp.on('end', () => { try { res(JSON.parse(Buffer.concat(c).toString())); } catch { res(Buffer.concat(c).toString()); } });
    });
    r.on('error', rej);
    if (bodyStr) r.write(bodyStr);
    r.end();
  });
}

// ── 1. 스타일 랜덤화1 ─────────────────────────────────────────────────
// Gemini 노드 출력: { content: { parts: [{ text: "```json\n{...}\n```" }] } }
// bodyMarkdown을 content.parts에서 추출 후 정규화
const STYLE_CODE = `
function extractJson(json) {
  let raw = '';
  if (typeof json?.output === 'string' && json.output.trim()) {
    raw = json.output.trim();
  } else if (Array.isArray(json?.content?.parts)) {
    raw = json.content.parts
      .map(p => (typeof p?.text === 'string' ? p.text : ''))
      .filter(Boolean).join('\\n').trim();
  } else if (typeof json?.bodyMarkdown === 'string') {
    return json; // 이미 파싱됨
  }
  if (!raw) return json;
  raw = raw.replace(/^\`\`\`(?:json)?\\s*\\n?/i, '').replace(/\\n?\`\`\`\\s*$/i, '').trim();
  try { return JSON.parse(raw); } catch { return json; }
}

function normalizeBody(text) {
  let t = String(text ?? '').replace(/\\r\\n/g, '\\n');
  if (!t.trim()) return '';
  t = t.replace(/\\n{3,}/g, '\\n\\n');
  const paragraphs = t.split('\\n\\n').map(p => p.trim()).filter(Boolean);
  const leadWords = ['또한', '그리고', '하지만', '다만', '즉', '특히', '이 경우', '이때'];
  let prevLead = '';
  const cleaned = paragraphs.map((p) => {
    let out = p;
    for (const word of leadWords) {
      const re = new RegExp('^' + word + '[, ]*');
      if (re.test(out)) {
        if (prevLead === word) out = out.replace(re, '');
        prevLead = word;
        return out.trim();
      }
    }
    prevLead = '';
    return out.trim();
  });
  t = cleaned.join('\\n\\n');
  t = t.replace(/\\n{3,}\\|/g, '\\n\\n|');
  t = t.replace(/\\|\\n{3,}/g, '|\\n\\n');
  t = t.replace(/\\n{3,}\\*/g, '\\n\\n*');
  return t.trim();
}

function diversifyStyle(body, industry) {
  if (!body) return body;
  const aiPatterns = [
    /이(?:에 대해|를 통해) (?:알아보|살펴보)겠습니다[.!]?/g,
    /(?:지금|이제)부터 (?:알아보|살펴보)(?:겠습니다|도록 하겠습니다)[.!]?/g,
    /이번 (?:글|포스팅)에서는 .{5,30}(?:정리|소개|안내)(?:해 보겠습니다|했습니다|합니다)[.!]?/g
  ];
  for (const pat of aiPatterns) { body = body.replace(pat, ''); }
  body = body.replace(/\\n{3,}/g, '\\n\\n').trim();
  return body;
}

const industry = (() => {
  try { return $items('가중치부여1')[0]?.json?.industry || ''; } catch { return ''; }
})();

const parsed = extractJson($json);
let body = parsed?.bodyMarkdown || '';
body = normalizeBody(body);
body = diversifyStyle(body, industry);

const result = { ...(parsed || $json), bodyMarkdown: body };
return result;
`.trim();

// ── 2. AI 이미지 프롬프트 준비 ───────────────────────────────────────────
// 단락별쪼개기 output의 sections + 가중치_키워드1 isCardNewsIndustry 평가
const IMG_PREP_CODE = `
function safeItems(n) { try { return $items(n) || []; } catch { return []; } }

const sections = $json.sections || [];
const isCardNews = safeItems('키워드_업종 매칭1')[0]?.json?.isCardNewsIndustry || false;

const prompt = \`다음은 블로그 섹션 데이터이다.

\${JSON.stringify(sections, null, 2)}

이 블로그의 업종은 카드뉴스 업종인가: \${isCardNews}

각 섹션(h2 + body)에 맞는 이미지 생성 프롬프트를 작성하라.

────────────────────────
[업종별 이미지 모드 분기]

isCardNewsIndustry가 true인 경우:
- 모든 idx에 대해 카드뉴스 인포그래픽 프롬프트를 작성한다.

isCardNewsIndustry가 false인 경우:
- idx === 1: 카드뉴스 인포그래픽 프롬프트
- idx > 1: 실사 이미지 프롬프트

────────────────────────
[카드뉴스 규칙]

idx === 1 (모든 업종 공통 — 실사 배경 + 카드뉴스 오버레이):
- 배경은 반드시 실사 사진이어야 한다
  * 업종과 관련된 현장 사진 (사무실, 매장, 작업 현장, 상담실 등)
  * 한국 실제 환경 기반, 자연광, 약간 흐린 배경 (얕은 심도)
  * 배경 사진이 전체 이미지의 바탕을 채운다
- 배경 위에 반투명 오버레이 패널이 올라간다
  * 반투명 흰색 또는 네이비 패널 (opacity 70~85%)
  * 패널 안에 제목 텍스트와 핵심 키워드가 배치된다
- 텍스트 규칙:
  * 모든 텍스트는 반드시 한국어
  * 글 전체 제목을 큰 볼드 텍스트로 배치
  * 3~4개 핵심 키워드 또는 포인트를 작은 텍스트로 나열
  * 텍스트는 선명하고 깨지지 않아야 한다
  * 폰트는 깔끔한 고딕체
  * Headline, Sub, Micro copy 같은 영어 라벨 절대 금지
- 전체 느낌: 실제 블로그 썸네일이나 카드뉴스 커버처럼 보여야 한다
  * 실사 배경 + 텍스트 오버레이 = 전문적이면서도 현실감 있는 이미지
  * 순수 인포그래픽(단색 배경)이 아니다

카드뉴스 업종 전용 (idx >= 2, isCardNewsIndustry가 true인 경우):
- 인포그래픽 스타일 카드
- 밝은 배경색 기반 (화이트, 라이트 그레이, 연한 블루)
- 상단에 H2 제목을 큰 볼드 한국어로 배치
- 본문 영역에 2~3개 블록 (플랫 아이콘 + 핵심 텍스트 1~2줄)
- 아이콘: 플랫 벡터 아이콘 (체크마크, 전구, 톱니바퀴, 화살표 등)
- 색상: 네이비/다크블루 + 화이트 팔레트
- 각 블록은 카드/박스 형태로 구분
- 텍스트는 반드시 한국어, 선명, 깨지지 않게

────────────────────────
[실사 이미지 규칙 (카드뉴스 업종이 아닌 경우 idx > 1에만 적용)]

반드시 실제 촬영된 사진처럼 보여야 한다 (AI 느낌 완전 제거)

한국 실제 환경 기반 (실존 가능한 공간 구조, 인테리어, 거리, 사무실 등)

등장 인물이 있을 경우 반드시 한국인

포즈는 연출된 느낌이 아닌 자연스러운 순간 포착 (candid shot)

피부는 깨끗하고 건강한 상태로 표현

촬영/카메라 조건:
Sony A7R IV / Canon EOS R5 수준의 풀프레임 카메라
렌즈: 35mm 또는 50mm 고정렌즈
조리개: f/1.8 ~ f/2.8
얕은 심도, 자연광 기반
필름 그레인 약하게 포함
RAW 사진 느낌

색감: 블루 / 그레이 / 화이트 기반의 절제된 톤

텍스트 절대 포함 금지
사람이 실제로 존재하는 상황처럼 구성
행동 중심 장면 (회의, 작업, 상담, 일상 등)
자연스러운 순간 포착

────────────────────────
[절대 금지 요소 - 공통]

CG 느낌 / 3D 렌더 느낌 금지
텍스트 왜곡 / 깨짐 금지
손가락 이상 / 비정상 구조 금지

────────────────────────
출력 규칙:

출력은 반드시 JSON 배열 형태

[
{ "idx": 1, "prompt": "200자 이상의 상세한 프롬프트", "mode": "card" },
{ "idx": 2, "prompt": "200자 이상", "mode": "card" 또는 "photo" }
]

mode 필드:
- "card": 카드뉴스 인포그래픽 (nano-banana-pro로 생성됨)
- "photo": 실사 이미지 (gpt-image-1.5로 생성됨)

카드뉴스 프롬프트에는 반드시 포함:
1. 카드의 전체 레이아웃 설명
2. 상단 제목 텍스트 (한국어 원문 그대로)
3. 각 블록의 아이콘 + 텍스트 내용 (한국어 원문 그대로)
4. 스타일 키워드 (flat design, infographic, business card news, clean layout)

실사 프롬프트에는 반드시 포함:
1. 구체적 장면 묘사
2. 카메라/조명 조건
3. "no text, no typography, no letters, no watermark, no logo"

다른 텍스트 절대 출력 금지\`;

return { json: { ...$json, _img_prompt: prompt } };
`.trim();

// ── 3. imageSkip ──────────────────────────────────────────────────────
const IMAGESKIP_CODE = `
const slots = $json.structured?.image_layout?.slots || {};
const fileMap = $json.fileMap || {};
const imageBySection = {};
for (const key in slots) {
  const ids = slots[key];
  if (Array.isArray(ids) && ids.length > 0) {
    imageBySection[key] = ids.map(id => fileMap[id]).filter(Boolean);
  }
}
return { json: { ...$json, imageBySection, skipImageGen: true } };
`.trim();

// ── 4. 이미지url매칭 ─────────────────────────────────────────────────
// imageBySection → 섹션 순서대로 imageUrls 배열로 변환
// Collect Success Images 우회하여 직접 매핑6로
const IMG_URL_MATCH_CODE = `
const imageBySection = $json.imageBySection || {};
const allUrls = [];
for (const key of Object.keys(imageBySection)) {
  const urls = imageBySection[key];
  if (Array.isArray(urls)) {
    urls.forEach(url => { if (url) allUrls.push(url); });
  }
}
return { json: { ...$json, imageUrls: allUrls, imageMeta: allUrls.map((url, i) => ({ idx: i + 1, imageUrl: url, failed: false, finalStatus: 'SKIPPED' })) } };
`.trim();

// ── 5. 매핑6 (imageSkip path → Merge) ───────────────────────────────
const MAPPING6_CODE = `
function safeItems(nodeName) {
  try { return $items(nodeName) || []; } catch { return []; }
}
const promptData = safeItems('단락별쪼개기')[0]?.json;
const memberData = safeItems('가중치_키워드1')[0]?.json || {};
return {
  json: {
    ...$json,
    bodyMarkdown: promptData?.rawParsed?.bodyMarkdown || '',
    summary: promptData?.rawParsed?.summary || promptData?.summary || '',
    title: promptData?.rawParsed?.title || promptData?.title || '',
    memberPk: memberData?.ctx?.member_pk || $json.memberPk || null,
    promptId: memberData?.ctx?.id || $json.promptId || null
  }
};
`.trim();

// ── 6. Collect Success Images (fix node name refs) ────────────────────
const COLLECT_CODE = `
function safeItems(nodeName) {
  try { return $items(nodeName) || []; } catch (e) { return []; }
}

function normItem(item) {
  const j = item?.json || item || {};
  return {
    ...j,
    idx: Number(j.idx ?? 999999),
    imageUrl: j.imageUrl ?? null,
    failed: Boolean(j.failed ?? !j.imageUrl),
    error: j.error ?? null,
    finalStatus: j.finalStatus ?? null
  };
}

const directItems = $input.all().map(normItem);
const pipelineItems = [
  ...safeItems('Extract Image1').map(normItem),
  ...safeItems('FAIL1').map(normItem)
];

let all = pipelineItems.length ? pipelineItems : directItems;
const byIdx = new Map();
for (const item of all) {
  if (!Number.isFinite(item.idx)) continue;
  const prev = byIdx.get(item.idx);
  if (!prev || (!prev.imageUrl && item.imageUrl)) {
    byIdx.set(item.idx, item);
  }
}

const expectedFromSplit = (() => {
  try {
    const splitItems = $('IMG - 분할1').all();
    if (Array.isArray(splitItems) && splitItems.length) return splitItems.length;
  } catch (e) {}
  return byIdx.size;
})();

const imageUrls = [];
const imageMeta = [];
for (let idx = 1; idx <= expectedFromSplit; idx++) {
  const item = byIdx.get(idx) || { idx, imageUrl: null, failed: true, error: 'missing image result', finalStatus: 'MISSING' };
  imageUrls.push(item.imageUrl ?? null);
  imageMeta.push({
    idx,
    imageUrl: item.imageUrl ?? null,
    failed: item.failed ?? !item.imageUrl,
    error: item.error ?? null,
    finalStatus: item.finalStatus ?? null
  });
}

const missing = imageMeta.filter(x => !x.imageUrl).map(x => x.idx);

return [{
  json: {
    ...$input.first()?.json,
    imageUrls,
    imageMeta,
    imageGenerationSummary: {
      pipeline: 'single-fal-generate',
      expected: expectedFromSplit,
      collected: byIdx.size,
      success: imageUrls.filter(Boolean).length,
      missing
    }
  }
}];
`.trim();

// Syntax checks
const checks = {
  '스타일 랜덤화1': STYLE_CODE,
  'AI 이미지 프롬프트 준비': IMG_PREP_CODE,
  'imageSkip': IMAGESKIP_CODE,
  '이미지url매칭': IMG_URL_MATCH_CODE,
  '매핑6': MAPPING6_CODE,
  'Collect Success Images': COLLECT_CODE,
};
let hasError = false;
for (const [name, code] of Object.entries(checks)) {
  try { new Function(code); console.log(`✓ ${name}: Syntax OK`); }
  catch (e) { console.error(`✗ ${name}: SYNTAX ERROR:`, e.message); hasError = true; }
}
if (hasError) { process.exit(1); }

async function main() {
  const wf = await req('GET', `/api/v1/workflows/${WF_ID}`);
  if (!wf.nodes) { console.error('Fetch failed:', JSON.stringify(wf).substring(0, 200)); process.exit(1); }

  const nodes = wf.nodes;
  const conns = wf.connections;

  // ── Fix 스타일 랜덤화1 ──
  const s1 = nodes.find(n => n.name === '스타일 랜덤화1');
  if (!s1) { console.error('스타일 랜덤화1 not found'); process.exit(1); }
  s1.parameters.jsCode = STYLE_CODE;
  console.log('✓ 스타일 랜덤화1: updated');

  // ── Add AI 이미지 프롬프트 준비 node if missing ──
  const aiPrepExists = nodes.find(n => n.name === 'AI 이미지 프롬프트 준비');
  const daljNode = nodes.find(n => n.name === '단락별쪼개기');
  const aiAgentNode = nodes.find(n => n.name === 'AI Agent');

  if (!aiPrepExists) {
    const prepPos = daljNode ? [daljNode.position[0] + 224, daljNode.position[1]] : [25792, -3824];
    const newPrepNode = {
      id: 'ai-img-prep-001',
      name: 'AI 이미지 프롬프트 준비',
      type: 'n8n-nodes-base.code',
      typeVersion: 2,
      position: prepPos,
      parameters: { jsCode: IMG_PREP_CODE }
    };
    nodes.push(newPrepNode);
    console.log('✓ AI 이미지 프롬프트 준비: added');
  } else {
    aiPrepExists.parameters.jsCode = IMG_PREP_CODE;
    console.log('✓ AI 이미지 프롬프트 준비: updated');
  }

  // ── Fix AI Agent text → use $json._img_prompt ──
  if (aiAgentNode) {
    aiAgentNode.parameters.text = '={{ $json._img_prompt }}';
    console.log('✓ AI Agent text: fixed to ={{ $json._img_prompt }}');
  }

  // ── Add If node (단락별쪼개기 → If → [imageSkip | AI 이미지 프롬프트 준비]) ──
  const ifNode = nodes.find(n => n.name === 'If');
  if (!ifNode) {
    const ifPos = daljNode ? [daljNode.position[0] + 448, daljNode.position[1] - 64] : [26016, -3888];
    nodes.push({
      id: 'if-skip-img-001',
      name: 'If',
      type: 'n8n-nodes-base.if',
      typeVersion: 2,
      position: ifPos,
      parameters: {
        conditions: {
          options: { caseSensitive: true, leftValue: '', typeValidation: 'strict' },
          conditions: [{
            id: 'skip-check',
            leftValue: '={{ Object.keys($json.fileMap || {}).length }}',
            rightValue: 0,
            operator: { type: 'number', operation: 'gt' }
          }],
          combinator: 'and'
        }
      }
    });
    console.log('✓ If node: added');
  }

  // ── Add imageSkip node ──
  const imageSkipNode = nodes.find(n => n.name === 'imageSkip');
  if (!imageSkipNode) {
    const ifPos2 = daljNode ? [daljNode.position[0] + 672, daljNode.position[1] - 128] : [26240, -3952];
    nodes.push({
      id: 'imageskip-001',
      name: 'imageSkip',
      type: 'n8n-nodes-base.code',
      typeVersion: 2,
      position: ifPos2,
      parameters: { jsCode: IMAGESKIP_CODE }
    });
    console.log('✓ imageSkip: added');
  }

  // ── Add 이미지url매칭 node ──
  const imgUrlNode = nodes.find(n => n.name === '이미지url매칭');
  if (!imgUrlNode) {
    const pos = daljNode ? [daljNode.position[0] + 896, daljNode.position[1] - 128] : [26464, -3952];
    nodes.push({
      id: 'imgurl-match-001',
      name: '이미지url매칭',
      type: 'n8n-nodes-base.code',
      typeVersion: 2,
      position: pos,
      parameters: { jsCode: IMG_URL_MATCH_CODE }
    });
    console.log('✓ 이미지url매칭: added');
  }

  // ── Add 매핑6 node ──
  const mapping6Node = nodes.find(n => n.name === '매핑6');
  const mergeNode = nodes.find(n => n.name === 'Merge');
  if (!mapping6Node) {
    const pos = mergeNode ? [mergeNode.position[0] - 224, mergeNode.position[1] - 224] : [29536, -3196];
    nodes.push({
      id: 'mapping6-001',
      name: '매핑6',
      type: 'n8n-nodes-base.code',
      typeVersion: 2,
      position: pos,
      parameters: { jsCode: MAPPING6_CODE }
    });
    console.log('✓ 매핑6: added');
  }

  // ── Fix Collect Success Images ──
  const csNode = nodes.find(n => n.name === 'Collect Success Images');
  if (csNode) {
    csNode.parameters.jsCode = COLLECT_CODE;
    console.log('✓ Collect Success Images: fixed node name refs');
  }

  // ── Fix connections ──
  // 1. 단락별쪼개기 → If (not directly to AI 이미지 프롬프트 준비)
  conns['단락별쪼개기'] = { main: [[{ node: 'If', type: 'main', index: 0 }]] };

  // 2. If[true] → imageSkip, If[false] → AI 이미지 프롬프트 준비
  conns['If'] = {
    main: [
      [{ node: 'imageSkip', type: 'main', index: 0 }],
      [{ node: 'AI 이미지 프롬프트 준비', type: 'main', index: 0 }]
    ]
  };

  // 3. imageSkip → 이미지url매칭
  conns['imageSkip'] = { main: [[{ node: '이미지url매칭', type: 'main', index: 0 }]] };

  // 4. 이미지url매칭 → 매핑6
  conns['이미지url매칭'] = { main: [[{ node: '매핑6', type: 'main', index: 0 }]] };

  // 5. 매핑6 → Merge[0]
  conns['매핑6'] = { main: [[{ node: 'Merge', type: 'main', index: 0 }]] };

  // 6. AI 이미지 프롬프트 준비 → AI Agent
  conns['AI 이미지 프롬프트 준비'] = { main: [[{ node: 'AI Agent', type: 'main', index: 0 }]] };

  // 7. 매핑 → Merge[0] (generation path - already set, keep as-is)
  // Both 매핑 and 매핑6 feed Merge[0] - they're mutually exclusive via If

  console.log('\nPushing to n8n...');

  const result = await req('PUT', `/api/v1/workflows/${WF_ID}`, {
    name: wf.name,
    nodes,
    connections: conns,
    settings: wf.settings || {},
    staticData: wf.staticData || null
  });

  if (!result.id) {
    console.error('PUT ERROR:', JSON.stringify(result).substring(0, 500));
    process.exit(1);
  }

  console.log('\n=== VERIFICATION ===');

  // Check nodes exist
  const toCheck = ['스타일 랜덤화1', 'AI 이미지 프롬프트 준비', 'AI Agent', 'If', 'imageSkip', '이미지url매칭', '매핑6', 'Collect Success Images'];
  for (const name of toCheck) {
    const n = result.nodes.find(n => n.name === name);
    console.log(n ? `✓ ${name}: exists` : `✗ ${name}: MISSING`);
  }

  // Check critical connections
  const c = result.connections;
  const checks2 = [
    ['단락별쪼개기', 'If'],
    ['If', 'imageSkip (true)'],
    ['imageSkip', '이미지url매칭'],
    ['이미지url매칭', '매핑6'],
    ['AI 이미지 프롬프트 준비', 'AI Agent'],
    ['매핑', 'Merge'],
  ];
  console.log('\nConnections:');
  console.log('단락별쪼개기 ->', c['단락별쪼개기']?.main?.[0]?.map(d=>d.node));
  console.log('If[true] ->', c['If']?.main?.[0]?.map(d=>d.node));
  console.log('If[false] ->', c['If']?.main?.[1]?.map(d=>d.node));
  console.log('AI 이미지 프롬프트 준비 ->', c['AI 이미지 프롬프트 준비']?.main?.[0]?.map(d=>d.node));
  console.log('AI Agent text:', result.nodes.find(n=>n.name==='AI Agent')?.parameters?.text);
  console.log('imageSkip ->', c['imageSkip']?.main?.[0]?.map(d=>d.node));
  console.log('이미지url매칭 ->', c['이미지url매칭']?.main?.[0]?.map(d=>d.node));
  console.log('매핑6 ->', c['매핑6']?.main?.[0]?.map(d=>d.node));
  console.log('매핑 ->', c['매핑']?.main?.[0]?.map(d=>d.node));

  // Syntax check live codes
  const styleCheck = result.nodes.find(n => n.name === '스타일 랜덤화1');
  try { new Function(styleCheck?.parameters?.jsCode || ''); console.log('\n✓ 스타일 랜덤화1 live: OK'); }
  catch(e) { console.error('\n✗ 스타일 랜덤화1 live FAIL:', e.message); }

  console.log('\nDone.');
}

main().catch(console.error);
