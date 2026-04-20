import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import '../models/post.dart';
import 'publish_screen.dart';

class PostViewerScreen extends StatelessWidget {
  final Post post;

  const PostViewerScreen({super.key, required this.post});

  // 네이버 블로그 스타일로 HTML 래핑
  String _wrapHtml(String html) => '''
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: "Apple SD Gothic Neo", "Malgun Gothic", sans-serif;
      font-size: 15px;
      line-height: 1.8;
      color: #222;
      padding: 20px 16px 40px;
      background: #fff;
    }
    h1 { font-size: 22px; font-weight: 700; margin-bottom: 20px; color: #111; border-bottom: 2px solid #03C75A; padding-bottom: 12px; }
    h2 { font-size: 18px; font-weight: 700; margin: 24px 0 12px; }
    h3 { font-size: 16px; font-weight: 600; margin: 20px 0 8px; }
    p  { margin-bottom: 14px; }
    img { max-width: 100%; border-radius: 8px; margin: 12px 0; display: block; }
    ul, ol { margin: 12px 0 12px 20px; }
    li { margin-bottom: 6px; }
    strong { font-weight: 700; }
    em { font-style: italic; }
    a  { color: #03C75A; text-decoration: none; }
    blockquote {
      border-left: 4px solid #03C75A;
      padding: 10px 16px;
      margin: 16px 0;
      background: #f0faf5;
      color: #444;
      border-radius: 0 8px 8px 0;
    }
    .tag-list { margin-top: 24px; display: flex; flex-wrap: wrap; gap: 8px; }
    .tag { background: #e8f9f0; color: #03C75A; padding: 4px 10px; border-radius: 20px; font-size: 13px; }
    hr { border: none; border-top: 1px solid #eee; margin: 20px 0; }
  </style>
</head>
<body>
  <h1>${_esc(post.title)}</h1>
  $html
  ${post.tags.isNotEmpty ? '<div class="tag-list">${post.tags.map((t) => '<span class="tag">#$t</span>').join()}</div>' : ''}
</body>
</html>
''';

  static String _esc(String s) => s
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(post.title,
            maxLines: 1, overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 15)),
        backgroundColor: Colors.white,
        foregroundColor: Colors.black87,
        elevation: 0,
        actions: [
          TextButton.icon(
            icon: const Icon(Icons.edit_outlined, size: 18,
                color: Color(0xFF03C75A)),
            label: const Text('에디터 열기',
                style: TextStyle(color: Color(0xFF03C75A), fontSize: 13)),
            onPressed: () => Navigator.push(
              context,
              MaterialPageRoute(
                  builder: (_) => PublishScreen(post: post)),
            ),
          ),
        ],
      ),
      body: InAppWebView(
        initialData: InAppWebViewInitialData(
          data: _wrapHtml(post.html),
          mimeType: 'text/html',
          encoding: 'utf-8',
        ),
        initialSettings: InAppWebViewSettings(
          javaScriptEnabled: true,
          disableHorizontalScroll: false,
        ),
      ),
    );
  }
}
