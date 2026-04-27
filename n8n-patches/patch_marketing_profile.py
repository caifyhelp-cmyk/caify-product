"""
패치: 고객 마케팅 프로파일 + 비즈니스 리서치 위치 변경 + 스마트 키워드 선택
"""
import json, sys, uuid, os

with open('/root/caify-product/키워드풀_반영.json', 'r') as f:
    d = json.load(f)

nodes = d['nodes']
conn  = d['connections']

def find_node(name):
    for n in nodes:
        if n['name'] == name:
            return n
    return None

ANTHROPIC_KEY = os.environ.get('ANTHROPIC_API_KEY', '<ANTHROPIC_KEY_PLACEHOLDER>')

# ══════════════════════════════════════════════════════════════
# 1. 비즈니스 리서치 위치 변경 (풀 체크 앞으로)
# ══════════════════════════════════════════════════════════════
# 가중치부여1 → 비즈니스 리서치 준비 (was: 풀 메타 조회)
conn['가중치부여1']['main'][0] = [{"node": "비즈니스 리서치 준비", "type": "main", "index": 0}]

# 리서치 결과 파싱 → 풀 메타 조회 (was: LLM 요청 준비)
conn['리서치 결과 파싱']['main'][0] = [{"node": "풀 메타 조회", "type": "main", "index": 0}]

# IF: 풀 생성 필요 TRUE → LLM 요청 준비 (was: 비즈니스 리서치 준비)
conn['IF: 풀 생성 필요']['main'][0] = [{"node": "LLM 요청 준비", "type": "main", "index": 0}]

print('✅ 1. 비즈니스 리서치 위치 변경', file=sys.stderr)

# ══════════════════════════════════════════════════════════════
# 2. 스마트 키워드 선택
# ══════════════════════════════════════════════════════════════
SMART_KW_CODE = r"""function safeItems(n) { try { return $items(n) || []; } catch { return []; } }
function clone(o)  { return o ? JSON.parse(JSON.stringify(o)) : {}; }
function norm(s)   { return String(s ?? "").replace(/\s+/g," ").trim(); }

const allRows = $input.all().map(i => i.json);

// ctx: Merge(풀) 또는 가중치부여1에서
const ctxItem = safeItems("Merge (풀)")[0] || safeItems("가중치부여1")[0];
const ctx = clone(ctxItem?.json || {});

// _biz_research: 리서치 결과 파싱에서 직접 참조
const bizResearch    = norm(safeItems("리서치 결과 파싱")[0]?.json?._biz_research || "");
const productStrengths = norm(ctx.product_strengths || "");
const extraStrength  = norm(ctx.extra_strength || "");
const industry       = norm(ctx.industry || "");
const brandName      = norm(ctx.brand_name || "");

if (allRows.length === 0) {
  throw new Error("키워드 풀이 비어있습니다. member_pk: " + ctx.member_pk);
}

// 14일 미사용 우선
const available = allRows.filter(r => Number(r.used_recently) === 0);
const pool = available.length > 0 ? available : allRows;

// 컨텍스트 기반 스코어링
const contextText = (bizResearch + " " + productStrengths + " " + extraStrength + " " + industry).toLowerCase();
const ctxTokens = contextText.match(/[가-힣]{2,}/g) || [];
const tokenFreq = {};
for (const t of ctxTokens) tokenFreq[t] = (tokenFreq[t] || 0) + 1;

function scoreKw(kw) {
  const tokens = String(kw).match(/[가-힣]{2,}/g) || [];
  let sc = 0;
  for (const t of tokens) sc += (tokenFreq[t] || 0) * (t.length >= 3 ? 2 : 1);
  if (brandName && kw.includes(brandName.slice(0,3))) sc += 3;
  if (industry && kw.includes(industry.slice(0,2))) sc += 2;
  return sc;
}

const scored = pool
  .map(r => ({ ...r, _score: scoreKw(r.keyword) }))
  .sort((a, b) => b._score - a._score || Math.random() - 0.5);

const sel = scored[0];

return {
  json: {
    keyword:          sel.keyword,
    keyword_norm:     sel.keyword,
    id:               null,
    category:         "llm_generated",
    priority:         1,
    role:             ctx._resolved_slot,
    _member_pk:       ctx.member_pk,
    _slot:            ctx._resolved_slot,
    _used_at:         new Date().toISOString().slice(0, 10),
    _is_fallback:     available.length === 0,
    _selection_score: sel._score
  }
};"""

kw_node = find_node("키워드 가져오기(plusA)1")
if kw_node:
    kw_node['parameters']['jsCode'] = SMART_KW_CODE
    print('✅ 2. 스마트 키워드 선택 업데이트', file=sys.stderr)
else:
    print('⚠️  키워드 가져오기(plusA)1 없음', file=sys.stderr)

# ══════════════════════════════════════════════════════════════
# 3. 고객 마케팅 프로파일 노드 3개 추가
# ══════════════════════════════════════════════════════════════
PROFILE_PREP_CODE = r"""function safeItems(n) { try { return $items(n) || []; } catch { return []; } }
function norm(s) { return String(s ?? "").replace(/\s+/g," ").trim(); }

const ctx = $json.ctx || safeItems("가중치부여1")[0]?.json || {};
const bizResearch  = norm(safeItems("리서치 결과 파싱")[0]?.json?._biz_research || "");

const industry     = norm(ctx.industry     || $json.industry     || "");
const productName  = norm(ctx.product_name || $json.product_name || "");
const brandName    = norm(ctx.brand_name   || $json.brand_name   || "");
const tones        = norm(ctx.tones        || $json.tones        || "");
const ages         = norm(ctx.ages         || $json.ages         || "");
const goal         = norm(ctx.goal         || $json.goal         || "");
const serviceTypes = norm(ctx.service_types|| $json.service_types|| "");
const strengths    = norm(ctx.product_strengths || $json.product_strengths || "");
const extra        = norm(ctx.extra_strength    || $json.extra_strength    || "");
const expression   = norm(ctx.expression   || $json.expression   || "");
const forbidden    = norm(ctx.forbidden_phrases || $json.forbidden_phrases || "");
const actionStyle  = norm(ctx.action_style || $json.action_style || "");

const system = `당신은 콘텐츠 마케팅 전략가다.
주어진 업체 정보를 분석해 이 업체 전담 마케터의 블로그 글쓰기 프로파일을 JSON으로만 출력한다.
JSON 외 다른 텍스트는 절대 포함하지 않는다.`;

const user = `[업체 정보]
업종: ${industry}
브랜드명: ${brandName}
상품/서비스: ${productName}
서비스 유형: ${serviceTypes}
타겟 연령대: ${ages}
목표: ${goal}
톤: ${tones}
행동 유도 스타일: ${actionStyle}
강점(고객 제출): ${strengths}
추가 강점: ${extra}
금지 표현: ${expression} / ${forbidden}

[사전 조사 결과]
${bizResearch || "조사 결과 없음 — 고객 제출 정보 기반으로만 프로파일 작성"}

[출력 JSON]
{
  "sentence_style": {
    "pattern": "short|medium|long|mixed 중 하나",
    "guide": "이 업체 담당 마케터가 문장을 쓰는 방식 — 독자 특성·톤을 반영한 구체적 지침 2~3문장"
  },
  "structure_bias": {
    "angle": "problem-solution|comparison-decision|authority-building|story-driven|informative 중 하나",
    "guide": "이 업체 목표·타겟에 맞는 글 구조 전략 2~3문장"
  },
  "key_angles": [
    "이 업체만의 차별화 각도1 (검색자 관점, 구체적으로)",
    "각도2",
    "각도3"
  ],
  "target_reader": "이 글의 실제 독자 — 상황·고민·검색 의도 포함 2문장",
  "emphasis_guide": "본문에서 자연스럽게 부각할 강점·특징 및 녹이는 방법 구체적으로",
  "expression_guide": {
    "use": "이 업체 글에 어울리는 표현·어조 패턴 (구체 예시 포함)",
    "avoid": "이 업체 글에서 피해야 할 표현·패턴"
  }
}`;

return {
  json: {
    ...$json,
    _profile_req: JSON.stringify({
      model: "claude-haiku-4-5-20251001",
      max_tokens: 1200,
      system,
      messages: [{ role: "user", content: user }]
    })
  }
};"""

PROFILE_PARSE_CODE = r"""function safeItems(n) { try { return $items(n) || []; } catch { return []; } }

const resp = $input.first().json;
const prevCtx = safeItems("마케팅 프로파일 준비")[0]?.json || {};

const raw = String(resp?.content?.[0]?.text || "").trim();
const jsonStr = raw.replace(/^```json?\n?/i,"").replace(/\n?```\s*$/i,"").trim();

let profile;
try {
  profile = JSON.parse(jsonStr);
} catch(e) {
  profile = {
    sentence_style:   { pattern: "mixed",       guide: "" },
    structure_bias:   { angle: "informative",   guide: "" },
    key_angles:       [],
    target_reader:    "",
    emphasis_guide:   "",
    expression_guide: { use: "", avoid: "" }
  };
}

return {
  json: {
    ...prevCtx,
    _marketing_profile: profile
  }
};"""

pid_prep  = str(uuid.uuid4())
pid_req   = str(uuid.uuid4())
pid_parse = str(uuid.uuid4())

nodes += [
    {
        "parameters": {"jsCode": PROFILE_PREP_CODE},
        "type": "n8n-nodes-base.code",
        "typeVersion": 2,
        "position": [35152, 5100],
        "id": pid_prep,
        "name": "마케팅 프로파일 준비"
    },
    {
        "parameters": {
            "method": "POST",
            "url": "https://api.anthropic.com/v1/messages",
            "sendHeaders": True,
            "headerParameters": {"parameters": [
                {"name": "x-api-key",          "value": ANTHROPIC_KEY},
                {"name": "anthropic-version",   "value": "2023-06-01"},
                {"name": "content-type",        "value": "application/json"}
            ]},
            "sendBody": True,
            "contentType": "raw",
            "rawContentType": "application/json",
            "body": "={{ $json._profile_req }}",
            "options": {}
        },
        "type": "n8n-nodes-base.httpRequest",
        "typeVersion": 4.2,
        "position": [35376, 5100],
        "id": pid_req,
        "name": "마케팅 프로파일 생성"
    },
    {
        "parameters": {"jsCode": PROFILE_PARSE_CODE},
        "type": "n8n-nodes-base.code",
        "typeVersion": 2,
        "position": [35600, 5100],
        "id": pid_parse,
        "name": "마케팅 프로파일 파싱"
    }
]

# 연결
conn['Merge (지식)']['main'][0] = [{"node": "마케팅 프로파일 준비",  "type": "main", "index": 0}]
conn['마케팅 프로파일 준비']   = {"main": [[{"node": "마케팅 프로파일 생성", "type": "main", "index": 0}]]}
conn['마케팅 프로파일 생성']   = {"main": [[{"node": "마케팅 프로파일 파싱", "type": "main", "index": 0}]]}
conn['마케팅 프로파일 파싱']   = {"main": [[{"node": "프롬프트생성1",         "type": "main", "index": 0}]]}

print('✅ 3. 마케팅 프로파일 노드 3개 추가', file=sys.stderr)

# ══════════════════════════════════════════════════════════════
# 4. 프롬프트생성1 — 프로파일 변수 + SYSTEM/USER PROMPT 주입
# ══════════════════════════════════════════════════════════════
p1 = find_node("프롬프트생성1")
if p1:
    code = p1['parameters']['jsCode']

    # 4a. 프로파일 변수 추가 (bizResearchText 바로 뒤)
    old_biz = "const bizResearchText = String(bizResearchItem?.json?._biz_research || '').trim();"
    new_biz = """const bizResearchText = String(bizResearchItem?.json?._biz_research || '').trim();

// 고객 마케팅 프로파일
const profileItem    = safeItems('마케팅 프로파일 파싱')[0];
const mktProfile     = profileItem?.json?._marketing_profile || null;
const sentenceGuide  = String(mktProfile?.sentence_style?.guide   || '').trim();
const structGuide    = String(mktProfile?.structure_bias?.guide    || '').trim();
const keyAngles      = Array.isArray(mktProfile?.key_angles) ? mktProfile.key_angles : [];
const targetReader   = String(mktProfile?.target_reader            || '').trim();
const emphasisGuide  = String(mktProfile?.emphasis_guide           || '').trim();
const exprUse        = String(mktProfile?.expression_guide?.use    || '').trim();
const exprAvoid      = String(mktProfile?.expression_guide?.avoid  || '').trim();"""

    if old_biz in code:
        code = code.replace(old_biz, new_biz)
        print('✅ 4a. 프로파일 변수 추가', file=sys.stderr)
    else:
        print('⚠️  bizResearchText 라인 없음', file=sys.stderr)

    # 4b. SYSTEM_PROMPT — 프로파일 지침 추가
    old_sys_end = "- 두 자료에 없는 수치·법령·판례·구체 사례는 절대 만들어내지 말 것"
    new_sys_end = """- 두 자료에 없는 수치·법령·판례·구체 사례는 절대 만들어내지 말 것
${sentenceGuide  ? '- [이 업체 문장 스타일] ' + sentenceGuide  : ''}
${structGuide    ? '- [이 업체 글 구조]     ' + structGuide    : ''}
${emphasisGuide  ? '- [강조 포인트]         ' + emphasisGuide  : ''}
${exprUse        ? '- [권장 표현 패턴]      ' + exprUse        : ''}
${exprAvoid      ? '- [금지 표현 패턴]      ' + exprAvoid      : ''}"""

    if old_sys_end in code:
        code = code.replace(old_sys_end, new_sys_end)
        print('✅ 4b. SYSTEM_PROMPT 프로파일 주입', file=sys.stderr)
    else:
        print('⚠️  SYSTEM_PROMPT 끝 없음', file=sys.stderr)

    # 4c. USER_PROMPT — 독자·각도 섹션 추가 ([고객 정보] 앞)
    old_ctx = "${ragContextText ? `[키워드 심층 조사"
    new_reader_block = """${targetReader  ? '[이 글의 독자]\\n'   + targetReader + '\\n\\n' : ''}${keyAngles.length ? '[이 업체만의 각도]\\n' + keyAngles.map((a,i) => (i+1)+'. '+a).join('\\n') + '\\n\\n' : ''}${ragContextText ? `[키워드 심층 조사"""

    if old_ctx in code:
        code = code.replace(old_ctx, new_reader_block, 1)
        print('✅ 4c. USER_PROMPT 독자·각도 주입', file=sys.stderr)
    else:
        print('⚠️  ragContextText 블록 없음', file=sys.stderr)

    p1['parameters']['jsCode'] = code

# ══════════════════════════════════════════════════════════════
# 5. 검색의도_H2생성 — 업체 특성·독자·각도 주입
# ══════════════════════════════════════════════════════════════
h2 = find_node("검색의도_H2생성")
if h2:
    old_role_line = "role\n{{ $('프롬프트생성1').item.json._meta.postRole }}"
    new_role_block = """role
{{ $('프롬프트생성1').item.json._meta.postRole }}

[이 업체 글 구조 방향]
{{ $('마케팅 프로파일 파싱').item.json._marketing_profile?.structure_bias?.angle || '' }}

[이 글의 독자]
{{ $('마케팅 프로파일 파싱').item.json._marketing_profile?.target_reader || '' }}

[이 업체만의 각도 — H2 구성 시 반드시 이 각도에서 파생할 것]
{{ ($('마케팅 프로파일 파싱').item.json._marketing_profile?.key_angles || []).map((a,i) => (i+1)+'. '+a).join('\\n') }}"""

    txt = h2['parameters']['text']
    if old_role_line in txt:
        h2['parameters']['text'] = txt.replace(old_role_line, new_role_block, 1)
        print('✅ 5. 검색의도_H2생성 프로파일 주입', file=sys.stderr)
    else:
        print('⚠️  검색의도_H2생성 role 라인 없음', file=sys.stderr)

# ══════════════════════════════════════════════════════════════
# OUTPUT
# ══════════════════════════════════════════════════════════════
with open('/root/caify-product/키워드풀_반영.json', 'w') as f:
    json.dump(d, f, ensure_ascii=False, indent=2)

print(json.dumps({"patched": True}))
