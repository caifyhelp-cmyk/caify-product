# Caify 개발 로그 (Claude와 작업 기록)

## 프로젝트 구조

```
caify-product/
├── flutter-app/          # Android APK (메인 제품)
│   ├── lib/
│   │   ├── main.dart
│   │   ├── models/post.dart
│   │   ├── screens/
│   │   │   ├── home_screen.dart
│   │   │   ├── login_screen.dart
│   │   │   ├── publish_screen.dart   ← 핵심: 네이버 에디터 WebView
│   │   │   ├── post_list_screen.dart
│   │   │   └── settings_screen.dart
│   │   └── services/
│   │       ├── api_service.dart      ← Caify 서버 API
│   │       ├── naver_publisher.dart  ← SE3 JS 주입 코드 (핵심)
│   │       └── update_service.dart   ← GitHub Releases 자동 업데이트
│   └── android/
│       └── app/src/main/
│           ├── kotlin/.../MainActivity.kt  ← APK 설치 MethodChannel
│           └── res/
│               ├── mipmap-*/ic_launcher.png
│               ├── mipmap-anydpi-v26/ic_launcher.xml  ← 어댑티브 아이콘
│               └── drawable/ic_launcher_*.xml
├── mock-server/
│   └── server.js         ← 로컬 테스트용 Express 서버 (포트 3030)
├── electron-tray/        ← Windows 트레이 앱 (별도)
├── provision/            ← n8n 프로비저닝
├── docs/index.html       ← APK 다운로드 랜딩 페이지 (GitHub Pages)
├── .github/workflows/build-apk.yml  ← CI/CD
├── CHANGELOG.md
└── DEVELOPMENT_LOG.md    ← 이 파일
```

---

## 핵심 설정값

### API 서버
- **기본값**: `https://caify-mock-server.onrender.com` (Render 무료 서버, cold start 있음)
- **로컬 테스트**: `http://10.0.2.2:3030` (Android 에뮬레이터에서 localhost)
- 실서버 전환 시: LoginScreen에서 서버 주소 변경 (로고 5번 탭 → 숨겨진 설정)

### 네이버 에디터 설정
- **UA**: 데스크톱 Chrome 120 (모바일 UA → 네이버 "일시적 오류" 발생)
- **초기 URL**: `https://blog.naver.com/GoBlogWrite.naver`
- **모바일 UA 쓰면 안 되는 이유**: 네이버가 모바일 에디터로 리다이렉트하거나 오류 반환

### GitHub
- **Repo**: `https://github.com/caifyhelp-cmyk/caify-product`
- **APK 다운로드 (항상 최신)**: `https://github.com/caifyhelp-cmyk/caify-product/releases/latest/download/caify.apk`
- **랜딩 페이지**: `https://caifyhelp-cmyk.github.io/caify-product/` (GitHub Pages, docs/ 폴더)

---

## SE3 주입 동작 원리 (naver_publisher.dart)

### 흐름
1. `jsIsEditorReady()` 로 에디터 로드 확인 (1초 간격, 최대 60초)
2. 준비되면 `_doInjectAndTempSave()` 실행:
   - `jsInjectTitle()` → 제목 입력
   - `jsInjectHtml()` → 본문 HTML paste
   - `jsInjectHtmlFallback()` → 2초 후 내용 없으면 execCommand/innerHTML
   - `jsAddTags()` → 태그 입력
   - `jsClickTempSave()` → 임시저장
3. 항상 `editorReady` 상태로 전환 (주입 성공/실패 무관)
4. 사용자가 에디터 확인 후 "발행하기" 버튼 클릭

### 중요: 주입 실패해도 에디터는 보임
`_doInjectAndTempSave()`는 실패해도 `failed`가 아닌 `editorReady`로 전환.
→ 사용자가 에디터에서 직접 내용 확인/수정 가능.

### jsIsEditorReady 탐지 순서
1. SE3 `.se-title-text` + `.se-component.se-text` 둘 다 있으면 → `ready_se3`
2. 모바일 `input[placeholder*="제목"]` 있으면 → `ready_mobile`
3. `DIV[contenteditable]` 2개 이상 → `ready_ce:N`

### _docFinder
SE3 에디터 document를 찾는 공통 IIFE.
메인 doc → mainFrame iframe → 첫 iframe 순으로 탐색.

---

## 자동 업데이트 (update_service.dart)

- 앱 시작 시 GitHub Releases API 호출
- `tag_name` (v1.2.3) vs 현재 앱 버전 비교
- 새 버전 있으면 다이얼로그 → APK 다운로드 → 설치
- Render cold start 문제로 GitHub API로 변경 (즉시 응답)

---

## mock-server (로컬 테스트)

```bash
cd mock-server
node server.js  # 포트 3030
```

### 테스트 계정
- ID: `test` / PW: `test123`

### 테스트 포스팅 (id=1)
- 제목: 임플란트 비용 가이드
- 태그: 임플란트, 치과, 비용
- 이미지: picsum.photos 포함

---

## 알려진 이슈 및 히스토리

### SE3 주입 관련
- **v1.1.8에서 주입 코드 전면 재작성 → broken**
  - `_fw` 변수 주입 방식으로 바꿨다가 문제 발생
  - `jsIsEditorReady()`를 raw string으로 만들어 `$_fw` 미삽입 → 에디터 미완성 상태에서 주입
  - v1.2.2, v1.2.3에서 복원 및 수정

- **execCommand deprecated 이슈**
  - Android WebView에서 `execCommand('insertText')` 여전히 동작함
  - 안 될 경우 `innerText` setter → `textContent` 순으로 폴백

- **ClipboardEvent paste**
  - 본문 HTML 주입에 가장 적합 (이미지 포함 HTML 처리)
  - 안 될 경우 `execCommand('insertHTML')` → `innerHTML` 직접 할당

### 앱 아이콘
- 초기 PNG 파일이 투명(1031바이트)으로 아이콘 안 보임
- v1.2.3에서 `mipmap-anydpi-v26` 어댑티브 아이콘 + 녹색 PNG 교체

### Render cold start
- 무료 Render 서버 첫 요청 ~30초 걸림
- 업데이트 체크: GitHub Releases API로 대체 (해결)
- fetchPosts: 10초 타임아웃 + 빈 목록 반환 (해결)

---

## 실서버 전환 체크리스트

현재 `mock-server/server.js`를 실제 서버로 교체할 때:

- [ ] `/member/login` 엔드포인트
- [ ] `GET /api/posts?member_pk=X&status=ready`
- [ ] `POST /api/posts/:id/published`
- [ ] `POST /api/posts/:id/failed`
- [ ] 응답 형식: `{ ok: true, posts: [...], tags: [...] }`
- [ ] `tags` 필드 반드시 포함 (없으면 태그 입력 안 됨)

---

## CI/CD (.github/workflows/build-apk.yml)

트리거: `flutter-app/**` 또는 `.github/workflows/build-apk.yml` 변경 시 자동 빌드

빌드 결과:
- GitHub Release: `caify_1.2.3.apk` (버전명) + `caify.apk` (고정명)
- 소요 시간: 약 5분

Secrets 필요:
- `KEYSTORE_BASE64`: release keystore base64 인코딩
- `KEYSTORE_PASSWORD`
- `KEY_ALIAS`
- `KEY_PASSWORD`

---

## 다른 단말에서 작업 시작하기

```bash
# 1. 클론
git clone https://github.com/caifyhelp-cmyk/caify-product.git
cd caify-product

# 2. Flutter 의존성
cd flutter-app
flutter pub get

# 3. 로컬 서버 (선택)
cd ../mock-server
npm install
node server.js

# 4. 앱 빌드 (디버그)
cd ../flutter-app
flutter run

# 5. APK 릴리즈 빌드는 GitHub Actions에 맡기기
git push  # → 자동 빌드
```
