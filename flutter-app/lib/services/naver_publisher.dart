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

  // ── SE3 이프레임 내부 API 진단 ───────────────────────────────────
  static String jsDebugSE3() => r'''
    (function() {
      const iframe = document.querySelector("iframe[name='mainFrame']") ||
                     document.querySelector("iframe#mainFrame") ||
                     document.querySelector("iframe");
      let iDoc; try { iDoc = iframe ? iframe.contentDocument : null; } catch(e) { return 'cors'; }
      if (!iDoc) return 'no_iframe_doc';

      const w = iframe.contentWindow;

      // SmartEditor 상세 구조 탐색
      const sm = w.SmartEditor;
      const smType = typeof sm;
      const smIsArr = Array.isArray(sm);
      const smLen = smIsArr ? sm.length : -1;
      const smKeys = sm && !smIsArr ? Object.keys(sm).slice(0,15).join(',') : '';
      // _editors 상세 탐색
      let editorsInfo = 'no_ed';
      if (sm && sm._editors) {
        const ed = sm._editors;
        const edIsArr = Array.isArray(ed);
        const edLen = edIsArr ? ed.length : -1;
        const edKeys = !edIsArr ? Object.keys(ed).slice(0,10).join(',') : '';
        const ed0 = edIsArr ? ed[0] : (Object.values(ed)[0] || null);
        const ed0Keys = ed0 ? Object.keys(ed0).slice(0,15).join(',') : 'null';
        editorsInfo = 'isArr=' + edIsArr + ',len=' + edLen + ',keys=[' + edKeys + '],ed0=[' + ed0Keys + ']';
      }
      const smInfo = 'type=' + smType + ',smKeys=[' + smKeys + '],editors:{' + editorsInfo + '}';

      // nhn 네임스페이스
      const nhn = w.nhn;
      const nhnKeys = nhn ? Object.keys(nhn).slice(0,8).join(',') : 'null';

      // SE 네임스페이스
      const SE = w.SE;
      const seNsKeys = SE ? Object.keys(SE).slice(0,8).join(',') : 'null';

      // contenteditable 요소들
      const ces = Array.from(iDoc.querySelectorAll('[contenteditable]')).map(el =>
        el.tagName + '[ce=' + el.getAttribute('contenteditable') + ']'
      ).join('|');

      return 'SM:{' + smInfo + '} nhn:[' + nhnKeys + '] SE:[' + seNsKeys + '] ces=[' + ces + ']';
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
          // 1순위: SmartEditor 내부 API — 제목 모듈
          + 'var _sm=window.SmartEditor;'
          + 'var se3=null;'
          // _editors 배열 또는 맵에서 직접 접근
          + 'if(!se3&&_sm&&_sm._editors){'
          + '  try{var _ed=_sm._editors;'
          + '    se3=Array.isArray(_ed)?_ed[0]:(Object.values(_ed)[0]||null);'
          + '  }catch(e_ed){}'
          + '}'
          // getEditor() 호출
          + 'if(!se3&&_sm){try{var _ge=_sm.getEditor();if(_ge&&typeof _ge==="object")se3=_ge;}catch(e_ge){}}'
          // 배열이면 [0]
          + 'if(!se3&&_sm&&Array.isArray(_sm)){se3=_sm[0]||null;}'
          // SmartEditor 자체에 exec가 있으면 그대로 사용
          + 'if(!se3&&_sm&&typeof _sm.exec==="function"){se3=_sm;}'
          // se3 null이면 _editors 상태 로깅
          + 'if(!se3){document.__caifyResult="se3_null:ed_len="+((_sm&&_sm._editors&&Array.isArray(_sm._editors))?_sm._editors.length:((_sm&&_sm._editors)?Object.keys(_sm._editors).length:"no_ed"));return;}'
          + 'if(se3){'
          // _documentService 통해 제목 설정
          + '  var ds=se3._documentService;'
          + '  if(ds){'
          + '    var dsMethods=["setTitle","setDocumentTitle","updateTitle","changeTitle","setDocTitle"];'
          + '    for(var di=0;di<dsMethods.length;di++){'
          + '      try{if(typeof ds[dsMethods[di]]==="function"){ds[dsMethods[di]](t);document.__caifyResult="ok_ds:"+dsMethods[di];return;}}catch(dm){}'
          + '    }'
          + '  }'
          // _papyrus 통해 제목 설정
          + '  var pap=se3._papyrus;'
          + '  if(pap){'
          + '    var papTMethods=["setTitle","setDocumentTitle","setTitleContent"];'
          + '    for(var pi=0;pi<papTMethods.length;pi++){'
          + '      try{if(typeof pap[papTMethods[pi]]==="function"){pap[papTMethods[pi]](t);document.__caifyResult="ok_pap:"+papTMethods[pi];return;}}catch(pm){}'
          + '    }'
          + '  }'
          // 서비스 메서드 목록 반환 (다음 진단용)
          + '  var dsKeys=ds?Object.getOwnPropertyNames(Object.getPrototypeOf(ds)).filter(function(k){return k[0]!=="_";}).join(","):"null";'
          + '  var papKeys=pap?Object.getOwnPropertyNames(Object.getPrototypeOf(pap)).filter(function(k){return k[0]!=="_";}).join(","):"null";'
          + '  document.__caifyResult="se3_no_title:ds=["+dsKeys+"],pap=["+papKeys+"]";'
          + '  return;'
          + '}'
          // 2순위: DOM 직접 수정 + se-is-empty 클래스 제거
          + 'var titleP=document.querySelector(".se-title-text .se-text-paragraph")||document.querySelector(".se-title-text p")||document.querySelector(".se-section-documentTitle p");'
          + 'if(!titleP){document.__caifyResult="no_title_p";return;}'
          + 'var allCE=Array.from(document.querySelectorAll("[contenteditable=true]"));'
          + 'var seEd=allCE.find(function(c){return c.contains(titleP);})||allCE[0];'
          + 'if(seEd)seEd.focus();'
          + 'var sel=window.getSelection(),r=document.createRange();'
          + 'r.selectNodeContents(titleP);sel.removeAllRanges();sel.addRange(r);'
          + 'titleP.dispatchEvent(new InputEvent("beforeinput",{bubbles:true,cancelable:true,inputType:"insertText",data:t}));'
          + 'titleP.textContent=t;'
          + 'titleP.dispatchEvent(new InputEvent("input",{bubbles:true,inputType:"insertText",data:t}));'
          // se-is-empty 클래스 제거 — SE3 빈 상태 마커 제거
          + 'var titleMod=titleP.closest(".__se-unit")||titleP.closest(".se-module");'
          + 'if(titleMod)titleMod.classList.remove("se-is-empty");'
          + 'if(seEd){seEd.dispatchEvent(new Event("input",{bubbles:true}));sel.removeAllRanges();seEd.blur();}'
          + 'document.__caifyResult="ok_direct";'
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
            // 1순위: SmartEditor exec API — 본문
            + 'var _smb=window.SmartEditor;'
            + 'var se3b=null;'
            + 'if(!se3b&&_smb&&_smb._editors){'
            + '  try{var _edb=_smb._editors;'
            + '    se3b=Array.isArray(_edb)?_edb[0]:(Object.values(_edb)[0]||null);'
            + '  }catch(e_edb){}'
            + '}'
            + 'if(!se3b&&_smb){try{var _geb=_smb.getEditor();if(_geb&&typeof _geb==="object")se3b=_geb;}catch(e_geb){}}'
            + 'if(!se3b&&_smb&&Array.isArray(_smb)){se3b=_smb[0]||null;}'
            + 'if(!se3b&&_smb&&typeof _smb.exec==="function"){se3b=_smb;}'
            + 'if(se3b){'
            + '  var ds2=se3b._documentService;'
            + '  var pap2=se3b._papyrus;'
            // 방법 1: getDocumentData로 현재 구조 가져와서 body 컴포넌트만 교체 후 setDocumentData
            // → 타이틀 컴포넌트를 보존하면서 본문만 수정
            + '  if(ds2&&typeof ds2.getDocumentData==="function"&&typeof ds2.setDocumentData==="function"){'
            + '    try{'
            + '      var docData=ds2.getDocumentData();'
            + '      var docKeys=docData?Object.keys(docData).join(","):"null";'
            + '      var inner=docData&&(docData.document||docData);'
            + '      var comps=inner&&(inner.componentList||inner.components||inner.body||null);'
            + '      if(Array.isArray(comps)&&comps.length>0){'
            + '        var compTypes=comps.map(function(c){return (c["@ctype"]||c.ctype||c.type||"?")+":"+Object.keys(c).join(";");}).join("|");'
            + '        var textComp=null;'
            + '        for(var ci=0;ci<comps.length;ci++){'
            + '          var ct=(comps[ci]["@ctype"]||comps[ci].ctype||comps[ci].type||"").toLowerCase();'
            + '          if(ct==="documenttitle"||ct==="title"||ct==="document_title")continue;'
            // @ctype 없거나 title 아닌 경우 — title 키가 없는 컴포넌트를 본문으로 간주
            + '          if(!ct&&("title" in comps[ci]||"subTitle" in comps[ci]))continue;'
            + '          textComp=comps[ci];break;'
            + '        }'
            + '        if(textComp){'
            + '          var bodyKey=("html" in textComp)?"html":("value" in textComp)?"value":("content" in textComp)?"content":("data" in textComp)?"data":null;'
            + '          if(!bodyKey){document.__caifyResult2="no_bodyKey:compKeys=["+Object.keys(textComp).join(",")+"]";return;}'
            + '          textComp[bodyKey]=h;'
            + '          ds2.setDocumentData(docData);'
            + '          document.__caifyResult2="ok_setDocData:"+bodyKey;return;'
            + '        }'
            + '        document.__caifyResult2="no_textComp:n="+comps.length+",types=["+compTypes+"]";return;'
            + '      }'
            + '      document.__caifyResult2="no_comps:docKeys=["+docKeys+"],preview="+JSON.stringify(docData).substring(0,120);return;'
            + '    }catch(gdd){document.__caifyResult2="err_getDocData:"+gdd.message;return;}'
            + '  }'
            // 방법 2: _papyrus paste 플러그인 (더 많은 이름 시도)
            + '  if(pap2&&typeof pap2.getPlugin==="function"){'
            + '    var pasteNames=["paste","Paste","PastePlugin","html","Html","textPlugin","text","RichText","richText","clipboard","Clipboard","pasteHelper","PasteHelper"];'
            + '    for(var pn=0;pn<pasteNames.length;pn++){'
            + '      try{'
            + '        var plugin=pap2.getPlugin(pasteNames[pn]);'
            + '        if(plugin){'
            + '          if(typeof plugin.onPaste==="function"){plugin.onPaste({html:h});document.__caifyResult2="ok_plugin_onPaste:"+pasteNames[pn];return;}'
            + '          if(typeof plugin.paste==="function"){plugin.paste(h);document.__caifyResult2="ok_plugin_paste:"+pasteNames[pn];return;}'
            + '          if(typeof plugin.insertHtml==="function"){plugin.insertHtml(h);document.__caifyResult2="ok_plugin_insertHtml:"+pasteNames[pn];return;}'
            + '        }'
            + '      }catch(ppn){}'
            + '    }'
            + '  }'
            + '  var papBKeys=pap2?Object.getOwnPropertyNames(Object.getPrototypeOf(pap2)).filter(function(k){return k[0]!=="_";}).slice(0,10).join(","):"null";'
            + '  var ds2Keys=ds2?Object.getOwnPropertyNames(Object.getPrototypeOf(ds2)).filter(function(k){return k[0]!=="_";}).slice(0,10).join(","):"null";'
            + '  document.__caifyResult2="se3_no_body:pap=["+papBKeys+"],ds2=["+ds2Keys+"]";'
            + '  return;'
            + '}'
            // 2순위: DOM <p> 직접 수정
            + 'var bodyP=document.querySelector(".se-component.se-text .se-text-paragraph")||document.querySelector(".se-section-text p");'
            + 'if(!bodyP){document.__caifyResult2="no_body_p";return;}'
            + 'var allCE2=Array.from(document.querySelectorAll("[contenteditable=true]"));'
            + 'var seEd2=allCE2.find(function(c){return c.contains(bodyP);})||allCE2[0];'
            + 'if(seEd2)seEd2.focus();'
            + 'var sel2=window.getSelection(),r2=document.createRange();'
            + 'r2.selectNodeContents(bodyP);sel2.removeAllRanges();sel2.addRange(r2);'
            + 'try{'
            + '  var dt=new DataTransfer();dt.setData("text/html",h);dt.setData("text/plain","");'
            + '  bodyP.dispatchEvent(new ClipboardEvent("paste",{clipboardData:dt,bubbles:true,cancelable:true}));'
            + '  document.__caifyResult2="paste_ok";return;'
            + '}catch(pe){}'
            + 'bodyP.dispatchEvent(new InputEvent("beforeinput",{bubbles:true,cancelable:true,inputType:"insertFromPaste"}));'
            + 'bodyP.innerHTML=h;'
            + 'bodyP.dispatchEvent(new InputEvent("input",{bubbles:true,inputType:"insertFromPaste"}));'
            + 'if(seEd2)seEd2.dispatchEvent(new Event("input",{bubbles:true}));'
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
        const iframe = document.querySelector("iframe[name='mainFrame']") ||
                       document.querySelector("iframe#mainFrame") ||
                       document.querySelector("iframe");
        const inMain = !!document.querySelector('.se-title-text,.se-section-documentTitle');
        const iDoc = inMain ? document
                   : (iframe ? (function(){try{return iframe.contentDocument;}catch(e){return null;}}()) : null);

        // 방법 A: _tagService API (SE3 내부)
        const sm = inMain ? window.SmartEditor : (iframe?.contentWindow?.SmartEditor);
        if (sm && sm._editors) {
          const ed = Object.values(sm._editors)[0];
          const ts = ed?._tagService;
          if (ts) {
            const tagMethods = ['addTag','addTags','setTags','insertTag'];
            for (const m of tagMethods) {
              if (typeof ts[m] === 'function') {
                try {
                  for (const tag of [$tagsJson]) { ts[m](tag); }
                  return 'ok_tagService:' + m;
                } catch(e) {}
              }
            }
            // 태그 서비스 메서드 목록 반환
            const tsMethods = Object.getOwnPropertyNames(Object.getPrototypeOf(ts)).filter(k=>k[0]!=='_').join(',');
            return 'no_tag_method:[' + tsMethods + ']';
          }
        }

        // 방법 B: DOM input 탐색
        const doc = iDoc || document;
        const tagInput =
          doc.querySelector('input.se-tag-input') ||
          doc.querySelector('input[placeholder*="태그"]') ||
          doc.querySelector('[class*="tag"] input') ||
          doc.querySelector('.se-tag-field input');
        if (!tagInput) return 'no_tag_input';

        tagInput.focus();
        for (const tag of [$tagsJson]) {
          const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(tagInput), 'value')?.set;
          if (setter) setter.call(tagInput, tag);
          else tagInput.value = tag;
          tagInput.dispatchEvent(new Event('input', { bubbles: true }));
          tagInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, bubbles: true }));
          tagInput.dispatchEvent(new KeyboardEvent('keyup',   { key: 'Enter', keyCode: 13, bubbles: true }));
          tagInput.value = '';
        }
        return 'ok_dom';
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
      // .se-title-text 없어도 버튼 탐색 — 상위/iframe 모두 시도
      const searchDocs = [document];
      const frames = [
        document.querySelector("iframe[name='mainFrame']"),
        document.querySelector("iframe#mainFrame"),
        document.querySelector("iframe"),
      ];
      for (const f of frames) {
        if (!f) continue;
        let d; try { d = f.contentDocument; } catch(e) { continue; }
        if (d) searchDocs.push(d);
      }
      for (const doc of searchDocs) {
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
        if (btn) { btn.click(); return 'ok'; }
      }
      const allBtns = Array.from(document.querySelectorAll('button'))
        .map(b=>(b.innerText||'').trim().substring(0,15)).filter(t=>t).join(',');
      return 'no_save_btn|' + allBtns;
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
