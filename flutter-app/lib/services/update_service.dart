import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:open_file/open_file.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:path_provider/path_provider.dart';
import 'api_service.dart';

const _installChannel = MethodChannel('caify/install');

class UpdateService {
  /// 앱 시작 시 호출. 새 버전이 있으면 업데이트 다이얼로그 표시.
  static Future<void> checkAndPrompt(BuildContext context) async {
    try {
      final info = await _fetchVersionInfo();
      if (info == null) return;

      final pkg = await PackageInfo.fromPlatform();
      if (!_isNewer(info['version'], pkg.version)) return;

      if (!context.mounted) return;
      _showDialog(context, info);
    } catch (_) {
      // 업데이트 체크 실패는 조용히 무시 (서비스 영향 없음)
    }
  }

  // ── 버전 정보 fetch ─────────────────────────────────────────
  static Future<Map<String, dynamic>?> _fetchVersionInfo() async {
    // 업데이트 체크는 항상 기본 서버(Render)로 — 저장된 URL이 죽어있을 수 있음
    final base = ApiService.defaultApiBase;

    // 백그라운드 체크이므로 넉넉하게 대기 (Render cold start ~30초)
    http.Response? res;
    for (int attempt = 0; attempt < 2; attempt++) {
      try {
        res = await http
            .get(Uri.parse('$base/api/version'))
            .timeout(const Duration(seconds: 30));
        if (res.statusCode == 200) break;
      } catch (_) {
        if (attempt == 1) return null;
        await Future.delayed(const Duration(seconds: 3));
      }
    }
    if (res == null || res.statusCode != 200) return null;

    final body = res.body.trim();
    // JSON 응답 파싱
    if (body.startsWith('{')) {
      final Map<String, dynamic> data = {};
      // 간단 파싱 (dart:convert import 없이)
      final vMatch = RegExp(r'"version"\s*:\s*"([^"]+)"').firstMatch(body);
      final uMatch = RegExp(r'"apk_url"\s*:\s*"([^"]+)"').firstMatch(body);
      final nMatch = RegExp(r'"notes"\s*:\s*"([^"]*)"').firstMatch(body);
      if (vMatch == null || uMatch == null) return null;
      data['version'] = vMatch.group(1)!;
      data['apk_url'] = uMatch.group(1)!;
      data['notes']   = nMatch?.group(1) ?? '';
      return data;
    }
    return null;
  }

  // ── 버전 비교 (semantic: major.minor.patch) ─────────────────
  static bool _isNewer(String remote, String current) {
    try {
      final r = remote.split('.').map(int.parse).toList();
      final c = current.split('.').map(int.parse).toList();
      for (int i = 0; i < 3; i++) {
        final ri = i < r.length ? r[i] : 0;
        final ci = i < c.length ? c[i] : 0;
        if (ri > ci) return true;
        if (ri < ci) return false;
      }
      return false;
    } catch (_) {
      return false;
    }
  }

  // ── 업데이트 다이얼로그 ─────────────────────────────────────
  static void _showDialog(BuildContext context, Map<String, dynamic> info) {
    final notes = info['notes'] as String? ?? '';
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => _UpdateDialog(
        newVersion: info['version'] as String,
        apkUrl:     info['apk_url'] as String,
        notes:      notes,
      ),
    );
  }
}

class _UpdateDialog extends StatefulWidget {
  final String newVersion;
  final String apkUrl;
  final String notes;

  const _UpdateDialog({
    required this.newVersion,
    required this.apkUrl,
    required this.notes,
  });

  @override
  State<_UpdateDialog> createState() => _UpdateDialogState();
}

class _UpdateDialogState extends State<_UpdateDialog> {
  _Phase _phase = _Phase.idle;
  double _progress = 0;
  String _errorMsg = '';

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: _phase == _Phase.idle || _phase == _Phase.done || _phase == _Phase.error,
      child: AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        title: Row(
          children: [
            const Icon(Icons.system_update, color: Color(0xFF03C75A)),
            const SizedBox(width: 10),
            Text('업데이트 v${widget.newVersion}',
                style: const TextStyle(fontSize: 17)),
          ],
        ),
        content: _buildContent(),
        actions: _buildActions(),
      ),
    );
  }

  Widget _buildContent() {
    switch (_phase) {
      case _Phase.idle:
        return Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('새 버전이 있습니다.\n지금 업데이트 하시겠습니까?'),
            if (widget.notes.isNotEmpty) ...[
              const SizedBox(height: 12),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: const Color(0xFFF5F5F5),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(widget.notes,
                    style: const TextStyle(fontSize: 12, color: Colors.black54)),
              ),
            ],
          ],
        );

      case _Phase.downloading:
        return Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            LinearProgressIndicator(
              value: _progress,
              backgroundColor: const Color(0xFFE0E0E0),
              color: const Color(0xFF03C75A),
              minHeight: 8,
              borderRadius: BorderRadius.circular(4),
            ),
            const SizedBox(height: 12),
            Text('다운로드 중... ${(_progress * 100).toInt()}%',
                style: const TextStyle(fontSize: 13, color: Colors.black54)),
          ],
        );

      case _Phase.done:
        return const Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('다운로드 완료! 설치를 진행하세요.'),
            SizedBox(height: 10),
            Text(
              '💡 설치 중 "출처 불명 앱" 경고가 뜨면\n'
              '"허용"을 눌러주세요.\n\n'
              '앱 검사를 끄려면:\n'
              'Google Play → 프로필 → Play Protect\n'
              '→ 설정(⚙) → "앱 검사 개선" 끄기',
              style: TextStyle(fontSize: 11, color: Colors.black54),
            ),
          ],
        );

      case _Phase.error:
        return Text('오류: $_errorMsg',
            style: const TextStyle(color: Colors.red));
    }
  }

  List<Widget> _buildActions() {
    switch (_phase) {
      case _Phase.idle:
        return [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('나중에', style: TextStyle(color: Colors.grey)),
          ),
          ElevatedButton(
            onPressed: _startDownload,
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF03C75A),
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(8)),
            ),
            child: const Text('업데이트'),
          ),
        ];

      case _Phase.downloading:
        return [
          TextButton(
            onPressed: null,
            child: const Text('다운로드 중...', style: TextStyle(color: Colors.grey)),
          ),
        ];

      case _Phase.done:
        return [
          ElevatedButton(
            onPressed: _install,
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF03C75A),
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(8)),
            ),
            child: const Text('설치하기'),
          ),
        ];

      case _Phase.error:
        return [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('닫기'),
          ),
          TextButton(
            onPressed: _startDownload,
            child: const Text('재시도'),
          ),
        ];
    }
  }

  Future<void> _startDownload() async {
    setState(() { _phase = _Phase.downloading; _progress = 0; });

    try {
      final dir = await getTemporaryDirectory();
      final savePath = '${dir.path}/caify_update.apk';

      final req = http.Request('GET', Uri.parse(widget.apkUrl));
      final streamed = await req.send().timeout(const Duration(minutes: 5));
      final total = streamed.contentLength ?? 0;
      int received = 0;

      final sink = File(savePath).openWrite();
      await for (final chunk in streamed.stream) {
        sink.add(chunk);
        received += chunk.length;
        if (total > 0 && mounted) {
          setState(() => _progress = received / total);
        }
      }
      await sink.flush();
      await sink.close();

      if (mounted) setState(() { _phase = _Phase.done; _progress = 1.0; });
    } catch (e) {
      if (mounted) setState(() { _phase = _Phase.error; _errorMsg = e.toString(); });
    }
  }

  Future<void> _install() async {
    try {
      // Android 8+: 출처 불명 앱 설치 권한 런타임 체크
      final canInstall = await _installChannel.invokeMethod<bool>('canInstall') ?? true;
      if (!canInstall) {
        if (!mounted) return;
        setState(() {
          _phase    = _Phase.error;
          _errorMsg = '설치 권한이 필요합니다.\n설정 화면에서 "이 소스 허용"을 켜주세요.';
        });
        // 설정 화면 바로 열기
        await _installChannel.invokeMethod('openInstallSettings');
        return;
      }

      final dir = await getTemporaryDirectory();
      final apkPath = '${dir.path}/caify_update.apk';
      final result = await OpenFile.open(
          apkPath, type: 'application/vnd.android.package-archive');

      if (result.type != ResultType.done && mounted) {
        setState(() {
          _phase    = _Phase.error;
          _errorMsg = '설치 실패: ${result.message}\n\n'
              '직접 설치하려면 설정 → 앱 → 출처 불명 앱 허용 후 다시 시도해 주세요.';
        });
      }
    } catch (e) {
      if (mounted) setState(() { _phase = _Phase.error; _errorMsg = '설치 실행 실패: $e'; });
    }
  }
}

enum _Phase { idle, downloading, done, error }
