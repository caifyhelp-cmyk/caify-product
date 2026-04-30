import 'package:flutter/material.dart';
import 'package:package_info_plus/package_info_plus.dart';
import '../services/api_service.dart';
import '../services/app_logger.dart';
import '../services/update_service.dart';
import 'log_viewer_screen.dart';
import 'naver_link_screen.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  final _apiBaseCtrl  = TextEditingController();
  final _memberIdCtrl = TextEditingController();
  final _tokenCtrl    = TextEditingController();
  String _testResult  = '';
  bool _testing       = false;
  String? _blogId;
  bool _blogIdLoading = false;
  String _appVersion  = '';

  @override
  void initState() {
    super.initState();
    _loadConfig();
    PackageInfo.fromPlatform().then((info) {
      if (mounted) setState(() => _appVersion = info.version);
    });
  }

  Future<void> _loadConfig() async {
    final cfg = await ApiService.loadConfig();
    _apiBaseCtrl.text  = cfg['apiBase']  ?? '';
    _memberIdCtrl.text = cfg['memberId'] ?? '';
    _tokenCtrl.text    = cfg['apiToken'] ?? '';
    _loadBlogId();
  }

  Future<void> _loadBlogId() async {
    setState(() => _blogIdLoading = true);
    final id = await ApiService.fetchNaverBlogId();
    if (mounted) setState(() { _blogId = id; _blogIdLoading = false; });
  }

  @override
  void dispose() {
    _apiBaseCtrl.dispose();
    _memberIdCtrl.dispose();
    _tokenCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('설정'),
        backgroundColor: const Color(0xFF03C75A),
        foregroundColor: Colors.white,
        elevation: 0,
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // ── 네이버 블로그 연동 ──────────────────────────────────
            _sectionTitle('네이버 블로그 연동'),
            Card(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Container(
                          width: 36, height: 36,
                          decoration: BoxDecoration(
                            color: const Color(0xFF03C75A).withOpacity(0.1),
                            shape: BoxShape.circle,
                          ),
                          child: const Icon(Icons.person, color: Color(0xFF03C75A), size: 20),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: _blogIdLoading
                              ? const Text('로딩 중...', style: TextStyle(color: Colors.grey))
                              : Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      _blogId != null && _blogId!.isNotEmpty
                                          ? _blogId!
                                          : '연동된 계정 없음',
                                      style: TextStyle(
                                        fontSize: 15,
                                        fontWeight: FontWeight.w600,
                                        color: _blogId != null && _blogId!.isNotEmpty
                                            ? Colors.black87
                                            : Colors.grey,
                                      ),
                                    ),
                                    if (_blogId != null && _blogId!.isNotEmpty)
                                      Text(
                                        'blog.naver.com/$_blogId',
                                        style: const TextStyle(fontSize: 12, color: Colors.grey),
                                      ),
                                  ],
                                ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 14),
                    SizedBox(
                      width: double.infinity,
                      child: OutlinedButton.icon(
                        onPressed: _openNaverLink,
                        icon: const Icon(Icons.swap_horiz, size: 16),
                        label: Text(
                          _blogId != null && _blogId!.isNotEmpty ? '블로그 연동 변경' : '블로그 연동하기',
                          style: const TextStyle(fontSize: 14),
                        ),
                        style: OutlinedButton.styleFrom(
                          foregroundColor: const Color(0xFF03C75A),
                          side: const BorderSide(color: Color(0xFF03C75A)),
                          padding: const EdgeInsets.symmetric(vertical: 12),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),

            const SizedBox(height: 24),
            const Divider(),
            const SizedBox(height: 20),

            // ── 서버 연결 ───────────────────────────────────────────
            _sectionTitle('서버 연결'),
            _field('API 주소', _apiBaseCtrl, 'http://localhost:3030'),
            _field('회원 ID (member_pk)', _memberIdCtrl, '123'),
            _field('API 토큰 (없으면 비워두세요)', _tokenCtrl, '', obscure: true),
            const SizedBox(height: 24),
            Row(
              children: [
                Expanded(
                  child: ElevatedButton(
                    onPressed: _save,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF03C75A),
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                    child: const Text('저장', style: TextStyle(fontSize: 16)),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: OutlinedButton(
                    onPressed: _testing ? null : _testConnection,
                    style: OutlinedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                    child: _testing
                        ? const SizedBox(
                            height: 18, width: 18,
                            child: CircularProgressIndicator(strokeWidth: 2))
                        : const Text('연결 테스트'),
                  ),
                ),
              ],
            ),
            if (_testResult.isNotEmpty) ...[
              const SizedBox(height: 16),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: _testResult.startsWith('✅')
                      ? const Color(0xFFE6F4EA)
                      : const Color(0xFFFCE8E6),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  _testResult,
                  style: TextStyle(
                    color: _testResult.startsWith('✅')
                        ? const Color(0xFF137333)
                        : const Color(0xFFC5221F),
                    fontSize: 13,
                  ),
                ),
              ),
            ],

            const SizedBox(height: 32),
            const Divider(),
            const SizedBox(height: 16),

            // ── 개발 로그 ───────────────────────────────────────────
            _sectionTitle('개발 로그'),
            Row(
              children: [
                Expanded(
                  child: ElevatedButton.icon(
                    onPressed: () => Navigator.push(
                      context,
                      MaterialPageRoute(builder: (_) => const LogViewerScreen()),
                    ),
                    icon: const Icon(Icons.list_alt, size: 18),
                    label: Text('로그 보기 (${AppLogger.entries.length}개)'),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: Colors.black87,
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                OutlinedButton(
                  onPressed: () {
                    AppLogger.clear();
                    setState(() {});
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('로그가 초기화됐습니다')),
                    );
                  },
                  style: OutlinedButton.styleFrom(
                    padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 16),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  ),
                  child: const Text('초기화'),
                ),
              ],
            ),
            const SizedBox(height: 20),
            const Divider(),
            const SizedBox(height: 16),

            // ── 앱 버전 ──────────────────────────────────────────────
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text('앱 버전 v$_appVersion',
                    style: const TextStyle(fontSize: 13, color: Colors.grey)),
                TextButton(
                  onPressed: () => UpdateService.checkAndPrompt(context),
                  child: const Text('업데이트 확인',
                      style: TextStyle(color: Color(0xFF03C75A), fontSize: 13)),
                ),
              ],
            ),
            const SizedBox(height: 20),
          ],
        ),
      ),
    );
  }

  Future<void> _openNaverLink() async {
    final result = await Navigator.push<String>(
      context,
      MaterialPageRoute(
        builder: (_) => NaverLinkScreen(currentBlogId: _blogId),
      ),
    );
    if (result != null && result.isNotEmpty && mounted) {
      setState(() => _blogIdLoading = true);
      await ApiService.saveNaverBlogId(result);
      if (!mounted) return;
      setState(() { _blogId = result; _blogIdLoading = false; });
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('블로그 연동이 완료됐습니다: $result'),
          backgroundColor: const Color(0xFF03C75A),
        ),
      );
    }
  }

  Widget _sectionTitle(String text) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: Text(text,
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Colors.black87)),
      );

  Widget _field(String label, TextEditingController ctrl, String hint, {bool obscure = false}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: const TextStyle(fontSize: 13, color: Colors.grey)),
          const SizedBox(height: 6),
          TextField(
            controller: ctrl,
            obscureText: obscure,
            decoration: InputDecoration(
              hintText: hint,
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
              contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _save() async {
    final base = _apiBaseCtrl.text.trim();
    AppLogger.info('SETTINGS', '설정 저장: apiBase=$base memberId=${_memberIdCtrl.text.trim()}');
    await ApiService.saveConfig(
      apiBase:  base,
      memberId: _memberIdCtrl.text.trim(),
      token:    _tokenCtrl.text.trim(),
    );
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('저장되었습니다'), backgroundColor: Color(0xFF03C75A)),
      );
      Navigator.pop(context);
    }
  }

  Future<void> _testConnection() async {
    final base = _apiBaseCtrl.text.trim();
    AppLogger.info('SETTINGS', '연결 테스트: $base');
    setState(() { _testing = true; _testResult = ''; });
    try {
      final posts = await ApiService.fetchPosts();
      AppLogger.info('SETTINGS', '연결 성공 — 포스팅 ${posts.length}개');
      setState(() { _testResult = '✅ 연결 성공 — 포스팅 ${posts.length}개 조회됨'; });
    } catch (e) {
      AppLogger.error('SETTINGS', '연결 실패: $e');
      setState(() { _testResult = '❌ 연결 실패: $e'; });
    } finally {
      setState(() => _testing = false);
    }
  }
}
