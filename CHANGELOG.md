# Caify 개발 변경 이력

---

## [v1.2.3] - 2026-04-21 (현재)

### 버그 수정
- **앱 아이콘 투명 → 녹색 펜 아이콘**: mipmap-anydpi-v26 어댑티브 아이콘 추가, PNG도 교체
- **SE3 주입 후 editorReady 항상 보장**: 제목/본문/태그 주입 실패해도 에디터 WebView 표시
- **jsIsEditorReady 3단계 폴백**: SE3 클래스 → 모바일 input → CE>=2 순서로 탐지

### 개선
- `jsInjectTitle`: execCommand → innerText → textContent 3단계 폴백
- `jsAddTags`: React nativeInputValueSetter 적용
- `_doInjectAndTempSave`: 주입 실패 시 failed 아닌 editorReady 도달 (직접 수정 가능)
- APK 다운로드 랜딩 페이지: `https://caifyhelp-cmyk.github.io/caify-product/`
- `caify.apk` 고정 파일명으로 Release 업로드 (최신 버전 고정 URL)

---

## [v1.2.2] - 2026-04-21

### 버그 수정
- SE3 주입 로직을 마지막 동작 버전(c5d0d8d)으로 복원
- `_fw` 변수 주입 방식 → `_docFinder` IIFE 반환 방식으로 복원
- `jsIsEditorReady`: div contenteditable 개수 체크 → SE3 구조 확인으로 복원

---

## [v1.2.1] - 2026-04-21

### 디버그
- `jsDiagnose()` 결과를 화면에 표시 (로딩 중 상태메시지)
- `jsIsEditorReady`: DIV contenteditable 기반으로 단순화
- 에디터 감지 타이밍 수정 (3초 대기 후 진입)

---

## [v1.2.0] - 2026-04-21

### 버그 수정
- 제목 주입 체크 조건 수정 (`!= 'ok'` → `startsWith('no_')`)
- `_waitForEditor` 체크 조건 수정 (`== 'ready'` → `startsWith('ready')`)
- 에디터 감지 타이밍 수정 (naver.com 페이지에서 3초 대기)

---

## [v1.1.9] - 2026-04-21

### 버그 수정
- 블랭크 스크린 수정: `fetchPosts` 10초 타임아웃 + catch → 빈 목록 반환
- 에디터 감지 강화

---

## [v1.1.8] - 2026-04-21 ⚠️ SE3 주입 깨진 버전

### 변경 (주입 코드 전면 재작성 → 결과적으로 broken)
- `_fw` 변수 주입 방식으로 전환 (나중에 복원됨)
- 테스트 데이터에 이미지 추가 (picsum.photos)
- mock-server GET /api/posts에 tags 필드 추가

---

## [v1.1.7] - 2026-04-21

### 버그 수정
- 데스크톱 UA 복원 (모바일 UA → "일시적 오류" 발생 이슈)
- 로드 URL: `blog.naver.com/GoBlogWrite.naver` (데스크톱)
- SE3 주입 정리

---

## [v1.1.6] - 2026-04-21

### 개선
- 업데이트 체크를 GitHub Releases API로 변경
  - 기존: Render 서버 `/api/version` (cold start로 타임아웃)
  - 변경: `api.github.com/repos/caifyhelp-cmyk/caify-product/releases/latest`

---

## [v1.1.5] - 2026-04-21

### 변경 (이후 롤백됨)
- 모바일 UA 적용 시도 → 네이버 "일시적 오류" 발생으로 v1.1.7에서 데스크톱으로 복원

---

## [v1.1.4 이하] - 2026-04-21

- 무한루프 제거, 데스크톱 UA 통일
- 에러 페이지 감지 → 쿠키 삭제 후 재시도
- 모바일 SE3 input 엘리먼트 지원
- 설치하기 버튼 출처불명앱 권한 런타임 체크
- 발행 UX 고도화: 배너 X/다시안보기, 네이버 쿠키 자동저장/복원
- AppBar 발행하기 버튼, 줌 허용, 사이드 패널 닫기

---

## [v1.0.0] - 2026-04-21

### 신규 기능
- 채팅 화면 (ChatScreen): 시스템 말풍선, 폴링, 포스팅 확인/발행 버튼
- 포스팅 뷰어 (PostViewerScreen)
- 하단 탭 내비게이션 (채팅/포스팅/설정)
- 로그인 화면 (LoginScreen)
- 인앱 APK 자동 업데이트 (UpdateService)
- GitHub Actions CI/CD: APK 빌드 → GitHub Releases

### 네이버 블로그 발행 자동화
- 제목/본문 HTML/태그 자동 주입 (SE3 에디터)
- 이미지 자동 라이브러리 업로드 (ClipboardEvent paste)
- 임시저장 자동 처리 → 발행은 고객 직접 (네이버 정책 준수)
