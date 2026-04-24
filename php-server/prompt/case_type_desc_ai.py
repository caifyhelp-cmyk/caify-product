# -*- coding: utf-8 -*-
import sys
import json
import base64
import re

from case_short_ai import generate_response


def strip_markdown_codeblock(text: str) -> str:
    text = text.strip()
    m = re.match(r"^```(?:json)?\s*\n?(.*?)\n?\s*```$", text, re.DOTALL)
    return m.group(1).strip() if m else text


def generate_type_descriptions(data: dict) -> str:
    brand_name = data.get("brand_name", "")
    product_name = data.get("product_name", "")
    industry = data.get("industry", "")
    goal = data.get("goal", "")
    strengths = data.get("strengths", "")

    business_context = []
    if brand_name:
        business_context.append(f"브랜드명: {brand_name}")
    if product_name:
        business_context.append(f"제품/서비스: {product_name}")
    if industry:
        business_context.append(f"업종: {industry}")
    if goal:
        business_context.append(f"마케팅 목표: {goal}")
    if strengths:
        business_context.append(f"강점: {strengths}")

    context_text = "\n".join(business_context) if business_context else "정보 없음"

    return f"""
다음은 블로그 마케팅을 위해 고객 사례를 등록하려는 사업자의 정보입니다.

[사업자 정보]
{context_text}

이 사업자가 고객 사례를 블로그에 올리려 합니다.
아래 4가지 사례 유형 각각에 대해, 이 사업자의 업종과 서비스에 맞는 맞춤 설명과 구체적 예시를 작성하세요.

사례 유형:
1. problem_solve (문제 해결 사례): 고객의 문제를 진단하고 해결한 사례
2. process_work (작업/진행 과정 사례): 작업이나 프로젝트의 진행 과정을 단계별로 보여주는 사례
3. consulting_qa (상담/문의 사례): 고객의 문의에 전문적으로 답변한 상담 사례
4. review_experience (고객 경험/후기): 서비스를 이용한 고객의 만족 경험을 정리한 후기

요구사항:
- desc: 이 사업자의 업종에 맞춰 "~할 때 사용하세요" 형태로 1~2문장. 반말 금지, ~하세요 체.
- example: 이 업종에서 실제로 쓸 법한 구체적 사례명 1개 (15~30자)

JSON 출력:
{{
  "problem_solve": {{ "desc": "", "example": "" }},
  "process_work": {{ "desc": "", "example": "" }},
  "consulting_qa": {{ "desc": "", "example": "" }},
  "review_experience": {{ "desc": "", "example": "" }}
}}

제약:
- 순수 JSON만 출력
- 한국어
- 과장 금지
- 사업자 정보에 없는 내용은 업종에 맞게 자연스럽게 추측 가능
"""


if __name__ == "__main__":
    if len(sys.argv) <= 1:
        print(json.dumps({"error": "No data provided."}, ensure_ascii=False))
        sys.exit(0)

    response = ""
    try:
        raw = base64.b64decode(sys.argv[1]).decode("utf-8")
        data = json.loads(raw)
        prompt_text = generate_type_descriptions(data)
        response = generate_response(prompt_text)
        cleaned = strip_markdown_codeblock(response)
        parsed = json.loads(cleaned)
        print(json.dumps({"response": parsed}, ensure_ascii=False))
    except json.JSONDecodeError:
        print(json.dumps({"error": "JSON 파싱 실패", "raw": response}, ensure_ascii=False))
    except Exception as e:
        print(json.dumps({"error": str(e)}, ensure_ascii=False))
