import json, sys, uuid, io

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')

with open('C:/Users/조경일/caify-product/wf_live2.json', 'r', encoding='utf-8') as f:
    d = json.load(f)

nodes = d['nodes']
conn = d['connections']
ok, warn = [], []

def find_node(name):
    for n in nodes:
        if n['name'] == name:
            return n

# ══════════════════════════════════════════════════════════════
# 1. 이미지 생성 — openai/gpt-image-2로 통일
# ══════════════════════════════════════════════════════════════
fal1 = find_node("fal Generate1")
if fal1:
    if 'nano-banana-pro' in fal1['parameters'].get('url', ''):
        fal1['parameters']['url'] = 'https://queue.fal.run/openai/gpt-image-2'
        ok.append("1a. fal Generate1: nano-banana-pro → openai/gpt-image-2")
    else:
        ok.append("1a. fal Generate1 이미 gpt-image-2")
else:
    warn.append("1a. fal Generate1 없음")

fal4 = find_node("fal Generate4")
if fal4:
    if 'gpt-image-1.5' in fal4['parameters'].get('url', ''):
        fal4['parameters']['url'] = 'https://queue.fal.run/openai/gpt-image-2'
        ok.append("1b. fal Generate4: gpt-image-1.5 → gpt-image-2")
    else:
        ok.append("1b. fal Generate4 이미 gpt-image-2")
else:
    warn.append("1b. fal Generate4 없음")

# ══════════════════════════════════════════════════════════════
# 2. KB 검색 노드 추가 (검색의도_H2생성 → KB 검색 → 글생성)
# ══════════════════════════════════════════════════════════════
if not find_node("KB 검색"):
    kb_id = str(uuid.uuid4())
    kb_body = (
        '={\n'
        '  "member_pk": {{ $(\'콘텐츠 슬롯 구성1\').item.json.member_pk }},\n'
        '  "top_k": 6,\n'
        '  "min_score": 0.25,\n'
        '  "query": {{ JSON.stringify(\n'
        '    String(($("프롬프트생성1").item.json._meta?.selectedKeywords || []).join(" ")) + "\\n" +\n'
        '    String($("검색의도_H2생성").item.json.output?.intent || "") + "\\n" +\n'
        '    String(($("검색의도_H2생성").item.json.output?.searchQuestions || []).join("\\n")) + "\\n" +\n'
        '    String(($("검색의도_H2생성").item.json.output?.h2_outline || []).join("\\n"))\n'
        '  ) }}\n'
        '}'
    )
    KB_NODE = {
        "parameters": {
            "method": "POST",
            "url": "https://caify.ai/prompt/kb_n8n_search.php",
            "sendBody": True,
            "contentType": "raw",
            "rawContentType": "application/json",
            "body": kb_body,
            "options": {}
        },
        "type": "n8n-nodes-base.httpRequest",
        "typeVersion": 4.2,
        "position": [36000, 5328],
        "id": kb_id,
        "name": "KB 검색"
    }
    nodes.append(KB_NODE)
    conn['검색의도_H2생성']['main'][0] = [{"node": "KB 검색", "type": "main", "index": 0}]
    conn['KB 검색'] = {"main": [[{"node": "글생성", "type": "main", "index": 0}]]}
    ok.append("2. KB 검색 노드 추가 + 연결")
else:
    ok.append("2. KB 검색 이미 존재")

# ══════════════════════════════════════════════════════════════
# 3. 글생성 업데이트
# ══════════════════════════════════════════════════════════════
gen = find_node("글생성")
if gen:
    txt = gen['parameters'].get('text', '')

    # 3a. KB CONTEXT 추가 (프롬프트 맨 앞)
    KB_SECTION = (
        "[MEMBER KB CONTEXT — 고객이 직접 업로드한 자료, 최우선 참고]\n"
        "{{ $('KB 검색').item.json.context || '관련 업로드 자료 없음' }}\n\n"
        "Rules for MEMBER KB CONTEXT:\n"
        "- 업체 실제 서비스·절차·조건·주의사항·수치는 이 자료에서 그대로 사용한다\n"
        "- 이 자료에 없는 사례·수치·보증·법적 내용은 만들어내지 않는다\n"
        "- KB, 벡터, 임베딩 같은 내부 단어는 본문에 절대 노출하지 않는다\n"
        "- 자료가 비어있으면 키워드·브랜드·프롬프트 컨텍스트만으로 작성한다\n"
        "- Perplexity 조사 자료와 충돌 시 이 자료 우선\n\n"
        "--------------------------------\n\n"
    )
    if "MEMBER KB CONTEXT" not in txt:
        txt = KB_SECTION + txt
        ok.append("3a. KB CONTEXT 섹션 추가 (최우선)")
    else:
        ok.append("3a. KB CONTEXT 이미 있음")

    # 3b. 도입부 훅 규칙 강화
    OLD_HOOK = (
        "- situation: 독자가 처한 구체 상황을 묘사 (\"계약서에 도장 찍고 나서야 알았다\")\n"
        "  * mistake: 흔한 실수를 짚어주며 시작 (\"대부분 이 단계에서 빠뜨린다\")\n"
        "  * question: 독자의 내면 질문으로 시작 (\"이거 그냥 진행해도 되는 걸까?\")\n"
        "  * contrast: 기대와 현실의 차이로 시작 (\"다 비슷해 보이지만 실제로는 결과가 다르다\")"
    )
    NEW_HOOK = (
        "- situation: 독자가 처한 구체 상황 묘사 (\"지난달 계약서에 도장 찍고 나서야 특약 하나를 빼먹은 걸 알았다\")\n"
        "  * mistake: 흔한 실수를 짚으며 시작 (\"대부분 이 단계를 건너뛴다. 그리고 나중에 후회한다\")\n"
        "  * question: 독자의 내면 질문으로 시작 (\"이거 그냥 진행해도 되는 건지, 아니면 한 번 더 확인해야 하는 건지\")\n"
        "  * contrast: 기대와 현실 차이로 시작 (\"겉으로는 다 비슷해 보인다. 근데 실제로 써보면 결과가 꽤 다르다\")\n\n"
        "도입부 금지:\n"
        "- \"OO에 대해 알아보겠습니다\" / \"OO가 중요한 이유를 살펴보겠습니다\"\n"
        "- \"이번 글에서는 OO을 정리했습니다\"\n"
        "- 글의 구조나 목차를 미리 안내하는 도입부\n"
        "- 핵심 없이 배경 설명만 늘어놓는 도입부\n"
        "도입부는 반드시 독자가 \"이건 나한테 필요한 글이다\" 또는 \"이 부분 나도 헷갈렸는데\" 라고 느끼게 해야 한다."
    )
    if OLD_HOOK in txt:
        txt = txt.replace(OLD_HOOK, NEW_HOOK)
        ok.append("3b. 도입부 훅 규칙 강화")
    else:
        warn.append("3b. 도입부 훅 패턴 없음")

    # 3c. 제목 CTR 규칙 강화
    OLD_TITLE = (
        "- \"~의 중요성\", \"~완벽 가이드\", \"~총정리\" 같은 표현 금지\n"
        "- 너무 교과서형 제목 금지"
    )
    NEW_TITLE = (
        "- \"~완벽 가이드\", \"~총정리\", \"~의 중요성\", \"~에 대해 알아보자\", \"~이란 무엇인가\" 금지\n"
        "- 교과서형·정보 나열형 제목 금지\n"
        "- 제목은 검색자가 클릭하고 싶게 만들어야 한다 — 정보가 바로 느껴지거나 궁금증이 생기거나 내 상황과 맞아야 한다\n"
        "- 아래 유형 중 내용에 가장 자연스러운 것을 선택한다 (매번 다르게, 반복 금지):\n"
        "  * 독자 상황형: \"OO 알아보다가 놓치는 것들\"\n"
        "  * 반전/의외형: \"OO, 사실 이 부분이 제일 중요했다\"\n"
        "  * 숫자 구체형: \"OO 결정 전 확인해야 할 3가지\"\n"
        "  * 질문형: \"OO, 어디서 받는 게 맞을까\"\n"
        "  * 결과형: \"OO 제대로 하면 달라지는 것들\""
    )
    if OLD_TITLE in txt:
        txt = txt.replace(OLD_TITLE, NEW_TITLE)
        ok.append("3c. 제목 CTR 규칙 강화 (5가지 패턴)")
    else:
        warn.append("3c. 제목 규칙 패턴 없음")

    # 3d. 볼드 선택→필수
    OLD_BOLD = "- 핵심 키워드, 비교 기준, 바로 봐야 하는 조건이나 문구는 Markdown 볼드(** **)를 제한적으로 사용할 수 있다\n- 한 섹션에서 볼드는 최대 3회까지만 사용한다"
    NEW_BOLD = (
        "- 핵심 조건, 비교 기준, 판단 포인트, 독자가 바로 봐야 하는 문구는 Markdown 볼드(**텍스트**)로 반드시 강조한다\n"
        "- 볼드 없이 본문만 이어지는 섹션이 2개 이상 연속되면 실패다\n"
        "- 한 섹션에서 볼드는 1~3회 (0회는 실패, 4회 이상은 과잉)"
    )
    if OLD_BOLD in txt:
        txt = txt.replace(OLD_BOLD, NEW_BOLD)
        ok.append("3d. 볼드 선택→필수 (0회 실패)")
    else:
        warn.append("3d. 볼드 규칙 패턴 없음")

    # 3e. 구조화 배분 — 리스트 최소 2회 추가
    OLD_STRUCT = "- 글 전체에서 표는 최소 1개\n- 글 전체에서 볼드 소제목 구조는 최소 1~2개 섹션에서 사용"
    NEW_STRUCT = (
        "- 글 전체에서 표는 최소 1개\n"
        "- 글 전체에서 번호 리스트 또는 하이픈 리스트는 최소 2회 이상\n"
        "- 글 전체에서 볼드 소제목 구조는 최소 1~2개 섹션에서 사용\n"
        "- 모든 섹션이 문단으로만 구성되면 실패\n"
        "- 같은 구조화 요소만 반복하지 않는다"
    )
    if OLD_STRUCT in txt:
        txt = txt.replace(OLD_STRUCT, NEW_STRUCT)
        ok.append("3e. 구조화 배분 규칙 (리스트 최소 2회 추가)")
    else:
        warn.append("3e. 구조화 배분 패턴 없음")

    gen['parameters']['text'] = txt
else:
    warn.append("3. 글생성 없음")

# ══════════════════════════════════════════════════════════════
# 저장
# ══════════════════════════════════════════════════════════════
with open('C:/Users/조경일/caify-product/wf_patched_v9.json', 'w', encoding='utf-8') as f:
    json.dump(d, f, ensure_ascii=False, indent=2)

print("=== 결과 ===")
for m in ok:   print(f"OK  {m}")
for m in warn: print(f"WARN {m}")
