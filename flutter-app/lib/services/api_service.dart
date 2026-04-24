import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../models/post.dart';

class ApiService {
  static const _keyBase       = 'apiBase';
  static const _keyMemberId   = 'memberId';
  static const _keyToken      = 'apiToken';
  static const _keyMemberName = 'memberName';
  static const _keyTier       = 'memberTier';       // 0=무료, 1=유료
  static const _keyHasWf      = 'memberHasWorkflows';

  // 실서버 기본 주소 — 설정 미저장 시 fallback
  static const defaultApiBase = 'https://caify-mock-server.onrender.com';

  // ── 설정 저장/로드 ───────────────────────────────────────────
  static Future<void> saveConfig({
    required String apiBase,
    required String memberId,
    String token = '',
    String memberName = '',
    int tier = 0,
    bool hasWorkflows = false,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_keyBase, apiBase.replaceAll(RegExp(r'/$'), ''));
    await prefs.setString(_keyMemberId, memberId);
    await prefs.setString(_keyToken, token);
    await prefs.setString(_keyMemberName, memberName);
    await prefs.setInt(_keyTier, tier);
    await prefs.setBool(_keyHasWf, hasWorkflows);
  }

  static Future<Map<String, dynamic>> loadConfig() async {
    final prefs = await SharedPreferences.getInstance();
    return {
      'apiBase':      prefs.getString(_keyBase)       ?? '',
      'memberId':     prefs.getString(_keyMemberId)   ?? '',
      'apiToken':     prefs.getString(_keyToken)      ?? '',
      'memberName':   prefs.getString(_keyMemberName) ?? '',
      'tier':         prefs.getInt(_keyTier)          ?? 0,
      'hasWorkflows': prefs.getBool(_keyHasWf)        ?? false,
    };
  }

  static Future<bool> isLoggedIn() async {
    final cfg = await loadConfig();
    return (cfg['apiToken'] as String).isNotEmpty &&
           (cfg['memberId'] as String).isNotEmpty;
  }

  static Future<void> logout() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_keyToken);
    await prefs.remove(_keyMemberId);
    await prefs.remove(_keyMemberName);
    await prefs.remove(_keyTier);
    await prefs.remove(_keyHasWf);
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
          apiBase:      base,
          memberId:     data['member_pk'].toString(),
          token:        data['api_token'] ?? '',
          memberName:   data['company_name'] ?? memberId,
          tier:         (data['tier'] as num?)?.toInt() ?? 0,
          hasWorkflows: data['has_workflows'] as bool? ?? false,
        );
        return {'ok': true, ...data};
      }
      return {'ok': false, 'error': data['error'] ?? '로그인 실패'};
    } catch (e) {
      return {'ok': false, 'error': '서버 연결 실패: $e'};
    }
  }

  // ── 내 정보 (tier + 워크플로우 현황) ─────────────────────────────
  /// 서버에서 최신 플랜 정보를 가져와 로컬에도 반영
  static Future<Map<String, dynamic>?> fetchMe() async {
    final cfg = await loadConfig();
    if ((cfg['apiBase'] as String).isEmpty) return null;

    try {
      final res = await http.get(
        Uri.parse('${cfg['apiBase']}/member/me'),
        headers: await authHeaders(),
      ).timeout(const Duration(seconds: 10));

      if (res.statusCode != 200) return null;
      final data = jsonDecode(res.body) as Map<String, dynamic>;
      if (data['ok'] != true) return null;

      await saveConfig(
        apiBase:      cfg['apiBase'] as String,
        memberId:     cfg['memberId'] as String,
        token:        cfg['apiToken'] as String,
        memberName:   cfg['memberName'] as String,
        tier:         (data['tier'] as num?)?.toInt() ?? 0,
        hasWorkflows: data['has_workflows'] as bool? ?? false,
      );
      return data;
    } catch (_) {
      return null;
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

  // ── 포스팅 모드 조회/변경 ─────────────────────────────────────
  static Future<Map<String, dynamic>?> fetchPostingMode() async {
    final cfg = await loadConfig();
    if ((cfg['apiBase'] as String).isEmpty) return null;
    try {
      final res = await http.get(
        Uri.parse('${cfg['apiBase']}/api/posting-mode'),
        headers: await authHeaders(),
      ).timeout(const Duration(seconds: 10));
      if (res.statusCode != 200) return null;
      return jsonDecode(res.body) as Map<String, dynamic>;
    } catch (_) { return null; }
  }

  /// mode: 'intensive' | 'mixed'
  static Future<Map<String, dynamic>> requestModeChange(String mode) async {
    final cfg = await loadConfig();
    try {
      final res = await http.post(
        Uri.parse('${cfg['apiBase']}/api/posting-mode'),
        headers: await authHeaders(),
        body: jsonEncode({'mode': mode}),
      ).timeout(const Duration(seconds: 10));
      return jsonDecode(res.body) as Map<String, dynamic>;
    } catch (e) {
      return {'ok': false, 'error': '서버 연결 실패: $e'};
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

  // ── 네이버 블로그 ID ─────────────────────────────────────────
  static Future<String?> fetchNaverBlogId() async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty || cfg['memberId']!.isEmpty) return null;
    try {
      final uri = Uri.parse('${cfg['apiBase']}/api/naver-blog')
          .replace(queryParameters: {'member_pk': cfg['memberId']!});
      final res = await http.get(uri, headers: await _headers())
          .timeout(const Duration(seconds: 8));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body) as Map<String, dynamic>;
        return data['blog_id'] as String?;
      }
    } catch (_) {}
    return null;
  }

  static Future<bool> saveNaverBlogId(String blogId) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return false;
    try {
      final res = await http.patch(
        Uri.parse('${cfg['apiBase']}/api/naver-blog'),
        headers: await _headers(),
        body: jsonEncode({'member_pk': cfg['memberId']!, 'blog_id': blogId}),
      ).timeout(const Duration(seconds: 8));
      return res.statusCode == 200;
    } catch (_) {
      return false;
    }
  }

  // ── 워크플로우 ───────────────────────────────────────────────
  static Future<Map<String, dynamic>?> fetchWorkflow() async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty || cfg['memberId']!.isEmpty) return null;
    try {
      final uri = Uri.parse('${cfg['apiBase']}/api/workflow')
          .replace(queryParameters: {'member_pk': cfg['memberId']!});
      final res = await http.get(uri, headers: await _headers())
          .timeout(const Duration(seconds: 8));
      if (res.statusCode == 200) return jsonDecode(res.body) as Map<String, dynamic>;
    } catch (_) {}
    return null;
  }

  static Future<Map<String, dynamic>> provisionWorkflow() async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return {'ok': false, 'error': '서버 미설정'};
    try {
      final res = await http.post(
        Uri.parse('${cfg['apiBase']}/api/workflow/provision'),
        headers: await _headers(),
        body: jsonEncode({'member_pk': cfg['memberId']!}),
      ).timeout(const Duration(seconds: 15));
      return jsonDecode(res.body) as Map<String, dynamic>;
    } catch (e) {
      return {'ok': false, 'error': '연결 실패: $e'};
    }
  }

  static Future<Map<String, dynamic>> modifyWorkflow(String instruction) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return {'ok': false, 'error': '서버 미설정'};
    try {
      final res = await http.post(
        Uri.parse('${cfg['apiBase']}/api/workflow/modify'),
        headers: await _headers(),
        body: jsonEncode({'member_pk': cfg['memberId']!, 'instruction': instruction}),
      ).timeout(const Duration(seconds: 10));
      return jsonDecode(res.body) as Map<String, dynamic>;
    } catch (e) {
      return {'ok': false, 'error': '연결 실패: $e'};
    }
  }

  // ── 키워드 순위 ──────────────────────────────────────────────
  static Future<List<Map<String, dynamic>>> fetchRanks() async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty || cfg['memberId']!.isEmpty) return [];
    try {
      final uri = Uri.parse('${cfg['apiBase']}/api/rank')
          .replace(queryParameters: {'member_pk': cfg['memberId']!});
      final res = await http.get(uri, headers: await _headers())
          .timeout(const Duration(seconds: 8));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body) as Map<String, dynamic>;
        return List<Map<String, dynamic>>.from(data['ranks'] ?? []);
      }
    } catch (_) {}
    return [];
  }

  static Future<Map<String, dynamic>> checkRank(String keyword, String blogId) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return {'ok': false, 'error': '서버 미설정'};
    try {
      final res = await http.post(
        Uri.parse('${cfg['apiBase']}/api/rank/check'),
        headers: await _headers(),
        body: jsonEncode({
          'member_pk': cfg['memberId']!,
          'keyword': keyword,
          'blog_id': blogId,
        }),
      ).timeout(const Duration(seconds: 15));
      return jsonDecode(res.body) as Map<String, dynamic>;
    } catch (e) {
      return {'ok': false, 'error': '연결 실패: $e'};
    }
  }

  // ── 사례(Case) 제출 ──────────────────────────────────────────
  /// 이미지 파일 리스트와 함께 multipart/form-data로 전송
  static Future<Map<String, dynamic>> submitCase({
    required String caseTitle,
    required String rawContent,
    List<File> images = const [],
  }) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return {'ok': false, 'error': '서버 미설정'};
    try {
      final uri = Uri.parse('${cfg['apiBase']}/api/case/submit');
      final req = http.MultipartRequest('POST', uri);

      // 인증 헤더
      final token = (cfg['apiToken'] as String?) ?? '';
      if (token.isNotEmpty) req.headers['Authorization'] = 'Bearer $token';

      // 텍스트 필드
      req.fields['member_pk']   = cfg['memberId']!;
      req.fields['case_title']  = caseTitle;
      req.fields['raw_content'] = rawContent;

      // 이미지 파일들
      for (final file in images) {
        final name = file.path.split('/').last;
        req.files.add(await http.MultipartFile.fromPath(
          'case_images', file.path, filename: name,
        ));
      }

      final streamed = await req.send().timeout(const Duration(seconds: 30));
      final body = await streamed.stream.bytesToString();
      return jsonDecode(body) as Map<String, dynamic>;
    } catch (e) {
      return {'ok': false, 'error': '연결 실패: $e'};
    }
  }

  // ── 내 사례 목록 ─────────────────────────────────────────────
  static Future<List<Map<String, dynamic>>> fetchCases() async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty || cfg['memberId']!.isEmpty) return [];
    try {
      final uri = Uri.parse('${cfg['apiBase']}/api/case')
          .replace(queryParameters: {'member_pk': cfg['memberId']!});
      final res = await http.get(uri, headers: await _headers())
          .timeout(const Duration(seconds: 10));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        return List<Map<String, dynamic>>.from(data is List ? data : []);
      }
    } catch (_) {}
    return [];
  }

  // ── 산출물(Outputs) 목록 ─────────────────────────────────────
  static Future<Map<String, dynamic>> fetchOutputs({int page = 1}) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty || cfg['memberId']!.isEmpty) {
      return {'ok': false, 'items': [], 'total': 0};
    }
    try {
      final uri = Uri.parse('${cfg['apiBase']}/api/outputs').replace(
        queryParameters: {'member_pk': cfg['memberId']!, 'page': '$page'},
      );
      final res = await http.get(uri, headers: await _headers())
          .timeout(const Duration(seconds: 10));
      if (res.statusCode == 200) return jsonDecode(res.body) as Map<String, dynamic>;
    } catch (_) {}
    return {'ok': false, 'items': [], 'total': 0};
  }
}
