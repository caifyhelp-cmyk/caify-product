import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// 네이버 계정 연동 화면
/// - WebView로 네이버 로그인
/// - 로그인 감지 → 쿠키 저장 → 블로그 ID 확인
/// - 반환값: 사용자가 입력/확인한 블로그 ID (null = 취소)
class NaverLinkScreen extends StatefulWidget {
  final String? currentBlogId;

  const NaverLinkScreen({super.key, this.currentBlogId});

  @override
  State<NaverLinkScreen> createState() => _NaverLinkScreenState();
}

enum _NaverLinkState { login, detecting, confirm }

class _NaverLinkScreenState extends State<NaverLinkScreen> {
  InAppWebViewController? _ctrl;
  _NaverLinkState _stage = _NaverLinkState.login;
  final _blogIdCtrl = TextEditingController();
  bool _saving = false;

  static const _prefCookieKey = 'naver_cookies_v1';

  static const _loginUrl = 'https://nid.naver.com/nidlogin.login';
  static const _blogUrl  = 'https://blog.naver.com';

  @override
  void initState() {
    super.initState();
    _blogIdCtrl.text = widget.currentBlogId ?? '';
    _clearNaverCookies();
  }

  @override
  void dispose() {
    _blogIdCtrl.dispose();
    super.dispose();
  }

  Future<void> _clearNaverCookies() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_prefCookieKey);
    final mgr = CookieManager.instance();
    await mgr.deleteCookies(url: WebUri('https://naver.com'));
    await mgr.deleteCookies(url: WebUri('https://nid.naver.com'));
    await mgr.deleteCookies(url: WebUri('https://blog.naver.com'));
  }

  Future<void> _saveNaverCookies() async {
    try {
      final mgr = CookieManager.instance();
      final urls = [WebUri('https://naver.com'), WebUri('https://blog.naver.com')];
      final all = <Map<String, dynamic>>[];
      for (final url in urls) {
        final cookies = await mgr.getCookies(url: url);
        for (final c in cookies) {
          all.add({
            'url': url.toString(), 'name': c.name, 'value': c.value,
            'domain': c.domain ?? '', 'path': c.path ?? '/',
            'isSecure': c.isSecure ?? true,
          });
        }
      }
      if (all.isNotEmpty) {
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString(_prefCookieKey, jsonEncode(all));
      }
    } catch (_) {}
  }

  Future<String?> _extractBlogId() async {
    if (_ctrl == null) return null;
    try {
      // 네이버 블로그 홈에서 내 블로그 링크 추출
      final raw = await _ctrl!.evaluateJavascript(source: '''
        (function() {
          var a = document.querySelector('a[href*="blog.naver.com/"]');
          if (a) {
            var m = a.href.match(/blog\\.naver\\.com\\/([^\\/?&"]+)/);
            if (m && m[1] !== 'GoBlogWrite' && m[1] !== 'buddy' && m[1] !== 'search')
              return m[1];
          }
          var meta = document.querySelector('meta[property="og:url"]');
          if (meta) {
            var m2 = meta.content.match(/blog\\.naver\\.com\\/([^\\/?&]+)/);
            if (m2) return m2[1];
          }
          return '';
        })()
      ''');
      final id = raw?.toString().replaceAll('"', '').trim() ?? '';
      return id.isNotEmpty ? id : null;
    } catch (_) {
      return null;
    }
  }

  Future<void> _onPageLoaded(String url) async {
    if (_stage == _NaverLinkState.login) {
      final isLoginPage = url.contains('nid.naver.com') ||
          url.contains('naver.com/nidlogin') ||
          url.contains('naver.com/login');
      if (!isLoginPage && url.contains('naver.com')) {
        // 로그인 완료 감지
        await _saveNaverCookies();
        if (!mounted) return;
        setState(() => _stage = _NaverLinkState.detecting);
        // blog.naver.com으로 이동해서 블로그 ID 추출 시도
        await _ctrl?.loadUrl(urlRequest: URLRequest(url: WebUri(_blogUrl)));
      }
    } else if (_stage == _NaverLinkState.detecting) {
      if (url.contains('blog.naver.com')) {
        await Future.delayed(const Duration(seconds: 2));
        final detected = await _extractBlogId();
        if (!mounted) return;
        if (detected != null && _blogIdCtrl.text.isEmpty) {
          _blogIdCtrl.text = detected;
        }
        setState(() => _stage = _NaverLinkState.confirm);
      }
    }
  }

  void _confirm() {
    final id = _blogIdCtrl.text.trim();
    if (id.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('블로그 ID를 입력해 주세요.')),
      );
      return;
    }
    Navigator.pop(context, id);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(
          _stage == _NaverLinkState.login ? '네이버 로그인' : '블로그 연동',
          style: const TextStyle(fontSize: 15),
        ),
        backgroundColor: const Color(0xFF03C75A),
        foregroundColor: Colors.white,
        elevation: 0,
      ),
      body: Stack(
        children: [
          // WebView (로그인 + 블로그 탐색 단계)
          Opacity(
            opacity: _stage == _NaverLinkState.confirm ? 0 : 1,
            child: InAppWebView(
              initialUrlRequest: URLRequest(url: WebUri(_loginUrl)),
              initialSettings: InAppWebViewSettings(
                javaScriptEnabled: true,
                domStorageEnabled: true,
                sharedCookiesEnabled: true,
                userAgent:
                    'Mozilla/5.0 (Linux; Android 13) '
                    'AppleWebKit/537.36 (KHTML, like Gecko) '
                    'Chrome/120.0.0.0 Mobile Safari/537.36',
              ),
              onWebViewCreated: (ctrl) => _ctrl = ctrl,
              onLoadStop: (_, url) => _onPageLoaded(url?.toString() ?? ''),
            ),
          ),

          // 탐색 중 오버레이
          if (_stage == _NaverLinkState.detecting)
            Container(
              color: Colors.white70,
              child: const Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    CircularProgressIndicator(color: Color(0xFF03C75A)),
                    SizedBox(height: 16),
                    Text('블로그 정보를 확인하는 중...'),
                  ],
                ),
              ),
            ),

          // 확인 단계
          if (_stage == _NaverLinkState.confirm)
            Container(
              color: Colors.white,
              padding: const EdgeInsets.all(28),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.check_circle, color: Color(0xFF03C75A), size: 48),
                  const SizedBox(height: 16),
                  const Text(
                    '네이버 로그인 완료!',
                    style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 8),
                  const Text(
                    '아래 블로그 ID를 확인하거나 직접 입력해 주세요.',
                    style: TextStyle(color: Colors.grey, fontSize: 13),
                  ),
                  const SizedBox(height: 28),
                  const Text(
                    '네이버 블로그 ID',
                    style: TextStyle(fontSize: 13, color: Colors.grey),
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: _blogIdCtrl,
                    decoration: InputDecoration(
                      hintText: 'myblog123',
                      helperText: 'blog.naver.com/ 뒤에 오는 영문+숫자 ID',
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    ),
                  ),
                  const Spacer(),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: _saving ? null : _confirm,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF03C75A),
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 16),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      ),
                      child: _saving
                          ? const SizedBox(
                              width: 20, height: 20,
                              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                          : const Text('연동 완료', style: TextStyle(fontSize: 16)),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
