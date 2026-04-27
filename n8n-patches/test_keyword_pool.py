import json, requests, time, re

import os
ANTHROPIC_KEY = os.environ.get("ANTHROPIC_API_KEY", "")
PERPLEXITY_KEY = os.environ.get("PERPLEXITY_API_KEY", "")

# ── 테스트 케이스 5개 ────────────────────────────────────────────────────────
CASES = [
    {
        "label": "[일반①] 동네 헬스장",
        "brand_name": "피트니스 강남점",
        "industry": "헬스·피트니스",
        "product_name": "헬스장, PT, GX",
        "address": "서울 강남구 역삼로 123",
        "service_types": "오프라인 매장",
        "ages": "20대 / 30대 / 40대",
        "goal": "매출을 늘리고 싶다",
        "product_strengths": "가격이 합리적이다. / 트레이너 수가 많다. / 24시간 운영.",
        "tones": "친근하고 활기차게",
    },
    {
        "label": "[일반②] ISO인증 컨설팅",
        "brand_name": "한국인증센터",
        "industry": "경영컨설팅",
        "product_name": "ISO인증 컨설팅 및 교육 서비스",
        "address": "서울 마포구 마포대로 15",
        "service_types": "온라인 서비스 / 전국 서비스",
        "ages": "30대 / 40대 / 50대",
        "goal": "문의·상담을 늘리고 싶다",
        "product_strengths": "전문 인력이 직접 제공한다. / 처리 속도가 빠르다. / 결과·성과가 명확하다.",
        "tones": "전문가가 조언하는 느낌",
    },
    {
        "label": "[일반③] 유아동복 쇼핑몰",
        "brand_name": "꼬마옷장",
        "industry": "유아동복",
        "product_name": "유아동복, 아동잡화, 돌잔치 의상",
        "address": "경기 성남시 분당구 판교로 100",
        "service_types": "온라인 서비스 / 전국 서비스",
        "ages": "20대 / 30대",
        "goal": "매출을 늘리고 싶다",
        "product_strengths": "유기농 소재 사용. / 디자인이 예쁘다. / 세탁이 편하다.",
        "tones": "따뜻하고 감성적으로",
    },
    {
        "label": "[특수①] 뷰티학원 입시 전문",
        "brand_name": "뷰티아트아카데미",
        "industry": "뷰티학원",
        "product_name": "헤어·메이크업·네일 입시반, 자격증반",
        "address": "서울 강남구 논현동 50",
        "service_types": "오프라인 매장",
        "ages": "10대 / 20대",
        "goal": "문의·상담을 늘리고 싶다",
        "product_strengths": "입시 합격률 97%. / 현직 교수진 직강. / 포트폴리오 제작 지원.",
        "tones": "전문가가 조언하는 느낌",
    },
    {
        "label": "[특수②] 보험 설계사 모집 GA",
        "brand_name": "하나금융파트너스",
        "industry": "보험",
        "product_name": "보험영업 파트너 모집, 생명·손해보험 판매",
        "address": "서울 여의도동 63빌딩",
        "service_types": "전국 서비스",
        "ages": "20대 / 30대 / 40대",
        "goal": "매출을 늘리고 싶다",
        "product_strengths": "업계 최고 수수료. / 전담 교육 시스템. / 독립 GA라 자유로운 영업.",
        "tones": "단호하고 확신 있게",
    },
]

def research(case):
    brand = case['brand_name']
    industry = case['industry']
    product = case['product_name']
    city_m = re.search(r'([가-힣]+구|[가-힣]+시|[가-힣]+군)', case['address'])
    city = city_m.group(1) if city_m else ''
    goal = case['goal']

    body = {
        "model": "sonar",
        "messages": [{"role": "user", "content": f"""다음 업체를 조사하여 한국어로 상세히 분석해주세요.

업체명: {brand}
업종: {industry}
상품/서비스: {product}
지역: {city or '전국'}
고객 목표: {goal}

[필수 조사 항목]
1. 핵심 상품·서비스 목록
2. 실제 강점·차별화 포인트
3. 주요 타겟 고객 (소비자/구직자/수험생/사업자 중 명시)
4. 고객 Pain Point
5. 실제 네이버 검색 키워드 패턴
6. 세부 카테고리·하위 서비스

각 항목을 명확히 구분하여 작성하고, 확인되지 않은 내용은 추측하지 마세요."""}],
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
    addr = case['address']
    m = re.search(r'([가-힣]+동)(?=\s|\d|$)', addr)
    if m: dong = m.group(1)
    gu_m = re.search(r'([가-힣]+구)', addr)
    gu = gu_m.group(1) if gu_m else ''
    local_unit = dong or gu

    slot_def = """[슬롯 정의 — 오프라인/지역 서비스]
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
━━━━━━━━━━━━━━━━━━━━━━━━""" if research_text else "⚠️ 사전 조사 결과 없음 — 고객 제출 정보만으로 생성"

    system = f"""당신은 네이버 SEO 및 콘텐츠 마케팅 전문가다.

[작업 순서]
1단계: 사전 조사 결과와 고객 정보를 종합하여 실제 비즈니스 목적과 주요 타겟을 추론한다.
  - 겉으로 드러난 업종명이 아닌 실제 수익 목적을 파악하라.
  - 예) 뷰티학원이라도 입시 전문이면 타겟은 수험생
  - 예) 보험사라도 설계사 모집이 메인이면 타겟은 구직자
2단계: 확인된 서비스·강점에서 키워드를 최대한 파생한다.
3단계: tier1(핵심40%) / tier2(2순위30%) / tier3(롱테일30%) 4:3:3 비율로 분류한다.

{slot_def}
{offline_guide}

[타겟별 키워드 방향]
- 타겟이 구직자 → 취업·모집·채용 키워드 비중 높임
- 타겟이 수험생 → 입시·시험·준비 키워드 비중 높임
- 타겟이 사업자 → 창업·가맹·B2B 키워드 비중 높임

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
  "primary_target": "소비자100% 또는 구직자70%+소비자30% 등",
  "target_reasoning": "추론 근거",
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
        headers={
            "x-api-key": ANTHROPIC_KEY,
            "anthropic-version": "2023-06-01",
            "content-type": "application/json"
        },
        json={"model": "claude-sonnet-4-6", "max_tokens": 3000, "system": system,
              "messages": [{"role": "user", "content": user}]},
        timeout=60
    )
    raw = resp.json().get('content', [{}])[0].get('text', '').strip()
    js = re.sub(r'^```json?\n?', '', raw)
    js = re.sub(r'```\s*$', '', js).strip()
    return json.loads(js)

def pick_keyword(pool):
    """키워드 가져오기(plusA)1 노드 로직 재현 — 랜덤 1개 선택"""
    import random
    all_kws = []
    for slot in ['promo', 'info', 'convert']:
        sd = pool.get('keyword_pool', {}).get(slot, {})
        kws = sd if isinstance(sd, list) else (sd.get('tier1', []) + sd.get('tier2', []) + sd.get('tier3', []))
        for kw in kws:
            all_kws.append({'slot': slot, 'keyword': kw})
    return random.choice(all_kws) if all_kws else None

# ── 실행 ─────────────────────────────────────────────────────────────────────
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
    print(f"  ▶ 확인된 서비스: {', '.join(result.get('extracted_services', [])[:3])}")

    pool = result.get('keyword_pool', {})
    total = 0
    for slot in ['promo', 'info', 'convert']:
        sd = pool.get(slot, {})
        t1 = sd.get('tier1', [])
        t2 = sd.get('tier2', [])
        t3 = sd.get('tier3', [])
        cnt = len(t1) + len(t2) + len(t3)
        total += cnt
        print(f"\n  [{slot.upper()}] {cnt}개 (tier1:{len(t1)} tier2:{len(t2)} tier3:{len(t3)})")
        for kw in t1[:3]: print(f"    tier1 | {kw}")
        for kw in t2[:2]: print(f"    tier2 | {kw}")
        for kw in t3[:2]: print(f"    tier3 | {kw}")
        if cnt > 7: print(f"    ... 외 {cnt-7}개")

    print(f"\n  ▶ 총 키워드: {total}개")

    selected = pick_keyword(result)
    if selected:
        print(f"  ▶ 선택된 키워드 (랜덤): [{selected['slot']}] \"{selected['keyword']}\"")

    time.sleep(1)

print(f"\n{'='*65}\n테스트 완료\n")
