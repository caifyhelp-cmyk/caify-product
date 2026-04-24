# -*- coding: utf-8 -*-
import sys
import json
import base64
import re

from case_short_ai import generate_response


def strip_markdown_codeblock(text: str) -> str:
    text = str(text or "").strip()
    m = re.match(r"^```(?:json)?\s*\n?(.*?)\n?\s*```$", text, re.DOTALL)
    return m.group(1).strip() if m else text


def build_analyze_prompt(data: dict) -> str:
    file_content = str(data.get("file_content", "")).strip()

    return f"""
당신은 문서 분석 전문가입니다.
사용자가 첨부한 문서를 읽고, 이 문서의 내용을 블로그 사례글로 만들기 위한 정보를 정리해주세요.

⚠️ 핵심 규칙:
- 오직 아래 문서에 적혀 있는 내용만 사용하세요.
- 문서에 없는 사실, 수치, 이름, 결과를 지어내지 마세요.
- 사례 유형(문제 해결, 작업 과정, 상담 등)을 미리 정하지 마세요. 문서 내용이 무엇이든 그대로 분석하세요.
- 문서가 평가서든, 보고서든, 메모든, 후기든 상관없이 있는 그대로의 내용을 정리하세요.

[첨부 문서 내용]
{file_content[:8000]}

요구사항:

1. summary: 이 문서가 어떤 내용인지 3~5문장으로 요약하세요. 문서에 있는 사실만 적으세요.

2. suggested_title: 이 문서 내용으로 블로그 사례글을 쓴다면 어울리는 제목을 제안하세요. (15~30자)

3. questions: 이 문서의 내용을 블로그 사례글로 만들기 위해 가장 적합한 질문 3개를 만들어주세요.
   - 질문은 문서에 실제로 답이 있는 내용을 기반으로 만드세요.
   - "문제가 뭐였나요?" 같은 일반적인 틀이 아니라, 이 문서의 내용에 딱 맞는 구체적인 질문이어야 합니다.
   - 예를 들어 문서가 인사 평가서면 "어떤 성과를 냈나요?", "어떤 역량이 돋보였나요?", "향후 어떤 목표를 세웠나요?" 같은 식입니다.

4. answers: 위 3개 질문에 대한 답변을 문서 내용에서 추출해 각각 3~5문장으로 작성하세요.
   - 반드시 문서에 있는 내용만 사용하세요.

5. examples: 이 문서와 같은 분야/주제에서 나올 수 있는 유사 사례 예시 3개를 생성하세요.
   - 이것만 창작이 허용됩니다.
   - 각 예시는 title, q1, q2, q3 필드를 포함합니다.
   - q1, q2, q3는 위에서 만든 questions의 1번, 2번, 3번 질문에 대한 답변입니다.
   - 첨부 문서의 내용을 그대로 반복하지 말고, 같은 분야에서 다른 상황/대상/결과를 다루세요.
   - 각 q1, q2, q3는 3~5문장으로 구체적이고 생생하게 작성하세요.
   - 독자가 "이 정도로 써야 좋은 사례가 되는구나" 하고 감을 잡을 수 있을 만큼 디테일하게 쓰세요.
   - 단순 요약이 아니라 실제 블로그 사례 원문처럼 현장감 있게 작성하세요.

반드시 순수 JSON만 출력하세요:
{{
  "summary": "문서 요약",
  "suggested_title": "사례명 제안",
  "questions": ["질문1", "질문2", "질문3"],
  "answers": ["답변1", "답변2", "답변3"],
  "examples": [
    {{"title": "예시 제목1", "q1": "질문1에 대한 구체적 답변 (3~5문장)", "q2": "질문2에 대한 구체적 답변 (3~5문장)", "q3": "질문3에 대한 구체적 답변 (3~5문장)"}},
    {{"title": "예시 제목2", "q1": "...", "q2": "...", "q3": "..."}},
    {{"title": "예시 제목3", "q1": "...", "q2": "...", "q3": "..."}}
  ]
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
        prompt_text = build_analyze_prompt(data)
        response = generate_response(prompt_text)
        cleaned = strip_markdown_codeblock(response)
        parsed = json.loads(cleaned)
        print(json.dumps({"response": parsed}, ensure_ascii=False))
    except json.JSONDecodeError:
        print(json.dumps({"error": "JSON 파싱 실패", "raw": response}, ensure_ascii=False))
    except Exception as e:
        print(json.dumps({"error": str(e)}, ensure_ascii=False))
