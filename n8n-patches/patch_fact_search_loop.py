#!/usr/bin/env python3
"""
patch_fact_search_loop.py — 3축 검색 + 클레임 검증 루프
2026-05-03

변경 내용:
1. 업체+키워드 축 검색 추가 (Perplexity sonar)
   - B2B/전문직: "{brand} {keyword}" 실제 서비스 범위
   - 로컬(음식점/미용/병원 등): "{brand} 후기 방문기"
2. 클레임 검증 (Claude Haiku)
   - 검색 결과에 없는 수치·비용·소요시간·비교 클레임 제거
3. 재검색 루프 (최대 1회)
4. 프롬프트생성1: [업체+키워드 특화 조사] 섹션 추가
                  "수치 많을수록 신뢰도" 문장 제거
"""

import json, uuid, sys, urllib.request, copy, os

N8N_URL  = os.environ.get('N8N_URL',     'https://n8n.caify.ai')
API_KEY  = os.environ.get('N8N_API_KEY', '')
WF_ID    = os.environ.get('WF_ID',       '4ajVXNzlJ52jP02M')
PPLX_KEY = os.environ.get('PPLX_KEY',   '')
ANT_KEY  = os.environ.get('ANT_KEY',    '')

# n8n.config.js 에서 N8N_API_KEY 폴백 로드
if not API_KEY:
    try:
        import subprocess, re
        cfg = subprocess.check_output(['node', '-e',
            "const c=require('./mock-server/n8n.config.js');"
            "process.stdout.write(c.N8N_API_KEY)"],
            cwd=os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
            text=True)
        API_KEY = cfg.strip()
    except Exception:
        pass

def api(method, path, body=None):
    url = N8N_URL + path
    data = json.dumps(body).encode() if body else None
    req = urllib.request.Request(url, data=data, method=method,
          headers={'X-N8N-API-KEY': API_KEY, 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req) as r:
            return json.loads(r.read())
    except urllib.error.HTTPError as e:
        msg = e.read().decode()
        print(f'HTTP {e.code}: {msg[:500]}')
        sys.exit(1)

def uid():
    return str(uuid.uuid4())

# ──────────────────────────────────────────────
# 새 노드 JavaScript 코드
# ──────────────────────────────────────────────

CODE_CKW_PREP = r"""function safeItems(n){try{return $items(n)||[];}catch{return[];}}
function norm(s){return String(s??'').replace(/\s+/g,' ').trim();}
const ctx = $json.ctx || safeItems('가중치부여1')[0]?.json || {};
const brand   = norm(ctx.brand_name || $json.brand_name || '');
const industry= norm(ctx.industry   || $json.industry   || '');
const keyword = norm($json._rag_search_keyword || $json._rag_keyword || '');
const LOCAL_KW = ['음식점','식당','카페','커피','미용','헤어','네일','병원','의원',
  '치과','한의원','마사지','숙박','호텔','펜션','편의점','베이커리','빵집','꽃집'];
const isLocal = LOCAL_KW.some(k => industry.includes(k) || brand.includes(k));
const query = isLocal
  ? `"${brand}" 후기 방문기 실제 경험`
  : `"${brand}" ${keyword} 서비스 특징 실제`;
return {json:{...$json,
  _ckw_brand:brand, _ckw_keyword:keyword, _ckw_is_local:isLocal,
  _retry_count:0,
  _ckw_body:JSON.stringify({model:'sonar',
    messages:[{role:'user',content:query}],
    return_citations:true, search_recency_filter:'month'})}};"""

CODE_CKW_PARSE = r"""function safeItems(n){try{return $items(n)||[];}catch{return[];}}
const prev = safeItems('업체+키워드 준비')[0]?.json || {};
const resp  = $input.first().json;
const content   = resp?.choices?.[0]?.message?.content || '';
const citations = (resp?.citations||[]).slice(0,3).join('\n');
return {json:{...prev,
  company_kw_context: content || '(검색결과 없음)',
  company_kw_sources: citations}};"""

CODE_VERIFY_PREP = r"""function safeItems(n){try{return $items(n)||[];}catch{return[];}}
function norm(s){return String(s??'').replace(/\s+/g,' ').trim();}
const ctx     = $json;
const brand   = norm((ctx.ctx||{}).brand_name || ctx._ckw_brand || '');
const keyword = norm(ctx._ckw_keyword || ctx._rag_search_keyword || '');
const retry   = ctx._retry_count || 0;
const ragCtx  = norm(ctx.rag_context || '');
const bizRes  = norm(safeItems('리서치 결과 파싱')[0]?.json?._biz_research || ctx._biz_research || '');
const ckwCtx  = norm(ctx.company_kw_context || '');
const combined = [
  ragCtx  ? '[키워드 심층 조사]\n'+ragCtx  : '',
  bizRes  ? '[업체 조사]\n'+bizRes          : '',
  ckwCtx  ? '[업체+키워드 특화]\n'+ckwCtx  : ''
].filter(Boolean).join('\n\n');
const noRetry = retry >= 1 ? '이미 재검색을 했으므로 needs_recheck는 반드시 []로 설정하세요.' : '필요한 경우만 제시하세요.';
const prompt = `업체명: ${brand}\n키워드: ${keyword}\n재검색횟수: ${retry}\n\n[검색결과]\n${combined.slice(0,5000)}\n\n작업:\n1. 구체적 클레임(금액/소요시간/성공률/타서비스비교/기술능력) 추출\n2. 검색결과 원문에 없는 것 → UNVERIFIED\n3. UNVERIFIED 제거한 clean_context 작성\n4. 재검색 쿼리 최대2개. ${noRetry}\n\n반드시 JSON만 출력:\n{"verified_claims":[],"unverified_claims":[],"needs_recheck":[],"clean_context":"..."}`;
return {json:{...ctx, _combined_context:combined,
  _verify_req:JSON.stringify({model:'claude-haiku-4-5-20251001',max_tokens:2000,
    system:'블로그 팩트체크 전문가. 검색결과에 없는 구체 클레임 철저 제거. JSON만 출력.',
    messages:[{role:'user',content:prompt}]})}};"""

CODE_VERIFY_PARSE = r"""function safeItems(n){try{return $items(n)||[];}catch{return[];}}
const prev = safeItems('클레임 검증 준비')[0]?.json || {};
const resp  = $input.first().json;
const raw   = resp?.content?.[0]?.text || '';
let parsed = {};
try { const m=raw.match(/\{[\s\S]+\}/); if(m) parsed=JSON.parse(m[0]); } catch(e) {}
const needs  = Array.isArray(parsed.needs_recheck) ? parsed.needs_recheck.filter(Boolean) : [];
const cleanCtx = (parsed.clean_context || prev._combined_context || '').trim();
return {json:{...prev,
  rag_context: cleanCtx,
  _verified_context: cleanCtx,
  _needs_recheck: needs,
  _unverified_claims: parsed.unverified_claims||[],
  _verified_claims:   parsed.verified_claims||[]}};"""

CODE_RECHECK_PREP = r"""const ctx  = $json;
const q0   = (ctx._needs_recheck||[])[0] || '';
const brand= String((ctx.ctx||{}).brand_name || ctx._ckw_brand || '').trim();
const fullQ= brand && !q0.includes(brand) ? brand+' '+q0 : q0;
return {json:{...ctx,
  _recheck_body:JSON.stringify({model:'sonar',
    messages:[{role:'user',content:fullQ}],
    return_citations:true,search_recency_filter:'month'}),
  _retry_count:(ctx._retry_count||0)+1}};"""

CODE_RECHECK_PARSE = r"""function safeItems(n){try{return $items(n)||[];}catch{return[];}}
const prev    = safeItems('재검색 준비')[0]?.json || {};
const resp    = $input.first().json;
const content = resp?.choices?.[0]?.message?.content || '';
const combined = prev._combined_context+'\n\n[재검색결과]\n'+content;
return {json:{...prev,
  _combined_context: combined,
  company_kw_context:(prev.company_kw_context||'')+'\n'+content}};"""

CODE_FINAL = r"""const ctx = $json;
const finalCtx = ctx._verified_context || ctx._combined_context || ctx.rag_context || '';
return {json:{...ctx, rag_context:finalCtx, _fact_check_done:true,
  _unverified_removed:(ctx._unverified_claims||[]).length}};"""


def make_code_node(name, nid, code, pos):
    return {'id': nid, 'name': name, 'type': 'n8n-nodes-base.code',
            'typeVersion': 2, 'position': pos,
            'parameters': {'mode': 'runOnceForAllItems', 'jsCode': code}}

def make_http_pplx(name, nid, body_expr, pos):
    return {'id': nid, 'name': name, 'type': 'n8n-nodes-base.httpRequest',
            'typeVersion': 4.2, 'position': pos,
            'parameters': {
                'method': 'POST',
                'url': 'https://api.perplexity.ai/chat/completions',
                'sendHeaders': True,
                'headerParameters': {'parameters': [
                    {'name': 'Authorization', 'value': f'Bearer {PPLX_KEY}'},
                    {'name': 'Content-Type',  'value': 'application/json'}]},
                'sendBody': True, 'contentType': 'raw',
                'rawContentType': 'application/json',
                'body': '={{ ' + body_expr + ' }}',
                'options': {}}}

def make_http_claude(name, nid, body_expr, pos):
    return {'id': nid, 'name': name, 'type': 'n8n-nodes-base.httpRequest',
            'typeVersion': 4.2, 'position': pos,
            'parameters': {
                'method': 'POST',
                'url': 'https://api.anthropic.com/v1/messages',
                'sendHeaders': True,
                'headerParameters': {'parameters': [
                    {'name': 'x-api-key',        'value': ANT_KEY},
                    {'name': 'anthropic-version', 'value': '2023-06-01'},
                    {'name': 'content-type',      'value': 'application/json'}]},
                'sendBody': True, 'contentType': 'raw',
                'rawContentType': 'application/json',
                'body': '={{ ' + body_expr + ' }}',
                'options': {}}}

def make_if_node(name, nid, expr, pos):
    return {'id': nid, 'name': name, 'type': 'n8n-nodes-base.if',
            'typeVersion': 2, 'position': pos,
            'parameters': {
                'conditions': {
                    'options': {'caseSensitive': True, 'leftValue': '', 'typeValidation': 'strict'},
                    'conditions': [{'id': uid(),
                        'leftValue': '={{ ' + expr + ' }}',
                        'rightValue': True,
                        'operator': {'type': 'boolean', 'operation': 'equal'}}],
                    'combinator': 'and'},
                'options': {}}}


def patch_prompt_builder(code):
    changed = False
    # 1. "수치가 많이 보일수록" 제거
    BAD = '- 수치가 많이 보일수록 독자 신뢰도가 올라간다 — 조사에서 확인된 숫자는 최대한 활용한다\n'
    if BAD in code:
        code = code.replace(BAD, '', 1)
        changed = True

    # 2. [업체+키워드 특화 조사] 섹션 추가
    if '[업체+키워드 특화 조사]' not in code:
        OLD = "${bizResearchText ? `[업체 조사 — 이 업체의 실제 서비스·강점·타겟]\\n${bizResearchText}\\n\\n` : ''}"
        NEW = (OLD + "\n${inJson.company_kw_context ? "
               "`[업체+키워드 특화 조사 — 검증된 실제 서비스 범위·방식·후기]\\n"
               "${String(inJson.company_kw_context).trim()}\\n\\n` : ''}")
        if OLD in code:
            code = code.replace(OLD, NEW, 1)
            changed = True

    return code, changed


def main():
    print('워크플로우 로드 중...')
    wf = api('GET', f'/api/v1/workflows/{WF_ID}')
    nodes = wf['nodes']
    conns = wf.get('connections', {})
    node_map = {n['name']: n for n in nodes}

    # 가드
    if '업체+키워드 준비' in node_map:
        print('이미 적용됨 (업체+키워드 준비 존재)')
        return

    # 프롬프트생성1의 상류 노드 찾기
    PROMPT_NODE = '프롬프트생성1'
    upstream = None
    for src, outs in conns.items():
        for port, targets in outs.items():
            for tlist in targets:
                for t in tlist:
                    if t['node'] == PROMPT_NODE:
                        upstream = src

    if not upstream:
        print(f'오류: {PROMPT_NODE}의 상류 노드를 찾지 못했습니다')
        return

    print(f'삽입 위치: {upstream} → [새 체인] → {PROMPT_NODE}')

    # 프롬프트생성1의 기준 위치
    pn = node_map[PROMPT_NODE]
    BASE_X = pn['position'][0] + 400
    BASE_Y = pn['position'][1] - 800   # 위쪽에 배치
    STEP   = 500

    # 새 노드 ID
    ids = {k: uid() for k in [
        'ckw_prep','ckw_pplx','ckw_parse',
        'vfy_prep','vfy_api','vfy_parse',
        'if_node',
        'rchk_prep','rchk_pplx','rchk_parse',
        'final']}

    new_nodes = [
        make_code_node('업체+키워드 준비',   ids['ckw_prep'],  CODE_CKW_PREP,    [BASE_X,             BASE_Y]),
        make_http_pplx('업체+키워드 Perplexity', ids['ckw_pplx'], '$json._ckw_body',   [BASE_X+STEP,        BASE_Y]),
        make_code_node('업체+키워드 파싱',    ids['ckw_parse'], CODE_CKW_PARSE,   [BASE_X+STEP*2,      BASE_Y]),
        make_code_node('클레임 검증 준비',    ids['vfy_prep'],  CODE_VERIFY_PREP, [BASE_X+STEP*3,      BASE_Y]),
        make_http_claude('클레임 검증 API',  ids['vfy_api'],   '$json._verify_req', [BASE_X+STEP*4,      BASE_Y]),
        make_code_node('클레임 검증 파싱',   ids['vfy_parse'], CODE_VERIFY_PARSE, [BASE_X+STEP*5,      BASE_Y]),
        make_if_node('재검색 여부',          ids['if_node'],
                     '($json._needs_recheck||[]).length>0 && ($json._retry_count||0)<1',
                                                              [BASE_X+STEP*6,      BASE_Y]),
        make_code_node('재검색 준비',        ids['rchk_prep'], CODE_RECHECK_PREP, [BASE_X+STEP*6,      BASE_Y-400]),
        make_http_pplx('재검색 Perplexity', ids['rchk_pplx'], '$json._recheck_body', [BASE_X+STEP*7, BASE_Y-400]),
        make_code_node('재검색 파싱',        ids['rchk_parse'], CODE_RECHECK_PARSE, [BASE_X+STEP*8,   BASE_Y-400]),
        make_code_node('최종 컨텍스트 정리', ids['final'],     CODE_FINAL,        [BASE_X+STEP*7,      BASE_Y]),
    ]

    # ── 연결 수정
    # upstream → 프롬프트생성1 제거
    if upstream in conns:
        for port, targets in conns[upstream].items():
            conns[upstream][port] = [
                [t for t in tlist if t['node'] != PROMPT_NODE]
                for tlist in targets]

    def add_conn(src, dst):
        conns.setdefault(src, {}).setdefault('main', [[]])
        if not conns[src]['main']:
            conns[src]['main'] = [[]]
        conns[src]['main'][0].append({'node': dst, 'type': 'main', 'index': 0})

    add_conn(upstream,           '업체+키워드 준비')
    add_conn('업체+키워드 준비',    '업체+키워드 Perplexity')
    add_conn('업체+키워드 Perplexity', '업체+키워드 파싱')
    add_conn('업체+키워드 파싱',    '클레임 검증 준비')
    add_conn('클레임 검증 준비',    '클레임 검증 API')
    add_conn('클레임 검증 API',     '클레임 검증 파싱')
    add_conn('클레임 검증 파싱',    '재검색 여부')

    # IF FALSE(0) → 최종 컨텍스트 정리, IF TRUE(1) → 재검색 준비
    conns.setdefault('재검색 여부', {})['main'] = [
        [{'node': '최종 컨텍스트 정리', 'type': 'main', 'index': 0}],
        [{'node': '재검색 준비',        'type': 'main', 'index': 0}]]

    add_conn('재검색 준비',        '재검색 Perplexity')
    add_conn('재검색 Perplexity',  '재검색 파싱')
    add_conn('재검색 파싱',        '최종 컨텍스트 정리')
    add_conn('최종 컨텍스트 정리', PROMPT_NODE)

    # ── 프롬프트생성1 패치
    for n in nodes:
        if n['name'] == PROMPT_NODE:
            patched, changed = patch_prompt_builder(n['parameters']['jsCode'])
            n['parameters']['jsCode'] = patched
            print(f'프롬프트생성1: {"패치 완료" if changed else "변경 없음"}')
            break

    # ── 노드 추가 + 업로드
    nodes.extend(new_nodes)
    wf['nodes'] = nodes
    wf['connections'] = conns

    PUT_KEYS = {'name','nodes','connections','settings'}
    payload = {k: v for k, v in wf.items() if k in PUT_KEYS}

    print(f'업로드 중... ({len(nodes)}노드)')
    result = api('PUT', f'/api/v1/workflows/{WF_ID}', payload)
    print(f'완료! {result["name"]} ({len(result["nodes"])}노드)')
    print('추가 노드:', [n['name'] for n in new_nodes])

if __name__ == '__main__':
    main()
