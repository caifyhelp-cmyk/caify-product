class Post {
  final int id;
  final String title;
  final String html;
  final String status; // 'ready' | 'published' | 'failed'
  final String createdAt;
  final List<String> tags;

  const Post({
    required this.id,
    required this.title,
    required this.html,
    this.status = '',
    this.createdAt = '',
    this.tags = const [],
  });

  factory Post.fromJson(Map<String, dynamic> j) {
    final rawTags = j['tags'];
    List<String> tags = [];
    if (rawTags is List) {
      tags = rawTags.map((t) => t.toString()).toList();
    } else if (rawTags is String && rawTags.isNotEmpty) {
      tags = rawTags.split(',').map((t) => t.trim()).where((t) => t.isNotEmpty).toList();
    }
    return Post(
      id:        j['id'] as int,
      title:     j['title'] as String? ?? '',
      html:      j['html'] as String? ?? '',
      status:    j['status']?.toString() ?? '',
      createdAt: j['created_at'] ?? '',
      tags:      tags,
    );
  }
}
