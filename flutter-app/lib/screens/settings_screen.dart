import 'package:flutter/material.dart';
import '../services/api_service.dart';

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
          ],
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
