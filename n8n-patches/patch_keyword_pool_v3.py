import json, sys

with open('/root/caify-product/키워드풀_반영.json', 'r') as f:
    d = json.load(f)

# ── 1. 비즈니스 리서치 준비 — 타겟 추론 질문 추가 ─────────────────────────
new_research_prep = """const brand    = String($json.brand_name    || '').trim();
const industry = String($json.industry     || '').trim();
const product  = String($json.product_name || '').trim();
const address  = String($json.address      || '').trim();
const goal     = String($json.goal         || '').trim();

const cityMatch = address.match(/([가-힣]+시|[가-힣]+구|[가-힣]+군)/);
const city = cityMatch ? cityMatch[0] : '';

return {
  json: {
    ...$json,
    _research_query: brand,
    _research_body: JSON.stringify({
      model: 'sonar',
      messages: [{
        role: 'user',
        content: `다음 업체를 조사하여 한국어로 상세히 분석해주세요.

업체명: ${brand}
업종: ${industry}
상품/서비스: ${product}
지역: ${city || '전국'}
고객 목표: ${goal}

[필수 조사 항목]
1. 핵심 상품·서비스 목록 — 이 업체(또는 동종 업계)가 실제로 제공하는 것을 구체적으로
2. 실제 강점·차별화 포인트 — 고객이 선택하는 이유, 경쟁사 대비 특장점
3. 주요 타겟 고객 — 아래 중 실제 메인 타겟을 명시하라
   - 일반 소비자 (B2C)
   - 구직자·취업 희망자 (예: 설계사 모집, 취업 지원)
   - 학생·수험생 (예: 입시 준비, 시험 대비)
   - 사업자·가맹점주 (B2B, 프랜차이즈 모집)
   - 기타 특수 타겟 (구체적으로 명시)
4. 고객 Pain Point — 고객이 해결하려는 문제, 구매/문의 동기
5. 실제 네이버 검색 키워드 패턴 — 이 서비스를 찾는 사람들이 실제로 치는 검색어
6. 세부 카테고리·하위 서비스 — 상품/서비스를 더 잘게 쪼갠 세부 항목

각 항목을 명확히 구분하여 작성하고, 확인되지 않은 내용은 추측하지 마세요.`
      }],
      return_citations: true,
      search_recency_filter: 'month'
    })
  }
};"""

# ── 2. LLM 요청 준비 — 하드코딩 제거, Claude 자율 추론으로 교체 ──────────
new_llm_prep = """const slotRaw = String($json._resolved_slot || $json.publish_slot || '').toLowerCase();
const slot = slotRaw === 'plusa' ? 'convert' : (slotRaw || 'promo');

const serviceTypes = String($json.service_types || '').toLowerCase();
const industry     = String($json.industry      || '').toLowerCase();
const productName  = String($json.product_name  || '').toLowerCase();
const address      = String($json.address       || '').trim();

// ── 온/오프라인 판별 (슬롯 정의 선택용으로만 사용) ─────────────────────────
const isOnlineOrNational = serviceTypes.includes('온라인') || serviceTypes.includes('전국');
const isOffline          = !isOnlineOrNational;

// ── 동(洞) 추출: NAVER 지오코딩 우선, 없으면 address 정규식 ─────────────────
let dong = '';
try {
  const naverResp = $('NAVER API (위도경도)1').first().json;
  const jibun = naverResp?.addresses?.[0]?.jibunAddress || '';
  const m = jibun.match(/([가-힣]+동)(?=\\s|\\d|$)/);
  if (m) dong = m[1];
} catch(e) {}
if (!dong) {
  const m = address.match(/([가-힣]+동)(?=\\s|\\d|$)/);
  if (m) dong = m[1];
}
const guMatch = address.match(/([가-힣]+구)/);
const gu = guMatch ? guMatch[1] : '';
const localUnit = dong || gu;

// ── 슬롯 정의 ─────────────────────────────────────────────────────────────
const slotDefA = `[슬롯 정의 — 오프라인/지역 서비스]
- promo  : 지역+서비스명 직접 검색. 업체를 직접 찾는 사람 타겟.
- info   : 정보 탐색 의도. 광고 티 없이 신뢰/유입 목적.
- convert: 업체 선택 기준·비용·비교 검색.`;

const slotDefB = `[슬롯 정의 — 온라인/전국 서비스·브랜드]
- promo  : 브랜드·서비스명 직접 검색.
- info   : 정보·지식 탐색 의도.
- convert: 선택 기준·비교·비용 검색.`;

const slotDef = isOffline ? slotDefA : slotDefB;

// ── 오프라인 동 단위 필수 규칙 ────────────────────────────────────────────
const offlineLocalGuide = (isOffline && localUnit) ? `
[오프라인 지역 매장 — 동(洞) 단위 키워드 필수]
추출된 지역 단위: "${localUnit}"${dong ? ' (동 단위)' : ' (구 단위 — 동 정보 없음)'}
- promo tier1: "${localUnit}+서비스명" 형태 최소 2개 필수
- promo tier2: "${localUnit}+세부서비스" 형태 최소 2개 필수
- 도로명(로/길) 기반 키워드 사용 금지` : '';

// ── 사전 조사 섹션 ────────────────────────────────────────────────────────
const bizResearch = String($json._biz_research || '').trim();
const researchSection = bizResearch
  ? `━━━ [사전 조사 결과] ━━━
${bizResearch}
━━━━━━━━━━━━━━━━━━━━━━━━`
  : '';

const noResearchNote = !$json._research_available
  ? `\n⚠️ 사전 조사 결과 없음 — 고객 제출 정보와 업종 일반 지식만으로 생성. 미확인 서비스 생성 금지.`
  : '';

// ── 시스템 프롬프트 ───────────────────────────────────────────────────────
const system = `당신은 네이버 SEO 및 콘텐츠 마케팅 전문가다.
${noResearchNote}

[작업 순서 — 반드시 이 순서로]
1단계 (목적·타겟 추론):
  - 사전 조사 결과와 고객 정보를 종합하여 이 업체의 실제 비즈니스 목적과 주요 타겟을 추론한다.
  - 겉으로 드러난 업종명이 아닌 실제 수익 목적을 파악하라.
  - 예) 뷰티학원이라도 입시 전문이면 타겟은 수험생, 보험사라도 설계사 모집이 메인이면 타겟은 구직자
  - "primary_target" 필드에 명시 (소비자 / 구직자 / 수험생 / 사업자 / 기타)
  - 타겟이 복수이면 모두 나열하고 비중(%)도 표시

2단계 (서비스·강점 추출):
  - 조사 결과에서 확인된 실제 상품·서비스와 차별화 강점을 추출한다.
  - 확인되지 않은 서비스는 절대 포함 금지.

3단계 (키워드 파생):
  - 추론된 타겟과 추출된 서비스·강점 각각에서 키워드를 최대한 파생한다.
  - 타겟별로 검색 의도가 다르면 슬롯을 타겟 특성에 맞게 배분한다.

4단계 (4:3:3 분류):
  - tier1 (핵심 40%): 업종·서비스명 직결, 검색량 많음
  - tier2 (2순위 30%): 더 구체적. 특정 상황·지역·니즈 포함
  - tier3 (롱테일 30%): 3단어 이상, 구매/문의 의도 명확

${slotDef}
${offlineLocalGuide}

[수량 규칙 — 슬롯당 15개 이상, 총 45개 이상]
[품질 규칙]
- 타겟이 "구직자"이면 취업·모집·채용 관련 키워드 비중 높임
- 타겟이 "수험생"이면 입시·시험·준비 관련 키워드 비중 높임
- 타겟이 "사업자"이면 창업·가맹·B2B 관련 키워드 비중 높임
- 단일 주제 40% 초과 금지
- 반드시 JSON만 출력

[업종 앵커 규칙 — 엄격히 준수]
모든 키워드는 반드시 이 업체의 업종 또는 서비스명과 직접 연결되어야 한다.
혜택·특징·상황만 단독으로 쓴 키워드는 절대 금지.

❌ 금지 예시 (업종 앵커 없음):
- "불합격 무료 재수강" → 어느 학원에나 해당, 업종 연결 없음
- "가맹비 0원" → 어느 프랜차이즈에나 해당
- "합격률 1위" → 어느 시험이든 해당
- "24시간 운영" → 어느 업종에나 해당

✅ 올바른 예시 (업종명 앵커 포함):
- "공인중개사 학원 불합격 재수강 무료"
- "치킨 프랜차이즈 가맹비 0원"
- "공인중개사 합격률 높은 학원"
- "24시간 헬스장 강남"

규칙: 키워드 안에 업종명·서비스명·지역명 중 최소 1개 반드시 포함.

[롱테일(tier3) 실검 기준 — 엄격히 준수]
네이버 자동완성에 실제로 뜰 법한 3~5단어 명사형 키워드만 허용.
단어 논리 조합도 금지, 긴 문장·구어체 질문도 금지.

❌ 금지:
- "공인중개사 기출문제 학원 활용법" → 논리 조합, 아무도 이렇게 안 침
- "공인중개사 독학이랑 학원이랑 합격률 차이 얼마나 나요" → 문장이지 검색어가 아님
- "50대 직장인인데 공인중개사 학원 따라갈 수 있을지 걱정돼요" → 대화체
- "공인중개사 학원 수강 신청" → 검색 의도 없음

✅ 허용 (네이버 자동완성 스타일 3~5단어):
- "공인중개사 독학 합격률"
- "40대 공인중개사 취업"
- "공인중개사 학원 인강 비교"
- "공인중개사 2차 과락 기준"
- "직장인 공인중개사 공부 기간"

기준: 네이버 검색창에 쳤을 때 자동완성으로 뜰 법한 3~5단어 명사형 키워드.
구어체·문장형·조사로 끝나는 표현 모두 금지.`;


// ── 유저 프롬프트 ─────────────────────────────────────────────────────────
const userContent = `${researchSection}

━━━ [고객 기본 정보] ━━━
업종: ${$json.industry}
상품/서비스명: ${$json.product_name}
브랜드명: ${$json.brand_name}
주소: ${$json.address}${localUnit ? `\n지역 단위: ${localUnit}${dong ? ` (동: ${dong})` : ''}` : ''}
서비스 유형: ${$json.service_types}
타겟 연령대: ${$json.ages}
목표: ${$json.goal}
강점(고객 제출): ${$json.product_strengths}

[출력 JSON 형식]
{
  "primary_target": "소비자 100% 또는 구직자 70%+소비자 30% 등",
  "target_reasoning": "추론 근거 1~2문장",
  "extracted_services": ["확인된 서비스1", "서비스2"],
  "extracted_strengths": ["확인된 강점1", "강점2"],
  "keyword_pool": {
    "promo":   { "tier1": [], "tier2": [], "tier3": [] },
    "info":    { "tier1": [], "tier2": [], "tier3": [] },
    "convert": { "tier1": [], "tier2": [], "tier3": [] }
  }
}`;

return {
  json: {
    ...$json,
    _resolved_slot: slot,
    _dong: dong,
    _local_unit: localUnit,
    llm_request_body: JSON.stringify({
      model: 'claude-sonnet-4-6',
      max_tokens: 3000,
      system: system,
      messages: [{ role: 'user', content: userContent }]
    })
  }
};"""

# ── 3. LLM 응답 파싱 — primary_target 저장 추가 ──────────────────────────
new_llm_parse = r"""const response = $input.first().json;
const rawText  = (response.content && response.content[0] && response.content[0].text)
  ? response.content[0].text.trim() : '';

if (!rawText) throw new Error('LLM 응답이 비어있습니다.');

const jsonStr = rawText.startsWith('```')
  ? rawText.replace(/```json?\n?/g, '').replace(/```\s*$/g, '').trim()
  : rawText;

let pool;
try { pool = JSON.parse(jsonStr); }
catch(e) { throw new Error('LLM JSON 파싱 실패: ' + rawText.slice(0, 300)); }

const ctx       = $('LLM 요청 준비').first().json;
const member_pk = ctx.member_pk;
const now       = new Date().toISOString().slice(0, 19).replace('T', ' ');

const rows = [];
for (const slot of ['promo', 'info', 'convert']) {
  const slotData = pool.keyword_pool[slot];
  if (!slotData) continue;
  const keywords = Array.isArray(slotData)
    ? slotData
    : [...(slotData.tier1 || []), ...(slotData.tier2 || []), ...(slotData.tier3 || [])];
  for (const kw of keywords) {
    const escaped = String(kw).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    rows.push(`(${member_pk}, '${slot}', '${escaped}', '${now}', '${now}')`);
  }
}

if (rows.length === 0) throw new Error('LLM이 키워드를 생성하지 않았습니다.');

return {
  json: {
    ...ctx,
    llm_primary_target:      pool.primary_target      || '',
    llm_target_reasoning:    pool.target_reasoning     || '',
    llm_extracted_services:  JSON.stringify(pool.extracted_services  || []),
    llm_extracted_strengths: JSON.stringify(pool.extracted_strengths || []),
    insert_sql: `INSERT INTO caify_keyword_pool (member_pk, slot, keyword, created_at, refreshed_at) VALUES ${rows.join(', ')}`
  }
};"""

# ── 패치 적용 ─────────────────────────────────────────────────────────────
patched = 0
for n in d['nodes']:
    if n['name'] == '비즈니스 리서치 준비':
        n['parameters']['jsCode'] = new_research_prep
        patched += 1
    elif n['name'] == 'LLM 요청 준비':
        n['parameters']['jsCode'] = new_llm_prep
        patched += 1
    elif n['name'] == 'LLM 응답 파싱':
        n['parameters']['jsCode'] = new_llm_parse
        patched += 1

print(f"패치된 노드: {patched}개", file=sys.stderr)
print(json.dumps(d))
