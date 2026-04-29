const https = require('https');
const http = require('http');
const fs = require('fs');

const N8N_KEY = process.argv[2];
if (!N8N_KEY) { console.error('Usage: node fix_h2_node.js <API_KEY>'); process.exit(1); }

const WF_ID = 'daUM2xPEVyBhbyez';
const BASE = 'http://localhost:5678';

function req(method, path, body) {
  return new Promise((res, rej) => {
    const bodyStr = body ? JSON.stringify(body) : null;
    const opts = {
      method,
      headers: {
        'X-N8N-API-KEY': N8N_KEY,
        'Content-Type': 'application/json',
        ...(bodyStr ? { 'Content-Length': Buffer.byteLength(bodyStr) } : {})
      }
    };
    const url = new URL(BASE + path);
    const proto = url.protocol === 'https:' ? https : http;
    const r = proto.request(url, opts, resp => {
      const chunks = [];
      resp.on('data', c => chunks.push(c));
      resp.on('end', () => {
        const txt = Buffer.concat(chunks).toString();
        try { res(JSON.parse(txt)); } catch { res(txt); }
      });
    });
    r.on('error', rej);
    if (bodyStr) r.write(bodyStr);
    r.end();
  });
}

// H2 프롬프트 준비 Code node jsCode
const H2_PREP_CODE = `
function safeItems(n) { try { return $items(n) || []; } catch { return []; } }

const meta = ($json._meta) || {};
const mainKeyword = (meta.selectedKeywords || [])[0] || '';
const postRole = meta.postRole || '';

const mkpItem = safeItems('마케팅 프로파일 파싱')[0];
const mprofile = mkpItem?.json?._marketing_profile || {};
const structureAngle = mprofile?.structure_bias?.angle || '';
const targetReader = mprofile?.target_reader || '';
const keyAngles = (mprofile?.key_angles || []).map((a, i) => (i+1) + '. ' + a).join('\n');

const _h2_full_text = \`너는 "SEO 검색 의도 분석가"다.

입력된 키워드를 보고
1. 검색자가 실제로 궁금해하는 질문
2. 블로그 글 구조(H2)
를 만든다.

--------------------------------

입력 키워드

\${mainKeyword}

role
\${postRole}

[이 업체 글 구조 방향]
\${structureAngle}

[이 글의 독자]
\${targetReader}

[이 업체만의 각도 — H2 구성 시 반드시 이 각도에서 파생할 것]
\${keyAngles}

--------------------------------

목표

이 키워드로 검색했을 때
상위 블로그 글에서 자주 보이는
"실제 판단형 / 현장형 / 질문형 구조"를 만든다.

정의 설명형 글이 아니라,
검색자가 실제로
- 어디서 막히는지
- 무엇을 먼저 봐야 하는지
- 어떤 차이를 구분해야 하는지
- 선택이나 진행 전에 무엇을 확인해야 하는지
를 다루는 구조여야 한다.

질문과 H2가
정해진 예시 문구를 바꿔 쓴 것처럼 보이면 실패다.
같은 업종의 다른 글과 비교해도
질문 축과 제목 흐름이 최대한 겹치지 않게 만들어라.

--------------------------------

출력 필드

intent
searchQuestions
h2_outline

--------------------------------

intent 규칙

- 1~2문장
- 검색자가 단순 정의를 찾는지, 비교/준비/의사결정/문제 해결을 하려는지 드러나야 한다
- "무엇인가" 설명으로 끝내지 말고, 검색자가 어떤 판단을 하려는지 보여줘야 한다
- role은 설명 주제가 아니라 강조 강도에만 반영한다

--------------------------------

searchQuestions 규칙

- 4~6개
- 실제 검색자가 할 법한 질문만 만든다
- 정의형 질문만 반복하지 않는다
- 아래 성격의 질문이 자연스럽게 섞이게 한다
  - 무엇부터 봐야 하는지
  - 어떤 기준으로 판단해야 하는지
  - 비용/범위/시점/절차/조건 중 무엇이 갈리는지
  - 어떤 실수를 많이 하는지
  - 선택 또는 진행 전에 무엇을 확인해야 하는지
- 최소 2개는 실제 의사결정 직전 질문이어야 한다
- 같은 질문 축을 표현만 바꿔 반복하지 않는다
- 질문 시작 패턴이 지나치게 반복되지 않게 한다
- 같은 업종의 다른 글에서도 흔히 보이는 안전한 질문만 나열하면 실패다
- 검색 키워드만 바꾼 복붙형 질문처럼 보이면 실패다
- 질문 4~6개가 모두 비슷한 무게의 일반론으로 흐르지 않게 한다

[role 반영 방식]
- promo: 직접 판단 기준, 준비 포인트, 실수 방지 질문이 더 또렷해야 한다
- info: 제공 주체의 필요성이나 차이가 자연스럽게 드러날 수 있다
- plusA: 자주 막히는 이유, 겉보기와 실제 차이, 그냥 진행하면 안 되는 이유가 더 또렷해야 한다

--------------------------------

h2_outline 규칙

- 4~6개
- searchQuestions 기반으로 만들되, 그대로 옮기지 말고 글 구조용 제목으로 재구성한다
- 설명형 제목만 반복 금지
- 전체 H2 중 최소 4개는 현장형 / 실수형 / 질문형 / 판단형 제목으로 만든다
- "왜 필요할까", "무엇을 알아야 할까", "핵심 포인트", "중요한 이유" 같은 추상 제목 금지
- 제목만 읽어도 실제로 어디서 막히는지, 무엇을 먼저 봐야 하는지 감이 와야 한다
- 입력 키워드는 전체 H2 중 1~2개에만 자연스럽게 포함한다
- 나머지는 관련 맥락어로 이어간다
- 마지막 H2가 꼭 정리형일 필요는 없다
- 같은 톤의 제목만 줄줄이 만들지 않는다
- "체크리스트", "정리", "선택 기준" 같은 안전한 제목만 반복하면 실패다
- searchQuestions를 문장만 바꿔 반복한 H2면 실패다

[H2 작성 방향]
- 현장형
- 비교형
- 실수 방지형
- 판단형
- 진행 직전 확인형
- 겉보기와 실제 차이형
위 성격을 3종 이상 섞는다

- 모든 H2를 비슷한 문장 길이와 비슷한 말투로 만들지 않는다
- 모든 H2를 "무엇을/왜/어떻게"형 안전 제목으로 통일하지 않는다

[구조 비대칭 규칙]
- h2_outline는 전체가 너무 매끈한 단계형 구조로만 이어지면 실패다
- 예: 개념 → 기준 → 비용 → 실수 → 체크리스트 처럼 교과서형으로만 정리되면 안 된다
- 최소 1개 H2는 "독자가 자기 상황을 바로 점검하게 만드는 제목"이어야 한다
- 최소 1개 H2는 "겉으로는 비슷하지만 실제로 갈리는 차이"를 다뤄야 한다
- 마지막 H2는 정리/체크리스트형보다 "결정 직전 확인" 또는 "이 경우 다시 봐야 하는 포인트" 성격을 우선한다
- 모든 H2가 비슷한 추상도 수준이면 실패다
- 어떤 H2는 비교형, 어떤 H2는 상황형, 어떤 H2는 판단형처럼 층위가 조금 달라야 한다

[자기진단 유도 규칙]
- searchQuestions 4~6개 중 최소 1개는 "그래서 내 상황에서는 어디가 문제일 가능성이 큰가"를 떠올리게 하는 질문이어야 한다
- h2_outline 중 최소 1개는 독자가 스스로 점검할 수 있는 구조여야 한다

--------------------------------

피해야 할 방향 예

- 왜 필요할까
- 무엇을 알아야 할까
- 핵심 포인트
- 중요한 이유
- 체크해야 할 내용
- h2에 : 삽입

--------------------------------

출력

반드시 JSON

{
  "intent": "",
  "searchQuestions": [],
  "h2_outline": []
}

[H2 소제목 작성 규칙]
아래 스타일 유형 중 이 업체 성격·독자에 맞는 것을 골라 H2마다 다른 패턴으로 작성한다.
최소 3가지 유형 혼합. 같은 패턴 연속 사용 금지.

① 질문형 — 독자 궁금증을 제목으로
   예) "왜 같은 조건인데 결과가 다를까?" / "아직도 {업종} 고를 때 가격만 보시나요?"

② 반전·의외형 — 상식을 뒤집는 한 마디
   예) "열심히 해도 효과 없다면, 방향이 문제일 수 있습니다"
   예) "비싼 게 꼭 좋은 건 아닙니다 — {업종}에서 진짜 중요한 기준"

③ 숫자·구체형 — 수치로 신뢰와 호기심 동시에
   예) "3가지만 바꿨더니 달라졌습니다" / "확인해야 할 5가지"

④ 공감·상황형 — 독자 상황을 그대로 제목에
   예) "처음이라 뭘 물어봐야 할지 모르겠다면"
   예) "바빠서 제대로 못 알아봤는데 괜찮을까 걱정되신다면"

⑤ 혜택·결과형 — 읽으면 뭘 얻는지 명확하게
   예) "이것만 알아도 후회할 확률이 낮아집니다"

⑥ 업체특화형 — 이 업체 강점·차별화를 자연스럽게 제목에
   [이 업체만의 각도]에서 뽑은 포인트를 제목 안에 녹인다

금지: 키워드 그대로 붙여넣기 / 클릭 욕구 없는 평범한 정보형 제목\`;

return { json: { ...\$json, _h2_full_text } };
`.trim();

async function main() {
  console.log('Fetching workflow...');
  const wf = await req('GET', `/api/v1/workflows/${WF_ID}`);
  if (!wf.nodes) { console.error('Error:', JSON.stringify(wf)); process.exit(1); }
  
  const nodes = wf.nodes;
  const conns = wf.connections;
  
  const h2node = nodes.find(n => n.name === '검색의도_H2생성');
  const p1node = nodes.find(n => n.name === '프롬프트생성1');
  
  if (!h2node || !p1node) { console.error('Node not found'); process.exit(1); }
  
  // 1. Create new Code node "H2 프롬프트 준비"
  const newNodeId = 'h2-prep-' + Date.now();
  const newNode = {
    id: newNodeId,
    name: 'H2 프롬프트 준비',
    type: 'n8n-nodes-base.code',
    typeVersion: 2,
    position: [h2node.position[0] - 224, h2node.position[1]],
    parameters: {
      jsCode: H2_PREP_CODE
    }
  };
  
  // 2. Update 검색의도_H2생성 text to use $json._h2_full_text
  h2node.parameters.text = '={{ $json._h2_full_text }}';
  
  // 3. Add new node
  nodes.push(newNode);
  
  // 4. Update connections
  // Change: 프롬프트생성1 → 검색의도_H2생성  to  프롬프트생성1 → H2 프롬프트 준비
  if (conns['프롬프트생성1']?.main) {
    conns['프롬프트생성1'].main = conns['프롬프트생성1'].main.map(output =>
      (output || []).map(dest => 
        dest.node === '검색의도_H2생성' ? { ...dest, node: 'H2 프롬프트 준비' } : dest
      )
    );
  }
  
  // Add: H2 프롬프트 준비 → 검색의도_H2생성
  conns['H2 프롬프트 준비'] = {
    main: [[{ node: '검색의도_H2생성', type: 'main', index: 0 }]]
  };
  
  // 5. PUT workflow
  const payload = {
    name: wf.name,
    nodes: nodes,
    connections: conns,
    settings: wf.settings || {},
    staticData: wf.staticData || null
  };
  
  console.log('Applying fix...');
  const result = await req('PUT', `/api/v1/workflows/${WF_ID}`, payload);
  
  if (result.id) {
    console.log('SUCCESS: Workflow updated. H2 프롬프트 준비 node added.');
    fs.writeFileSync('C:/Users/조경일/caify-product/wf_h2fixed.json', JSON.stringify(result, null, 2));
  } else {
    console.error('ERROR:', JSON.stringify(result).substring(0, 500));
  }
}

main().catch(console.error);
