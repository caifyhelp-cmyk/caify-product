import json, sys

with open('/root/caify-product/키워드풀_반영.json', 'r') as f:
    d = json.load(f)

# ── 1. Perplexity 요청 준비 — 키워드 심층 조사 쿼리로 개선 ──────────────────
new_pplx_prep = """const item = $input.first().json;
const kw       = item._rag_keyword   || '';
const industry = item._rag_industry  || item.industry || '';

const query = `"${kw}"에 대해 블로그 콘텐츠 작성에 활용할 수 있는 깊고 정확한 정보를 한국어로 제공해주세요.

업종 맥락: ${industry}

아래 항목을 구체적으로 작성해주세요.
1. 핵심 개념과 작동 원리 — 사실 기반, 정확하게
2. 실제로 많이 하는 실수·오해 — 구체적으로
3. 선택·판단 기준 — 실질적인 비교 포인트
4. 최신 트렌드·변화 — 확인된 것만
5. 실무에서 자주 막히는 지점
6. 관련 수치·통계 — 출처 포함, 확인된 것만

확인되지 않은 내용은 포함하지 마세요.`;

return {
  json: {
    ...item,
    _pplx_body: JSON.stringify({
      model: 'sonar-pro',
      messages: [{ role: 'user', content: query }],
      return_citations: true,
      search_recency_filter: 'month'
    })
  }
};"""

# ── 2. 프롬프트생성1 — _biz_research 주입 + 두 소스 분리 전달 ───────────────
# 기존 코드에서 context 섹션과 USER_PROMPT 마지막 부분만 교체
old_context_section = """//------------------------------------------------
// context
//------------------------------------------------
const contextFull = {"""

new_context_section = """//------------------------------------------------
// context
//------------------------------------------------

// 키워드 심층 조사 (RAG — Perplexity 검색 결과)
const ragContextText = String(inJson.rag_context || '').trim();

// 업체 조사 (비즈니스 리서치 — 키워드풀 생성 시 수행)
const bizResearchItem = safeItems('리서치 결과 파싱')[0];
const bizResearchText = String(bizResearchItem?.json?._biz_research || '').trim();

const contextFull = {"""

old_user_prompt_end = """컨텍스트
${inJson.rag_context ? "[참고 자료 — Perplexity 검색 결과, 사실 기반 작성에 활용]\\n" + inJson.rag_context + "\\n\\n" : ""}${JSON.stringify(contextFull, null, 2)}
`;"""

new_user_prompt_end = """컨텍스트
${ragContextText ? `[키워드 심층 조사 — "${mainKeyword}" 관련 사실·원리·판단기준]\\n${ragContextText}\\n\\n` : ''}${bizResearchText ? `[업체 조사 — 이 업체의 실제 서비스·강점·타겟]\\n${bizResearchText}\\n\\n` : ''}[고객 정보]
${JSON.stringify(contextFull, null, 2)}
`;"""

old_system_rules = """- 참고 자료가 제공된 경우 그 내용을 적극 활용해 깊이 있는 글을 써라
- 참고 자료에 없는 수치·법령·판례·구체 사례는 절대 만들어내지 말 것"""

new_system_rules = """- [키워드 심층 조사]의 사실·원리·기준을 글의 정보 뼈대로 적극 활용해라
- [업체 조사]의 서비스·강점·타겟을 글 흐름 안에 자연스럽게 녹여라
- 두 자료가 모두 제공된 경우, 키워드 지식과 업체 강점이 함께 느껴지는 글을 써라
- 두 자료에 없는 수치·법령·판례·구체 사례는 절대 만들어내지 말 것"""

# ── 패치 적용 ──────────────────────────────────────────────────────────────
patched = 0
for n in d['nodes']:
    if n['name'] == 'Perplexity 요청 준비':
        n['parameters']['jsCode'] = new_pplx_prep
        patched += 1

    elif n['name'] == '프롬프트생성1':
        code = n['parameters']['jsCode']

        # context 섹션 교체
        if old_context_section in code:
            code = code.replace(old_context_section, new_context_section)
        else:
            print('⚠️  context 섹션 교체 실패', file=sys.stderr)

        # USER_PROMPT 컨텍스트 블록 교체
        if old_user_prompt_end in code:
            code = code.replace(old_user_prompt_end, new_user_prompt_end)
        else:
            print('⚠️  USER_PROMPT 끝 교체 실패', file=sys.stderr)

        # SYSTEM_PROMPT 규칙 교체
        if old_system_rules in code:
            code = code.replace(old_system_rules, new_system_rules)
        else:
            print('⚠️  SYSTEM_PROMPT 규칙 교체 실패', file=sys.stderr)

        n['parameters']['jsCode'] = code
        patched += 1

print(f'패치된 노드: {patched}개', file=sys.stderr)
print(json.dumps(d))
