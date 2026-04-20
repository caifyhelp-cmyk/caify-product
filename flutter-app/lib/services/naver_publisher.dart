/// Naver Smart Editor 3 자동화 JS 스니펫 모음
/// flutter_inappwebview의 evaluateJavascript()로 메인 프레임에서 실행
/// → iframe[name='mainFrame'].contentDocument 접근 (same-origin 이므로 가능)
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

  // ── 저장 버튼 클릭 ───────────────────────────────────────────
  static String jsSave() => r'''
    (function() {
      const iframe = document.querySelector("iframe[name='mainFrame']");
      if (!iframe || !iframe.contentDocument) return 'no_iframe';
      const doc = iframe.contentDocument;
      const btn =
        doc.querySelector('button.save_btn__bzc5B') ||
        doc.querySelector('button[data-click-area="tpb.save"]') ||
        doc.querySelector('.save_btn_area__Qo0W7 button') ||
        Array.from(doc.querySelectorAll('button')).find(b =>
          (b.innerText || '').trim() === '저장'
        );
      if (!btn) {
        // 디버그: 버튼 목록 반환
        return 'no_save_btn|' + Array.from(doc.querySelectorAll('button'))
          .map(b => (b.innerText||'').trim().substring(0,15) + ':' + b.className.substring(0,20))
          .join(',');
      }
      btn.click();
      return 'ok';
    })()
  ''';

  // ── 현재 URL이 로그인 페이지인지 확인 ────────────────────────
  static bool isLoginUrl(String url) =>
      url.contains('nidlogin') || url.contains('login.naver');

  // ── JS 문자열 이스케이프 (백슬래시, 따옴표, 개행) ─────────────
  static String _escapeJs(String s) => s
      .replaceAll(r'\', r'\\')
      .replaceAll('"', r'\"')
      .replaceAll('\n', r'\n')
      .replaceAll('\r', '');
}
