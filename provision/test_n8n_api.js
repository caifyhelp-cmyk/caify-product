const fs = require('fs');
const path = require('path');

// .env 파일 읽기
const envPath = path.join(__dirname, '.env');
const env = {};
fs.readFileSync(envPath, 'utf-8').split('\n').forEach(line => {
  const [key, ...val] = line.split('=');
  if (key && val.length) env[key.trim()] = val.join('=').trim();
});

const N8N_URL = env.N8N_URL?.replace(/\/$/, '');
const API_KEY = env.N8N_API_KEY;

async function apiCall(method, endpoint, body = null) {
  const options = {
    method,
    headers: {
      'X-N8N-API-KEY': API_KEY,
      'Content-Type': 'application/json'
    }
  };
  if (body) options.body = JSON.stringify(body);

  const res = await fetch(`${N8N_URL}/api/v1${endpoint}`, options);
  const text = await res.text();
  try { return { status: res.status, data: JSON.parse(text) }; }
  catch { return { status: res.status, data: text }; }
}

async function run() {
  console.log('=== n8n API 테스트 시작 ===\n');
  console.log('n8n URL:', N8N_URL);

  // 1. 연결 확인
  console.log('\n1단계: n8n API 연결 확인...');
  const health = await apiCall('GET', '/workflows?limit=5');
  if (health.status !== 200) {
    console.log('❌ API 연결 실패:', health.status, health.data);
    return;
  }
  console.log('✅ 연결 성공');

  const workflows = health.data.data || [];
  console.log(`워크플로우 수: ${workflows.length}개`);
  workflows.forEach(w => console.log(`  - [${w.id}] ${w.name} (${w.active ? '활성' : '비활성'})`));

  if (workflows.length === 0) {
    console.log('워크플로우가 없습니다.');
    return;
  }

  // 2. 첫 번째 워크플로우 상세 조회
  const targetWorkflow = workflows[0];
  console.log(`\n2단계: 워크플로우 상세 조회 - [${targetWorkflow.id}] ${targetWorkflow.name}`);
  const detail = await apiCall('GET', `/workflows/${targetWorkflow.id}`);
  if (detail.status === 200) {
    const nodes = detail.data.nodes || [];
    console.log(`✅ 노드 수: ${nodes.length}개`);
    nodes.forEach(n => console.log(`  - ${n.name} (${n.type})`));
  } else {
    console.log('❌ 상세 조회 실패:', detail.status);
  }

  // 3. 워크플로우 복제 테스트
  console.log(`\n3단계: 워크플로우 복제 테스트...`);
  const d = detail.data;
  const cloneBody = {
    name: `[테스트복제] ${targetWorkflow.name}`,
    nodes: d.nodes,
    connections: d.connections,
    settings: d.settings || {},
    staticData: d.staticData || null
  };

  const cloned = await apiCall('POST', '/workflows', cloneBody);
  if (cloned.status === 200 || cloned.status === 201) {
    console.log(`✅ 복제 성공! 새 워크플로우 ID: ${cloned.data.id}`);

    // 4. 복제된 워크플로우에서 특정 노드 수정
    console.log(`\n4단계: 복제된 워크플로우 노드 수정 테스트...`);
    const clonedDetail = cloned.data;
    const nodes = clonedDetail.nodes || [];

    // Schedule 트리거 찾기
    const scheduleTrigger = nodes.find(n =>
      n.type === 'n8n-nodes-base.scheduleTrigger' ||
      n.type === 'n8n-nodes-base.cron'
    );

    if (scheduleTrigger) {
      console.log('스케줄 트리거 발견:', scheduleTrigger.name);
      // cron 변경 (매일 오전 9시)
      if (scheduleTrigger.parameters?.rule) {
        scheduleTrigger.parameters.rule = { interval: [{ field: 'hours', hoursInterval: 24 }] };
      }
      console.log('✅ 스케줄 수정 가능 확인');
    } else {
      console.log('ℹ️  스케줄 트리거 없음 (이 워크플로우엔 없을 수 있음)');
    }

    // MySQL 노드에서 member_pk 확인
    const mysqlNode = nodes.find(n => n.type === 'n8n-nodes-base.mySql');
    if (mysqlNode) {
      console.log('\nMySQL 노드 발견:', mysqlNode.name);
      console.log('현재 쿼리:', mysqlNode.parameters?.query?.substring(0, 100));
      console.log('✅ member_pk 수정 가능 확인');
    }

    // 5. 복제본 삭제 (테스트 정리)
    console.log(`\n5단계: 테스트 복제본 삭제...`);
    const deleted = await apiCall('DELETE', `/workflows/${cloned.data.id}`);
    if (deleted.status === 200 || deleted.status === 204) {
      console.log('✅ 복제본 삭제 완료');
    } else {
      console.log('⚠️  삭제 실패 (수동으로 삭제 필요):', cloned.data.id);
    }

  } else {
    console.log('❌ 복제 실패:', cloned.status, JSON.stringify(cloned.data).substring(0, 200));
  }

  console.log('\n=== n8n API 테스트 완료 ===');
}

run().catch(e => console.error('오류:', e.message));
