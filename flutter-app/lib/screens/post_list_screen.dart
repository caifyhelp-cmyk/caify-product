import 'package:flutter/material.dart';
import '../models/post.dart';
import '../services/api_service.dart';
import 'publish_screen.dart';
import 'settings_screen.dart';

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

  @override
  void initState() {
    super.initState();
    _tabCtrl = TabController(length: 2, vsync: this);
    _load();
  }

  @override
  void dispose() {
    _tabCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF5F5F5),
      appBar: AppBar(
        title: const Text('Caify', style: TextStyle(fontWeight: FontWeight.bold)),
        backgroundColor: const Color(0xFF03C75A), // Naver green
        foregroundColor: Colors.white,
        elevation: 0,
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _load),
          IconButton(
            icon: const Icon(Icons.settings),
            onPressed: () async {
              await Navigator.push(context,
                  MaterialPageRoute(builder: (_) => const SettingsScreen()));
              _load(); // 설정 변경 후 새로 로드
            },
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
                  onPressed: () => _publish(post),
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
              )
            ],
          ],
        ),
      ),
    );
  }

  Future<void> _publish(Post post) async {
    final success = await Navigator.push<bool>(
      context,
      MaterialPageRoute(builder: (_) => PublishScreen(post: post)),
    );
    if (success == true) _load(); // 발행 완료 후 목록 갱신
  }
}
