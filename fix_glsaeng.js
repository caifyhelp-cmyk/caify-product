const https = require('https');
const fs = require('fs');

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

// 글생성 프롬프트 준비 노드 코드
// $json = KB 검색 output
// $items('프롬프트생성1') = 프롬프트생성1 output
// $items('검색의도_H2생성') = 검색의도_H2생성 output
const PREP_CODE = `
function safeItems(n) { try { return $items(n) || []; } catch { return []; } }

// KB context
const kbContext = String($json.context || '');

// 프롬프트생성1
const p1json = safeItems('프롬프트생성1')[0]?.json || {};
const sysPrompt  = String((p1json.input || [])[0]?.content || '');
const userPrompt = String((p1json.input || [])[1]?.content || '');
const meta       = p1json._meta || {};
const toneGuide  = String(meta.toneGuide  || '');
const actionStyle= String(meta.actionStyle|| '');
const expression = String(meta.expression || '');
const postRole   = String(meta.postRole   || '');
const brandName  = String(meta.brandName  || '');

// 검색의도_H2생성
const h2json   = safeItems('검색의도_H2생성')[0]?.json?.output || {};
const intent   = String(h2json.intent || '');
const questions= (h2json.searchQuestions || []).join('\\n- ');
const h2list   = (h2json.h2_outline    || []).join('\\n');

const _gl_full_text = [
  '[MEMBER KB CONTEXT — 고객 업로드 자료]',
  kbContext,
  '',
  '[자료 우선순위 규칙]',
  'KB 자료가 있는 경우:',
  '- 업체 실제 서비스·절차·조건·수치는 KB 자료에서 그대로 사용한다 (최우선)',
  '- [키워드 심층 조사] (Perplexity)는 KB에 없는 업종 일반 지식 보완용으로만 사용한다',
  '- KB와 Perplexity 충돌 시 KB 우선',
  '',
  'KB 자료가 없는 경우:',
  '- [키워드 심층 조사] (Perplexity)를 주 정보 자료로 사용한다',
  '- 브랜드·키워드·프롬프트 컨텍스트를 기반으로 작성한다',
  '- Perplexity에 없는 수치·사례는 만들어내지 않는다',
  '',
  '공통: KB, 벡터, 임베딩 같은 내부 단어는 본문에 절대 노출하지 않는다',
  '',
  '--------------------------------',
  '',
  sysPrompt,
  '',
  userPrompt,
  '',
  '[검색 의도]',
  intent,
  '',
  '--------------------------------',
  '',
  '[검색 질문]',
  '- ' + questions,
  '',
  '--------------------------------',
  '',
  '[H2 구조]',
  '다음 H2 구조를 반드시 사용한다.',
  '',
  h2list,
  '',
  '규칙',
  '- H2 순서를 변경하지 않는다',
  '- 새로운 H2를 생성하지 않는다',
  '- 각 H2는 반드시 이번 글의 주제와 직접 연결되게 작성한다',
  '- 각 H2 첫 문장은 해당 H2가 왜 필요한지 바로 드러나야 한다',
  '- 앞 H2에서 던진 문제를 다음 H2에서 자연스럽게 이어받는다',
  '',
  '[도입부 훅 규칙 - 매우 중요]',
  '⚠️ 아래 괄호 안 예시 문장은 유형 이해용이다. 이 문장을 그대로 쓰거나 업종명만 끼워 넣지 말 것. 반드시 현재 키워드·업종·업체에 맞는 새로운 문장으로 작성한다.',
  '- situation: 독자가 처한 구체 상황 묘사',
  '- mistake: 흔한 실수를 짚으며 시작',
  '- question: 독자의 내면 질문으로 시작',
  '- contrast: 기대와 현실 차이로 시작',
  '',
  '도입부 금지:',
  '- \"OO에 대해 알아보겠습니다\" / 목차·구조 미리 안내',
  '도입부는 독자가 \"이건 나한테 필요한 글이다\" 라고 느끼게 해야 한다.',
  '',
  '[톤 적용 규칙]',
  '현재 고객 톤은 \"' + toneGuide + '\" 이다.',
  '현재 action_style은 \"' + actionStyle + '\" 이다.',
  '현재 expression은 \"' + expression + '\" 이다.',
  '',
  '이 톤은 참고 사항이 아니라 실제 문장 스타일에 반드시 반영해야 하는 핵심 규칙이다.',
  '- 설명문이 아니라 조언문에 가깝게 써야 한다',
  '- 독자가 읽을 때 \"알려준다\"보다 \"옆에서 짚어준다\"는 느낌이 나야 한다',
  '',
  '[role 차등 규칙]',
  '현재 role은 ' + postRole + ' 이다.',
  '',
  '- promo: 정보 밀도와 판단 기준이 가장 또렷해야 한다',
  '- info: 서비스/제공 주체가 왜 한 번 더 비교해볼 만한지 자연스럽게 남아야 한다. 정확한 브랜드명 \"' + brandName + '\"은 필요할 때만 1~3회 반영',
  '- plusA: 문제 제기, 흥미, 오해 방지, 막히는 지점을 더 선명하게 다룬다',
  '',
  '[제목 규칙]',
  '- title에는 반드시 위 컨텍스트에서 제시된 메인 키워드를 그대로 포함한다',
  '- 금지: \"~완벽 가이드\", \"~총정리\", \"~의 중요성\", \"~에 대해 알아보자\"',
  '',
  '[본문 작성 원칙]',
  '- 단순 정의 설명형 글로 쓰지 않는다',
  '- 각 섹션에는 최소 1개 이상 포함: 막히는 지점 / 실수하기 쉬운 부분 / 비교 기준 / 확인 질문 / 먼저 볼 조건',
  '- 추상 표현 사용 시 반드시 다음 문장에서 구체 조건/상황 풀어줌',
  '- 최소 1개의 Markdown 표 포함',
  '- 볼드(**텍스트**) 강조: 섹션당 1~3회',
  '- 이모지: ✅ 📌 ⚠️ 💡 만 허용, 글 전체 최대 3개',
  '',
  '[브랜드 반영 규칙]',
  '- 정확한 브랜드명은 \"' + brandName + '\" 이다',
  '- 브랜드는 독립 소개 문단처럼 넣지 않는다',
  '- 판단 기준·차이 설명·진행 흐름 안에서만 자연스럽게 반영한다',
  '- 모든 role에서 브랜드 또는 서비스 존재가 최소 1회 이상 자연스럽게 드러나야 한다',
  '',
  '[출력 구조]',
  '반드시 아래 JSON 구조를 사용한다.',
  '',
  '{',
  '  \"title\": \"\",',
  '  \"summary\": \"\",',
  '  \"bodyMarkdown\": \"\",',
  '  \"cta\": { \"text\": \"\", \"urlOrContact\": \"\" },',
  '  \"hashtags\": [],',
  '  \"selectedKeywords\": []',
  '}',
  '',
  '규칙',
  '- bodyMarkdown 안에는 반드시 \"## \" H2만 사용한다',
  '- 검색의도_H2생성에서 받은 H2를 bodyMarkdown 안에 그대로 순서대로 넣는다',
  '- 최소 1개의 Markdown 표를 포함한다',
  '- selectedKeywords는 반드시 입력 selectedKeywords를 유지한다'
].join('\\n');

return { json: { ...$json, _gl_full_text } };
`.trim();

// 문법 검사
try { new Function(PREP_CODE); console.log('Syntax OK'); }
catch(e) { console.error('SYNTAX ERROR:', e.message); process.exit(1); }

async function main() {
  const wf = await req('GET', `/api/v1/workflows/${WF_ID}`);
  if (!wf.nodes) { console.error('Fetch error:', JSON.stringify(wf).substring(0,200)); process.exit(1); }

  const nodes = wf.nodes;
  const conns = wf.connections;

  const glNode  = nodes.find(n => n.name === '글생성');
  const kbNode  = nodes.find(n => n.name === 'KB 검색');
  if (!glNode) { console.error('글생성 not found'); process.exit(1); }

  // 1. 새 Code 노드 추가
  const newNode = {
    id: 'gl-prompt-prep-001',
    name: '글생성 프롬프트 준비',
    type: 'n8n-nodes-base.code',
    typeVersion: 2,
    position: [glNode.position[0] - 224, glNode.position[1]],
    parameters: { jsCode: PREP_CODE }
  };
  nodes.push(newNode);

  // 2. 글생성 text → $json._gl_full_text
  glNode.parameters.text = '={{ $json._gl_full_text }}';

  // 3. 연결 변경: KB 검색 → 글생성 프롬프트 준비
  if (conns['KB 검색']?.main) {
    conns['KB 검색'].main = conns['KB 검색'].main.map(output =>
      (output || []).map(dest =>
        dest.node === '글생성' ? { ...dest, node: '글생성 프롬프트 준비' } : dest
      )
    );
  }

  // 4. 글생성 프롬프트 준비 → 글생성
  conns['글생성 프롬프트 준비'] = {
    main: [[{ node: '글생성', type: 'main', index: 0 }]]
  };

  const payload = {
    name: wf.name, nodes, connections: conns,
    settings: wf.settings || {}, staticData: wf.staticData || null
  };

  const result = await req('PUT', `/api/v1/workflows/${WF_ID}`, payload);
  if (result.id) {
    console.log('SUCCESS');
    console.log('- 글생성 프롬프트 준비 노드 추가');
    console.log('- KB 검색 → 글생성 프롬프트 준비 → 글생성');
    console.log('- 글생성 text = ={{ $json._gl_full_text }}');

    // 검증
    const prep = result.nodes.find(n => n.name === '글생성 프롬프트 준비');
    try { new Function(prep?.parameters?.jsCode || ''); console.log('Live syntax check: OK'); }
    catch(e) { console.error('Live syntax ERROR:', e.message); }

    const kbConns = result.connections['KB 검색']?.main?.[0]?.map(d=>d.node);
    const prepConns = result.connections['글생성 프롬프트 준비']?.main?.[0]?.map(d=>d.node);
    console.log('KB 검색 →', kbConns);
    console.log('글생성 프롬프트 준비 →', prepConns);
    console.log('글생성 text:', result.nodes.find(n=>n.name==='글생성')?.parameters?.text?.substring(0,50));
  } else {
    console.error('ERROR:', JSON.stringify(result).substring(0, 500));
  }
}

main().catch(console.error);
