import 'package:flutter/material.dart';
import '../services/api_service.dart';

class RankScreen extends StatefulWidget {
  const RankScreen({super.key});

  @override
  State<RankScreen> createState() => _RankScreenState();
}

class _RankScreenState extends State<RankScreen> {
  List<Map<String, dynamic>> _ranks = [];
  String? _blogId;
  bool _loading = true;
  final Set<String> _checking = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final results = await Future.wait([
      ApiService.fetchRanks(),
      ApiService.fetchNaverBlogId(),
    ]);
    if (!mounted) return;
    setState(() {
      _ranks  = results[0] as List<Map<String, dynamic>>;
      _blogId = results[1] as String?;
      _loading = false;
    });
  }

  Future<void> _checkAll() async {
    if (_blogId == null || _blogId!.isEmpty) {
      _showNoBlogIdDialog();
      return;
    }
    for (final r in _ranks) {
      await _checkOne(r['keyword'] as String);
    }
  }

  Future<void> _checkOne(String keyword) async {
    if (_blogId == null || _blogId!.isEmpty) {
      _showNoBlogIdDialog();
      return;
    }
    setState(() => _checking.add(keyword));
    final res = await ApiService.checkRank(keyword, _blogId!);
    if (!mounted) return;
    if (res['ok'] == true) {
      final updated = {
        'keyword':    keyword,
        'rank':       res['rank'],
        'found':      res['found'] ?? false,
        'prev_rank':  res['prev_rank'],
        'checked_at': res['checked_at'],
        'message':    res['message'],
      };
      final idx = _ranks.indexWhere((r) => r['keyword'] == keyword);
      setState(() {
        if (idx >= 0) _ranks[idx] = updated;
        else _ranks.add(updated);
      });
    }
    setState(() => _checking.remove(keyword));
  }

  void _showNoBlogIdDialog() {
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('블로그 ID 미설정'),
        content: const Text('설정 > 블로그 연동 변경에서\n네이버 블로그 ID를 먼저 등록해 주세요.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('확인')),
        ],
      ),
    );
  }

  void _showAddKeywordDialog() {
    final ctrl = TextEditingController();
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('키워드 추가'),
        content: TextField(
          controller: ctrl,
          autofocus: true,
          decoration: const InputDecoration(hintText: '예) 임플란트 강남'),
          onSubmitted: (_) => Navigator.pop(context, ctrl.text.trim()),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('취소')),
          ElevatedButton(
            onPressed: () => Navigator.pop(context, ctrl.text.trim()),
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF03C75A), foregroundColor: Colors.white),
            child: const Text('추가'),
          ),
        ],
      ),
    ).then((kw) {
      if (kw != null && kw.isNotEmpty) _checkOne(kw);
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF5F5F5),
      appBar: AppBar(
        title: const Text('상위 노출 순위', style: TextStyle(fontWeight: FontWeight.bold)),
        backgroundColor: const Color(0xFF03C75A),
        foregroundColor: Colors.white,
        elevation: 0,
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _load),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _ranks.isEmpty ? null : _checkAll,
        backgroundColor: const Color(0xFF03C75A),
        foregroundColor: Colors.white,
        icon: const Icon(Icons.search),
        label: const Text('전체 확인'),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: Color(0xFF03C75A)))
          : Column(
              children: [
                _buildHeader(),
                Expanded(child: _buildList()),
              ],
            ),
    );
  }

  Widget _buildHeader() {
    return Container(
      color: Colors.white,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Row(
        children: [
          const Icon(Icons.person_outline, size: 18, color: Colors.grey),
          const SizedBox(width: 8),
          Text(
            _blogId != null && _blogId!.isNotEmpty
                ? 'blog.naver.com/$_blogId'
                : '블로그 ID 미등록',
            style: TextStyle(
              fontSize: 13,
              color: _blogId != null && _blogId!.isNotEmpty ? Colors.black87 : Colors.orange,
              fontWeight: FontWeight.w500,
            ),
          ),
          const Spacer(),
          TextButton.icon(
            onPressed: _showAddKeywordDialog,
            icon: const Icon(Icons.add, size: 16),
            label: const Text('키워드 추가', style: TextStyle(fontSize: 12)),
            style: TextButton.styleFrom(foregroundColor: const Color(0xFF03C75A)),
          ),
        ],
      ),
    );
  }

  Widget _buildList() {
    if (_ranks.isEmpty) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.bar_chart, size: 56, color: Colors.grey),
            const SizedBox(height: 12),
            const Text('순위 데이터가 없습니다.', style: TextStyle(color: Colors.grey)),
            const SizedBox(height: 8),
            TextButton.icon(
              onPressed: _showAddKeywordDialog,
              icon: const Icon(Icons.add),
              label: const Text('키워드 추가하기'),
              style: TextButton.styleFrom(foregroundColor: const Color(0xFF03C75A)),
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      color: const Color(0xFF03C75A),
      child: ListView.builder(
        padding: const EdgeInsets.all(12),
        itemCount: _ranks.length,
        itemBuilder: (_, i) => _buildCard(_ranks[i]),
      ),
    );
  }

  Widget _buildCard(Map<String, dynamic> r) {
    final keyword    = r['keyword'] as String? ?? '';
    final rank       = r['rank'] as int?;
    final found      = r['found'] as bool? ?? (rank != null);
    final prevRank   = r['prev_rank'] as int?;
    final checkedAt  = r['checked_at'] as String?;
    final isChecking = _checking.contains(keyword);

    // 이전 순위와의 차이 (양수 = 상승)
    int? diff;
    if (rank != null && prevRank != null) diff = prevRank - rank;

    Color rankColor = Colors.black87;
    if (!found) {
      rankColor = Colors.grey;
    } else if (rank != null) {
      if (rank <= 5)       rankColor = const Color(0xFF1B5E20);
      else if (rank <= 10) rankColor = const Color(0xFF2E7D32);
      else if (rank <= 20) rankColor = const Color(0xFF388E3C);
      else if (rank <= 30) rankColor = Colors.orange;
      else                 rankColor = Colors.red.shade700;
    }

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            // 순위 숫자
            SizedBox(
              width: 56,
              child: isChecking
                  ? const Center(
                      child: SizedBox(
                        width: 24, height: 24,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xFF03C75A)),
                      ))
                  : Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(
                          found && rank != null ? '$rank위' : '50위\n밖',
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            fontSize: found ? 22 : 14,
                            fontWeight: FontWeight.bold,
                            color: rankColor,
                            height: 1.2,
                          ),
                        ),
                        if (diff != null && found)
                          Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(
                                diff > 0 ? Icons.arrow_upward : diff < 0 ? Icons.arrow_downward : Icons.remove,
                                size: 12,
                                color: diff > 0 ? Colors.green : diff < 0 ? Colors.red : Colors.grey,
                              ),
                              Text(
                                diff != 0 ? '${diff.abs()}' : '-',
                                style: TextStyle(
                                  fontSize: 11,
                                  color: diff > 0 ? Colors.green : diff < 0 ? Colors.red : Colors.grey,
                                ),
                              ),
                            ],
                          ),
                      ],
                    ),
            ),
            const SizedBox(width: 12),
            const VerticalDivider(width: 1, color: Color(0xFFEEEEEE)),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(keyword,
                      style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                  const SizedBox(height: 4),
                  if (checkedAt != null)
                    Text(
                      '마지막 확인: ${_formatDate(checkedAt)}',
                      style: const TextStyle(fontSize: 11, color: Colors.grey),
                    ),
                  if (prevRank != null)
                    Text(
                      '이전 순위: $prevRank위',
                      style: const TextStyle(fontSize: 11, color: Colors.grey),
                    ),
                ],
              ),
            ),
            IconButton(
              icon: const Icon(Icons.search, size: 20),
              color: const Color(0xFF03C75A),
              tooltip: '순위 확인',
              onPressed: isChecking ? null : () => _checkOne(keyword),
            ),
          ],
        ),
      ),
    );
  }

  String _formatDate(String iso) {
    try {
      final dt = DateTime.parse(iso).toLocal();
      return '${dt.month}/${dt.day} ${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
    } catch (_) {
      return iso;
    }
  }
}
