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

// ── 1. H2 출력 파싱 ────────────────────────────────────────────────
// 검색의도_H2생성이 hasOutputParser:false 로 바뀌므로
// $json.output = LangChain agent raw string → JSON parse해서 구조 복원
const H2_PARSE_CODE = [
  "// 검색의도_H2생성 raw output → structured object",
  "const raw = String($json.output || '');",
  "let parsed = {};",
  "try {",
  "  const match = raw.match(/\\{[\\s\\S]*\\}/);",
  "  if (match) parsed = JSON.parse(match[0]);",
  "} catch(e) {}",
  "// output 구조 보장",
  "parsed.intent          = parsed.intent          || '';",
  "parsed.searchQuestions = parsed.searchQuestions || [];",
  "parsed.h2_outline      = parsed.h2_outline      || [];",
  "return { json: { output: parsed } };"
].join('\n');

// ── 2. 글생성 프롬프트 준비 ────────────────────────────────────────
// $json = KB 검색 output
// $items('프롬프트생성1') = 프롬프트생성1 output
// $items('H2 출력 파싱')  = H2 파싱 output  (검색의도_H2생성 대신)
const GL_PREP_CODE = [
  "function safeItems(n) { try { return $items(n) || []; } catch { return []; } }",
  "",
  "// KB context",
  "const kbContext = String($json.context || '');",
  "",
  "// 프롬프트생성1",
  "const p1json = safeItems('프롬프트생성1')[0]?.json || {};",
  "const sysPrompt  = String((p1json.input || [])[0]?.content || '');",
  "const userPrompt = String((p1json.input || [])[1]?.content || '');",
  "const meta       = p1json._meta || {};",
  "const toneGuide  = String(meta.toneGuide   || '');",
  "const actionStyle= String(meta.actionStyle || '');",
  "const expression = String(meta.expression  || '');",
  "const postRole   = String(meta.postRole    || '');",
  "const brandName  = String(meta.brandName   || '');",
  "",
  "// H2 출력 파싱 (hasOutputParser:false 로 바뀐 검색의도_H2생성 결과)",
  "const h2json   = safeItems('H2 출력 파싱')[0]?.json?.output || {};",
  "const intent   = String(h2json.intent || '');",
  "const questions= (h2json.searchQuestions || []).join('\\n- ');",
  "const h2list   = (h2json.h2_outline    || []).join('\\n');",
  "",
  "const _gl_full_text = [",
  "  '[MEMBER KB CONTEXT — 고객 업로드 자료]',",
  "  kbContext,",
  "  '',",
  "  '[자료 우선순위 규칙]',",
  "  'KB 자료가 있는 경우:',",
  "  '- 업체 실제 서비스·절차·조건·수치는 KB 자료에서 그대로 사용한다 (최우선)',",
  "  '- [키워드 심층 조사] (Perplexity)는 KB에 없는 업종 일반 지식 보완용으로만 사용한다',",
  "  '- KB와 Perplexity 충돌 시 KB 우선',",
  "  '',",
  "  'KB 자료가 없는 경우:',",
  "  '- [키워드 심층 조사] (Perplexity)를 주 정보 자료로 사용한다',",
  "  '- 브랜드·키워드·프롬프트 컨텍스트를 기반으로 작성한다',",
  "  '- Perplexity에 없는 수치·사례는 만들어내지 않는다',",
  "  '',",
  "  '공통: KB, 벡터, 임베딩 같은 내부 단어는 본문에 절대 노출하지 않는다',",
  "  '',",
  "  '--------------------------------',",
  "  '',",
  "  sysPrompt,",
  "  '',",
  "  userPrompt,",
  "  '',",
  "  '[검색 의도]',",
  "  intent,",
  "  '',",
  "  '--------------------------------',",
  "  '',",
  "  '[검색 질문]',",
  "  '- ' + questions,",
  "  '',",
  "  '--------------------------------',",
  "  '',",
  "  '[H2 구조]',",
  "  '다음 H2 구조를 반드시 사용한다.',",
  "  '',",
  "  h2list,",
  "  '',",
  "  '규칙',",
  "  '- H2 순서를 변경하지 않는다',",
  "  '- 새로운 H2를 생성하지 않는다',",
  "  '- 각 H2는 반드시 이번 글의 주제와 직접 연결되게 작성한다',",
  "  '- 각 H2 첫 문장은 해당 H2가 왜 필요한지 바로 드러나야 한다',",
  "  '- 앞 H2에서 던진 문제를 다음 H2에서 자연스럽게 이어받는다',",
  "  '',",
  "  '[도입부 훅 규칙 - 매우 중요]',",
  "  '⚠️ 아래 괄호 안 예시 문장은 유형 이해용이다. 이 문장을 그대로 쓰거나 업종명만 끼워 넣지 말 것. 반드시 현재 키워드·업종·업체에 맞는 새로운 문장으로 작성한다.',",
  "  '- situation: 독자가 처한 구체 상황 묘사',",
  "  '- mistake: 흔한 실수를 짚으며 시작',",
  "  '- question: 독자의 내면 질문으로 시작',",
  "  '- contrast: 기대와 현실 차이로 시작',",
  "  '',",
  "  '도입부 금지:',",
  "  '- \"OO에 대해 알아보겠습니다\" / 목차·구조 미리 안내',",
  "  '도입부는 독자가 \"이건 나한테 필요한 글이다\" 라고 느끼게 해야 한다.',",
  "  '',",
  "  '[톤 적용 규칙]',",
  "  '현재 고객 톤은 \"' + toneGuide + '\" 이다.',",
  "  '현재 action_style은 \"' + actionStyle + '\" 이다.',",
  "  '현재 expression은 \"' + expression + '\" 이다.',",
  "  '',",
  "  '이 톤은 참고 사항이 아니라 실제 문장 스타일에 반드시 반영해야 하는 핵심 규칙이다.',",
  "  '- 설명문이 아니라 조언문에 가깝게 써야 한다',",
  "  '- 독자가 읽을 때 \"알려준다\"보다 \"옆에서 짚어준다\"는 느낌이 나야 한다',",
  "  '',",
  "  '[role 차등 규칙]',",
  "  '현재 role은 ' + postRole + ' 이다.',",
  "  '',",
  "  '- promo: 정보 밀도와 판단 기준이 가장 또렷해야 한다',",
  "  '- info: 서비스/제공 주체가 왜 한 번 더 비교해볼 만한지 자연스럽게 남아야 한다. 정확한 브랜드명 \"' + brandName + '\"은 필요할 때만 1~3회 반영',",
  "  '- plusA: 문제 제기, 흥미, 오해 방지, 막히는 지점을 더 선명하게 다룬다',",
  "  '',",
  "  '[제목 규칙]',",
  "  '- title에는 반드시 위 컨텍스트에서 제시된 메인 키워드를 그대로 포함한다',",
  "  '- 금지: \"~완벽 가이드\", \"~총정리\", \"~의 중요성\", \"~에 대해 알아보자\"',",
  "  '',",
  "  '[본문 작성 원칙]',",
  "  '- 단순 정의 설명형 글로 쓰지 않는다',",
  "  '- 각 섹션에는 최소 1개 이상 포함: 막히는 지점 / 실수하기 쉬운 부분 / 비교 기준 / 확인 질문 / 먼저 볼 조건',",
  "  '- 추상 표현 사용 시 반드시 다음 문장에서 구체 조건/상황 풀어줌',",
  "  '- 최소 1개의 Markdown 표 포함',",
  "  '- 볼드(**텍스트**) 강조: 섹션당 1~3회',",
  "  '- 이모지: ✅ 📌 ⚠️ 💡 만 허용, 글 전체 최대 3개',",
  "  '',",
  "  '[브랜드 반영 규칙]',",
  "  '- 정확한 브랜드명은 \"' + brandName + '\" 이다',",
  "  '- 브랜드는 독립 소개 문단처럼 넣지 않는다',",
  "  '- 판단 기준·차이 설명·진행 흐름 안에서만 자연스럽게 반영한다',",
  "  '- 모든 role에서 브랜드 또는 서비스 존재가 최소 1회 이상 자연스럽게 드러나야 한다',",
  "  '',",
  "  '[출력 구조]',",
  "  '반드시 아래 JSON 구조를 사용한다.',",
  "  '',",
  "  '{',",
  "  '  \"title\": \"\",',",
  "  '  \"summary\": \"\",',",
  "  '  \"bodyMarkdown\": \"\",',",
  "  '  \"cta\": { \"text\": \"\", \"urlOrContact\": \"\" },',",
  "  '  \"hashtags\": [],',",
  "  '  \"selectedKeywords\": []',",
  "  '}',",
  "  '',",
  "  '규칙',",
  "  '- bodyMarkdown 안에는 반드시 \"## \" H2만 사용한다',",
  "  '- 검색의도_H2생성에서 받은 H2를 bodyMarkdown 안에 그대로 순서대로 넣는다',",
  "  '- 최소 1개의 Markdown 표를 포함한다',",
  "  '- selectedKeywords는 반드시 입력 selectedKeywords를 유지한다'",
  "].join('\\n');",
  "",
  "return { json: { ...$json, _gl_full_text } };"
].join('\n');

// ── 3. 글검수 프롬프트 준비 ────────────────────────────────────────
const GLCHECK_PREP_CODE = [
  "function safeItems(n) { try { return $items(n) || []; } catch { return []; } }",
  "",
  "const glOutput = String($json.output || '');",
  "",
  "const meta = safeItems('프롬프트생성1')[0]?.json?._meta || {};",
  "const postRole    = String(meta.postRole || '');",
  "const mainKeyword = String((meta.selectedKeywords || [])[0] || '');",
  "const toneGuide   = String(meta.toneGuide   || '');",
  "const actionStyle = String(meta.actionStyle || '');",
  "const expression  = String(meta.expression  || '');",
  "const brandName   = String(meta.brandName   || '');",
  "",
  "const _glcheck_full_text = [",
  "  '너는 \"블로그 글 구조 및 정합성 검수 에디터\"다.',",
  "  '',",
  "  '아래 JSON 블로그 글을 검수한다.',",
  "  '',",
  "  glOutput,",
  "  '',",
  "  'role : ' + postRole,",
  "  'mainKeyword : ' + mainKeyword,",
  "  'tone : ' + toneGuide,",
  "  'actionStyle : ' + actionStyle,",
  "  'expression : ' + expression,",
  "  'brandName : ' + brandName,",
  "  '',",
  "  '────────────────────────',",
  "  '',",
  "  '[목표]',",
  "  '',",
  "  '이 단계의 목적은 아래 6개뿐이다.',",
  "  '',",
  "  '1. 주제와 직접 연결되지 않는 섹션 제거 또는 약한 보정',",
  "  '2. H2와 본문 연결 확인',",
  "  '3. Markdown 안정화',",
  "  '4. 키워드/구조 누락 보정',",
  "  '5. role 성격이 약해졌으면 최소 범위에서 복원',",
  "  '6. 고객이 제출한 tone이 약해졌다면 최소 범위에서 복원',",
  "  '',",
  "  '문체를 새로 쓰는 단계가 아니다.',",
  "  '사람형 문장을 다시 반듯하게 정리하지 않는다.',",
  "  '',",
  "  '────────────────────────',",
  "  '',",
  "  '[절대 원칙]',",
  "  '',",
  "  '- 새로운 정보 생성 금지',",
  "  '- 사례 생성 금지',",
  "  '- 수치 생성 금지',",
  "  '- H2 순서 변경 금지',",
  "  '- 전체 글을 다시 쓰지 말 것',",
  "  '- 이미 자연스러운 짧은 문장, 짧은 문단, 질문형 문장은 유지할 것',",
  "  '- 이 단계 때문에 글이 더 매끈한 안내문처럼 변하면 안 된다',",
  "  '- 살아 있는 문장보다 정리된 문장이 많아지면 실패다',",
  "  '- 고객이 제출한 tone이 약해졌다면 최소 범위에서 복원한다',",
  "  '',",
  "  '────────────────────────',",
  "  '',",
  "  '[검수 기준]',",
  "  '',",
  "  '1. 각 H2가 이번 글 주제와 직접 연결되는가',",
  "  '2. 각 H2 첫 문장이 해당 섹션의 판단 포인트를 드러내는가',",
  "  '3. 뒤쪽 섹션이 일반론으로 흐르지 않는가',",
  "  '4. selectedKeywords가 title/summary/본문/H2에 자연스럽게 반영됐는가',",
  "  '5. bodyMarkdown 구조가 깨지지 않았는가',",
  "  '6. 표가 최소 1개 있는가',",
  "  '7. 현재 role의 성격이 실제 결과에서 느껴지는가',",
  "  '8. 브랜드 또는 서비스가 자연스럽게 최소 1회 이상 반영됐는가',",
  "  '9. 여러 H2 섹션의 첫 문장 길이와 시작 방식이 지나치게 비슷하지 않은가',",
  "  '10. 모든 섹션이 같은 전개 패턴으로 정리돼 있지 않은가',",
  "  '11. 긴 문단만 반복되어 모바일에서 훑어보기 어려운 구간이 없는가',",
  "  '12. 볼드가 과하지 않으면서도 핵심 판단 지점을 드러내는가',",
  "  '13. 고객이 제출한 tone이 실제 결과 문장에 반영되어 있는가',",
  "  '14. 설명문처럼만 읽히지 않고 짚어주고 안내하는 어조가 살아 있는가',",
  "  '',",
  "  '────────────────────────',",
  "  '',",
  "  '[출력 구조]',",
  "  '반드시 원본과 동일한 JSON 구조로 출력한다.',",
  "  '{',",
  "  '  \"title\": \"\",',",
  "  '  \"summary\": \"\",',",
  "  '  \"bodyMarkdown\": \"\",',",
  "  '  \"cta\": { \"text\": \"\", \"urlOrContact\": \"\" },',",
  "  '  \"hashtags\": [],',",
  "  '  \"selectedKeywords\": []',",
  "  '}'",
  "].join('\\n');",
  "",
  "return { json: { ...$json, _glcheck_full_text } };"
].join('\n');

// ── 4. AI 이미지 프롬프트 준비 ────────────────────────────────────
const AI_PREP_CODE = [
  "const sectionsJson = JSON.stringify($json.sections || [], null, 2);",
  "",
  "const _ai_prompt = [",
  "  '다음은 블로그 섹션 데이터이다.',",
  "  '',",
  "  sectionsJson,",
  "  '',",
  "  '각 섹션(h2 + body)에 맞는 초고화질 이미지 생성 프롬프트를 작성하라.',",
  "  '',",
  "  '────────────────────────',",
  "  '[절대적인 규칙]',",
  "  '사람은 절대 표정이 과하거나 행동이 과하지 않아야 한다',",
  "  '자연스러워야하고 반드시 모든 이미지는 주제와 일관성이 있어야 한다',",
  "  '어떤 이미지든 ai스럽지 않고 자연스러워야한다',",
  "  '자연광으로 자연스럽게',",
  "  '실사 느낌이 날수 있게',",
  "  '',",
  "  '────────────────────────',",
  "  '[전역 공통 조건 – 모든 이미지에 적용]',",
  "  '',",
  "  '한국 배경',",
  "  '등장 인물이 사람일 경우 반드시 한국인',",
  "  '등장 인물은 사람이 아니어도 무방 (동물/사물 가능)',",
  "  'ultra realistic photography',",
  "  '자연광 기반 조명',",
  "  '전문적이고 신뢰감 있는 분위기',",
  "  '상업용 고급 블로그 이미지 스타일',",
  "  'magazine cover level impact',",
  "  '블루, 그레이, 화이트 중심의 절제된 톤',",
  "  '포인트 컬러 최소 사용',",
  "  '블로그 섹션 데이터의 의미를 절대 벗어나지 말 것',",
  "  '',",
  "  '────────────────────────',",
  "  '[카드뉴스 적용 범위 규칙]',",
  "  '',",
  "  'idx === 1 인 첫 번째 섹션에만 카드뉴스 구조를 적용한다.',",
  "  'idx > 1 인 모든 섹션은 카드뉴스를 사용하지 않는다.',",
  "  'idx > 1 인 경우:',",
  "  '이미지 위 텍스트 패널을 절대 넣지 않는다.',",
  "  '순수 고급 실사 블로그 이미지로 구성한다.',",
  "  '이미지에 어떤 텍스트도 포함하지 않는다.',",
  "  '',",
  "  '────────────────────────',",
  "  '[idx === 1 전용: 카드뉴스 이미지 규칙]',",
  "  '',",
  "  '카드뉴스 형태, 카드 패널은 이미지 정중앙 배치, 중앙 80% 영역을 카드 패널이 차지',",
  "  '카드 외부 배경은 의도적으로 흐리게 처리',",
  "  '',",
  "  '■ 카드 내부 텍스트 구성 (반드시 준수)',",
  "  '카드 패널 안에는 최대 3개 텍스트 블록만 배치:',",
  "  'Headline (1줄, 24자 이내) / Sub (1줄, 30자 이내) / Micro copy (최대 2줄, 각 줄 25자 이내)',",
  "  'body 전체를 카드에 옮기지 마라. 반드시 요약·선별·발췌해서 사용한다.',",
  "  '자연스러운 한국어 문장이어야 한다. 비문, 조사 생략, 단어 절단 금지',",
  "  '',",
  "  '────────────────────────',",
  "  '[idx > 1 전용: 일반 블로그 이미지 규칙]',",
  "  '',",
  "  'ultra realistic photography, 자연광 기반, 한국 배경',",
  "  '35mm 또는 50mm 렌즈, 전문적·신뢰감·안정감',",
  "  '이미지에 어떤 텍스트도 포함하지 않는다',",
  "  '',",
  "  '────────────────────────',",
  "  '[출력 형식 — 반드시 준수]',",
  "  '',",
  "  '반드시 아래 JSON 배열만 출력한다. 설명, 마크다운, 코드블록 금지.',",
  "  '[',",
  "  '  { \"idx\": 1, \"prompt\": \"영문 이미지 생성 프롬프트\" },',",
  "  '  { \"idx\": 2, \"prompt\": \"영문 이미지 생성 프롬프트\" }',",
  "  ']',",
  "  '',",
  "  '규칙',",
  "  '- idx는 섹션 번호 (1부터 시작)',",
  "  '- prompt는 반드시 영문으로 작성',",
  "  '- JSON 배열 외 다른 텍스트 출력 금지',",
  "  '- ```json ``` 코드블록 감싸기 금지'",
  "].join('\\n');",
  "",
  "return { json: { ...$json, _ai_prompt } };"
].join('\n');

// 문법 검사
for (const [name, code] of [
  ['H2 출력 파싱',        H2_PARSE_CODE],
  ['글생성 프롬프트 준비', GL_PREP_CODE],
  ['글검수 프롬프트 준비', GLCHECK_PREP_CODE],
  ['AI 이미지 프롬프트 준비', AI_PREP_CODE],
]) {
  try { new Function(code); console.log(name + ': Syntax OK'); }
  catch(e) { console.error(name + ': SYNTAX ERROR', e.message); process.exit(1); }
}

async function main() {
  const wf = await req('GET', `/api/v1/workflows/${WF_ID}`);
  if (!wf.nodes) { console.error('Fetch error:', JSON.stringify(wf).substring(0,200)); process.exit(1); }

  const nodes = wf.nodes;
  const conns = wf.connections;

  // 참조 노드
  const h2Node      = nodes.find(n => n.name === '검색의도_H2생성');
  const kbNode      = nodes.find(n => n.name === 'KB 검색');
  const glNode      = nodes.find(n => n.name === '글생성');
  const glcheckNode = nodes.find(n => n.name === '글검수');
  const aiAgentNode = nodes.find(n => n.name === 'AI Agent');
  const ifNode      = nodes.find(n => n.name === 'If');

  if (!h2Node || !kbNode || !glNode || !glcheckNode || !aiAgentNode || !ifNode) {
    const missing = ['검색의도_H2생성','KB 검색','글생성','글검수','AI Agent','If']
      .filter(n => !nodes.find(nd=>nd.name===n));
    console.error('Missing nodes:', missing); process.exit(1);
  }

  // 기존 prep 노드 제거 (중복 방지)
  const prepNames = ['H2 출력 파싱','글생성 프롬프트 준비','글검수 프롬프트 준비','AI 이미지 프롬프트 준비'];
  const cleanedNodes = nodes.filter(n => !prepNames.includes(n.name));

  // ── (A) 검색의도_H2생성: hasOutputParser 해제 ──────────────────
  const h2NodeClean = cleanedNodes.find(n => n.name === '검색의도_H2생성');
  h2NodeClean.parameters.hasOutputParser = false;
  // notice/needsFallback 등 extra params 그대로 유지, text는 이미 _h2_full_text

  // ── (B) 새 노드 4개 추가 ──────────────────────────────────────
  const newH2Parse = {
    id: 'h2-parse-001',
    name: 'H2 출력 파싱',
    type: 'n8n-nodes-base.code',
    typeVersion: 2,
    position: [h2Node.position[0] + 224, h2Node.position[1]],
    parameters: { jsCode: H2_PARSE_CODE }
  };
  const newGlPrep = {
    id: 'gl-prompt-prep-001',
    name: '글생성 프롬프트 준비',
    type: 'n8n-nodes-base.code',
    typeVersion: 2,
    position: [kbNode.position[0] + 224, kbNode.position[1]],
    parameters: { jsCode: GL_PREP_CODE }
  };
  const newGlcheckPrep = {
    id: 'glcheck-prep-001',
    name: '글검수 프롬프트 준비',
    type: 'n8n-nodes-base.code',
    typeVersion: 2,
    position: [glcheckNode.position[0] - 224, glcheckNode.position[1]],
    parameters: { jsCode: GLCHECK_PREP_CODE }
  };
  const newAIPrep = {
    id: 'ai-prompt-prep-001',
    name: 'AI 이미지 프롬프트 준비',
    type: 'n8n-nodes-base.code',
    typeVersion: 2,
    position: [aiAgentNode.position[0] - 224, aiAgentNode.position[1]],
    parameters: { jsCode: AI_PREP_CODE }
  };

  cleanedNodes.push(newH2Parse, newGlPrep, newGlcheckPrep, newAIPrep);

  // ── (C) 글생성, 글검수 text 파라미터 설정 ──────────────────────
  const glNodeClean      = cleanedNodes.find(n => n.name === '글생성');
  const glcheckNodeClean = cleanedNodes.find(n => n.name === '글검수');
  const aiAgentClean     = cleanedNodes.find(n => n.name === 'AI Agent');
  glNodeClean.parameters.text      = '={{ $json._gl_full_text }}';
  glcheckNodeClean.parameters.text = '={{ $json._glcheck_full_text }}';
  aiAgentClean.parameters.text     = '={{ $json._ai_prompt }}';

  // ── (D) 연결 재구성 ────────────────────────────────────────────
  // 기존 prep 연결 제거
  for (const n of prepNames) delete conns[n];

  // 검색의도_H2생성 → H2 출력 파싱
  conns['검색의도_H2생성'] = {
    main: [[{ node: 'H2 출력 파싱', type: 'main', index: 0 }]]
  };

  // H2 출력 파싱 → KB 검색
  conns['H2 출력 파싱'] = {
    main: [[{ node: 'KB 검색', type: 'main', index: 0 }]]
  };

  // KB 검색 → 글생성 프롬프트 준비
  conns['KB 검색'] = {
    main: [[{ node: '글생성 프롬프트 준비', type: 'main', index: 0 }]]
  };

  // 글생성 프롬프트 준비 → 글생성
  conns['글생성 프롬프트 준비'] = {
    main: [[{ node: '글생성', type: 'main', index: 0 }]]
  };

  // 글생성 → 글검수 프롬프트 준비
  conns['글생성'] = {
    main: [[{ node: '글검수 프롬프트 준비', type: 'main', index: 0 }]]
  };

  // 글검수 프롬프트 준비 → 글검수
  conns['글검수 프롬프트 준비'] = {
    main: [[{ node: '글검수', type: 'main', index: 0 }]]
  };

  // If → output0: imageSkip (그대로), output1: AI 이미지 프롬프트 준비
  const ifConns = conns['If']?.main || [];
  // output0 (imageSkip) 유지, output1 (AI Agent → AI 이미지 프롬프트 준비)
  const output0 = ifConns[0] || [];
  const output1 = (ifConns[1] || []).map(d =>
    d.node === 'AI Agent' ? { ...d, node: 'AI 이미지 프롬프트 준비' } : d
  );
  // If output1에 AI 이미지 프롬프트 준비가 없으면 추가
  if (!output1.find(d => d.node === 'AI 이미지 프롬프트 준비')) {
    output1.push({ node: 'AI 이미지 프롬프트 준비', type: 'main', index: 0 });
  }
  conns['If'] = { main: [output0, output1] };

  // AI 이미지 프롬프트 준비 → AI Agent
  conns['AI 이미지 프롬프트 준비'] = {
    main: [[{ node: 'AI Agent', type: 'main', index: 0 }]]
  };

  // ── (E) PUT ────────────────────────────────────────────────────
  const result = await req('PUT', `/api/v1/workflows/${WF_ID}`, {
    name: wf.name,
    nodes: cleanedNodes,
    connections: conns,
    settings: wf.settings || {},
    staticData: wf.staticData || null
  });

  if (!result.id) {
    console.error('PUT ERROR:', JSON.stringify(result).substring(0, 500));
    process.exit(1);
  }

  console.log('\n=== SUCCESS ===\n');

  // ── 검증 ───────────────────────────────────────────────────────
  const checks = [
    { prep: 'H2 출력 파싱',           from: '검색의도_H2생성', to: 'KB 검색',        textNode: '글생성', textField: '_gl_full_text' },
    { prep: '글생성 프롬프트 준비',    from: 'KB 검색',        to: '글생성',          textNode: '글생성', textField: '_gl_full_text' },
    { prep: '글검수 프롬프트 준비',    from: '글생성',         to: '글검수',          textNode: '글검수', textField: '_glcheck_full_text' },
    { prep: 'AI 이미지 프롬프트 준비', from: 'If',             to: 'AI Agent',        textNode: 'AI Agent', textField: '_ai_prompt' },
  ];

  for (const { prep, from, to, textNode, textField } of checks) {
    const prepNode = result.nodes.find(n => n.name === prep);
    const fromConns = result.connections[from]?.main?.flat().map(d=>d.node) || [];
    const prepConns = result.connections[prep]?.main?.flat().map(d=>d.node) || [];
    const targetNode = result.nodes.find(n => n.name === textNode);

    let syntaxOk = false;
    try { new Function(prepNode?.parameters?.jsCode || ''); syntaxOk = true; } catch {}

    console.log('[' + prep + ']');
    console.log('  exists:', prepNode ? 'YES' : 'NO');
    console.log('  syntax:', syntaxOk ? 'OK' : 'FAIL');
    console.log('  ' + from + ' →', fromConns);
    console.log('  ' + prep + ' →', prepConns);
    if (textNode !== prep) {
      console.log('  ' + textNode + ' text:', (targetNode?.parameters?.text||'').substring(0,60));
    }
    console.log();
  }

  // 검색의도_H2생성 hasOutputParser 확인
  const h2check = result.nodes.find(n=>n.name==='검색의도_H2생성');
  console.log('검색의도_H2생성 hasOutputParser:', h2check?.parameters?.hasOutputParser);
}

main().catch(console.error);
