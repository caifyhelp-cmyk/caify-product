import 'dart:async';
import 'package:flutter/material.dart';
import '../models/post.dart';
import '../services/api_service.dart';
import 'login_screen.dart';
import 'publish_screen.dart';

class PostListScreen extends StatefulWidget {
  const PostListScreen({super.key});

  @override
  State<PostListScreen> createState() => _PostListScreenState();
}

class _PostListScreenState extends State<PostListScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabCtrl;
  List<Post> _readyPosts     = [];
  List<Post> _publishedPosts = [];
  bool _loading = false;
  bool _autoPublishing = false;

  Timer? _pollTimer;

  @override
  void initState() {
    super.initState();
    _tabCtrl = TabController(length: 2, vsync: this);
    _load();
    // 5분마다 새 포스트 확인 후 자동 발행
    _pollTimer = Timer.periodic(const Duration(minutes: 5), (_) => _load());
  }

  @override
  void dispose() {
    _tabCtrl.dispose();
    _pollTimer?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    if (_loading) return;
    setState(() => _loading = true);

    final ready     = await ApiService.fetchPosts(status: 'ready');
    final published = await ApiService.fetchPosts(status: 'published');

    if (mounted) {
      setState(() {
        _readyPosts     = ready;
        _publishedPosts = published;
        _loading        = false;
      });
      // 로드 후 대기 포스트가 있으면 자동 발행 시작
      if (ready.isNotEmpty && !_autoPublishing) {
        _autoPublishAll();
      }
    }
  }

  /// 대기 중인 포스트를 순차적으로 자동 발행
  Future<void> _autoPublishAll() async {
    if (_autoPublishing || !mounted) return;
    _autoPublishing = true;

    for (final post in List<Post>.from(_readyPosts)) {
      if (!mounted) break;
      final success = await _publishAuto(post);
      if (!mounted) break;
      if (success) {
        setState(() => _readyPosts.removeWhere((p) => p.id == post.id));
      }
      // 포스트 간 간격
      await Future.delayed(const Duration(seconds: 2));
    }

    _autoPublishing = false;
    if (mounted) _load(); // 완료 후 목록 갱신
  }

  /// autoMode로 발행 (화면 표시 최소화, 자동 팝)
  Future<bool> _publishAuto(Post post) async {
    final result = await Navigator.push<bool>(
      context,
      MaterialPageRoute(
        builder: (_) => PublishScreen(post: post, autoMode: true),
      ),
    );
    return result == true;
  }

  Future<void> _logout() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('로그아웃'),
        content: const Text('로그아웃 하시겠습니까?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false),
              child: const Text('취소')),
          TextButton(onPressed: () => Navigator.pop(context, true),
              child: const Text('로그아웃',
                  style: TextStyle(color: Colors.red))),
        ],
      ),
    );
    if (confirm != true || !mounted) return;
    await ApiService.logout();
    if (mounted) {
      Navigator.pushAndRemoveUntil(
        context,
        MaterialPageRoute(builder: (_) => const LoginScreen()),
        (_) => false,
      );
    }
  }

  /// 수동 발행 (버튼 탭 시)
  Future<void> _publishManual(Post post) async {
    final success = await Navigator.push<bool>(
      context,
      MaterialPageRoute(builder: (_) => PublishScreen(post: post)),
    );
    if (success == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF5F5F5),
      appBar: AppBar(
        title: Row(
          children: [
            const Text('Caify', style: TextStyle(fontWeight: FontWeight.bold)),
            if (_autoPublishing) ...[
              const SizedBox(width: 10),
              const SizedBox(
                width: 14, height: 14,
                child: CircularProgressIndicator(
                  color: Colors.white, strokeWidth: 2,
                ),
              ),
              const SizedBox(width: 6),
              const Text('자동 발행 중', style: TextStyle(fontSize: 13)),
            ],
          ],
        ),
        backgroundColor: const Color(0xFF03C75A),
        foregroundColor: Colors.white,
        elevation: 0,
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _load),
          IconButton(
            icon: const Icon(Icons.logout),
            tooltip: '로그아웃',
            onPressed: _logout,
          ),
        ],
        bottom: TabBar(
          controller: _tabCtrl,
          indicatorColor: Colors.white,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          tabs: [
            Tab(text: '발행 대기 (${_readyPosts.length})'),
            Tab(text: '발행 완료 (${_publishedPosts.length})'),
          ],
        ),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: Color(0xFF03C75A)))
          : TabBarView(
              controller: _tabCtrl,
              children: [
                _buildList(_readyPosts, showPublishBtn: true),
                _buildList(_publishedPosts, showPublishBtn: false),
              ],
            ),
    );
  }

  Widget _buildList(List<Post> posts, {required bool showPublishBtn}) {
    if (posts.isEmpty) {
      return const Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.article_outlined, size: 48, color: Colors.grey),
            SizedBox(height: 12),
            Text('포스팅이 없습니다', style: TextStyle(color: Colors.grey)),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      color: const Color(0xFF03C75A),
      child: ListView.builder(
        padding: const EdgeInsets.all(12),
        itemCount: posts.length,
        itemBuilder: (ctx, i) => _buildCard(posts[i], showPublishBtn),
      ),
    );
  }

  Widget _buildCard(Post post, bool showPublishBtn) {
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(post.title,
                style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15)),
            const SizedBox(height: 6),
            Text(post.createdAt,
                style: const TextStyle(color: Colors.grey, fontSize: 12)),
            if (showPublishBtn) ...[
              const SizedBox(height: 12),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton.icon(
                  icon: const Icon(Icons.send, size: 16),
                  label: const Text('네이버 블로그에 발행'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF03C75A),
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(8)),
                  ),
                  onPressed: _autoPublishing ? null : () => _publishManual(post),
                ),
              ),
            ] else ...[
              const SizedBox(height: 8),
              const Row(
                children: [
                  Icon(Icons.check_circle, color: Color(0xFF03C75A), size: 14),
                  SizedBox(width: 4),
                  Text('발행 완료',
                      style: TextStyle(color: Color(0xFF03C75A), fontSize: 12)),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}
