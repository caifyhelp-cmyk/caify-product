import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/app_logger.dart';
import 'case_submit_sheet.dart';

class WorkflowScreen extends StatefulWidget {
  const WorkflowScreen({super.key});

  @override
  State<WorkflowScreen> createState() => _WorkflowScreenState();
}

class _WorkflowScreenState extends State<WorkflowScreen> {
  Map<String, dynamic>? _data;
  bool _loading = true;
  bool _saving   = false;
  bool _provisioning = false;

  // 편집 상태
  late List<String> _keywords;
  late String _schedule;
  late List<Map<String, dynamic>> _workflows;

  final _kwCtrl = TextEditingController();

  static const _typeLabels = {
    'case':  '케이스형',
    'info':  '정보형',
    'promo': '프로모션형',
    'plusA': '플러스A형',
  };

  static const _scheduleOptions = [
    '매일 오전 10시',
    '월·수·금 오전 10시',
    '화·목 오전 10시',
    '주 1회 (월요일)',
    '주 1회 (금요일)',
  ];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _kwCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    AppLogger.info('WF_UI', '워크플로우 화면 로드 시작');
    setState(() => _loading = true);
    final data = await ApiService.fetchWorkflow();
    if (!mounted) return;
    setState(() {
      _data = data;
      _loading = false;
      if (data != null && data['provisioned'] == true) {
        _keywords  = List<String>.from(data['keywords'] ?? []);
        _schedule  = (data['schedule'] as String?) ?? _scheduleOptions[0];
        _workflows = List<Map<String, dynamic>>.from(
          (data['workflows'] as List?)?.map((e) => Map<String, dynamic>.from(e)) ?? [],
        );
      }
    });
    if (data == null) AppLogger.warn('WF_UI', '워크플로우 데이터 null');
  }

  Future<void> _save() async {
    AppLogger.info('WF_UI', '워크플로우 저장 시작');
    setState(() => _saving = true);
    final res = await ApiService.updateWorkflow(
      keywords:  _keywords,
      schedule:  _schedule,
      workflows: _workflows.map((w) => {'type': w['type'], 'active': w['active']}).toList(),
    );
    if (!mounted) return;
    setState(() => _saving = false);
    if (res['ok'] == true) {
      AppLogger.info('WF_UI', '워크플로우 저장 완료');
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('저장됐습니다.'), backgroundColor: Color(0xFF03C75A),
            duration: Duration(seconds: 2)),
      );
      await _load();
    } else {
      AppLogger.error('WF_UI', '저장 실패: ${res['error']}');
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('오류: ${res['error'] ?? '저장 실패'}'), backgroundColor: Colors.red),
      );
    }
  }

  Future<void> _provision() async {
    AppLogger.info('WF_UI', '워크플로우 프로비저닝 시작');
    setState(() => _provisioning = true);
    final res = await ApiService.provisionWorkflow();
    if (!mounted) return;
    if (res['ok'] == true) {
      AppLogger.info('WF_UI', '프로비저닝 성공');
      await _load();
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(res['message'] ?? '워크플로우가 설정됐습니다.'),
            backgroundColor: const Color(0xFF03C75A)),
      );
    } else {
      AppLogger.error('WF_UI', '프로비저닝 실패: ${res['error']}');
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('오류: ${res['error'] ?? '알 수 없는 오류'}'), backgroundColor: Colors.red),
      );
    }
    setState(() => _provisioning = false);
  }

  void _addKeyword() {
    final kw = _kwCtrl.text.trim();
    if (kw.isEmpty || _keywords.contains(kw)) return;
    setState(() { _keywords.add(kw); _kwCtrl.clear(); });
  }

  void _removeKeyword(String kw) => setState(() => _keywords.remove(kw));

  void _toggleWorkflow(int idx, bool val) {
    setState(() => _workflows[idx]['active'] = val);
  }

  @override
  Widget build(BuildContext context) {
    final provisioned = _data?['provisioned'] == true;
    return Scaffold(
      backgroundColor: const Color(0xFFF5F5F5),
      appBar: AppBar(
        title: const Text('워크플로우', style: TextStyle(fontWeight: FontWeight.bold)),
        backgroundColor: const Color(0xFF03C75A),
        foregroundColor: Colors.white,
        elevation: 0,
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _load),
          if (provisioned)
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: TextButton(
                onPressed: _saving ? null : _save,
                child: _saving
                    ? const SizedBox(width: 18, height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : const Text('저장', style: TextStyle(color: Colors.white,
                        fontWeight: FontWeight.bold, fontSize: 15)),
              ),
            ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: Color(0xFF03C75A)))
          : provisioned ? _buildEditor() : _buildNotProvisioned(),
    );
  }

  Widget _buildEditor() {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // ── 워크플로우 유형 토글 ──────────────────────────────
          _sectionTitle('워크플로우 유형'),
          const SizedBox(height: 6),
          Card(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            child: Column(
              children: _workflows.asMap().entries.map((e) {
                final idx  = e.key;
                final wf   = e.value;
                final type = wf['type'] as String? ?? '';
                final label = _typeLabels[type] ?? type;
                final active = wf['active'] == true;
                final isCase = type == 'case';
                return Column(
                  children: [
                    ListTile(
                      leading: Icon(
                        _wfIcon(type),
                        color: active ? const Color(0xFF03C75A) : Colors.grey,
                        size: 22,
                      ),
                      title: Text(label,
                          style: TextStyle(
                            fontWeight: FontWeight.w600,
                            color: active ? Colors.black87 : Colors.grey,
                          )),
                      subtitle: isCase && active
                          ? GestureDetector(
                              onTap: () async {
                                AppLogger.info('WF_UI', '케이스형 사례 제출 탭');
                                await showCaseSubmitSheet(context);
                              },
                              child: const Text('탭해서 사례 제출 →',
                                  style: TextStyle(color: Color(0xFF03C75A), fontSize: 12)),
                            )
                          : null,
                      trailing: Switch(
                        value: active,
                        activeColor: const Color(0xFF03C75A),
                        onChanged: (v) => _toggleWorkflow(idx, v),
                      ),
                    ),
                    if (idx < _workflows.length - 1)
                      const Divider(height: 1, indent: 16, endIndent: 16),
                  ],
                );
              }).toList(),
            ),
          ),

          const SizedBox(height: 20),

          // ── 발행 일정 ────────────────────────────────────────
          _sectionTitle('발행 일정'),
          const SizedBox(height: 6),
          Card(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<String>(
                  value: _scheduleOptions.contains(_schedule) ? _schedule : _scheduleOptions[0],
                  isExpanded: true,
                  icon: const Icon(Icons.expand_more, color: Color(0xFF03C75A)),
                  items: _scheduleOptions.map((s) => DropdownMenuItem(
                    value: s,
                    child: Row(
                      children: [
                        const Icon(Icons.schedule, size: 16, color: Color(0xFF03C75A)),
                        const SizedBox(width: 10),
                        Text(s, style: const TextStyle(fontSize: 14)),
                      ],
                    ),
                  )).toList(),
                  onChanged: (v) { if (v != null) setState(() => _schedule = v); },
                ),
              ),
            ),
          ),

          const SizedBox(height: 20),

          // ── 타겟 키워드 ──────────────────────────────────────
          _sectionTitle('타겟 키워드'),
          const SizedBox(height: 6),
          Card(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // 키워드 칩
                  if (_keywords.isEmpty)
                    const Text('키워드를 추가하세요',
                        style: TextStyle(color: Colors.grey, fontSize: 13))
                  else
                    Wrap(
                      spacing: 8, runSpacing: 6,
                      children: _keywords.map((kw) => Chip(
                        label: Text(kw, style: const TextStyle(fontSize: 13)),
                        backgroundColor: const Color(0xFFE8F5E9),
                        side: const BorderSide(color: Color(0xFF03C75A), width: 0.8),
                        deleteIcon: const Icon(Icons.close, size: 14),
                        deleteIconColor: Colors.grey,
                        onDeleted: () => _removeKeyword(kw),
                      )).toList(),
                    ),

                  const SizedBox(height: 12),

                  // 키워드 입력
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: _kwCtrl,
                          textInputAction: TextInputAction.done,
                          onSubmitted: (_) => _addKeyword(),
                          decoration: InputDecoration(
                            hintText: '키워드 입력 후 + 또는 엔터',
                            hintStyle: const TextStyle(color: Colors.grey, fontSize: 13),
                            filled: true,
                            fillColor: const Color(0xFFF5F5F5),
                            border: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(8),
                              borderSide: BorderSide.none,
                            ),
                            contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                          ),
                          style: const TextStyle(fontSize: 14),
                        ),
                      ),
                      const SizedBox(width: 8),
                      GestureDetector(
                        onTap: _addKeyword,
                        child: Container(
                          width: 40, height: 40,
                          decoration: BoxDecoration(
                            color: const Color(0xFF03C75A),
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: const Icon(Icons.add, color: Colors.white),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),

          const SizedBox(height: 28),

          // ── 저장 버튼 ────────────────────────────────────────
          SizedBox(
            width: double.infinity,
            height: 50,
            child: ElevatedButton(
              onPressed: _saving ? null : _save,
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF03C75A),
                foregroundColor: Colors.white,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                elevation: 0,
              ),
              child: _saving
                  ? const SizedBox(width: 20, height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Text('변경사항 저장', style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
            ),
          ),
          const SizedBox(height: 20),
        ],
      ),
    );
  }

  Widget _buildNotProvisioned() => Center(
    child: Padding(
      padding: const EdgeInsets.all(36),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.account_tree_outlined, size: 72, color: Colors.grey),
          const SizedBox(height: 20),
          const Text('워크플로우가 아직 설정되지 않았습니다.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
          const Text('AI 블로그 자동화를 시작하려면\n워크플로우를 생성하세요.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Colors.grey, fontSize: 13, height: 1.6)),
          const SizedBox(height: 32),
          ElevatedButton.icon(
            onPressed: _provisioning ? null : _provision,
            icon: _provisioning
                ? const SizedBox(width: 18, height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.rocket_launch_outlined, size: 18),
            label: Text(_provisioning ? '생성 중...' : '워크플로우 시작하기'),
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF03C75A),
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 14),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              textStyle: const TextStyle(fontSize: 15),
            ),
          ),
        ],
      ),
    ),
  );

  Widget _sectionTitle(String text) => Text(
    text, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: Colors.black87),
  );

  IconData _wfIcon(String type) {
    switch (type) {
      case 'case':  return Icons.medical_services_outlined;
      case 'info':  return Icons.info_outline;
      case 'promo': return Icons.campaign_outlined;
      case 'plusA': return Icons.star_border;
      default:      return Icons.account_tree_outlined;
    }
  }
}
