import 'dart:async';
import 'package:flutter/material.dart';
import '../models/chat_message.dart';
import '../models/post.dart';
import '../services/chat_service.dart';
import '../services/api_service.dart';
import '../services/app_logger.dart';
import 'post_viewer_screen.dart';
import 'publish_screen.dart';

class ChatScreen extends StatefulWidget {
  const ChatScreen({super.key});

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  final List<ChatMessage> _messages = [];
  final _textCtrl  = TextEditingController();
  final _scrollCtrl = ScrollController();
  Timer? _pollTimer;
  bool _sending = false;
  int _lastId = 0;

  @override
  void initState() {
    super.initState();
    _load(initial: true);
    _pollTimer = Timer.periodic(const Duration(seconds: 15), (_) => _load());
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    _textCtrl.dispose();
    _scrollCtrl.dispose();
    super.dispose();
  }

  Future<void> _load({bool initial = false}) async {
    if (initial) AppLogger.info('CHAT', '채팅 초기 로드');
    final newMsgs = await ChatService.fetchMessages(
        afterId: initial ? 0 : _lastId);
    if (!mounted || newMsgs.isEmpty) return;
    if (newMsgs.isNotEmpty) {
      AppLogger.info('CHAT', '새 메시지 ${newMsgs.length}개 수신 (lastId=${newMsgs.last.id})');
    }
    setState(() {
      if (initial) {
        _messages
          ..clear()
          ..addAll(newMsgs);
      } else {
        _messages.addAll(newMsgs);
      }
      _lastId = _messages.fold(0, (m, e) => e.id > m ? e.id : m);
    });
    _scrollToBottom();

    // 읽음 처리
    for (final m in newMsgs.where((m) => m.isSystem && !m.read)) {
      ChatService.markRead(m.id);
    }
  }

  void _scrollToBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scrollCtrl.hasClients) {
        _scrollCtrl.animateTo(
          _scrollCtrl.position.maxScrollExtent,
          duration: const Duration(milliseconds: 300),
          curve: Curves.easeOut,
        );
      }
    });
  }

  Future<void> _sendText() async {
    final text = _textCtrl.text.trim();
    if (text.isEmpty || _sending) return;
    AppLogger.info('CHAT', '메시지 전송: "$text"');
    _textCtrl.clear();
    setState(() => _sending = true);
    await ChatService.sendText(text);
    setState(() => _sending = false);
    await _load();
  }

  Future<void> _doAction(ChatMessage msg, ChatAction action) async {
    final key = action.actionKey;
    AppLogger.info('CHAT', '액션 버튼 탭: key=$key postId=${msg.postId}');

    if (key == 'view_post' && msg.postId != null) {
      // 포스트 뷰어 열기
      final post = Post(
        id: msg.postId!,
        title: msg.postTitle ?? '',
        html: msg.postHtml ?? '',
        tags: [],
        createdAt: '',
      );
      await Navigator.push(
        context,
        MaterialPageRoute(builder: (_) => PostViewerScreen(post: post)),
      );
      return;
    }

    if (key == 'publish_post' && msg.postId != null) {
      final post = Post(
        id: msg.postId!,
        title: msg.postTitle ?? '',
        html: msg.postHtml ?? '',
        tags: [],
        createdAt: '',
      );
      await Navigator.push(
        context,
        MaterialPageRoute(builder: (_) => PublishScreen(post: post)),
      );
    }

    // 서버에 액션 전송
    await ChatService.sendAction(msg.id, key);
    await _load();
  }

  // ── 말풍선 UI ─────────────────────────────────────────────────
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF0F0F0),
      appBar: AppBar(
        title: const Text('Caify', style: TextStyle(fontWeight: FontWeight.bold)),
        backgroundColor: const Color(0xFF03C75A),
        foregroundColor: Colors.white,
        elevation: 0,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: () => _load(initial: true),
          ),
        ],
      ),
      body: Column(
        children: [
          Expanded(
            child: _messages.isEmpty
                ? const Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.chat_bubble_outline,
                            size: 48, color: Colors.grey),
                        SizedBox(height: 12),
                        Text('새로운 알림이 없습니다',
                            style: TextStyle(color: Colors.grey)),
                      ],
                    ),
                  )
                : ListView.builder(
                    controller: _scrollCtrl,
                    padding: const EdgeInsets.symmetric(
                        vertical: 12, horizontal: 10),
                    itemCount: _messages.length,
                    itemBuilder: (_, i) => _buildBubble(_messages[i]),
                  ),
          ),
          _buildInputBar(),
        ],
      ),
    );
  }

  Widget _buildBubble(ChatMessage msg) {
    final isSystem = msg.isSystem;
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment:
            isSystem ? MainAxisAlignment.start : MainAxisAlignment.end,
        children: [
          if (isSystem) ...[
            CircleAvatar(
              radius: 16,
              backgroundColor: const Color(0xFF03C75A),
              child: const Text('C',
                  style: TextStyle(
                      color: Colors.white,
                      fontSize: 13,
                      fontWeight: FontWeight.bold)),
            ),
            const SizedBox(width: 8),
          ],
          Flexible(
            child: Column(
              crossAxisAlignment: isSystem
                  ? CrossAxisAlignment.start
                  : CrossAxisAlignment.end,
              children: [
                if (isSystem)
                  const Padding(
                    padding: EdgeInsets.only(bottom: 4),
                    child: Text('Caify',
                        style: TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.w600,
                            color: Colors.black54)),
                  ),
                Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 14, vertical: 11),
                  decoration: BoxDecoration(
                    color: isSystem ? Colors.white : const Color(0xFF03C75A),
                    borderRadius: BorderRadius.only(
                      topLeft:     Radius.circular(isSystem ? 4 : 16),
                      topRight:    Radius.circular(isSystem ? 16 : 4),
                      bottomLeft:  const Radius.circular(16),
                      bottomRight: const Radius.circular(16),
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withOpacity(0.06),
                        blurRadius: 4,
                        offset: const Offset(0, 2),
                      ),
                    ],
                  ),
                  child: Text(
                    msg.text,
                    style: TextStyle(
                      fontSize: 14,
                      color: isSystem ? Colors.black87 : Colors.white,
                      height: 1.5,
                    ),
                  ),
                ),

                // 액션 버튼
                if (msg.actions.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 6,
                    children: msg.actions
                        .map((a) => _buildActionBtn(msg, a))
                        .toList(),
                  ),
                ],

                // 시간
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(
                    _formatTime(msg.createdAt),
                    style: const TextStyle(
                        fontSize: 11, color: Colors.grey),
                  ),
                ),
              ],
            ),
          ),
          if (!isSystem) const SizedBox(width: 8),
        ],
      ),
    );
  }

  Widget _buildActionBtn(ChatMessage msg, ChatAction action) {
    final isView = action.actionKey == 'view_post';
    final isPublish = action.actionKey == 'publish_post';

    return OutlinedButton(
      onPressed: () => _doAction(msg, action),
      style: OutlinedButton.styleFrom(
        foregroundColor: isPublish
            ? Colors.white
            : const Color(0xFF03C75A),
        backgroundColor: isPublish
            ? const Color(0xFF03C75A)
            : Colors.white,
        side: const BorderSide(color: Color(0xFF03C75A)),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        textStyle: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500),
        minimumSize: Size.zero,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (isView)   const Icon(Icons.article_outlined, size: 14),
          if (isPublish) const Icon(Icons.send_outlined, size: 14),
          if (isView || isPublish) const SizedBox(width: 4),
          Text(action.label),
        ],
      ),
    );
  }

  Widget _buildInputBar() {
    return Container(
      color: Colors.white,
      padding: EdgeInsets.only(
        left: 12, right: 8, top: 8,
        bottom: MediaQuery.of(context).viewInsets.bottom + 8,
      ),
      child: SafeArea(
        top: false,
        child: Row(
          children: [
            Expanded(
              child: TextField(
                controller: _textCtrl,
                minLines: 1,
                maxLines: 4,
                textInputAction: TextInputAction.send,
                onSubmitted: (_) => _sendText(),
                decoration: InputDecoration(
                  hintText: '수정 요청, 방향성 등을 입력하세요...',
                  hintStyle: const TextStyle(fontSize: 14, color: Colors.grey),
                  filled: true,
                  fillColor: const Color(0xFFF5F5F5),
                  contentPadding: const EdgeInsets.symmetric(
                      horizontal: 16, vertical: 10),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(24),
                    borderSide: BorderSide.none,
                  ),
                ),
              ),
            ),
            const SizedBox(width: 8),
            _sending
                ? const SizedBox(
                    width: 40, height: 40,
                    child: CircularProgressIndicator(
                        strokeWidth: 2, color: Color(0xFF03C75A)))
                : IconButton(
                    onPressed: _sendText,
                    icon: const Icon(Icons.send_rounded),
                    color: const Color(0xFF03C75A),
                    iconSize: 26,
                  ),
          ],
        ),
      ),
    );
  }

  static String _formatTime(DateTime dt) {
    final h = dt.hour.toString().padLeft(2, '0');
    final m = dt.minute.toString().padLeft(2, '0');
    return '$h:$m';
  }
}
