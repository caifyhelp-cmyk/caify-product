import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/app_logger.dart';

class WorkflowScreen extends StatefulWidget {
  const WorkflowScreen({super.key});

  @override
  State<WorkflowScreen> createState() => _WorkflowScreenState();
}

class _WorkflowScreenState extends State<WorkflowScreen> {
  Map<String, dynamic>? _data;
  bool _loading = true;
  bool _provisioning = false;
  bool _modifying = false;
  final _modifyCtrl = TextEditingController();
  String? _modifyResult;

  static const _typeLabels = {
    'case':  '케이스형',
    'info':  '정보형',
    'promo': '프로모션형',
    'plusA': '플러스A형',
  };

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _modifyCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    AppLogger.info('WF_UI', '워크플로우 화면 로드 시작');
    setState(() { _loading = true; _modifyResult = null; });
    final data = await ApiService.fetchWorkflow();
    if (mounted) {
      setState(() { _data = data; _loading = false; });
      if (data == null) {
        AppLogger.warn('WF_UI', '워크플로우 데이터 null — 서버 응답 없음');
      } else {
        AppLogger.info('WF_UI', '워크플로우 로드 완료: provisioned=${data['provisioned']} data=$data');
      }
    }
  }

  Future<void> _provision() async {
    AppLogger.info('WF_UI', '워크플로우 프로비저닝 시작');
    setState(() => _provisioning = true);
    final res = await ApiService.provisionWorkflow();
    if (!mounted) return;
    if (res['ok'] == true) {
      AppLogger.info('WF_UI', '프로비저닝 성공: ${res['message']}');
      await _load();
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? '워크플로우가 설정됐습니다.'),
          backgroundColor: const Color(0xFF03C75A),
        ),
      );
    } else {
      AppLogger.error('WF_UI', '프로비저닝 실패: ${res['error']}');
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('오류: ${res['error'] ?? '알 수 없는 오류'}'), backgroundColor: Colors.red),
      );
    }
    setState(() => _provisioning = false);
  }

  Future<void> _sendModify() async {
    final text = _modifyCtrl.text.trim();
    if (text.isEmpty || _modifying) return;
    AppLogger.info('WF_UI', '수정 요청: "$text"');
    setState(() { _modifying = true; _modifyResult = null; });
    final res = await ApiService.modifyWorkflow(text);
    if (!mounted) return;
    if (res['ok'] == true) {
      AppLogger.info('WF_UI', '수정 성공: ${res['message']}');
    } else {
      AppLogger.error('WF_UI', '수정 실패: ${res['error']}');
    }
    setState(() {
      _modifying = false;
      _modifyResult = res['ok'] == true ? '✅ ${res['message']}' : '❌ ${res['error'] ?? '오류'}';
    });
    if (res['ok'] == true) {
      _modifyCtrl.clear();
      await _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF5F5F5),
      appBar: AppBar(
        title: const Text('워크플로우', style: TextStyle(fontWeight: FontWeight.bold)),
        backgroundColor: const Color(0xFF03C75A),
        foregroundColor: Colors.white,
        elevation: 0,
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _load),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: Color(0xFF03C75A)))
          : _buildBody(),
    );
  }

  Widget _buildBody() {
    final provisioned = _data?['provisioned'] == true;

    if (!provisioned) return _buildNotProvisioned();

    final workflows = List<Map<String, dynamic>>.from(_data?['workflows'] ?? []);
    final keywords  = List<String>.from(_data?['keywords'] ?? []);
    final schedule  = _data?['schedule'] as String?;
    final lastMod   = _data?['last_modified'] as String?;

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // 워크플로우 카드들
          _sectionTitle('워크플로우 유형'),
          const SizedBox(height: 8),
          GridView.count(
            crossAxisCount: 2,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            mainAxisSpacing: 10,
            crossAxisSpacing: 10,
            childAspectRatio: 2.0,
            children: workflows.map((wf) => _buildWfCard(wf)).toList(),
          ),

          const SizedBox(height: 20),

          // 키워드
          _sectionTitle('타겟 키워드'),
          const SizedBox(height: 8),
          Card(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: keywords.isEmpty
                  ? const Text('키워드 미설정', style: TextStyle(color: Colors.grey))
                  : Wrap(
                      spacing: 8, runSpacing: 6,
                      children: keywords.map((k) => Chip(
                        label: Text(k, style: const TextStyle(fontSize: 13)),
                        backgroundColor: const Color(0xFFE8F5E9),
                        side: const BorderSide(color: Color(0xFF03C75A), width: 0.8),
                      )).toList(),
                    ),
            ),
          ),

          const SizedBox(height: 12),

          // 발행 일정
          if (schedule != null) ...[
            _sectionTitle('발행 일정'),
            const SizedBox(height: 8),
            Card(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              child: ListTile(
                leading: const Icon(Icons.schedule, color: Color(0xFF03C75A)),
                title: Text(schedule, style: const TextStyle(fontSize: 14)),
                subtitle: lastMod != null
                    ? Text('마지막 수정: ${_formatDate(lastMod)}',
                        style: const TextStyle(fontSize: 12, color: Colors.grey))
                    : null,
              ),
            ),
            const SizedBox(height: 20),
          ],

          // 수정 요청
          _sectionTitle('수정 요청'),
          const SizedBox(height: 8),
          Card(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    '키워드 변경, 발행 일정, 글 스타일 등 원하는 내용을 입력하면 워크플로우에 반영됩니다.',
                    style: TextStyle(fontSize: 12, color: Colors.grey, height: 1.5),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _modifyCtrl,
                    minLines: 3,
                    maxLines: 5,
                    decoration: InputDecoration(
                      hintText: '예) 키워드: 임플란트, 치아교정\n예) 발행 일정을 매일로 변경해주세요\n예) 글 톤을 더 전문적으로 써주세요',
                      hintStyle: const TextStyle(fontSize: 12, color: Colors.grey),
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                      contentPadding: const EdgeInsets.all(12),
                    ),
                  ),
                  const SizedBox(height: 12),
                  if (_modifyResult != null) ...[
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(
                        color: _modifyResult!.startsWith('✅')
                            ? const Color(0xFFE8F5E9)
                            : const Color(0xFFFCE8E6),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Text(
                        _modifyResult!,
                        style: TextStyle(
                          fontSize: 13,
                          color: _modifyResult!.startsWith('✅')
                              ? const Color(0xFF2E7D32)
                              : const Color(0xFFC62828),
                        ),
                      ),
                    ),
                    const SizedBox(height: 10),
                  ],
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: _modifying ? null : _sendModify,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF03C75A),
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                      ),
                      child: _modifying
                          ? const SizedBox(
                              width: 18, height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                          : const Text('수정 요청 보내기'),
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 20),
        ],
      ),
    );
  }

  Widget _buildNotProvisioned() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(36),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.account_tree_outlined, size: 72, color: Colors.grey),
            const SizedBox(height: 20),
            const Text(
              '워크플로우가 아직 설정되지 않았습니다.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 8),
            const Text(
              'AI 블로그 자동화를 시작하려면\n워크플로우를 생성하세요.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Colors.grey, fontSize: 13, height: 1.6),
            ),
            const SizedBox(height: 32),
            ElevatedButton.icon(
              onPressed: _provisioning ? null : _provision,
              icon: _provisioning
                  ? const SizedBox(
                      width: 18, height: 18,
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
  }

  Widget _buildWfCard(Map<String, dynamic> wf) {
    final type   = wf['type'] as String? ?? '';
    final active = wf['active'] == true;
    final label  = _typeLabels[type] ?? type;

    return Card(
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(
          color: active ? const Color(0xFF03C75A) : Colors.grey.shade300,
          width: active ? 1.5 : 1,
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        child: Row(
          children: [
            Icon(
              active ? Icons.check_circle : Icons.pause_circle_outline,
              color: active ? const Color(0xFF03C75A) : Colors.grey,
              size: 18,
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(label, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
                  Text(
                    active ? '활성' : '비활성',
                    style: TextStyle(
                      fontSize: 11,
                      color: active ? const Color(0xFF03C75A) : Colors.grey,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _sectionTitle(String text) => Text(
        text,
        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: Colors.black87),
      );

  String _formatDate(String iso) {
    try {
      final dt = DateTime.parse(iso).toLocal();
      return '${dt.month}/${dt.day} ${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
    } catch (_) {
      return iso;
    }
  }
}
