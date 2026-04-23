/**
 * provision_customer.js
 * 신규 고객 구매 시 n8n 워크플로우 4개를 자동 복제/활성화합니다.
 *
 * 사용법:
 *   node provision_customer.js --member_pk=123 --name="김철수 치과"
 *   node provision_customer.js --member_pk=123 --name="김철수 치과" --dry-run
 *   node provision_customer.js --deprovision --member_pk=123   (워크플로우 삭제)
 */

const path = require('path');

// 공유 n8n 설정 (mock-server/n8n.config.js)
const n8nCfg = require(path.join(__dirname, '../mock-server/n8n.config'));

const N8N_BASE = n8nCfg.N8N_URL.replace(/\/$/, '');
const API_KEY  = n8nCfg.N8N_API_KEY;
const TEMPLATES       = n8nCfg.TEMPLATE_IDS;
const QUEUE_WORKER_IDS = n8nCfg.QUEUE_WORKER_IDS;

// ── n8n API 헬퍼 ─────────────────────────────────────────────
async function api(method, endpoint, body = null) {
  const res = await fetch(`${N8N_BASE}/api/v1${endpoint}`, {
    method,
    headers: {
      'X-N8N-API-KEY': API_KEY,
      'Content-Type': 'application/json',
    },
    body: body ? JSON.stringify(body) : null,
  });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = text; }
  if (!res.ok) throw new Error(`API ${method} ${endpoint} → ${res.status}: ${JSON.stringify(data).substring(0, 200)}`);
  return data;
}

// ── 워크플로우 복제 ───────────────────────────────────────────
async function cloneWorkflow(templateId, newName, dryRun = false) {
  const template = await api('GET', `/workflows/${templateId}`);

  const body = {
    name:        newName,
    nodes:       template.nodes,
    connections: template.connections,
    settings:    template.settings   || {},
    staticData:  template.staticData || null,
  };

  if (dryRun) {
    console.log(`  [dry-run] 복제 예정: "${newName}" (template: ${templateId})`);
    return { id: 'DRY_RUN_ID', name: newName };
  }

  const cloned = await api('POST', '/workflows', body);
  return cloned;
}

// ── 워크플로우 활성화 ─────────────────────────────────────────
async function activateWorkflow(workflowId, dryRun = false) {
  if (dryRun) { console.log(`  [dry-run] 활성화 예정: ${workflowId}`); return; }
  await api('POST', `/workflows/${workflowId}/activate`);
}

// ── 워크플로우 삭제 ───────────────────────────────────────────
async function deleteWorkflow(workflowId, dryRun = false) {
  if (dryRun) { console.log(`  [dry-run] 삭제 예정: ${workflowId}`); return; }
  await api('DELETE', `/workflows/${workflowId}`);
}

// ── Queue Worker의 플레이스홀더 ID 교체 ───────────────────────
// (INFO_SUB_WORKFLOW_ID, PROMO_SUB_WORKFLOW_ID, PLUSA_SUB_WORKFLOW_ID 대체)
// 주의: 전체 고객 공유 워크플로우 구조에서만 사용.
// 개별 고객 라우팅은 DB 테이블로 처리하는 것을 권장.
async function patchQueueWorker(workerId, mapping, dryRun = false) {
  const worker = await api('GET', `/workflows/${workerId}`);

  let patched = false;
  for (const node of worker.nodes) {
    if (node.type !== 'n8n-nodes-base.executeWorkflow') continue;

    const wfId = node.parameters?.workflowId;
    if (wfId === 'INFO_SUB_WORKFLOW_ID'  && mapping.info)  { node.parameters.workflowId = mapping.info;  patched = true; }
    if (wfId === 'PROMO_SUB_WORKFLOW_ID' && mapping.promo) { node.parameters.workflowId = mapping.promo; patched = true; }
    if (wfId === 'PLUSA_SUB_WORKFLOW_ID' && mapping.plusA) { node.parameters.workflowId = mapping.plusA; patched = true; }
  }

  if (!patched) return false;

  if (dryRun) { console.log(`  [dry-run] Queue Worker ${workerId} 패치 예정`); return true; }

  await api('PUT', `/workflows/${workerId}`, {
    name:        worker.name,
    nodes:       worker.nodes,
    connections: worker.connections,
    settings:    worker.settings   || {},
    staticData:  worker.staticData || null,
  });
  return true;
}

// ── 메인: 프로비저닝 ──────────────────────────────────────────
async function provision(memberPk, customerName, dryRun = false) {
  console.log(`\n🚀 프로비저닝 시작: member_pk=${memberPk}, 고객명="${customerName}"${dryRun ? ' [DRY-RUN]' : ''}\n`);

  const mapping = {};

  for (const [type, templateId] of Object.entries(TEMPLATES)) {
    const workflowName = `[${memberPk}] ${customerName} - ${type}`;
    console.log(`📋 [${type}] 복제 중...`);

    try {
      const cloned = await cloneWorkflow(templateId, workflowName, dryRun);
      mapping[type] = cloned.id;
      console.log(`  ✅ 복제 완료: "${cloned.name}" (ID: ${cloned.id})`);

      if (!dryRun) {
        await activateWorkflow(cloned.id);
        console.log(`  ✅ 활성화 완료`);
      }
    } catch (e) {
      console.error(`  ❌ [${type}] 실패:`, e.message);
      mapping[type] = null;
    }
  }

  console.log('\n📊 고객 워크플로우 매핑:');
  console.log(JSON.stringify({ member_pk: memberPk, ...mapping }, null, 2));

  // 결과 파일로 저장 (나중에 DB 저장 또는 PHP 서버 연동)
  const outputPath = path.join(__dirname, `provision_${memberPk}.json`);
  if (!dryRun) {
    fs.writeFileSync(outputPath, JSON.stringify({
      member_pk:    memberPk,
      customer_name: customerName,
      provisioned_at: new Date().toISOString(),
      workflows:    mapping,
    }, null, 2));
    console.log(`\n💾 매핑 저장: ${outputPath}`);
  }

  return mapping;
}

// ── 메인: 디프로비저닝 (워크플로우 삭제) ─────────────────────
async function deprovision(memberPk, dryRun = false) {
  const outputPath = path.join(__dirname, `provision_${memberPk}.json`);
  if (!fs.existsSync(outputPath)) {
    console.error(`❌ 매핑 파일 없음: ${outputPath}`);
    return;
  }

  const data = JSON.parse(fs.readFileSync(outputPath, 'utf-8'));
  console.log(`\n🗑️  디프로비저닝: member_pk=${memberPk}, 고객명="${data.customer_name}"\n`);

  for (const [type, wfId] of Object.entries(data.workflows)) {
    if (!wfId || wfId === 'DRY_RUN_ID') continue;
    console.log(`  [${type}] 삭제 중: ${wfId}`);
    try {
      await deleteWorkflow(wfId, dryRun);
      console.log(`  ✅ 삭제 완료`);
    } catch (e) {
      console.error(`  ❌ 삭제 실패:`, e.message);
    }
  }

  if (!dryRun) {
    fs.unlinkSync(outputPath);
    console.log('\n✅ 디프로비저닝 완료');
  }
}

// ── CLI 파싱 ──────────────────────────────────────────────────
const args = Object.fromEntries(
  process.argv.slice(2).map(a => {
    const m = a.match(/^--([^=]+)(?:=(.*))?$/);
    return m ? [m[1], m[2] ?? true] : [a, true];
  })
);

const memberPk     = args.member_pk ? Number(args.member_pk) : null;
const customerName = args.name      || `고객_${memberPk}`;
const dryRun       = !!args['dry-run'];
const isDeprovision = !!args.deprovision;

if (!memberPk) {
  console.error('사용법: node provision_customer.js --member_pk=123 --name="고객명"');
  process.exit(1);
}

if (isDeprovision) {
  deprovision(memberPk, dryRun).catch(console.error);
} else {
  provision(memberPk, customerName, dryRun).catch(console.error);
}
