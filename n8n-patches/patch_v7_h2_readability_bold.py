"""
패치 v7: H2 다채롭게 + 가독성 개선 + 업체명 볼드 강화
- 업체명 볼드: H2 헤더까지 확장
- 가독성: '전문가 납득' 제거, 어려운 개념 즉시 풀어쓰기 강화, 전문가 용어 수집 항목 삭제
- H2 스타일: 업체 성격 기반 흥미 유발 패턴 (질문형/반전형/숫자형/공감형/혜택형 혼합)
"""
import json, sys

WF_PATH = '/root/caify-product/키워드풀_반영.json'

with open(WF_PATH, 'r', encoding='utf-8') as f:
    d = json.load(f)

nodes = d['nodes']

def find_node(name):
    for n in nodes:
        if n['name'] == name:
            return n
    return None

ok = []
warn = []

# ══════════════════════════════════════════════════════════════
# 1. NAVER REBUILD V1 — H2 헤더에 boldBrand 적용
# ══════════════════════════════════════════════════════════════
naver = find_node("NAVER REBUILD V1")
if naver:
    code = naver['parameters']['jsCode']

    # 1a. H2 섹션 헤더 렌더링에 boldBrand 추가
    old_h2_render = "${esc(sec.h2)}"
    new_h2_render = "${boldBrand(esc(sec.h2))}"
    if old_h2_render in code:
        code = code.replace(old_h2_render, new_h2_render)
        ok.append("1a. H2 헤더 boldBrand 적용")
    else:
        warn.append("1a. H2 헤더 esc 패턴 없음 (이미 적용됐거나 구조 다름)")

    # 1b. body 라인 enhanceLine 체인에 boldBrand 추가 (applyMarkdownBold 뒤)
    old_body_chain = ".map(applyMarkdownBold).map(enhanceLine)"
    new_body_chain = ".map(applyMarkdownBold).map(l => boldBrand(l)).map(enhanceLine)"
    if old_body_chain in code and new_body_chain not in code:
        code = code.replace(old_body_chain, new_body_chain)
        ok.append("1b. body 라인 boldBrand 체인 추가")
    else:
        warn.append("1b. body 라인 체인 이미 적용됐거나 패턴 없음")

    # 1c. summary 라인도 동일하게
    old_sum_chain = ".map(applyMarkdownBold).map(enhanceLine)"
    # 이미 1b에서 replace됐으면 summary 전용 패턴 찾기
    # summary는 rhythmLines(summary).map(applyMarkdownBold).map(enhanceLine) 형태
    # 1b replace_all로 처리됐으므로 별도 처리 불필요

    naver['parameters']['jsCode'] = code
else:
    warn.append("1. NAVER REBUILD V1 노드 없음")

# ══════════════════════════════════════════════════════════════
# 2. Perplexity 요청 준비 — 전문가 용어 수집 항목 제거
# ══════════════════════════════════════════════════════════════
pplx = find_node("Perplexity 요청 준비")
if pplx:
    code = pplx['parameters']['jsCode']

    # 항목 7 "전문가·실무자 용어" 삭제 (어려운 단어 유입 주범)
    old_item7 = """7. 업종 전문가·실무자가 실제로 쓰는 용어와 기준 — 가능한 구체적으로

확인되지 않은 내용은 포함하지 마세요.
수치는 추정·요약 말고 원문 그대로 인용하세요."""

    new_item7 = """확인되지 않은 내용은 포함하지 마세요.
수치는 추정·요약 말고 원문 그대로 인용하세요.
전문 용어보다 독자가 이해하기 쉬운 표현 우선으로 서술하세요."""

    if old_item7 in code:
        pplx['parameters']['jsCode'] = code.replace(old_item7, new_item7)
        ok.append("2. Perplexity 전문가 용어 항목 제거 + 쉬운 표현 지침 추가")
    else:
        warn.append("2. Perplexity 항목7 패턴 없음")
else:
    warn.append("2. Perplexity 요청 준비 노드 없음")

# ══════════════════════════════════════════════════════════════
# 3. 프롬프트생성1 SYSTEM_PROMPT — 가독성 방향 전환
# ══════════════════════════════════════════════════════════════
p1 = find_node("프롬프트생성1")
if p1:
    code = p1['parameters']['jsCode']

    # '전문가 납득' + '수치 전시' 지침 → 독자 중심으로 교체
    old_expert = """- [키워드 심층 조사]에 수치·통계·연구결과가 있으면 본문에 반드시 포함하고 "(출처: 기관명)" 형태로 병기한다
- 수치가 많이 보일수록 독자 신뢰도가 올라간다 — 조사에서 확인된 숫자는 최대한 활용한다
- 전문가가 읽어도 납득할 깊이를 갖추되, 어려운 개념은 반드시 쉬운 말로 한 단계 더 풀어준다
- "이 글 쓴 사람은 이 분야를 진짜 안다"는 느낌과 "나도 이해했다"는 느낌이 동시에 남아야 한다"""

    new_expert = """- [키워드 심층 조사]에 수치·통계가 있으면 본문에 포함하되, "왜 이 수치가 나에게 중요한가"를 바로 다음 문장에서 연결한다. "(출처: 기관명)" 병기
- 전문 지식은 독자가 더 나은 결정을 내리도록 돕는 도구다 — 전시가 목적이 아니다
- 어려운 개념이나 용어가 나오면 **바로 다음 문장에** 쉬운 말로 풀어쓴다 (절대 그냥 지나치지 않는다)
- "이 분야를 처음 접하는 사람도 읽고 나서 핵심을 설명할 수 있어야 한다"는 기준으로 쓴다"""

    if old_expert in code:
        p1['parameters']['jsCode'] = code.replace(old_expert, new_expert)
        ok.append("3. 프롬프트생성1 가독성 방향 전환")
    else:
        warn.append("3. 프롬프트생성1 전문가 납득 패턴 없음")
else:
    warn.append("3. 프롬프트생성1 노드 없음")

# ══════════════════════════════════════════════════════════════
# 4. 글생성 — 가독성 + 수치 연결 규칙 교체
# ══════════════════════════════════════════════════════════════
gen = find_node("글생성")
if gen:
    txt = gen['parameters'].get('text', '')

    old_gen_rule = """- [키워드 심층 조사]에 수치·통계·연구결과가 있으면 본문에 반드시 포함하고 "(출처: 기관명)" 병기
- 수치를 쓸 때 단순 나열이 아닌, 왜 그 수치가 독자 판단에 중요한지 한 문장 연결
- 전문가가 읽어도 고개를 끄덕일 깊이를 유지하되, 어려운 개념·용어는 쉬운 말로 반드시 풀어쓴다
- 각 H2에 구체적 수치·비율·기간·금액 중 하나 이상이 자연스럽게 포함되면 신뢰도가 올라간다
- "전문성 있는 마케터가 독자를 위해 쓴 글"처럼 읽혀야 한다"""

    new_gen_rule = """- [키워드 심층 조사]에 수치·통계가 있으면 본문에 포함하고 "(출처: 기관명)" 병기. 단, 수치 바로 다음 문장에서 "그래서 나한테 어떤 의미인지"를 반드시 연결한다
- 어려운 개념·용어가 등장하면 **같은 단락 안에** 쉬운 말로 풀어쓴다 — 독자가 검색하러 나가지 않아도 이해할 수 있어야 한다
- 깊이는 구조와 흐름에서 나온다 — 어려운 단어를 쓰는 게 아니라 "왜→무엇→어떻게→그래서"의 논리로 전달한다
- 읽고 나서 "나도 이 내용 설명할 수 있겠다"는 느낌이 남아야 한다"""

    if old_gen_rule in txt:
        gen['parameters']['text'] = txt.replace(old_gen_rule, new_gen_rule)
        ok.append("4. 글생성 가독성 규칙 교체")
    else:
        warn.append("4. 글생성 수치규칙 패턴 없음")
else:
    warn.append("4. 글생성 노드 없음")

# ══════════════════════════════════════════════════════════════
# 5. 검색의도_H2생성 — 흥미 유발 스타일 패턴 + 업체 성격 반영
# ══════════════════════════════════════════════════════════════
h2 = find_node("검색의도_H2생성")
if h2:
    txt = h2['parameters'].get('text', '')

    # [이 업체만의 각도] 블록 뒤에 H2 스타일 가이드 삽입
    old_h2_end = "{{ ($('마케팅 프로파일 파싱').item.json._marketing_profile?.key_angles || []).map((a,i) => (i+1)+'. '+a).join('\\n') }}"

    new_h2_block = """{{ ($('마케팅 프로파일 파싱').item.json._marketing_profile?.key_angles || []).map((a,i) => (i+1)+'. '+a).join('\\n') }}

[H2 소제목 작성 규칙]
아래 스타일 유형 중에서 **이 업체 성격과 독자에게 맞는 것**을 골라 H2마다 다른 패턴을 사용한다.
같은 패턴을 반복하지 않는다. 최소 3가지 유형을 혼합한다.

① 질문형 — 독자의 궁금증을 제목으로
   예) "왜 같은 키워드인데 어떤 글은 상위노출이 되고 어떤 글은 안 될까?"
   예) "아직도 {업종} 고를 때 가격만 보시나요?"

② 반전·의외형 — 상식을 뒤집는 한 마디
   예) "열심히 하는데 효과 없다면, 방향이 문제일 수 있습니다"
   예) "비싼 게 꼭 좋은 건 아닙니다 — {업종}에서 진짜 중요한 기준"

③ 숫자·구체형 — 수치로 신뢰와 호기심 동시에
   예) "3개월 만에 달라진 이유 — 딱 이것 하나 바꿨습니다"
   예) "{업종} 선택 전 반드시 확인해야 할 5가지"

④ 공감·상황형 — 독자 상황을 그대로 제목에
   예) "처음이라 뭘 물어봐야 할지도 모르겠다면"
   예) "바빠서 제대로 못 알아봤는데, 괜찮을까 걱정되신다면"

⑤ 혜택·결과형 — 읽으면 뭘 얻는지 명확하게
   예) "이것만 알아도 {업종} 선택에서 후회할 확률이 낮아집니다"
   예) "{브랜드명}을 선택한 분들이 공통적으로 말하는 한 가지"

⑥ 업체 특화형 — 이 업체의 강점·서비스를 자연스럽게 녹인 제목
   예) {업체 강점이나 차별화 포인트를 제목 안에 자연스럽게 포함}

주의:
- 키워드를 그대로 붙여넣은 제목 금지 ("공인중개사 학원 합격률 높은 곳" 같은 형식)
- 클릭하고 싶은 제목이어야 한다 — 읽기 전에 이미 가치가 느껴져야 한다
- 업체명·브랜드명을 H2에 넣을 때는 자연스러운 흐름 안에서만"""

    if old_h2_end in txt:
        h2['parameters']['text'] = txt.replace(old_h2_end, new_h2_block)
        ok.append("5. H2 흥미 유발 스타일 패턴 추가 (6가지 유형)")
    else:
        warn.append("5. 검색의도_H2생성 각도 블록 패턴 없음 — 프롬프트 끝에 직접 추가")
        # 패턴 없으면 프롬프트 끝에 추가
        H2_STYLE_APPEND = """

[H2 소제목 작성 규칙]
아래 스타일 유형 중 이 업체 성격과 독자에게 맞는 것을 골라 H2마다 다른 패턴으로 작성한다.
최소 3가지 유형 혼합. 같은 패턴 반복 금지.

① 질문형: "왜 같은 조건인데 결과가 다를까?" / "아직도 {업종} 고를 때 가격만 보시나요?"
② 반전형: "열심히 해도 안 된다면 방향 문제일 수 있습니다" / "비싼 게 꼭 좋은 건 아닙니다"
③ 숫자형: "3가지만 바꿨더니 달라졌습니다" / "확인해야 할 5가지"
④ 공감형: "처음이라 뭘 물어봐야 할지 모르겠다면" / "바빠서 제대로 못 알아봤다면"
⑤ 혜택형: "이것만 알아도 후회할 확률이 낮아집니다" / "선택한 분들이 공통적으로 말하는 한 가지"
⑥ 업체특화형: 이 업체 강점·차별화를 자연스럽게 녹인 제목

금지: 키워드 그대로 붙여넣기 / 클릭 욕구 없는 평범한 정보형 제목"""
        h2['parameters']['text'] = txt + H2_STYLE_APPEND
        ok.append("5. H2 스타일 가이드 프롬프트 끝에 추가")
else:
    warn.append("5. 검색의도_H2생성 노드 없음")

# ══════════════════════════════════════════════════════════════
# OUTPUT
# ══════════════════════════════════════════════════════════════
with open(WF_PATH, 'w', encoding='utf-8') as f:
    json.dump(d, f, ensure_ascii=False, indent=2)

result = {"patched": True, "ok": ok, "warn": warn}
print(json.dumps(result, ensure_ascii=False))
for msg in ok:   print(f"✅ {msg}", file=sys.stderr)
for msg in warn: print(f"⚠️  {msg}", file=sys.stderr)
