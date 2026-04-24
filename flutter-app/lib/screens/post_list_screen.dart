import 'dart:async';
import 'package:flutter/material.dart';
import '../models/post.dart';
import '../services/api_service.dart';
import 'login_screen.dart';
import 'publish_screen.dart';
import 'outputs_tab.dart';
import 'case_submit_sheet.dart';

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

  Timer? _pollTimer;

  static const _green = Color(0xFF03C75A);

  @override
  void initState() {
    super.initState();
    _tabCtrl = TabController(length: 3, vsync: this);
    _tabCtrl.addListener(() => setState(() {})); // FAB 갱신용
    _load();
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
    }
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

  Future<void> _openEditor(Post post) async {
    await Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => PublishScreen(post: post)),
    );
    _load();
  }

  Future<void> _openCaseSubmit() async {
    final submitted = await showCaseSubmitSheet(context);
    if (submitted == true) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Row(
            children: [
              Icon(Icons.check_circle, color: Colors.white, size: 18),
              SizedBox(width: 8),
              Text('사례가 전달됐습니다. 산출물 탭에서 확인하세요.'),
            ],
          ),
          backgroundColor: _green,
          duration: Duration(seconds: 3),
          behavior: SnackBarBehavior.floating,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final onOutputsTab = _tabCtrl.index == 2;

    return Scaffold(
      backgroundColor: const Color(0xFFF5F5F5),
      appBar: AppBar(
        title: const Text('Caify', style: TextStyle(fontWeight: FontWeight.bold)),
        backgroundColor: _green,
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
            const Tab(text: '산출물'),
          ],
        ),
      ),
      floatingActionButton: onOutputsTab
          ? FloatingActionButton.extended(
              onPressed: _openCaseSubmit,
              backgroundColor: _green,
              foregroundColor: Colors.white,
              icon: const Icon(Icons.add_photo_alternate_outlined),
              label: const Text('사례 제출', style: TextStyle(fontWeight: FontWeight.w600)),
            )
          : null,
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: _green))
          : TabBarView(
              controller: _tabCtrl,
              children: [
                _buildList(_readyPosts, showPublishBtn: true),
                _buildList(_publishedPosts, showPublishBtn: false),
                const OutputsTab(),
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
      color: _green,
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
                  icon: const Icon(Icons.edit_outlined, size: 16),
                  label: const Text('에디터에서 발행하기'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: _green,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(8)),
                  ),
                  onPressed: () => _openEditor(post),
                ),
              ),
            ] else ...[
              const SizedBox(height: 8),
              const Row(
                children: [
                  Icon(Icons.check_circle, color: _green, size: 14),
                  SizedBox(width: 4),
                  Text('발행 완료',
                      style: TextStyle(color: _green, fontSize: 12)),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}
