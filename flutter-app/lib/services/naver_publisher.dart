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
          // HTML → SE3 paragraph[] 변환을 바깥(top-level) 컨텍스트에서 처리
          // iframe 스크립트 안에서는 iDoc2 등 바깥 변수에 접근 불가이므로 JSON으로 전달
          const _pd = document.createElement('div');
          _pd.innerHTML = "$escaped";
          let _blocks = Array.from(_pd.querySelectorAll('p,h1,h2,h3,h4,h5,h6,li'));
          if (_blocks.length === 0) {
            const _raw = _pd.innerHTML.replace(/<br\\s*\\/?>/gi, '\\n');
            const _tmpDiv = document.createElement('div'); _tmpDiv.innerHTML = _raw;
            _blocks = (_tmpDiv.textContent||'').split('\\n').filter(s=>s.trim()).map(s=>{
              const _e = document.createElement('p'); _e.textContent = s; return _e;
            });
          }
          if (_blocks.length === 0) _blocks = [_pd];
          const _s4 = ()=>Math.floor((1+Math.random())*0x10000).toString(16).substring(1);
          const _uid = ()=>'SE-'+_s4()+_s4()+'-'+_s4()+'-4'+_s4().substr(1)+'-'+(Math.floor(Math.random()*4+8)).toString(16)+_s4().substr(1)+'-'+_s4()+_s4()+_s4();
          const _paras = _blocks.filter(b=>(b.textContent||'').trim()).map(b=>({
            id:_uid(), nodes:[{id:_uid(), value:(b.textContent||'').trim(), '@ctype':'textNode'}], '@ctype':'paragraph'
          }));
          if (_paras.length === 0) _paras.push({id:_uid(), nodes:[{id:_uid(), value:'', '@ctype':'textNode'}], '@ctype':'paragraph'});

          iDoc2.__caifyParas = JSON.stringify(_paras);
          iDoc2.__caifyResult2 = null;
          const s2 = iDoc2.createElement('script');
          // iframe 스크립트: document.__caifyParas 읽어서 SE3 setDocumentData 호출
          // iDoc2 등 바깥 변수 일절 사용 금지 — document만 사용
          s2.textContent = '(function(){'
            + 'try{'
            + 'var paras=JSON.parse(document.__caifyParas);'
            + 'var _sm=window.SmartEditor;'
            + 'var se3b=null;'
            + 'if(_sm&&_sm._editors){try{var _ed=_sm._editors;se3b=Array.isArray(_ed)?_ed[0]:(Object.values(_ed)[0]||null);}catch(ee){}}'
            + 'if(!se3b){document.__caifyResult2="se3b_null";return;}'
            + 'var ds=se3b._documentService;'
            + 'if(!ds||typeof ds.getDocumentData!=="function"||typeof ds.setDocumentData!=="function"){document.__caifyResult2="no_ds_methods";return;}'
            + 'var docData=ds.getDocumentData();'
            + 'var inner=docData&&(docData.document||docData);'
            + 'var comps=inner&&(inner.componentList||inner.components||inner.body||null);'
            + 'if(!Array.isArray(comps)||comps.length===0){document.__caifyResult2="no_comps_in_doc";return;}'
            + 'var textComp=null;'
            + 'for(var i=0;i<comps.length;i++){'
            + '  var ct=(comps[i]["@ctype"]||comps[i].ctype||comps[i].type||"").toLowerCase();'
            + '  if(ct==="documenttitle"||ct==="title"||ct==="document_title")continue;'
            + '  if(!ct&&("title" in comps[i]||"subTitle" in comps[i]))continue;'
            + '  textComp=comps[i];break;'
            + '}'
            + 'if(!textComp){document.__caifyResult2="no_textComp:n="+comps.length;return;}'
            + 'textComp.value=paras;'
            + 'ds.setDocumentData(docData);'
            + 'document.__caifyResult2="ok_setDocData:"+paras.length;'
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
  // 태그 input은 SE3 iframe 밖(outer document)에 있으므로 양쪽 모두 탐색
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
            const tsMethods = Object.getOwnPropertyNames(Object.getPrototypeOf(ts)).filter(k=>k[0]!=='_').join(',');
            return 'no_tag_method:[' + tsMethods + ']';
          }
        }

        // 방법 B: DOM input 탐색 (iframe 안팎 모두)
        const doc = iDoc || document;
        const tagInput =
          doc.querySelector('input.se-tag-input') ||
          doc.querySelector('input[placeholder*="태그"]') ||
          doc.querySelector('[class*="tag"] input') ||
          doc.querySelector('.se-tag-field input') ||
          document.querySelector('input.se-tag-input') ||
          document.querySelector('input[placeholder*="태그"]') ||
          document.querySelector('[class*="tag"] input') ||
          document.querySelector('.se-tag-field input') ||
          document.querySelector('input[type="text"][class*="tag"]');
        if (!tagInput) return 'no_tag_input';

        tagInput.focus();
        let added = 0;
        for (const tag of [$tagsJson]) {
          const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(tagInput), 'value')?.set;
          if (setter) setter.call(tagInput, tag);
          else tagInput.value = tag;
          tagInput.dispatchEvent(new Event('input',  { bubbles: true }));
          // Enter 또는 콤마로 태그 확정 (에디터에 따라 다름)
          tagInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, bubbles: true }));
          tagInput.dispatchEvent(new KeyboardEvent('keyup',   { key: 'Enter', keyCode: 13, bubbles: true }));
          tagInput.dispatchEvent(new KeyboardEvent('keydown', { key: ',', keyCode: 188, bubbles: true }));
          tagInput.value = '';
          added++;
        }
        return 'ok:' + added;
      })()
    ''';
  }

  // ── 이미지 URL → SE3 image 컴포넌트로 setDocumentData 삽입 ─────
  // 1순위: setDocumentData에 image 컴포넌트 추가 (업로드 없이 URL 직접 삽입)
  // 2순위: _papyrus 공개 메서드 목록 로깅 (업로드 API 탐색용)
  static String jsInjectImageComponent(String imageUrl) {
    final escaped = _escapeJs(imageUrl);
    return '''
      (function() {
        const iframe = document.querySelector("iframe[name='mainFrame']") ||
                       document.querySelector("iframe#mainFrame") ||
                       document.querySelector("iframe");
        const inMain = !!document.querySelector('.se-title-text,.se-section-documentTitle');
        const iDoc = inMain ? document
                   : (iframe ? (function(){try{return iframe.contentDocument;}catch(e){return null;}}()) : null);
        if (!iDoc) return 'no_iframe_doc';

        iDoc.__caifyImgUrl = '$escaped';
        iDoc.__caifyImgCompResult = null;

        const s = iDoc.createElement('script');
        s.textContent = '(function(){'
          + 'try{'
          + 'var imgUrl=document.__caifyImgUrl;'
          + 'var _sm=window.SmartEditor;'
          + 'var se3=null;'
          + 'if(_sm&&_sm._editors){try{var _ed=_sm._editors;se3=Array.isArray(_ed)?_ed[0]:(Object.values(_ed)[0]||null);}catch(ee){}}'
          + 'if(!se3){document.__caifyImgCompResult="se3_null";return;}'
          // _papyrus 메서드 목록 (디버깅용 — 업로드 API 찾기)
          + 'var pap=se3._papyrus;'
          + 'var papPub=pap?Object.getOwnPropertyNames(Object.getPrototypeOf(pap)).filter(function(k){return k[0]!=="_";}).slice(0,30).join(","):"null";'
          + 'var papPriv=pap?Object.getOwnPropertyNames(Object.getPrototypeOf(pap)).filter(function(k){return k[0]==="_";}).slice(0,20).join(","):"null";'
          // _documentService로 setDocumentData 시도
          + 'var ds=se3._documentService;'
          + 'if(!ds||typeof ds.getDocumentData!=="function"||typeof ds.setDocumentData!=="function"){'
          + '  document.__caifyImgCompResult="no_ds|pap_pub=["+papPub+"]";return;'
          + '}'
          + 'var docData=ds.getDocumentData();'
          + 'var inner=docData&&(docData.document||docData);'
          + 'var comps=inner&&(inner.componentList||inner.components||inner.body||null);'
          + 'if(!Array.isArray(comps)){'
          + '  document.__caifyImgCompResult="no_comps|pap_pub=["+papPub+"]";return;'
          + '}'
          // SE3 UUID 생성
          + 'var s4=function(){return Math.floor((1+Math.random())*0x10000).toString(16).substring(1);};'
          + 'var uid=function(){return "SE-"+s4()+s4()+"-"+s4()+"-4"+s4().substr(1)+"-"+(Math.floor(Math.random()*4+8)).toString(16)+s4().substr(1)+"-"+s4()+s4()+s4();};'
          // SE3 image 컴포넌트 형식 (다양한 형식 시도)
          + 'var imgComp={'
          + '  "@ctype":"image",'
          + '  "id":uid(),'
          + '  "layout":"default",'
          + '  "images":[{'
          + '    "@ctype":"image",'
          + '    "id":uid(),'
          + '    "src":imgUrl,'
          + '    "width":800,'
          + '    "height":600,'
          + '    "orgWidth":800,'
          + '    "orgHeight":600'
          + '  }],'
          + '  "caption":{"text":"","@ctype":"caption"}'
          + '};'
          + 'comps.push(imgComp);'
          + 'ds.setDocumentData(docData);'
          + 'document.__caifyImgCompResult="ok_img_comp|pap_pub=["+papPub+"]|pap_priv=["+papPriv+"]";'
          + '}catch(e){document.__caifyImgCompResult="err:"+e.message;}'
          + '})();';
        (iDoc.head || iDoc.body || iDoc.documentElement).appendChild(s);
        s.remove();
        return iDoc.__caifyImgCompResult || 'null_result';
      })()
    ''';
  }

  // ── 이미지 base64 → SE3 라이브러리 업로드 (iframe script 주입) ─
  // execCommand 차단 우회용 — ClipboardEvent를 iframe 컨텍스트에서 발송
  static String jsInjectImageViaScript(String base64Data, String mimeType) {
    final ext = mimeType.contains('/') ? mimeType.split('/').last : 'jpg';
    return '''
      (function() {
        const iframe = document.querySelector("iframe[name='mainFrame']") ||
                       document.querySelector("iframe#mainFrame") ||
                       document.querySelector("iframe");
        const inMain = !!document.querySelector('.se-title-text,.se-section-documentTitle');
        const iDoc = inMain ? document
                   : (iframe ? (function(){try{return iframe.contentDocument;}catch(e){return null;}}()) : null);
        if (!iDoc) return 'no_iframe_doc';

        iDoc.__caifyImgB64  = '$base64Data';
        iDoc.__caifyImgMime = '$mimeType';
        iDoc.__caifyImgExt  = '$ext';
        iDoc.__caifyImgResult = null;

        const s = iDoc.createElement('script');
        s.textContent = '(function(){'
          + 'try{'
          + 'var b64=document.__caifyImgB64;'
          + 'var mime=document.__caifyImgMime;'
          + 'var ext=document.__caifyImgExt;'
          + 'var byteChars=atob(b64);'
          + 'var bytes=new Uint8Array(byteChars.length);'
          + 'for(var i=0;i<byteChars.length;i++)bytes[i]=byteChars.charCodeAt(i);'
          + 'var blob=new Blob([bytes],{type:mime});'
          + 'var file=new File([blob],"image."+ext,{type:mime});'
          + 'var el='
          + '  document.querySelector(".se-component.se-text [contenteditable=true]")||'
          + '  document.querySelector(".se-section-text [contenteditable=true]")||'
          + '  document.querySelector("[data-placeholder*=\\u0027글감\\u0027][contenteditable]")||'
          + '  document.querySelector("[data-placeholder*=\\u0027내용\\u0027][contenteditable]");'
          + 'if(!el){'
          + '  var ce=document.querySelector("[contenteditable=true]");'
          + '  if(ce){var bp=Array.from(ce.querySelectorAll("p")).find(function(p){return !p.closest(".se-section-documentTitle")&&!p.closest(".se-title-text");});el=bp||ce;}'
          + '}'
          + 'if(!el){document.__caifyImgResult="no_body_el";return;}'
          + 'el.focus();'
          + 'var dt=new DataTransfer();'
          + 'dt.items.add(file);'
          + 'var ev=new ClipboardEvent("paste",{clipboardData:dt,bubbles:true,cancelable:true});'
          + 'var dispatched=el.dispatchEvent(ev);'
          + 'document.__caifyImgResult="paste_dispatched:"+dispatched;'
          + '}catch(e){document.__caifyImgResult="err:"+e.message;}'
          + '})();';
        (iDoc.head || iDoc.body || iDoc.documentElement).appendChild(s);
        s.remove();
        return iDoc.__caifyImgResult || 'null_result';
      })()
    ''';
  }

  // ── 발행 다이얼로그 태그 주입 ────────────────────────────────
  // 발행 버튼 클릭 후 다이얼로그 열리면 호출
  static String jsAddTagsInDialog(List<String> tags) {
    if (tags.isEmpty) return '"ok_empty"';
    final tagsJson = tags.map((t) => '"${_escapeJs(t)}"').join(',');
    return '''
      (function() {
        // 발행 다이얼로그는 top-level 또는 iframe 안에 있을 수 있음 — 둘 다 탐색
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

        const findTagInput = (doc) =>
          doc.querySelector('input[placeholder*="#태그"]') ||
          doc.querySelector('input[placeholder*="태그를 입력"]') ||
          doc.querySelector('input[placeholder*="태그"]') ||
          doc.querySelector('[class*="tag_input"] input') ||
          doc.querySelector('[class*="TagInput"] input') ||
          doc.querySelector('[class*="tag"] input') ||
          doc.querySelector('input[name*="tag"]');

        let tagInput = null;
        for (const doc of searchDocs) {
          tagInput = findTagInput(doc);
          if (tagInput) break;
        }

        if (!tagInput) {
          const allInputs = searchDocs.flatMap(doc =>
            Array.from(doc.querySelectorAll('input'))
              .map(i => (i.placeholder||i.name||'').substring(0,20)).filter(Boolean)
          ).join('|');
          return 'no_tag_input|inputs=[' + allInputs + ']';
        }

        tagInput.focus();
        let added = 0;
        for (const tag of [$tagsJson]) {
          const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(tagInput), 'value')?.set;
          if (setter) setter.call(tagInput, tag);
          else tagInput.value = tag;
          tagInput.dispatchEvent(new Event('input', { bubbles: true }));
          tagInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, bubbles: true }));
          tagInput.dispatchEvent(new KeyboardEvent('keyup',   { key: 'Enter', keyCode: 13, bubbles: true }));
          tagInput.value = '';
          added++;
        }
        return 'ok:' + added;
      })()
    ''';
  }

  // ── 본문 끝으로 커서 이동 + 클립보드 paste ───────────────────
  // 실제 Android 클립보드에 이미지 세팅 후 이 JS 실행 → SE3 라이브러리 업로드
  static String jsFocusBodyEndAndPaste() => r'''
    (function() {
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
      if (!doc) return 'no_doc';
      // 본문 CE 탐색 — setDocumentData 후 SE3가 DOM 재렌더링하므로 다양한 셀렉터 시도
      let el =
        doc.querySelector('.se-component.se-text [contenteditable="true"]') ||
        doc.querySelector('.se-section-text [contenteditable="true"]') ||
        doc.querySelector('[data-placeholder*="글감"][contenteditable]') ||
        doc.querySelector('[data-placeholder*="내용"][contenteditable]');
      if (!el) {
        // 폴백: 단일 CE div 안에서 title 영역 제외한 본문 단락 찾기
        const ce = doc.querySelector('[contenteditable="true"]');
        if (ce) {
          const bodyP =
            Array.from(ce.querySelectorAll('p')).find(p =>
              !p.closest('.se-section-documentTitle') &&
              !p.closest('.se-title-text') &&
              !p.closest('.se-module-documentTitle')
            );
          el = bodyP || ce;
        }
      }
      if (!el) return 'no_body_el';
      el.focus();
      const win = doc.defaultView || window;
      const sel = win.getSelection();
      const range = doc.createRange();
      range.selectNodeContents(el);
      range.collapse(false);
      sel.removeAllRanges();
      sel.addRange(range);
      try {
        const r = doc.execCommand('paste');
        return 'ok:' + r;
      } catch(e) {
        return 'err:' + e.message;
      }
    })()
  ''';

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
