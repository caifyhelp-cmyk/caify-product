import json, requests, time, re

import os
ANTHROPIC_KEY = os.environ.get("ANTHROPIC_API_KEY", "")
PERPLEXITY_KEY = os.environ.get("PERPLEXITY_API_KEY", "")

CASES = [
    {
        "label": "[특수③] 치킨 프랜차이즈 본사 — 겉은 치킨집, 실제는 가맹점주 모집",
        "brand_name": "황금닭발치킨",
        "industry": "치킨 프랜차이즈",
        "product_name": "치킨 프랜차이즈 창업 지원, 가맹점 모집",
        "address": "서울 송파구 가락동 99",
        "service_types": "전국 서비스",
        "ages": "30대 / 40대 / 50대",
        "goal": "매출을 늘리고 싶다",
        "product_strengths": "가맹비 0원. / 본사 식자재 공급으로 원가 절감. / 창업 교육 2주 제공. / 월 순수익 평균 350만원.",
        "tones": "단호하고 확신 있게",
    },
    {
        "label": "[특수④] 공인중개사 학원 — 겉은 부동산, 실제는 자격증 수험생 타겟",
        "brand_name": "에듀공인중개사학원",
        "industry": "공인중개사 학원",
        "product_name": "공인중개사 시험 준비, 단기 합격 과정",
        "address": "서울 노원구 공릉동 200",
        "service_types": "오프라인 매장 / 온라인 서비스",
        "ages": "30대 / 40대 / 50대",
        "goal": "문의·상담을 늘리고 싶다",
        "product_strengths": "합격률 전국 1위. / 현직 출제위원 강사진. / 1차+2차 패키지 할인. / 불합격 시 재수강 무료.",
        "tones": "전문가가 조언하는 느낌",
    },
]

def research(case):
    city_m = re.search(r'([가-힣]+구|[가-힣]+시|[가-힣]+군)', case['address'])
    city = city_m.group(1) if city_m else ''
    body = {
        "model": "sonar",
        "messages": [{"role": "user", "content": f"""다음 업체를 조사하여 한국어로 상세히 분석해주세요.

업체명: {case['brand_name']}
업종: {case['industry']}
상품/서비스: {case['product_name']}
지역: {city or '전국'}
고객 목표: {case['goal']}

[필수 조사 항목]
1. 핵심 상품·서비스 목록
2. 실제 강점·차별화 포인트
3. 주요 타겟 고객 (소비자/구직자/수험생/사업자/가맹점주 중 명시)
4. 고객 Pain Point
5. 실제 네이버 검색 키워드 패턴
6. 세부 카테고리·하위 서비스

확인되지 않은 내용은 추측하지 마세요."""}],
        "return_citations": True,
        "search_recency_filter": "month"
    }
    resp = requests.post(
        "https://api.perplexity.ai/chat/completions",
        headers={"Authorization": f"Bearer {PERPLEXITY_KEY}", "Content-Type": "application/json"},
        json=body, timeout=30
    )
    content = resp.json().get('choices', [{}])[0].get('message', {}).get('content', '')
    return content[:2000] if len(content) > 50 else ''

def gen_keywords(case, research_text):
    is_offline = '온라인' not in case['service_types'] and '전국' not in case['service_types']
    dong = ''
    m = re.search(r'([가-힣]+동)(?=\s|\d|$)', case['address'])
    if m: dong = m.group(1)
    gu_m = re.search(r'([가-힣]+구)', case['address'])
    gu = gu_m.group(1) if gu_m else ''
    local_unit = dong or gu

    slot_def = """[슬롯 정의 — 오프라인/지역]
- promo: 지역+서비스명 직접 검색
- info: 정보 탐색 의도
- convert: 선택 기준·비용·비교""" if is_offline else """[슬롯 정의 — 온라인/전국]
- promo: 브랜드·서비스명 직접 검색
- info: 정보·지식 탐색
- convert: 선택 기준·비교·비용"""

    offline_guide = f"""
[오프라인 동 단위 키워드 필수]
지역 단위: "{local_unit}"
- promo tier1: "{local_unit}+서비스명" 최소 2개 필수
- promo tier2: "{local_unit}+세부서비스" 최소 2개 필수""" if (is_offline and local_unit) else ''

    research_section = f"""━━━ [사전 조사 결과] ━━━
{research_text}
━━━━━━━━━━━━━━━━━━━━━━━━""" if research_text else "⚠️ 사전 조사 결과 없음"

    system = f"""당신은 네이버 SEO 및 콘텐츠 마케팅 전문가다.

[작업 순서]
1단계: 사전 조사 결과와 고객 정보를 종합하여 실제 비즈니스 목적과 주요 타겟을 추론한다.
  - 겉으로 드러난 업종명이 아닌 실제 수익 목적을 파악하라.
  - 예) 치킨 브랜드라도 가맹점 모집이 목적이면 타겟은 창업 희망자(사업자)
  - 예) 부동산 관련이라도 자격증 학원이면 타겟은 수험생
  - "primary_target" 필드에 명시 (소비자/구직자/수험생/사업자·창업희망자/기타)
2단계: 확인된 서비스·강점에서 키워드를 최대한 파생한다.
3단계: tier1(핵심40%) / tier2(2순위30%) / tier3(롱테일30%) 4:3:3 비율 분류.

{slot_def}
{offline_guide}

[타겟별 키워드 방향]
- 구직자 → 취업·모집·채용 키워드 비중 높임
- 수험생 → 시험·합격·공부법 키워드 비중 높임
- 사업자·창업희망자 → 창업비용·수익·가맹 키워드 비중 높임

슬롯당 15개 이상, 총 45개 이상. 반드시 JSON만 출력."""

    user = f"""{research_section}

━━━ [고객 기본 정보] ━━━
업종: {case['industry']}
상품/서비스명: {case['product_name']}
브랜드명: {case['brand_name']}
주소: {case['address']}{f' / 지역단위: {local_unit}' if local_unit else ''}
서비스 유형: {case['service_types']}
타겟 연령대: {case['ages']}
목표: {case['goal']}
강점: {case['product_strengths']}

[출력 JSON]
{{
  "primary_target": "소비자100% 또는 창업희망자80%+소비자20% 등",
  "target_reasoning": "추론 근거 2~3문장",
  "extracted_services": [],
  "extracted_strengths": [],
  "keyword_pool": {{
    "promo":   {{"tier1": [], "tier2": [], "tier3": []}},
    "info":    {{"tier1": [], "tier2": [], "tier3": []}},
    "convert": {{"tier1": [], "tier2": [], "tier3": []}}
  }}
}}"""

    resp = requests.post(
        "https://api.anthropic.com/v1/messages",
        headers={"x-api-key": ANTHROPIC_KEY, "anthropic-version": "2023-06-01", "content-type": "application/json"},
        json={"model": "claude-sonnet-4-6", "max_tokens": 4000, "system": system,
              "messages": [{"role": "user", "content": user}]},
        timeout=60
    )
    raw = resp.json().get('content', [{}])[0].get('text', '').strip()
    js = re.sub(r'^```json?\n?', '', raw)
    js = re.sub(r'```\s*$', '', js).strip()
    return json.loads(js)

def pick_keyword(result):
    import random
    all_kws = []
    for slot in ['promo', 'info', 'convert']:
        sd = result.get('keyword_pool', {}).get(slot, {})
        kws = sd if isinstance(sd, list) else (sd.get('tier1', []) + sd.get('tier2', []) + sd.get('tier3', []))
        for kw in kws:
            all_kws.append({'slot': slot, 'keyword': kw})
    return random.choice(all_kws) if all_kws else None

for case in CASES:
    print(f"\n{'='*65}")
    print(f"  {case['label']}")
    print(f"{'='*65}")

    print("  [1] Perplexity 조사 중...", end='', flush=True)
    res = research(case)
    print(f" {'완료' if res else '결과없음'} ({len(res)}자)")

    print("  [2] Claude 키워드 생성 중...", end='', flush=True)
    try:
        result = gen_keywords(case, res)
        print(" 완료")
    except Exception as e:
        print(f" 실패: {e}")
        continue

    print(f"\n  ▶ 타겟 추론: {result.get('primary_target', '-')}")
    print(f"  ▶ 근거: {result.get('target_reasoning', '-')}")
    print(f"  ▶ 확인된 서비스: {', '.join(result.get('extracted_services', [])[:4])}")
    print(f"  ▶ 확인된 강점: {', '.join(result.get('extracted_strengths', [])[:3])}")

    pool = result.get('keyword_pool', {})
    total = 0
    for slot in ['promo', 'info', 'convert']:
        sd = pool.get(slot, {})
        t1, t2, t3 = sd.get('tier1', []), sd.get('tier2', []), sd.get('tier3', [])
        cnt = len(t1) + len(t2) + len(t3)
        total += cnt
        print(f"\n  [{slot.upper()}] {cnt}개  (tier1:{len(t1)} / tier2:{len(t2)} / tier3:{len(t3)})")
        for kw in t1[:3]: print(f"    tier1 | {kw}")
        for kw in t2[:2]: print(f"    tier2 | {kw}")
        for kw in t3[:2]: print(f"    tier3 | {kw}")
        if cnt > 7: print(f"    ... 외 {cnt-7}개")

    print(f"\n  ▶ 총 키워드: {total}개")
    sel = pick_keyword(result)
    if sel:
        print(f"  ▶ 선택된 키워드 (랜덤): [{sel['slot']}] \"{sel['keyword']}\"")
    time.sleep(1)

print(f"\n{'='*65}\n테스트 완료\n")
