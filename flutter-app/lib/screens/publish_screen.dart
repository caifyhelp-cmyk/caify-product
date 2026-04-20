import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import '../models/post.dart';
import '../services/api_service.dart';
import '../services/naver_publisher.dart';

enum PublishState { loading, loginRequired, injecting, saving, done, failed }

class PublishScreen extends StatefulWidget {
  final Post post;

  /// autoMode: true면 완료/실패 시 자동으로 pop (버튼 탭 불필요)
  final bool autoMode;

  const PublishScreen({super.key, required this.post, this.autoMode = false});

  @override
  State<PublishScreen> createState() => _PublishScreenState();
}

class _PublishScreenState extends State<PublishScreen> {
  InAppWebViewController? _ctrl;
  PublishState _state = PublishState.loading;
  String _statusMsg = '에디터 로드 중...';
  bool _webViewVisible = false;
  bool _waitingStarted = false;

  static String _jsStr(dynamic result) => result?.toString() ?? '';

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.autoMode ? '자동 발행 중' : '발행 중'),
        backgroundColor: Colors.white,
        foregroundColor: Colors.black87,
        elevation: 0,
        leading: (_state == PublishState.done || _state == PublishState.failed) && !widget.autoMode
            ? IconButton(
                icon: const Icon(Icons.close),
                onPressed: () => Navigator.pop(context),
              )
            : const SizedBox.shrink(),
        automaticallyImplyLeading: false,
      ),
      body: Stack(
        children: [
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
              // autoMode: 완료/실패 후 자동 pop, 버튼 표시 안 함
              if ((isDone || isError) && !widget.autoMode) ...[
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

  Future<void> _onPageLoaded(String url) async {
    if (url.isEmpty) return;

    if (NaverPublisher.isLoginUrl(url)) {
      setState(() {
        _webViewVisible = true;
        _state = PublishState.loginRequired;
        _statusMsg = '네이버 로그인이 필요합니다.\n아래에서 로그인 후 자동으로 발행됩니다.';
      });
      _waitingStarted = false;
      return;
    }

    if (!url.contains('blog.naver.com')) return;
    if (_waitingStarted) return;
    _waitingStarted = true;

    if (_state == PublishState.loginRequired) {
      setState(() {
        _webViewVisible = false;
        _state = PublishState.loading;
        _statusMsg = '에디터 로드 중...';
      });
    }

    await _waitForEditor();
  }

  Future<void> _waitForEditor() async {
    for (int i = 0; i < 30; i++) {
      await Future.delayed(const Duration(seconds: 1));
      if (_ctrl == null || !mounted) return;

      final raw = await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsIsEditorReady(),
      );
      if (_jsStr(raw) == 'ready') {
        await _doInjectAndPublish();
        return;
      }
    }
    _setStatus(PublishState.failed, '에디터 로드 타임아웃 (30초)\n앱을 다시 시도해 주세요.');
  }

  Future<void> _doInjectAndPublish() async {
    if (!mounted) return;
    _setStatus(PublishState.injecting, '포스팅 내용 입력 중...');

    // 제목 주입
    final titleResult = _jsStr(await _ctrl!.evaluateJavascript(
      source: NaverPublisher.jsInjectTitle(widget.post.title),
    ));
    debugPrint('[inject] title: $titleResult');
    if (titleResult != 'ok') {
      _setStatus(PublishState.failed, '제목 입력 실패\n($titleResult)');
      _autoPop(false);
      return;
    }

    await Future.delayed(const Duration(milliseconds: 500));

    // 본문 주입
    final bodyResult = _jsStr(await _ctrl!.evaluateJavascript(
      source: NaverPublisher.jsInjectHtml(widget.post.html),
    ));
    debugPrint('[inject] body: $bodyResult');
    if (bodyResult != 'ok') {
      _setStatus(PublishState.failed, '본문 입력 실패\n($bodyResult)');
      _autoPop(false);
      return;
    }

    await Future.delayed(const Duration(seconds: 2));

    _setStatus(PublishState.saving, '발행 중...');

    // 발행 버튼 클릭
    final publishResult = _jsStr(await _ctrl!.evaluateJavascript(
      source: NaverPublisher.jsClickPublish(),
    ));
    debugPrint('[publish_btn] $publishResult');
    if (!publishResult.startsWith('ok')) {
      _setStatus(PublishState.failed, '발행 버튼을 찾지 못했습니다\n($publishResult)');
      _autoPop(false);
      return;
    }

    // 발행 확인 패널 대기 후 클릭 (최대 5초)
    for (int i = 0; i < 5; i++) {
      await Future.delayed(const Duration(seconds: 1));
      if (_ctrl == null || !mounted) return;
      final confirmResult = _jsStr(await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsConfirmPublish(),
      ));
      debugPrint('[confirm] $confirmResult');
      if (confirmResult == 'ok') break;
      // 'not_found' 이면 패널 없이 바로 발행된 것 — 계속 진행
    }

    // 발행 완료 대기
    await Future.delayed(const Duration(seconds: 3));

    await ApiService.markPublished(widget.post.id);

    if (mounted) {
      _setStatus(PublishState.done, "'${widget.post.title}'\n발행이 완료되었습니다!");
      _autoPop(true);
    }
  }

  /// autoMode일 때 일정 시간 후 자동으로 화면 닫기
  void _autoPop(bool success) {
    if (!widget.autoMode) return;
    Future.delayed(const Duration(seconds: 2), () {
      if (mounted) Navigator.pop(context, success);
    });
  }

  void _setStatus(PublishState state, String msg) {
    if (mounted) setState(() { _state = state; _statusMsg = msg; });
  }
}
