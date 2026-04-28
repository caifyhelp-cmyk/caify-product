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
│       ├── app/src/main/kotlin/.../MainActivity.kt  ← APK 설치 + 클립보드 MethodChannel
│       └── app/src/main/res/xml/provider_paths.xml  ← FileProvider 경로 설정
├── mock-server/server.js              # 로컬 테스트용 Express (포트 3030)
├── electron-tray/                     # Windows 트레이 앱
├── provision/                         # n8n 프로비저닝 스크립트
├── docs/index.html                    # APK 다운로드 랜딩 페이지 (GitHub Pages)
└── .github/workflows/build-apk.yml   # CI/CD → 자동 APK 빌드 (~5분)
```

**로컬 PHP 소스 위치**: `C:\Users\조경일\caify_php_src\html\` (caify.ai 실서버 소스)

---

## n8n 워크플로우 — 키워드풀 반영 (키워드풀_반영.json)

**워크플로우 ID**: `daUM2xPEVyBhbyez`  
**파일**: `키워드풀_반영.json` (90노드, executeWorkflowTrigger, Queue Worker 서브워크플로우)

### 노드 체인
```
비즈니스 리서치 준비 → Perplexity 비즈니스 리서치 → 리서치 결과 파싱
→ LLM 요청 준비 → LLM 키워드 생성 → LLM 응답 파싱 → 풀 저장 (MySQL)
```

### v3 업데이트 내용 (2026-04-27)
**핵심 변경: 키워드 생성 로직 전면 고도화**

1. **Perplexity 사전 조사 강화** (`비즈니스 리서치 준비`)
   - 타겟 고객 명시 질문 추가: 소비자/구직자/수험생/사업자 중 실제 타겟 명시 요청
   - `goal` 필드도 Perplexity에 전달

2. **리서치 결과 없음 처리** (`리서치 결과 파싱`)
   - `_research_available` 플래그 추가 → LLM에 fallback 가이드 제공

3. **LLM 자율 타겟 추론** (`LLM 요청 준비`)
   - 기존 하드코딩(isInsurance, isAcademy) 제거 → Claude가 자율 추론
   - 1단계: 실제 비즈니스 목적·타겟 추론 (보험 설계사 모집 vs 상품 소개 등)
   - 2단계: 서비스·강점 추출
   - 3단계: 키워드 파생 (타겟별 방향 반영)
   - 4단계: 4:3:3 분류 (tier1:6 / tier2:5 / tier3:4 per slot)

4. **동(洞) 단위 오프라인 키워드** (`LLM 요청 준비`)
   - NAVER Geocoding jibunAddress에서 동 추출 우선, fallback 정규식
   - promo tier1: `{동}+서비스명` 최소 2개 필수
   - 도로명(로/길) 기반 키워드 사용 금지

5. **업종 앵커 규칙**
   - 키워드 안에 업종명·서비스명·지역명 중 최소 1개 포함 필수
   - 예: "불합격 무료 재수강" ❌ → "공인중개사 학원 불합격 재수강 무료" ✅

6. **롱테일(tier3) 실검 기준**
   - 네이버 자동완성 스타일 3~5단어 명사형만 허용
   - 구어체/문장형/조사로 끝나는 표현 금지
   - 예: "공인중개사 독학이랑 학원이랑 합격률 차이 얼마나 나요" ❌
   - 예: "공인중개사 독학 합격률" ✅

7. **LLM 응답 파싱** — primary_target, target_reasoning DB 저장 추가

### 테스트 검증 결과
- 일반업종 5개 (헬스장, ISO인증, 유아동복, 뷰티학원, 보험GA): 모두 정상
- 특수업종 2개 (치킨 프랜차이즈→창업희망자80%, 공인중개사 학원→수험생100%): 자율 추론 정상

### 관련 파일
- `n8n-patches/patch_keyword_pool_v3.py` — 워크플로우 JSON 패치 스크립트
- `n8n-patches/test_keyword_pool.py` — 일반 5케이스 테스트
- `n8n-patches/test_keyword_extra.py` — 특수 2케이스 테스트

### v4 업데이트 내용 (2026-04-27) — RAG 고도화
**핵심 변경: 키워드 심층 조사 + 업체 조사 분리 전달**

#### 배경
기존 RAG는 `"키워드"에 대해 전문 정보 주세요` 단순 쿼리 → 일반 정보만 가져옴.
`_biz_research`(업체 조사 결과)가 블로그 생성 프롬프트에 전혀 전달되지 않던 문제.

#### 변경 노드

1. **`Perplexity 요청 준비`**
   - 모델: `sonar` → `sonar-pro` (더 깊은 검색)
   - 쿼리 구조화: 6개 항목 명시 요청
     1. 핵심 개념과 작동 원리 (사실 기반)
     2. 실제로 많이 하는 실수·오해
     3. 선택·판단 기준 (비교 포인트)
     4. 최신 트렌드·변화 (확인된 것만)
     5. 실무에서 자주 막히는 지점
     6. 관련 수치·통계 (출처 포함, 확인된 것만)

2. **`프롬프트생성1`**
   - `_biz_research` (`리서치 결과 파싱` 노드에서 참조) 주입
   - 컨텍스트 3단 구조로 전달:
     - `[키워드 심층 조사]` — Perplexity RAG 결과 (사실·원리·기준 뼈대)
     - `[업체 조사]` — `_biz_research` (서비스·강점·타겟 맥락)
     - `[고객 정보]` — 기존 contextFull (브랜드명·주소·강점·톤 등)
   - SYSTEM_PROMPT: 두 소스 역할 구분 지침 추가

#### 캐시 전략
keyword 단위 캐시 유지 (키워드 지식은 업체 무관 공유 가능)

#### 버그 수정 (2026-04-27) — 리서치 결과 파싱 누락
**`리서치 결과 파싱` 노드에 `_research_available` 플래그 미포함 버그**
- `LLM 요청 준비`가 `_research_available`을 참조하는데 해당 노드가 이를 반환하지 않아
  항상 `undefined` → `!undefined === true` → "사전 조사 결과 없음" 경고 출력
- `content.slice(0, 1500)` → `slice(0, 2000)` 도 함께 수정
- 패치 스크립트: `n8n-patches/patch_research_parse_fix.py`

### v5 업데이트 (2026-04-27) — 고객 마케팅 프로파일 + 차별화 강화

#### 배경
- 풀 있을 때 비즈니스 리서치 스킵 → `_biz_research` 블로그 프롬프트 미전달 버그
- 키워드 선택이 랜덤 → 고객 특성 미반영
- 동종업계 100개 기업이 같은 키워드로 써도 중복 판정 안 나게 차별화 필요

#### 변경 내용

1. **비즈니스 리서치 위치 변경** — 풀 체크 앞으로 이동 (항상 실행)
   - `가중치부여1` → `비즈니스 리서치 준비` → ... → `풀 메타 조회` 순서로 재배치
   - 풀 유무와 무관하게 `_biz_research` 항상 확보

2. **스마트 키워드 선택** — 랜덤 → 컨텍스트 기반 스코어링
   - `_biz_research` + `product_strengths` + `industry` 핵심 단어 빈도 분석
   - 키워드 토큰과 매칭 스코어 높은 것 우선 선택

3. **고객 마케팅 프로파일 노드** (3개 추가)
   - `마케팅 프로파일 준비` → `마케팅 프로파일 생성`(Claude haiku) → `마케팅 프로파일 파싱`
   - 출력: `sentence_style`, `structure_bias`, `key_angles`, `target_reader`, `emphasis_guide`, `expression_guide`
   - `Merge (지식)` → 프로파일 노드 → `프롬프트생성1` 으로 연결

4. **`프롬프트생성1` + `검색의도_H2생성` 프로파일 주입**
   - SYSTEM_PROMPT: 문장 스타일·구조·강조·표현 패턴 고객별 동적 주입
   - USER_PROMPT: `[이 글의 독자]` + `[이 업체만의 각도]` 섹션 추가
   - `검색의도_H2생성`: 업체 구조 방향·독자·각도 기반 H2 생성

#### 결과
같은 키워드라도 각 고객의 업체 특성에서 H2·문장·표현이 자연스럽게 분기됨

### v6 업데이트 (2026-04-28) — 전문성·수치·출처 강화 + 업체명 볼드

#### 변경 내용

1. **Perplexity RAG 쿼리 강화**
   - 수치·통계 수집 시 기관명·보고서명·연도 반드시 포함 요청
   - 항목 7 추가: 업종 전문가·실무자가 쓰는 용어와 기준

2. **프롬프트생성1 + 글생성 규칙 추가**
   - 확인된 수치는 본문에 반드시 포함 + `(출처: 기관명)` 병기
   - 전문가도 납득할 깊이 + 비전문 독자도 이해하게 풀어쓰기 동시 요구

3. **NAVER REBUILD V1 — 업체명 자동 볼드**
   - `brand_name` 등장 시 `<strong>` 자동 처리 (본문 + summary 모두)

#### 패치 스크립트
`n8n-patches/patch_depth_stats_brand.py`

### 관련 파일
- `n8n-patches/patch_keyword_pool_v3.py` — 키워드풀 v3 패치
- `n8n-patches/patch_rag_v1.py` — RAG v1 패치 스크립트
- `n8n-patches/patch_research_parse_fix.py` — 리서치 파싱 버그 수정 패치
- `n8n-patches/patch_marketing_profile.py` — 마케팅 프로파일 + 차별화 패치
- `n8n-patches/patch_depth_stats_brand.py` — 전문성·수치·볼드 패치
- `n8n-patches/test_keyword_pool.py` — 일반 5케이스 테스트
- `n8n-patches/test_keyword_extra.py` — 특수 2케이스 테스트
- `키워드풀_반영.json` — 최신 워크플로우 (로컬 전용, API 키 포함, gitignore)

---

## APK 다운로드 URL 및 GitHub Pages

- **랜딩 페이지**: `https://caifyhelp-cmyk.github.io/caify-product/`
- **APK 직접 링크 (항상 최신)**: `https://github.com/caifyhelp-cmyk/caify-product/releases/latest/download/caify.apk`

---

## 현재 버전: v1.5.7

### 버전 히스토리 요약
| 버전 | 주요 변경 |
|------|-----------|
| v1.5.7 | 포스팅 모드 3가지 확정 (정보성/믹스/사례형), 사례형 큐에서 제거 |
| v1.5.6 | n8n 프로비저닝 + 무료 유저(tier=0) 차단 + 포스팅 모드 시스템 |
| v1.5.5 | useHybridComposition WebView 계층 수정 |
| v1.5.4 | dispatchPaste findFocus fallback 추가 |
| v1.5.3 | dispatchKeyEvent Ctrl+V 이미지 trusted paste |
| v1.4.7 | FileProvider cache-path 추가 (이미지 클립보드 ERR 수정) |
| v1.4.6 | PostingBridge-style 이미지 클립보드 업로드 구현 |
| v1.4.5 | iframe script context 수정, SE3 paragraph[] 본문 주입 |
| v1.3.x | _papyrus paste, _tagService API 추가 |
| v1.2.x | 인앱 로그 뷰어, 어댑티브 아이콘, SE3 재작성 |

---

## 구현 완료 기능 (v1.5.7 기준)

| 화면 | 기능 |
|------|------|
| 로그인 | 서버 URL·ID·비밀번호 로그인, 자동 로그인 |
| 잠금 화면 | tier=0 무료 유저 차단, caifyhelp@gmail.com 안내, 로그아웃 |
| 채팅 | 15초 폴링, 시스템 메시지 + 액션버튼, 자유 입력 → 워크플로우 커스터마이징 |
| 포스팅 목록 | status=ready 포스팅 조회 |
| 포스팅 미리보기 | HTML 렌더링 |
| 발행(SE3 WebView) | 제목·본문·이미지·태그 자동 주입 → 발행완료/실패 API 통보 |
| 설정 | 서버주소·회원ID·토큰 저장, 내 플랜 뱃지(유료/무료), 포스팅 모드 카드, 개발 로그 뷰어 |
| 자동 업데이트 | GitHub Releases 감지 → APK 다운로드·설치 |

### 미구현 (다음 작업)
- 사례형(case) 수동 제출 화면 — 고객이 내용+이미지 입력 → case 워크플로우 실행
- 산출물 관리 화면 — case 결과물 목록 조회

---

## n8n 연동

### 인스턴스
- URL: `https://n8n.caify.ai` (자체 호스팅)
- API Key: `mock-server/n8n.config.js`에 저장 (git 추적)

### 워크플로우 구조
- **Queue Worker** `bUXjHTh7xEecPuOr` — 5분마다 실행, `caify_publish_queue`에서 `publish_date=오늘` 픽업
- **서브워크플로우 (복제 템플릿)**:
  | 키 | 워크플로우 ID |
  |----|--------------|
  | info  | `DvvwnamBcqnqVgCz` |
  | promo | `zUhFnjJvA7Fuz6UG` |
  | plusA | `gDW5xp9brX889Qmv` |
  | case  | `vUlrwTSj0b3TcIKg` |
- 유료 고객 등록 시 `POST /member/provision` → 4개 워크플로우 복제 (shared credentials)
- 서브워크플로우 마지막: `POST https://caify.ai/api` 로 생성된 포스팅 저장 (HTTP Request 노드)
  - ⚠️ `html` 필드가 하드코딩 `"11"` → 실제 생성 HTML 필드명으로 수정 필요
  - ⚠️ HTTP Request → return 노드 연결 확인 필요

### 포스팅 모드 (2026-04-24 확정)

| 모드 키 | 레이블 | 자동 발행 (caify_publish_queue) | 사례형(수동) |
|---------|--------|--------------------------------|-------------|
| `intensive` | 정보성 | info+promo+plusA 평일 3개/일 (주 15개) | 없음 |
| `mixed` | 믹스 | promo→info→plusA 순환 평일 1개/일 (주 5개) | 주 3회 |
| `case` | 사례형 | 없음 | 주 5회 |

- 사례형은 **항상 수동** — 고객이 내용+이미지 입력 → case 워크플로우 → 산출물 관리
- 모드 변경은 채팅에서 요청 → **다음 주 월요일** 적용 (ISO week 기반)

### 미확인 사항
- `caify_publish_queue` 테이블이 실 MySQL에 존재하는지
- info/promo/plusA 서브워크플로우의 마지막 HTTP Request 노드가 정상 동작하는지

---

## 핵심 설정값

### API 서버
| 환경 | 주소 |
|------|------|
| 현재 (테스트) | `https://caify-mock-server.onrender.com` |
| 로컬 에뮬레이터 | `http://10.0.2.2:3030` |
| **실서버 (caify.ai)** | `https://caify.ai` (전환 예정) |

- 서버 주소 변경: 앱 설정 화면에서 직접 수정 가능

### 네이버 에디터 설정
- **UA**: 데스크톱 Chrome 120 (모바일 UA → 네이버 "일시적 오류" 발생)
- **초기 URL**: `https://blog.naver.com/GoBlogWrite.naver`

---

## SE3 주입 동작 원리 (publish_screen.dart + naver_publisher.dart)

### 전체 흐름
1. `jsIsEditorReady()` — 1초 간격, 최대 60초 폴링
2. 준비되면 `_doInjectAndTempSave()`:
   - `jsDebugSE3()` → SE3 내부 구조 진단 (로그용)
   - `jsInjectTitle()` → 제목 입력 (SE3 _documentService API → iframe script → DOM 순)
   - `jsInjectHtml()` → 본문 HTML ClipboardEvent paste
   - 2초 대기 후 `jsInjectHtmlFallback()` → setDocumentData fallback
   - `_injectImagesViaClipboard()` → 이미지 클립보드 업로드 (아래 참고)
   - `jsClickTempSave()` → 임시저장
3. 항상 `editorReady`로 전환 (주입 실패해도 에디터 보임 → 사용자 직접 수정 가능)
4. 사용자가 "발행하기" 클릭 → `_doPublish()`:
   - `jsClickPublish()` → 발행 버튼 클릭
   - 1.5초 대기 (다이얼로그 열림)
   - `jsAddTagsInDialog()` → 발행 다이얼로그 태그 input에 주입

### 이미지 클립보드 업로드 (_injectImagesViaClipboard)
**PostingBridge와 동일한 원리**: 실제 Android 클립보드 → SE3 trusted paste event → 네이버 라이브러리 업로드

1. post.html에서 `<img src>` URL 파싱
2. `http.get`으로 이미지 다운로드 → 임시 파일 저장 (`getTemporaryDirectory()`)
3. MethodChannel `setClipboardImage` → `MainActivity.kt` → Android `ClipboardManager.setPrimaryClip()`
4. `jsFocusBodyEndAndPaste()` → SE3 본문 커서 끝 이동 + `doc.execCommand('paste')`
5. 4초 대기 (네이버 업로드 시간)

⚠️ **미확인**: `execCommand('paste')`가 Android WebView에서 trusted event로 처리되는지 테스트 필요.
만약 실패하면 → Android KeyEvent 주입 방식으로 전환 필요 (실제 Ctrl+V 시뮬레이션).

### MethodChannel (`caify/install`)
`MainActivity.kt`에 3개 메서드:
- `canInstall` → `packageManager.canRequestPackageInstalls()`
- `openInstallSettings` → 출처 불명 앱 설정 화면 열기
- `setClipboardImage` → FileProvider URI → Android ClipboardManager에 이미지 세팅

### FileProvider 설정
`provider_paths.xml` (authority: `ai.caify.caify_flutter.flutter_inappwebview.fileprovider`):
```xml
<paths>
  <external-path name="external_files" path="."/>
  <cache-path name="cache" path="."/>   ← v1.4.7에서 추가 (내부 캐시 허용)
  <files-path name="files" path="."/>
</paths>
```

### 태그 주입
- **발행 다이얼로그에서 주입** (에디터 본문이 아닌 발행 팝업의 태그 input)
- `jsAddTagsInDialog()` → `input[placeholder*="#태그"]` 탐색 → Enter key로 태그 확정
- 발행 버튼 클릭 후 **1.5초 대기** 후 실행

### 로그 진단 키
앱 설정 → 로그 보기 → 전체 복사:
| 로그 키 | 정상값 | 이상값 |
|---------|--------|--------|
| `[ready[N]]` | `ready_se3` | `not_ready:...` |
| `[se3_diag]` | `ed0=[..._documentService...]` | `no_iframe_doc` |
| `[inject_title]` | `ok_ds:setDocumentTitle` | `no_title_p` |
| `[inject_body]` | `paste_dispatched` | `no_body_el` |
| `[inject_body_fallback]` | `ok_setDocData:N` | `no_comps_in_doc` |
| `[img_clip]` | `clipboard=ok`, `paste[N]=ok:true` | `오류: PlatformException...` |
| `[inject_tags_dialog]` | `ok:N` | `no_tag_input\|inputs=[...]` |
| `[temp_save]` | `ok` | `no_save_btn\|...` |

---

## 실서버 전환 계획 (caify.ai → Bearer 토큰 API 추가)

### 현재 실서버 구조 (caify.ai PHP)
실서버는 **PHP 세션 기반** 인증, Flutter 앱은 **Bearer 토큰** 방식.
→ 실서버에 앱 전용 JSON API 엔드포인트 3개 추가 필요.

**새로 추가해야 할 API:**

#### 1. `api/app_login.php` — 앱 로그인 (Bearer 토큰 발급)
```
POST https://caify.ai/api/app_login.php
Body: { "member_id": "...", "passwd": "..." }
Response: { "ok": true, "member_pk": 1, "api_token": "...", "company_name": "..." }
```

#### 2. `api/app_posts.php` — 발행 대기 목록
```
GET https://caify.ai/api/app_posts.php?status=ready&member_pk=X
Header: Authorization: Bearer <api_token>
Response: [{ "id": 1, "title": "...", "html": "...", "tags": [...] }]
```

#### 3. `api/app_publish.php` — 발행 완료/실패 기록
```
POST https://caify.ai/api/app_publish.php
Header: Authorization: Bearer <api_token>
Body: { "id": 1, "action": "published" }
Response: { "ok": true }
```

### 전환 순서
1. `caify_member` 테이블에 `api_token VARCHAR(64)` 컬럼 추가
2. 위 3개 PHP 파일 작성 및 배포
3. Flutter `api_service.dart` 서버 주소 `caify.ai`로 변경
4. mock-server는 개발용으로만 유지

---

## CI/CD

- 트리거: `flutter-app/**` 변경 시 자동 빌드 (~5~15분, GitHub Actions 대기 시간 포함)
- 결과: GitHub Release에 `caify_버전.apk` + `caify.apk` (고정명)
- **버전 올리지 않으면 업데이트 알림 안 뜸** → 기능 수정 시 반드시 `pubspec.yaml` version 함께 bump
- Secrets: `KEYSTORE_BASE64`, `KEYSTORE_PASSWORD`, `KEY_ALIAS`, `KEY_PASSWORD`

---

## mock-server

```bash
cd mock-server && node server.js  # 포트 3030
```

- 테스트 계정: `testuser` / `password123`
- Render 배포 URL: `https://caify-mock-server.onrender.com`
- 테스트 포스트 이미지: Unsplash 실제 치과 이미지 (photo-1588776814546, photo-1606811841689)
- **주의**: Render는 별도 레포 `caifyhelp-cmyk/caify-mock-server` 참조 → server.js 수정 시 두 곳 모두 push 필요

---

## 알려진 이슈 및 미결 사항

### 이미지 업로드 (최우선)
- `execCommand('paste')`가 Android WebView에서 trusted event인지 미확인
- 실패 시 대안: Android `dispatchKeyEvent(KeyEvent.KEYCODE_V)` 주입

### 기타
- Render cold start (~30초): 업데이트 체크는 GitHub API로 대체 완료
- 태그 다이얼로그 주입 1.5초 타이밍: 네트워크 속도에 따라 다이얼로그가 늦게 열리면 실패 가능

---

## 다른 단말에서 작업 시작하기

```bash
git clone https://github.com/caifyhelp-cmyk/caify-product.git
cd caify-product/flutter-app && flutter pub get
git push  # → Actions 자동 빌드 (flutter-app/** 변경 시)
```

**기능 수정 후 배포 체크리스트:**
1. 코드 수정
2. `pubspec.yaml` version 번호 올리기 (반드시!)
3. `git add`, `git commit`, `git push`
4. GitHub Actions 완료 대기 (~5~15분)
5. 앱 재시작 → 업데이트 알림 확인
