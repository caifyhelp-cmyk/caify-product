"""
Image -> structured JSON metadata generator (for video auto-generation).

Usage:
  python3 api/image.py <base64_encoded_image_path>

Output:
  Prints STRICT JSON ONLY to stdout.
"""

# -*- coding: utf-8 -*-
import os
import sys
import json
import base64
import mimetypes
from openai import OpenAI
from dotenv import load_dotenv

# api/.env 파일을 명시적으로 로드 (스크립트 위치 기준)
_script_dir = os.path.dirname(os.path.abspath(__file__))
load_dotenv(dotenv_path=os.path.join(_script_dir, '.env'))


MODEL_NAME = os.getenv("OPENAI_VISION_MODEL") or "gpt-4o-mini"


SYSTEM_PROMPT = "너는 업로드 이미지에서 블로그 본문 배치와 사례 요약에 필요한 핵심 정보만 빠르게 추출하는 시각 분석 전문가다."


USER_PROMPT = r"""
사용자가 업로드한 이미지를 보고,
블로그 사례 작성과 이미지 배치 추천에 필요한 핵심 메타데이터만 빠르게 생성하라.

⚠️ 매우 중요:
- 출력은 반드시 JSON만 허용된다.
- 설명 문장, 마크다운, 주석, 인사말을 절대 포함하지 말 것.
- 모든 추론은 이미지에 "보이는 범위 내에서 합리적으로" 수행할 것.
- 속도와 일관성이 중요하므로, 길고 과도한 설명보다 짧고 정확한 판단을 우선하라.

---

[분석 목표]
이 이미지를 기반으로 아래 정보만 추출하라:
1. 짧고 자연스러운 이미지 설명
2. 검색/분류용 핵심 키워드
3. 주요 대상 정보
4. 장면 성격과 이미지 역할
5. 분위기 정보
6. 짧은 자막 후보

---

[출력 JSON 형식 — 반드시 이 구조를 유지]

{
  "description": "이미지를 1~2문장으로 짧고 자연스럽게 설명",

  "keywords": ["키워드1", "키워드2", "키워드3", "키워드4"],

  "subject": {
    "primary": "주요 피사체",
    "secondary": "보조 피사체 또는 배경"
  },

  "scene": {
    "scene_type": "진료 장면 | 검사 장면 | 상담 장면 | 제품/결과 장면 | 공간/분위기 장면 | 기타",
    "visual_role": "공간 소개 | 분위기 강조 | 행동 설명 | 감정 전달"
  },

  "mood": {
    "mood": "차분함 | 신뢰감 | 깨끗함 | 따뜻함 | 활기참 | 긴장감"
  },

  "audio_text": {
    "subtitle_candidate": "자막으로 쓰기 좋은 짧은 문장"
  }
}

추가 규칙:
- description은 길게 쓰지 말고 핵심만 요약하라.
- subtitle_candidate는 12~24자 안팎의 짧은 문장으로 작성하라.
- keywords는 중복 없이 4개만 작성하라.
- 보이지 않는 정보는 추정하지 말라.
""".strip()


def _json_print(obj) -> None:
    sys.stdout.write(json.dumps(obj, ensure_ascii=False))


def _make_data_url_from_file(path: str) -> str:
    mime, _ = mimetypes.guess_type(path)
    if not mime:
        mime = "application/octet-stream"
    with open(path, "rb") as f:
        b = f.read()
    b64 = base64.b64encode(b).decode("ascii")
    return f"data:{mime};base64,{b64}"


def analyze_image(image_path: str) -> dict:
    api_key = os.getenv("OPENAI_API_KEY")
    if not api_key:
        return {"error": "OpenAI API 키가 설정되지 않았습니다. 환경변수 OPENAI_API_KEY를 설정해 주세요."}

    if not os.path.isfile(image_path):
        return {"error": "이미지 파일을 찾을 수 없습니다.", "image_path": image_path}

    data_url = _make_data_url_from_file(image_path)
    client = OpenAI(api_key=api_key)

    try:
        resp = client.chat.completions.create(
            model=MODEL_NAME,
            messages=[
                {"role": "system", "content": SYSTEM_PROMPT},
                {
                    "role": "user",
                    "content": [
                        {"type": "text", "text": USER_PROMPT},
                        {"type": "image_url", "image_url": {"url": data_url, "detail": "low"}},
                    ],
                },
            ],
            response_format={"type": "json_object"},
            temperature=0.1,
            max_tokens=380,
        )
        text = (resp.choices[0].message.content or "").strip()
        parsed = json.loads(text)
        return parsed
    except Exception as e:
        return {"error": "이미지 분석 중 오류 발생", "detail": str(e)}


if __name__ == "__main__":
    if len(sys.argv) < 2:
        _json_print({"error": "No image path provided."})
        sys.exit(0)

    try:
        image_path = base64.b64decode(sys.argv[1]).decode("utf-8").strip()
    except Exception:
        _json_print({"error": "Invalid base64 argument."})
        sys.exit(0)

    result = analyze_image(image_path)
    _json_print(result)
