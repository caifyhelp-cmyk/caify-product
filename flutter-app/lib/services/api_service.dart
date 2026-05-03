import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../models/post.dart';
import 'app_logger.dart';

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
    AppLogger.info('API', 'POST $base/member/login [id=$memberId]');
    try {
      final res = await http.post(
        Uri.parse('$base/member/login'),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'member_id': memberId, 'passwd': password}),
      ).timeout(const Duration(seconds: 10));

      AppLogger.info('API', 'login ← ${res.statusCode} ${res.body.length}b');
      final data = jsonDecode(res.body) as Map<String, dynamic>;
      if (res.statusCode == 200 && data['ok'] == true) {
        AppLogger.info('API', 'login OK — pk=${data['member_pk']} tier=${data['tier']}');
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
      AppLogger.warn('API', 'login FAIL — ${data['error']}');
      return {'ok': false, 'error': data['error'] ?? '로그인 실패'};
    } catch (e) {
      AppLogger.error('API', 'login ERR — $e');
      return {'ok': false, 'error': '서버 연결 실패: $e'};
    }
  }

  // ── 내 정보 (tier + 워크플로우 현황) ─────────────────────────────
  /// 서버에서 최신 플랜 정보를 가져와 로컬에도 반영
  static Future<Map<String, dynamic>?> fetchMe() async {
    final cfg = await loadConfig();
    if ((cfg['apiBase'] as String).isEmpty) return null;

    AppLogger.info('API', 'GET /member/me');
    try {
      final res = await http.get(
        Uri.parse('${cfg['apiBase']}/member/me'),
        headers: await authHeaders(),
      ).timeout(const Duration(seconds: 10));

      AppLogger.info('API', 'fetchMe ← ${res.statusCode}');
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
    } catch (e) {
      AppLogger.error('API', 'fetchMe ERR — $e');
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

    AppLogger.info('API', 'GET /api/posts?status=$status');
    try {
      final res = await http.get(uri, headers: await _headers())
          .timeout(const Duration(seconds: 10));
      if (res.statusCode != 200) {
        AppLogger.warn('API', 'fetchPosts ← ${res.statusCode}');
        return [];
      }
      final data = jsonDecode(res.body);
      final list = data is List ? data : (data['posts'] as List? ?? []);
      AppLogger.info('API', 'fetchPosts ← ${res.statusCode} [${list.length}개]');
      return list.map((j) => Post.fromJson(j as Map<String, dynamic>)).toList();
    } catch (e) {
      AppLogger.error('API', 'fetchPosts ERR — $e');
      return [];
    }
  }

  // ── 포스팅 모드 조회/변경 ─────────────────────────────────────
  static Future<Map<String, dynamic>?> fetchPostingMode() async {
    final cfg = await loadConfig();
    if ((cfg['apiBase'] as String).isEmpty) return null;
    AppLogger.info('MODE', 'GET /api/posting-mode');
    try {
      final res = await http.get(
        Uri.parse('${cfg['apiBase']}/api/posting-mode'),
        headers: await authHeaders(),
      ).timeout(const Duration(seconds: 10));
      AppLogger.info('MODE', 'fetchPostingMode ← ${res.statusCode} ${res.body}');
      if (res.statusCode != 200) return null;
      return jsonDecode(res.body) as Map<String, dynamic>;
    } catch (e) {
      AppLogger.error('MODE', 'fetchPostingMode ERR — $e');
      return null;
    }
  }

  /// mode: 'intensive' | 'mixed'
  static Future<Map<String, dynamic>> requestModeChange(String mode) async {
    final cfg = await loadConfig();
    AppLogger.info('MODE', 'POST /api/posting-mode mode=$mode');
    try {
      final res = await http.post(
        Uri.parse('${cfg['apiBase']}/api/posting-mode'),
        headers: await authHeaders(),
        body: jsonEncode({'mode': mode}),
      ).timeout(const Duration(seconds: 10));
      AppLogger.info('MODE', 'requestModeChange ← ${res.statusCode} ${res.body}');
      return jsonDecode(res.body) as Map<String, dynamic>;
    } catch (e) {
      AppLogger.error('MODE', 'requestModeChange ERR — $e');
      return {'ok': false, 'error': '서버 연결 실패: $e'};
    }
  }

  // ── 발행 설정 (정렬·폰트) ────────────────────────────────────
  /// null 반환 = 미설정(최초)
  static Future<Map<String, String?>> fetchPublishSettings() async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return {'align': null, 'font': null};
    try {
      final res = await http.get(
        Uri.parse('${cfg['apiBase']}/api/publish-settings'),
        headers: await _headers(),
      ).timeout(const Duration(seconds: 8));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body) as Map<String, dynamic>;
        return {
          'align': data['align'] as String?,
          'font':  data['font']  as String?,
        };
      }
    } catch (e) {
      AppLogger.error('API', 'fetchPublishSettings ERR — $e');
    }
    return {'align': null, 'font': null};
  }

  static Future<bool> savePublishSettings({required String align, required String font}) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return false;
    try {
      final res = await http.post(
        Uri.parse('${cfg['apiBase']}/api/publish-settings'),
        headers: await _headers(),
        body: jsonEncode({'align': align, 'font': font}),
      ).timeout(const Duration(seconds: 8));
      return res.statusCode == 200;
    } catch (e) {
      AppLogger.error('API', 'savePublishSettings ERR — $e');
      return false;
    }
  }

  // ── 발행 완료 통보 ───────────────────────────────────────────
  static Future<bool> markPublished(int postId) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return false;
    AppLogger.info('PUB', 'POST /api/posts/$postId/published');
    try {
      final res = await http.post(
        Uri.parse('${cfg['apiBase']}/api/posts/$postId/published'),
        headers: await _headers(),
      );
      AppLogger.info('PUB', 'markPublished ← ${res.statusCode}');
      return res.statusCode == 200 || res.statusCode == 204;
    } catch (e) {
      AppLogger.error('PUB', 'markPublished ERR — $e');
      return false;
    }
  }

  // ── 발행 실패 통보 ───────────────────────────────────────────
  static Future<void> markFailed(int postId, String reason) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return;
    AppLogger.warn('PUB', 'POST /api/posts/$postId/failed reason=$reason');
    try {
      await http.post(
        Uri.parse('${cfg['apiBase']}/api/posts/$postId/failed'),
        headers: await _headers(),
        body: jsonEncode({'reason': reason}),
      );
    } catch (e) {
      AppLogger.error('PUB', 'markFailed ERR — $e');
    }
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
    if (cfg['apiBase']!.isEmpty || cfg['memberId']!.isEmpty) {
      AppLogger.warn('WF', 'fetchWorkflow — 서버/멤버 미설정');
      return null;
    }
    AppLogger.info('WF', 'GET /api/workflow [pk=${cfg['memberId']}]');
    try {
      final uri = Uri.parse('${cfg['apiBase']}/api/workflow')
          .replace(queryParameters: {'member_pk': cfg['memberId']!});
      final res = await http.get(uri, headers: await _headers())
          .timeout(const Duration(seconds: 8));
      AppLogger.info('WF', 'fetchWorkflow ← ${res.statusCode} ${res.body.length}b');
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body) as Map<String, dynamic>;
        AppLogger.info('WF', 'fetchWorkflow data: provisioned=${data['provisioned']} wfs=${(data['workflows'] as List?)?.length ?? 0}');
        return data;
      }
      AppLogger.warn('WF', 'fetchWorkflow ← ${res.statusCode} body=${res.body}');
    } catch (e) {
      AppLogger.error('WF', 'fetchWorkflow ERR — $e');
    }
    return null;
  }

  static Future<Map<String, dynamic>> provisionWorkflow() async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return {'ok': false, 'error': '서버 미설정'};
    AppLogger.info('WF', 'POST /api/workflow/provision [pk=${cfg['memberId']}]');
    try {
      final res = await http.post(
        Uri.parse('${cfg['apiBase']}/api/workflow/provision'),
        headers: await _headers(),
        body: jsonEncode({'member_pk': cfg['memberId']!}),
      ).timeout(const Duration(seconds: 15));
      AppLogger.info('WF', 'provision ← ${res.statusCode} ${res.body}');
      final data = jsonDecode(res.body) as Map<String, dynamic>;
      if (data['ok'] != true) AppLogger.warn('WF', 'provision FAIL — ${data['error']}');
      return data;
    } catch (e) {
      AppLogger.error('WF', 'provision ERR — $e');
      return {'ok': false, 'error': '연결 실패: $e'};
    }
  }

  static Future<Map<String, dynamic>> modifyWorkflow(String instruction) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return {'ok': false, 'error': '서버 미설정'};
    AppLogger.info('WF', 'POST /api/workflow/modify instruction="${instruction.length > 40 ? instruction.substring(0,40) : instruction}"');
    try {
      final res = await http.post(
        Uri.parse('${cfg['apiBase']}/api/workflow/modify'),
        headers: await _headers(),
        body: jsonEncode({'member_pk': cfg['memberId']!, 'instruction': instruction}),
      ).timeout(const Duration(seconds: 10));
      AppLogger.info('WF', 'modify ← ${res.statusCode} ${res.body}');
      final data = jsonDecode(res.body) as Map<String, dynamic>;
      if (data['ok'] != true) AppLogger.warn('WF', 'modify FAIL — ${data['error']}');
      return data;
    } catch (e) {
      AppLogger.error('WF', 'modify ERR — $e');
      return {'ok': false, 'error': '연결 실패: $e'};
    }
  }

  static Future<Map<String, dynamic>> updateWorkflow({
    List<String>? scheduleDays,
    int? scheduleHour,
    List<Map<String, dynamic>>? workflows,
  }) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return {'ok': false, 'error': '서버 미설정'};
    AppLogger.info('WF', 'POST /api/workflow/update');
    try {
      final body = <String, dynamic>{'member_pk': cfg['memberId']!};
      if (scheduleDays != null) body['schedule_days'] = scheduleDays;
      if (scheduleHour != null) body['schedule_hour'] = scheduleHour;
      if (workflows != null) body['workflows'] = workflows;
      final res = await http.post(
        Uri.parse('${cfg['apiBase']}/api/workflow/update'),
        headers: await _headers(),
        body: jsonEncode(body),
      ).timeout(const Duration(seconds: 10));
      AppLogger.info('WF', 'update ← ${res.statusCode} ${res.body}');
      return jsonDecode(res.body) as Map<String, dynamic>;
    } catch (e) {
      AppLogger.error('WF', 'update ERR — $e');
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
    AppLogger.info('CASE', 'POST /api/case/submit title="$caseTitle" images=${images.length}');
    try {
      final uri = Uri.parse('${cfg['apiBase']}/api/case/submit');
      final req = http.MultipartRequest('POST', uri);

      final token = (cfg['apiToken'] as String?) ?? '';
      if (token.isNotEmpty) req.headers['Authorization'] = 'Bearer $token';

      req.fields['member_pk']   = cfg['memberId']!;
      req.fields['case_title']  = caseTitle;
      req.fields['raw_content'] = rawContent;

      for (final file in images) {
        final name = file.path.split('/').last;
        req.files.add(await http.MultipartFile.fromPath(
          'case_images', file.path, filename: name,
        ));
      }

      final streamed = await req.send().timeout(const Duration(seconds: 30));
      final body = await streamed.stream.bytesToString();
      AppLogger.info('CASE', 'submitCase ← ${streamed.statusCode} $body');
      final data = jsonDecode(body) as Map<String, dynamic>;
      if (data['ok'] != true) AppLogger.warn('CASE', 'submitCase FAIL — ${data['error']}');
      return data;
    } catch (e) {
      AppLogger.error('CASE', 'submitCase ERR — $e');
      return {'ok': false, 'error': '연결 실패: $e'};
    }
  }

  // ── 내 사례 목록 ─────────────────────────────────────────────
  static Future<List<Map<String, dynamic>>> fetchCases() async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty || cfg['memberId']!.isEmpty) return [];
    AppLogger.info('CASE', 'GET /api/case [pk=${cfg['memberId']}]');
    try {
      final uri = Uri.parse('${cfg['apiBase']}/api/case')
          .replace(queryParameters: {'member_pk': cfg['memberId']!});
      final res = await http.get(uri, headers: await _headers())
          .timeout(const Duration(seconds: 10));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        final list = List<Map<String, dynamic>>.from(data is List ? data : []);
        AppLogger.info('CASE', 'fetchCases ← ${res.statusCode} [${list.length}개]');
        return list;
      }
      AppLogger.warn('CASE', 'fetchCases ← ${res.statusCode}');
    } catch (e) {
      AppLogger.error('CASE', 'fetchCases ERR — $e');
    }
    return [];
  }

  // ── 사례 진행 상태 조회 ──────────────────────────────────────
  static Future<Map<String, dynamic>> fetchCaseStatus(int caseId) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return {};
    try {
      final uri = Uri.parse('${cfg['apiBase']}/api/case/$caseId/status');
      final res = await http.get(uri, headers: await _headers())
          .timeout(const Duration(seconds: 8));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body) as Map<String, dynamic>;
        AppLogger.info('CASE', 'fetchCaseStatus[$caseId] ← ${data['ai_status']} ${data['progress'] ?? ''}%');
        return data;
      }
      AppLogger.warn('CASE', 'fetchCaseStatus[$caseId] ← ${res.statusCode}');
    } catch (e) {
      AppLogger.error('CASE', 'fetchCaseStatus ERR — $e');
    }
    return {};
  }

  // ── 포스팅 단건 조회 ─────────────────────────────────────────
  static Future<Map<String, dynamic>?> fetchPost(int postId) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty) return null;
    AppLogger.info('API', 'GET /api/posts/$postId');
    try {
      final res = await http.get(
        Uri.parse('${cfg['apiBase']}/api/posts/$postId'),
        headers: await _headers(),
      ).timeout(const Duration(seconds: 8));
      if (res.statusCode == 200) {
        return jsonDecode(res.body) as Map<String, dynamic>;
      }
      AppLogger.warn('API', 'fetchPost[$postId] ← ${res.statusCode}');
    } catch (e) {
      AppLogger.error('API', 'fetchPost ERR — $e');
    }
    return null;
  }

  // ── 산출물(Outputs) 목록 ─────────────────────────────────────
  static Future<Map<String, dynamic>> fetchOutputs({int page = 1}) async {
    final cfg = await loadConfig();
    if (cfg['apiBase']!.isEmpty || cfg['memberId']!.isEmpty) {
      AppLogger.warn('OUT', 'fetchOutputs — 서버/멤버 미설정');
      return {'ok': false, 'items': [], 'total': 0};
    }
    AppLogger.info('OUT', 'GET /api/outputs page=$page');
    try {
      final uri = Uri.parse('${cfg['apiBase']}/api/outputs').replace(
        queryParameters: {'member_pk': cfg['memberId']!, 'page': '$page'},
      );
      final res = await http.get(uri, headers: await _headers())
          .timeout(const Duration(seconds: 10));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body) as Map<String, dynamic>;
        AppLogger.info('OUT', 'fetchOutputs ← ${res.statusCode} total=${data['total']} items=${(data['items'] as List?)?.length ?? 0}');
        return data;
      }
      AppLogger.warn('OUT', 'fetchOutputs ← ${res.statusCode}');
    } catch (e) {
      AppLogger.error('OUT', 'fetchOutputs ERR — $e');
    }
    return {'ok': false, 'items': [], 'total': 0};
  }
}
