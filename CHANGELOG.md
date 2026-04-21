# Caify 개발 진행 로그

## [v1.0.0] - 2026-04-21

### 신규 기능
- **채팅 화면** (ChatScreen): 시스템 발신 말풍선, 15초 폴링, 포스팅 확인/발행 액션 버튼
- **포스팅 뷰어** (PostViewerScreen): 포스팅 HTML을 네이버 블로그 스타일로 렌더링
- **하단 탭 내비게이션** (HomeScreen): 채팅 / 포스팅 / 설정
- **로그인 화면** (LoginScreen): 아이디/비밀번호 로그인, caify.ai 고정 API
- **인앱 APK 자동 업데이트** (UpdateService): 버전 체크 → 인앱 다운로드 → 설치
- **GitHub Actions CI/CD**: 코드 푸시 시 APK 자동 빌드 → GitHub Releases 업로드

### 네이버 블로그 발행 자동화
- 제목 / 본문 HTML / 태그 자동 주입 (SE3 에디터)
- 이미지 자동 라이브러리 업로드 (ClipboardEvent paste)
- 임시저장까지 자동 처리 → 발행은 고객이 직접 (네이버 정책 준수)
- Electron 트레이 앱 동일 로직 적용

### 메시지 타입 (시스템 → 고객)
| 타입 | 설명 |
|------|------|
| post.created | 포스팅 생성 알림 → 확인/발행 버튼 |
| post.modified | 수정 완료 알림 |
| post.published | 발행 완료 알림 |
| post.failed | 발행 실패 알림 → 재시도 버튼 |
| rank.check | 키워드 순위 확인 결과 |
| rank.winner | 순위 1~3위 달성 알림 |
| strategy.weekly | 주간 콘텐츠 전략 요약 |

### mock-server 엔드포인트
```
POST /member/login
GET  /api/posts?status=ready&member_pk=X
POST /api/posts
POST /api/posts/:id/published
POST /api/posts/:id/failed
GET  /api/post_meta?id=X
GET  /api/posts/:id
PATCH /api/posts/:id
POST /api/posts/:id/reject
GET  /api/messages?member_pk=X&after_id=Y
POST /api/messages
POST /api/messages/:id/action
POST /api/messages/:id/read
GET  /api/version
GET  /admin/posts
POST /admin/posts/:id/approve
```

### 예정 (Phase 2)
- FCM 푸시 알림 (앱 백그라운드/종료 시에도 알림)
- 실 SERP 순위 체크 API 연동
- 네이버 셀렉터 원격 관리 엔드포인트
- n8n → 채팅 메시지 자동 push

---

## CI/CD 빌드 이력

| 날짜 | 커밋 | 결과 | 비고 |
|------|------|------|------|
| 2026-04-21 | e1906b0 | 실패 | Release 권한 누락 |
| 2026-04-21 | ca0847b | 진행 중 | permissions: contents: write 추가 |
