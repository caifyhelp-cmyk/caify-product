import 'dart:async';
import 'package:flutter/material.dart';
import '../services/app_logger.dart';

class LogViewerScreen extends StatefulWidget {
  const LogViewerScreen({super.key});

  @override
  State<LogViewerScreen> createState() => _LogViewerScreenState();
}

class _LogViewerScreenState extends State<LogViewerScreen> {
  Timer? _refreshTimer;
  String _filter = '';           // 태그 필터
  LogLevel? _levelFilter;        // 레벨 필터
  final _scrollCtrl = ScrollController();
  bool _autoScroll = true;

  static const _levelColors = {
    LogLevel.info:  Color(0xFF80CBC4),
    LogLevel.warn:  Color(0xFFFFCC80),
    LogLevel.error: Color(0xFFEF9A9A),
  };

  @override
  void initState() {
    super.initState();
    // 1초마다 자동 갱신
    _refreshTimer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
      if (_autoScroll) _scrollToBottom();
    });
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    _scrollCtrl.dispose();
    super.dispose();
  }

  void _scrollToBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scrollCtrl.hasClients) {
        _scrollCtrl.jumpTo(_scrollCtrl.position.maxScrollExtent);
      }
    });
  }

  List<LogEntry> get _filtered {
    var entries = AppLogger.entries.toList();
    if (_filter.isNotEmpty) {
      entries = entries.where((e) =>
        e.tag.toLowerCase().contains(_filter.toLowerCase()) ||
        e.msg.toLowerCase().contains(_filter.toLowerCase())
      ).toList();
    }
    if (_levelFilter != null) {
      entries = entries.where((e) => e.level == _levelFilter).toList();
    }
    return entries;
  }

  @override
  Widget build(BuildContext context) {
    final entries = _filtered;

    return Scaffold(
      backgroundColor: const Color(0xFF1A1A2E),
      appBar: AppBar(
        title: Text('로그 (${entries.length}개)',
            style: const TextStyle(fontFamily: 'monospace', fontSize: 15)),
        backgroundColor: const Color(0xFF16213E),
        foregroundColor: Colors.white,
        actions: [
          // 레벨 필터
          PopupMenuButton<LogLevel?>(
            icon: Icon(
              Icons.filter_list,
              color: _levelFilter == null ? Colors.white60 : Colors.greenAccent,
            ),
            onSelected: (v) => setState(() => _levelFilter = v),
            itemBuilder: (_) => [
              const PopupMenuItem(value: null,            child: Text('전체')),
              const PopupMenuItem(value: LogLevel.info,   child: Text('INFO')),
              const PopupMenuItem(value: LogLevel.warn,   child: Text('WARN', style: TextStyle(color: Colors.orange))),
              const PopupMenuItem(value: LogLevel.error,  child: Text('ERROR', style: TextStyle(color: Colors.red))),
            ],
          ),
          // 자동 스크롤 토글
          IconButton(
            icon: Icon(
              Icons.vertical_align_bottom,
              color: _autoScroll ? Colors.greenAccent : Colors.white38,
            ),
            tooltip: '자동 스크롤',
            onPressed: () => setState(() => _autoScroll = !_autoScroll),
          ),
          // 복사
          IconButton(
            icon: const Icon(Icons.copy, color: Colors.white70),
            tooltip: '전체 복사',
            onPressed: () async {
              await AppLogger.copyToClipboard();
              if (!mounted) return;
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(
                  content: Text('클립보드에 복사됐습니다'),
                  backgroundColor: Color(0xFF03C75A),
                  duration: Duration(seconds: 2),
                ),
              );
            },
          ),
          // 초기화
          IconButton(
            icon: const Icon(Icons.delete_outline, color: Colors.white54),
            tooltip: '로그 초기화',
            onPressed: () {
              AppLogger.clear();
              setState(() {});
            },
          ),
        ],
      ),
      body: Column(
        children: [
          // 검색/필터 바
          Container(
            color: const Color(0xFF16213E),
            padding: const EdgeInsets.fromLTRB(12, 4, 12, 8),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    style: const TextStyle(color: Colors.white, fontSize: 13),
                    decoration: InputDecoration(
                      hintText: '태그 또는 메시지 검색...',
                      hintStyle: const TextStyle(color: Colors.white38, fontSize: 13),
                      prefixIcon: const Icon(Icons.search, color: Colors.white38, size: 18),
                      filled: true,
                      fillColor: Colors.white10,
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(8),
                        borderSide: BorderSide.none,
                      ),
                      contentPadding: const EdgeInsets.symmetric(vertical: 8),
                    ),
                    onChanged: (v) => setState(() => _filter = v),
                  ),
                ),
                if (_levelFilter != null) ...[
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      color: Colors.orange.withOpacity(0.2),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(
                      _levelFilter!.name.toUpperCase(),
                      style: const TextStyle(color: Colors.orange, fontSize: 11),
                    ),
                  ),
                ],
              ],
            ),
          ),

          // 로그 목록
          Expanded(
            child: entries.isEmpty
                ? const Center(
                    child: Text(
                      '로그 없음\n앱을 사용하면 여기에 기록됩니다',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: Colors.white24, fontSize: 13),
                    ),
                  )
                : ListView.builder(
                    controller: _scrollCtrl,
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    itemCount: entries.length,
                    itemBuilder: (_, i) {
                      final e = entries[i];
                      final color = _levelColors[e.level] ?? Colors.white70;
                      final isError = e.level == LogLevel.error;
                      return Container(
                        margin: const EdgeInsets.only(bottom: 1),
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: isError
                            ? BoxDecoration(
                                color: Colors.red.withOpacity(0.1),
                                borderRadius: BorderRadius.circular(3),
                              )
                            : null,
                        child: Text(
                          e.toString(),
                          style: TextStyle(
                            fontFamily: 'monospace',
                            fontSize: 11,
                            color: color,
                            height: 1.5,
                          ),
                        ),
                      );
                    },
                  ),
          ),

          // 하단 상태바
          Container(
            color: const Color(0xFF16213E),
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            child: Row(
              children: [
                Text(
                  '총 ${AppLogger.entries.length}개',
                  style: const TextStyle(color: Colors.white38, fontSize: 11),
                ),
                const SizedBox(width: 12),
                _levelCount(LogLevel.error, Colors.red),
                const SizedBox(width: 8),
                _levelCount(LogLevel.warn, Colors.orange),
                const Spacer(),
                Text(
                  '1초 자동갱신',
                  style: TextStyle(
                    color: _autoScroll ? Colors.greenAccent.withOpacity(0.6) : Colors.white24,
                    fontSize: 10,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _levelCount(LogLevel level, Color color) {
    final count = AppLogger.entries.where((e) => e.level == level).length;
    if (count == 0) return const SizedBox.shrink();
    return Text(
      '${level.name.toUpperCase()}: $count',
      style: TextStyle(color: color, fontSize: 11),
    );
  }
}
