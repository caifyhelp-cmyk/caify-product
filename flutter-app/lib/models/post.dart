class Post {
  final int id;
  final String title;
  final String html;       // 미리보기용 HTML
  final String naverHtml;  // SE3 에디터 주입용 HTML
  final String status; // 'ready' | 'published' | 'failed'
  final String createdAt;
  final List<String> tags;

  Post({
    required this.id,
    required this.title,
    required this.html,
    String naverHtml = '',
    this.status = '',
    this.createdAt = '',
    this.tags = const [],
  }) : naverHtml = naverHtml.isNotEmpty ? naverHtml : html;

  factory Post.fromJson(Map<String, dynamic> j) {
    final rawTags = j['tags'];
    List<String> tags = [];
    if (rawTags is List) {
      tags = rawTags.map((t) => t.toString()).toList();
    } else if (rawTags is String && rawTags.isNotEmpty) {
      tags = rawTags.split(',').map((t) => t.trim()).where((t) => t.isNotEmpty).toList();
    }
    final html      = j['html']       as String? ?? '';
    final naverHtml = j['naver_html'] as String? ?? '';
    return Post(
      id:        j['id'] as int,
      title:     j['title'] as String? ?? '',
      html:      html,
      naverHtml: naverHtml.isNotEmpty ? naverHtml : html, // fallback
      status:    j['status']?.toString() ?? '',
      createdAt: j['created_at'] ?? '',
      tags:      tags,
    );
  }
}
