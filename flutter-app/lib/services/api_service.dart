import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../models/post.dart';

class ApiService {
  static const _keyBase     = 'apiBase';
  static const _keyMemberId = 'memberId';
  static const _keyToken    = 'apiToken';

  // ── 설정 저장/로드 ───────────────────────────────────────────
  static Future<void> saveConfig({
    required String apiBase,
    required String memberId,
    String token = '',
  }) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_keyBase, apiBase.replaceAll(RegExp(r'/$'), ''));
    await prefs.setString(_keyMemberId, memberId);
    await prefs.setString(_keyToken, token);
  }

  static Future<Map<String, String>> loadConfig() async {
    final prefs = await SharedPreferences.getInstance();
    return {
      'apiBase':  prefs.getString(_keyBase)     ?? '',
      'memberId': prefs.getString(_keyMemberId) ?? '',
      'apiToken': prefs.getString(_keyToken)    ?? '',
    };
  }

  // ── 공통 헤더 ────────────────────────────────────────────────
  static Future<Map<String, String>> _headers() async {
    final cfg = await loadConfig();
    return {
      'Content-Type': 'application/json',
      if (cfg['apiToken']!.isNotEmpty)
        'Authorization': 'Bearer ${cfg['apiToken']}',
    };
  }

  // ── 포스팅 목록 ──────────────────────────────────────────────
  /// status: 'ready' | 'published' | '' (전체)
  static Future<List<Post>> fetchPosts({String status = ''}) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return [];

    final params = {
      'member_pk': cfg['memberId']!,
      if (status.isNotEmpty) 'status': status,
    };
    final uri = Uri.parse('${cfg['apiBase']}/api/posts')
        .replace(queryParameters: params);

    final res = await http.get(uri, headers: await _headers());
    if (res.statusCode != 200) return [];

    final data = jsonDecode(res.body);
    final list = data is List ? data : (data['posts'] as List? ?? []);
    return list.map((j) => Post.fromJson(j as Map<String, dynamic>)).toList();
  }

  // ── 발행 완료 통보 ───────────────────────────────────────────
  static Future<bool> markPublished(int postId) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return false;

    final res = await http.post(
      Uri.parse('${cfg['apiBase']}/api/posts/$postId/published'),
      headers: await _headers(),
    );
    return res.statusCode == 200 || res.statusCode == 204;
  }

  // ── 발행 실패 통보 ───────────────────────────────────────────
  static Future<void> markFailed(int postId, String reason) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return;

    await http.post(
      Uri.parse('${cfg['apiBase']}/api/posts/$postId/failed'),
      headers: await _headers(),
      body: jsonEncode({'reason': reason}),
    );
  }
}
