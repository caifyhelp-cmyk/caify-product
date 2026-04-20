/// Naver Smart Editor 3 자동화 JS 스니펫 모음
/// flutter_inappwebview의 evaluateJavascript()로 메인 프레임에서 실행
/// → iframe[name='mainFrame'].contentDocument 접근 (same-origin)
library naver_publisher;

class NaverPublisher {
  // ── 에디터 준비 확인 ─────────────────────────────────────────
  /// 반환값: 'ready' | 'not_ready' | 'no_iframe'
  static String jsIsEditorReady() => r'''
    (function() {
      const iframe = document.querySelector("iframe[name='mainFrame']");
      if (!iframe || !iframe.contentDocument) return 'no_iframe';
      const doc = iframe.contentDocument;
      const titleEl = doc.querySelector('.se-title-text') ||
                      doc.querySelector('.se-section-documentTitle');
      const bodyEl  = doc.querySelector('.se-component.se-text') ||
                      doc.querySelector('.se-section-text');
      return (titleEl && bodyEl) ? 'ready' : 'not_ready';
    })()
  ''';

  // ── 제목 입력 ────────────────────────────────────────────────
  static String jsInjectTitle(String title) {
    final escaped = _escapeJs(title);
    return '''
      (function() {
        const iframe = document.querySelector("iframe[name='mainFrame']");
        if (!iframe || !iframe.contentDocument) return 'no_iframe';
        const doc = iframe.contentDocument;
        const el =
          doc.querySelector('.se-title-text .se-text-paragraph') ||
          doc.querySelector('.se-section-documentTitle .se-text-paragraph') ||
          doc.querySelector('.se-title-text');
        if (!el) return 'no_title_el';
        el.focus();
        el.textContent = "$escaped";
        el.dispatchEvent(new Event('input',  { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        return 'ok';
      })()
    ''';
  }

  // ── 본문 HTML 주입 (ClipboardEvent) ──────────────────────────
  static String jsInjectHtml(String html) {
    final escaped = _escapeJs(html);
    return '''
      (function() {
        const iframe = document.querySelector("iframe[name='mainFrame']");
        if (!iframe || !iframe.contentDocument) return 'no_iframe';
        const doc = iframe.contentDocument;
        const el =
          doc.querySelector('.se-component.se-text .__se-node') ||
          doc.querySelector('.se-section-text .__se-node') ||
          doc.querySelector('.se-component.se-text .se-text-paragraph');
        if (!el) return 'no_body_el';
        el.focus();
        const dt = new DataTransfer();
        dt.setData('text/html', "$escaped");
        dt.setData('text/plain', '');
        el.dispatchEvent(new ClipboardEvent('paste', {
          clipboardData: dt, bubbles: true, cancelable: true
        }));
        return 'ok';
      })()
    ''';
  }

  // ── 태그 입력 ────────────────────────────────────────────────
  /// 태그 목록을 에디터 태그 입력창에 삽입
  /// 반환값: 'ok' | 'no_tag_input'
  static String jsAddTags(List<String> tags) {
    if (tags.isEmpty) return '"ok"';
    final tagsJson = tags.map((t) => '"${_escapeJs(t)}"').join(',');
    return '''
      (function() {
        const iframe = document.querySelector("iframe[name='mainFrame']");
        if (!iframe || !iframe.contentDocument) return 'no_iframe';
        const doc = iframe.contentDocument;
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

  // ── 임시저장 버튼 클릭 ───────────────────────────────────────
  /// 에디터 상단 '임시저장' 버튼 클릭 후 멈춤 (발행은 고객이 직접)
  /// 반환값: 'ok' | 'no_save_btn|<button_list>'
  static String jsClickTempSave() => r'''
    (function() {
      const iframe = document.querySelector("iframe[name='mainFrame']");
      if (!iframe || !iframe.contentDocument) return 'no_iframe';
      const doc = iframe.contentDocument;
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

  // ── JS 문자열 이스케이프 ──────────────────────────────────────
  static String _escapeJs(String s) => s
      .replaceAll(r'\', r'\\')
      .replaceAll('"', r'\"')
      .replaceAll('\n', r'\n')
      .replaceAll('\r', '');
}
