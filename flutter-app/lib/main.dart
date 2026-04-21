import 'package:flutter/material.dart';
import 'screens/login_screen.dart';
import 'screens/home_screen.dart';
import 'services/api_service.dart';
import 'services/update_service.dart';

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
    // 첫 프레임 빌드 후 실행 — initState에서 showDialog 호출 시 context 미준비 문제 방지
    WidgetsBinding.instance.addPostFrameCallback((_) => _route());
  }

  Future<void> _route() async {
    // 업데이트 체크는 화면 전환 후 백그라운드에서 — 스플래시 블로킹 방지
    final loggedIn = await ApiService.isLoggedIn();
    if (!mounted) return;
    if (loggedIn) {
      Navigator.pushReplacement(
          context, MaterialPageRoute(builder: (_) => const HomeScreen()));
    } else {
      Navigator.pushReplacement(
          context, MaterialPageRoute(builder: (_) => const LoginScreen()));
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
