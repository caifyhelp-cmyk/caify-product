"""
키워드풀 반영2 (4ajVXNzlJ52jP02M) 패치
- summary 훅 강화 (인트로 흥미 유발)
- 마무리 섹션 규칙 추가 + 회사 정보 블록 삽입
- 이모지 중복 방지 (번호이모지 + 라벨이모지 동시 방지)
- 색상 span → 볼드 강제
- HTTP Request1 유지
- 매핑6: ctx + cta → NAVER REBUILD V1 전달
"""

import json, sys, urllib.request, urllib.error

N8N_URL = "https://n8n.caify.ai"
API_KEY = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhMmNlNTVlNS01YTUwLTQyMjgtOWM5Yi1hNWM0MzBmNzM4NDEiLCJpc3MiOiJuOG4iLCJhdWQiOiJwdWJsaWMtYXBpIiwiaWF0IjoxNzc2OTgyMzIzfQ.zeIagMQxIuDN-OwQHhKuATLM0CDb-dNRCLuB5zCFzGI"
WF_ID   = "4ajVXNzlJ52jP02M"

def get_workflow():
    req = urllib.request.Request(
        f"{N8N_URL}/api/v1/workflows/{WF_ID}",
        headers={"X-N8N-API-KEY": API_KEY}
    )
    with urllib.request.urlopen(req) as r:
        return json.loads(r.read())

def put_workflow(data):
    body = json.dumps(data).encode()
    req = urllib.request.Request(
        f"{N8N_URL}/api/v1/workflows/{WF_ID}",
        data=body,
        method="PUT",
        headers={"X-N8N-API-KEY": API_KEY, "Content-Type": "application/json"}
    )
    with urllib.request.urlopen(req) as r:
        return json.loads(r.read())

# ──────────────────────────────────────────────
# 프롬프트생성1 패치
# ──────────────────────────────────────────────

def patch_prompt_builder(code: str) -> str:

    # 1) SYSTEM_PROMPT: 마무리 + summary 훅 지침 추가
    OLD_SYS = "- [AI 전형 표현 절대 금지 — 이 표현이 나오면 글 전체가 AI가 쓴 것처럼 보인다]"
    NEW_SYS = (
        "- summary는 독자의 실제 상황·고민을 건드리는 훅 문장으로 시작해야 한다 — 정보 요약이나 \"이 글에서는\" 형식 금지\n"
        "- 글의 마지막 섹션은 \"이제 어떻게 해야 할까\", \"어디서 시작하면 될까\" — 독자의 다음 행동이 자연스럽게 그려지도록 마무리한다\n"
        "- [AI 전형 표현 절대 금지 — 이 표현이 나오면 글 전체가 AI가 쓴 것처럼 보인다]"
    )
    # 이미 적용됐으면 스킵 (NEW_SYS 끝이 OLD_SYS라 중복 적용 방지)
    if 'summary는 독자의 실제 상황·고민을 건드리는 훅 문장' not in code and OLD_SYS in code:
        code = code.replace(OLD_SYS, NEW_SYS, 1)
    # 중복 적용된 경우 정리
    DUPE_HOOK = (
        "- summary는 독자의 실제 상황·고민을 건드리는 훅 문장으로 시작해야 한다 — 정보 요약이나 \"이 글에서는\" 형식 금지\n"
        "- 글의 마지막 섹션은 \"이제 어떻게 해야 할까\", \"어디서 시작하면 될까\" — 독자의 다음 행동이 자연스럽게 그려지도록 마무리한다\n"
        "- summary는 독자의 실제 상황·고민을 건드리는 훅 문장으로 시작해야 한다 — 정보 요약이나 \"이 글에서는\" 형식 금지\n"
        "- 글의 마지막 섹션은 \"이제 어떻게 해야 할까\", \"어디서 시작하면 될까\" — 독자의 다음 행동이 자연스럽게 그려지도록 마무리한다\n"
    )
    SINGLE_HOOK = (
        "- summary는 독자의 실제 상황·고민을 건드리는 훅 문장으로 시작해야 한다 — 정보 요약이나 \"이 글에서는\" 형식 금지\n"
        "- 글의 마지막 섹션은 \"이제 어떻게 해야 할까\", \"어디서 시작하면 될까\" — 독자의 다음 행동이 자연스럽게 그려지도록 마무리한다\n"
    )
    while DUPE_HOOK in code:
        code = code.replace(DUPE_HOOK, SINGLE_HOOK, 1)
    # exprAvoid 바로 뒤에 삽입된 케이스 제거
    BAD_EXPR = (
        "${exprAvoid      ? '- [금지 표현 패턴]      ' + exprAvoid      : ''}\n"
        "- summary는 독자의 실제 상황·고민을 건드리는 훅 문장으로 시작해야 한다 — 정보 요약이나 \"이 글에서는\" 형식 금지\n"
        "- 글의 마지막 섹션은 \"이제 어떻게 해야 할까\", \"어디서 시작하면 될까\" — 독자의 다음 행동이 자연스럽게 그려지도록 마무리한다\n"
    )
    if BAD_EXPR in code:
        code = code.replace(BAD_EXPR,
            "${exprAvoid      ? '- [금지 표현 패턴]      ' + exprAvoid      : ''}\n", 1)

    # 2) [추가 규칙] summary 규칙 강화 (두 줄 교체)
    OLD_SUM = (
        "- summary는 2문장 이내\n"
        "- summary는 정보만 요약하고 끝내지 말고, 검색자가 왜 봐야 하는지가 자연스럽게 드러나야 한다\n"
        "- bodyMarkdown은 반드시 \"## \" 제목만 사용"
    )
    NEW_SUM = (
        "- summary는 2문장 이내\n"
        "- 첫 문장: 독자의 실제 고민·상황·공감으로 시작한다 — 형식은 자유롭게, 핵심은 독자가 \"내 얘기다\"라고 느끼게 하는 것\n"
        "- 두 번째 문장: 이 글을 읽으면 뭘 얻는지 독자 관점의 이득으로 구체적으로 드러낸다\n"
        "- \"이 글에서는\" / \"알아보겠습니다\" / \"살펴보겠습니다\" 같은 표현 금지\n"
        "- 정보 요약이 아니라 계속 읽게 만드는 훅이어야 한다\n"
        "- bodyMarkdown은 반드시 \"## \" 제목만 사용"
    )
    if OLD_SUM in code:
        code = code.replace(OLD_SUM, NEW_SUM, 1)

    # 3) 마무리 섹션 규칙 — [출력 규칙] 앞에 삽입 (이미 있으면 스킵)
    OLD_OUT = "[출력 규칙]\n반드시 JSON"
    NEW_OUT = (
        "[마무리 섹션 규칙]\n"
        "- 마지막 H2는 단순 요약·정리·결론 반복 금지\n"
        "- 마지막 H2의 맨 끝 문장: 독자가 지금 처한 구체적 상황 또는 숫자로 마무리\n"
        "  금지: \"~길입니다\" / \"~선택입니다\" / \"~지워냅니다\" / \"~높입니다\" / 추상적 동기부여 선언\n"
        "  예시: \"혼자 쓰면 3일, 여기 맡기면 오늘 끝납니다.\" / \"지금 이 순간에도 직원이 서류 작업에 묶여 있다면 낭비입니다.\"\n"
        "- 브랜드/서비스는 판단 기준·선택 맥락 안에서 한 번 더 자연스럽게 등장\n"
        "- cta.text 기준: 짧고 구체적인 현실 비교 또는 숫자 1문장\n"
        "  금지: 명령형(\"~하세요\") / \"눈으로 확인합니다\" / 추상 동사\n"
        "  예시: \"직접 붙잡고 고민하면 3일, 맡기면 5분\" / \"심사 전날 밤을 새우지 않아도 됩니다\"\n"
        "- cta.urlOrContact: [고객 정보]의 연락처·홈페이지 URL·SNS 링크 등을 그대로 사용\n"
        "\n"
        "[출력 규칙]\n"
        "반드시 JSON"
    )
    if '[마무리 섹션 규칙]' not in code and OLD_OUT in code:
        code = code.replace(OLD_OUT, NEW_OUT, 1)

    return code


def patch_mamuji_cta(code: str) -> str:
    """마지막 H2 끝 문장 + CTA 규칙 고도화 — 추상 선언형 금지, 구체 숫자 비교형으로"""

    # 마지막 H2 끝 문장 규칙 (구버전 → 신버전)
    OLD_END = (
        "- 마지막 H2의 맨 끝 문장: 독자가 '지금 바로 해야겠다'고 느끼는 행동 트리거로 마무리 — "
        "\"~지름길입니다\" / \"~중요합니다\" / \"~방법입니다\" / \"~아끼는 길입니다\" / \"~길입니다\" / \"~선택입니다\" 로 끝내는 것 금지\n"
        "- 대신: 독자의 현재 상황을 묘사하거나 구체적 행동·결과를 제시하는 문장으로 마무리\n"
        "- 예) \"지금 이 글을 읽는 동안에도 [문제]가 진행 중입니다. [행동]이 가장 빠른 방법입니다.\""
    )
    NEW_END = (
        "- 마지막 H2의 맨 끝 문장: 독자가 지금 처한 구체적 상황 또는 숫자로 마무리\n"
        "  금지: \"~길입니다\" / \"~선택입니다\" / \"~지워냅니다\" / \"~높입니다\" / 추상적 동기부여 선언\n"
        "  금지: 멋진 말로 포장한 결론 ('한 번의 판단이 몇 년을 바꿉니다' 류)\n"
        "  예시:\n"
        "    \"심사 2주 전에 매뉴얼 없으면, 전화 한 통이 가장 빠릅니다.\"\n"
        "    \"혼자 쓰면 3일, 여기 맡기면 오늘 끝납니다.\"\n"
        "    \"지금 이 순간에도 서류 작업에 인력이 묶여 있다면 낭비입니다.\""
    )
    if OLD_END in code:
        code = code.replace(OLD_END, NEW_END, 1)

    # CTA 규칙 (구버전 → 신버전)
    OLD_CTA = (
        "- cta.text 기준: 독자가 지금 겪는 구체적 고통을 짧고 날카롭게 건드리는 1문장\n"
        "  · 금지: \"확인해 보세요\" / \"알아보세요\" / \"문의해 보세요\" / \"물어보는 것이 가장 빠릅니다\"\n"
        "  · 예시: \"혼자 몇 주 끙끙대느니 5분에 끝냅니다.\" / \"아직 수동이라면 지금 바꿀 이유가 있습니다.\""
    )
    NEW_CTA = (
        "- cta.text 기준: 짧고 구체적인 현실 비교 또는 숫자 1문장\n"
        "  금지: 명령형(\"~하세요\") / \"눈으로 확인합니다\" / \"결과를 경험합니다\" 등 체험 묘사형 / 추상 동사\n"
        "  방향: 숫자·시간·비용이 들어간 사실 문장 또는 짧은 대조\n"
        "  예시:\n"
        "    \"직접 하면 3일, 여기 맡기면 5분\"\n"
        "    \"심사 전날 밤을 새우지 않아도 됩니다\"\n"
        "    \"서류 작업에 인력을 묶어둘 이유가 없습니다\""
    )
    if OLD_CTA in code:
        code = code.replace(OLD_CTA, NEW_CTA, 1)

    return code


# ──────────────────────────────────────────────
# NAVER REBUILD V1 패치
# ──────────────────────────────────────────────

def patch_naver_rebuild(code: str) -> str:

    # 1) stripLabelAfterNumEmoji 함수 추가 (stripLabelBeforeNumEmoji 직후)
    OLD_AFTER = (
        "function stripLabelBeforeNumEmoji(line){\n"
        "  const NUM_SET = new Set(['1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣']);\n"
        "  const t = String(line ?? '').trim();\n"
        "  const leadEmoji = getLeadingEmojiPrefix(t);\n"
        "  if (!leadEmoji || NUM_SET.has(leadEmoji.trim())) return t;\n"
        "  // 선두 이모지가 번호이모지가 아닌데, 다음 토큰이 번호이모지면 → 선두 이모지 제거\n"
        "  const rest = stripLeadingEmojiPrefix(t).trimStart();\n"
        "  if ([...NUM_SET].some(e => rest.startsWith(e))) return rest;\n"
        "  return t;\n"
        "}"
    )
    NEW_AFTER = (
        OLD_AFTER + "\n\n"
        "// 번호이모지 뒤에 라벨이모지 겹침 방지: 3️⃣ ✅ → 3️⃣\n"
        "function stripLabelAfterNumEmoji(line){\n"
        "  const t = String(line ?? '').trim();\n"
        "  const nmMatch = t.match(NUM_EMOJI_RE);\n"
        "  if (!nmMatch) return t;\n"
        "  const rest = t.slice(nmMatch[0].length).trimStart();\n"
        "  const labelMatch = rest.match(EMOJI_RE);\n"
        "  if (labelMatch) {\n"
        "    return nmMatch[1] + ' ' + rest.slice(labelMatch[0].length).trimStart();\n"
        "  }\n"
        "  return t;\n"
        "}"
    )
    if OLD_AFTER in code:
        code = code.replace(OLD_AFTER, NEW_AFTER, 1)

    # 2) enhanceLine에 stripLabelAfterNumEmoji 호출 추가
    OLD_ENHANCE = "  t = stripLabelBeforeNumEmoji(t);  // ⚠️ 3️⃣ → 3️⃣\n\n  const list"
    NEW_ENHANCE = (
        "  t = stripLabelBeforeNumEmoji(t);  // ⚠️ 3️⃣ → 3️⃣\n"
        "  t = stripLabelAfterNumEmoji(t);   // 3️⃣ ⚠️ → 3️⃣\n\n"
        "  const list"
    )
    if OLD_ENHANCE in code:
        code = code.replace(OLD_ENHANCE, NEW_ENHANCE, 1)

    # 3) 색상 span → 볼드 강제: color span 복원 시 <strong> 감싸기
    OLD_COLOR = (
        "  // span color 허용 (e.g. <span style=\"color:#e03131\">)\n"
        "  // 텍스트 색상 span\n"
        "  t = t.replace(/&lt;span\\s+style=&quot;color:\\s*(#[0-9a-fA-F]{3,6}|[a-z]+)&quot;&gt;/g,\n"
        "    (m, c) => `<span style=\"color:${c}\">`)"
    )
    NEW_COLOR = (
        "  // span color 허용 (e.g. <span style=\"color:#e03131\">) — 색상은 반드시 볼드와 함께\n"
        "  // 텍스트 색상 span: <strong> 자동 감싸기\n"
        "  t = t.replace(/&lt;span\\s+style=&quot;color:\\s*(#[0-9a-fA-F]{3,6}|[a-z]+)&quot;&gt;/g,\n"
        "    (m, c) => `<strong><span style=\"color:${c}\">`)"
    )
    if OLD_COLOR in code:
        code = code.replace(OLD_COLOR, NEW_COLOR, 1)

    # &lt;/span&gt; 복원 시 </strong> 함께 닫기
    # 단, background-color span은 볼드 불필요하므로 별도 처리
    OLD_SPAN_CLOSE = '  t = t.replace(/&lt;\\/span&gt;/g, "</span>");'
    NEW_SPAN_CLOSE = (
        '  // color span은 <strong>으로 감쌌으므로 </span></strong> 함께 닫기\n'
        '  // background-color span은 <strong> 미적용이므로 </span>만\n'
        '  t = t.replace(/&lt;\\/span&gt;/g, "</span></strong>");\n'
        '  // background-color span은 strong 없이 닫기 (위에서 열지 않았음)\n'
        '  t = t.replace(/<span style="background-color:[^"]+">([\\s\\S]*?)<\\/span><\\/strong>/g,\n'
        '    (m, inner) => m.replace(/<\\/strong>$/, ""));'
    )
    if OLD_SPAN_CLOSE in code:
        code = code.replace(OLD_SPAN_CLOSE, NEW_SPAN_CLOSE, 1)

    # 4) safeItems 헬퍼 + 마무리 회사 정보 블록 — outtro 이미지 직전에 삽입
    OLD_OUTTRO = "// -------------------- ✅ outtro 이미지 --------------------\nif (outtroImageUrl) {"
    NEW_OUTTRO = (
        "// -------------------- 마무리 + 회사 정보 블록 --------------------\n"
        "function safeItems(nodeName) {\n"
        "  try { return $items(nodeName) || []; } catch { return []; }\n"
        "}\n"
        "\n"
        "const _parsedPost = safeItems('단락별쪼개기1')[0]?.json?.rawParsed || {};\n"
        "const _ctaText = norm(_parsedPost.cta?.text || src.cta?.text || j.cta?.text || '');\n"
        "const _ctaContact = norm(_parsedPost.cta?.urlOrContact || src.cta?.urlOrContact || j.cta?.urlOrContact || '');\n"
        "const _ctxData = j.ctx || {};\n"
        "const _companyPhone = norm(_ctxData.phone || _ctxData.contact || j.phone || '');\n"
        "const _companyAddr = norm(_ctxData.address || j.address || j.fullAddress || '');\n"
        "const _companyWeb = norm(_ctxData.website || _ctxData.homepage || j.website || '');\n"
        "\n"
        "if (_brandRaw || _ctaText || _companyPhone || _companyAddr || _companyWeb) {\n"
        "  html += enter1();\n"
        "  html += sectionDivider();\n"
        "  html += enter1();\n"
        "\n"
        "  if (_ctaText) {\n"
        "    const _ctaSafe = boldBrand(restoreAllowedTags(esc(_ctaText)));\n"
        "    html += textLineBlock(`<strong>${_ctaSafe}</strong>`, 17, 0);\n"
        "    html += enter1();\n"
        "  }\n"
        "\n"
        "  const _infoLines = [];\n"
        "  if (_brandRaw) _infoLines.push(`📌 <strong>${esc(_brandRaw)}</strong>`);\n"
        "  if (_companyPhone) _infoLines.push(`📞 <strong>${esc(_companyPhone)}</strong>`);\n"
        "  if (_companyAddr) _infoLines.push(`📍 ${esc(_companyAddr)}`);\n"
        "  if (_companyWeb) _infoLines.push(`🌐 ${esc(_companyWeb)}`);\n"
        "  if (!_companyWeb && _ctaContact) _infoLines.push(`📍 ${esc(_ctaContact)}`);\n"
        "\n"
        "  for (const _line of _infoLines) {\n"
        "    html += textLineBlock(_line, 15, 0);\n"
        "    html += enter1();\n"
        "  }\n"
        "}\n"
        "\n"
        "// -------------------- ✅ outtro 이미지 --------------------\n"
        "if (outtroImageUrl) {"
    )
    if OLD_OUTTRO in code:
        code = code.replace(OLD_OUTTRO, NEW_OUTTRO, 1)

    # 5) debug 태그 업데이트
    OLD_DBG = '"V3 + LIST_SAFE_EMOJI + KO_BOLD_FIX + MD_TABLE_RENDER + STAR_LIST_FIX + EMOJI_LIST_JOIN + KO_SENT_SPLIT + COMPACT_LINES + MEMBER_INTRO_OUTTRO"'
    NEW_DBG = '"V3 + LIST_SAFE_EMOJI + KO_BOLD_FIX + MD_TABLE_RENDER + STAR_LIST_FIX + EMOJI_LIST_JOIN + KO_SENT_SPLIT + COMPACT_LINES + MEMBER_INTRO_OUTTRO + OUTRO_COMPANY_INFO + EMOJI_DEDUP + COLOR_BOLD"'
    if OLD_DBG in code:
        code = code.replace(OLD_DBG, NEW_DBG, 1)

    return code


# ──────────────────────────────────────────────
# main
# ──────────────────────────────────────────────

def patch_mapping6(code: str) -> str:
    if 'cta: promptData?.rawParsed?.cta' in code:
        return code  # 이미 적용됨
    OLD = (
        '    memberPk: memberData?.ctx?.member_pk || $json.memberPk || null,\n'
        '    promptId: memberData?.ctx?.id || $json.promptId || null\n'
        '  }\n'
        '};'
    )
    NEW = (
        '    cta: promptData?.rawParsed?.cta || null,\n'
        '    ctx: memberData?.ctx || {},\n'
        '    memberPk: memberData?.ctx?.member_pk || $json.memberPk || null,\n'
        '    promptId: memberData?.ctx?.id || $json.promptId || null\n'
        '  }\n'
        '};'
    )
    if OLD not in code:
        return code
    return code.replace(OLD, NEW, 1)


def patch_naver_rebuild_outro(code: str) -> str:
    OLD = (
        'function safeItems(nodeName) {\n'
        '  try { return $items(nodeName) || []; } catch { return []; }\n'
        '}\n'
        '\n'
        "const _parsedPost = safeItems('단락별쪼개기1')[0]?.json?.rawParsed || {};\n"
        "const _ctaText = norm(_parsedPost.cta?.text || src.cta?.text || j.cta?.text || '');\n"
        "const _ctaContact = norm(_parsedPost.cta?.urlOrContact || src.cta?.urlOrContact || j.cta?.urlOrContact || '');\n"
        "const _ctxData = j.ctx || {};\n"
        "const _companyPhone = norm(_ctxData.phone || _ctxData.contact || j.phone || '');\n"
        "const _companyAddr = norm(_ctxData.address || j.address || j.fullAddress || '');\n"
        "const _companyWeb = norm(_ctxData.website || _ctxData.homepage || j.website || '');\n"
    )
    NEW = (
        '// ctx, cta는 매핑6에서 전달됨; fallback → 가중치부여1 직접 조회\n'
        'const _ctxData = j.ctx || {};\n'
        'const _memberBase = (function(){ try { return $items("가중치부여1")[0]?.json || {}; } catch { return {}; } })();\n'
        "const _ctaText = norm(j.cta?.text || '');\n"
        "const _ctaContact = norm(j.cta?.urlOrContact || '');\n"
        "const _companyPhone = norm(_ctxData.phone || _ctxData.contact || _memberBase.phone || j.phone || '');\n"
        "const _companyAddr = norm(_ctxData.address || _memberBase.address || j.address || j.fullAddress || '');\n"
        "const _companyWeb = norm(_ctxData.website || _ctxData.homepage || _ctxData.inquiry_channels || _memberBase.inquiry_channels || _memberBase.website || j.website || '');\n"
        "const _companyBrand = norm(_ctxData.brand_name || j.brand_name || _memberBase.brand_name || _brandRaw || '');\n"
    )
    if OLD not in code:
        return code
    code = code.replace(OLD, NEW, 1)
    code = code.replace(
        "if (_brandRaw) _infoLines.push(`📌 <strong>${esc(_brandRaw)}</strong>`);",
        "if (_companyBrand) _infoLines.push(`📌 <strong>${esc(_companyBrand)}</strong>`);",
        1
    )
    return code


def patch_naver_rebuild_ctx_fallback(code: str) -> str:
    """이미 patch_naver_rebuild_outro가 적용된 워크플로우에 ctx fallback 추가"""
    OLD = (
        '// ctx, cta는 매핑6에서 전달됨\n'
        'const _ctxData = j.ctx || {};\n'
        "const _ctaText = norm(j.cta?.text || '');\n"
        "const _ctaContact = norm(j.cta?.urlOrContact || '');\n"
        "const _companyPhone = norm(_ctxData.phone || _ctxData.contact || j.phone || '');\n"
        "const _companyAddr = norm(_ctxData.address || j.address || j.fullAddress || '');\n"
        "const _companyWeb = norm(_ctxData.website || _ctxData.homepage || _ctxData.inquiry_channels || j.website || '');\n"
        "const _companyBrand = norm(_ctxData.brand_name || j.brand_name || _brandRaw || '');\n"
    )
    NEW = (
        '// ctx, cta는 매핑6에서 전달됨; fallback → 가중치부여1 직접 조회\n'
        'const _ctxData = j.ctx || {};\n'
        'const _memberBase = (function(){ try { return $items("가중치부여1")[0]?.json || {}; } catch { return {}; } })();\n'
        "const _ctaText = norm(j.cta?.text || '');\n"
        "const _ctaContact = norm(j.cta?.urlOrContact || '');\n"
        "const _companyPhone = norm(_ctxData.phone || _ctxData.contact || _memberBase.phone || j.phone || '');\n"
        "const _companyAddr = norm(_ctxData.address || _memberBase.address || j.address || j.fullAddress || '');\n"
        "const _companyWeb = norm(_ctxData.website || _ctxData.homepage || _ctxData.inquiry_channels || _memberBase.inquiry_channels || _memberBase.website || j.website || '');\n"
        "const _companyBrand = norm(_ctxData.brand_name || j.brand_name || _memberBase.brand_name || _brandRaw || '');\n"
    )
    if OLD not in code:
        return code  # 이미 적용됐거나 대상 없음
    return code.replace(OLD, NEW, 1)


def patch_prompt_selfcheck(code: str) -> str:
    """AI 금지어 자기검수 단계 추가 — 출력 전 스캔·교체 지시"""
    OLD_BAN = (
        '- [AI 전형 표현 절대 금지 — 이 표현이 나오면 글 전체가 AI가 쓴 것처럼 보인다]\n'
        '  금지 표현: "정리해 드립니다" / "확인해 보시기 바랍니다" / "참고해 주시기 바랍니다" / "도움이 되셨으면 합니다" / "도움이 되길 바랍니다" / "알아보겠습니다" / "살펴보겠습니다" / "알아보도록 하겠습니다" / "권해드립니다" / "추천해 드립니다" / "많은 분들이" / "많은 사람들이" / "다양한 방법으로" / "중요한 역할을 합니다" / "허다합니다" / "십상입니다" / "이듬해" / "획기적으로" / "필수불가결" / "대입해 보세요" / "현명한 선택" / "최적의 솔루션" / "알려드릴 테니" / "얻어갈 수 있습니다" / "알아두면 좋습니다" / "중요합니다" / "필요합니다" / "이러한" / "이와 같은" / "해당합니다" / "바랍니다"\n'
        '  문장 패턴 금지: "~할 수 있습니다"가 동일 단락 내 2회 이상 반복 / 모든 섹션 첫 문장이 같은 방식으로 시작 / 문어체 어미("~십상입니다", "~허다합니다", "~이듬해")로 끝나는 문장'
    )
    NEW_BAN = (
        '- [AI 전형 표현 절대 금지 — 이 표현이 나오면 글 전체가 AI가 쓴 것처럼 보인다]\n'
        '  금지 표현: "정리해 드립니다" / "확인해 보시기 바랍니다" / "참고해 주시기 바랍니다" / "도움이 되셨으면 합니다" / "도움이 되길 바랍니다" / "알아보겠습니다" / "살펴보겠습니다" / "알아보도록 하겠습니다" / "권해드립니다" / "추천해 드립니다" / "많은 분들이" / "많은 사람들이" / "다양한 방법으로" / "중요한 역할을 합니다" / "허다합니다" / "십상입니다" / "이듬해" / "획기적으로" / "필수불가결" / "대입해 보세요" / "현명한 선택" / "최적의 솔루션" / "알려드릴 테니" / "얻어갈 수 있습니다" / "알아두면 좋습니다" / "중요합니다" / "필요합니다" / "이러한" / "이와 같은" / "해당합니다" / "바랍니다"\n'
        '  문장 패턴 금지: "~할 수 있습니다"가 동일 단락 내 2회 이상 반복 / 모든 섹션 첫 문장이 같은 방식으로 시작 / 문어체 어미("~십상입니다", "~허다합니다", "~이듬해")로 끝나는 문장\n'
        '\n'
        '- [출력 전 자기검수 — 아래 표현이 본문에 있으면 즉시 교체 후 출력]\n'
        '  "허다합니다" → "많습니다" / "흔합니다" 등\n'
        '  "십상입니다" → "쉽습니다" / "자주 벌어집니다" 등\n'
        '  "이러한" / "이와 같은" → 바로 앞에서 언급한 구체적 명사로 직접 표현\n'
        '  "획기적으로" → 삭제하거나 구체적 수치·변화로 대체\n'
        '  "이듬해" → "다음 해"\n'
        '  "중요합니다" / "필요합니다" → 왜 중요한지·필요한지를 문장으로 풀어쓰기'
    )
    if OLD_BAN not in code:
        return code  # 이미 적용됐거나 대상 없음
    return code.replace(OLD_BAN, NEW_BAN, 1)


def patch_keyword_rotation(code: str) -> str:
    """폴백에서 전체 동일 날짜이면 Fisher-Yates 셔플 순서 유지 (score 무시 → 진짜 랜덤 회전)"""
    OLD_SEL = (
        'const scored = pool\n'
        '  .map(r => ({ ...r, _score: scoreKw(r.keyword) }))\n'
        '  .sort((a, b) => b._score - a._score || Math.random() - 0.5);\n'
        '\n'
        'const sel = scored[0];'
    )
    if OLD_SEL not in code:
        return code  # 이미 적용됐거나 대상 없음
    NEW_SEL = (
        'const _isAllUsed = available.length === 0;\n'
        'const scored = pool\n'
        '  .map(r => ({ ...r, _score: scoreKw(r.keyword) }))\n'
        '  .sort((a, b) => {\n'
        "    if (_isAllUsed) {\n"
        "      const da = a.last_used_at || '0';\n"
        "      const db = b.last_used_at || '0';\n"
        "      if (da !== db) return da < db ? -1 : 1;\n"
        "    }\n"
        '    return b._score - a._score || Math.random() - 0.5;\n'
        '  });\n'
        '\n'
        '// 폴백 전체가 같은 날짜면 Fisher-Yates 셔플 순서 그대로 사용 (score 무시)\n'
        "const _isAllSameDate = _isAllUsed &&\n"
        "  pool.every(r => (r.last_used_at || '0') === (pool[0]?.last_used_at || '0'));\n"
        'const sel = _isAllSameDate\n'
        '  ? { ...pool[0], _score: scoreKw(pool[0].keyword) }\n'
        '  : scored[0];'
    )
    return code.replace(OLD_SEL, NEW_SEL, 1)


def main():
    print("워크플로우 다운로드 중...")
    wf = get_workflow()

    changed = []
    for node in wf["nodes"]:
        name = node["name"]
        if name == "프롬프트생성1":
            original = node["parameters"]["jsCode"]
            patched  = patch_prompt_builder(original)
            patched  = patch_prompt_selfcheck(patched)
            patched  = patch_mamuji_cta(patched)
            if patched != original:
                node["parameters"]["jsCode"] = patched
                changed.append(name)
                print(f"  ✅ {name} 패치 완료")
            else:
                print(f"  ⚠️  {name} 변경 없음")

        elif name == "매핑6":
            original = node["parameters"]["jsCode"]
            patched  = patch_mapping6(original)
            if patched != original:
                node["parameters"]["jsCode"] = patched
                changed.append(name)
                print(f"  ✅ {name} 패치 완료")
            else:
                print(f"  ⚠️  {name} 변경 없음")

        elif name == "키워드 가져오기(plusA)1":
            original = node["parameters"]["jsCode"]
            patched  = patch_keyword_rotation(original)
            if patched != original:
                node["parameters"]["jsCode"] = patched
                changed.append(name)
                print(f"  ✅ {name} 패치 완료")
            else:
                print(f"  ⚠️  {name} 변경 없음")

        elif name == "NAVER REBUILD V1":
            original = node["parameters"]["jsCode"]
            patched  = patch_naver_rebuild(original)
            if patched != original:
                patched = patch_naver_rebuild_outro(patched)
            # ctx fallback은 이미 outro 적용된 상태에도 추가 적용
            patched2 = patch_naver_rebuild_ctx_fallback(patched)
            if patched2 != original:
                node["parameters"]["jsCode"] = patched2
                changed.append(name)
                print(f"  ✅ {name} 패치 완료")
            else:
                print(f"  ⚠️  {name} 변경 없음")

    if not changed:
        print("변경된 노드 없음 — 업로드 생략")
        return

    # HTTP Request1 무결성 확인 (건드리지 않았는지)
    for node in wf["nodes"]:
        if node["name"] == "HTTP Request1":
            assert node["parameters"].get("url") == "https://caify.ai/api", \
                "HTTP Request1 url 변경됨 — 업로드 중단"
            print("  ✅ HTTP Request1 무결성 확인 OK")
            break

    # n8n API PUT에 필요한 필드만 추출
    payload = {
        "name":        wf["name"],
        "nodes":       wf["nodes"],
        "connections": wf["connections"],
        "settings":    wf.get("settings", {}),
        "staticData":  wf.get("staticData")
    }

    print(f"\n업로드 중... (변경 노드: {changed})")
    result = put_workflow(payload)
    print(f"업로드 완료: {result.get('name')} (id={result.get('id')})")

if __name__ == "__main__":
    main()
