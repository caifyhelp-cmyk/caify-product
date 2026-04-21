/// Naver Smart Editor 3 자동화 JS 스니펫 모음
/// flutter_inappwebview의 evaluateJavascript()로 실행
library naver_publisher;

class NaverPublisher {

  // ── 공통 doc 탐색 (인라인 IIFE) ──────────────────────────────
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

  // ── 페이지 진단 ───────────────────────────────────────────────
  static String jsDiagnose() => r'''
    (function() {
      const iframes = Array.from(document.querySelectorAll('iframe'));
      const iframeInfo = iframes.map(f => {
        let docInfo = 'no_access';
        try {
          const d = f.contentDocument;
          if (d) {
            const title = d.querySelector('.se-title-text,.se-section-documentTitle');
            const body  = d.querySelector('.se-component.se-text,.se-section-text');
            docInfo = 'title=' + !!title + ',body=' + !!body;
          }
        } catch(e) { docInfo = 'cors'; }
        return (f.name||f.id||'?') + '[' + docInfo + ']';
      }).join('|');

      const mainTitle = !!document.querySelector('.se-title-text,.se-section-documentTitle');
      const mainBody  = !!document.querySelector('.se-component.se-text,.se-section-text');
      const ces = Array.from(document.querySelectorAll('[contenteditable="true"]')).length;
      const titleInput = !!document.querySelector('input[placeholder*="제목"]');

      return 'main[title=' + mainTitle + ',body=' + mainBody + '] iframes=[' + iframeInfo + '] ces=' + ces + ' mobileTitle=' + titleInput + ' url=' + location.href.substring(0,80);
    })()
  ''';

  // ── 에디터 준비 확인 ──────────────────────────────────────────
  /// 'ready_se3' | 'ready_mobile' | 'ready_ce:N' | 'not_ready:...'
  static String jsIsEditorReady() => r'''
    (function() {
      // 1. SE3 데스크톱: .se-title-text + .se-component.se-text 둘 다
      const findDoc = function() {
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
      };
      const doc = findDoc();
      if (doc) {
        const titleEl = doc.querySelector('.se-title-text,.se-section-documentTitle');
        const bodyEl  = doc.querySelector('.se-component.se-text,.se-section-text,.se-component[class*="text"]');
        if (titleEl && bodyEl) return 'ready_se3';
      }

      // 2. 모바일 input 기반
      const mobileTitle = document.querySelector('input[placeholder*="제목"]') ||
                          document.querySelector('textarea[placeholder*="제목"]');
      if (mobileTitle) return 'ready_mobile';

      // 3. 폴백: DIV/P contenteditable 2개 이상 = SE3 에디터 로드됨
      const ces = Array.from(document.querySelectorAll('[contenteditable="true"]'))
        .filter(el => el.tagName === 'DIV' || el.tagName === 'P');
      if (ces.length >= 2) return 'ready_ce:' + ces.length;

      return 'not_ready:se3=' + !!doc + ',ces=' + ces.length + ',url=' + location.pathname;
    })()
  ''';

  // ── 제목 입력 ─────────────────────────────────────────────────
  static String jsInjectTitle(String title) {
    final escaped = _escapeJs(title);
    return '''
      (function() {
        // 방법 A: 모바일 — input/textarea
        const inputEl =
          document.querySelector('input[placeholder*="제목"]') ||
          document.querySelector('textarea[placeholder*="제목"]') ||
          document.querySelector('input[id*="title"]');
        if (inputEl) {
          inputEl.focus();
          const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(inputEl), 'value')?.set;
          if (setter) setter.call(inputEl, "$escaped");
          else inputEl.value = "$escaped";
          inputEl.dispatchEvent(new Event('input',  { bubbles: true }));
          inputEl.dispatchEvent(new Event('change', { bubbles: true }));
          return 'ok_input';
        }

        // 방법 B: iframe script 주입 — iframe 자체 컨텍스트에서 paste로 SE3 내부 모델 갱신
        const iframe = document.querySelector("iframe[name='mainFrame']") ||
                       document.querySelector("iframe#mainFrame") ||
                       document.querySelector("iframe");
        const inMain = !!document.querySelector('.se-title-text,.se-section-documentTitle');
        const iDoc = inMain ? document
                   : (iframe ? (function(){try{return iframe.contentDocument;}catch(e){return null;}}()) : null);
        if (!iDoc) return 'no_doc';

        // 데이터를 document 프로퍼티로 전달 (스크립트 내 문자열 이스케이프 불필요)
        iDoc.__caifyTitle = "$escaped";
        iDoc.__caifyResult = null;
        const s = iDoc.createElement('script');
        s.textContent = '(function(){'
          + 'try{'
          + 'var t=document.__caifyTitle;'
          // 실제 contenteditable 요소 탐색 (자식 p 태그 아닌 편집 가능한 컨테이너)
          + 'var cont=document.querySelector(".se-title-text")||document.querySelector(".se-section-documentTitle");'
          + 'if(!cont){document.__caifyResult="no_cont";return;}'
          + 'var el=cont.isContentEditable?cont:cont.querySelector("[contenteditable]");'
          + 'if(!el){document.__caifyResult="no_ce";return;}'
          + 'el.click();el.focus();'
          // selectAll + insertText — Chrome 확장과 동일한 방식
          + 'document.execCommand("selectAll",false,null);'
          + 'if(document.execCommand("insertText",false,t)){'
          + '  document.__caifyResult="ok_execText";'
          + '}else if(document.execCommand("insertHTML",false,"<span>"+t+"</span>")){'
          + '  document.__caifyResult="ok_execHTML";'
          + '}else{'
          + '  el.innerText=t;'
          + '  el.dispatchEvent(new InputEvent("input",{bubbles:true,inputType:"insertText",data:t}));'
          + '  document.__caifyResult="ok_innerText";'
          + '}'
          // 제목 삽입 후 반드시 blur — 남은 selection이 본문 paste를 제목에 보내는 것 방지
          + 'el.blur();window.getSelection().removeAllRanges();'
          + '}catch(e){document.__caifyResult="err:"+e.message;}'
          + '})();';
        (iDoc.head || iDoc.body || iDoc.documentElement).appendChild(s);
        s.remove();
        return iDoc.__caifyResult || 'null_result';
      })()
    ''';
  }

  // ── 본문 HTML 주입 ────────────────────────────────────────────
  // SE3 paste 이벤트 방식: 이미지가 네이버 라이브러리에 자동 업로드됨
  static String jsInjectHtml(String html) {
    final escaped = _escapeJs(html);
    return '''
      (function() {
        const doc = $_docFinder;
        if (!doc) return 'no_doc';

        // 제목 영역 blur — 이전 단계에서 남은 커서 제거
        const titleArea =
          doc.querySelector('.se-title-text') ||
          doc.querySelector('.se-section-documentTitle') ||
          doc.querySelector('.se-module-documentTitle');
        if (titleArea) {
          const tce = titleArea.querySelector('[contenteditable="true"]');
          if (tce && tce.blur) tce.blur();
          const win2 = doc.defaultView || window;
          win2.getSelection()?.removeAllRanges();
        }

        // 본문 요소 탐색 (제목 영역 제외 보장)
        const candidates = [
          doc.querySelector('.se-component.se-text [contenteditable="true"]'),
          doc.querySelector('.se-section-text [contenteditable="true"]'),
          doc.querySelector('.se-component.se-text .se-text-paragraph'),
          doc.querySelector('[data-placeholder*="글감"][contenteditable]'),
          doc.querySelector('[data-placeholder*="내용"][contenteditable]'),
        ];
        const el = candidates.find(c => {
          if (!c) return false;
          // 제목 영역 안에 있으면 제외
          return !c.closest('.se-title-text, .se-section-documentTitle, .se-module-documentTitle');
        });
        if (!el) return 'no_body_el';

        // 커서를 본문으로 명시적 이동
        el.click();
        el.focus();
        const win = doc.defaultView || window;
        const sel = win.getSelection();
        const range = doc.createRange();
        range.selectNodeContents(el);
        sel.removeAllRanges();
        sel.addRange(range);

        // ClipboardEvent paste (이미지 → 네이버 라이브러리 업로드)
        try {
          const dt = new DataTransfer();
          dt.setData('text/html', "$escaped");
          dt.setData('text/plain', '');
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

  // ── 본문 주입 fallback ────────────────────────────────────────
  static String jsInjectHtmlFallback(String html) {
    final escaped = _escapeJs(html);
    return '''
      (function() {
        const doc = $_docFinder;
        if (!doc) return 'no_doc';

        const candidates = [
          doc.querySelector('.se-component.se-text [contenteditable="true"]'),
          doc.querySelector('.se-section-text [contenteditable="true"]'),
          doc.querySelector('.se-component.se-text .se-text-paragraph'),
          doc.querySelector('[data-placeholder*="글감"][contenteditable]'),
          doc.querySelector('[data-placeholder*="내용"][contenteditable]'),
        ];
        const el = candidates.find(c => {
          if (!c) return false;
          return !c.closest('.se-title-text, .se-section-documentTitle, .se-module-documentTitle');
        });
        if (!el) return 'no_body_el';

        // placeholder 텍스트는 실제 내용이 아님 — SE3는 placeholder를 DOM에 직접 렌더링
        const placeholder = (el.getAttribute('data-placeholder') || '').trim();
        const txt = (el.innerText || el.textContent || '').trim();
        const isPlaceholder = txt === placeholder || txt === '글감과 함께 나의 일상을 기록해보세요!' || txt === '내용을 입력해주세요.';
        if (txt.length > 5 && !isPlaceholder) return 'already_filled:' + txt.substring(0, 30);

        // iframe script 주입으로 execCommand('insertHTML') 실행 — SE3 내부 모델 갱신
        const iframe2 = document.querySelector("iframe[name='mainFrame']") ||
                        document.querySelector("iframe#mainFrame") ||
                        document.querySelector("iframe");
        const inMain2 = !!document.querySelector('.se-title-text,.se-section-documentTitle');
        const iDoc2 = inMain2 ? document
                    : (iframe2 ? (function(){try{return iframe2.contentDocument;}catch(e){return null;}}()) : null);
        if (iDoc2) {
          iDoc2.__caifyHtml = "$escaped";
          iDoc2.__caifyResult2 = null;
          const s2 = iDoc2.createElement('script');
          s2.textContent = '(function(){'
            + 'try{'
            + 'var h=document.__caifyHtml;'
            // 실제 contenteditable 컨테이너 탐색 (자식 p 아닌 편집 가능한 부모)
            + 'var cont=document.querySelector(".se-component.se-text")'
            + '||document.querySelector(".se-section-text");'
            + 'if(!cont){document.__caifyResult2="no_cont";return;}'
            + 'var el=cont.querySelector("[contenteditable]");'
            + 'if(!el){document.__caifyResult2="no_ce";return;}'
            + 'el.click();el.focus();'
            + 'document.execCommand("selectAll",false,null);'
            + 'if(document.execCommand("insertHTML",false,h)){'
            + '  document.__caifyResult2="execHTML_ok";return;'
            + '}'
            // paste 이벤트 시도 (이미지 라이브러리 업로드용)
            + 'try{'
            + '  var dt=new DataTransfer();dt.setData("text/html",h);dt.setData("text/plain","");'
            + '  el.dispatchEvent(new ClipboardEvent("paste",{clipboardData:dt,bubbles:true,cancelable:true}));'
            + '  document.__caifyResult2="paste_ok";return;'
            + '}catch(pe){}'
            + 'el.innerHTML=h;'
            + 'el.dispatchEvent(new InputEvent("input",{bubbles:true,inputType:"insertFromPaste"}));'
            + 'document.__caifyResult2="innerHTML_ok";'
            + '}catch(e){document.__caifyResult2="err:"+e.message;}'
            + '})();';
          (iDoc2.head || iDoc2.body || iDoc2.documentElement).appendChild(s2);
          s2.remove();
          return iDoc2.__caifyResult2 || 'null_result2';
        }

        // iDoc2 없을 경우 최후 수단
        el.innerHTML = "$escaped";
        el.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertFromPaste' }));
        return 'innerHTML_ok';
      })()
    ''';
  }

  // ── 태그 입력 ─────────────────────────────────────────────────
  static String jsAddTags(List<String> tags) {
    if (tags.isEmpty) return '"ok_empty"';
    final tagsJson = tags.map((t) => '"${_escapeJs(t)}"').join(',');
    return '''
      (function() {
        const doc = $_docFinder;
        if (!doc) return 'no_doc';
        const tagInput =
          doc.querySelector('input.se-tag-input') ||
          doc.querySelector('input[placeholder*="태그"]') ||
          doc.querySelector('[class*="tag"] input') ||
          doc.querySelector('.se-tag-field input') ||
          doc.querySelector('input[type="text"][class*="tag"]');
        if (!tagInput) return 'no_tag_input';

        tagInput.focus();
        for (const tag of [$tagsJson]) {
          // React 호환 value setter
          const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(tagInput), 'value')?.set;
          if (setter) setter.call(tagInput, tag);
          else tagInput.value = tag;
          tagInput.dispatchEvent(new Event('input', { bubbles: true }));
          tagInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, bubbles: true }));
          tagInput.dispatchEvent(new KeyboardEvent('keyup',   { key: 'Enter', keyCode: 13, bubbles: true }));
          tagInput.value = '';
        }
        return 'ok';
      })()
    ''';
  }

  // ── 발행 버튼 클릭 ────────────────────────────────────────────
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
        const all = Array.from(document.querySelectorAll('button'))
          .map(b=>(b.innerText||'').trim().substring(0,10)).filter(t=>t).join(',');
        return 'no_btn|' + all;
      }
      btn.click();
      return 'ok';
    })()
  ''';

  // ── 사이드 패널 닫기 + 스크롤 ────────────────────────────────
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

  // ── 임시저장 버튼 ─────────────────────────────────────────────
  static String jsClickTempSave() => r'''
    (function() {
      const doc = (function() {
        if (document.querySelector('.se-title-text,.se-section-documentTitle')) return document;
        const frames = [document.querySelector("iframe[name='mainFrame']"),document.querySelector("iframe#mainFrame"),document.querySelector("iframe")];
        for (const f of frames) { if (!f) continue; let d; try{d=f.contentDocument;}catch(e){continue;} if(d&&d.querySelector('.se-title-text,.se-section-documentTitle'))return d; }
        return null;
      })();
      if (!doc) return 'no_doc';
      const btn =
        doc.querySelector('button[data-click-area="tpb.tempsave"]') ||
        doc.querySelector('button[class*="temp_save"]') ||
        doc.querySelector('button[class*="tempSave"]') ||
        Array.from(doc.querySelectorAll('button')).find(b =>
          (b.innerText||'').trim() === '임시저장'
        ) ||
        Array.from(doc.querySelectorAll('button')).find(b =>
          (b.innerText||'').trim() === '저장'
        );
      if (!btn) {
        const all = Array.from(doc.querySelectorAll('button'))
          .map(b=>(b.innerText||'').trim().substring(0,15)).filter(t=>t).join(',');
        return 'no_save_btn|' + all;
      }
      btn.click();
      return 'ok';
    })()
  ''';

  // ── URL 판단 ──────────────────────────────────────────────────
  static bool isLoginUrl(String url) =>
      url.contains('nidlogin') || url.contains('login.naver');

  static bool isEditorUrl(String url) =>
      url.contains('PostWrite') ||
      url.contains('GoBlogWrite') ||
      url.contains('AuthorPostEditor') ||
      url.contains('PostWriteForm') ||
      url.contains('GoPost');

  // ── JS 이스케이프 ─────────────────────────────────────────────
  static String _escapeJs(String s) => s
      .replaceAll(r'\', r'\\')
      .replaceAll('"', r'\"')
      .replaceAll('\n', r'\n')
      .replaceAll('\r', '');
}
