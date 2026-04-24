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


def generate_prompt(data: dict) -> str:
    case_type        = data.get("case_type", "")
    customer_name    = data.get("customer_name", "")
    customer_info    = data.get("customer_info", "")
    service_name     = data.get("service_name", "")
    service_period   = data.get("service_period", "")
    before_situation = data.get("before_situation", "")
    case_process     = data.get("case_process", "")
    after_result     = data.get("after_result", "")
    case_content     = data.get("case_content", "")

    lines = []
    if case_type:
        lines.append(f"- 사례 유형: {case_type}")
    if customer_name:
        lines.append(f"- 고객/환자/의뢰인: {customer_name}")
    if customer_info:
        lines.append(f"- 고객 특성: {customer_info}")
    if service_name:
        lines.append(f"- 서비스/치료/시술명: {service_name}")
    if service_period:
        lines.append(f"- 진행 시기/기간: {service_period}")
    if before_situation:
        lines.append(f"- 내원/의뢰 전 상황: {before_situation}")
    if case_process:
        lines.append(f"- 진행 과정/처방/방법: {case_process}")
    if after_result:
        lines.append(f"- 결과/회복/변화: {after_result}")
    if case_content:
        lines.append(f"- 추가 메모/특이사항: {case_content}")

    context_str = "\n".join(lines) if lines else "사례 내용 없음"

    return f"""
다음은 비즈니스 고객 사례 정보입니다. 이를 바탕으로 블로그 포스팅 구조를 생성해 주세요.

[사례 데이터]
{context_str}

위 데이터를 분석하여 아래 JSON 형식으로만 출력해 주세요.

요구사항:
1. title: 잠재 고객이 검색할 만한 키워드가 포함된 SEO 친화적 제목 (30~60자)
   - 사례 유형과 핵심 결과를 담을 것
   - 예: "[슬개골탈구 수술 후기] 뭉치의 3개월 회복 기록 + 강남 동물병원 실제 케이스"
   - 예: "개인사업자 법인전환 후 소득세 1,200만 원 줄인 실제 컨설팅 사례"

2. summary: 블로그 도입부 요약 (100~200자)
   - 독자(잠재 고객)의 공감을 이끌어내고 계속 읽고 싶게 만드는 문장
   - 사례의 핵심 전후 변화를 담을 것

3. h2_sections: H2 소제목 5~6개 (각 15~40자)
   - 블로그 전체 흐름을 구성하는 섹션 제목
   - 권장 구성:
     * 1번: 내원/의뢰 전 상황 소개 섹션
     * 2~3번: 진단/분석/접근 방법 섹션
     * 4번: 치료/서비스 진행 과정 섹션
     * 5번: 결과 및 변화 섹션
     * 6번(선택): 전문가 코멘트 또는 유사 사례 안내 섹션

JSON 출력 형식:
{{
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
- 잠재 고객이 "나도 저 서비스를 받아보고 싶다"는 신뢰와 행동 의지를 갖도록 작성
"""


if __name__ == "__main__":
    if len(sys.argv) > 1:
        try:
            raw  = base64.b64decode(sys.argv[1]).decode("utf-8")
            data = json.loads(raw)

            prompt_text = generate_prompt(data)
            response    = generate_response(prompt_text)

            parsed = json.loads(response)
            print(json.dumps({"response": parsed}, ensure_ascii=False))

        except json.JSONDecodeError:
            print(json.dumps({"error": "AI 응답 JSON 파싱 실패", "raw": response}, ensure_ascii=False))
        except Exception:
            print(json.dumps({"error": "사례 데이터 처리 중 오류 발생"}, ensure_ascii=False))
    else:
        print(json.dumps({"error": "No case data provided."}, ensure_ascii=False))
