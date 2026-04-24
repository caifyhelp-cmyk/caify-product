# -*- coding: utf-8 -*-
"""
PDF 이미지 → GPT-4o Vision OCR
PDF 페이지를 이미지로 변환한 뒤 GPT Vision으로 텍스트를 추출합니다.

Usage: python3 pdf_vision_ocr.py <pdf_path> [max_pages]
Output: 추출된 텍스트를 stdout으로 출력
"""
import os
import sys
import base64
import tempfile
from openai import OpenAI
from dotenv import load_dotenv

_script_dir = os.path.dirname(os.path.abspath(__file__))
_api_dir = os.path.join(os.path.dirname(_script_dir), "api")
load_dotenv(dotenv_path=os.path.join(_api_dir, ".env"))

MODEL_NAME = "gpt-4o-mini"

OCR_PROMPT = """이 이미지는 문서를 스캔한 것입니다.
이미지에 보이는 모든 텍스트를 빠짐없이 그대로 읽어서 출력해주세요.

규칙:
- 표, 목록, 제목, 본문 등 구조를 최대한 유지하세요.
- 손글씨가 있으면 최선을 다해 판독하세요.
- 읽을 수 없는 부분은 [판독불가]로 표시하세요.
- 설명이나 해석을 추가하지 말고, 문서에 적힌 텍스트만 출력하세요.
- 한국어와 영어가 섞여 있을 수 있습니다."""


def pdf_to_images(pdf_path: str, max_pages: int = 5) -> list:
    from pdf2image import convert_from_path
    images = convert_from_path(pdf_path, dpi=200, first_page=1, last_page=max_pages)
    paths = []
    for i, img in enumerate(images):
        tmp = tempfile.NamedTemporaryFile(suffix=".png", delete=False)
        img.save(tmp.name, "PNG")
        paths.append(tmp.name)
    return paths


def ocr_image(client: "OpenAI", image_path: str) -> str:
    with open(image_path, "rb") as f:
        b64 = base64.b64encode(f.read()).decode("ascii")
    data_url = f"data:image/png;base64,{b64}"

    resp = client.chat.completions.create(
        model=MODEL_NAME,
        messages=[
            {
                "role": "user",
                "content": [
                    {"type": "text", "text": OCR_PROMPT},
                    {"type": "image_url", "image_url": {"url": data_url, "detail": "high"}},
                ],
            },
        ],
        temperature=0.1,
        max_tokens=4000,
    )
    return (resp.choices[0].message.content or "").strip()


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("", end="")
        sys.exit(0)

    pdf_path = sys.argv[1]
    max_pages = int(sys.argv[2]) if len(sys.argv) > 2 else 3

    if not os.path.isfile(pdf_path):
        print("", end="")
        sys.exit(0)

    api_key = os.getenv("OPENAI_API_KEY", "")
    if not api_key:
        print("", end="")
        sys.exit(0)

    image_paths = []
    try:
        image_paths = pdf_to_images(pdf_path, max_pages)
        if not image_paths:
            print("", end="")
            sys.exit(0)

        client = OpenAI(api_key=api_key)
        pages_text = []
        for img_path in image_paths:
            text = ocr_image(client, img_path)
            if text:
                pages_text.append(text)

        print("\n\n".join(pages_text))
    except Exception as e:
        print("", end="")
        sys.exit(0)
    finally:
        for p in image_paths:
            try:
                os.unlink(p)
            except OSError:
                pass
