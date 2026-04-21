/// Naver Smart Editor 3 자동화 JS 스니펫 모음
/// flutter_inappwebview의 evaluateJavascript()로 실행
/// 데스크톱 SE3 (blog.naver.com) + iframe mainFrame 지원
library naver_publisher;

class NaverPublisher {

  // ── 공통: iframe 포함 SE3 doc+win 탐지 ─────────────────────────
  /// _doc / _win 변수를 설정하는 인라인 JS 블록
  static const String _fw = r'''
    let _doc = document, _win = window;
    for (const _f of [
      document.querySelector("iframe[name='mainFrame']"),
      document.querySelector("iframe#mainFrame"),
      document.querySelector("iframe"),
    ]) {
      if (!_f) continue;
      try {
        const _d = _f.contentDocument;
        if (_d && _d.querySelector('.se-title-text,.se-section-documentTitle')) {
          _doc = _d; _win = _f.contentWindow; break;
        }
      } catch(e) {}
    }
  ''';

  // ── 페이지 진단 (디버그용) ────────────────────────────────────
  static String jsDiagnose() => r'''
    (function() {
      let doc = document;
      for (const f of [document.querySelector("iframe[name='mainFrame']"),document.querySelector("iframe")]) {
        if (!f) continue;
        try { const d=f.contentDocument; if(d&&d.querySelector('.se-title-text,.se-section-documentTitle')){doc=d;break;} } catch(e){}
      }
      const titleEl  = doc.querySelector('.se-title-text,.se-section-documentTitle');
      const bodyEl   = doc.querySelector('.se-module-text,.se-component.se-text,.se-section-text');
      const titleTxt = titleEl ? (titleEl.innerText||'').substring(0,30) : 'NONE';
      const bodyTxt  = bodyEl  ? (bodyEl.innerText||'').substring(0,30)  : 'NONE';
      const ce       = Array.from(doc.querySelectorAll('[contenteditable="true"]')).length;
      return 'inDoc='+(doc!==document)
        +' title=['+titleTxt+'] body=['+bodyTxt+'] ce='+ce
        +' url='+location.href.substring(0,60);
    })()
  ''';

  // ── 에디터 준비 확인 ─────────────────────────────────────────
  static String jsIsEditorReady() => '''
    (function() {
      $_fw
      const titleEl = _doc.querySelector('.se-title-text,.se-section-documentTitle');
      const bodyEl  = _doc.querySelector('.se-module-text,.se-component.se-text,.se-section-text,[contenteditable="true"]');
      if (!titleEl) return 'no_doc:url='+location.pathname+',iframes='+document.querySelectorAll('iframe').length;
      if (titleEl && bodyEl) return 'ready';
      return 'not_ready:title='+!!titleEl+',body='+!!bodyEl;
    })()
  ''';

  // ── 제목 입력 ────────────────────────────────────────────────
  static String jsInjectTitle(String title) {
    final escaped = _escapeJs(title);
    return '''
      (function() {
        $_fw
        // SE3 제목 contenteditable 요소
        const el =
          _doc.querySelector('.se-title-text .se-text-paragraph') ||
          _doc.querySelector('.se-section-documentTitle .se-text-paragraph') ||
          _doc.querySelector('.se-title-text [contenteditable="true"]') ||
          _doc.querySelector('[contenteditable="true"].se-title-text') ||
          _doc.querySelector('.se-title-text');
        if (!el) {
          const allCe = Array.from(_doc.querySelectorAll('[contenteditable]')).map(e=>e.className.substring(0,25)).join('|');
          return 'no_title_el:ce=['+allCe+']';
        }

        // 포커스
        _win.focus();
        el.focus();

        // 전체 선택 후 교체
        try {
          const sel = _win.getSelection();
          sel.selectAllChildren(el);
          if (_doc.execCommand('insertText', false, "$escaped")) return 'ok_exec';
        } catch(e) {}

        // fallback: textContent 직접 세팅
        el.textContent = "$escaped";
        el.dispatchEvent(new InputEvent('input', {bubbles:true, inputType:'insertText', data:"$escaped"}));
        el.dispatchEvent(new Event('change', {bubbles:true}));
        return 'ok_direct';
      })()
    ''';
  }

  // ── 본문 HTML 주입 ────────────────────────────────────────────
  static String jsInjectHtml(String html) {
    final escaped = _escapeJs(html);
    return '''
      (function() {
        $_fw
        // SE3 본문 편집 영역
        const el =
          _doc.querySelector('.se-module-text') ||
          _doc.querySelector('.se-component.se-text .__se-node') ||
          _doc.querySelector('.se-component.se-text [contenteditable="true"]') ||
          _doc.querySelector('.se-section-text [contenteditable="true"]') ||
          _doc.querySelector('.se-section-text');
        if (!el) return 'no_body_el';

        _win.focus();
        el.focus();

        // Method 1: ClipboardEvent paste (SE3 이미지 업로드 포함 처리)
        try {
          const dt = new DataTransfer();
          dt.setData('text/html', "$escaped");
          dt.setData('text/plain', el.innerText || '');
          el.dispatchEvent(new ClipboardEvent('paste', {
            clipboardData: dt, bubbles: true, cancelable: true
          }));
          return 'paste_dispatched';
        } catch(e) {
          return 'paste_err:' + e.message;
        }
      })()
    ''';
  }

  // ── 본문 주입 결과 확인 + fallback ──────────────────────────
  static String jsInjectHtmlFallback(String html) {
    final escaped = _escapeJs(html);
    return '''
      (function() {
        $_fw
        const el =
          _doc.querySelector('.se-module-text') ||
          _doc.querySelector('.se-component.se-text .__se-node') ||
          _doc.querySelector('.se-component.se-text [contenteditable="true"]') ||
          _doc.querySelector('.se-section-text [contenteditable="true"]') ||
          _doc.querySelector('.se-section-text');
        if (!el) return 'no_body_el';

        // 이미 내용 있으면 skip
        const txt = (el.innerText || el.textContent || '').trim();
        if (txt.length > 5) return 'already_filled:' + txt.substring(0, 40);

        _win.focus();
        el.focus();

        // Method 2: selectAll + execCommand insertHTML
        try {
          const sel = _win.getSelection();
          sel.selectAllChildren(el);
          if (_doc.execCommand('insertHTML', false, "$escaped")) return 'execCommand_ok';
        } catch(e) {}

        // Method 3: innerHTML 직접 (최후 수단)
        el.innerHTML = "$escaped";
        el.dispatchEvent(new InputEvent('input', {bubbles:true}));
        el.dispatchEvent(new Event('change', {bubbles:true}));
        return 'innerHTML_ok';
      })()
    ''';
  }

  // ── 태그 입력 ────────────────────────────────────────────────
  static String jsAddTags(List<String> tags) {
    if (tags.isEmpty) return '"ok"';
    final tagsJson = tags.map((t) => '"${_escapeJs(t)}"').join(',');
    return '''
      (function() {
        $_fw
        const tagInput =
          _doc.querySelector('input.se-tag-input') ||
          _doc.querySelector('input[placeholder*="태그"]') ||
          _doc.querySelector('[class*="tag"] input') ||
          _doc.querySelector('.se-tag-field input');
        if (!tagInput) return 'no_tag_input';
        tagInput.focus();
        for (const tag of [$tagsJson]) {
          tagInput.value = tag;
          tagInput.dispatchEvent(new Event('input', {bubbles:true}));
          tagInput.dispatchEvent(new KeyboardEvent('keydown', {key:'Enter', keyCode:13, bubbles:true}));
          tagInput.dispatchEvent(new KeyboardEvent('keyup',   {key:'Enter', keyCode:13, bubbles:true}));
        }
        return 'ok';
      })()
    ''';
  }

  // ── 발행 버튼 클릭 ──────────────────────────────────────────
  static String jsClickPublish() => '''
    (function() {
      $_fw
      const searchDoc = _doc || document;
      const btn =
        searchDoc.querySelector('button[data-click-area*="publish"]') ||
        searchDoc.querySelector('button.se-publish-btn') ||
        searchDoc.querySelector('button[class*="publish"]') ||
        document.querySelector('button[data-click-area*="publish"]') ||
        document.querySelector('button[class*="publish"]') ||
        Array.from(document.querySelectorAll('button')).find(b =>
          (b.innerText||'').trim() === '발행' || (b.innerText||'').trim() === '발행하기'
        );
      if (!btn) {
        const all = Array.from(document.querySelectorAll('button'))
          .map(b=>(b.innerText||'').trim().substring(0,10)).filter(t=>t).join(',');
        return 'no_btn|' + all;
      }
      btn.click();
      return 'ok';
    })()
  ''';

  // ── 에디터 사이드 패널 닫기 + 스크롤 ────────────────────────
  static String jsCleanupView() => r'''
    (function() {
      const styleId = 'caify-hide-sidebar';
      if (!document.getElementById(styleId)) {
        const s = document.createElement('style');
        s.id = styleId;
        s.textContent = `
          [class*="feature_guide"],[class*="featureGuide"],
          [class*="whats_new"],[class*="whatsNew"],
          [class*="intro_panel"],[class*="introPanel"],
          [class*="guide_panel"],[class*="se-help"],
          [class*="help-panel"],[class*="floating_panel"] { display:none!important; }
        `;
        document.head.appendChild(s);
      }
      ['button[aria-label*="닫기"]','[class*="feature_guide"] button','[class*="guide_panel"] button']
        .forEach(sel => { const el=document.querySelector(sel); if(el) try{el.click();}catch(e){} });
      window.scrollTo(0, 0);
      return 'ok';
    })()
  ''';

  // ── 임시저장 버튼 클릭 ───────────────────────────────────────
  static String jsClickTempSave() => '''
    (function() {
      $_fw
      const btn =
        _doc.querySelector('button[data-click-area="tpb.tempsave"]') ||
        _doc.querySelector('button[class*="temp_save"]') ||
        _doc.querySelector('button[class*="tempSave"]') ||
        Array.from(_doc.querySelectorAll('button')).find(b =>
          (b.innerText||'').trim() === '임시저장'
        );
      if (!btn) {
        const all = Array.from(_doc.querySelectorAll('button'))
          .map(b=>(b.innerText||'').trim().substring(0,15)).filter(t=>t).join(',');
        return 'no_save_btn|' + all;
      }
      btn.click();
      return 'ok';
    })()
  ''';

  // ── URL 판단 ─────────────────────────────────────────────────
  static bool isLoginUrl(String url) =>
      url.contains('nidlogin') || url.contains('login.naver');

  static bool isEditorUrl(String url) =>
      url.contains('PostWrite') ||
      url.contains('GoBlogWrite') ||
      url.contains('AuthorPostEditor') ||
      url.contains('PostWriteForm') ||
      url.contains('GoPost');

  // ── JS 문자열 이스케이프 ──────────────────────────────────────
  static String _escapeJs(String s) => s
      .replaceAll(r'\', r'\\')
      .replaceAll('"', r'\"')
      .replaceAll('\n', r'\n')
      .replaceAll('\r', '');
}
