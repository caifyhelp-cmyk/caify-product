import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import '../models/post.dart';
import '../services/api_service.dart';
import '../services/naver_publisher.dart';

enum PublishState { loading, loginRequired, injecting, saving, done, failed }

class PublishScreen extends StatefulWidget {
  final Post post;
  const PublishScreen({super.key, required this.post});

  @override
  State<PublishScreen> createState() => _PublishScreenState();
}

class _PublishScreenState extends State<PublishScreen> {
  InAppWebViewController? _ctrl;
  PublishState _state = PublishState.loading;
  String _statusMsg = '에디터 로드 중...';
  bool _webViewVisible = false;

  // 중복 실행 방지 플래그
  bool _waitingStarted = false;

  // evaluateJavascript 결과를 안전하게 문자열로 변환
  // (dynamic 타입으로 반환되므로 null-safe + toString() 사용)
  static String _jsStr(dynamic result) => result?.toString() ?? '';

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('발행 중'),
        backgroundColor: Colors.white,
        foregroundColor: Colors.black87,
        elevation: 0,
        leading: _state == PublishState.done || _state == PublishState.failed
            ? IconButton(
                icon: const Icon(Icons.close),
                onPressed: () => Navigator.pop(context),
              )
            : const SizedBox.shrink(),
        automaticallyImplyLeading: false,
      ),
      body: Stack(
        children: [
          // ── Naver 에디터 WebView ──────────────────────────────
          Opacity(
            opacity: _webViewVisible ? 1.0 : 0.0,
            child: InAppWebView(
              initialUrlRequest: URLRequest(
                url: WebUri('https://blog.naver.com/GoBlogWrite.naver'),
              ),
              initialSettings: InAppWebViewSettings(
                javaScriptEnabled: true,
                domStorageEnabled: true,
                sharedCookiesEnabled: true,
                userAgent:
                    'Mozilla/5.0 (Linux; Android 13; SM-G991B) '
                    'AppleWebKit/537.36 (KHTML, like Gecko) '
                    'Chrome/120.0.0.0 Mobile Safari/537.36',
              ),
              onWebViewCreated: (ctrl) => _ctrl = ctrl,
              onLoadStop: (ctrl, url) => _onPageLoaded(url?.toString() ?? ''),
              onReceivedError: (ctrl, req, err) {
                if (err.type.toString().contains('ERR_INTERNET_DISCONNECTED')) {
                  _setStatus(PublishState.failed, '인터넷 연결을 확인해 주세요.');
                }
              },
            ),
          ),

          // ── 상태 오버레이 ─────────────────────────────────────
          if (!_webViewVisible || _state != PublishState.loginRequired)
            _buildOverlay(),
        ],
      ),
    );
  }

  Widget _buildOverlay() {
    final isError = _state == PublishState.failed;
    final isDone  = _state == PublishState.done;

    return Container(
      color: Colors.white,
      child: Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (!isDone && !isError)
                const CircularProgressIndicator(color: Color(0xFF03C75A)),
              if (isDone)
                const Icon(Icons.check_circle, color: Color(0xFF03C75A), size: 64),
              if (isError)
                const Icon(Icons.error_outline, color: Colors.red, size: 64),
              const SizedBox(height: 24),
              Text(
                _statusMsg,
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 16,
                  color: isError ? Colors.red : Colors.black87,
                ),
              ),
              if (isDone || isError) ...[
                const SizedBox(height: 32),
                ElevatedButton(
                  onPressed: () => Navigator.pop(context, isDone),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: isDone ? const Color(0xFF03C75A) : Colors.grey,
                    padding: const EdgeInsets.symmetric(horizontal: 40, vertical: 14),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  ),
                  child: Text(isDone ? '완료' : '닫기',
                      style: const TextStyle(color: Colors.white, fontSize: 16)),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  // ── 페이지 로드 완료 이벤트 ───────────────────────────────────
  Future<void> _onPageLoaded(String url) async {
    if (url.isEmpty) return;

    // 로그인 페이지 감지
    if (NaverPublisher.isLoginUrl(url)) {
      setState(() {
        _webViewVisible = true;
        _state = PublishState.loginRequired;
        _statusMsg = '네이버 로그인이 필요합니다.\n아래에서 로그인 후 자동으로 발행됩니다.';
      });
      _waitingStarted = false; // 로그인 후 재시도 허용
      return;
    }

    // blog.naver.com 이 아닌 리다이렉트는 무시
    if (!url.contains('blog.naver.com')) return;

    // 이미 대기 시작했으면 중복 실행 방지
    if (_waitingStarted) return;
    _waitingStarted = true;

    // 로그인 완료 후 에디터 페이지
    if (_state == PublishState.loginRequired) {
      setState(() {
        _webViewVisible = false;
        _state = PublishState.loading;
        _statusMsg = '에디터 로드 중...';
      });
    }

    await _waitForEditor();
  }

  // ── 에디터 준비 대기 (최대 30초 폴링) ─────────────────────────
  Future<void> _waitForEditor() async {
    for (int i = 0; i < 30; i++) {
      await Future.delayed(const Duration(seconds: 1));
      if (_ctrl == null || !mounted) return;

      final raw = await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsIsEditorReady(),
      );
      final result = _jsStr(raw);
      debugPrint('[editor] poll $i: $result');

      if (result == 'ready') {
        await _doInject();
        return;
      }
    }
    _setStatus(PublishState.failed, '에디터 로드 타임아웃 (30초)\n앱을 다시 시도해 주세요.');
  }

  // ── 내용 주입 + 저장 ─────────────────────────────────────────
  Future<void> _doInject() async {
    if (!mounted) return;
    _setStatus(PublishState.injecting, '포스팅 내용 입력 중...');

    // 제목 주입
    final titleRaw = await _ctrl!.evaluateJavascript(
      source: NaverPublisher.jsInjectTitle(widget.post.title),
    );
    final titleResult = _jsStr(titleRaw);
    debugPrint('[inject] title: $titleResult');
    if (titleResult != 'ok') {
      _setStatus(PublishState.failed, '제목 입력 실패\n($titleResult)\n\n에디터가 완전히 로드된 후 다시 시도해 주세요.');
      return;
    }

    await Future.delayed(const Duration(milliseconds: 500));

    // 본문 HTML 주입
    final bodyRaw = await _ctrl!.evaluateJavascript(
      source: NaverPublisher.jsInjectHtml(widget.post.html),
    );
    final bodyResult = _jsStr(bodyRaw);
    debugPrint('[inject] body: $bodyResult');
    if (bodyResult != 'ok') {
      _setStatus(PublishState.failed, '본문 입력 실패\n($bodyResult)');
      return;
    }

    // SE3 HTML 파싱 대기
    await Future.delayed(const Duration(seconds: 2));

    _setStatus(PublishState.saving, '저장 중...');

    // 저장 버튼 클릭
    final saveRaw = await _ctrl!.evaluateJavascript(
      source: NaverPublisher.jsSave(),
    );
    final saveResult = _jsStr(saveRaw);
    debugPrint('[inject] save: $saveResult');
    if (saveResult != 'ok') {
      _setStatus(PublishState.failed, '저장 버튼을 찾지 못했습니다\n($saveResult)');
      return;
    }

    // 저장 완료 대기
    await Future.delayed(const Duration(seconds: 3));

    // API 완료 통보
    await ApiService.markPublished(widget.post.id);

    if (mounted) {
      _setStatus(PublishState.done, "'${widget.post.title}'\n발행이 완료되었습니다!");
    }
  }

  void _setStatus(PublishState state, String msg) {
    if (mounted) setState(() { _state = state; _statusMsg = msg; });
  }
}
