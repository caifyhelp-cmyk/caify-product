const https = require('https');

const N8N_KEY = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhMmNlNTVlNS01YTUwLTQyMjgtOWM5Yi1hNWM0MzBmNzM4NDEiLCJpc3MiOiJuOG4iLCJhdWQiOiJwdWJsaWMtYXBpIiwiaWF0IjoxNzc2OTgyMzIzfQ.zeIagMQxIuDN-OwQHhKuATLM0CDb-dNRCLuB5zCFzGI";
const WF_ID = 'daUM2xPEVyBhbyez';

function req(method, path, body) {
  return new Promise((res, rej) => {
    const bodyStr = body ? JSON.stringify(body) : null;
    const r = https.request({
      hostname: 'n8n.caify.ai', path, method,
      headers: {
        'X-N8N-API-KEY': N8N_KEY,
        'Content-Type': 'application/json',
        ...(bodyStr ? { 'Content-Length': Buffer.byteLength(bodyStr) } : {})
      }
    }, resp => {
      const c = [];
      resp.on('data', d => c.push(d));
      resp.on('end', () => { try { res(JSON.parse(Buffer.concat(c).toString())); } catch { res(Buffer.concat(c).toString()); } });
    });
    r.on('error', rej);
    if (bodyStr) r.write(bodyStr);
    r.end();
  });
}

// ── 스타일 랜덤화 새 코드 ──────────────────────────────────────────
// 최종 검수(고급모델) = @n8n/n8n-nodes-langchain.googleGemini
// 출력: { content: { parts: [{ text: "```json\n{...}\n```" }] }, finishReason, index }
// 기존 코드는 $json.bodyMarkdown 읽는데 이 시점에는 undefined → no-op
// → content.parts[0].text에서 bodyMarkdown 추출해 정규화 후 content 갱신
const NEW_STYLE_CODE = [
  "function extractFromJson(json) {",
  "  let raw = '';",
  "  if (typeof json?.output === 'string' && json.output.trim()) {",
  "    raw = json.output.trim();",
  "  } else if (Array.isArray(json?.content?.parts)) {",
  "    raw = json.content.parts",
  "      .map(p => (typeof p?.text === 'string' ? p.text : ''))",
  "      .filter(Boolean).join('\\n').trim();",
  "  }",
  "  if (!raw) return null;",
  "  // 코드펜스 제거",
  "  raw = raw.replace(/^```(?:json)?\\s*\\n?/i, '').replace(/\\n?```\\s*$/i, '').trim();",
  "  try { return JSON.parse(raw); } catch { return null; }",
  "}",
  "",
  "function normalizeBody(text) {",
  "  let t = String(text ?? '').replace(/\\r\\n/g, '\\n');",
  "  if (!t.trim()) return '';",
  "  t = t.replace(/\\n{3,}/g, '\\n\\n');",
  "  const paragraphs = t.split('\\n\\n').map(p => p.trim()).filter(Boolean);",
  "  const leadWords = ['또한', '그리고', '하지만', '다만', '즉', '특히', '이 경우', '이때'];",
  "  let prevLead = '';",
  "  const cleaned = paragraphs.map((p) => {",
  "    let out = p;",
  "    for (const word of leadWords) {",
  "      const re = new RegExp('^' + word + '[, ]*');",
  "      if (re.test(out)) {",
  "        if (prevLead === word) out = out.replace(re, '');",
  "        prevLead = word;",
  "        return out.trim();",
  "      }",
  "    }",
  "    prevLead = '';",
  "    return out.trim();",
  "  });",
  "  t = cleaned.join('\\n\\n');",
  "  t = t.replace(/\\n{3,}\\|/g, '\\n\\n|');",
  "  t = t.replace(/\\|\\n{3,}/g, '|\\n\\n');",
  "  t = t.replace(/\\n{3,}\\*/g, '\\n\\n*');",
  "  return t.trim();",
  "}",
  "",
  "const parsed = extractFromJson($json);",
  "if (parsed && parsed.bodyMarkdown) {",
  "  const normalized = normalizeBody(parsed.bodyMarkdown);",
  "  const newParsed = { ...parsed, bodyMarkdown: normalized };",
  "  const newText = JSON.stringify(newParsed);",
  "  // content.parts 갱신 → 단락별쪼개기1이 올바른 bodyMarkdown을 읽을 수 있게",
  "  return {",
  "    ...$json,",
  "    bodyMarkdown: normalized,",
  "    content: {",
  "      ...($json.content || {}),",
  "      parts: [{ text: newText }]",
  "    }",
  "  };",
  "}",
  "// 파싱 불가 시 원본 그대로 통과",
  "return { ...$json };"
].join('\n');

// 문법 검사
try { new Function(NEW_STYLE_CODE); console.log('스타일 랜덤화 new code: Syntax OK'); }
catch(e) { console.error('SYNTAX ERROR:', e.message); process.exit(1); }

async function main() {
  const wf = await req('GET', `/api/v1/workflows/${WF_ID}`);
  if (!wf.nodes) { console.error('Fetch failed'); process.exit(1); }

  // 1) 스타일 랜덤화 코드 교체
  const styleNode = wf.nodes.find(n => n.name === '스타일 랜덤화');
  if (!styleNode) { console.error('스타일 랜덤화 not found'); process.exit(1); }
  styleNode.parameters.jsCode = NEW_STYLE_CODE;

  // 2) HTTP Request1 body에서 "html":"11" → "html": {{ JSON.stringify($json.naverHtml) }}
  const hr1 = wf.nodes.find(n => n.name === 'HTTP Request1');
  if (!hr1) { console.error('HTTP Request1 not found'); process.exit(1); }
  const oldBody = String(hr1.parameters.body || '');
  if (oldBody.includes('"html":"11"')) {
    hr1.parameters.body = oldBody.replace('"html":"11"', '"html": {{ JSON.stringify($json.naverHtml) }}');
    console.log('HTTP Request1 html field: replaced');
  } else {
    console.log('HTTP Request1 html field: "11" not found, current body snippet:', oldBody.substring(0, 100));
  }

  const result = await req('PUT', `/api/v1/workflows/${WF_ID}`, {
    name: wf.name, nodes: wf.nodes,
    connections: wf.connections,
    settings: wf.settings || {}, staticData: wf.staticData || null
  });

  if (!result.id) { console.error('PUT ERROR:', JSON.stringify(result).substring(0, 300)); process.exit(1); }

  // 검증
  const styleCheck = result.nodes.find(n => n.name === '스타일 랜덤화');
  try { new Function(styleCheck.parameters.jsCode); console.log('스타일 랜덤화 live: OK'); }
  catch(e) { console.error('스타일 랜덤화 live FAIL:', e.message); }

  const hr1Check = result.nodes.find(n => n.name === 'HTTP Request1');
  const bodySnippet = String(hr1Check?.parameters?.body || '');
  console.log('HTTP Request1 html field now:', bodySnippet.match(/"html"[^,\n}]*/)?.[0] || 'not found');
  console.log('\nDone.');
}

main().catch(console.error);
