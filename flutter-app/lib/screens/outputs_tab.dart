import 'dart:async';
import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import '../services/api_service.dart';
import '../models/post.dart';
import 'publish_screen.dart';
import 'case_submit_sheet.dart';

/// 산출물 탭 — AI 포스팅 + 내 사례 + 영상 3개 서브탭
class OutputsTab extends StatefulWidget {
  const OutputsTab({super.key});

  @override
  State<OutputsTab> createState() => _OutputsTabState();
}

class _OutputsTabState extends State<OutputsTab>
    with SingleTickerProviderStateMixin {
  late TabController _sub;

  List<Map<String, dynamic>> _outputs = [];
  List<Map<String, dynamic>> _cases   = [];
  bool _loadingOutputs = false;
  bool _loadingCases   = false;

  // 진행률 polling
  final Map<int, Map<String, dynamic>> _caseProgress = {};
  final Map<int, int> _caseFailCount = {};   // 케이스별 연속 실패 횟수
  static const _maxFails = 5;               // 이 횟수 초과 시 polling 중단
  bool _isPolling = false;                  // 동시 실행 방지 가드
  Timer? _pollTimer;
  final Set<int> _navigatedCases = {};

  static const _green = Color(0xFF03C75A);

  @override
  void initState() {
    super.initState();
    _sub = TabController(length: 3, vsync: this);
    _sub.addListener(_onTabChanged);
    _loadAll();
  }

  @override
  void dispose() {
    _stopPolling();
    _sub.removeListener(_onTabChanged);
    _sub.dispose();
    super.dispose();
  }

  void _onTabChanged() {
    if (_sub.index == 1) _startPollingIfNeeded();
  }

  void _startPollingIfNeeded() {
    final hasActive = _cases.any((c) {
      final id = c['id'] as int;
      final status = c['ai_status'] as String? ?? 'pending';
      return status == 'pending' && (_caseFailCount[id] ?? 0) < _maxFails;
    });
    if (!hasActive) { _stopPolling(); return; }
    if (_pollTimer != null && _pollTimer!.isActive) return;
    _pollPendingCases();
    _pollTimer = Timer.periodic(const Duration(seconds: 3), (_) => _pollPendingCases());
  }

  void _stopPolling() {
    _pollTimer?.cancel();
    _pollTimer = null;
  }

  Future<void> _pollPendingCases() async {
    if (_isPolling) return;   // 이전 요청 진행 중이면 skip
    if (!mounted) { _stopPolling(); return; }
    final pending = _cases
        .where((c) {
          final id = c['id'] as int;
          final s  = c['ai_status'] as String? ?? 'pending';
          return s == 'pending' && (_caseFailCount[id] ?? 0) < _maxFails;
        })
        .toList();

    if (pending.isEmpty) { _stopPolling(); return; }
    _isPolling = true;
    try {
      for (final c in pending) {
        final caseId = c['id'] as int;
        final status = await ApiService.fetchCaseStatus(caseId);
        if (!mounted) return;

        // 서버 응답 없음 (빈 맵) → 연속 실패 카운트
        if (status.isEmpty) {
          final fails = (_caseFailCount[caseId] ?? 0) + 1;
          _caseFailCount[caseId] = fails;
          if (fails >= _maxFails) {
            setState(() => _caseProgress[caseId] = {
              'progress': 0,
              'step': '서버 연결 실패 — 새로고침 해주세요',
            });
            _startPollingIfNeeded();
          }
          continue;
        }

        _caseFailCount[caseId] = 0;

        final aiStatus = status['ai_status'] as String?;
        final pct      = (status['progress'] as num?)?.toInt() ?? 0;
        final step     = status['step'] as String? ?? 'AI 처리 중...';

        setState(() => _caseProgress[caseId] = {'progress': pct, 'step': step});

        if (aiStatus == 'done' && !_navigatedCases.contains(caseId)) {
          _navigatedCases.add(caseId);
          await _loadCases();
          if (!mounted) return;

          final updatedCase = _cases.firstWhere(
            (x) => x['id'] == caseId,
            orElse: () => <String, dynamic>{},
          );
          if (updatedCase.isEmpty) continue;

          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: const Text('✅ AI 포스팅 완성! 발행할 수 있어요.'),
              backgroundColor: _green,
              duration: const Duration(seconds: 5),
              action: SnackBarAction(
                label: '발행하기 →',
                textColor: Colors.white,
                onPressed: () => _openCaseDetail(updatedCase),
              ),
            ),
          );

          if (_sub.index == 1) {
            await Future.delayed(const Duration(milliseconds: 600));
            if (mounted) _openCaseDetail(updatedCase);
          }
        }
      }
    } finally {
      _isPolling = false;
    }
  }

  Future<void> _loadAll() async {
    _loadOutputs();
    _loadCases();
  }

  Future<void> _loadOutputs() async {
    setState(() => _loadingOutputs = true);
    final res = await ApiService.fetchOutputs();
    if (mounted) {
      setState(() {
        _outputs = List<Map<String, dynamic>>.from(res['items'] ?? []);
        _loadingOutputs = false;
      });
    }
  }

  Future<void> _loadCases() async {
    // 로드 전 이미 done이었던 케이스 ID 보관 (새로 done된 케이스 감지용)
    final prevDoneIds = _cases
        .where((c) => c['ai_status'] == 'done')
        .map((c) => c['id'] as int)
        .toSet();

    setState(() => _loadingCases = true);
    final res = await ApiService.fetchCases();
    if (!mounted) return;

    setState(() {
      _cases = res;
      _loadingCases = false;
      _caseFailCount.clear();
      _isPolling = false;
    });

    // 폴링 없이 이미 done인 케이스 감지 → 스낵바 + 자동 이동
    for (final c in _cases) {
      final caseId = c['id'] as int;
      if (c['ai_status'] == 'done' &&
          !prevDoneIds.contains(caseId) &&
          !_navigatedCases.contains(caseId)) {
        _navigatedCases.add(caseId);
        if (!mounted) break;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: const Text('✅ AI 포스팅 완성! 발행할 수 있어요.'),
            backgroundColor: _green,
            duration: const Duration(seconds: 5),
            action: SnackBarAction(
              label: '발행하기 →',
              textColor: Colors.white,
              onPressed: () => _openCaseDetail(c),
            ),
          ),
        );
        if (_sub.index == 1) {
          await Future.delayed(const Duration(milliseconds: 600));
          if (mounted) _openCaseDetail(c);
        }
        break;
      }
    }

    _startPollingIfNeeded();
  }

  Future<void> _openCaseSubmit() async {
    final submitted = await showCaseSubmitSheet(context);
    if (submitted == true) {
      await _loadCases();
      _sub.animateTo(1);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF5F5F5),
      appBar: AppBar(
        title: const Text('산출물', style: TextStyle(fontWeight: FontWeight.bold)),
        backgroundColor: const Color(0xFF03C75A),
        foregroundColor: Colors.white,
        elevation: 0,
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _loadAll),
        ],
        bottom: TabBar(
          controller: _sub,
          indicatorColor: Colors.white,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          labelStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
          tabs: [
            Tab(text: 'AI 포스팅 (${_outputs.length})'),
            Tab(text: '내 사례 (${_cases.length})'),
            const Tab(text: '영상'),
          ],
        ),
      ),
      body: TabBarView(
        controller: _sub,
        children: [
          _buildOutputs(),
          _buildCases(),
          _buildVideos(),
        ],
      ),
      floatingActionButton: ListenableBuilder(
        listenable: _sub,
        builder: (_, __) {
          if (_sub.index != 1) return const SizedBox.shrink();
          return FloatingActionButton.extended(
            onPressed: _openCaseSubmit,
            backgroundColor: _green,
            foregroundColor: Colors.white,
            icon: const Icon(Icons.add),
            label: const Text('새 사례 제출', style: TextStyle(fontWeight: FontWeight.w600)),
          );
        },
      ),
    );
  }

  // ── AI 포스팅 목록 ────────────────────────────────────────────
  Widget _buildOutputs() {
    if (_loadingOutputs) {
      return const Center(child: CircularProgressIndicator(color: _green));
    }
    if (_outputs.isEmpty) {
      return _buildEmpty(
        icon: Icons.auto_awesome_outlined,
        message: 'AI가 생성한 포스팅이 없습니다.\n사례를 제출하면 여기에 나타납니다.',
      );
    }
    return RefreshIndicator(
      onRefresh: _loadOutputs,
      color: _green,
      child: ListView.builder(
        padding: const EdgeInsets.fromLTRB(12, 12, 12, 100),
        itemCount: _outputs.length,
        itemBuilder: (_, i) => _buildOutputCard(_outputs[i]),
      ),
    );
  }

  Widget _buildOutputCard(Map<String, dynamic> item) {
    final thumbnail = item['thumbnail'] as String?;
    final title     = item['title'] as String? ?? '(제목 없음)';
    final date      = _formatDate(item['created_at'] as String?);
    final published = item['posting_date'] != null;

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      elevation: 0,
      color: Colors.white,
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => _openPost(item),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (thumbnail != null)
              ClipRRect(
                borderRadius: const BorderRadius.vertical(top: Radius.circular(12)),
                child: CachedNetworkImage(
                  imageUrl: thumbnail,
                  height: 160,
                  width: double.infinity,
                  fit: BoxFit.cover,
                  errorWidget: (_, __, ___) => const SizedBox.shrink(),
                ),
              ),
            Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: const TextStyle(
                        fontSize: 15, fontWeight: FontWeight.w600, height: 1.4),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      const Icon(Icons.schedule, size: 13, color: Colors.grey),
                      const SizedBox(width: 4),
                      Text(date, style: const TextStyle(fontSize: 12, color: Colors.grey)),
                      const Spacer(),
                      if (published)
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(
                            color: const Color(0xFFE8F5E9),
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(Icons.check_circle, size: 11, color: _green),
                              SizedBox(width: 3),
                              Text('발행완료',
                                  style: TextStyle(fontSize: 11, color: _green)),
                            ],
                          ),
                        ),
                    ],
                  ),
                  if (!published) ...[
                    const SizedBox(height: 12),
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton.icon(
                        icon: const Icon(Icons.edit_outlined, size: 16),
                        label: const Text('발행하기',
                            style: TextStyle(fontWeight: FontWeight.w600)),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: _green,
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 11),
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(8)),
                          elevation: 0,
                        ),
                        onPressed: () => _openPost(item),
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  void _openPost(Map<String, dynamic> item) {
    final post = Post.fromJson(item);
    Navigator.push(context, MaterialPageRoute(builder: (_) => PublishScreen(post: post)));
  }

  // ── 내 사례 목록 ──────────────────────────────────────────────
  Widget _buildCases() {
    if (_loadingCases) {
      return const Center(child: CircularProgressIndicator(color: _green));
    }
    if (_cases.isEmpty) {
      return _buildEmpty(
        icon: Icons.medical_services_outlined,
        message: '제출된 사례가 없습니다.\n아래 버튼으로 첫 사례를 제출해보세요.',
      );
    }
    return RefreshIndicator(
      onRefresh: _loadCases,
      color: _green,
      child: ListView.builder(
        padding: const EdgeInsets.fromLTRB(12, 12, 12, 100),
        itemCount: _cases.length,
        itemBuilder: (_, i) => _buildCaseCard(_cases[i]),
      ),
    );
  }

  Widget _buildCaseCard(Map<String, dynamic> c) {
    final status     = c['ai_status'] as String? ?? 'pending';
    final caseTitle  = c['case_title'] as String? ?? '(제목 없음)';
    final aiTitle    = c['ai_title'] as String?;
    final aiSummary  = c['ai_summary'] as String?;
    final date       = _formatDate(c['created_at'] as String?);
    final files      = (c['files'] as List?) ?? [];
    final filesCount = files.length;
    final caseId     = c['id'] as int;

    final isDone   = status == 'done';
    final isFailed = status == 'failed' || status == 'error';

    final String? thumbUrl = (filesCount > 0 && files.first is Map)
        ? (files.first as Map<String, dynamic>)['url'] as String?
        : null;

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      elevation: 0,
      color: Colors.white,
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: isDone ? () => _openCaseDetail(c) : null,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (isDone && thumbUrl != null)
              ClipRRect(
                borderRadius: const BorderRadius.vertical(top: Radius.circular(12)),
                child: CachedNetworkImage(
                  imageUrl: thumbUrl,
                  height: 160,
                  width: double.infinity,
                  fit: BoxFit.cover,
                  errorWidget: (_, __, ___) => const SizedBox.shrink(),
                ),
              ),
            Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Text(
                          caseTitle,
                          style: const TextStyle(
                              fontSize: 15, fontWeight: FontWeight.w600, height: 1.4),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      const SizedBox(width: 8),
                      _statusBadge(status),
                    ],
                  ),
                  if (isDone && aiTitle != null) ...[
                    const SizedBox(height: 6),
                    Text(
                      'AI 제목: $aiTitle',
                      style: const TextStyle(
                          fontSize: 13, color: _green, fontWeight: FontWeight.w500),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                  if (isDone && aiSummary != null) ...[
                    const SizedBox(height: 4),
                    Text(
                      aiSummary,
                      style: const TextStyle(
                          fontSize: 12, color: Colors.grey, height: 1.4),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                  // 진행률 바 (pending 상태)
                  if (!isDone && !isFailed) ...[
                    const SizedBox(height: 10),
                    _buildProgressSection(caseId),
                  ],
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      const Icon(Icons.schedule, size: 12, color: Colors.grey),
                      const SizedBox(width: 3),
                      Text(date, style: const TextStyle(fontSize: 11, color: Colors.grey)),
                      if (filesCount > 0 && !isDone) ...[
                        const SizedBox(width: 10),
                        const Icon(Icons.photo_library_outlined,
                            size: 12, color: Colors.grey),
                        const SizedBox(width: 3),
                        Text('$filesCount장',
                            style: const TextStyle(fontSize: 11, color: Colors.grey)),
                      ],
                    ],
                  ),
                  if (isDone) ...[
                    const SizedBox(height: 12),
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton.icon(
                        icon: const Icon(Icons.edit_outlined, size: 16),
                        label: const Text('포스팅 발행하기',
                            style: TextStyle(fontWeight: FontWeight.w600)),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: _green,
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 11),
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(8)),
                          elevation: 0,
                        ),
                        onPressed: () => _openCaseDetail(c),
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildProgressSection(int caseId) {
    final progress = _caseProgress[caseId];
    final pct  = (progress?['progress'] as int?) ?? 0;
    final step = (progress?['step'] as String?) ?? 'AI 처리 대기 중...';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(step,
                style: const TextStyle(fontSize: 11, color: Colors.grey)),
            Text(
              pct > 0 ? '$pct%' : '',
              style: const TextStyle(
                  fontSize: 11, color: _green, fontWeight: FontWeight.w600),
            ),
          ],
        ),
        const SizedBox(height: 5),
        pct > 0
            ? LinearProgressIndicator(
                value: pct / 100,
                color: _green,
                backgroundColor: const Color(0xFFE8F5E9),
                minHeight: 4,
              )
            : const LinearProgressIndicator(
                color: _green,
                backgroundColor: Color(0xFFE8F5E9),
                minHeight: 4,
              ),
      ],
    );
  }

  Future<void> _openCaseDetail(Map<String, dynamic> c) async {
    final postId = c['post_id'];

    if (postId != null) {
      final found = _outputs.firstWhere(
        (o) => o['id'].toString() == postId.toString(),
        orElse: () => <String, dynamic>{},
      );
      if (found.isNotEmpty) {
        _openPost(found);
        return;
      }

      if (!mounted) return;
      showDialog(
        context: context,
        barrierDismissible: false,
        builder: (_) => const Center(child: CircularProgressIndicator(color: _green)),
      );
      final res = await ApiService.fetchOutputs();
      if (mounted) Navigator.of(context, rootNavigator: true).pop();
      if (!mounted) return;

      final items = List<Map<String, dynamic>>.from(res['items'] ?? []);
      final fresh = items.firstWhere(
        (o) => o['id'].toString() == postId.toString(),
        orElse: () => <String, dynamic>{},
      );
      if (fresh.isNotEmpty) {
        setState(() => _outputs = items);
        _openPost(fresh);
        return;
      }

      // outputs 필터에 걸려 안 나올 수 있음 (사례형 즉시 완성 등) → 단건 직접 조회
      if (!mounted) return;
      final single = await ApiService.fetchPost(postId as int);
      if (single != null && single['id'] != null) {
        if (mounted) _openPost(single);
        return;
      }
    }

    _showCaseDetailSheet(c);
  }

  void _showCaseDetailSheet(Map<String, dynamic> c) {
    final files = (c['files'] as List?) ?? [];
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.65,
        maxChildSize: 0.95,
        builder: (_, ctrl) => ListView(
          controller: ctrl,
          padding: const EdgeInsets.all(20),
          children: [
            Center(
              child: Container(
                width: 40, height: 4,
                decoration: BoxDecoration(
                  color: Colors.grey.shade300,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            ),
            const SizedBox(height: 20),
            Row(
              children: [
                Expanded(
                  child: Text(
                    c['case_title'] as String? ?? '',
                    style: const TextStyle(
                        fontSize: 17, fontWeight: FontWeight.bold),
                  ),
                ),
                _statusBadge(c['ai_status'] as String? ?? 'pending'),
              ],
            ),
            if (c['ai_title'] != null) ...[
              const SizedBox(height: 12),
              const Text('AI 생성 제목',
                  style: TextStyle(fontSize: 12, color: Colors.grey)),
              const SizedBox(height: 4),
              Text(c['ai_title'] as String,
                  style: const TextStyle(
                      fontSize: 14, color: _green, fontWeight: FontWeight.w500)),
            ],
            if (c['ai_summary'] != null) ...[
              const SizedBox(height: 12),
              const Text('AI 요약',
                  style: TextStyle(fontSize: 12, color: Colors.grey)),
              const SizedBox(height: 4),
              Text(c['ai_summary'] as String,
                  style: const TextStyle(fontSize: 14, height: 1.5)),
            ],
            if (files.isNotEmpty) ...[
              const SizedBox(height: 16),
              const Text('제출 이미지',
                  style: TextStyle(fontSize: 12, color: Colors.grey)),
              const SizedBox(height: 8),
              SizedBox(
                height: 100,
                child: ListView.separated(
                  scrollDirection: Axis.horizontal,
                  itemCount: files.length,
                  separatorBuilder: (_, __) => const SizedBox(width: 8),
                  itemBuilder: (_, i) {
                    final f = files[i] as Map<String, dynamic>;
                    final url = f['url'] as String? ?? '';
                    return ClipRRect(
                      borderRadius: BorderRadius.circular(8),
                      child: CachedNetworkImage(
                        imageUrl: url,
                        width: 100,
                        height: 100,
                        fit: BoxFit.cover,
                        errorWidget: (_, __, ___) => Container(
                          width: 100, height: 100,
                          color: Colors.grey.shade200,
                          child: const Icon(Icons.broken_image,
                              color: Colors.grey),
                        ),
                      ),
                    );
                  },
                ),
              ),
            ],
            if (c['raw_content'] != null) ...[
              const SizedBox(height: 16),
              const Text('제출 내용',
                  style: TextStyle(fontSize: 12, color: Colors.grey)),
              const SizedBox(height: 4),
              Text(c['raw_content'] as String,
                  style: const TextStyle(
                      fontSize: 13, color: Colors.black87, height: 1.6)),
            ],
            const SizedBox(height: 32),
          ],
        ),
      ),
    );
  }

  Widget _statusBadge(String status) {
    final (label, color, bg) = switch (status) {
      'done'              => ('완성', _green, const Color(0xFFE8F5E9)),
      'failed' || 'error' => ('실패', Colors.red, const Color(0xFFFCE8E6)),
      _                   => ('처리중', Colors.orange, const Color(0xFFFFF3E0)),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration:
          BoxDecoration(color: bg, borderRadius: BorderRadius.circular(20)),
      child: Text(label,
          style: TextStyle(
              fontSize: 11, color: color, fontWeight: FontWeight.w600)),
    );
  }

  Widget _buildVideos() {
    return _buildEmpty(
      icon: Icons.videocam_outlined,
      message: '영상 기능을 준비 중입니다.\n곧 업데이트될 예정이에요.',
    );
  }

  Widget _buildEmpty({required IconData icon, required String message}) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 52, color: Colors.grey.shade300),
            const SizedBox(height: 16),
            Text(
              message,
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.grey, height: 1.6),
            ),
          ],
        ),
      ),
    );
  }

  String _formatDate(String? iso) {
    if (iso == null) return '-';
    try {
      final dt = DateTime.parse(iso).toLocal();
      return '${dt.year}.${dt.month.toString().padLeft(2, '0')}.${dt.day.toString().padLeft(2, '0')}';
    } catch (_) {
      return iso;
    }
  }
}
