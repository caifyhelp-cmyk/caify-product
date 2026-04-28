"""
패치 v10: 이미지 생성 파이프라인 전면 교체
- 구 파이프라인 제거: AI Agent1(Gemini), fal Generate1/4, Init Retry/4, Wait/4,
  Check Status/4, Is Completed?/4, Get Result/4, Extract Image/4,
  Retry Counter/4, FAIL/2, Retry < 20?/2, Merge All, Collect Success Images1
- 신 파이프라인 적용 (이거.txt 기반):
  * AI Agent (OpenAI 모델)
  * fal Generate / fal Generate3 (openai/gpt-image-2)
  * Init Retry2/3, Wait2/3, Check Status2/3, Is Completed?2/3,
    Get Result2/3, Extract Image2/3, Retry Counter2/3,
    FAIL1/3, Retry < 20?1/3, Merge All1
  * Collect Success Images
  * imageSkip 분기: If → imageSkip → 이미지url매칭 → 매핑
- 경계 연결:
  * 단락별쪼개기1 → If (기존: 단락별쪼개기1 → AI Agent1)
  * 매핑 → 매핑7
"""
import json, sys, io, urllib.request

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')

N8N_KEY  = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhMmNlNTVlNS01YTUwLTQyMjgtOWM5Yi1hNWM0MzBmNzM4NDEiLCJpc3MiOiJuOG4iLCJhdWQiOiJwdWJsaWMtYXBpIiwiaWF0IjoxNzc2OTgyMzIzfQ.zeIagMQxIuDN-OwQHhKuATLM0CDb-dNRCLuB5zCFzGI'
WF_ID    = 'daUM2xPEVyBhbyez'
NEW_IMG_PATH = 'C:/Users/조경일/Desktop/이거.txt'  # 이미지 파이프라인 소스

OLD_IMG = {
    'AI Agent1',
    'Google Gemini Chat Model', 'Google Gemini Chat Model2', 'Google Gemini Chat Model3',
    '이미지배열정리', 'IMG - 분할', 'If4',
    'fal Generate1', 'fal Generate4',
    'Init Retry', 'Init Retry4',
    'Wait', 'Wait4',
    'Check Status', 'Check Status4',
    'Is Completed?', 'Is Completed?4',
    'Get Result', 'Get Result4',
    'Extract Image', 'Extract Image4',
    'Retry Counter', 'Retry Counter4',
    'FAIL', 'FAIL2',
    'Retry < 20?', 'Retry < 20?2',
    'Merge All', 'Collect Success Images1',
}

# 1. 라이브 워크플로우 다운로드
req = urllib.request.Request(
    f'https://n8n.caify.ai/api/v1/workflows/{WF_ID}',
    headers={'X-N8N-API-KEY': N8N_KEY}
)
with urllib.request.urlopen(req) as res:
    live = json.load(res)

# 2. 신 이미지 노드 소스 로드
with open(NEW_IMG_PATH, 'r', encoding='utf-8') as f:
    new = json.load(f)

ok, warn = [], []

# 3. 구 이미지 노드 제거
before = len(live['nodes'])
live['nodes'] = [n for n in live['nodes'] if n['name'] not in OLD_IMG]
ok.append(f"구 이미지 노드 제거: {before - len(live['nodes'])}개")

# 4. 신 이미지 노드 추가
for n in new['nodes']:
    live['nodes'].append(n)
ok.append(f"신 이미지 노드 추가: {len(new['nodes'])}개 → 총 {len(live['nodes'])}개")

# 5. 연결 정리 (구 노드 참조 제거)
live['connections'] = {
    src: targets
    for src, targets in live['connections'].items()
    if src not in OLD_IMG
}
for src in list(live['connections'].keys()):
    live['connections'][src]['main'] = [
        [t for t in (out or []) if t['node'] not in OLD_IMG]
        for out in live['connections'][src].get('main', [])
    ]

# 6. 신 이미지 연결 추가
for src, targets in new['connections'].items():
    live['connections'][src] = targets

# 7. 경계 연결
live['connections']['단락별쪼개기1'] = {
    "main": [[{"node": "If", "type": "main", "index": 0}]]
}
ok.append("단락별쪼개기1 → If")

node_names = {n['name'] for n in live['nodes']}
downstream = '매핑7' if '매핑7' in node_names else 'Merge'
live['connections']['매핑'] = {
    "main": [[{"node": downstream, "type": "main", "index": 0}]]
}
ok.append(f"매핑 → {downstream}")

# 8. PUT
payload = {k: live[k] for k in ['name','nodes','connections','settings','staticData'] if k in live}
data = json.dumps(payload, ensure_ascii=False).encode('utf-8')
req = urllib.request.Request(
    f'https://n8n.caify.ai/api/v1/workflows/{WF_ID}',
    data=data, method='PUT',
    headers={'X-N8N-API-KEY': N8N_KEY, 'Content-Type': 'application/json'}
)
try:
    with urllib.request.urlopen(req) as res:
        r = json.load(res)
        ok.append(f"PUT OK | updatedAt: {r.get('updatedAt')}")
except urllib.error.HTTPError as e:
    warn.append(f"PUT 실패: {e.code} {e.read().decode()[:200]}")

print("=== 결과 ===")
for m in ok:   print(f"OK   {m}")
for m in warn: print(f"WARN {m}")
