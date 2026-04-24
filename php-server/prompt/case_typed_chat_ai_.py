# -*- coding: utf-8 -*-
import sys
import json
import base64
import re

from case_short_ai import generate_response, generate_draft_prompt
from case_typed_ai import type_cfg, generate_analysis_prompt_typed


def strip_markdown_codeblock(text: str) -> str:
    text = str(text or "").strip()
    matched = re.match(r"^```(?:json)?\s*\n?(.*?)\n?```$", text, re.DOTALL)
    return matched.group(1).strip() if matched else text


def answer_text(value) -> str:
    return str(value or "").strip()


def generate_followup_prompt(data: dict, step: int) -> str:
    case_input_type = str(data.get("case_input_type", "")).strip()
    q1 = answer_text(data.get("question1_answer"))
    q2 = answer_text(data.get("question2_answer"))
    q3 = answer_text(data.get("question3_answer"))
    prompt_profile = data.get("prompt_profile", {}) or {}
    file_summary = str(data.get("file_summary", "")).strip()

    tcfg = type_cfg(case_input_type)
    brand = str(prompt_profile.get("brand_name", "")).strip()
    product = str(prompt_profile.get("product_name", "")).strip()
    industry = str(prompt_profile.get("industry", "")).strip()

    if file_summary:
        context_lines = [
            f"- 첨부 문서 요약:\n{file_summary}",
            f"- 질문1 답변 (문서 기반으로 작성됨):\n{q1 or '없음'}",
        ]
        if brand or industry:
            context_lines.append(f"- 참고 브랜드/업종 (톤 참고만): {brand or ''} / {industry or ''}")
    else:
        context_lines = [
            f"- 사례 유형: {tcfg['label']}",
            f"- 브랜드명: {brand or '없음'}",
            f"- 제품/서비스: {product or '없음'}",
            f"- 업종: {industry or '없음'}",
            f"- 질문1 답변:\n{q1 or '없음'}",
        ]
    if q2:
        context_lines.append(f"- 질문2 답변:\n{q2}")
    if q3:
        context_lines.append(f"- 질문3 답변:\n{q3}")

    if step == 2:
        return f"""
당신은 블로그 사례 작성을 위한 상담 매니저입니다.
아래 답변을 보고, 더 좋은 블로그 글을 만들기 위해 꼭 필요한 추가 질문을 1개만 생성하세요.

[상담 정보]
{chr(10).join(context_lines)}

질문 생성 원칙:
- 질문은 구체적이어야 합니다.
- 예/아니오형 질문 금지
- 실제 상황, 전후 변화, 고객 반응, 과정 디테일, 사용 환경, 결정 계기 등을 더 끌어낼 수 있어야 합니다.
- 이미 답변된 내용은 반복하지 마세요.
- 반드시 1개만 생성하세요.
- 질문은 한국어로 자연스럽게 작성하세요.
- 결과물은 상담 기록이 아니라 블로그 사례글을 위한 정보 보강용 질문이어야 합니다.
- 질문 하나로도 가장 중요한 빈 정보를 채울 수 있어야 합니다.

우선순위 규칙:
- 만약 답변이 동물병원 치료/진료 후기, 반려동물 치료 사례, 보호자 상담 사례처럼 보인다면 동물 정보를 먼저 확인하세요.
- 특히 동물의 이름, 나이, 견종/묘종, 성별 같은 기본 정보가 빠져 있으면 그 정보를 묻는 질문을 최우선으로 생성하세요.
- 나이만 있고 이름, 견종/묘종, 성별이 빠진 경우도 기본 정보가 부족한 상태로 판단하세요.
- 질문이 1개뿐이므로, 동물병원 사례에서 기본 정보가 비어 있으면 이름/나이/견종(또는 묘종)/성별을 한 번에 자연스럽게 묻는 하나의 질문으로 합쳐서 생성하세요.
- 예: "아이의 이름, 나이, 묘종(또는 견종), 성별을 함께 알려주실 수 있을까요? 사례글에서 대상 정보가 보이면 독자가 상황을 더 쉽게 이해할 수 있습니다."
- 동물 관련 기본 정보가 이미 충분하면 그 다음으로 증상 시작 시점, 치료 전 불편, 치료 후 변화 중 가장 비어 있는 정보를 질문하세요.

JSON 형식으로만 출력:
{{
  "need_more": true,
  "questions": ["질문1"]
}}
"""

    return f"""
당신은 블로그 사례 작성을 위한 상담 매니저입니다.
아래 상담 내용을 보고, 지금 정보만으로도 충분한지 먼저 판단하세요.
부족하면 마지막으로 물어볼 추가 질문을 최대 3개 생성하세요.

[상담 정보]
{chr(10).join(context_lines)}

판단 원칙:
- 사례 배경, 해결 방식, 결과/변화, 고객 반응 중 중요한 정보가 비어 있으면 추가 질문이 필요합니다.
- 이미 본문 초안을 만들 수 있을 만큼 충분하면 need_more를 false로 반환하세요.
- 질문이 필요할 때만 questions를 채우세요.
- 최대 3개까지만 생성하세요.
- 질문은 구체적이고 실무적인 정보 확보용이어야 합니다.

JSON 형식으로만 출력:
{{
  "need_more": true,
  "questions": ["질문1", "질문2"],
  "reason": "어떤 정보가 더 필요한지 짧게 설명"
}}
또는
{{
  "need_more": false,
  "questions": [],
  "reason": "현재 정보만으로 충분한 이유"
}}
"""


if __name__ == "__main__":
    if len(sys.argv) <= 1:
        print(json.dumps({"error": "No data provided."}, ensure_ascii=False))
        sys.exit(0)

    response = ""
    try:
        raw = base64.b64decode(sys.argv[1]).decode("utf-8")
        data = json.loads(raw)
        mode = str(data.get("mode") or "analyze").strip().lower()

        if mode == "followup2":
            prompt_text = generate_followup_prompt(data, 2)
        elif mode == "followup3":
            prompt_text = generate_followup_prompt(data, 3)
        elif mode == "draft":
            prompt_text = generate_draft_prompt(data)
        else:
            prompt_text = generate_analysis_prompt_typed(data)

        response = generate_response(prompt_text)
        parsed = json.loads(strip_markdown_codeblock(response))
        print(json.dumps({"response": parsed}, ensure_ascii=False))
    except json.JSONDecodeError:
        print(json.dumps({"error": "AI 응답 JSON 파싱 실패", "raw": response}, ensure_ascii=False))
    except Exception as exc:
        print(json.dumps({"error": str(exc)}, ensure_ascii=False))
