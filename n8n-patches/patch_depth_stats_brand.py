"""
패치: 전문성·수치·출처 강화 + 고객 업체명 항상 볼드 처리
"""
import json, sys

with open('/root/caify-product/키워드풀_반영.json', 'r') as f:
    d = json.load(f)

nodes = d['nodes']

def find_node(name):
    for n in nodes:
        if n['name'] == name:
            return n
    return None

# ══════════════════════════════════════════════════════════════
# 1. Perplexity 요청 준비 — 수치/통계 강조 강화
# ══════════════════════════════════════════════════════════════
pplx = find_node("Perplexity 요청 준비")
if pplx:
    old_q = "6. 관련 수치·통계 — 출처 포함, 확인된 것만\n\n확인되지 않은 내용은 포함하지 마세요."
    new_q = """6. 관련 수치·통계 — 가능한 한 많이, 출처(기관명·보고서명·연도) 반드시 포함
   예) "2024년 한국인터넷진흥원 조사에 따르면 XX% …"
7. 업종 전문가·실무자가 실제로 쓰는 용어와 기준 — 가능한 구체적으로

확인되지 않은 내용은 포함하지 마세요.
수치는 추정·요약 말고 원문 그대로 인용하세요."""
    code = pplx['parameters']['jsCode']
    if old_q in code:
        pplx['parameters']['jsCode'] = code.replace(old_q, new_q)
        print('✅ 1. Perplexity 쿼리 수치 강화', file=sys.stderr)
    else:
        print('⚠️  Perplexity 쿼리 old text 없음', file=sys.stderr)

# ══════════════════════════════════════════════════════════════
# 2. 프롬프트생성1 SYSTEM_PROMPT — 전문성·수치·출처·접근성 규칙 추가
# ══════════════════════════════════════════════════════════════
p1 = find_node("프롬프트생성1")
if p1:
    code = p1['parameters']['jsCode']
    old_sys = "- 두 자료에 없는 수치·법령·판례·구체 사례는 절대 만들어내지 말 것"
    new_sys = """- 두 자료에 없는 수치·법령·판례·구체 사례는 절대 만들어내지 말 것
- [키워드 심층 조사]에 수치·통계·연구결과가 있으면 본문에 반드시 포함하고 "(출처: 기관명)" 형태로 병기한다
- 수치가 많이 보일수록 독자 신뢰도가 올라간다 — 조사에서 확인된 숫자는 최대한 활용한다
- 전문가가 읽어도 납득할 깊이를 갖추되, 어려운 개념은 반드시 쉬운 말로 한 단계 더 풀어준다
- "이 글 쓴 사람은 이 분야를 진짜 안다"는 느낌과 "나도 이해했다"는 느낌이 동시에 남아야 한다"""
    if old_sys in code:
        p1['parameters']['jsCode'] = code.replace(old_sys, new_sys)
        print('✅ 2. 프롬프트생성1 전문성·수치 규칙 추가', file=sys.stderr)
    else:
        print('⚠️  프롬프트생성1 old_sys 없음', file=sys.stderr)

# ══════════════════════════════════════════════════════════════
# 3. 글생성 prompt — 수치·출처·전문성·접근성 규칙 추가
# ══════════════════════════════════════════════════════════════
gen = find_node("글생성")
if gen:
    old_stats = "- 단, 입력에 없는 숫자/사례는 만들지 않지만 본문 내용에 따라 현장형 느낌을 주도록 쓴다"
    new_stats = """- 단, 입력에 없는 숫자/사례는 만들지 않지만 본문 내용에 따라 현장형 느낌을 주도록 쓴다
- [키워드 심층 조사]에 수치·통계·연구결과가 있으면 본문에 반드시 포함하고 "(출처: 기관명)" 병기
- 수치를 쓸 때 단순 나열이 아닌, 왜 그 수치가 독자 판단에 중요한지 한 문장 연결
- 전문가가 읽어도 고개를 끄덕일 깊이를 유지하되, 어려운 개념·용어는 쉬운 말로 반드시 풀어쓴다
- 각 H2에 구체적 수치·비율·기간·금액 중 하나 이상이 자연스럽게 포함되면 신뢰도가 올라간다
- "전문성 있는 마케터가 독자를 위해 쓴 글"처럼 읽혀야 한다"""
    txt = gen['parameters']['text']
    if old_stats in txt:
        gen['parameters']['text'] = txt.replace(old_stats, new_stats)
        print('✅ 3. 글생성 수치·전문성·접근성 규칙 추가', file=sys.stderr)
    else:
        print('⚠️  글생성 old_stats 없음', file=sys.stderr)

# ══════════════════════════════════════════════════════════════
# 4. NAVER REBUILD V1 — 업체명 항상 볼드 처리
# ══════════════════════════════════════════════════════════════
naver = find_node("NAVER REBUILD V1")
if naver:
    code = naver['parameters']['jsCode']

    # 4a. 브랜드명 추출 + boldBrand 함수 추가 (data 섹션 바로 뒤)
    old_data = """// -------------------- data --------------------
const j = $json || {};
const src = (j.finalPost && typeof j.finalPost === "object") ? j.finalPost : j;

const summary = norm(src.summary || "");
const bodyMarkdown = norm(src.bodyMarkdown || "");"""

    new_data = """// -------------------- data --------------------
const j = $json || {};
const src = (j.finalPost && typeof j.finalPost === "object") ? j.finalPost : j;

const summary = norm(src.summary || "");
const bodyMarkdown = norm(src.bodyMarkdown || "");

// 업체명 항상 볼드
const _brandRaw = norm(
  j.brand_name || j.ctx?.brand_name ||
  src.brand_name || src.ctx?.brand_name || ""
);
function boldBrand(text) {
  if (!_brandRaw || !text) return text;
  const esc = _brandRaw.replace(/[.*+?^${}()|[\\]\\\\]/g, "\\\\$&");
  return String(text).replace(new RegExp(esc, "g"), `<strong>${_brandRaw}</strong>`);
}"""

    if old_data in code:
        code = code.replace(old_data, new_data)
        print('✅ 4a. brandRaw + boldBrand 함수 추가', file=sys.stderr)
    else:
        print('⚠️  data 섹션 없음', file=sys.stderr)

    # 4b. textLineBlock 렌더링 시 boldBrand 적용
    # restoreAllowedTags(escaped) 결과에 boldBrand 적용
    old_render = """      const escaped = esc(line);
      const safe = restoreAllowedTags(escaped);
      html += textLineBlock(safe, 17, 0);
      html += enter1();
    }
  }

  html += sectionDivider();"""

    new_render = """      const escaped = esc(line);
      const safe = boldBrand(restoreAllowedTags(escaped));
      html += textLineBlock(safe, 17, 0);
      html += enter1();
    }
  }

  html += sectionDivider();"""

    if old_render in code:
        code = code.replace(old_render, new_render)
        print('✅ 4b. 본문 라인 boldBrand 적용', file=sys.stderr)
    else:
        print('⚠️  본문 렌더링 섹션 없음', file=sys.stderr)

    # 4c. summary 렌더링에도 boldBrand 적용
    old_sum_render = """    const escaped = esc(line);
    const safe = restoreAllowedTags(escaped);
    html += textLineBlock(safe, 17, 0);
    html += enter1();
  }
  html += enter1();
}"""

    new_sum_render = """    const escaped = esc(line);
    const safe = boldBrand(restoreAllowedTags(escaped));
    html += textLineBlock(safe, 17, 0);
    html += enter1();
  }
  html += enter1();
}"""

    if old_sum_render in code:
        code = code.replace(old_sum_render, new_sum_render)
        print('✅ 4c. summary 볼드 적용', file=sys.stderr)
    else:
        print('⚠️  summary 렌더링 섹션 없음', file=sys.stderr)

    naver['parameters']['jsCode'] = code

# ══════════════════════════════════════════════════════════════
# OUTPUT
# ══════════════════════════════════════════════════════════════
with open('/root/caify-product/키워드풀_반영.json', 'w') as f:
    json.dump(d, f, ensure_ascii=False, indent=2)

print(json.dumps({"patched": True}))
