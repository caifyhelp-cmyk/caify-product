import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

enum LogLevel { info, warn, error }

class AppLogger {
  AppLogger._();

  static final _logs = <LogEntry>[];
  static const _maxLogs = 1000;

  static void log(String tag, String message, {LogLevel level = LogLevel.info}) {
    final entry = LogEntry(
      time:  DateTime.now(),
      tag:   tag,
      msg:   message,
      level: level,
    );
    _logs.add(entry);
    if (_logs.length > _maxLogs) _logs.removeAt(0);
    debugPrint(entry.toString()); // Flutter 콘솔에도 출력
  }

  static void info(String tag, String msg)  => log(tag, msg, level: LogLevel.info);
  static void warn(String tag, String msg)  => log(tag, msg, level: LogLevel.warn);
  static void error(String tag, String msg) => log(tag, msg, level: LogLevel.error);

  static List<LogEntry> get entries => List.unmodifiable(_logs);

  static String get formatted {
    if (_logs.isEmpty) return '(로그 없음)';
    return _logs.reversed.map((e) => e.toString()).join('\n');
  }

  static void clear() => _logs.clear();

  static Future<void> copyToClipboard() async {
    await Clipboard.setData(ClipboardData(text: formatted));
  }
}

class LogEntry {
  final DateTime time;
  final String tag;
  final String msg;
  final LogLevel level;

  const LogEntry({
    required this.time,
    required this.tag,
    required this.msg,
    this.level = LogLevel.info,
  });

  String format() {
    final t = '${time.hour.toString().padLeft(2,'0')}:'
              '${time.minute.toString().padLeft(2,'0')}:'
              '${time.second.toString().padLeft(2,'0')}';
    final lvl = switch (level) {
      LogLevel.warn  => 'W',
      LogLevel.error => 'E',
      _              => 'I',
    };
    return '[$t][$lvl][$tag] $msg';
  }

  @override
  String toString() => format();
}
