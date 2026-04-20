import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import '../models/post.dart';
import '../services/api_service.dart';
import '../services/naver_publisher.dart';

// ready       : 에디터 로드 중
// loginRequired: 네이버 로그인 필요 — WebView 노출
// injecting   : 제목/본문/태그 입력 중
// saving      : 임시저장 중
// editorReady : 임시저장 완료 — 에디터 고객에게 노출, 발행은 직접
// failed      : 오류
enum PublishState { loading, loginRequired, injecting, saving, editorReady, failed }

class PublishScreen extends StatefulWidget {
  final Post post;

  const PublishScreen({super.key, required this.post});

  @override
  State<PublishScreen> createState() => _PublishScreenState();
}

class _PublishScreenState extends State<PublishScreen> {
  InAppWebViewController? _ctrl;
  PublishState _state = PublishState.loading;
  String _statusMsg   = '에디터 로드 중...';
  bool _waitingStarted = false;

  static String _jsStr(dynamic r) => r?.toString() ?? '';

  bool get _showWebView =>
      _state == PublishState.loginRequired ||
      _state == PublishState.editorReady;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_state == PublishState.editorReady
            ? '에디터 확인 후 발행 버튼을 눌러주세요'
            : '발행 준비 중'),
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
      ),
      body: Stack(
        children: [
          // WebView — 로그인 필요 시 또는 임시저장 완료 후 표시
          Opacity(
            opacity: _showWebView ? 1.0 : 0.0,
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
          // 오버레이 — 진행 중 / 오류 화면
          if (!_showWebView) _buildOverlay(),

          // 임시저장 완료 안내 배너 (에디터 위에 표시)
          if (_state == PublishState.editorReady)
            Positioned(
              top: 0, left: 0, right: 0,
              child: Container(
                color: const Color(0xFF03C75A),
                padding: const EdgeInsets.symmetric(
                    horizontal: 16, vertical: 10),
                child: const Row(
                  children: [
                    Icon(Icons.check_circle, color: Colors.white, size: 18),
                    SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        '내용이 입력됐습니다. 확인 후 오른쪽 위 발행 버튼을 눌러주세요.',
                        style: TextStyle(color: Colors.white, fontSize: 13),
                      ),
                    ),
                  ],
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
                  color: isError ? Colors.red : Colors.black87,
                ),
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

    if (NaverPublisher.isLoginUrl(url)) {
      setState(() {
        _state      = PublishState.loginRequired;
        _statusMsg  = '네이버 로그인이 필요합니다.\n로그인 후 자동으로 진행됩니다.';
      });
      _waitingStarted = false;
      return;
    }

    if (!url.contains('blog.naver.com')) return;
    if (_waitingStarted) return;
    _waitingStarted = true;

    if (_state == PublishState.loginRequired) {
      setState(() {
        _state     = PublishState.loading;
        _statusMsg = '에디터 로드 중...';
      });
    }

    await _waitForEditor();
  }

  // ── 에디터 준비 대기 ───────────────────────────────────────
  Future<void> _waitForEditor() async {
    for (int i = 0; i < 30; i++) {
      await Future.delayed(const Duration(seconds: 1));
      if (_ctrl == null || !mounted) return;
      final raw = await _ctrl!.evaluateJavascript(
          source: NaverPublisher.jsIsEditorReady());
      if (_jsStr(raw) == 'ready') {
        await _doInjectAndTempSave();
        return;
      }
    }
    _setStatus(PublishState.failed, '에디터 로드 타임아웃\n앱을 다시 시도해 주세요.');
  }

  // ── 주입 + 임시저장 ────────────────────────────────────────
  Future<void> _doInjectAndTempSave() async {
    if (!mounted) return;
    _setStatus(PublishState.injecting, '제목 입력 중...');

    // 1. 제목
    final titleResult = _jsStr(await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsInjectTitle(widget.post.title)));
    if (titleResult != 'ok') {
      _setStatus(PublishState.failed, '제목 입력 실패\n($titleResult)');
      return;
    }
    await Future.delayed(const Duration(milliseconds: 500));

    // 2. 본문 (이미지 포함 HTML — SE3가 자동으로 라이브러리에 업로드)
    _setStatus(PublishState.injecting, '본문 입력 중...\n(이미지 업로드 포함)');
    final bodyResult = _jsStr(await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsInjectHtml(widget.post.html)));
    if (bodyResult != 'ok') {
      _setStatus(PublishState.failed, '본문 입력 실패\n($bodyResult)');
      return;
    }
    await Future.delayed(const Duration(seconds: 2));

    // 3. 태그 (있는 경우)
    if (widget.post.tags.isNotEmpty) {
      _setStatus(PublishState.injecting, '태그 입력 중...');
      await _ctrl!.evaluateJavascript(
          source: NaverPublisher.jsAddTags(widget.post.tags));
      await Future.delayed(const Duration(milliseconds: 500));
    }

    // 4. 임시저장
    _setStatus(PublishState.saving, '임시저장 중...');
    final saveResult = _jsStr(await _ctrl!.evaluateJavascript(
        source: NaverPublisher.jsClickTempSave()));
    debugPrint('[temp_save] $saveResult');

    await Future.delayed(const Duration(seconds: 1));

    // 5. 에디터 고객에게 노출 — 발행은 고객이 직접
    if (mounted) {
      setState(() {
        _state     = PublishState.editorReady;
        _statusMsg = '';
      });
    }
  }

  void _setStatus(PublishState state, String msg) {
    if (mounted) setState(() { _state = state; _statusMsg = msg; });
  }
}
