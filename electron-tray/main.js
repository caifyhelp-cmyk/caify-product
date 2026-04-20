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

app.on('window-all-closed', e => e.preventDefault()); // 창 닫아도 트레이 유지

// ── 트레이 ────────────────────────────────────────────────────
function createTray() {
  // 1×1 PNG fallback → 16×16으로 확대 (실제 배포시 icon.ico 사용)
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
    { label: '네이버 로그인 설정', click: openNaverLogin },
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

// ── 네이버 로그인 (최초 1회) ──────────────────────────────────
function openNaverLogin() {
  const win = new BrowserWindow({
    width: 480, height: 720,
    title: '네이버 로그인 — 완료 후 창을 닫아주세요',
    webPreferences: {
      partition: 'persist:naver',  // 쿠키 영구 보존
      contextIsolation: true
    }
  });
  win.loadURL('https://nid.naver.com/nidlogin.login');
  win.webContents.on('did-navigate', (e, url) => {
    if (!url.includes('nidlogin')) {
      win.setTitle('로그인 완료! 창을 닫아주세요.');
    }
  });
}

// ── 폴링 ─────────────────────────────────────────────────────
function startPolling() {
  if (pollTimer) clearInterval(pollTimer);
  checkNewPosts();
  pollTimer = setInterval(checkNewPosts, 60_000); // 60초마다
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
      publishPost(posts[0]);
    }
  } catch (e) {
    console.error('[poll]', e.message);
  }
}

// ── 발행 ─────────────────────────────────────────────────────
async function publishPost(post) {
  isPublishing = true;
  setTrayStatus('발행 중...');
  console.log('[publish] 시작:', post.title);

  const win = new BrowserWindow({
    width: 1280, height: 900,
    show: false,
    webPreferences: {
      partition: 'persist:naver',   // 로그인 세션 재사용
      contextIsolation: false,
      nodeIntegration: false
    }
  });

  try {
    await win.loadURL('https://blog.naver.com/GoBlogWrite.naver');
    const curUrl = win.webContents.getURL();

    // 로그인 필요시 사용자에게 안내
    if (curUrl.includes('nidlogin') || curUrl.includes('login.naver')) {
      win.show();
      showNotif('Caify', '네이버 로그인이 필요합니다. 브라우저에서 로그인해 주세요.');

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

    // 에디터 iframe에 내용 주입 후 저장
    await injectAndSave(win, post);

    // API에 발행 완료 통보
    const cfg = store.get('config');
    await fetch(`${cfg.apiBase}/api/posts/${post.id}/published`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${cfg.apiToken || ''}`, 'Content-Type': 'application/json' }
    }).catch(() => {});

    showNotif('Caify', `발행 완료: ${post.title}`);
    console.log('[publish] 완료:', post.title);

  } catch (e) {
    console.error('[publish] 실패:', e.message);
    showNotif('Caify — 발행 실패', e.message.substring(0, 80));
  } finally {
    isPublishing = false;
    setTrayStatus('대기 중');
    win.destroy();
  }
}

// ── 에디터 iframe 주입 + 저장 ─────────────────────────────────
// 이벤트 방식 대신 폴링 방식 사용:
// loadURL 완료 후 이벤트를 놓칠 수 있으므로, 1초마다 프레임 목록을 체크
async function injectAndSave(win, post) {
  // 에디터 iframe 대기 (최대 60초)
  let frame = null;
  for (let i = 0; i < 60; i++) {
    await sleep(1000);
    try {
      const frames = win.webContents.mainFrame.framesInSubtree;
      frame = frames.find(f => f.url && f.url.includes('PostWriteForm'));
      if (frame) break;
    } catch { /* 페이지 전환 중 오류 무시 */ }
  }
  if (!frame) throw new Error('에디터 frame 로드 타임아웃 (60초)');

  // Smart Editor 3 JS 초기화 대기
  await sleep(2000);

  // 에디터 준비 확인 (최대 10초)
  let editorReady = false;
  for (let i = 0; i < 10; i++) {
    const check = await frame.executeJavaScript(`
      (function() {
        const t = document.querySelector('.se-title-text') || document.querySelector('.se-section-documentTitle');
        const b = document.querySelector('.se-component.se-text') || document.querySelector('.se-section-text');
        return !!(t && b);
      })()
    `).catch(() => false);
    if (check) { editorReady = true; break; }
    await sleep(1000);
  }
  if (!editorReady) throw new Error('Smart Editor 3 초기화 타임아웃');

  try {
        await sleep(500); // 안전 마진

        // ── 제목 입력 ──
        const titleOk = await frame.executeJavaScript(`
          (function() {
            const el =
              document.querySelector('.se-title-text .se-text-paragraph') ||
              document.querySelector('.se-section-documentTitle .se-text-paragraph') ||
              document.querySelector('.se-title-text');
            if (!el) return false;
            el.focus();
            el.textContent = ${JSON.stringify(post.title)};
            el.dispatchEvent(new Event('input', { bubbles: true }));
            return true;
          })()
        `);
        console.log('[inject] 제목:', titleOk);

        await sleep(500);

        // ── 본문 HTML 주입 (ClipboardEvent 방식) ──
        const bodyOk = await frame.executeJavaScript(`
          (function() {
            const el =
              document.querySelector('.se-component.se-text .__se-node') ||
              document.querySelector('.se-section-text .__se-node') ||
              document.querySelector('.se-component.se-text .se-text-paragraph');
            if (!el) return false;
            el.focus();
            const dt = new DataTransfer();
            dt.setData('text/html', ${JSON.stringify(post.html)});
            dt.setData('text/plain', '');
            el.dispatchEvent(new ClipboardEvent('paste', {
              clipboardData: dt, bubbles: true, cancelable: true
            }));
            return true;
          })()
        `);
        console.log('[inject] 본문:', bodyOk);

        await sleep(2000); // 에디터가 HTML 파싱하는 시간

        // ── 저장 버튼 클릭 ──
        const saveOk = await frame.executeJavaScript(`
          (function() {
            const btn =
              document.querySelector('button.save_btn__bzc5B') ||
              document.querySelector('button[data-click-area="tpb.save"]') ||
              document.querySelector('.save_btn_area__Qo0W7 button') ||
              Array.from(document.querySelectorAll('button')).find(b =>
                (b.innerText || '').trim() === '저장'
              );
            if (btn) { btn.click(); return true; }
            // 디버그: 모든 버튼 나열
            return Array.from(document.querySelectorAll('button'))
              .map(b => b.className + '|' + (b.innerText||'').trim().substring(0,20));
          })()
        `);
        console.log('[inject] 저장:', saveOk);

        await sleep(3000); // 저장 완료 대기

  } catch (e) {
    throw e;
  }
}

// ── 알림 ─────────────────────────────────────────────────────
function showNotif(title, body) {
  if (Notification.isSupported()) new Notification({ title, body }).show();
}

// ── IPC (설정 창 ↔ main) ──────────────────────────────────────
ipcMain.handle('get-config', () => store.get('config') || {});

ipcMain.handle('set-config', (e, cfg) => {
  store.set('config', cfg);
  startPolling();
  return { ok: true };
});

ipcMain.handle('test-connection', async (e, cfg) => {
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
