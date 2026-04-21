import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/app_logger.dart';

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

  @override
  void initState() {
    super.initState();
    _loadConfig();
  }

  Future<void> _loadConfig() async {
    final cfg = await ApiService.loadConfig();
    _apiBaseCtrl.text  = cfg['apiBase']  ?? '';
    _memberIdCtrl.text = cfg['memberId'] ?? '';
    _tokenCtrl.text    = cfg['apiToken'] ?? '';
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
            _sectionTitle('서버 연결'),
            _field('API 주소', _apiBaseCtrl, 'http://localhost:4000'),
            _field('회원 ID (member_pk)', _memberIdCtrl, '123'),
            _field('API 토큰 (없으면 비워두세요)', _tokenCtrl, '',
                obscure: true),
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
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(8)),
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
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(8)),
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
            _sectionTitle('개발 로그'),
            Row(
              children: [
                Expanded(
                  child: ElevatedButton.icon(
                    onPressed: () => _showLogViewer(context),
                    icon: const Icon(Icons.list_alt, size: 18),
                    label: Text('로그 보기 (${AppLogger.entries.length}개)'),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: Colors.black87,
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(8)),
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
          ],
        ),
      ),
    );
  }

  void _showLogViewer(BuildContext context) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => DraggableScrollableSheet(
        initialChildSize: 0.85,
        maxChildSize: 0.95,
        minChildSize: 0.4,
        builder: (_, scrollCtrl) => Container(
          decoration: const BoxDecoration(
            color: Color(0xFF1E1E1E),
            borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
          ),
          child: Column(
            children: [
              // 핸들
              Container(
                margin: const EdgeInsets.symmetric(vertical: 10),
                width: 40, height: 4,
                decoration: BoxDecoration(
                  color: Colors.white24,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              // 헤더
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Row(
                  children: [
                    const Text('발행 로그', style: TextStyle(
                        color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
                    const Spacer(),
                    TextButton.icon(
                      onPressed: () async {
                        await AppLogger.copyToClipboard();
                        if (context.mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(
                              content: Text('로그가 클립보드에 복사됐습니다'),
                              backgroundColor: Color(0xFF03C75A),
                            ),
                          );
                        }
                      },
                      icon: const Icon(Icons.copy, size: 16, color: Color(0xFF03C75A)),
                      label: const Text('전체 복사',
                          style: TextStyle(color: Color(0xFF03C75A), fontSize: 13)),
                    ),
                  ],
                ),
              ),
              const Divider(color: Colors.white12),
              // 로그 목록
              Expanded(
                child: AppLogger.entries.isEmpty
                    ? const Center(
                        child: Text('로그 없음\n발행 화면을 열면 로그가 쌓입니다',
                            textAlign: TextAlign.center,
                            style: TextStyle(color: Colors.white38, fontSize: 13)))
                    : ListView.builder(
                        controller: scrollCtrl,
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                        itemCount: AppLogger.entries.length,
                        reverse: true,
                        itemBuilder: (_, i) {
                          final entries = AppLogger.entries;
                          final e = entries[entries.length - 1 - i];
                          final t = e.toString();
                          Color color = Colors.white70;
                          if (t.contains('[diag') || t.contains('[ready')) {
                            color = const Color(0xFF80CBC4);
                          } else if (t.contains('ok_') || t.contains('already_filled') || t.contains('ready')) {
                            color = const Color(0xFF81C784);
                          } else if (t.contains('no_') || t.contains('failed') || t.contains('err')) {
                            color = const Color(0xFFEF9A9A);
                          }
                          return Padding(
                            padding: const EdgeInsets.symmetric(vertical: 2),
                            child: Text(t,
                                style: TextStyle(
                                    fontFamily: 'monospace',
                                    fontSize: 11,
                                    color: color,
                                    height: 1.4)),
                          );
                        },
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _sectionTitle(String text) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: Text(text,
            style: const TextStyle(
                fontWeight: FontWeight.bold, fontSize: 16, color: Colors.black87)),
      );

  Widget _field(String label, TextEditingController ctrl, String hint,
      {bool obscure = false}) {
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
              contentPadding:
                  const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _save() async {
    await ApiService.saveConfig(
      apiBase:  _apiBaseCtrl.text.trim(),
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
    setState(() { _testing = true; _testResult = ''; });
    try {
      final posts = await ApiService.fetchPosts();
      setState(() {
        _testResult = '✅ 연결 성공 — 포스팅 ${posts.length}개 조회됨';
      });
    } catch (e) {
      setState(() { _testResult = '❌ 연결 실패: $e'; });
    } finally {
      setState(() => _testing = false);
    }
  }
}
