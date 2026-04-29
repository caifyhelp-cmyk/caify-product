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

// 문자열 배열 join 방식으로 작성 → 개행/따옴표 이스케이프 문제 없음
const jsCode = [
  "function safeItems(n) { try { return $items(n) || []; } catch { return []; } }",
  "",
  "const meta = ($json._meta) || {};",
  "const mainKeyword = (meta.selectedKeywords || [])[0] || '';",
  "const postRole = meta.postRole || '';",
  "",
  "const mkpItem = safeItems('마케팅 프로파일 파싱')[0];",
  "const mprofile = mkpItem?.json?._marketing_profile || {};",
  "const structureAngle = mprofile?.structure_bias?.angle || '';",
  "const targetReader = mprofile?.target_reader || '';",
  "const keyAngles = (mprofile?.key_angles || []).map((a, i) => (i+1) + '. ' + a).join('\\n');",
  "",
  "const parts = [",
  "  '너는 \"SEO 검색 의도 분석가\"다.',",
  "  '',",
  "  '입력된 키워드를 보고',",
  "  '1. 검색자가 실제로 궁금해하는 질문',",
  "  '2. 블로그 글 구조(H2)',",
  "  '를 만든다.',",
  "  '',",
  "  '--------------------------------',",
  "  '',",
  "  '입력 키워드',",
  "  '',",
  "  mainKeyword,",
  "  '',",
  "  'role',",
  "  postRole,",
  "  '',",
  "  '[이 업체 글 구조 방향]',",
  "  structureAngle,",
  "  '',",
  "  '[이 글의 독자]',",
  "  targetReader,",
  "  '',",
  "  '[이 업체만의 각도 — H2 구성 시 반드시 이 각도에서 파생할 것]',",
  "  keyAngles,",
  "  '',",
  "  '--------------------------------',",
  "  '',",
  "  '목표',",
  "  '',",
  "  '이 키워드로 검색했을 때 상위 블로그 글에서 자주 보이는',",
  "  '\"실제 판단형 / 현장형 / 질문형 구조\"를 만든다.',",
  "  '',",
  "  '정의 설명형 글이 아니라, 검색자가 실제로',",
  "  '- 어디서 막히는지',",
  "  '- 무엇을 먼저 봐야 하는지',",
  "  '- 어떤 차이를 구분해야 하는지',",
  "  '- 선택이나 진행 전에 무엇을 확인해야 하는지',",
  "  '를 다루는 구조여야 한다.',",
  "  '',",
  "  '질문과 H2가 정해진 예시 문구를 바꿔 쓴 것처럼 보이면 실패다.',",
  "  '같은 업종의 다른 글과 비교해도 질문 축과 제목 흐름이 최대한 겹치지 않게 만들어라.',",
  "  '',",
  "  '--------------------------------',",
  "  '',",
  "  'intent 규칙',",
  "  '- 1~2문장',",
  "  '- 검색자가 단순 정의를 찾는지, 비교/준비/의사결정/문제 해결을 하려는지 드러나야 한다',",
  "  '- role은 설명 주제가 아니라 강조 강도에만 반영한다',",
  "  '',",
  "  'searchQuestions 규칙',",
  "  '- 4~6개, 실제 검색자가 할 법한 질문만',",
  "  '- 정의형 질문만 반복 금지',",
  "  '- 최소 2개는 실제 의사결정 직전 질문',",
  "  '- 같은 질문 축 표현만 바꿔 반복 금지',",
  "  '- role: promo=판단기준/실수방지, info=필요성/차이, plusA=막히는이유/겉보기차이',",
  "  '',",
  "  'h2_outline 규칙',",
  "  '- 4~6개',",
  "  '- searchQuestions 기반이되 글 구조용 제목으로 재구성',",
  "  '- 설명형 제목만 반복 금지',",
  "  '- 최소 4개는 현장형/실수형/질문형/판단형',",
  "  '- \"왜 필요할까\", \"핵심 포인트\", \"중요한 이유\" 같은 추상 제목 금지',",
  "  '- 입력 키워드는 전체 H2 중 1~2개에만 포함',",
  "  '- 같은 톤 연속 금지',",
  "  '',",
  "  '[H2 스타일 — 최소 3종 혼합, 같은 패턴 연속 금지]',",
  "  '① 질문형: 독자 궁금증을 제목으로',",
  "  '② 반전형: 상식을 뒤집는 한 마디 (예: \"비싼 게 꼭 좋은 건 아닙니다\")',",
  "  '③ 숫자형: 수치로 신뢰와 호기심 (예: \"3가지만 바꿨더니 달라졌습니다\")',",
  "  '④ 공감형: 독자 상황을 그대로 제목에 (예: \"처음이라 뭘 물어봐야 할지 모르겠다면\")',",
  "  '⑤ 혜택형: 읽으면 뭘 얻는지 명확하게',",
  "  '⑥ 업체특화형: [이 업체만의 각도] 포인트를 제목에 녹인다',",
  "  '',",
  "  '[구조 비대칭]',",
  "  '- 매끈한 단계형 구조 금지 (개념→기준→비용→실수→체크리스트 패턴 금지)',",
  "  '- 최소 1개: 독자가 자기 상황 바로 점검하는 제목',",
  "  '- 최소 1개: 겉으로 비슷하지만 실제로 갈리는 차이를 다루는 제목',",
  "  '- 마지막 H2: 정리형보다 \"결정 직전 확인\" 성격 우선',",
  "  '',",
  "  '피해야 할 방향: 왜 필요할까 / 무엇을 알아야 할까 / 핵심 포인트 / 중요한 이유 / h2에 : 삽입',",
  "  '',",
  "  '출력: 반드시 JSON',",
  "  '{',",
  "  '  \"intent\": \"\",',",
  "  '  \"searchQuestions\": [],',",
  "  '  \"h2_outline\": []',",
  "  '}'",
  "];",
  "",
  "const _h2_full_text = parts.join('\\n');",
  "",
  "return { json: { ...$json, _h2_full_text } };"
].join('\n');

// 문법 검사
try { new Function(jsCode); console.log('Syntax OK'); }
catch(e) { console.error('Syntax ERROR:', e.message); process.exit(1); }

async function main() {
  const wf = await req('GET', `/api/v1/workflows/${WF_ID}`);
  if (!wf.nodes) { console.error('Fetch error'); process.exit(1); }

  const prep = wf.nodes.find(n => n.name === 'H2 프롬프트 준비');
  if (!prep) { console.error('Node not found'); process.exit(1); }

  prep.parameters.jsCode = jsCode;

  const payload = {
    name: wf.name, nodes: wf.nodes,
    connections: wf.connections,
    settings: wf.settings || {},
    staticData: wf.staticData || null
  };

  const result = await req('PUT', `/api/v1/workflows/${WF_ID}`, payload);
  if (result.id) {
    console.log('SUCCESS: H2 프롬프트 준비 jsCode 수정 완료');
    // 재확인
    const prep2 = result.nodes.find(n => n.name === 'H2 프롬프트 준비');
    try { new Function(prep2.parameters.jsCode); console.log('Live syntax check: OK'); }
    catch(e) { console.error('Live syntax check FAILED:', e.message); }
  } else {
    console.error('ERROR:', JSON.stringify(result).substring(0, 300));
  }
}

main().catch(console.error);
