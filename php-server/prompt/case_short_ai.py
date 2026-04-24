# -*- coding: utf-8 -*-
import os
import sys
import json
import base64
from openai import OpenAI
from dotenv import load_dotenv

load_dotenv()

openai_api_key = os.getenv("OPENAI_API_KEY")

if not openai_api_key or openai_api_key == "YOUR_OPENAI_API_KEY_HERE":
    print(json.dumps({
        "error": "OpenAI API 키가 설정되지 않았습니다."
    }, ensure_ascii=False))
    sys.exit(0)

client = OpenAI(api_key=openai_api_key)
model_name = "gpt-4o-mini"

INDUSTRY_KEYWORDS = {
    "동물병원": [
        "강아지", "고양이", "반려동물", "슬개골", "중성화", "내원", "보호자",
        "수의사", "엑스레이", "초음파", "입원", "진료", "수술", "재활"
    ],
    "병원/의원": [
        "환자", "치료", "시술", "내시경", "도수치료", "정형외과", "치과", "한의원",
        "피부과", "검진", "통증", "회복", "의사", "간호", "처치"
    ],
    "법률": [
        "법무", "변호사", "소송", "이혼", "상속", "형사", "민사", "합의",
        "고소", "법률", "자문", "계약서", "분쟁"
    ],
    "세무/회계": [
        "세무", "기장", "부가세", "종합소득세", "법인세", "절세", "회계", "세금",
        "신고", "매출", "매입", "원천세"
    ],
    "컨설팅/비즈니스": [
        "컨설팅", "법인전환", "법인설립", "사업계획", "운영 전략", "브랜딩", "조직",
        "대표", "스타트업", "경영", "자문", "프로세스"
    ],
    "인테리어/시공": [
        "리모델링", "인테리어", "시공", "타일", "도배", "욕실", "주방", "싱크대",
        "상가", "평수", "철거", "마감", "현장"
    ],
    "뷰티/에스테틱": [
        "피부", "리프팅", "레이저", "관리", "에스테틱", "미용", "탄력", "모공",
        "색소", "여드름", "시술 후", "원장"
    ],
    "교육": [
        "학생", "학부모", "수업", "학습", "코칭", "입시", "성적", "교육",
        "강의", "학원", "커리큘럼"
    ],
    "부동산": [
        "매매", "전세", "임대", "부동산", "매물", "분양", "상가 임대", "투자",
        "입주", "거래"
    ],
}


def infer_industry(text: str) -> str:
    normalized = (text or "").lower()
    best_industry = "일반 서비스"
    best_score = 0

    for industry, keywords in INDUSTRY_KEYWORDS.items():
        score = 0
        for keyword in keywords:
            score += normalized.count(keyword.lower())
        if score > best_score:
            best_score = score
            best_industry = industry

    return best_industry


def build_industry_guidance(industry: str) -> str:
    guidance_map = {
        "동물병원": """
- 보호자 관점의 공감 포인트를 반영하세요.
- 진단, 수술, 회복 경과처럼 치료 흐름이 드러나도록 구성하세요.
- 자극적인 표현보다 신뢰, 안전, 회복 경과를 강조하세요.
""",
        "병원/의원": """
- 환자의 증상, 검사, 시술/치료 과정, 회복 변화를 중심으로 구조화하세요.
- 의료광고처럼 과장하지 말고 사실 기반의 설명형 톤을 유지하세요.
""",
        "법률": """
- 사건 배경, 쟁점, 대응 전략, 결과 순으로 정리하세요.
- 확정적 표현이나 과장 대신 절차적 설명과 신뢰감 있는 톤을 사용하세요.
""",
        "세무/회계": """
- 절세, 신고, 구조 개선 같은 실무 문제 해결 흐름이 보이게 구성하세요.
- 숫자/효율/리스크 관리 관점의 제목과 소제목을 선호하세요.
""",
        "컨설팅/비즈니스": """
- 문제 진단, 전략 수립, 실행, 개선 결과 흐름이 드러나게 작성하세요.
- 의사결정과 성과 변화가 명확히 느껴지도록 정리하세요.
""",
        "인테리어/시공": """
- 시공 전 문제, 현장 상황, 시공 과정, 완료 후 변화가 잘 보이게 하세요.
- 공간 변화와 사용성 개선이 드러나는 제목/H2를 선호하세요.
""",
        "뷰티/에스테틱": """
- 시술 전 고민, 상담 포인트, 진행 과정, 이후 변화 중심으로 구성하세요.
- 후기형 톤은 살리되 과장 없이 자연스럽고 세련되게 작성하세요.
""",
        "교육": """
- 학습 전 고민, 지도 방식, 학습 변화, 성과 흐름으로 정리하세요.
- 학부모/학생이 공감할 수 있는 구체적인 변화 포인트를 담으세요.
""",
        "부동산": """
- 고객 니즈, 매물/전략 검토, 진행 과정, 최종 결과 흐름을 명확히 하세요.
- 신뢰와 실행력을 느낄 수 있는 현실적인 표현을 사용하세요.
""",
    }
    return guidance_map.get(industry, """
- 업종을 특정할 수 없으면 일반 서비스 사례로 보고 문제, 진행 과정, 결과 중심으로 정리하세요.
- 실제 사례 기반의 설명형 톤을 유지하세요.
""")


def build_body_draft_guidance(industry: str) -> str:
    guidance_map = {
        "동물병원": """
- 보호자가 읽는다는 전제로, 증상-진단-수술/치료-회복 흐름이 자연스럽게 이어지게 작성하세요.
- 의료 광고처럼 과장하지 말고, 실제 경과와 관리 포인트를 설명형으로 풀어주세요.
""",
        "병원/의원": """
- 환자의 고민, 검사/진단 포인트, 치료 과정, 회복/변화 흐름으로 서술하세요.
- 신뢰 중심의 설명형 톤을 유지하고, 단정적 과장 표현은 피하세요.
""",
        "법률": """
- 사건 배경, 핵심 쟁점, 대응 과정, 결과를 차분하고 신뢰감 있게 정리하세요.
- 법률 문장처럼 너무 딱딱하지 않되, 전문성이 느껴지게 작성하세요.
""",
        "세무/회계": """
- 문제 상황, 절세/신고 전략, 실제 적용 과정, 최종 효과를 실무형 글로 작성하세요.
- 숫자와 변화 포인트를 명확히 보여주세요.
""",
        "컨설팅/비즈니스": """
- 문제 진단, 실행 전략, 변화, 성과가 드러나는 사례형 글로 작성하세요.
- 대표나 실무자가 읽을 때 바로 이해될 수 있는 문장으로 구성하세요.
""",
        "인테리어/시공": """
- 시공 전 불편, 현장 점검, 진행 과정, 완성 후 변화가 생생하게 느껴지게 작성하세요.
- 공간 사용성과 변화의 체감 포인트를 담아주세요.
""",
        "뷰티/에스테틱": """
- 시술 전 고민, 상담 포인트, 진행 과정, 이후 변화와 만족감을 자연스럽게 이어주세요.
- 후기형 문장과 정보형 설명이 균형 있게 섞이게 하세요.
""",
        "교육": """
- 학습 전 상태, 지도 방식, 변화 과정, 결과와 의미를 이해하기 쉽게 작성하세요.
- 학부모와 학생이 모두 공감할 수 있는 문장으로 풀어주세요.
""",
        "부동산": """
- 니즈 파악, 검토 과정, 실행, 최종 결과 흐름을 현실적으로 작성하세요.
- 신뢰와 실행 경험이 느껴지도록 서술하세요.
""",
    }
    return guidance_map.get(industry, """
- 일반 서비스 사례처럼 문제, 과정, 결과가 자연스럽게 이어지는 설명형 글을 작성하세요.
- 지나치게 마케팅 문구처럼 쓰지 말고 실제 사례 중심으로 풀어주세요.
""")


def infer_case_style(case_title: str, raw_content: str, structured: dict) -> str:
    joined = " ".join([
        str(case_title or ""),
        str(raw_content or ""),
        str((structured or {}).get("case_category", "")),
    ]).lower()

    review_keywords = [
        "리뷰", "후기", "방문 후기", "이용 후기", "체험 후기", "숙박 후기",
        "좋았", "만족", "편안", "깨끗", "조용", "쉬기", "추천", "꿀잠",
        "잘 쉬", "다녀왔", "지내다 왔", "만족도", "재방문",
    ]

    review_score = sum(1 for keyword in review_keywords if keyword in joined)
    if review_score >= 2:
        return "review"

    return "case"


def build_review_analysis_guidance(industry: str) -> str:
    return f"""
- 이 사례는 일반 문제 해결형 케이스보다 '실사용 후기/리뷰' 성격에 가깝습니다.
- 업종은 {industry}일 수 있으나, 글의 중심은 서비스 소개가 아니라 실제 이용자의 체감 포인트입니다.
- H2는 '장소 소개', '고객 니즈 충족' 같은 홍보형 제목보다 실제 리뷰에서 언급된 만족 요소 중심으로 잡으세요.
- 예: 청결, 조용한 분위기, 위치/환경, 공간 구성, 침구/수면감, 재방문 의사, 함께 간 인원의 만족도
- 원문에 없는 문제 해결 과정이나 운영 프로세스를 억지로 만들지 마세요.
"""


def build_review_draft_guidance(industry: str) -> str:
    return f"""
- 이 원문은 {industry} 업종일 수 있지만, 본문은 '소개글'보다 '리뷰 정리형 후기'로 써야 합니다.
- 업체 소개, 고객 니즈 분석, 운영 프로세스 설명처럼 딱딱한 섹션은 피하세요.
- 실제 후기 문장을 정리해 전달하는 느낌으로 작성하세요.
- 과장된 홍보 문구(예: 최적의 장소, 각광받고 있습니다, 추천드립니다, 최고의 선택)는 사용하지 마세요.
- 문체는 '이용 후 느낀 점을 차분히 정리한 후기형'으로 유지하세요.
- 원문에 있는 표현(예: 깨끗했다, 조용했다, 쉬기 좋았다, 꿀잠 잤다, 만족도 100%)을 자연스럽게 살리세요.
- 없는 문제 상황, 상담 과정, 진행 절차, 개선 수치를 새로 만들지 마세요.
- H2도 리뷰형 흐름으로 맞추세요. 예: 첫인상, 공간 분위기, 청결/침구, 함께 간 인원 만족도, 총평
"""


def build_prompt_profile_text(prompt_profile: dict) -> str:
    if not isinstance(prompt_profile, dict) or len(prompt_profile) == 0:
        return "- 브랜드 프롬프트 정보 없음"

    pairs = [
        ("브랜드명", prompt_profile.get("brand_name", "")),
        ("상품/서비스명", prompt_profile.get("product_name", "")),
        ("브랜드 업종", prompt_profile.get("industry", "")),
        ("최우선 목표", prompt_profile.get("goal", "")),
        ("핵심 강점", prompt_profile.get("strengths", "")),
        ("추가 강점", prompt_profile.get("extra_strength", "")),
        ("권장 톤", prompt_profile.get("tones", "")),
        ("콘텐츠 스타일", prompt_profile.get("content_styles", "")),
        ("행동 유도 방식", prompt_profile.get("action_style", "")),
        ("피해야 할 표현", prompt_profile.get("forbidden_expressions", "")),
        ("추가 금지 표현", prompt_profile.get("forbidden_phrases", "")),
        ("문의 채널", prompt_profile.get("inquiry_channels", "")),
        ("문의 정보", prompt_profile.get("inquiry_phone", "")),
    ]

    lines = []
    for label, value in pairs:
        text = str(value).strip()
        if text != "":
            lines.append(f"- {label}: {text}")

    return "\n".join(lines) if lines else "- 브랜드 프롬프트 정보 없음"


def generate_response(prompt_text: str) -> str:
    messages = [
        {
            "role": "system",
            "content": (
                "당신은 다양한 업종(의료, 법률, 인테리어, 뷰티, 컨설팅, 교육 등)의 비즈니스 사례를 "
                "SEO에 최적화된 블로그 콘텐츠로 전환하는 전문 카피라이터입니다. "
                "실제 사례 데이터를 바탕으로 잠재 고객의 신뢰를 얻고 문의를 유도하는 구조를 만듭니다."
            )
        },
        {"role": "user", "content": prompt_text}
    ]
    try:
        response = client.chat.completions.create(
            model=model_name,
            messages=messages,
            max_tokens=2000,
            temperature=0.7,
            top_p=0.9,
            frequency_penalty=0.1,
            presence_penalty=0.3
        )
        return response.choices[0].message.content.strip()
    except Exception:
        return json.dumps({"error": "AI 응답 생성 중 오류 발생"})


def generate_analysis_prompt(data: dict) -> str:
    case_title = data.get("case_title", "")
    raw_content = data.get("raw_content", "")
    prompt_profile = data.get("prompt_profile", {}) or {}
    inferred_industry = infer_industry(f"{case_title}\n{raw_content}")
    industry_guidance = build_industry_guidance(inferred_industry)
    case_style = infer_case_style(case_title, raw_content, {})
    prompt_profile_text = build_prompt_profile_text(prompt_profile)

    context_str = "\n".join([
        f"- 사례명: {case_title}" if case_title else "- 사례명: 없음",
        f"- 자유 입력 원문: {raw_content}" if raw_content else "- 자유 입력 원문: 없음"
    ])

    return f"""
다음은 업종을 특정하지 않은 자유 입력 고객 사례 원문입니다.
문장이 정리되어 있지 않거나, 정보가 섞여 있거나, 순서가 뒤죽박죽이어도 됩니다.
당신의 역할은 이 원문을 읽고 사례를 구조화한 뒤, 블로그용 초안을 만드는 것입니다.

[입력 데이터]
{context_str}

[사전 추정 업종]
- {inferred_industry}

[업종별 작성 가이드]
{industry_guidance}

[사례 문체 가이드]
{build_review_analysis_guidance(inferred_industry) if case_style == "review" else "- 일반 사례형 구조로 분석하세요."}

[브랜드/마케팅 프롬프트 정보]
{prompt_profile_text}

위 데이터를 분석하여 아래 JSON 형식으로만 출력해 주세요.

요구사항:
1. structured:
   - industry_category: AI가 최종 판단한 업종 분류
   - case_category: AI가 판단한 사례 유형
   - subject_label: 고객/환자/의뢰인/대상에 대한 한 줄 요약
   - problem_summary: 사례 시작 전 문제 상황 요약
   - process_summary: 진행 과정, 조치, 처방, 작업 방식 요약
   - result_summary: 결과, 변화, 회복, 성과 요약

2. title:
   - 잠재 고객이 검색할 만한 키워드가 포함된 SEO 친화적 제목
   - 30~60자 권장
   - 사례 유형과 핵심 결과가 드러나게 작성

3. summary:
   - 블로그 도입부 요약
   - 100~200자 권장
   - 독자가 공감할 수 있도록 문제와 결과를 자연스럽게 연결
   - 브랜드 프롬프트 정보가 있으면 그 톤과 목표를 반영할 것

4. h2_sections:
   - 블로그 전체 흐름을 구성하는 H2 소제목 5~6개
   - 15~40자 권장
   - 원문에 없는 세부 사실은 지어내지 말 것
   - 일반 사례형 권장 흐름:
     * 사례 배경/문제
     * 진단 또는 분석 포인트
     * 진행 과정
     * 변화/결과
     * 관리 포인트 또는 마무리
   - 리뷰형 권장 흐름:
     * 첫인상 또는 전반적 분위기
     * 청결/공간/구성
     * 위치나 환경 체감
     * 함께 간 인원 또는 사용 편의성
     * 전체 만족도와 총평

JSON 출력 형식:
{{
  "structured": {{
    "industry_category": "AI가 판단한 업종 분류",
    "case_category": "AI가 판단한 사례 유형",
    "subject_label": "대상 요약",
    "problem_summary": "문제 상황 요약",
    "process_summary": "진행 과정 요약",
    "result_summary": "결과 요약"
  }},
  "title": "블로그 제목",
  "summary": "블로그 도입부 요약 문장.",
  "h2_sections": [
    "소제목 1",
    "소제목 2",
    "소제목 3",
    "소제목 4",
    "소제목 5",
    "소제목 6"
  ]
}}

제약사항:
- 반드시 순수 JSON만 출력 (마크다운 코드블록·추가 설명 없음)
- 모든 내용은 한국어로 작성
- 과장 표현 금지, 실제 사례 데이터에 근거한 표현 사용
- 업종은 먼저 원문에서 추론하되, 사전 추정 업종이 문맥과 맞으면 적극 반영할 것
- 브랜드 프롬프트 정보가 있으면, 제목/요약/H2에 그 브랜드의 목표, 강점, 톤을 반영할 것
- 금지 표현이 있으면 사용하지 말 것
- 자유 입력 원문이 모호하더라도, 합리적으로 분류하되 없는 사실은 만들지 말 것
- 추정이 필요한 경우에도 자연스럽고 보수적으로 정리할 것
- 리뷰형 원문이면 문제 해결 사례처럼 억지로 재구성하지 말고, 후기의 핵심 체감 포인트 중심으로 정리할 것
"""


def generate_draft_prompt(data: dict) -> str:
    case_title = data.get("case_title", "")
    raw_content = data.get("raw_content", "")
    ai_title = data.get("ai_title", "")
    ai_summary = data.get("ai_summary", "")
    h2_sections = data.get("ai_h2_sections", []) or []
    structured = data.get("structured", {}) or {}
    prompt_profile = data.get("prompt_profile", {}) or {}
    case_style = infer_case_style(case_title, raw_content, structured)
    image_contexts = data.get("image_contexts", []) or []

    inferred_industry = (
        structured.get("industry_category")
        or infer_industry(f"{case_title}\n{raw_content}\n{ai_title}\n{ai_summary}")
    )
    body_guidance = build_body_draft_guidance(inferred_industry)
    prompt_profile_text = build_prompt_profile_text(prompt_profile)

    structured_lines = [
        f"- 업종: {structured.get('industry_category', '')}",
        f"- 사례 유형: {structured.get('case_category', '')}",
        f"- 대상 요약: {structured.get('subject_label', '')}",
        f"- 문제 상황: {structured.get('problem_summary', '')}",
        f"- 진행 과정: {structured.get('process_summary', '')}",
        f"- 결과 요약: {structured.get('result_summary', '')}",
    ]
    structured_lines = [line for line in structured_lines if not line.endswith(": ")]

    h2_text = "\n".join([f"- {item}" for item in h2_sections if str(item).strip() != ""])
    if h2_text == "":
        h2_text = "- H2 없음"

    section_keys = ["intro"]
    for idx, item in enumerate(h2_sections, start=1):
        if str(item).strip() != "":
            section_keys.append(f"h2-{idx}")

    image_context_lines = []
    for item in image_contexts:
        if not isinstance(item, dict):
            continue
        file_id = int(item.get("file_id", 0))
        if file_id <= 0:
            continue
        keywords = item.get("keywords", [])
        if isinstance(keywords, list):
            keyword_text = ", ".join([str(v).strip() for v in keywords if str(v).strip() != ""])
        else:
            keyword_text = ""
        image_context_lines.append(
            "\n".join([
                f"- file_id: {file_id}",
                f"  original_name: {str(item.get('original_name', '')).strip()}",
                f"  description: {str(item.get('description', '')).strip()}",
                f"  subject_primary: {str(item.get('subject_primary', '')).strip()}",
                f"  scene_type: {str(item.get('scene_type', '')).strip()}",
                f"  visual_role: {str(item.get('visual_role', '')).strip()}",
                f"  mood: {str(item.get('mood', '')).strip()}",
                f"  subtitle_candidate: {str(item.get('subtitle_candidate', '')).strip()}",
                f"  keywords: {keyword_text}",
            ])
        )

    image_context_text = "\n\n".join(image_context_lines) if image_context_lines else "- 사용 가능한 이미지 메타 없음"
    section_key_text = ", ".join(section_keys)

    return f"""
다음은 고객 사례 원문과 AI가 정리한 블로그 구조입니다.
이 정보를 바탕으로 실제 블로그 본문 초안을 작성해 주세요.

[원문 정보]
- 사례명: {case_title}
- 자유 입력 원문: {raw_content}

[AI 구조화 결과]
{chr(10).join(structured_lines) if structured_lines else "- 구조화 결과 없음"}

[블로그 구조]
- 블로그 제목: {ai_title}
- 요약: {ai_summary}
- H2 목록:
{h2_text}

[업종별 본문 작성 가이드]
- 추정 업종: {inferred_industry}
{body_guidance}

[사례 문체 가이드]
{build_review_draft_guidance(inferred_industry) if case_style == "review" else "- 일반 사례형 블로그 본문으로 작성하세요."}

[브랜드/마케팅 프롬프트 정보]
{prompt_profile_text}

[사용 가능한 이미지 메타 정보]
{image_context_text}

본문 작성 요구사항:
1. 출력은 JSON만 하세요.
2. body_draft 필드에 본문 초안을 문자열로 넣으세요.
3. 본문은 한국어로 작성하세요.
4. 제목은 본문에 다시 쓰지 말고, 도입부 1~2문단 후 H2별로 문단을 구성하세요.
5. 각 H2는 반드시 `## 소제목` 형식으로 시작하세요.
6. 원문에 없는 수치, 기간, 결과는 새로 지어내지 말되 일반적으로 통용되는 수치나 기간, 결과는 허용
7. 문체는 설명형이 기본이고, 지나치게 광고성으로 쓰지 마세요.
8. 마지막에는 자연스러운 마무리 문단 1개를 추가하세요.
9. HTML이 아니라 일반 텍스트/마크다운 형태로 작성하세요.
10. 브랜드 프롬프트 정보가 있으면 강점, 톤, 목표를 본문에 자연스럽게 반영하세요.
11. 금지 표현과 피해야 할 표현은 사용하지 마세요.
12. 행동 유도 방식이 주어졌다면 마지막 문단의 톤에 반영하세요.
13. 사용 가능한 이미지 메타가 있다면 각 섹션에 어울리는 이미지 후보를 골라주세요.
14. 섹션별 추천 이미지는 최대 2장까지입니다.
15. 같은 이미지를 여러 섹션에 중복 추천하지 마세요.
16. 이미지 메타가 부족하면 비워도 됩니다.
17. 리뷰형 사례라면 '업체 소개/고객 니즈/제공 과정/추천' 같은 홍보형 문단 대신 실제 체감 포인트를 정리하는 후기형 본문으로 작성하세요.
18. 리뷰형 사례라면 원문에 없는 배경 설명, 관리 프로세스, 서비스 운영 방식은 만들지 마세요.
19. 각 단락은 최대 2문장으로 짧게 끊어 작성하세요.

이미지 추천 규칙:
- 사용 가능한 section_key는 정확히 다음 값만 사용하세요: {section_key_text}
- intro는 도입부를 뜻합니다.
- h2-1, h2-2 ... 는 H2 순서와 정확히 대응합니다.
- recommended_image_ids에는 반드시 위 이미지 메타에 있는 file_id만 넣으세요.
- 없는 사실을 상상해서 이미지를 추천하지 마세요.

JSON 출력 형식:
{{
  "body_draft": "도입부...\\n\\n## 소제목 1\\n본문...\\n\\n## 소제목 2\\n본문...",
  "image_placements": [
    {{
      "section_key": "intro",
      "recommended_image_ids": [1],
      "reason": "도입부에서 사례의 배경과 분위기를 보여주기 좋음"
    }},
    {{
      "section_key": "h2-1",
      "recommended_image_ids": [3, 5],
      "reason": "이 섹션의 내용과 직접 연결되는 이미지"
    }}
  ]
}}
"""


if __name__ == "__main__":
    if len(sys.argv) > 1:
        try:
            raw  = base64.b64decode(sys.argv[1]).decode("utf-8")
            data = json.loads(raw)
            mode = (data.get("mode") or "analyze").strip().lower()
            if mode == "draft":
                prompt_text = generate_draft_prompt(data)
            else:
                prompt_text = generate_analysis_prompt(data)
            response    = generate_response(prompt_text)

            parsed = json.loads(response)
            print(json.dumps({"response": parsed}, ensure_ascii=False))

        except json.JSONDecodeError:
            print(json.dumps({"error": "AI 응답 JSON 파싱 실패", "raw": response}, ensure_ascii=False))
        except Exception:
            print(json.dumps({"error": "사례 데이터 처리 중 오류 발생"}, ensure_ascii=False))
    else:
        print(json.dumps({"error": "No case data provided."}, ensure_ascii=False))
