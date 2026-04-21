/// Naver Smart Editor 3 자동화 JS 스니펫 모음
/// flutter_inappwebview의 evaluateJavascript()로 실행
/// 메인 도큐먼트 + mainFrame iframe 양쪽 자동 탐지
library naver_publisher;

class NaverPublisher {

  // ── 공통: 에디터 도큐먼트 탐지 헬퍼 ─────────────────────────────
  /// 메인 doc → mainFrame iframe → 첫 번째 iframe 순서로 탐색
  static const String _findDoc = r'''
    (function _findDoc() {
      // 1) 메인 도큐먼트에 SE 엘리먼트가 있으면 그대로 사용
      if (document.querySelector('.se-title-text, .se-section-documentTitle')) {
        return document;
      }
      // 2) mainFrame iframe
      const frames = [
        document.querySelector("iframe[name='mainFrame']"),
        document.querySelector("iframe#mainFrame"),
        document.querySelector("iframe"),
      ];
      for (const f of frames) {
        if (!f) continue;
        let d;
        try { d = f.contentDocument; } catch(e) { continue; }
        if (d && d.querySelector('.se-title-text, .se-section-documentTitle')) return d;
      }
      return null;
    })()
  ''';

  // ── 페이지 진단 (디버그용) ────────────────────────────────────
  /// 현재 페이지 구조를 문자열로 반환
  static String jsDiagnose() => r'''
    (function() {
      const iframes = Array.from(document.querySelectorAll('iframe'));
      const iframeInfo = iframes.map(f => {
        let docInfo = 'no_access';
        try {
          const d = f.contentDocument;
          if (d) {
            const title = d.querySelector('.se-title-text, .se-section-documentTitle');
            const body  = d.querySelector('.se-component.se-text, .se-section-text');
            docInfo = `title=${!!title},body=${!!body}`;
          }
        } catch(e) { docInfo = 'cors'; }
        return `${f.name||f.id||'?'}[${docInfo}]`;
      }).join('|');

      const mainTitle = !!document.querySelector('.se-title-text,.se-section-documentTitle');
      const mainBody  = !!document.querySelector('.se-component.se-text,.se-section-text');

      // 모바일 에디터 요소 탐색
      const titleInput = document.querySelector('input[placeholder*="제목"]') ||
                         document.querySelector('input[id*="title"]') ||
                         document.querySelector('textarea[placeholder*="제목"]');
      const allInputs = Array.from(document.querySelectorAll('input,textarea'))
        .map(el=>`${el.tagName}[${el.placeholder||el.id||el.className.substring(0,20)}]`)
        .slice(0,5).join(',');
      const bodyEditable = !!document.querySelector('[contenteditable="true"]');

      return `main[title=${mainTitle},body=${mainBody}] iframes=[${iframeInfo}] `+
             `mobileTitle=${!!titleInput} inputs=[${allInputs}] bodyEdit=${bodyEditable} `+
             `url=${location.href.substring(0,80)}`;
    })()
  ''';

  // ── 에디터 준비 확인 ─────────────────────────────────────────
  /// 반환값: 'ready' | 'no_doc:...' | 'not_ready:title=X,body=X'
  static String jsIsEditorReady() => '''
    (function() {
      ${_findDoc.replaceAll('(function _findDoc()', '(function()')}
      const doc = (function() {
        if (document.querySelector('.se-title-text, .se-section-documentTitle')) return document;
        const frames = [
          document.querySelector("iframe[name='mainFrame']"),
          document.querySelector("iframe#mainFrame"),
          document.querySelector("iframe"),
        ];
        for (const f of frames) {
          if (!f) continue;
          let d;
          try { d = f.contentDocument; } catch(e) { continue; }
          if (d && d.querySelector('.se-title-text, .se-section-documentTitle')) return d;
        }
        return null;
      })();

      // 모바일 SE3: input/contenteditable 기반
      const mobileTitle = document.querySelector('input[placeholder*="제목"]') ||
                          document.querySelector('textarea[placeholder*="제목"]');
      const mobileBody  = document.querySelector('[contenteditable="true"]') ||
                          document.querySelector('textarea:not([placeholder*="제목"])');
      if (mobileTitle && mobileBody) return 'ready';

      if (!doc) {
        const iframes = document.querySelectorAll('iframe');
        return 'no_doc:iframes=' + iframes.length + ',url=' + location.pathname;
      }
      const titleEl = doc.querySelector('.se-title-text') ||
                      doc.querySelector('.se-section-documentTitle');
      const bodyEl  = doc.querySelector('.se-component.se-text') ||
                      doc.querySelector('.se-section-text');
      if (titleEl && bodyEl) return 'ready';
      return 'not_ready:title=' + !!titleEl + ',body=' + !!bodyEl;
    })()
  ''';

  // ── 공통 doc 탐색 (인라인) ───────────────────────────────────
  static const String _docFinder = r'''
    (function() {
      if (document.querySelector('.se-title-text,.se-section-documentTitle')) return document;
      const frames = [
        document.querySelector("iframe[name='mainFrame']"),
        document.querySelector("iframe#mainFrame"),
        document.querySelector("iframe"),
      ];
      for (const f of frames) {
        if (!f) continue;
        let d; try { d = f.contentDocument; } catch(e) { continue; }
        if (d && d.querySelector('.se-title-text,.se-section-documentTitle')) return d;
      }
      return null;
    })()
  ''';

  // ── 제목 입력 ────────────────────────────────────────────────
  static String jsInjectTitle(String title) {
    final escaped = _escapeJs(title);
    return '''
      (function() {
        // 방법 A: 모바일 SE3 — input/textarea 기반 제목 필드
        const inputEl =
          document.querySelector('input[placeholder*="제목"]') ||
          document.querySelector('textarea[placeholder*="제목"]') ||
          document.querySelector('input[id*="title"]') ||
          document.querySelector('input[name*="title"]');
        if (inputEl) {
          inputEl.focus();
          // React 호환 네이티브 setter
          const setter = Object.getOwnPropertyDescriptor(
            Object.getPrototypeOf(inputEl), 'value'
          )?.set;
          if (setter) setter.call(inputEl, "$escaped");
          else inputEl.value = "$escaped";
          inputEl.dispatchEvent(new Event('input',  { bubbles: true }));
          inputEl.dispatchEvent(new Event('change', { bubbles: true }));
          return 'ok_input';
        }

        // 방법 B: 데스크톱/iframe SE3 — contenteditable
        const doc = $_docFinder;
        if (!doc) return 'no_doc';
        const el =
          doc.querySelector('.se-title-text .se-text-paragraph') ||
          doc.querySelector('.se-section-documentTitle .se-text-paragraph') ||
          doc.querySelector('.se-title-text [contenteditable]') ||
          doc.querySelector('.se-title-text');
        if (!el) return 'no_title_el';
        el.focus();
        const sel = (doc.defaultView || window).getSelection();
        const range = doc.createRange();
        range.selectNodeContents(el);
        sel.removeAllRanges();
        sel.addRange(range);
        const inserted = doc.execCommand('insertText', false, "$escaped");
        if (!inserted) {
          el.innerText = "$escaped";
          el.dispatchEvent(new InputEvent('input', { bubbles: true }));
        }
        el.dispatchEvent(new Event('change', { bubbles: true }));
        return 'ok_contenteditable';
      })()
    ''';
  }

  // ── 본문 HTML 주입 ────────────────────────────────────────────
  static String jsInjectHtml(String html) {
    final escaped = _escapeJs(html);
    return '''
      (function() {
        const doc = $_docFinder;
        if (!doc) return 'no_doc';
        const el =
          doc.querySelector('.se-component.se-text .__se-node') ||
          doc.querySelector('.se-section-text .__se-node') ||
          doc.querySelector('.se-component.se-text [contenteditable]') ||
          doc.querySelector('.se-component.se-text .se-text-paragraph') ||
          doc.querySelector('[contenteditable="true"].se-text-paragraph');
        if (!el) return 'no_body_el';
        el.focus();

        // 방법 1: ClipboardEvent paste
        try {
          const dt = new DataTransfer();
          dt.setData('text/html', "$escaped");
          dt.setData('text/plain', '');
          const pasteOk = el.dispatchEvent(new ClipboardEvent('paste', {
            clipboardData: dt, bubbles: true, cancelable: true
          }));
          // 100ms 후 내용 확인 — 비어있으면 방법 2 시도
          return 'paste_dispatched';
        } catch(e) {
          return 'paste_err:' + e.message;
        }
      })()
    ''';
  }

  // ── 본문 주입 결과 확인 + fallback execCommand ───────────────
  static String jsInjectHtmlFallback(String html) {
    final escaped = _escapeJs(html);
    return '''
      (function() {
        const doc = $_docFinder;
        if (!doc) return 'no_doc';
        const el =
          doc.querySelector('.se-component.se-text .__se-node') ||
          doc.querySelector('.se-section-text .__se-node') ||
          doc.querySelector('.se-component.se-text [contenteditable]') ||
          doc.querySelector('.se-component.se-text .se-text-paragraph');
        if (!el) return 'no_body_el';

        // 이미 내용이 있으면 skip
        const txt = (el.innerText || el.textContent || '').trim();
        if (txt.length > 5) return 'already_filled:' + txt.substring(0, 30);

        el.focus();
        // 방법 2: execCommand insertHTML
        const r2 = doc.execCommand('insertHTML', false, "$escaped");
        if (r2) return 'execCommand_ok';

        // 방법 3: innerHTML 직접 (최후 수단)
        el.innerHTML = "$escaped";
        el.dispatchEvent(new InputEvent('input', { bubbles: true }));
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
        const doc = (function() {
          if (document.querySelector('.se-title-text, .se-section-documentTitle')) return document;
          const frames = [document.querySelector("iframe[name='mainFrame']"),document.querySelector("iframe#mainFrame"),document.querySelector("iframe")];
          for (const f of frames) { if (!f) continue; let d; try{d=f.contentDocument;}catch(e){continue;} if(d&&d.querySelector('.se-title-text,.se-section-documentTitle'))return d; }
          return null;
        })();
        if (!doc) return 'no_doc';
        const tagInput =
          doc.querySelector('input.se-tag-input') ||
          doc.querySelector('input[placeholder*="태그"]') ||
          doc.querySelector('[class*="tag"] input') ||
          doc.querySelector('.se-tag-field input');
        if (!tagInput) return 'no_tag_input';
        const tagList = [$tagsJson];
        tagInput.focus();
        for (const tag of tagList) {
          tagInput.value = tag;
          tagInput.dispatchEvent(new Event('input', { bubbles: true }));
          tagInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, bubbles: true }));
          tagInput.dispatchEvent(new KeyboardEvent('keyup',  { key: 'Enter', keyCode: 13, bubbles: true }));
        }
        return 'ok';
      })()
    ''';
  }

  // ── 발행 버튼 클릭 ──────────────────────────────────────────
  static String jsClickPublish() => r'''
    (function() {
      const doc = (function() {
        if (document.querySelector('.se-title-text,.se-section-documentTitle')) return document;
        const frames = [document.querySelector("iframe[name='mainFrame']"),document.querySelector("iframe#mainFrame"),document.querySelector("iframe")];
        for (const f of frames) { if (!f) continue; let d; try{d=f.contentDocument;}catch(e){continue;} if(d&&d.querySelector('.se-title-text,.se-section-documentTitle'))return d; }
        return null;
      })();
      const searchDoc = doc || document;
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
        const allBtns = Array.from(document.querySelectorAll('button'))
          .map(b=>(b.innerText||'').trim().substring(0,10)).filter(t=>t).join(',');
        return 'no_publish_btn|' + allBtns;
      }
      btn.click();
      return 'ok';
    })()
  ''';

  // ── 에디터 사이드 패널 닫기 + 상단 스크롤 ───────────────────
  static String jsCleanupView() => r'''
    (function() {
      // ① 특정 클래스명이 명확한 도움말 패널만 숨김 (레이아웃 영향 최소화)
      const styleId = 'caify-hide-sidebar';
      if (!document.getElementById(styleId)) {
        const s = document.createElement('style');
        s.id = styleId;
        s.textContent = `
          [class*="feature_guide"],
          [class*="featureGuide"],
          [class*="whats_new"],
          [class*="whatsNew"],
          [class*="intro_panel"],
          [class*="introPanel"],
          [class*="guide_panel"],
          [class*="se-help"],
          [class*="help-panel"],
          [class*="floating_panel"] { display: none !important; }
        `;
        document.head.appendChild(s);
      }

      // ② 닫기 버튼 클릭 시도
      ['button[aria-label*="닫기"]','[class*="feature_guide"] button','[class*="guide_panel"] button']
        .forEach(sel => {
          const el = document.querySelector(sel);
          if (el) { try { el.click(); } catch(e) {} }
        });

      // ③ 상단으로 스크롤
      window.scrollTo(0, 0);
      return 'ok';
    })()
  ''';

  // ── 임시저장 버튼 클릭 ───────────────────────────────────────
  static String jsClickTempSave() => r'''
    (function() {
      const doc = (function() {
        if (document.querySelector('.se-title-text, .se-section-documentTitle')) return document;
        const frames = [document.querySelector("iframe[name='mainFrame']"),document.querySelector("iframe#mainFrame"),document.querySelector("iframe")];
        for (const f of frames) { if (!f) continue; let d; try{d=f.contentDocument;}catch(e){continue;} if(d&&d.querySelector('.se-title-text,.se-section-documentTitle'))return d; }
        return null;
      })();
      if (!doc) return 'no_doc';
      const btn =
        doc.querySelector('button[class*="temp_save"]') ||
        doc.querySelector('button[data-click-area="tpb.tempsave"]') ||
        doc.querySelector('button[class*="tempSave"]') ||
        Array.from(doc.querySelectorAll('button')).find(b =>
          (b.innerText || '').trim() === '임시저장'
        );
      if (!btn) {
        const all = Array.from(doc.querySelectorAll('button'))
          .map(b => (b.innerText||'').trim().substring(0,15))
          .filter(t => t)
          .join(',');
        return 'no_save_btn|' + all;
      }
      btn.click();
      return 'ok';
    })()
  ''';

  // ── 현재 URL이 로그인 페이지인지 확인 ────────────────────────
  static bool isLoginUrl(String url) =>
      url.contains('nidlogin') || url.contains('login.naver');

  // ── 현재 URL이 블로그 에디터 페이지인지 확인 ──────────────────
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
