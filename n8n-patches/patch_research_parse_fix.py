"""
리서치 결과 파싱 노드 버그 수정 패치
- _research_available 플래그 추가
- content.slice(0, 1500) → slice(0, 2000)
"""
import json, sys

with open('/root/caify-product/키워드풀_반영.json', 'r') as f:
    d = json.load(f)

old_code = '''return {
  json: {
    ...ctx,
    _biz_research: content.length > 50 ? content.slice(0, 1500) : '',
    _biz_citations: citations.slice(0, 3).join(', ')
  }
};'''

new_code = '''const hasContent = content.length > 50;
return {
  json: {
    ...ctx,
    _biz_research: hasContent ? content.slice(0, 2000) : '',
    _biz_citations: citations.slice(0, 3).join(', '),
    _research_available: hasContent,
  }
};'''

patched = 0
for n in d['nodes']:
    if n['name'] == '리서치 결과 파싱':
        code = n['parameters']['jsCode']
        if old_code in code:
            n['parameters']['jsCode'] = code.replace(old_code, new_code)
            patched += 1
        else:
            print('⚠️  old_code not found', file=sys.stderr)

print(f'패치된 노드: {patched}개', file=sys.stderr)
print(json.dumps(d))
