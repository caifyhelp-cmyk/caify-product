class Post {
  final int id;
  final String title;
  final String html;
  final String status; // 'ready' | 'published' | 'failed'
  final String createdAt;

  const Post({
    required this.id,
    required this.title,
    required this.html,
    required this.status,
    required this.createdAt,
  });

  factory Post.fromJson(Map<String, dynamic> j) => Post(
        id: j['id'] as int,
        title: j['title'] as String,
        html: j['html'] as String,
        status: j['status'] as String,
        createdAt: j['created_at'] ?? '',
      );
}
