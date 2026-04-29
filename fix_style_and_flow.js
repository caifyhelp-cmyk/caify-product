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

// ── 1. 스타일 랜덤화1 ─────────────────────────────────────────────────
// 입력: 최종 검수(고급모델) Gemini 출력 → content.parts[0].text = "```json\n{...}\n```"
// 출력: { ...$json, output: JSON.stringify(normalizedParsed) }
//       → 단락별쪼개기가 json.output(string)으로 읽을 수 있게
const STYLE_CODE = `
function extractJson(json) {
  let raw = '';
  if (typeof json?.output === 'string' && json.output.trim()) {
    raw = json.output.trim();
  } else if (Array.isArray(json?.content?.parts)) {
    raw = json.content.parts
      .map(p => (typeof p?.text === 'string' ? p.text : ''))
      .filter(Boolean).join('\\n').trim();
  } else if (Array.isArray(json?.candidates)) {
    for (const c of json.candidates) {
      const t = (c?.content?.parts || []).map(p => p?.text || '').filter(Boolean).join('\\n').trim();
      if (t) { raw = t; break; }
    }
  }
  if (!raw) return null;
  raw = raw.replace(/^\`\`\`(?:json)?\\s*\\n?/i, '').replace(/\\n?\`\`\`\\s*$/i, '').trim();
  try { return JSON.parse(raw); } catch { return null; }
}

function normalizeBody(text) {
  let t = String(text ?? '').replace(/\\r\\n/g, '\\n');
  if (!t.trim()) return '';
  t = t.replace(/\\n{3,}/g, '\\n\\n');
  const paragraphs = t.split('\\n\\n').map(p => p.trim()).filter(Boolean);
  const leadWords = ['또한', '그리고', '하지만', '다만', '즉', '특히', '이 경우', '이때'];
  let prevLead = '';
  const cleaned = paragraphs.map((p) => {
    let out = p;
    for (const word of leadWords) {
      const re = new RegExp('^' + word + '[, ]*');
      if (re.test(out)) {
        if (prevLead === word) out = out.replace(re, '');
        prevLead = word;
        return out.trim();
      }
    }
    prevLead = '';
    return out.trim();
  });
  t = cleaned.join('\\n\\n');
  t = t.replace(/\\n{3,}\\|/g, '\\n\\n|');
  t = t.replace(/\\|\\n{3,}/g, '|\\n\\n');
  t = t.replace(/\\n{3,}\\*/g, '\\n\\n*');
  return t.trim();
}

function diversifyStyle(body) {
  if (!body) return body;
  const aiPatterns = [
    /이(?:에 대해|를 통해) (?:알아보|살펴보)겠습니다[.!]?/g,
    /(?:지금|이제)부터 (?:알아보|살펴보)(?:겠습니다|도록 하겠습니다)[.!]?/g,
    /이번 (?:글|포스팅)에서는 .{5,30}(?:정리|소개|안내)(?:해 보겠습니다|했습니다|합니다)[.!]?/g
  ];
  for (const pat of aiPatterns) { body = body.replace(pat, ''); }
  return body.replace(/\\n{3,}/g, '\\n\\n').trim();
}

const parsed = extractJson($json);
if (!parsed) {
  // 파싱 불가: 원본 그대로 통과 (단락별쪼개기가 에러 처리)
  return { ...$json };
}

let body = parsed.bodyMarkdown || '';
body = normalizeBody(body);
body = diversifyStyle(body);

const result = { ...parsed, bodyMarkdown: body };

// 핵심: output 필드에 JSON 문자열로 담아서 단락별쪼개기가 json.output 경로로 읽게 함
return { ...$json, output: JSON.stringify(result) };
`.trim();

// ── 2. 이미지배열정리1 ─────────────────────────────────────────────────
// AI Agent (OpenAI) 출력 파싱 — output 필드, 코드블록 제거, 빈 배열 안전 처리
const IMG_PARSE_CODE = `
let raw = '';

// OpenAI Agent는 output 필드에 string으로 반환
if (typeof $json?.output === 'string') {
  raw = $json.output.trim();
} else if (typeof $json?.text === 'string') {
  raw = $json.text.trim();
}

// 코드블록 제거
raw = raw.replace(/^\`\`\`(?:json)?\\s*\\n?/i, '').replace(/\\n?\`\`\`\\s*$/i, '').trim();

// JSON 배열 추출
let data = [];
if (raw) {
  const match = raw.match(/\\[[\\s\\S]*\\]/);
  try {
    data = JSON.parse(match ? match[0] : raw);
  } catch (e) {
    // 파싱 실패 시 빈 배열 (이미지 없이 진행)
    data = [];
  }
}

if (!Array.isArray(data)) data = [];

return [{
  json: {
    ...$json,
    items: data.map((item, i) => ({
      idx: item.idx || i + 1,
      text: item.prompt || '',
      mode: item.mode || 'photo'
    }))
  }
}];
`.trim();

// ── 3. 매핑 수정 ──────────────────────────────────────────────────────
// Collect Success Images 결과 + 단락별쪼개기 데이터 합성
// $json에 naverHtml이 없으면 Code in JavaScript2 이후에도 없게 됨 → 매핑에서 가져오기
const MAPPING_CODE = `
function safeItems(nodeName) {
  try { return $items(nodeName) || []; } catch { return []; }
}

const promptData = safeItems('단락별쪼개기')[0]?.json;
const memberData = safeItems('가중치_키워드1')[0]?.json || {};

return {
  json: {
    ...$json,
    bodyMarkdown: promptData?.rawParsed?.bodyMarkdown || promptData?.rawParsed?.body || '',
    summary: promptData?.rawParsed?.summary || promptData?.summary || '',
    title: promptData?.rawParsed?.title || promptData?.title || '',
    memberPk: memberData?.ctx?.member_pk || $json.memberPk || null,
    promptId: memberData?.ctx?.id || $json.promptId || null
  }
};
`.trim();

// Syntax checks
const checks = {
  '스타일 랜덤화1': STYLE_CODE,
  '이미지배열정리1': IMG_PARSE_CODE,
  '매핑': MAPPING_CODE,
};
let hasError = false;
for (const [name, code] of Object.entries(checks)) {
  try { new Function(code); console.log(`✓ ${name}: Syntax OK`); }
  catch (e) { console.error(`✗ ${name}: SYNTAX ERROR:`, e.message); hasError = true; }
}
if (hasError) process.exit(1);

async function main() {
  const wf = await req('GET', `/api/v1/workflows/${WF_ID}`);
  if (!wf.nodes) { console.error('Fetch failed'); process.exit(1); }

  const nodes = wf.nodes;
  const conns = wf.connections;

  // Fix 스타일 랜덤화1
  const s1 = nodes.find(n => n.name === '스타일 랜덤화1');
  if (!s1) { console.error('스타일 랜덤화1 not found'); process.exit(1); }
  s1.parameters.jsCode = STYLE_CODE;
  console.log('✓ 스타일 랜덤화1: updated (now outputs json.output string)');

  // Fix 이미지배열정리1
  const img1 = nodes.find(n => n.name === '이미지배열정리1');
  if (img1) {
    img1.parameters.jsCode = IMG_PARSE_CODE;
    console.log('✓ 이미지배열정리1: updated (code block stripping + safe empty array)');
  }

  // Fix 매핑
  const mapNode = nodes.find(n => n.name === '매핑');
  if (mapNode) {
    mapNode.parameters.jsCode = MAPPING_CODE;
    console.log('✓ 매핑: updated');
  }

  const result = await req('PUT', `/api/v1/workflows/${WF_ID}`, {
    name: wf.name,
    nodes,
    connections: conns,
    settings: wf.settings || {},
    staticData: wf.staticData || null
  });

  if (!result.id) {
    console.error('PUT ERROR:', JSON.stringify(result).substring(0, 500));
    process.exit(1);
  }

  // Verify
  console.log('\n=== VERIFICATION ===');
  const s1v = result.nodes.find(n => n.name === '스타일 랜덤화1');
  try {
    new Function(s1v?.parameters?.jsCode || '');
    // Quick logic check: does it output 'output' field?
    const hasOutput = s1v?.parameters?.jsCode?.includes("output: JSON.stringify(result)");
    console.log('✓ 스타일 랜덤화1 live:', hasOutput ? 'returns output field ✓' : 'WARNING: no output field');
  } catch(e) { console.error('✗ 스타일 랜덤화1 FAIL:', e.message); }

  const img1v = result.nodes.find(n => n.name === '이미지배열정리1');
  const hasStrip = img1v?.parameters?.jsCode?.includes('code block');
  console.log('✓ 이미지배열정리1:', hasStrip ? 'code block stripping OK' : 'WARNING: check code');

  // Confirm 단락별쪼개기 uses json.output path (read its extractRawText)
  const dv = result.nodes.find(n => n.name === '단락별쪼개기');
  const hasOutputPath = dv?.parameters?.jsCode?.includes('json?.output');
  console.log('✓ 단락별쪼개기 reads json.output:', hasOutputPath ? 'YES' : 'NO');

  console.log('\n스타일 랜덤화1 output → 단락별쪼개기 input chain:');
  console.log('  스타일 랜덤화1: Gemini content.parts → extractJson → normalizeBody → returns { output: JSON.stringify(result) }');
  console.log('  단락별쪼개기: reads json.output → stripCodeFences → safeJsonParse → extracts title/sections');
  console.log('  AI 이미지 프롬프트 준비: reads $json.sections → builds prompt → sets _img_prompt');
  console.log('  AI Agent: uses $json._img_prompt → outputs JSON array');
  console.log('  이미지배열정리1: strips code blocks → parse JSON array → items[]');
  console.log('\nDone.');
}

main().catch(console.error);
