import 'package:flutter/services.dart';

class AppLogger {
  AppLogger._();

  static final _logs = <_LogEntry>[];
  static const _maxLogs = 500;

  static void log(String tag, String message) {
    final entry = _LogEntry(
      time: DateTime.now(),
      tag:  tag,
      msg:  message,
    );
    _logs.add(entry);
    if (_logs.length > _maxLogs) _logs.removeAt(0);
  }

  static List<_LogEntry> get entries => List.unmodifiable(_logs);

  static String get formatted {
    if (_logs.isEmpty) return '(로그 없음)';
    return _logs.reversed.map((e) => e.toString()).join('\n');
  }

  static void clear() => _logs.clear();

  static Future<void> copyToClipboard() async {
    await Clipboard.setData(ClipboardData(text: formatted));
  }
}

class _LogEntry {
  final DateTime time;
  final String tag;
  final String msg;
  const _LogEntry({required this.time, required this.tag, required this.msg});

  String format() {
    final t = '${time.hour.toString().padLeft(2,'0')}:'
              '${time.minute.toString().padLeft(2,'0')}:'
              '${time.second.toString().padLeft(2,'0')}';
    return '[$t][$tag] $msg';
  }

  @override
  String toString() => format();
}
