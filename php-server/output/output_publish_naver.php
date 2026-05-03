<?php
declare(strict_types=1);

/**
 * 확장프로그램 없이 네이버 블로그 발행 도우미
 *
 * 흐름:
 *   1. 정렬·폰트 선택
 *   2. 스타일 적용된 HTML 클립보드 복사
 *   3. 네이버 에디터 팝업 열기
 *   4. 에디터에서 Ctrl+V 붙여넣기
 *   5. 발행 완료 버튼 → 서버에 posting_date 기록
 */

session_start();

require "../inc/db.php";

header('Content-Type: text/html; charset=UTF-8');

$customer_id = (int)($_SESSION['member']['id'] ?? 0);
$is_admin    = ($customer_id === 10);

if ($customer_id <= 0) {
    header('Location: ../member/login.php');
    exit;
}

$post_id = (int)($_GET['id'] ?? 0);
if ($post_id <= 0) {
    echo "<script>alert('잘못된 접근입니다.'); location.href='output_list.php';</script>";
    exit;
}

// ── 발행 완료 기록 (AJAX) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'mark_done') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $pdo = db();
        $sql = 'UPDATE ai_posts SET posting_date = NOW() WHERE id = :id';
        $params = [':id' => $post_id];
        if (!$is_admin) {
            $sql .= ' AND customer_id = :cid';
            $params[':cid'] = $customer_id;
        }
        $sql .= ' LIMIT 1';
        $pdo->prepare($sql)->execute($params);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── 포스트 로드 ───────────────────────────────────────────────
try {
    $pdo = db();
    if ($is_admin) {
        $stmt = $pdo->prepare('SELECT id, title, naver_html, tags, customer_id, status FROM ai_posts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $post_id]);
    } else {
        $stmt = $pdo->prepare('SELECT id, title, naver_html, tags, customer_id, status FROM ai_posts WHERE id = :id AND customer_id = :cid AND status = 1 LIMIT 1');
        $stmt->execute([':id' => $post_id, ':cid' => $customer_id]);
    }
    $post = $stmt->fetch();
} catch (Throwable $e) {
    $post = null;
}

if (!is_array($post)) {
    echo "<script>alert('게시물을 찾을 수 없습니다.'); location.href='output_list.php';</script>";
    exit;
}

$title    = (string)($post['title'] ?? '');
$html_raw = (string)($post['naver_html'] ?? '');
$tags_raw = (string)($post['tags'] ?? '[]');
$tags     = json_decode($tags_raw, true) ?: [];

// ── 네이버 폰트 목록 ──────────────────────────────────────────
$naver_fonts = [
    ''                                                           => '기본 (변경 없음)',
    'NanumGothic, \'나눔고딕\', sans-serif'                      => '나눔고딕',
    'NanumMyeongjo, \'나눔명조\', serif'                         => '나눔명조',
    'NanumBarunGothic, \'나눔바른고딕\', sans-serif'             => '나눔바른고딕',
    '\'Malgun Gothic\', \'Apple SD Gothic Neo\', sans-serif'     => '맑은고딕',
    'dotum, \'돋움\', sans-serif'                                => '돋움',
    'gulim, \'굴림\', sans-serif'                                => '굴림',
    'batang, \'바탕\', serif'                                    => '바탕',
    'gungsuh, \'궁서\', serif'                                   => '궁서',
    'Arial, sans-serif'                                          => 'Arial',
    'Verdana, sans-serif'                                        => 'Verdana',
    'D2Coding, monospace'                                        => 'D2Coding',
];

// ── HTML에 정렬·폰트 스타일 적용 ─────────────────────────────
function applyPublishStyles(string $html, string $align, string $fontFamily): string
{
    if ($align === 'left' && $fontFamily === '') {
        return $html;
    }
    $styles = [];
    if ($align !== 'left') {
        $styles[] = 'text-align:' . $align;
    }
    if ($fontFamily !== '') {
        $styles[] = 'font-family:' . $fontFamily;
    }
    $styleStr = implode(';', $styles);

    // <p> 태그에 인라인 스타일 추가
    return preg_replace_callback(
        '/<p(\s[^>]*)?>/i',
        static function (array $m) use ($styleStr): string {
            $attrs = $m[1] ?? '';
            if (preg_match('/\bstyle="([^"]*)"/i', $attrs, $sm)) {
                $newAttrs = str_replace(
                    'style="' . $sm[1] . '"',
                    'style="' . $sm[1] . ';' . $styleStr . '"',
                    $attrs
                );
                return '<p' . $newAttrs . '>';
            }
            return '<p' . $attrs . ' style="' . $styleStr . '">';
        },
        $html
    ) ?? $html;
}

$currentPage = 'bg_page';
?>
<?php include '../header.inc.php'; ?>
<style>
.publish-helper {
    max-width: 820px;
    margin: 0 auto;
}
.publish-helper__title {
    font-size: 1.15rem;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 4px;
    line-height: 1.4;
}
.publish-helper__meta {
    color: #94a3b8;
    font-size: 0.85rem;
    margin-bottom: 28px;
}
.option-group {
    margin-bottom: 22px;
}
.option-group__label {
    font-size: 0.82rem;
    font-weight: 700;
    color: #475569;
    margin-bottom: 10px;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.align-btns {
    display: flex;
    gap: 8px;
}
.align-btn {
    flex: 1;
    padding: 10px 6px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 600;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: border-color .15s, background .15s, color .15s;
}
.align-btn.active {
    border-color: #2563eb;
    background: #eff6ff;
    color: #1d4ed8;
}
.font-select {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 0.9rem;
    color: #334155;
    background: #fff;
    outline: none;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%2394a3b8' d='M6 8L0 0h12z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 36px;
}
.font-select:focus {
    border-color: #2563eb;
}
.steps {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 28px;
}
.step {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    background: #f8fafc;
}
.step__num {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #2563eb;
    color: #fff;
    font-weight: 800;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.step__num.done {
    background: #16a34a;
}
.step__body {
    flex: 1;
}
.step__title {
    font-weight: 700;
    font-size: 0.92rem;
    color: #1e293b;
    margin-bottom: 2px;
}
.step__desc {
    font-size: 0.8rem;
    color: #64748b;
}
.step__btn {
    flex-shrink: 0;
    padding: 9px 18px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.88rem;
    cursor: pointer;
    border: none;
    transition: background .15s, opacity .15s;
}
.step__btn--copy {
    background: #2563eb;
    color: #fff;
}
.step__btn--copy:hover { background: #1d4ed8; }
.step__btn--copy.copied {
    background: #16a34a;
}
.step__btn--open {
    background: #03c75a;
    color: #fff;
}
.step__btn--open:hover { background: #059148; }
.step__btn--done {
    background: #fff;
    color: #64748b;
    border: 1.5px solid #cbd5e1;
}
.step__btn--done:hover { background: #f1f5f9; }
.step__btn--done.marked {
    background: #f0fdf4;
    color: #16a34a;
    border-color: #86efac;
}
.tag-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
}
.tag-badge {
    padding: 3px 10px;
    border-radius: 999px;
    background: #f1f5f9;
    color: #475569;
    font-size: 0.78rem;
    font-weight: 600;
}
</style>

<main class="main">
    <div class="container">
        <?php include '../inc/snb.inc.php'; ?>
        <div class="content__wrap">
            <div class="content__inner">
                <div class="content__header">
                    <h2 class="content__header__title">네이버 발행 도우미</h2>
                </div>
                <div class="content">
                    <div class="publish-helper">
                        <div class="publish-helper__title"><?= htmlspecialchars($title) ?></div>
                        <div class="publish-helper__meta">확장프로그램 없이 네이버 블로그에 발행합니다</div>

                        <!-- 정렬 선택 -->
                        <div class="option-group">
                            <div class="option-group__label">본문 정렬</div>
                            <div class="align-btns">
                                <button type="button" class="align-btn active" data-align="left" onclick="setAlign('left', this)">
                                    ☰ 왼쪽
                                </button>
                                <button type="button" class="align-btn" data-align="center" onclick="setAlign('center', this)">
                                    ≡ 가운데
                                </button>
                                <button type="button" class="align-btn" data-align="right" onclick="setAlign('right', this)">
                                    ☱ 오른쪽
                                </button>
                            </div>
                        </div>

                        <!-- 폰트 선택 -->
                        <div class="option-group">
                            <div class="option-group__label">본문 폰트</div>
                            <select class="font-select" id="fontSelect" onchange="onFontChange(this.value)">
                                <?php foreach ($naver_fonts as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 태그 -->
                        <?php if (!empty($tags)): ?>
                        <div class="option-group">
                            <div class="option-group__label">태그</div>
                            <div class="tag-list">
                                <?php foreach ($tags as $tag): ?>
                                    <span class="tag-badge">#<?= htmlspecialchars((string)$tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div style="margin-top:8px; font-size:0.78rem; color:#94a3b8;">
                                네이버 에디터에서 태그 입력란에 직접 입력해 주세요.
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 단계별 발행 -->
                        <div class="steps">
                            <!-- Step 1 -->
                            <div class="step">
                                <div class="step__num" id="num1">1</div>
                                <div class="step__body">
                                    <div class="step__title">내용 복사하기</div>
                                    <div class="step__desc">선택한 정렬·폰트가 적용된 HTML이 클립보드에 복사됩니다</div>
                                </div>
                                <button type="button" class="step__btn step__btn--copy" id="copyBtn" onclick="copyHtml()">
                                    📋 복사하기
                                </button>
                            </div>

                            <!-- Step 2 -->
                            <div class="step">
                                <div class="step__num" id="num2">2</div>
                                <div class="step__body">
                                    <div class="step__title">네이버 에디터 열기</div>
                                    <div class="step__desc">새 탭에서 네이버 블로그 글쓰기 화면이 열립니다</div>
                                </div>
                                <button type="button" class="step__btn step__btn--open" onclick="openNaver()">
                                    ✍ 에디터 열기
                                </button>
                            </div>

                            <!-- Step 3 -->
                            <div class="step">
                                <div class="step__num" id="num3">3</div>
                                <div class="step__body">
                                    <div class="step__title">에디터에서 Ctrl+V 붙여넣기</div>
                                    <div class="step__desc">
                                        네이버 에디터 본문 클릭 → Ctrl+V (Mac: ⌘+V)<br>
                                        제목 입력 → 태그 입력 → <strong>발행하기</strong> 클릭
                                    </div>
                                </div>
                            </div>

                            <!-- Step 4: 발행 완료 기록 -->
                            <div class="step">
                                <div class="step__num done" id="num4">✓</div>
                                <div class="step__body">
                                    <div class="step__title">발행 완료 기록</div>
                                    <div class="step__desc">네이버에서 발행하기를 누른 후 클릭해 주세요</div>
                                </div>
                                <button type="button" class="step__btn step__btn--done" id="doneBtn" onclick="markDone()">
                                    발행 완료
                                </button>
                            </div>
                        </div>

                        <div style="margin-top:20px; text-align:right;">
                            <a href="output_list.php" class="btn" style="font-size:0.85rem;">← 목록으로</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
// ── 원본 HTML (PHP에서 전달) ──────────────────────────────────
const RAW_HTML = <?= json_encode($html_raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const POST_TITLE = <?= json_encode($title, JSON_UNESCAPED_UNICODE) ?>;
const POST_ID = <?= (int)$post_id ?>;

const LS_ALIGN = 'caify_publish_align';
const LS_FONT  = 'caify_publish_font';

let selectedAlign = localStorage.getItem(LS_ALIGN) || 'left';
let selectedFont  = localStorage.getItem(LS_FONT)  || '';

// 페이지 로드 시 저장된 설정 복원
(function restoreSettings() {
    document.querySelectorAll('.align-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.align === selectedAlign);
    });
    const fontSel = document.getElementById('fontSelect');
    if (fontSel) fontSel.value = selectedFont;
})();

function setAlign(align, btn) {
    selectedAlign = align;
    localStorage.setItem(LS_ALIGN, align);
    document.querySelectorAll('.align-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

function onFontChange(val) {
    selectedFont = val;
    localStorage.setItem(LS_FONT, val);
}

// ── HTML에 정렬·폰트 적용 (클라이언트 사이드) ─────────────────
function buildStyledHtml() {
    if (selectedAlign === 'left' && selectedFont === '') return RAW_HTML;

    const styles = [];
    if (selectedAlign !== 'left') styles.push('text-align:' + selectedAlign);
    if (selectedFont !== '')       styles.push('font-family:' + selectedFont);
    const styleStr = styles.join(';');

    return RAW_HTML.replace(/<p(\s[^>]*)?>/gi, (m, attrs) => {
        attrs = attrs || '';
        const sm = attrs.match(/\bstyle="([^"]*)"/i);
        if (sm) {
            return '<p' + attrs.replace(sm[0], 'style="' + sm[1] + ';' + styleStr + '"') + '>';
        }
        return '<p' + attrs + ' style="' + styleStr + '">';
    });
}

// ── 클립보드 복사 ────────────────────────────────────────────
async function copyHtml() {
    const styledHtml = buildStyledHtml();
    const btn = document.getElementById('copyBtn');

    try {
        if (navigator.clipboard && window.ClipboardItem) {
            const htmlBlob = new Blob([styledHtml], { type: 'text/html' });
            const textBlob = new Blob([document.createElement('div').appendChild(Object.assign(document.createElement('div'), {innerHTML: styledHtml})).parentElement.innerText || styledHtml], { type: 'text/plain' });
            await navigator.clipboard.write([
                new ClipboardItem({ 'text/html': htmlBlob, 'text/plain': textBlob })
            ]);
        } else {
            // fallback: execCommand
            const tmp = document.createElement('div');
            tmp.innerHTML = styledHtml;
            tmp.style.position = 'fixed';
            tmp.style.left = '-9999px';
            document.body.appendChild(tmp);
            const range = document.createRange();
            range.selectNode(tmp);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(range);
            document.execCommand('copy');
            window.getSelection().removeAllRanges();
            document.body.removeChild(tmp);
        }

        btn.textContent = '✅ 복사 완료!';
        btn.classList.add('copied');
        document.getElementById('num1').textContent = '✓';
        document.getElementById('num1').style.background = '#16a34a';
        setTimeout(() => {
            btn.textContent = '📋 복사하기';
            btn.classList.remove('copied');
        }, 3000);
    } catch (e) {
        alert('클립보드 복사에 실패했습니다.\n브라우저 설정에서 클립보드 접근을 허용해 주세요.\n\n오류: ' + e.message);
    }
}

// ── 네이버 에디터 열기 ────────────────────────────────────────
function openNaver() {
    window.open('https://blog.naver.com/GoBlogWrite.naver', '_blank',
        'width=1280,height=900,noopener,noreferrer');
    document.getElementById('num2').textContent = '✓';
    document.getElementById('num2').style.background = '#16a34a';
}

// ── 발행 완료 기록 ────────────────────────────────────────────
async function markDone() {
    const btn = document.getElementById('doneBtn');
    btn.disabled = true;
    btn.textContent = '기록 중...';

    try {
        const form = new FormData();
        form.append('action', 'mark_done');
        const resp = await fetch('output_publish_naver.php?id=' + POST_ID, {
            method: 'POST',
            body: form,
            credentials: 'same-origin'
        });
        const result = await resp.json();
        if (result.ok) {
            btn.textContent = '✅ 기록 완료';
            btn.classList.add('marked');
            document.getElementById('num4').textContent = '✓';
        } else {
            btn.textContent = '재시도';
            btn.disabled = false;
            alert('기록 실패: ' + (result.error || '알 수 없는 오류'));
        }
    } catch (e) {
        btn.textContent = '재시도';
        btn.disabled = false;
        alert('네트워크 오류: ' + e.message);
    }
}
</script>

<?php include '../footer.inc.php'; ?>
