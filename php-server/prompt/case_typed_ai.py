# -*- coding: utf-8 -*-
import sys
import json
import base64

from case_short_ai import (
    generate_response,
    infer_industry,
    build_industry_guidance,
    build_body_draft_guidance,
    build_prompt_profile_text,
    infer_case_style,
    build_review_analysis_guidance,
    build_review_draft_guidance,
)


TYPE_CONFIG = {
    "problem_solve": {
        "label": "문제 해결 사례",
        "analysis": """
- 입력은 반드시 문제 -> 해결 -> 결과 흐름으로 해석하세요.
- H2도 문제 인식, 원인/진단, 해결 실행, 결과 변화 중심으로 구성하세요.
- 보고서 목차처럼 딱딱한 항목명이 아니라, 사례를 읽는 흐름 안에서 자연스럽게 읽히는 소제목으로 작성하세요.
- 원문에 있는 실제 해결 포인트를 우선 사용하고, 과장된 성과 문구는 피하세요.
""",
        "draft": """
- 본문은 문제 제기 -> 해결 방법 -> 변화/결과 흐름을 분명히 유지하세요.
- 해결 과정의 핵심 포인트를 독자가 따라가기 쉽게 짧은 단락으로 제시하세요.
""",
        "h2_hint": "문제 상황 / 원인 또는 진단 / 해결 방법 / 실행 포인트 / 결과 변화 / 정리",
    },
    "process_work": {
        "label": "작업/진행 과정 사례",
        "analysis": """
- 입력은 대상 -> 핵심 단계 -> 완료 결과 흐름으로 해석하세요.
- H2는 진행 순서를 기계적으로 나열하지 말고, 독자가 사례를 읽으며 자연스럽게 이해할 수 있는 블로그형 소제목으로 구성하세요.
- '작업 대상 및 목표', '사전 점검', '단계별 진행', '핵심 이슈 대응', '완료 결과', '정리' 같은 보고서/문서 목차형 표현은 사용하지 마세요.
- 완료 결과는 실제 확인 가능한 변화 중심으로 정리하세요.
""",
        "draft": """
- 본문은 사례 소개 -> 핵심 진행 내용 -> 결과 흐름이 자연스럽게 이어지게 작성하세요.
- 각 단계의 목적과 핵심 액션이 보이도록 작성하되 장황한 설명은 줄이세요.
""",
        "h2_hint": "처음 내원했을 때 보였던 상태 / 원인을 확인하기 위해 진행한 검사 / 치료 방향을 결정한 이유 / 실제로 진행한 치료 과정 / 치료 후 달라진 모습 / 보호자가 안심한 변화",
    },
    "consulting_qa": {
        "label": "상담/문의 사례",
        "analysis": """
- 입력은 질문 -> 답변 -> 상담 결과 흐름으로 해석하세요.
- 최종 결과물은 상담 기록 정리가 아니라 블로그 사례글입니다.
- H2는 '문의 배경', '핵심 질문', '답변 핵심'처럼 상담 항목명 그대로 쓰지 마세요.
- H2에 콜론(:)을 넣어 항목명처럼 쓰지 마세요.
- 대신 고객이 어떤 고민을 가졌고, 어떤 설명/가이드를 받았으며, 어떤 판단 변화가 생겼는지 사례형 소제목으로 바꾸세요.
- 독자가 읽었을 때 "이런 상담을 통해 어떤 도움을 받았는지"가 드러나는 블로그형 흐름으로 구성하세요.
- 고객의 오해/불안을 어떻게 해소했는지 요약을 포함하세요.
""",
        "draft": """
- 본문은 문의 내용, 답변 포인트, 상담 후 변화가 자연스럽게 이어지게 작성하세요.
- 과도한 홍보 대신 실제 상담 맥락과 이해 포인트 중심으로 구성하세요.
- 문단 제목도 상담 메모처럼 딱딱하게 쓰지 말고, 사례를 읽는 독자가 이해하기 쉬운 블로그 소제목으로 풀어주세요.
""",
        "h2_hint": "고객이 궁금해한 상황 / 상담이 필요했던 이유 / 핵심 안내 포인트 / 이해를 도운 설명 / 상담 후 판단 변화 / 정리",
    },
    "review_experience": {
        "label": "고객 경험/후기",
        "analysis": """
- 입력은 만족 포인트 -> 고객 반응 -> 강조 포인트 흐름으로 해석하세요.
- 소개글이 아니라 실제 후기 요약형으로 H2를 구성하세요.
- 홍보형 문장보다 체감 포인트(청결, 친절, 속도, 전문성, 가성비 등)를 우선 정리하세요.
""",
        "draft": """
- 본문은 후기형 문체를 유지하고, 체감 포인트를 짧고 읽기 쉽게 전달하세요.
- 원문에 없는 운영 프로세스/배경 설명을 새로 만들지 마세요.
""",
        "h2_hint": "첫인상 / 만족 포인트 / 실제 반응 / 재이용 또는 추천 의사 / 총평",
    },
}


def type_cfg(case_input_type: str) -> dict:
    return TYPE_CONFIG.get(case_input_type, {
        "label": "일반 사례",
        "analysis": "- 유형 정보가 불명확하므로 원문 맥락 중심으로 구조화하세요.",
        "draft": "- 유형 정보가 불명확하므로 문제-과정-결과 중심 설명형 본문으로 작성하세요.",
        "h2_hint": "배경 / 핵심 내용 / 진행 / 결과 / 정리",
    })


def is_animal_case(inferred_industry: str, case_title: str, raw_content: str, prompt_profile: dict) -> bool:
    text = " ".join([
        str(inferred_industry or ""),
        str(case_title or ""),
        str(raw_content or ""),
        str((prompt_profile or {}).get("industry", "") or ""),
        str((prompt_profile or {}).get("product_name", "") or ""),
    ])
    keywords = ["동물병원", "수의", "반려동물", "강아지", "고양이", "반려견", "반려묘", "견종", "묘종"]
    return any(keyword in text for keyword in keywords)


def generate_analysis_prompt_typed(data: dict) -> str:
    case_title = data.get("case_title", "")
    raw_content = data.get("raw_content", "")
    case_input_type = str(data.get("case_input_type", "")).strip()
    target_keywords = str(data.get("target_keywords", "")).strip()
    prompt_profile = data.get("prompt_profile", {}) or {}

    tcfg = type_cfg(case_input_type)
    inferred_industry = infer_industry(f"{case_title}\n{raw_content}")
    industry_guidance = build_industry_guidance(inferred_industry)
    case_style = infer_case_style(case_title, raw_content, {})
    if case_input_type == "review_experience":
        case_style = "review"
    prompt_profile_text = build_prompt_profile_text(prompt_profile)
    animal_case = is_animal_case(inferred_industry, case_title, raw_content, prompt_profile)

    keyword_guidance = ""
    if target_keywords:
        keyword_guidance = f"""
[타겟 키워드]
- {target_keywords}
- 아래 title_candidates와 summary에 이 키워드를 자연스럽게 포함시키세요.
- 키워드를 억지로 반복하지 말고, 문맥에 맞게 1~2회 자연스럽게 녹이세요.
"""

    animal_case_guidance = ""
    if animal_case:
        animal_case_guidance = """
[동물병원/반려동물 사례 추가 규칙]
- 원문에 동물의 이름이 있으면 제목 후보(title_candidates)에 그 이름을 자연스럽게 녹여 쓰세요.
- 단, 이름만 억지로 앞에 붙이지 말고 실제 사례 제목처럼 자연스럽게 포함시키세요.
- 예: "10살 고양이 코코가 잦은 구토를 보인 이유", "코코가 이물질을 삼킨 뒤 회복까지의 과정"
- 제목은 딱딱한 의무기록/진료차트 말투가 아니라, 실제 동물병원 블로그에서 보호자가 편하게 읽을 수 있는 부드럽고 친근한 톤으로 작성하세요.
- 제목은 "증례 보고", "케이스 분석", "작업 대상", "진행 단계"처럼 문서형 표현을 피하고, 보호자가 궁금해할 만한 상황과 변화를 중심으로 쓰세요.
- 병원명, 동물병원 브랜드명, 원장/수의사 이름이 원문이나 프롬프트 정보에 있으면 제목 후보 3개 중 1개 이상에는 그 정보를 자연스럽게 녹여도 됩니다.
- 단, 병원명/원장명을 억지로 반복하거나 과장 광고처럼 쓰지 말고, 실제 블로그 제목처럼 자연스럽게 포함시키세요.
- structured.subject_label에도 가능하면 이름, 나이, 동물 종류가 자연스럽게 드러나게 정리하세요.
- summary에도 이름이 있으면 1회 정도 자연스럽게 포함해 독자가 대상 정보를 바로 이해할 수 있게 하세요.
- H2도 전체가 딱딱한 의료 보고서처럼 보이지 않게 작성하세요.
- 원문에 동물 이름이 있으면 H2 5~6개 중 최소 1개는 그 이름이 자연스럽게 들어가게 작성하세요.
- 예: "코코가 처음 보여준 이상 신호", "코코가 다시 밥을 먹기까지 확인한 점"
- 이름이 없으면 새로 만들지 마세요.
- 동물병원 이름이 있다면 제목에 자연스럽게 넣어줘
"""

    return f"""
다음은 유형이 지정된 고객 사례 원문입니다. 원문을 구조화하고 블로그용 초안을 생성하세요.

[입력 데이터]
- 사례 유형: {tcfg['label']} ({case_input_type or '미지정'})
- 사례명: {case_title or '없음'}
- 자유 입력 원문: {raw_content or '없음'}

[사전 추정 업종]
- {inferred_industry}

[업종별 작성 가이드]
{industry_guidance}

[유형별 분석 가이드]
{tcfg['analysis']}

[사례 문체 가이드]
{build_review_analysis_guidance(inferred_industry) if case_style == "review" else "- 일반 사례형 구조로 분석하세요."}

[브랜드/마케팅 프롬프트 정보]
{prompt_profile_text}
{keyword_guidance}
{animal_case_guidance}
요구사항:
1) structured
- industry_category
- case_category
- subject_label
- problem_summary
- process_summary
- result_summary

2) title_candidates
- 블로그 제목 후보 3개를 배열로 제공
- 각 제목은 SEO 친화적, 30~60자
- 사례 유형과 핵심 결과가 드러나게
- 3개 모두 다른 관점/스타일로 작성 (정보형, 결과 강조형, 독자 공감형)
- 제목은 블로그 독자가 읽기 편한 자연스러운 문장형으로 작성하고, 딱딱한 보고서/차트/브리핑 말투는 피하세요.
- 세 제목 모두 "본문이 궁금해지는 후킹 요소"를 반드시 포함하세요.
- 예: `왜 이런 판단을 했는지`, `어떤 이유가 있었는지`, `무엇이 달라졌는지`, `어떻게 해결했는지`, `보호자가 안심한 포인트는 무엇이었는지`
- 질문형, 이유형, 변화형, 반전형 표현을 자연스럽게 활용하되 과한 낚시성 문구는 금지합니다.
- "A를 한 이유는?", "A가 달라진 이유", "A 후 달라진 점", "왜 이 검사를 먼저 했을까" 같은 형태를 적극 활용하세요.
- 사람 이름, 병원명, 원장명, 제품명, 서비스명이 원문에 있으면 그 고유명을 자연스럽게 넣어 제목의 주목도를 높여도 됩니다.
- 단순 요약형 제목 3개를 내지 말고, 적어도 2개 이상은 읽는 사람이 다음 문단을 기대하게 만드는 제목으로 작성하세요.

3) summary
- 100~200자
- 문제/핵심 내용/결과가 자연스럽게 이어지게

4) h2_sections
- 5~6개
- 유형 힌트: {tcfg['h2_hint']}
- H2는 반드시 블로그 사례글의 소제목처럼 자연스럽게 작성하세요.
- H2 5~6개 중 최소 2개 이상은 "이유", "왜", "달라진 점"을 포함해야 합니다.
- 최소 1개 이상 최대 2개는 의문문("?")으로 끝나야 합니다.
- 위 조건을 만족하지 않으면 잘못된 결과로 간주합니다.

- 다음과 같은 단순 명사형 제목은 금지합니다:
  예: "전문가의 진단 과정", "알레르기 테스트의 중요성"

- 반드시 아래 패턴 중 일부를 사용하세요:
  - ~하게 된 이유는?
  - 왜 ~를 먼저 했을까
  - ~ 후 달라진 점
  - ~가 필요했던 이유

[좋은 예시]
- 알레르기 테스트를 먼저 진행한 이유는?
- 코코가 다시 밥을 먹게 된 결정적인 변화
- 초기에 놓치기 쉬운 신호는 무엇이었을까

- '문의 배경', '핵심 질문', '답변 핵심', '정리'처럼 상담 메모/상담일지 같은 표현은 사용 금지
- '작업 대상 및 목표', '사전 점검', '단계별 진행', '핵심 이슈 대응', '완료 결과'처럼 보고서/문서 목차형 표현도 사용 금지
- 각 H2는 항목명이나 라벨처럼 쓰지 말고, 블로그 본문에 바로 들어가도 어색하지 않은 자연스러운 한국어 제목으로 작성하세요.
- 제목 중간에 콜론(:)을 넣지 마세요.
- `무엇을 했는지`만 적는 딱딱한 제목보다, `왜 그 상황이 중요했는지`나 `어떤 변화가 있었는지`가 느껴지는 제목을 우선하세요.
- 독자가 검색으로 읽었을 때 도움이 되는 사례형 문장으로 바꾸세요.
- 원문에 없는 세부 사실 생성 금지

JSON 출력 형식:
{{
  "structured": {{
    "industry_category": "",
    "case_category": "",
    "subject_label": "",
    "problem_summary": "",
    "process_summary": "",
    "result_summary": ""
  }},
  "title_candidates": ["제목1", "제목2", "제목3"],
  "summary": "",
  "h2_sections": ["", "", "", "", "", ""]
}}

제약사항:
- 반드시 순수 JSON만 출력
- 한국어로 작성
- 과장 표현 금지
- 리뷰형이면 후기형 체감 포인트 중심으로 정리
"""


def generate_draft_prompt_typed(data: dict) -> str:
    case_title = data.get("case_title", "")
    raw_content = data.get("raw_content", "")
    case_input_type = str(data.get("case_input_type", "")).strip()
    target_keywords = str(data.get("target_keywords", "")).strip()
    ai_title = data.get("ai_title", "")
    ai_summary = data.get("ai_summary", "")
    h2_sections = data.get("ai_h2_sections", []) or []
    structured = data.get("structured", {}) or {}
    prompt_profile = data.get("prompt_profile", {}) or {}

    tcfg = type_cfg(case_input_type)
    inferred_industry = structured.get("industry_category") or infer_industry(
        f"{case_title}\n{raw_content}\n{ai_title}\n{ai_summary}"
    )
    body_guidance = build_body_draft_guidance(inferred_industry)
    prompt_profile_text = build_prompt_profile_text(prompt_profile)
    case_style = infer_case_style(case_title, raw_content, structured)
    if case_input_type == "review_experience":
        case_style = "review"
    animal_case = is_animal_case(inferred_industry, case_title, raw_content, prompt_profile)

    h2_text = "\n".join([f"- {item}" for item in h2_sections if str(item).strip() != ""]) or "- H2 없음"
    structured_lines = "\n".join([
        f"- 업종: {structured.get('industry_category', '')}",
        f"- 사례 유형: {structured.get('case_category', '')}",
        f"- 대상 요약: {structured.get('subject_label', '')}",
        f"- 문제 상황: {structured.get('problem_summary', '')}",
        f"- 진행 과정: {structured.get('process_summary', '')}",
        f"- 결과 요약: {structured.get('result_summary', '')}",
    ])

    keyword_guidance = ""
    if target_keywords:
        keyword_guidance = f"""
[타겟 키워드]
- {target_keywords}
- 본문 도입부와 마무리에 이 키워드를 자연스럽게 1~2회 포함시키세요.
"""

    animal_case_guidance = ""
    if animal_case:
        animal_case_guidance = """
[동물병원/반려동물 사례 추가 규칙]
- 원문이나 구조화 결과에 동물의 이름이 있으면 본문 도입부 또는 첫 번째 관련 문단에서 그 이름을 자연스럽게 언급하세요.
- 이름만 반복하지 말고, 나이/동물 종류/상황 정보와 함께 자연스럽게 섞어 쓰세요.
- 예: "코코는 10살 고양이로, 최근 2주간 구토가 반복돼 보호자의 걱정이 컸습니다."
- 치료 전 상태, 검사 과정, 회복 변화 설명에도 이름이 어색하지 않은 범위에서 자연스럽게 드러나게 작성하세요.
- 원문이나 프롬프트 정보에 병원명 또는 원장/수의사 이름이 있으면 도입부나 마무리에서 한 번 정도 자연스럽게 언급할 수 있습니다.
- 단, 병원명이나 원장명을 광고처럼 반복하지 말고 실제 동물병원 블로그 후기/사례글처럼 부드럽고 친근한 톤으로 녹여 쓰세요.
- 보호자가 읽는 글처럼 너무 딱딱한 의료 보고서 문체는 피하고, 설명은 전문적이되 말투는 부드럽게 유지하세요.
- 이름이 없으면 새로 만들지 마세요.
"""

    return f"""
다음은 유형이 지정된 고객 사례 원문과 블로그 구조입니다. 본문 초안을 작성하세요.

[원문 정보]
- 사례 유형: {tcfg['label']} ({case_input_type or '미지정'})
- 사례명: {case_title}
- 자유 입력 원문: {raw_content}

[AI 구조화 결과]
{structured_lines}

[블로그 구조]
- 블로그 제목: {ai_title}
- 요약: {ai_summary}
- H2 목록:
{h2_text}

[업종별 본문 가이드]
- 추정 업종: {inferred_industry}
{body_guidance}

[유형별 본문 가이드]
{tcfg['draft']}

[사례 문체 가이드]
{build_review_draft_guidance(inferred_industry) if case_style == "review" else "- 일반 사례형 설명 문체를 유지하세요."}

[브랜드/마케팅 프롬프트 정보]
{prompt_profile_text}
{keyword_guidance}
{animal_case_guidance}
본문 작성 요구사항:
1. JSON만 출력
2. body_draft 문자열로 출력
3. 도입부 후 H2별 본문 구성
4. H2는 반드시 `## 소제목` 형식
5. 위에 제공된 H2 문구를 절대 수정하거나 재작성하지 말고 그대로 사용하세요.
6. 콜론 추가, 단어 치환, 순서 변경, 축약, 확장 모두 금지합니다.
7. 원문에 없는 사실 생성 금지
8. 과도한 광고 문구 금지
9. 단락은 최대 2문장
10. 마무리 문단 1개 포함

JSON 출력 형식:
{{
  "body_draft": "도입부...\\n\\n## 소제목 1\\n본문..."
}}
"""


if __name__ == "__main__":
    if len(sys.argv) <= 1:
        print(json.dumps({"error": "No case data provided."}, ensure_ascii=False))
        sys.exit(0)

    response = ""
    try:
        raw = base64.b64decode(sys.argv[1]).decode("utf-8")
        data = json.loads(raw)
        mode = (data.get("mode") or "analyze").strip().lower()

        if mode == "draft":
            prompt_text = generate_draft_prompt_typed(data)
        else:
            prompt_text = generate_analysis_prompt_typed(data)

        response = generate_response(prompt_text)
        parsed = json.loads(response)
        print(json.dumps({"response": parsed}, ensure_ascii=False))
    except json.JSONDecodeError:
        print(json.dumps({"error": "AI 응답 JSON 파싱 실패", "raw": response}, ensure_ascii=False))
    except Exception:
        print(json.dumps({"error": "사례 데이터 처리 중 오류 발생"}, ensure_ascii=False))

