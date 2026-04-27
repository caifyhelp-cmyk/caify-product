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

  static const _green = Color(0xFF03C75A);

  @override
  void initState() {
    super.initState();
    _sub = TabController(length: 3, vsync: this);
    _loadAll();
  }

  @override
  void dispose() {
    _sub.dispose();
    super.dispose();
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
    setState(() => _loadingCases = true);
    final res = await ApiService.fetchCases();
    if (mounted) {
      setState(() {
        _cases = res;
        _loadingCases = false;
      });
    }
  }

  Future<void> _openCaseSubmit() async {
    final submitted = await showCaseSubmitSheet(context);
    if (submitted == true) {
      await _loadCases();
      // 제출 성공 시 내 사례 탭으로 이동
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
                          child: const Row(
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

  Future<void> _openPost(Map<String, dynamic> item) async {
    final post = Post.fromJson(item);
    await Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => PublishScreen(post: post)),
    );
    _loadOutputs();
  }

  // ── 내 사례 목록 ──────────────────────────────────────────────
  Widget _buildCases() {
    if (_loadingCases) {
      return const Center(child: CircularProgressIndicator(color: _green));
    }
    if (_cases.isEmpty) {
      return RefreshIndicator(
        onRefresh: _loadCases,
        color: _green,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            const SizedBox(height: 80),
            _buildEmpty(
              icon: Icons.inbox_outlined,
              message: '제출한 사례가 없습니다.\n아래 버튼으로 첫 사례를 제출해보세요.',
            ),
          ],
        ),
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
    final filesCount = (c['files'] as List?)?.length ?? 0;

    final isDone   = status == 'done';
    final isFailed = status == 'failed';

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      elevation: 0,
      color: Colors.white,
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: isDone ? () => _openCaseDetail(c) : null,
        child: Padding(
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
              if (!isDone && !isFailed) ...[
                const SizedBox(height: 10),
                const LinearProgressIndicator(
                  color: _green,
                  backgroundColor: Color(0xFFE8F5E9),
                  minHeight: 3,
                ),
                const SizedBox(height: 6),
                const Text('AI가 포스팅을 생성하는 중입니다...',
                    style: TextStyle(fontSize: 11, color: Colors.grey)),
              ],
              const SizedBox(height: 8),
              Row(
                children: [
                  const Icon(Icons.schedule, size: 12, color: Colors.grey),
                  const SizedBox(width: 3),
                  Text(date, style: const TextStyle(fontSize: 11, color: Colors.grey)),
                  if (filesCount > 0) ...[
                    const SizedBox(width: 10),
                    const Icon(Icons.photo_library_outlined,
                        size: 12, color: Colors.grey),
                    const SizedBox(width: 3),
                    Text('$filesCount장',
                        style: const TextStyle(fontSize: 11, color: Colors.grey)),
                  ],
                  if (isDone) ...[
                    const Spacer(),
                    const Text('포스팅 보기 →',
                        style: TextStyle(
                            fontSize: 11,
                            color: _green,
                            fontWeight: FontWeight.w500)),
                  ],
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _openCaseDetail(Map<String, dynamic> c) {
    // done 케이스에 연결된 포스팅이 있으면 포스팅 화면 열기
    final postId = c['post_id'];
    if (postId != null) {
      final postItem = _outputs.firstWhere(
        (o) => o['id'].toString() == postId.toString(),
        orElse: () => <String, dynamic>{},
      );
      if (postItem.isNotEmpty) {
        _openPost(postItem);
        return;
      }
    }
    // 연결된 포스팅 없으면 사례 상세 모달
    _showCaseDetailSheet(c);
  }

  void _showCaseDetailSheet(Map<String, dynamic> c) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.6,
        maxChildSize: 0.9,
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
      'done'   => ('완성', _green, const Color(0xFFE8F5E9)),
      'failed' => ('실패', Colors.red, const Color(0xFFFCE8E6)),
      _        => ('처리중', Colors.orange, const Color(0xFFFFF3E0)),
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
