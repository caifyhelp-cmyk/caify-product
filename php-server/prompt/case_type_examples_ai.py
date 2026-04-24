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


TYPE_CONFIG = {
    "problem_solve": {
        "label": "문제 해결 사례",
        "q1_label": "[문제] 어떤 문제가 있었나요?",
        "q2_label": "[해결] 해당 문제를 어떻게 해결했나요?",
        "q3_label": "[결과] 문제 해결을 통해 어떤 변화가 생겼나요?",
    },
    "process_work": {
        "label": "작업/진행 과정 사례",
        "q1_label": "[대상] 어떤 작업(프로젝트)이었나요?",
        "q2_label": "[핵심 단계] 어떤 과정/순서로 진행하셨나요?",
        "q3_label": "[완료] 최종 결과는 어떻게 되었나요?",
    },
    "consulting_qa": {
        "label": "상담/문의 사례",
        "q1_label": "[질문] 고객이 문의한 내용은 무엇인가요?",
        "q2_label": "[답변] 문의한 내용에 대해 어떻게 답했나요?",
        "q3_label": "[결과] 상담 후 고객의 반응은 어땠나요?",
    },
    "review_experience": {
        "label": "고객 경험/후기",
        "q1_label": "[만족 포인트] 고객이 만족한 제품/서비스는 무엇인가요?",
        "q2_label": "[고객 반응] 고객의 반응 또는 후기를 상세히 적어주세요.",
        "q3_label": "[강조] 후기를 통해 고객에게 어필하고싶은 점은 무엇인가요?",
    },
}


def generate_examples_prompt(data: dict) -> str:
    brand_name = data.get("brand_name", "")
    product_name = data.get("product_name", "")
    industry = data.get("industry", "")
    goal = data.get("goal", "")
    strengths = data.get("strengths", "")
    case_type = data.get("case_type", "")

    tcfg = TYPE_CONFIG.get(case_type, TYPE_CONFIG["problem_solve"])

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
당신은 전환이 잘 나오는 사례형 블로그를 기획하는 시니어 콘텐츠 전략가이자 카피라이터입니다.
아래 사업자가 "{tcfg['label']}" 블로그 글을 쓸 때 참고할 작성 예시 5개를 만들어주세요.
이 예시는 placeholder(입력 안내문)로 사용되지만, 단순 입력 예시가 아니라 "고객이 실제로 흥미를 느끼고 끝까지 읽고 싶어질 만한 사례글의 재료"처럼 보여야 합니다.
사용자가 "이 정도로 써야 읽히는 사례가 되는구나" 하고 바로 감을 잡을 수 있을 만큼 생생하고 구체적이어야 합니다.

[사업자 정보]
{context_text}

[사례 유형]
{tcfg['label']}

[입력 필드]
- title: 사례 제목
- q1: {tcfg['q1_label']}
- q2: {tcfg['q2_label']}
- q3: {tcfg['q3_label']}

핵심 목표:
- 예제 5개 모두 "일반적이고 평이한 설명"이 아니라, 실제 고객이 읽을 때 "왜 그랬지?", "그래서 어떻게 됐지?"가 생기는 사례여야 합니다.
- 각 예시는 제목, 문제 상황, 대응 방식, 결과가 하나의 작은 스토리처럼 이어져야 합니다.
- 정보 전달만 하지 말고, 고객이 결정을 망설이던 이유, 의외의 포인트, 해결의 전환점, 결과의 체감 변화가 드러나야 합니다.
- 블로그에 바로 써도 될 만큼 현실적이고 설득력 있어야 하며, 광고 문구처럼 과장하면 안 됩니다.

흥미 유발 규칙:
- 제목에는 낯익은 표현 대신 "구체 상황 + 긴장 포인트 + 해결 실마리"가 드러나야 합니다.
- q1에는 고객이 처음 왜 불안했는지, 무엇이 걸렸는지, 어떤 맥락이 있었는지가 보여야 합니다.
- q2에는 실제로 어떤 판단과 설명, 확인, 조치가 있었는지 현장감 있게 보여야 합니다.
- q3에는 단순 만족이 아니라 고객의 인식 변화, 행동 변화, 추가 결정, 재방문/추천 의사 등 다음 행동이 보이도록 작성하세요.
- 독자가 읽으며 장면이 그려질 정도로 디테일하되, 소설처럼 과장하지는 마세요.

금지 규칙:
- "문의가 들어왔습니다", "친절히 안내했습니다", "만족하셨습니다", "도움이 되었습니다" 같은 평이한 표현 반복 금지
- 다섯 예시가 비슷한 구조, 비슷한 갈등, 비슷한 결말로 반복되는 것 금지
- 누구에게나 적용되는 뻔한 조언, 교과서식 설명, 포괄적인 추상어 남발 금지
- 제목이 "OO 문의", "OO 상담", "OO 후기", "OO 케이스"처럼 너무 일반적인 형태로 끝나는 것 금지

★ 구체성 규칙 (반드시 지킬 것):
1. title: 핵심 키워드 + 구체적 상황 + 읽고 싶어지는 포인트가 담긴 제목 (15~34자). "○○ 문의", "○○ 상담" 같은 뻔한 제목 금지. 예) "3년째 반복된 귀 염증, 원인이 따로 있었습니다"
2. q1: 고객의 구체적 상황을 묘사하세요. 반드시 숫자(기간, 횟수, 금액, 연령, 사용 기간 등), 구체적 증상/요청사항, 고객이 처한 맥락, 망설임 또는 걱정 포인트를 포함. 3~5문장.
   나쁜 예) "고객이 예방접종에 대해 문의했습니다."
   좋은 예) "생후 8주 된 포메라니안을 데리고 오신 보호자분이 첫 예방접종 시기를 놓친 것 같다며 걱정하셨습니다. 이전 병원에서 1차 접종만 맞고 2차를 빠뜨린 상태였습니다."
3. q2: 실제 현장에서 대응한 것처럼 구체적 행동/과정/방법을 서술. 사용한 도구, 기법, 소요 시간, 단계뿐 아니라 "왜 그렇게 판단했는지"가 드러나야 합니다. 독자가 전문가의 사고 과정을 엿본다고 느낄 정도로 쓰세요. 3~5문장.
   나쁜 예) "저희가 잘 설명해드렸습니다."
   좋은 예) "먼저 항체가 검사를 실시해 현재 면역 상태를 확인했습니다. 검사 결과 항체가가 낮아 DHPPL 2차부터 다시 시작하기로 했고, 3주 간격 접종 스케줄을 잡아드렸습니다."
4. q3: 결과/변화/반응을 구체적 수치나 행동으로 표현. 단순 만족이 아니라 '고객이 무엇을 이해했고, 어떤 행동을 했고, 무엇이 달라졌는지'가 보여야 합니다. 2~4문장.
   나쁜 예) "고객이 만족했습니다."
   좋은 예) "보호자분이 접종 스케줄표를 사진 찍어 가시며 안심하셨고, 3주 뒤 2차 접종에 정확히 내원하셨습니다. 이후 건강검진까지 추가로 예약하셨습니다."
5. 5개 예시는 반드시 서로 다른 고객, 다른 상황, 다른 서비스/제품, 다른 갈등 포인트를 다뤄야 합니다.
6. 이 업종에서 실제로 흔히 발생하는 현실적인 시나리오만 사용하세요.
7. 최소 2개 이상의 예시는 "처음엔 고객이 잘못 알고 있었거나, 다른 선택지를 고민했지만 설명 후 관점이 바뀌는 흐름"이 포함되어야 합니다.
8. 최소 2개 이상의 예시는 "읽는 순간 장면이 잡히는 디테일"이 있어야 합니다. 예: 내원 시점, 사용 환경, 반복된 실패, 비교 고민, 가족/직원 반응 등.

JSON 출력 (배열만, 마크다운 코드블록 없이):
[
  {{"title": "", "q1": "", "q2": "", "q3": ""}},
  {{"title": "", "q1": "", "q2": "", "q3": ""}},
  {{"title": "", "q1": "", "q2": "", "q3": ""}},
  {{"title": "", "q1": "", "q2": "", "q3": ""}},
  {{"title": "", "q1": "", "q2": "", "q3": ""}}
]

제약:
- 순수 JSON 배열만 출력 (```json 등 마크다운 래퍼 금지)
- 한국어
- 과장이나 비현실적인 내용 금지
- 사업자 정보에 없는 세부 사항은 업종 특성에 맞게 자연스럽게 추정 가능
- 예제 문장은 밋밋한 안내문 말투보다, 실제 사례 초안처럼 읽히는 톤으로 작성
"""


if __name__ == "__main__":
    if len(sys.argv) <= 1:
        print(json.dumps({"error": "No data provided."}, ensure_ascii=False))
        sys.exit(0)

    response = ""
    try:
        raw = base64.b64decode(sys.argv[1]).decode("utf-8")
        data = json.loads(raw)
        prompt_text = generate_examples_prompt(data)
        response = generate_response(prompt_text)
        cleaned = strip_markdown_codeblock(response)
        parsed = json.loads(cleaned)
        print(json.dumps({"response": parsed}, ensure_ascii=False))
    except json.JSONDecodeError:
        print(json.dumps({"error": "JSON 파싱 실패", "raw": response}, ensure_ascii=False))
    except Exception as e:
        print(json.dumps({"error": str(e)}, ensure_ascii=False))
