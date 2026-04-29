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

// ─── 글검수 프롬프트 준비 ───────────────────────────────────────
// $json = 글생성 output  {output: "```json\n{...}```"}
// $items('프롬프트생성1') = 프롬프트생성1
const GLCHECK_PREP_CODE = [
  "function safeItems(n) { try { return $items(n) || []; } catch { return []; } }",
  "",
  "// 글생성 raw output",
  "const glOutput = String($json.output || '');",
  "",
  "// 프롬프트생성1 meta",
  "const meta = safeItems('프롬프트생성1')[0]?.json?._meta || {};",
  "const postRole      = String(meta.postRole || '');",
  "const mainKeyword   = String((meta.selectedKeywords || [])[0] || '');",
  "const toneGuide     = String(meta.toneGuide || '');",
  "const actionStyle   = String(meta.actionStyle || '');",
  "const expression    = String(meta.expression || '');",
  "const brandName     = String(meta.brandName || '');",
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

// ─── AI Agent 이미지 프롬프트 준비 ────────────────────────────────
// $json = If 출력, $json.sections = 블로그 섹션 배열
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
  "  '이미지에 어떤 텍스트도 포함하지 않는다'",
  "].join('\\n');",
  "",
  "return { json: { ...$json, _ai_prompt } };"
].join('\n');

// 문법 검사
for (const [name, code] of [['글검수 prep', GLCHECK_PREP_CODE], ['AI Agent prep', AI_PREP_CODE]]) {
  try { new Function(code); console.log(name + ': Syntax OK'); }
  catch(e) { console.error(name + ': SYNTAX ERROR', e.message); process.exit(1); }
}

async function main() {
  const wf = await req('GET', `/api/v1/workflows/${WF_ID}`);
  if (!wf.nodes) { console.error('Fetch error'); process.exit(1); }

  const nodes = wf.nodes;
  const conns = wf.connections;

  const glcheckNode = nodes.find(n => n.name === '글검수');
  const aiAgentNode = nodes.find(n => n.name === 'AI Agent');
  const glsaengNode = nodes.find(n => n.name === '글생성');
  const ifNode      = nodes.find(n => n.name === 'If');

  // ── 글검수 프롬프트 준비 ──
  const prepGlcheck = {
    id: 'glcheck-prep-001',
    name: '글검수 프롬프트 준비',
    type: 'n8n-nodes-base.code',
    typeVersion: 2,
    position: [glcheckNode.position[0] - 224, glcheckNode.position[1]],
    parameters: { jsCode: GLCHECK_PREP_CODE }
  };

  // 글생성 → 글검수 프롬프트 준비
  if (conns['글생성']?.main) {
    conns['글생성'].main = conns['글생성'].main.map(output =>
      (output || []).map(dest =>
        dest.node === '글검수' ? { ...dest, node: '글검수 프롬프트 준비' } : dest
      )
    );
  }
  conns['글검수 프롬프트 준비'] = {
    main: [[{ node: '글검수', type: 'main', index: 0 }]]
  };
  glcheckNode.parameters.text = '={{ $json._glcheck_full_text }}';

  // ── AI Agent 이미지 프롬프트 준비 ──
  const prepAI = {
    id: 'ai-prompt-prep-001',
    name: 'AI 이미지 프롬프트 준비',
    type: 'n8n-nodes-base.code',
    typeVersion: 2,
    position: [aiAgentNode.position[0] - 224, aiAgentNode.position[1]],
    parameters: { jsCode: AI_PREP_CODE }
  };

  // If → AI 이미지 프롬프트 준비
  if (conns['If']?.main) {
    conns['If'].main = conns['If'].main.map(output =>
      (output || []).map(dest =>
        dest.node === 'AI Agent' ? { ...dest, node: 'AI 이미지 프롬프트 준비' } : dest
      )
    );
  }
  conns['AI 이미지 프롬프트 준비'] = {
    main: [[{ node: 'AI Agent', type: 'main', index: 0 }]]
  };
  aiAgentNode.parameters.text = '={{ $json._ai_prompt }}';

  nodes.push(prepGlcheck, prepAI);

  const result = await req('PUT', `/api/v1/workflows/${WF_ID}`, {
    name: wf.name, nodes, connections: conns,
    settings: wf.settings || {}, staticData: wf.staticData || null
  });

  if (result.id) {
    console.log('\nSUCCESS');

    // 검증
    const checks = [
      ['글검수 프롬프트 준비', '글검수', '글생성', '_glcheck_full_text'],
      ['AI 이미지 프롬프트 준비', 'AI Agent', 'If', '_ai_prompt'],
    ];

    for (const [prepName, targetName, fromName, field] of checks) {
      const prep = result.nodes.find(n => n.name === prepName);
      const target = result.nodes.find(n => n.name === targetName);
      try { new Function(prep?.parameters?.jsCode || ''); }
      catch(e) { console.error(prepName + ' syntax ERROR:', e.message); continue; }

      const fromConns = result.connections[fromName]?.main?.[0]?.map(d=>d.node) || [];
      const prepConns = result.connections[prepName]?.main?.[0]?.map(d=>d.node) || [];
      const textOk = target?.parameters?.text === '={{ $json.' + field + ' }}';

      console.log('\n[' + prepName + ']');
      console.log('  syntax: OK');
      console.log('  ' + fromName + ' →', fromConns);
      console.log('  ' + prepName + ' →', prepConns);
      console.log('  ' + targetName + ' text:', textOk ? 'OK' : 'WRONG: ' + target?.parameters?.text?.substring(0,50));
    }
  } else {
    console.error('ERROR:', JSON.stringify(result).substring(0, 500));
  }
}

main().catch(console.error);
