const { app, BrowserWindow, Tray, Menu, Notification, nativeImage, ipcMain } = require('electron');
const path = require('path');
const Store = require('electron-store');

const store = new Store();
const sleep = ms => new Promise(r => setTimeout(r, ms));

let tray = null;
let settingsWin = null;
let isPublishing = false;
let pollTimer = null;

// ── 앱 시작 ──────────────────────────────────────────────────
app.whenReady().then(() => {
  createTray();
  startPolling();
});

app.on('window-all-closed', e => e.preventDefault());

// ── 트레이 ────────────────────────────────────────────────────
function createTray() {
  const ICON_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
  let icon;
  try {
    icon = nativeImage.createFromPath(path.join(__dirname, 'icon.ico'));
    if (icon.isEmpty()) throw new Error('no ico');
  } catch {
    icon = nativeImage.createFromDataURL('data:image/png;base64,' + ICON_B64)
      .resize({ width: 16, height: 16 });
  }

  tray = new Tray(icon);
  tray.setToolTip('Caify 발행 도우미');
  setTrayStatus('대기 중');
}

function setTrayStatus(status) {
  const menu = Menu.buildFromTemplate([
    { label: `Caify  [${status}]`, enabled: false },
    { type: 'separator' },
    { label: '지금 확인', click: () => checkNewPosts() },
    { label: '설정', click: openSettings },
    { label: '네이버 로그인', click: openNaverLogin },
    { type: 'separator' },
    { label: '종료', role: 'quit' }
  ]);
  tray.setContextMenu(menu);
}

// ── 설정 창 ──────────────────────────────────────────────────
function openSettings() {
  if (settingsWin) return settingsWin.focus();
  settingsWin = new BrowserWindow({
    width: 440, height: 500, resizable: false,
    title: 'Caify 설정',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true
    }
  });
  settingsWin.loadFile('settings.html');
  settingsWin.setMenu(null);
  settingsWin.on('closed', () => { settingsWin = null; });
}

// ── 네이버 로그인 (최초 1회 — 쿠키 영구 저장) ────────────────
function openNaverLogin() {
  const win = new BrowserWindow({
    width: 480, height: 720,
    title: '네이버 로그인 — 완료 후 창을 닫아주세요',
    webPreferences: {
      partition: 'persist:naver',
      contextIsolation: true
    }
  });
  win.loadURL('https://nid.naver.com/nidlogin.login');
  win.webContents.on('did-navigate', (_, url) => {
    if (!url.includes('nidlogin')) {
      win.setTitle('✅ 로그인 완료! 창을 닫아주세요.');
    }
  });
}

// ── 폴링 (60초마다 새 포스트 확인) ───────────────────────────
function startPolling() {
  if (pollTimer) clearInterval(pollTimer);
  checkNewPosts();
  pollTimer = setInterval(checkNewPosts, 60_000);
}

async function checkNewPosts() {
  const cfg = store.get('config');
  if (!cfg?.apiBase || !cfg?.memberId) return;

  try {
    const res = await fetch(
      `${cfg.apiBase}/api/posts?status=ready&member_pk=${cfg.memberId}`,
      { headers: { Authorization: `Bearer ${cfg.apiToken || ''}` } }
    );
    if (!res.ok) return;

    const data = await res.json();
    const posts = Array.isArray(data) ? data : (data.posts || []);

    if (posts.length > 0 && !isPublishing) {
      // 대기 중인 포스트 순차 발행
      for (const post of posts) {
        if (isPublishing) break;
        await publishPost(post);
      }
    }
  } catch (e) {
    console.error('[poll]', e.message);
  }
}

// ── 발행 메인 ─────────────────────────────────────────────────
async function publishPost(post) {
  isPublishing = true;
  setTrayStatus(`발행 중: ${post.title.substring(0, 20)}...`);
  console.log('[publish] 시작:', post.title);

  const win = new BrowserWindow({
    width: 1280, height: 900,
    show: false,                         // 기본 숨김 — 로그인 필요시만 표시
    webPreferences: {
      partition: 'persist:naver',        // 저장된 네이버 세션 재사용
      contextIsolation: false,
      nodeIntegration: false,
      webSecurity: false                 // iframe 접근 허용
    }
  });

  try {
    await win.loadURL('https://blog.naver.com/GoBlogWrite.naver');
    const curUrl = win.webContents.getURL();

    // 로그인 안 된 경우 → 창 표시하고 사용자 로그인 대기
    if (curUrl.includes('nidlogin') || curUrl.includes('login.naver')) {
      win.show();
      showNotif('Caify', '네이버 로그인이 필요합니다. 창에서 로그인해 주세요.');

      await new Promise((resolve, reject) => {
        const t = setTimeout(() => reject(new Error('로그인 타임아웃 (2분)')), 120_000);
        win.webContents.on('did-navigate', (_, url) => {
          if (!url.includes('nidlogin') && !url.includes('login.naver')) {
            clearTimeout(t);
            win.hide();
            resolve();
          }
        });
      });

      await win.loadURL('https://blog.naver.com/GoBlogWrite.naver');
    }

    await injectAndPublish(win, post);

    const cfg = store.get('config');
    await fetch(`${cfg.apiBase}/api/posts/${post.id}/published`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${cfg.apiToken || ''}`,
        'Content-Type': 'application/json'
      }
    }).catch(() => {});

    showNotif('Caify ✅', `발행 완료: ${post.title}`);
    console.log('[publish] 완료:', post.title);

  } catch (e) {
    console.error('[publish] 실패:', e.message);
    showNotif('Caify ❌ 발행 실패', e.message.substring(0, 80));

    const cfg = store.get('config');
    await fetch(`${cfg.apiBase}/api/posts/${post.id}/failed`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${cfg.apiToken || ''}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ reason: e.message })
    }).catch(() => {});

  } finally {
    isPublishing = false;
    setTrayStatus('대기 중');
    win.destroy();
  }
}

// ── JS 헬퍼: iframe[name='mainFrame'] 기반 에디터 자동화 ──────

async function jsExec(win, code) {
  return win.webContents.executeJavaScript(code);
}

async function waitForEditor(win) {
  for (let i = 0; i < 60; i++) {
    await sleep(1000);
    try {
      const state = await jsExec(win, `
        (function() {
          const iframe = document.querySelector("iframe[name='mainFrame']");
          if (!iframe || !iframe.contentDocument) return 'no_iframe';
          const doc = iframe.contentDocument;
          const titleEl = doc.querySelector('.se-title-text') ||
                          doc.querySelector('.se-section-documentTitle');
          const bodyEl  = doc.querySelector('.se-component.se-text') ||
                          doc.querySelector('.se-section-text');
          return (titleEl && bodyEl) ? 'ready' : 'not_ready';
        })()`);
      if (state === 'ready') return;
    } catch {}
  }
  throw new Error('에디터 로드 타임아웃 (60초)');
}

async function injectTitle(win, title) {
  const result = await jsExec(win, `
    (function() {
      const iframe = document.querySelector("iframe[name='mainFrame']");
      if (!iframe || !iframe.contentDocument) return 'no_iframe';
      const doc = iframe.contentDocument;
      const el = doc.querySelector('.se-title-text .se-text-paragraph') ||
                 doc.querySelector('.se-section-documentTitle .se-text-paragraph') ||
                 doc.querySelector('.se-title-text');
      if (!el) return 'no_title_el';
      el.focus();
      el.textContent = ${JSON.stringify(title)};
      el.dispatchEvent(new Event('input',  { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      return 'ok';
    })()`);
  if (result !== 'ok') throw new Error('제목 입력 실패: ' + result);
}

async function injectBody(win, html) {
  const result = await jsExec(win, `
    (function() {
      const iframe = document.querySelector("iframe[name='mainFrame']");
      if (!iframe || !iframe.contentDocument) return 'no_iframe';
      const doc = iframe.contentDocument;
      const el = doc.querySelector('.se-component.se-text .__se-node') ||
                 doc.querySelector('.se-section-text .__se-node') ||
                 doc.querySelector('.se-component.se-text .se-text-paragraph');
      if (!el) return 'no_body_el';
      el.focus();
      const dt = new DataTransfer();
      dt.setData('text/html', ${JSON.stringify(html)});
      dt.setData('text/plain', '');
      el.dispatchEvent(new ClipboardEvent('paste', {
        clipboardData: dt, bubbles: true, cancelable: true
      }));
      return 'ok';
    })()`);
  if (result !== 'ok') throw new Error('본문 입력 실패: ' + result);
}

async function clickPublish(win) {
  // 1단계: 발행 버튼 클릭 (에디터 상단 우측)
  const step1 = await jsExec(win, `
    (function() {
      const iframe = document.querySelector("iframe[name='mainFrame']");
      if (!iframe || !iframe.contentDocument) return 'no_iframe';
      const doc = iframe.contentDocument;
      const btn =
        doc.querySelector('button.publish_btn__m9KHr') ||
        doc.querySelector('button[data-click-area="tpb.publish"]') ||
        doc.querySelector('button[class*="publish_btn"]') ||
        Array.from(doc.querySelectorAll('button')).find(b =>
          (b.innerText || '').trim() === '발행'
        );
      if (!btn) {
        const all = Array.from(doc.querySelectorAll('button'))
          .map(b => (b.innerText||'').trim().substring(0,15) + ':' + b.className.substring(0,30))
          .join(' | ');
        return 'no_publish_btn | ' + all;
      }
      btn.click();
      return 'ok';
    })()`);

  console.log('[publish_btn]', step1);
  if (!step1.startsWith('ok')) throw new Error('발행 버튼 없음: ' + step1);

  // 2단계: 발행 확인 패널이 뜨면 확인 버튼 클릭 (최대 5초 대기)
  for (let i = 0; i < 5; i++) {
    await sleep(1000);
    const step2 = await jsExec(win, `
      (function() {
        const iframe = document.querySelector("iframe[name='mainFrame']");
        if (!iframe || !iframe.contentDocument) return 'no_iframe';
        const doc = iframe.contentDocument;
        // 발행 패널 안의 확인/발행 버튼
        const confirmBtn =
          doc.querySelector('.se-publish-layer button[class*="confirm"]') ||
          doc.querySelector('.publisharea button[class*="publish"]') ||
          doc.querySelector('[class*="LayerPublish"] button[class*="confirm"]') ||
          doc.querySelector('[class*="publish_layer"] button[class*="ok"]') ||
          // 버튼 텍스트로 찾기
          Array.from(doc.querySelectorAll('button')).find(b => {
            const t = (b.innerText || '').trim();
            const cls = b.className || '';
            return (t === '발행' || t === '확인') &&
                   (cls.includes('confirm') || cls.includes('ok') || cls.includes('submit') || cls.includes('publish'));
          });
        if (confirmBtn) { confirmBtn.click(); return 'ok'; }
        return 'not_found';
      })()`);

    if (step2 === 'ok') {
      console.log('[confirm_btn] ok');
      break;
    }
    // 패널 없이 바로 발행되는 경우도 있음 — 그냥 진행
  }

  // 발행 완료 대기
  await sleep(3000);
}

// ── 전체 발행 파이프라인 ──────────────────────────────────────
async function injectAndPublish(win, post) {
  await waitForEditor(win);
  await sleep(500);

  await injectTitle(win, post.title);
  await sleep(500);

  await injectBody(win, post.html || post.naver_html || '');
  await sleep(2000);  // SE3 HTML 파싱 대기

  await clickPublish(win);
}

// ── 알림 ─────────────────────────────────────────────────────
function showNotif(title, body) {
  if (Notification.isSupported()) new Notification({ title, body }).show();
}

// ── IPC ──────────────────────────────────────────────────────
ipcMain.handle('get-config', () => store.get('config') || {});

ipcMain.handle('set-config', (_, cfg) => {
  store.set('config', cfg);
  startPolling();
  return { ok: true };
});

ipcMain.handle('test-connection', async (_, cfg) => {
  try {
    const res = await fetch(
      `${cfg.apiBase}/api/posts?status=ready&member_pk=${cfg.memberId}`,
      { headers: { Authorization: `Bearer ${cfg.apiToken || ''}` } }
    );
    const text = await res.text();
    return { ok: res.ok, status: res.status, body: text.substring(0, 200) };
  } catch (err) {
    return { ok: false, error: err.message };
  }
});
