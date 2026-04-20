const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

// .env 파일 읽기
const envPath = path.join(__dirname, '.env');
const env = {};
fs.readFileSync(envPath, 'utf-8').split('\n').forEach(line => {
  const [key, ...val] = line.split('=');
  if (key && val.length) env[key.trim()] = val.join('=').trim();
});

const NAVER_ID = env.NAVER_ID;
const NAVER_PW = env.NAVER_PW;

const TEST_TITLE = 'CAIFY 자동발행 테스트 - 삭제예정';
const TEST_HTML = `
<p style="font-size:16px; font-family:'맑은 고딕'; text-align:center;">
  <strong>테스트 제목 문단입니다</strong>
</p>
<h2>소제목 테스트</h2>
<p>본문 내용입니다. Flutter WebView에서 HTML 주입이 가능한지 확인합니다.</p>
<table border="1" style="width:100%; border-collapse:collapse;">
  <tr><th>항목</th><th>내용</th></tr>
  <tr><td>테스트</td><td>성공 여부 확인</td></tr>
</table>
<p style="text-align:center; color:#666;">테스트 완료 후 삭제할 포스팅입니다.</p>
`;

async function run() {
  console.log('=== 네이버 에디터 JS 주입 테스트 시작 ===\n');

  const browser = await chromium.launch({ headless: false, slowMo: 300 });
  const page = await browser.newPage();
  page.setDefaultTimeout(120000);

  // 1. 네이버 로그인 (수동)
  console.log('1단계: 네이버 로그인 페이지 열기...');
  await page.goto('https://nid.naver.com/nidlogin.login');
  await page.waitForTimeout(1000);

  await page.goto('https://nid.naver.com/nidlogin.login');

  console.log('\n👉 브라우저에서 네이버 로그인을 직접 해주세요.');
  console.log('   로그인 완료되면 자동으로 다음 단계 진행됩니다...\n');

  // 로그인 완료 대기 (최대 120초)
  await page.waitForFunction(
    () => !window.location.href.includes('nidlogin'),
    { timeout: 120000 }
  );

  console.log('✅ 로그인 감지! 다음 단계 진행...\n');

  // 2. 블로그 글쓰기 이동
  console.log('2단계: 블로그 에디터 열기...');
  await page.goto('https://blog.naver.com/GoBlogWrite.naver');
  await page.waitForTimeout(3000);

  // 3. 에디터 구조 파악
  console.log('3단계: 에디터 구조 분석...');
  const editorInfo = await page.evaluate(() => {
    const iframes = Array.from(document.querySelectorAll('iframe'));
    const info = {
      iframes: iframes.map(f => ({ id: f.id, name: f.name, src: f.src?.substring(0, 80) })),
      globalEditorKeys: Object.keys(window).filter(k =>
        ['editor', 'naver', 'se2', 'smartEditor', 'SE'].some(kw =>
          k.toLowerCase().includes(kw.toLowerCase())
        )
      )
    };
    return info;
  });
  console.log('iframe 목록:', JSON.stringify(editorInfo.iframes, null, 2));
  console.log('전역 에디터 객체:', editorInfo.globalEditorKeys);

  // 4. 제목 입력
  console.log('\n4단계: 제목 입력 시도...');
  const titleResult = await page.evaluate((title) => {
    const titleInput = document.querySelector('.se-title-input') ||
                       document.querySelector('[placeholder*="제목"]') ||
                       document.querySelector('#title');
    if (!titleInput) return { ok: false, reason: '제목 입력창 못 찾음' };
    titleInput.focus();
    titleInput.textContent = title;
    titleInput.dispatchEvent(new Event('input', { bubbles: true }));
    return { ok: true, found: titleInput.tagName + ' ' + titleInput.className };
  }, TEST_TITLE);
  console.log('제목 입력:', titleResult);

  // 5. 본문 HTML 주입 - 방법 1: ClipboardEvent
  console.log('\n5단계: 본문 HTML 주입 시도 (방법 1: ClipboardEvent)...');
  const method1 = await page.evaluate((html) => {
    const editor = document.querySelector('.se-content') ||
                   document.querySelector('[contenteditable="true"]') ||
                   document.querySelector('.se-component-content');
    if (!editor) return { ok: false, reason: '에디터 본문 못 찾음' };

    editor.focus();
    const dt = new DataTransfer();
    dt.setData('text/html', html);
    dt.setData('text/plain', '테스트 본문');
    const pasteEvent = new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true });
    editor.dispatchEvent(pasteEvent);
    return { ok: true, editorFound: editor.tagName + '.' + editor.className.substring(0, 50) };
  }, TEST_HTML);
  console.log('방법 1 결과:', method1);
  await page.waitForTimeout(1500);

  // 6. 결과 확인
  console.log('\n6단계: 주입 결과 확인...');
  const bodyContent = await page.evaluate(() => {
    const editor = document.querySelector('.se-content') ||
                   document.querySelector('[contenteditable="true"]');
    return editor ? editor.innerHTML.substring(0, 200) : 'not found';
  });
  console.log('에디터 현재 내용 (앞 200자):', bodyContent);

  // 7. 방법 2: execCommand
  if (!bodyContent || bodyContent === 'not found' || bodyContent.length < 10) {
    console.log('\n7단계: 방법 2 시도 (execCommand)...');
    const method2 = await page.evaluate((html) => {
      const editor = document.querySelector('[contenteditable="true"]');
      if (!editor) return { ok: false };
      editor.focus();
      document.execCommand('insertHTML', false, html);
      return { ok: true };
    }, TEST_HTML);
    console.log('방법 2 결과:', method2);
    await page.waitForTimeout(1500);
  }

  // 8. iframe 내부에서 실제 주입
  console.log('\n8단계: iframe 내부 에디터 주입 시도...');
  const frames = page.frames();
  let editorFrame = null;

  for (const frame of frames) {
    const frameUrl = frame.url();
    if (frameUrl?.includes('PostWriteForm')) {
      editorFrame = frame;
      console.log('에디터 frame 발견:', frameUrl.substring(0, 80));
      break;
    }
  }

  if (!editorFrame) {
    console.log('❌ 에디터 frame 못 찾음');
    await browser.close();
    return;
  }

  // iframe 내부 에디터 구조 파악
  const frameStructure = await editorFrame.evaluate(() => {
    const editors = Array.from(document.querySelectorAll('[contenteditable="true"]'))
      .map(e => ({ tag: e.tagName, id: e.id, class: e.className.substring(0, 60), placeholder: e.getAttribute('placeholder') }));
    const titleInputs = Array.from(document.querySelectorAll('input[type="text"], .se-title-input, [placeholder*="제목"]'))
      .map(e => ({ tag: e.tagName, id: e.id, class: e.className.substring(0, 60) }));
    const globalKeys = Object.keys(window).filter(k =>
      ['SE', 'smartEditor', 'editor', 'nhn', 'tui'].some(kw => k.includes(kw))
    );
    return { editors, titleInputs, globalKeys };
  });
  console.log('에디터 요소들:', JSON.stringify(frameStructure.editors, null, 2));
  console.log('제목 입력창:', JSON.stringify(frameStructure.titleInputs, null, 2));
  console.log('에디터 전역 객체:', frameStructure.globalKeys);

  // 제목 입력 시도 (iframe 내부)
  console.log('\n9단계: iframe 내부 제목 입력...');
  const titleResult2 = await editorFrame.evaluate((title) => {
    const candidates = [
      document.querySelector('.se-title-input'),
      document.querySelector('[placeholder*="제목"]'),
      document.querySelector('.se-documentTitle [contenteditable]'),
      document.querySelectorAll('[contenteditable="true"]')[0]
    ].filter(Boolean);

    if (candidates.length === 0) return { ok: false, reason: '제목창 없음' };
    const el = candidates[0];
    el.focus();
    el.textContent = title;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return { ok: true, el: el.tagName + ' ' + el.className.substring(0, 40) };
  }, TEST_TITLE);
  console.log('제목 입력 결과:', titleResult2);
  await editorFrame.waitForTimeout(1000);

  // 본문 주입 시도 (iframe 내부)
  console.log('\n10단계: iframe 내부 본문 HTML 주입...');
  const bodyResult = await editorFrame.evaluate((html) => {
    // contenteditable 요소들 중 본문용 찾기
    const editors = Array.from(document.querySelectorAll('[contenteditable="true"]'));
    // 보통 두 번째 이후가 본문
    const bodyEditor = editors.find(e =>
      e.className.includes('se-content') ||
      e.className.includes('body') ||
      e.getAttribute('role') === 'textbox'
    ) || editors[editors.length - 1]; // 마지막 contenteditable

    if (!bodyEditor) return { ok: false, reason: '본문 에디터 없음' };

    bodyEditor.focus();

    // 방법 1: ClipboardEvent
    const dt = new DataTransfer();
    dt.setData('text/html', html);
    dt.setData('text/plain', '테스트 본문');
    bodyEditor.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));

    return {
      ok: true,
      el: bodyEditor.tagName + ' ' + bodyEditor.className.substring(0, 50),
      contentAfter: bodyEditor.innerHTML.substring(0, 100)
    };
  }, TEST_HTML);
  console.log('본문 주입 결과:', bodyResult);
  await editorFrame.waitForTimeout(2000);

  // 결과 스크린샷
  await page.screenshot({ path: 'editor_result.png', fullPage: false });
  console.log('\n스크린샷 저장: editor_result.png');

  // 최종 내용 확인
  const finalContent = await editorFrame.evaluate(() => {
    const editors = Array.from(document.querySelectorAll('[contenteditable="true"]'));
    return editors.map(e => ({
      class: e.className.substring(0, 50),
      content: e.innerHTML.substring(0, 150)
    }));
  });
  console.log('\n최종 에디터 내용:', JSON.stringify(finalContent, null, 2));

  console.log('\n=== 테스트 완료. 브라우저 30초 후 닫힙니다. ===');
  console.log('직접 확인해보세요: 에디터에 내용이 들어갔는지');
  await page.waitForTimeout(30000);
  await browser.close();
}

run().catch(e => {
  console.error('오류:', e.message);
  process.exit(1);
});
