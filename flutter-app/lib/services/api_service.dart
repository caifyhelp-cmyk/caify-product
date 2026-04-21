import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../models/post.dart';

class ApiService {
  static const _keyBase       = 'apiBase';
  static const _keyMemberId   = 'memberId';
  static const _keyToken      = 'apiToken';
  static const _keyMemberName = 'memberName';

  // 실서버 기본 주소 — 설정 미저장 시 fallback
  static const defaultApiBase = 'https://caify-mock-server.onrender.com';

  // ── 설정 저장/로드 ───────────────────────────────────────────
  static Future<void> saveConfig({
    required String apiBase,
    required String memberId,
    String token = '',
    String memberName = '',
  }) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_keyBase, apiBase.replaceAll(RegExp(r'/$'), ''));
    await prefs.setString(_keyMemberId, memberId);
    await prefs.setString(_keyToken, token);
    await prefs.setString(_keyMemberName, memberName);
  }

  static Future<Map<String, String>> loadConfig() async {
    final prefs = await SharedPreferences.getInstance();
    return {
      'apiBase':    prefs.getString(_keyBase)       ?? '',
      'memberId':   prefs.getString(_keyMemberId)   ?? '',
      'apiToken':   prefs.getString(_keyToken)      ?? '',
      'memberName': prefs.getString(_keyMemberName) ?? '',
    };
  }

  static Future<bool> isLoggedIn() async {
    final cfg = await loadConfig();
    return cfg['apiToken']!.isNotEmpty && cfg['memberId']!.isNotEmpty;
  }

  static Future<void> logout() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_keyToken);
    await prefs.remove(_keyMemberId);
    await prefs.remove(_keyMemberName);
  }

  // ── 로그인 ───────────────────────────────────────────────────
  /// 성공: {'ok': true, 'member_pk': ..., 'api_token': ..., 'company_name': ...}
  /// 실패: {'ok': false, 'error': '...'}
  static Future<Map<String, dynamic>> login({
    required String memberId,
    required String password,
    String? apiBase,
  }) async {
    final base = (apiBase?.isNotEmpty == true ? apiBase! : defaultApiBase)
        .replaceAll(RegExp(r'/$'), '');
    try {
      final res = await http.post(
        Uri.parse('$base/member/login'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'member_id': memberId, 'passwd': password}),
      ).timeout(const Duration(seconds: 10));

      final data = jsonDecode(res.body) as Map<String, dynamic>;
      if (res.statusCode == 200 && data['ok'] == true) {
        await saveConfig(
          apiBase:    base,
          memberId:   data['member_pk'].toString(),
          token:      data['api_token'] ?? '',
          memberName: data['company_name'] ?? memberId,
        );
        return {'ok': true, ...data};
      }
      return {'ok': false, 'error': data['error'] ?? '로그인 실패'};
    } catch (e) {
      return {'ok': false, 'error': '서버 연결 실패: $e'};
    }
  }

  // ── 공통 헤더 ────────────────────────────────────────────────
  static Future<Map<String, String>> authHeaders() async => _headers();

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

    try {
      final res = await http.get(uri, headers: await _headers())
          .timeout(const Duration(seconds: 10));
      if (res.statusCode != 200) return [];
      final data = jsonDecode(res.body);
      final list = data is List ? data : (data['posts'] as List? ?? []);
      return list.map((j) => Post.fromJson(j as Map<String, dynamic>)).toList();
    } catch (_) {
      return []; // 타임아웃·네트워크 오류 시 빈 목록 반환
    }
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
