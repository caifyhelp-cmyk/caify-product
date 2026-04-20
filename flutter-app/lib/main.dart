import 'package:flutter/material.dart';
import 'screens/post_list_screen.dart';
import 'screens/settings_screen.dart';
import 'services/api_service.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const CaifyApp());
}

class CaifyApp extends StatelessWidget {
  const CaifyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Caify',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF03C75A)),
        fontFamily: 'Roboto',
        useMaterial3: true,
      ),
      home: const _SplashRouter(),
    );
  }
}

/// 설정이 있으면 PostList, 없으면 Settings로 라우팅
class _SplashRouter extends StatefulWidget {
  const _SplashRouter();

  @override
  State<_SplashRouter> createState() => _SplashRouterState();
}

class _SplashRouterState extends State<_SplashRouter> {
  @override
  void initState() {
    super.initState();
    _route();
  }

  Future<void> _route() async {
    final cfg = await ApiService.loadConfig();
    if (!mounted) return;
    if (cfg['apiBase']?.isNotEmpty == true && cfg['memberId']?.isNotEmpty == true) {
      Navigator.pushReplacement(
          context, MaterialPageRoute(builder: (_) => const PostListScreen()));
    } else {
      Navigator.pushReplacement(
          context, MaterialPageRoute(builder: (_) => const SettingsScreen()));
    }
  }

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      backgroundColor: Color(0xFF03C75A),
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text('Caify',
                style: TextStyle(
                    color: Colors.white,
                    fontSize: 36,
                    fontWeight: FontWeight.bold)),
            SizedBox(height: 16),
            CircularProgressIndicator(color: Colors.white),
          ],
        ),
      ),
    );
  }
}
