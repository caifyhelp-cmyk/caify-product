import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../models/post.dart';
import '../services/naver_publisher.dart';
import '../services/app_logger.dart';

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
        AppLogger.log('publish','[cookies] ${all.length}개 저장됨');
      }
    } catch (e) {
      AppLogger.log('publish','[cookies] 저장 실패: $e');
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
      AppLogger.log('publish','[cookies] ${list.length}개 복원됨');
    } catch (e) {
      AppLogger.log('publish','[cookies] 복원 실패: $e');
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
                AppLogger.log('publish','[loadStop] url=$u body=${body.substring(0, body.length.clamp(0,100))}');
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
    AppLogger.log('publish','[onPageLoaded] $url');

    // Naver 에러 페이지 감지 → 1회만 쿠키 삭제 후 재시도, 이후엔 WebView 노출
    if (_isNaverErrorPage(url)) {
      AppLogger.log('publish','[onPageLoaded] 에러 페이지 감지 (시도 $_errorAttempts)');
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

    // isEditorUrl 미매칭 naver.com 페이지
    // → SE3 JS 초기화 시간 필요 → 3초 대기 후 _waitForEditor로 진입
    if (url.contains('naver.com') && !_waitingStarted) {
      AppLogger.log('publish','[onPageLoaded] non-editor url, waiting 3s for SE3 init...');
      _waitingStarted = true;
      _redirectAttempts = 0;
      _saveNaverCookies();
      // SE3 JavaScript 초기화 대기
      await Future.delayed(const Duration(seconds: 3));
      if (_ctrl == null || !mounted) return;
      await _waitForEditor();
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
      AppLogger.log('publish','[cookies] 삭제 완료');
    } catch (e) {
      AppLogger.log('publish','[cookies] 삭제 실패: $e');
    }
  }

  // ── 에디터 준비 대기 ───────────────────────────────────────
  Future<void> _waitForEditor() async {
    String lastResult = '';
    AppLogger.log('editor', '에디터 대기 시작');
    for (int i = 0; i < 60; i++) {
      await Future.delayed(const Duration(seconds: 1));
      if (_ctrl == null || !mounted) return;

      final raw = await _ctrl!.evaluateJavascript(
          source: NaverPublisher.jsIsEditorReady());
      lastResult = _jsStr(raw);
      AppLogger.log('ready[$i]', lastResult);

      // 매 5초마다 상세 진단
      if (i % 5 == 0) {
        final diag = _jsStr(await _ctrl!.evaluateJavascript(
            source: NaverPublisher.jsDiagnose()));
        AppLogger.log('diag[$i]', diag);
        _setStatus(PublishState.loading, '에디터 로드 중... (${i}s)\n$diag');
      }

      if (lastResult.startsWith('ready')) {
        AppLogger.log('editor', '준비 완료: $lastResult');
        await _doInjectAndTempSave();
        return;
      }
    }
    final finalDiag = _jsStr(await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsDiagnose()));
    AppLogger.log('editor', '타임아웃 | ready=$lastResult | diag=$finalDiag');
    _setStatus(PublishState.failed, '타임아웃\nready체크: $lastResult\n진단: $finalDiag');
  }

  // ── 주입 + 임시저장 ────────────────────────────────────────
  Future<void> _doInjectAndTempSave() async {
    if (!mounted) return;

    // 제목 주입 (실패해도 계속 진행)
    _setStatus(PublishState.injecting, '제목 입력 중...');
    final titleResult = _jsStr(await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsInjectTitle(widget.post.title)));
    AppLogger.log('publish','[inject_title] $titleResult');
    await Future.delayed(const Duration(milliseconds: 500));

    // 본문 주입 (실패해도 계속 진행)
    _setStatus(PublishState.injecting, '본문 입력 중...');
    final bodyResult = _jsStr(await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsInjectHtml(widget.post.html)));
    AppLogger.log('publish','[inject_body] $bodyResult');

    // 2초 후 fallback (paste가 안 됐을 경우 execCommand/innerHTML 시도)
    await Future.delayed(const Duration(seconds: 2));
    final fallbackResult = _jsStr(await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsInjectHtmlFallback(widget.post.html)));
    AppLogger.log('publish','[inject_body_fallback] $fallbackResult');

    // 이미지 라이브러리 업로드 (HTML에서 img URL 추출 → blob paste)
    final imgUrls = _extractImageUrls(widget.post.html);
    if (imgUrls.isNotEmpty) {
      _setStatus(PublishState.injecting, '이미지 업로드 중...');
      for (final url in imgUrls) {
        final result = await _injectImageFromUrl(url);
        AppLogger.log('publish','[inject_img] $url → $result');
        await Future.delayed(const Duration(seconds: 2)); // SE3 업로드 대기
      }
    }

    // 태그 주입 (실패해도 계속 진행)
    if (widget.post.tags.isNotEmpty) {
      _setStatus(PublishState.injecting, '태그 입력 중...');
      final tagResult = _jsStr(await _ctrl!.evaluateJavascript(
          source: NaverPublisher.jsAddTags(widget.post.tags)));
      AppLogger.log('publish','[inject_tags] $tagResult');
      await Future.delayed(const Duration(milliseconds: 800));
    }

    // 임시저장 시도 (실패해도 계속 진행)
    _setStatus(PublishState.saving, '임시저장 중...');
    final saveResult = _jsStr(await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsClickTempSave()));
    AppLogger.log('publish','[temp_save] $saveResult');

    await Future.delayed(const Duration(seconds: 1));

    // 주입 성공 여부와 무관하게 항상 editorReady로 전환 (사용자가 직접 수정 가능)
    if (mounted) {
      setState(() {
        _state     = PublishState.editorReady;
        _statusMsg = '';
      });
      await Future.delayed(const Duration(milliseconds: 500));
      if (_ctrl != null) {
        await _ctrl!.evaluateJavascript(source: NaverPublisher.jsCleanupView());
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
    AppLogger.log('publish','[publish_btn] $result');
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

  // ── HTML에서 img src URL 추출 ──────────────────────────────
  List<String> _extractImageUrls(String html) {
    final pattern = RegExp(r'<img[^>]+src=["\']([^"\']+)["\']', caseSensitive: false);
    return pattern.allMatches(html)
        .map((m) => m.group(1)!)
        .where((url) => url.startsWith('http'))
        .toList();
  }

  // ── 이미지 URL → Flutter 다운로드 → base64 → SE3 blob paste ─
  Future<String> _injectImageFromUrl(String url) async {
    try {
      final res = await http.get(Uri.parse(url))
          .timeout(const Duration(seconds: 10));
      if (res.statusCode != 200) return 'http_${res.statusCode}';

      final mimeType = res.headers['content-type'] ?? 'image/jpeg';
      final base64Data = base64Encode(res.bodyBytes);

      if (_ctrl == null || !mounted) return 'no_ctrl';
      final jsResult = _jsStr(await _ctrl!.evaluateJavascript(
          source: NaverPublisher.jsInjectImageBlob(base64Data, mimeType)));
      return jsResult;
    } catch (e) {
      return 'err:$e';
    }
  }

  void _setStatus(PublishState state, String msg) {
    if (mounted) setState(() { _state = state; _statusMsg = msg; });
  }
}
