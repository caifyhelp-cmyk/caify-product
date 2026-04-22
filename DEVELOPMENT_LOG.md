# Caify 개발 로그 (Claude와 작업 기록)

## 프로젝트 구조

```
caify-product/                         ← GitHub: caifyhelp-cmyk/caify-product
├── flutter-app/                       # Android APK (메인 제품)
│   ├── lib/
│   │   ├── screens/
│   │   │   ├── publish_screen.dart    ← 핵심: 네이버 에디터 WebView + 주입 흐름
│   │   │   ├── post_list_screen.dart
│   │   │   ├── chat_screen.dart
│   │   │   └── settings_screen.dart  ← 로그 뷰어 포함
│   │   └── services/
│   │       ├── naver_publisher.dart   ← SE3 JS 주입 코드 (핵심)
│   │       ├── api_service.dart       ← Caify 서버 API
│   │       ├── app_logger.dart        ← 인앱 로그 저장
│   │       └── update_service.dart    ← GitHub Releases 자동 업데이트
│   └── android/
│       └── app/src/main/kotlin/.../MainActivity.kt  ← APK 설치 MethodChannel
├── mock-server/server.js              # 로컬 테스트용 Express (포트 3030)
├── electron-tray/                     # Windows 트레이 앱
├── provision/                         # n8n 프로비저닝 스크립트
├── docs/index.html                    # APK 다운로드 랜딩 페이지 (GitHub Pages)
└── .github/workflows/build-apk.yml   # CI/CD → 자동 APK 빌드
```

**로컬 PHP 소스 위치**: `C:\Users\조경일\caify_php_src\html\` (caify.ai 실서버 소스)

---

## APK 다운로드 URL 및 GitHub Pages

- **랜딩 페이지**: `https://caifyhelp-cmyk.github.io/caify-product/`
  - 파일: `docs/index.html` (버튼 클릭 → APK 즉시 다운로드)
  - ⚠️ GitHub Pages 활성화 필요: repo Settings → Pages → Source: `master` / `docs/` 폴더
- **APK 직접 링크 (항상 최신)**: `https://github.com/caifyhelp-cmyk/caify-product/releases/latest/download/caify.apk`

---

## 핵심 설정값

### API 서버
| 환경 | 주소 |
|------|------|
| 현재 (테스트) | `https://caify-mock-server.onrender.com` |
| 로컬 에뮬레이터 | `http://10.0.2.2:3030` |
| **실서버 (caify.ai)** | `https://caify.ai` (전환 예정) |

- 서버 주소 변경: 앱 로고 5번 탭 → 숨겨진 설정창

### 네이버 에디터 설정
- **UA**: 데스크톱 Chrome 120 (모바일 UA → 네이버 "일시적 오류" 발생)
- **초기 URL**: `https://blog.naver.com/GoBlogWrite.naver`

---

## SE3 주입 동작 원리 (naver_publisher.dart)

### 전체 흐름
1. `jsIsEditorReady()` — 1초 간격, 최대 60초 폴링
2. 준비되면 `_doInjectAndTempSave()`:
   - `jsDebugSE3()` → SE3 내부 구조 진단 (로그용)
   - `jsInjectTitle()` → 제목 입력 (SE3 API → iframe script → DOM 순)
   - `jsInjectHtml()` → 본문 HTML paste
   - `jsInjectHtmlFallback()` → 2초 후 내용 없으면 execCommand/innerHTML
   - 이미지 URL 파싱 → Flutter `http.get` 다운로드 → base64 → `jsInjectImageBlob()` paste (SE3 라이브러리 업로드)
   - `jsAddTags()` → 태그 입력 (SE3 _tagService API → iframe+outer DOM 순)
   - `jsClickTempSave()` → 임시저장
3. 항상 `editorReady`로 전환 (주입 실패해도 에디터 보임 → 사용자가 직접 수정 가능)
4. 사용자가 "발행하기" 버튼 클릭

### jsIsEditorReady 탐지 순서
1. SE3 `.se-title-text` + `.se-component.se-text` 둘 다 → `ready_se3`
2. 모바일 `input[placeholder*="제목"]` → `ready_mobile`
3. `DIV[contenteditable]` 2개 이상 → `ready_ce:N`

### 로그 진단 키
앱 설정 → 로그 보기 → 전체 복사:
- `[ready[N]]` — `ready_se3` / `ready_ce:N` / `not_ready:...`
- `[se3_diag]` — SmartEditor 내부 API 구조
- `[inject_title]` — `ok_ds:setTitle` / `ok_direct` / `no_title_p`
- `[inject_body]` — `paste_dispatched` / `no_body_el`
- `[inject_img]` — `ok` / `err:...` (이미지별)
- `[inject_tags]` — `ok_tagService:addTag` / `ok:3` / `no_tag_input`
- `[temp_save]` — `ok` / `no_save_btn|...`

---

## 실서버 전환 계획 (caify.ai → Bearer 토큰 API 추가)

### 현재 실서버 구조 (caify.ai PHP)
실서버는 **PHP 세션 기반** 인증이고, Flutter 앱은 **Bearer 토큰** 방식.
→ 실서버에 앱 전용 JSON API 엔드포인트를 새로 추가해야 함.

**현재 실서버에 있는 것:**
| 파일 | 역할 |
|------|------|
| `api/index.php` | n8n이 AI 포스트 저장 (POST JSON) |
| `api/post_meta/index.php` | 고객 프롬프트 메타데이터 조회 |
| `output/output_publish_guard.php` | `mark_posting` (세션 기반, posting_date 갱신) |
| `output/output_list.php` | 포스트 목록 (HTML 웹페이지) |
| `member/login.php` | 웹 로그인 폼 (세션, JSON API 아님) |

**새로 추가해야 할 API (php 파일):**

#### 1. `api/app_login.php` — 앱 로그인 (Bearer 토큰 발급)
```
POST https://caify.ai/api/app_login.php
Body: { "member_id": "...", "passwd": "..." }
Response: { "ok": true, "member_pk": 1, "api_token": "...", "company_name": "..." }
```
- `caify_member` 테이블에서 `password_verify()` 검증
- `api_token` 컬럼이 있으면 반환, 없으면 `random_bytes(32)` 생성 후 저장

#### 2. `api/app_posts.php` — 발행 대기 목록
```
GET https://caify.ai/api/app_posts.php?status=ready&member_pk=X
Header: Authorization: Bearer <api_token>
Response: [{ "id": 1, "title": "...", "html": "...", "tags": [...], "posting_date": null }]
```
- `ai_posts` 테이블: `status=1 AND posting_date IS NULL AND customer_id=member_pk`
- `tags` 컬럼 없으면 별도 조회 또는 빈 배열 반환

#### 3. `api/app_publish.php` — 발행 완료/실패 기록
```
POST https://caify.ai/api/app_publish.php
Header: Authorization: Bearer <api_token>
Body: { "id": 1, "action": "published" }  또는  { "id": 1, "action": "failed", "reason": "..." }
Response: { "ok": true }
```
- `published`: `UPDATE ai_posts SET posting_date = NOW() WHERE id=? AND customer_id=?`
- `failed`: posting_date null 유지 (재시도 가능)

### Bearer 토큰 인증 헬퍼 (3개 파일 공통)
```php
function auth_by_token(PDO $pdo): array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = preg_replace('/^Bearer\s+/i', '', $auth);
    if (!$token) { http_response_code(401); exit; }
    $stmt = $pdo->prepare('SELECT * FROM caify_member WHERE api_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $member = $stmt->fetch();
    if (!$member) { http_response_code(401); exit; }
    return $member;
}
```

### 전환 순서
1. `caify_member` 테이블에 `api_token VARCHAR(64)` 컬럼 추가
2. 위 3개 PHP 파일 작성 및 배포
3. Flutter `api_service.dart`에서 서버 주소 `caify.ai`로 변경
4. mock-server 완전 제거 or 개발용으로만 유지

---

## 자동 업데이트 (update_service.dart)

- 앱 시작 시 GitHub Releases API 호출
- `tag_name` (v1.2.3) vs 현재 앱 버전 비교
- 새 버전 있으면 다이얼로그 → APK 다운로드 → 설치

---

## mock-server (로컬 테스트)

```bash
cd mock-server && node server.js  # 포트 3030
```

테스트 계정: `testuser` / `password123`, `admin` / `adminpass`  
Render 배포 URL: `https://caify-mock-server.onrender.com`

---

## 알려진 이슈 및 히스토리

### SE3 주입
- v1.1.8 전면 재작성 후 broken → v1.2.2~v1.2.3 복원
- 제목/본문 분리 버그 (v1.2.9에서 수정)
- 태그 `no_tag_input`: SE3 iframe 안에서만 탐색해서 outer doc 못 찾음 → 양쪽 탐색으로 수정
- 이미지 외부 URL paste → 라이브러리 미등록 → blob paste로 변경

### 기타
- 앱 아이콘 투명(1031바이트) → v1.2.3에서 어댑티브 아이콘 추가
- Render cold start (~30초): 업데이트 체크는 GitHub API로 대체

---

## CI/CD (.github/workflows/build-apk.yml)

트리거: `flutter-app/**` 변경 시 자동 빌드 (~5분)  
결과: GitHub Release에 `caify_버전.apk` + `caify.apk` (고정명)

Secrets: `KEYSTORE_BASE64`, `KEYSTORE_PASSWORD`, `KEY_ALIAS`, `KEY_PASSWORD`

---

## 다른 단말에서 작업 시작하기

```bash
git clone https://github.com/caifyhelp-cmyk/caify-product.git
cd caify-product/flutter-app && flutter pub get
cd ../mock-server && npm install && node server.js   # 로컬 서버 (선택)
cd ../flutter-app && flutter run                     # 디버그 빌드
git push                                             # → Actions 자동 빌드
```
