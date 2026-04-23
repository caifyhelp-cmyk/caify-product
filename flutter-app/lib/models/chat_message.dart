enum MsgType {
  postCreated,         // 포스팅 생성 알림 → 확인/수정요청 액션
  postModified,        // 수정 완료 알림 → 확인 액션
  postPublished,       // 발행 완료 알림 → 없음
  postFailed,          // 발행 실패 알림 → 재시도/무시 액션
  rankCheck,           // 순위 확인 결과 → 없음
  rankWinner,          // 순위 1-3위 달성 → 없음
  strategyWeekly,      // 주간 전략 요약 → 없음
  sessionExpired,      // 세션 만료 → 없음
  workflowUpdated,     // 워크플로우 커스터마이징 적용 완료
  workflowProvisioned, // 유료 플랜 활성화 + 워크플로우 생성
  userText,            // 고객이 직접 입력한 메시지
}

class ChatAction {
  final String label;
  final String actionKey;
  const ChatAction({required this.label, required this.actionKey});

  factory ChatAction.fromJson(Map<String, dynamic> j) =>
      ChatAction(label: j['label'] as String, actionKey: j['action_key'] as String);
}

class ChatMessage {
  final int id;
  final MsgType type;
  final bool isSystem;   // true = 시스템 발신, false = 고객 발신
  final String text;
  final int? postId;
  final String? postTitle;
  final String? postHtml;
  final Map<String, dynamic>? meta;
  final List<ChatAction> actions;
  final DateTime createdAt;
  final bool read;

  const ChatMessage({
    required this.id,
    required this.type,
    required this.isSystem,
    required this.text,
    this.postId,
    this.postTitle,
    this.postHtml,
    this.meta,
    this.actions = const [],
    required this.createdAt,
    this.read = false,
  });

  factory ChatMessage.fromJson(Map<String, dynamic> j) {
    final typeStr = j['type'] as String? ?? 'user_text';
    final type = _typeFromStr(typeStr);

    final actionsRaw = j['actions'] as List<dynamic>? ?? [];
    final actions = actionsRaw
        .map((a) => ChatAction.fromJson(a as Map<String, dynamic>))
        .toList();

    return ChatMessage(
      id:         (j['id'] as num).toInt(),
      type:       type,
      isSystem:   j['is_system'] as bool? ?? true,
      text:       j['text'] as String? ?? '',
      postId:     j['post_id'] != null ? (j['post_id'] as num).toInt() : null,
      postTitle:  j['post_title'] as String?,
      postHtml:   j['post_html'] as String?,
      meta:       j['meta'] as Map<String, dynamic>?,
      actions:    actions,
      createdAt:  DateTime.tryParse(j['created_at'] as String? ?? '') ?? DateTime.now(),
      read:       j['read'] as bool? ?? false,
    );
  }

  static MsgType _typeFromStr(String s) {
    switch (s) {
      case 'post.created':   return MsgType.postCreated;
      case 'post.modified':  return MsgType.postModified;
      case 'post.published': return MsgType.postPublished;
      case 'post.failed':    return MsgType.postFailed;
      case 'rank.check':     return MsgType.rankCheck;
      case 'rank.winner':    return MsgType.rankWinner;
      case 'strategy.weekly':      return MsgType.strategyWeekly;
      case 'session.expired':      return MsgType.sessionExpired;
      case 'workflow.updated':     return MsgType.workflowUpdated;
      case 'workflow.provisioned': return MsgType.workflowProvisioned;
      default:                     return MsgType.userText;
    }
  }
}
