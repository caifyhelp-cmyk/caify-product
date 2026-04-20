import 'dart:convert';
import 'package:http/http.dart' as http;
import '../models/chat_message.dart';
import 'api_service.dart';

class ChatService {
  // GET /api/messages?member_pk=X&after_id=Y
  static Future<List<ChatMessage>> fetchMessages({int afterId = 0}) async {
    final cfg = await ApiService.loadConfig();
    if (cfg['apiBase']!.isEmpty) return [];

    final uri = Uri.parse('${cfg['apiBase']}/api/messages').replace(
      queryParameters: {
        'member_pk': cfg['memberId']!,
        if (afterId > 0) 'after_id': afterId.toString(),
      },
    );

    try {
      final headers = await ApiService.authHeaders();
      final res = await http.get(uri, headers: headers);
      if (res.statusCode != 200) return [];
      final list = jsonDecode(res.body) as List<dynamic>;
      return list
          .map((j) => ChatMessage.fromJson(j as Map<String, dynamic>))
          .toList();
    } catch (_) {
      return [];
    }
  }

  // POST /api/messages/:id/action  { action_key: ... }
  static Future<bool> sendAction(int messageId, String actionKey,
      {String? userText}) async {
    final cfg = await ApiService.loadConfig();
    if (cfg['apiBase']!.isEmpty) return false;

    try {
      final headers = await ApiService.authHeaders();
      final res = await http.post(
        Uri.parse('${cfg['apiBase']}/api/messages/$messageId/action'),
        headers: headers,
        body: jsonEncode({
          'action_key': actionKey,
          if (userText != null) 'user_text': userText,
        }),
      );
      return res.statusCode == 200 || res.statusCode == 204;
    } catch (_) {
      return false;
    }
  }

  // POST /api/messages  { member_pk, text }  — 고객 직접 입력
  static Future<bool> sendText(String text) async {
    final cfg = await ApiService.loadConfig();
    if (cfg['apiBase']!.isEmpty) return false;

    try {
      final headers = await ApiService.authHeaders();
      final res = await http.post(
        Uri.parse('${cfg['apiBase']}/api/messages'),
        headers: headers,
        body: jsonEncode({'member_pk': cfg['memberId'], 'text': text}),
      );
      return res.statusCode == 200 || res.statusCode == 201;
    } catch (_) {
      return false;
    }
  }

  // POST /api/messages/:id/read
  static Future<void> markRead(int messageId) async {
    final cfg = await ApiService.loadConfig();
    if (cfg['apiBase']!.isEmpty) return;
    try {
      final headers = await ApiService.authHeaders();
      await http.post(
        Uri.parse('${cfg['apiBase']}/api/messages/$messageId/read'),
        headers: headers,
      );
    } catch (_) {}
  }
}
