import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/post.dart';
import '../services/naver_publisher.dart';

enum PublishState { loading, loginRequired, injecting, saving, editorReady, failed }

class PublishScreen extends StatefulWidget {
  final Post post;
  const PublishScreen({super.key, required this.post});

  @override
  State<PublishScreen> createState() => _PublishScreenState();
}

class _PublishScreenState extends State<PublishScreen> {
  InAppWebViewController? _ctrl;
  PublishState _state    = PublishState.loading;
  String _statusMsg      = '에디터 로드 중...';
  bool _waitingStarted   = false;
  int  _redirectAttempts = 0;
  int  _errorAttempts    = 0;      // 에러 페이지 재시도 횟수 (무한루프 방지)
  bool _showBanner       = true;   // 초록 안내 배너 표시 여부

  static const _prefBannerKey    = 'publish_banner_hidden';
  static const _prefCookieKey    = 'naver_cookies_v1';

  static String _jsStr(dynamic r) => r?.toString() ?? '';

  bool get _showWebView =>
      _state == PublishState.loginRequired ||
      _state == PublishState.editorReady;

  // ── 초기화 ────────────────────────────────────────────────
  @override
  void initState() {
    super.initState();
    _loadPrefs();
  }

  Future<void> _loadPrefs() async {
    final prefs = await SharedPreferences.getInstance();
    final hidden = prefs.getBool(_prefBannerKey) ?? false;
    if (hidden && mounted) setState(() => _showBanner = false);
  }

  // ── 배너 닫기 ──────────────────────────────────────────────
  void _closeBanner({bool neverShow = false}) async {
    setState(() => _showBanner = false);
    if (neverShow) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setBool(_prefBannerKey, true);
    }
  }

  // ── 네이버 쿠키 저장 ──────────────────────────────────────
  Future<void> _saveNaverCookies() async {
    try {
      final mgr = CookieManager.instance();
      final urls = [
        WebUri('https://blog.naver.com'),
        WebUri('https://naver.com'),
      ];
      final all = <Map<String, dynamic>>[];
      for (final url in urls) {
        final cookies = await mgr.getCookies(url: url);
        for (final c in cookies) {
          all.add({
            'url': url.toString(),
            'name': c.name,
            'value': c.value,
            'domain': c.domain ?? '',
            'path': c.path ?? '/',
            'isSecure': c.isSecure ?? true,
          });
        }
      }
      if (all.isNotEmpty) {
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString(_prefCookieKey, jsonEncode(all));
        debugPrint('[cookies] ${all.length}개 저장됨');
      }
    } catch (e) {
      debugPrint('[cookies] 저장 실패: $e');
    }
  }

  // ── 네이버 쿠키 복원 ──────────────────────────────────────
  Future<void> _restoreNaverCookies() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final saved = prefs.getString(_prefCookieKey);
      if (saved == null) return;
      final mgr  = CookieManager.instance();
      final list = List<Map<String, dynamic>>.from(jsonDecode(saved));
      for (final c in list) {
        await mgr.setCookie(
          url:      WebUri(c['url'] as String),
          name:     c['name']   as String,
          value:    c['value']  as String,
          domain:   c['domain'] as String,
          path:     c['path']   as String,
          isSecure: c['isSecure'] as bool? ?? true,
        );
      }
      debugPrint('[cookies] ${list.length}개 복원됨');
    } catch (e) {
      debugPrint('[cookies] 복원 실패: $e');
    }
  }

  // ── UI ────────────────────────────────────────────────────
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(
          _state == PublishState.editorReady ? '내용 확인 후 발행하세요' : '발행 준비 중',
          style: const TextStyle(fontSize: 14),
        ),
        backgroundColor: Colors.white,
        foregroundColor: Colors.black87,
        elevation: 0,
        leading: _state == PublishState.editorReady || _state == PublishState.failed
            ? IconButton(
                icon: const Icon(Icons.close),
                onPressed: () => Navigator.pop(context,
                    _state == PublishState.editorReady),
              )
            : const SizedBox.shrink(),
        automaticallyImplyLeading: false,
        actions: _state == PublishState.editorReady
            ? [
                ElevatedButton(
                  onPressed: _doPublish,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF03C75A),
                    foregroundColor: Colors.white,
                    elevation: 0,
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(6)),
                    padding: const EdgeInsets.symmetric(
                        horizontal: 14, vertical: 6),
                  ),
                  child: const Text('발행하기',
                      style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold)),
                ),
                const SizedBox(width: 8),
              ]
            : null,
      ),
      body: Stack(
        children: [
          // WebView
          Opacity(
            opacity: _showWebView ? 1.0 : 0.0,
            child: InAppWebView(
              initialSettings: InAppWebViewSettings(
                javaScriptEnabled: true,
                domStorageEnabled: true,
                sharedCookiesEnabled: true,
                supportZoom: true,
                builtInZoomControls: true,
                displayZoomControls: false,
                useWideViewPort: true,
                userAgent:
                    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                    'AppleWebKit/537.36 (KHTML, like Gecko) '
                    'Chrome/120.0.0.0 Safari/537.36',
              ),
              onWebViewCreated: (ctrl) async {
                _ctrl = ctrl;
                // 쿠키 먼저 복원 → 그 다음 URL 로드 (로그인 유지)
                await _restoreNaverCookies();
                await ctrl.loadUrl(urlRequest: URLRequest(
                  url: WebUri('https://blog.naver.com/GoBlogWrite.naver'),
                ));
              },
              onLoadStop: (ctrl, url) async {
                final u = url?.toString() ?? '';
                // 페이지 내용으로 에러 판단 (URL만으로 못 잡는 경우 대비)
                final bodyText = await ctrl.evaluateJavascript(
                    source: 'document.body?.innerText?.substring(0,200) ?? ""');
                final body = bodyText?.toString() ?? '';
                debugPrint('[loadStop] url=$u body=${body.substring(0, body.length.clamp(0,100))}');
                if (body.contains('일시적인 오류') || body.contains('서비스에 접속할 수 없')) {
                  _onPageLoaded('error_page_detected:$u');
                } else {
                  _onPageLoaded(u);
                }
              },
              onReceivedError: (ctrl, req, err) {
                if (err.type.toString().contains('ERR_INTERNET_DISCONNECTED')) {
                  _setStatus(PublishState.failed, '인터넷 연결을 확인해 주세요.');
                }
              },
            ),
          ),
          // 로딩/오류 오버레이
          if (!_showWebView) _buildOverlay(),

          // 초록 안내 배너 (X 닫기 + 다시는 보지 않기)
          if (_state == PublishState.editorReady && _showBanner)
            Positioned(
              top: 0, left: 0, right: 0,
              child: Material(
                color: const Color(0xFF03C75A),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  child: Row(
                    children: [
                      const Icon(Icons.check_circle, color: Colors.white, size: 16),
                      const SizedBox(width: 8),
                      const Expanded(
                        child: Text(
                          '내용이 입력됐습니다. 오른쪽 위 발행하기를 누르세요.',
                          style: TextStyle(color: Colors.white, fontSize: 12),
                        ),
                      ),
                      // 다시는 보지 않기
                      GestureDetector(
                        onTap: () => _closeBanner(neverShow: true),
                        child: const Text('다시 보지 않기',
                            style: TextStyle(
                                color: Colors.white70, fontSize: 11,
                                decoration: TextDecoration.underline,
                                decorationColor: Colors.white70)),
                      ),
                      const SizedBox(width: 8),
                      // X 닫기
                      GestureDetector(
                        onTap: () => _closeBanner(),
                        child: const Icon(Icons.close, color: Colors.white, size: 18),
                      ),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _buildOverlay() {
    final isError = _state == PublishState.failed;
    return Container(
      color: Colors.white,
      child: Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (!isError)
                const CircularProgressIndicator(color: Color(0xFF03C75A))
              else
                const Icon(Icons.error_outline, color: Colors.red, size: 64),
              const SizedBox(height: 24),
              Text(
                _statusMsg,
                textAlign: TextAlign.center,
                style: TextStyle(
                    fontSize: 16,
                    color: isError ? Colors.red : Colors.black87),
              ),
              if (isError) ...[
                const SizedBox(height: 32),
                ElevatedButton(
                  onPressed: () => Navigator.pop(context, false),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.grey,
                    padding: const EdgeInsets.symmetric(
                        horizontal: 40, vertical: 14),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(8)),
                  ),
                  child: const Text('닫기',
                      style: TextStyle(color: Colors.white, fontSize: 16)),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  // ── 페이지 로드 핸들러 ─────────────────────────────────────
  Future<void> _onPageLoaded(String url) async {
    if (url.isEmpty) return;
    debugPrint('[onPageLoaded] $url');

    // Naver 에러 페이지 감지 → 1회만 쿠키 삭제 후 재시도, 이후엔 WebView 노출
    if (_isNaverErrorPage(url)) {
      debugPrint('[onPageLoaded] 에러 페이지 감지 (시도 $_errorAttempts)');
      if (_errorAttempts < 1) {
        _errorAttempts++;
        await _clearNaverCookies();
        _redirectAttempts = 0;
        _waitingStarted   = false;
        await Future.delayed(const Duration(seconds: 2));
        if (_ctrl == null || !mounted) return;
        await _ctrl!.loadUrl(urlRequest: URLRequest(
          url: WebUri('https://blog.naver.com/GoBlogWrite.naver'),
        ));
      } else {
        // 재시도 후도 에러 → WebView 노출하고 사용자가 직접 이동
        if (mounted) {
          setState(() {
            _state     = PublishState.loginRequired;
            _statusMsg = '네이버 서비스 오류가 발생했습니다.\n직접 글쓰기 화면으로 이동해 주세요.';
          });
        }
      }
      return;
    }

    if (NaverPublisher.isLoginUrl(url)) {
      setState(() {
        _state     = PublishState.loginRequired;
        _statusMsg = '네이버 로그인이 필요합니다.\n로그인 후 자동으로 진행됩니다.';
      });
      _waitingStarted   = false;
      _redirectAttempts = 0;
      return;
    }

    if (NaverPublisher.isEditorUrl(url)) {
      if (_waitingStarted) return;
      _waitingStarted   = true;
      _redirectAttempts = 0;
      if (_state == PublishState.loginRequired) {
        setState(() { _state = PublishState.loading; _statusMsg = '에디터 로드 중...'; });
      }
      _saveNaverCookies();
      await _waitForEditor();
      return;
    }

    // 에디터가 아닌 네이버 페이지 → URL 패턴 미매칭 가능성 있음
    // → contenteditable 요소로 에디터 여부 직접 확인
    if (url.contains('naver.com') && !_waitingStarted) {
      // JS로 contenteditable 요소 수 확인 (에디터는 보통 2개 이상)
      final ceRaw = _jsStr(await _ctrl?.evaluateJavascript(
        source: 'document.querySelectorAll("[contenteditable=\\"true\\"]").length'));
      final ceCount = int.tryParse(ceRaw) ?? 0;
      debugPrint('[onPageLoaded] non-editor url, ce=$ceCount');

      if (ceCount >= 1) {
        // contenteditable 있음 → 에디터로 간주하고 주입 시작
        _waitingStarted   = true;
        _redirectAttempts = 0;
        _saveNaverCookies();
        await _waitForEditor();
        return;
      }

      // contenteditable 없음 → 아직 에디터 아님, 재시도
      if (_redirectAttempts < 2) {
        _redirectAttempts++;
        debugPrint('[onPageLoaded] 리다이렉트 재시도 $_redirectAttempts');
        await Future.delayed(const Duration(seconds: 2));
        if (_ctrl == null || !mounted) return;
        await _ctrl!.loadUrl(urlRequest: URLRequest(
          url: WebUri('https://blog.naver.com/GoBlogWrite.naver')));
      } else {
        // 재시도 후에도 에디터 없음 → WebView 노출, 사용자 직접 이동
        if (mounted) {
          setState(() {
            _state     = PublishState.loginRequired;
            _statusMsg = '에디터 화면을 자동으로 찾지 못했습니다.\n글쓰기 화면으로 직접 이동해 주세요.';
          });
        }
      }
    }
  }

  bool _isNaverErrorPage(String url) =>
      url.contains('error.naver.com') ||
      url.contains('errorPage') ||
      url.contains('error_page') ||
      url.contains('serviceError') ||
      url.startsWith('error_page_detected:');

  // ── 쿠키 삭제 ──────────────────────────────────────────────
  Future<void> _clearNaverCookies() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_prefCookieKey);
      final mgr = CookieManager.instance();
      await mgr.deleteCookies(url: WebUri('https://blog.naver.com'));
      await mgr.deleteCookies(url: WebUri('https://naver.com'));
      debugPrint('[cookies] 삭제 완료');
    } catch (e) {
      debugPrint('[cookies] 삭제 실패: $e');
    }
  }

  // ── 에디터 준비 대기 ───────────────────────────────────────
  Future<void> _waitForEditor() async {
    String lastResult = '';
    for (int i = 0; i < 60; i++) {
      await Future.delayed(const Duration(seconds: 1));
      if (_ctrl == null || !mounted) return;

      if (i % 5 == 0) {
        final diag = _jsStr(await _ctrl!.evaluateJavascript(
            source: NaverPublisher.jsDiagnose()));
        debugPrint('[editor diag $i] $diag');
      }

      final raw = await _ctrl!.evaluateJavascript(
          source: NaverPublisher.jsIsEditorReady());
      lastResult = _jsStr(raw);
      debugPrint('[editor check $i] $lastResult');

      if (lastResult.startsWith('ready')) {
        await _doInjectAndTempSave();
        return;
      }
    }
    final finalDiag = _jsStr(await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsDiagnose()));
    _setStatus(PublishState.failed, '에디터 로드 타임아웃\n$lastResult\n$finalDiag');
  }

  // ── 주입 + 임시저장 ────────────────────────────────────────
  Future<void> _doInjectAndTempSave() async {
    if (!mounted) return;
    _setStatus(PublishState.injecting, '제목 입력 중...');

    final titleResult = _jsStr(await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsInjectTitle(widget.post.title)));
    if (titleResult != 'ok') {
      _setStatus(PublishState.failed, '제목 입력 실패\n($titleResult)');
      return;
    }
    await Future.delayed(const Duration(milliseconds: 500));

    _setStatus(PublishState.injecting, '본문 입력 중...\n(이미지 업로드 포함)');
    final bodyResult = _jsStr(await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsInjectHtml(widget.post.html)));
    debugPrint('[inject_body] $bodyResult');
    if (bodyResult == 'no_doc' || bodyResult == 'no_body_el') {
      _setStatus(PublishState.failed, '본문 입력 실패\n($bodyResult)');
      return;
    }
    await Future.delayed(const Duration(seconds: 2));
    final fallbackResult = _jsStr(await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsInjectHtmlFallback(widget.post.html)));
    debugPrint('[inject_body_fallback] $fallbackResult');

    if (widget.post.tags.isNotEmpty) {
      _setStatus(PublishState.injecting, '태그 입력 중...');
      await _ctrl!.evaluateJavascript(
          source: NaverPublisher.jsAddTags(widget.post.tags));
      await Future.delayed(const Duration(milliseconds: 500));
    }

    _setStatus(PublishState.saving, '임시저장 중...');
    final saveResult = _jsStr(await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsClickTempSave()));
    debugPrint('[temp_save] $saveResult');

    await Future.delayed(const Duration(seconds: 1));

    if (mounted) {
      setState(() {
        _state     = PublishState.editorReady;
        _statusMsg = '';
      });
      await Future.delayed(const Duration(milliseconds: 500));
      if (_ctrl != null) {
        await _ctrl!.evaluateJavascript(
            source: NaverPublisher.jsCleanupView());
      }
    }
  }

  // ── 발행 버튼 클릭 ─────────────────────────────────────────
  Future<void> _doPublish() async {
    // 배너 자동 숨김
    if (_showBanner) setState(() => _showBanner = false);

    if (_ctrl == null) return;
    final result = _jsStr(await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsClickPublish()));
    debugPrint('[publish_btn] $result');
    if (result != 'ok' && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('발행 버튼을 찾지 못했습니다.\n에디터에서 직접 발행 버튼을 눌러주세요.\n($result)'),
          backgroundColor: Colors.orange,
          duration: const Duration(seconds: 4),
        ),
      );
    }
  }

  void _setStatus(PublishState state, String msg) {
    if (mounted) setState(() { _state = state; _statusMsg = msg; });
  }
}
