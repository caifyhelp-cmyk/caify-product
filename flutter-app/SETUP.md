# Flutter 앱 빌드 가이드

## 1. Flutter 설치
https://docs.flutter.dev/get-started/install/windows 에서 설치

## 2. 프로젝트 초기화
```bash
# caify_flutter 상위 폴더에서 실행
flutter create caify_flutter_base
# 생성된 android/, ios/ 폴더를 caify_flutter/로 복사
# 또는: caify_flutter 폴더 안에서
flutter create .
```

## 3. 패키지 설치
```bash
cd caify_flutter
flutter pub get
```

## 4. AndroidManifest.xml 수정
`android/app/src/main/AndroidManifest.xml`에서:
- `<uses-permission android:name="android.permission.INTERNET"/>` 추가
- `<uses-permission android:name="android.permission.POST_NOTIFICATIONS"/>` 추가
- `<application>` 태그에 `android:usesCleartextTraffic="true"` 추가
- FileProvider 추가 (AndroidManifest.xml 파일 참고)

## 5. 실행 / 빌드
```bash
# 에뮬레이터 또는 실기기 연결 후
flutter run

# APK 빌드
flutter build apk --release

# App Bundle (Play Store용)
flutter build appbundle --release
```

## 파일 구조
```
lib/
├── main.dart                    ← 앱 진입점, 라우팅
├── models/post.dart             ← Post 데이터 모델
├── services/
│   ├── api_service.dart         ← caify.ai API 연동
│   └── naver_publisher.dart     ← Naver 에디터 JS 주입 스니펫
└── screens/
    ├── post_list_screen.dart    ← 포스팅 목록 (발행대기/완료 탭)
    ├── publish_screen.dart      ← WebView 발행 화면
    └── settings_screen.dart     ← API 설정
```

## 발행 흐름
1. 포스팅 목록에서 "네이버 블로그에 발행" 버튼 클릭
2. Naver 에디터 WebView 열기
3. 로그인 필요시 → WebView 화면에 로그인창 표시 (사용자가 직접 로그인)
4. 로그인 완료 → 에디터 로드 대기 (최대 30초)
5. iframe[name='mainFrame'] 안에 제목+HTML 주입
6. 저장 버튼 클릭
7. 발행 완료 → API 서버에 상태 업데이트
