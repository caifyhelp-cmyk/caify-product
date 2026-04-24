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
- H2는 단계별 진행 순서와 핵심 작업을 독자가 이해할 수 있게 구성하세요.
- 완료 결과는 실제 확인 가능한 변화 중심으로 정리하세요.
""",
        "draft": """
- 본문은 프로젝트 개요 -> 단계별 진행 -> 완료 결과 순으로 작성하세요.
- 각 단계의 목적과 핵심 액션이 보이도록 작성하되 장황한 설명은 줄이세요.
""",
        "h2_hint": "작업 대상/목표 / 사전 점검 / 단계별 진행 / 핵심 이슈 대응 / 완료 결과 / 정리",
    },
    "consulting_qa": {
        "label": "상담/문의 사례",
        "analysis": """
- 입력은 질문 -> 답변 -> 상담 결과 흐름으로 해석하세요.
- 최종 결과물은 상담 기록 정리가 아니라 블로그 사례글입니다.
- H2는 '문의 배경', '핵심 질문', '답변 핵심'처럼 상담 항목명 그대로 쓰지 마세요.
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

    keyword_guidance = ""
    if target_keywords:
        keyword_guidance = f"""
[타겟 키워드]
- {target_keywords}
- 아래 title_candidates와 summary에 이 키워드를 자연스럽게 포함시키세요.
- 키워드를 억지로 반복하지 말고, 문맥에 맞게 1~2회 자연스럽게 녹이세요.
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

3) summary
- 100~200자
- 문제/핵심 내용/결과가 자연스럽게 이어지게

4) h2_sections
- 5~6개
- 유형 힌트: {tcfg['h2_hint']}
- H2는 반드시 블로그 사례글의 소제목처럼 자연스럽게 작성하세요.
- '문의 배경', '핵심 질문', '답변 핵심', '정리'처럼 상담 메모/상담일지 같은 표현은 사용 금지
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
본문 작성 요구사항:
1. JSON만 출력
2. body_draft 문자열로 출력
3. 도입부 후 H2별 본문 구성
4. H2는 반드시 `## 소제목` 형식
5. 원문에 없는 사실 생성 금지
6. 과도한 광고 문구 금지
7. 단락은 최대 2문장
8. 마무리 문단 1개 포함

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

