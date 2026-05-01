"""
키워드풀 반영2 (4ajVXNzlJ52jP02M) 패치
- summary 훅 강화 (인트로 흥미 유발)
- 마무리 섹션 규칙 추가 + 회사 정보 블록 삽입
- 이모지 중복 방지 (번호이모지 + 라벨이모지 동시 방지)
- 색상 span → 볼드 강제
- HTTP Request1 유지
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
    assert OLD_SYS in code, "SYSTEM_PROMPT 타겟 문자열 못 찾음"
    code = code.replace(OLD_SYS, NEW_SYS, 1)

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
    assert OLD_SUM in code, "[추가 규칙] summary 타겟 문자열 못 찾음"
    code = code.replace(OLD_SUM, NEW_SUM, 1)

    # 3) 마무리 섹션 규칙 — [출력 규칙] 앞에 삽입
    OLD_OUT = "[출력 규칙]\n반드시 JSON"
    NEW_OUT = (
        "[마무리 섹션 규칙]\n"
        "- 마지막 H2 또는 별도 마무리 단락은 단순 요약 반복 금지\n"
        "- 독자가 \"이제 한 번 더 따져봐야겠다\" 또는 \"한 번 알아봐야겠다\"는 행동 욕구가 자연스럽게 남도록 작성\n"
        "- 브랜드/서비스가 판단 기준·확인 포인트·맥락 안에서 자연스럽게 한 번 더 등장\n"
        "- cta.text: 독자가 자연스럽게 다음 행동을 떠올리는 문장으로, 직접적인 광고 문구 금지\n"
        "- cta.urlOrContact: [고객 정보]의 연락처·홈페이지 URL·SNS 링크 등을 그대로 사용\n"
        "\n"
        "[출력 규칙]\n"
        "반드시 JSON"
    )
    assert OLD_OUT in code, "[출력 규칙] 타겟 문자열 못 찾음"
    code = code.replace(OLD_OUT, NEW_OUT, 1)

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
    assert OLD_AFTER in code, "stripLabelBeforeNumEmoji 타겟 못 찾음"
    code = code.replace(OLD_AFTER, NEW_AFTER, 1)

    # 2) enhanceLine에 stripLabelAfterNumEmoji 호출 추가
    OLD_ENHANCE = "  t = stripLabelBeforeNumEmoji(t);  // ⚠️ 3️⃣ → 3️⃣\n\n  const list"
    NEW_ENHANCE = (
        "  t = stripLabelBeforeNumEmoji(t);  // ⚠️ 3️⃣ → 3️⃣\n"
        "  t = stripLabelAfterNumEmoji(t);   // 3️⃣ ⚠️ → 3️⃣\n\n"
        "  const list"
    )
    assert OLD_ENHANCE in code, "enhanceLine stripLabelBefore 타겟 못 찾음"
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
    assert OLD_COLOR in code, "color span 타겟 못 찾음"
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
    assert OLD_SPAN_CLOSE in code, "span close 타겟 못 찾음"
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
    assert OLD_OUTTRO in code, "outtro 이미지 타겟 못 찾음"
    code = code.replace(OLD_OUTTRO, NEW_OUTTRO, 1)

    # 5) debug 태그 업데이트
    OLD_DBG = '"V3 + LIST_SAFE_EMOJI + KO_BOLD_FIX + MD_TABLE_RENDER + STAR_LIST_FIX + EMOJI_LIST_JOIN + KO_SENT_SPLIT + COMPACT_LINES + MEMBER_INTRO_OUTTRO"'
    NEW_DBG = '"V3 + LIST_SAFE_EMOJI + KO_BOLD_FIX + MD_TABLE_RENDER + STAR_LIST_FIX + EMOJI_LIST_JOIN + KO_SENT_SPLIT + COMPACT_LINES + MEMBER_INTRO_OUTTRO + OUTRO_COMPANY_INFO + EMOJI_DEDUP + COLOR_BOLD"'
    assert OLD_DBG in code, "debug tag 타겟 못 찾음"
    code = code.replace(OLD_DBG, NEW_DBG, 1)

    return code


# ──────────────────────────────────────────────
# main
# ──────────────────────────────────────────────

def main():
    print("워크플로우 다운로드 중...")
    wf = get_workflow()

    changed = []
    for node in wf["nodes"]:
        name = node["name"]
        if name == "프롬프트생성1":
            original = node["parameters"]["jsCode"]
            patched  = patch_prompt_builder(original)
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
                node["parameters"]["jsCode"] = patched
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
