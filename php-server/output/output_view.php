<?php
declare(strict_types=1);

session_start();

require "../inc/db.php";

// customer_id 가져오기
$customer_id = (int)($_SESSION['member']['id'] ?? 0);
$is_admin = ($customer_id === 10);

if ($customer_id <= 0) {
    header('Location: ../member/login.php');
    exit;
}

// ID 파라미터 받기
$post_id = (int)($_GET['id'] ?? 0);


// 저장(수정) 처리
$post = null;
$error_message = '';

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'approve' && $is_admin) {
            $approve_id = (int)($_POST['id'] ?? 0);
            if ($approve_id > 0) {
                $approve_stmt = $pdo->prepare('UPDATE ai_posts SET status = 1 WHERE id = :id LIMIT 1');
                $approve_stmt->execute([':id' => $approve_id]);
                header('Location: output_view.php?id=' . $approve_id . '&approved=1');
                exit;
            }
            $error_message = '잘못된 승인 요청입니다.';
        }

        if ($action === 'update') {
            $post_id = (int)($_POST['id'] ?? 0);
            $updated_html = $_POST['naver_html'] ?? '';
            $updated_title = trim($_POST['title'] ?? '');

            if ($post_id <= 0) {
                $error_message = '잘못된 요청입니다.';
            } else {
                $sql = '
                    UPDATE ai_posts
                    SET
                        naver_html = :naver_html,
                        title = COALESCE(NULLIF(:title, ""), title)
                    WHERE id = :id';
                $params = [
                    ':naver_html' => $updated_html,
                    ':title' => $updated_title,
                    ':id' => $post_id,
                ];

                // 관리자가 아니면 본인 글만 수정 가능
                if (!$is_admin) {
                    $sql .= ' AND customer_id = :customer_id';
                    $params[':customer_id'] = $customer_id;
                }
                $sql .= ' LIMIT 1';

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                header('Location: output_view.php?id=' . $post_id . '&saved=1');
                exit;
            }
        }
    }

    if ($post_id <= 0) {
        $error_message = '잘못된 접근입니다.';
    } else {
        if ($is_admin) {
            // 관리자: 모든 산출물 열람 가능
            $stmt = $pdo->prepare('
                SELECT p.id, p.title, p.subject, p.intro, p.html, p.naver_html, p.created_at, p.prompt_node_id, p.customer_id, p.status, COALESCE(m.company_name, "") AS company_name
                FROM ai_posts p
                LEFT JOIN caify_member m ON m.id = p.customer_id
                WHERE p.id = :id
                LIMIT 1
            ');
            $stmt->execute([':id' => $post_id]);
        } else {
            // 일반회원: status=1 + 생성일이 오늘 기준 2일 전만 열람 가능
            $stmt = $pdo->prepare('
                SELECT p.id, p.title, p.subject, p.intro, p.html, p.naver_html, p.created_at, p.prompt_node_id, p.customer_id, p.status, COALESCE(m.company_name, "") AS company_name
                FROM ai_posts p
                LEFT JOIN caify_member m ON m.id = p.customer_id
                WHERE p.id = :id
                  AND p.status = 1
                  AND DATE(p.created_at) <= DATE_SUB(CURDATE(), INTERVAL 2 DAY) AND customer_id = :customer_id
                LIMIT 1
            ');
            $stmt->execute([':id' => $post_id, ':customer_id' => $customer_id]);
        }

        $post = $stmt->fetch();

        if (!$post) {
            $error_message = '열람 권한이 없거나 산출물을 찾을 수 없습니다.';
        }
    }
} catch (Exception $e) {
    if ($error_message === '') {
        $error_message = '데이터를 처리하는 중 오류가 발생했습니다.';
    }
}

$title = $post['title'] ?? '제목 없음';
$naver_html = $post['naver_html'] ?? '';
$created_at = $post['created_at'] ?? '';
$date = $created_at ? date('Y.m.d.H:i', strtotime($created_at)) : '';
$prompt_node_id = $post['prompt_node_id'] ?? '';
$is_approved = ((int)($post['status'] ?? 0) === 1);
$can_edit = $is_admin || ((int)($post['customer_id'] ?? 0) === $customer_id);
// 현재 페이지 체크해서 css 로드 
$currentPage = 'bg_page';

?>
<?php include '../header.inc.php'; ?>
<style>
	.output__view img,
	#naverHtmlContent img {
		max-width: 100% !important;
		height: auto !important;
		display: block;
	}
	.btn__output.status {
		border: 0;
		cursor: default;
		font-weight: 700;
	}
	.btn__output.status--approved {
		background: #e8f8ee;
		color: #18794e;
	}
	.btn__output.status--pending {
		background: #fff4e5;
		color: #b45309;
	}
	.btn__output.status--action {
		background: #2563eb;
		color: #fff;
		cursor: pointer;
	}
	.btn__output.status--action:hover {
		background: #1d4ed8;
	}
    .publish-warning-modal {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(15, 23, 42, 0.56);
        backdrop-filter: blur(6px);
        z-index: 10020;
    }
    .publish-warning-modal.is-open {
        display: flex;
    }
    .publish-warning-modal__dialog {
        width: min(620px, 100%);
        padding: 28px 24px 22px;
        border: 1px solid #dbe7ff;
        border-radius: 20px;
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        box-shadow: 0 28px 60px rgba(15, 23, 42, 0.2);
    }
    .publish-warning-modal__title {
        margin: 0 0 14px;
        color: #0f172a;
        font-size: 1.12rem;
        font-weight: 800;
        line-height: 1.45;
    }
    .publish-warning-modal__body {
        color: #475569;
        font-size: 0.94rem;
        line-height: 1.8;
        white-space: pre-line;
    }
    .publish-warning-modal__footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 20px;
    }
    .publish-warning-modal__cancel {
        background: #fff !important;
        color: #334155 !important;
        border: 1px solid #dbe3ef !important;
    }
    body.publish-warning-open {
        overflow: hidden;
    }
    @media (max-width: 768px) {
        .publish-warning-modal {
            padding: 14px;
        }
        .publish-warning-modal__dialog {
            padding: 22px 18px 18px;
        }
        .publish-warning-modal__footer {
            flex-direction: column;
        }
        .publish-warning-modal__footer .btn {
            width: 100%;
        }
    }
</style>

    <main class="main">
        <div class="container ">
            <?php include '../inc/snb.inc.php'; ?>
            <div class="content__wrap">
                <div class="content__inner ">
                    <div class="content__header">
                        <h2 class="content__header__title">산출물 관리</h2>
                    </div>
                    <div class="content">
                        <?php if ($error_message): ?>
                            <div style="text-align: center; padding: 50px 0;">
                                <p style="color: #d32f2f; font-size: 16px; margin-bottom: 20px;"><?= htmlspecialchars($error_message) ?></p>
                                <a href="output_list.php" class="btn">목록으로 돌아가기</a>
                            </div>
                        <?php else: ?>
						
                          <form method="post" id="editForm">
                            <div class="output__view">
                                <div class="output__view__title">
                                    <h3><?= htmlspecialchars($title) ?></h3>
                                    <span class="text--small"><?= $date ?></span>
                                </div>
                                <div class="output__view__file">
                                    <?php if ($is_admin): ?>
                                        <?php if ($is_approved): ?>
                                            <button type="button" class="btn__output status status--approved" disabled>승인완료</button>
                                        <?php else: ?>
                                            <button type="button" class="btn__output status status--action" onclick="approvePost()">미승인 · 승인하기</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($prompt_node_id && $can_edit): ?>
										
                                        <a href="../prompt/prompt.php?id=<?= htmlspecialchars($prompt_node_id) ?>" class="btn__output">프롬프트 수정</a>
                                    <?php endif; ?>
                                    <a href="output_publish_naver.php?id=<?= (int)$post_id ?>" class="btn__output btn--copy">산출물 발행</a>
                                </div>
									<input type="hidden" name="action" id="form_action" value="update">
									<input type="hidden" name="id" value="<?= (int)$post_id ?>">

									<!-- (선택) 제목도 수정하고 싶으면 input을 둠 -->
									<!-- <input type="text" name="title" value="<?= htmlspecialchars($title) ?>" class="input" style="width:100%;margin-bottom:10px;"> -->
									<input type="hidden" name="title" value="<?= htmlspecialchars($title) ?>">

									<!-- TinyMCE가 이 textarea를 에디터로 바꿔줌 -->
									<textarea id="naverHtmlEditor"><?= htmlspecialchars($naver_html) ?></textarea>

									<!-- 실제 저장되는 필드(에디터 내용이 여기로 들어감) -->
									<input type="hidden" name="naver_html" id="naver_html_hidden">
                            </div>                         
                            <div class="button__board">
                                <a href="output_list.php" class="btn">목록이동</a>
                                <?php if ($prompt_node_id && $can_edit): ?>
									<button type="button" class="btn" onclick="saveEditedContent()">수정 저장</button>
                                    <a href="../prompt/prompt.php?id=<?= htmlspecialchars($prompt_node_id) ?>" class="btn">프롬프트 수정</a>
                                <?php endif; ?>
                                <a href="output_publish_naver.php?id=<?= (int)$post_id ?>" class="btn btn--primary">산출물 발행</a>
                            </div>
						</form>
                        <div id="publishWarningModal" class="publish-warning-modal" aria-hidden="true">
                            <div class="publish-warning-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="publishWarningTitle">
                                <h3 id="publishWarningTitle" class="publish-warning-modal__title">즉시 발행 전 확인</h3>
                                <div class="publish-warning-modal__body">짧은 시간 내 여러 게시물을 연속 발행하는 방식은 블로그 노출 및 운영 불이익이 발생할 수 있으므로 게시 시간 간격을 두고 발행하는 것을 권장합니다.<br/>
권장 발행 방법 : 게시물은 <strong>최소 30분 이상</strong>의 시간 간격을 두고 발행하는 것이 좋습니다.
그럼에도 즉시 발행 하시겠습니까?</div>
                                <div class="publish-warning-modal__footer">
                                    <button type="button" class="btn publish-warning-modal__cancel" onclick="closePublishWarning()">취소</button>
                                    <button type="button" class="btn btn--primary" onclick="confirmImmediatePublish()">즉시 발행</button>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
	
	function saveEditedContent() {
		const form = document.getElementById('editForm');
		const actionInput = document.getElementById('form_action');
		const hidden = document.getElementById('naver_html_hidden');

		const html = (window.tinymce && tinymce.get('naverHtmlEditor'))
			? tinymce.get('naverHtmlEditor').getContent()
			: document.getElementById('naverHtmlEditor').value;

		actionInput.value = 'update';
		hidden.value = html;
		form.submit();
	}

	function approvePost() {
		const form = document.getElementById('editForm');
		const actionInput = document.getElementById('form_action');
		actionInput.value = 'approve';
		form.submit();
	}
	
	async function publishToNaver() {
        try {
            const formData = new FormData();
            formData.append('action', 'check_recent_posting');
            formData.append('id', '<?= (int)$post_id ?>');

            const response = await fetch('output_publish_guard.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const raw = await response.text();
            const result = JSON.parse(raw);

            if (!response.ok || !result.ok) {
                const msg = result.error || '최근 발행 이력 조회에 실패했습니다.';
                const detail = result.detail ? ('\n' + result.detail) : '';
                throw new Error(msg + detail);
            }

            if (result.has_recent_posting) {
                openPublishWarning();
                return;
            }
        } catch (error) {
            alert(error && error.message ? error.message : '최근 발행 이력 조회에 실패했습니다.');
            return;
        }

        executePublishToNaver();
	}

    async function executePublishToNaver() {
		const title = document.querySelector('.output__view__title h3').innerText;

		// ✅ 에디터 HTML 가져오기
		const html = (window.tinymce && tinymce.get('naverHtmlEditor'))
			? tinymce.get('naverHtmlEditor').getContent()
			: document.getElementById('naverHtmlEditor').value;

        try {
            const formData = new FormData();
            formData.append('action', 'mark_posting');
            formData.append('id', '<?= (int)$post_id ?>');

            const response = await fetch('output_publish_guard.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const raw = await response.text();
            const result = JSON.parse(raw);
            if (!response.ok || !result.ok) {
                throw new Error(result.error || '발행 시간 기록에 실패했습니다.');
            }
        } catch (error) {
            alert(error && error.message ? error.message : '발행 시간 기록에 실패했습니다.');
            return;
        }

		window.postMessage({
			type: "OPEN_WRITE",
			title: title,
			html: html
		}, "*");

		// 토스트는 그대로
		const toast = document.createElement('div');
		toast.textContent = '네이버 블로그 창이 열립니다...';
		toast.style.cssText = `
			position: fixed; top: 50%; left: 50%;
			transform: translate(-50%, -50%);
			background-color: #2196F3; color: white;
			padding: 20px 40px; border-radius: 8px;
			font-size: 16px; font-weight: bold;
			z-index: 10000; box-shadow: 0 4px 6px rgba(0,0,0,0.2);
		`;
		document.body.appendChild(toast);
		setTimeout(() => {
			toast.style.opacity = '0';
			toast.style.transition = 'opacity 0.3s';
			setTimeout(() => document.body.removeChild(toast), 300);
		}, 2000);
	}

    function openPublishWarning() {
        var modal = document.getElementById('publishWarningModal');
        if (!modal) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('publish-warning-open');
    }

    function closePublishWarning() {
        var modal = document.getElementById('publishWarningModal');
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('publish-warning-open');
    }

    function confirmImmediatePublish() {
        closePublishWarning();
        executePublishToNaver();
    }
	
    function copyRenderedHTML() {
        const content = document.getElementById('naverHtmlContent');
        
        if (!content) {
            alert('복사할 내용이 없습니다.');
            return;
        }

        // Range와 Selection을 사용하여 렌더링된 HTML 복사
        const range = document.createRange();
        range.selectNode(content);
        
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);

        try {
            // execCommand를 사용한 복사
            const successful = document.execCommand('copy');
            
            if (successful) {
                // 복사 성공 메시지
                showCopySuccess();
            } else {
                // Clipboard API 시도
                fallbackCopyHTML(content);
            }
        } catch (err) {
            console.error('복사 실패:', err);
            // Clipboard API 시도
            fallbackCopyHTML(content);
        } finally {
            // 선택 해제
            selection.removeAllRanges();
        }
    }

    function fallbackCopyHTML(content) {
        // Clipboard API를 사용하여 HTML과 텍스트 모두 복사
        if (navigator.clipboard && navigator.clipboard.write) {
            const htmlBlob = new Blob([content.innerHTML], { type: 'text/html' });
            const textBlob = new Blob([content.innerText], { type: 'text/plain' });
            
            const clipboardItem = new ClipboardItem({
                'text/html': htmlBlob,
                'text/plain': textBlob
            });
            
            navigator.clipboard.write([clipboardItem])
                .then(() => {
                    showCopySuccess();
                })
                .catch(err => {
                    console.error('Clipboard API 복사 실패:', err);
                    alert('복사에 실패했습니다. 브라우저가 클립보드 접근을 허용하지 않습니다.');
                });
        } else {
            alert('이 브라우저는 클립보드 복사를 지원하지 않습니다.');
        }
    }

    function showCopySuccess() {
        // 성공 메시지를 표시하는 토스트 알림
        const toast = document.createElement('div');
        toast.textContent = '산출물이 클립보드에 복사되었습니다!';
        toast.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: #4CAF50;
            color: white;
            padding: 20px 40px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            z-index: 10000;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
        `;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(() => {
                document.body.removeChild(toast);
            }, 300);
        }, 2000);
    }
    </script>

	<!-- Place the first <script> tag in your HTML's <head> -->
<script src="https://cdn.tiny.cloud/1/56udguqthmroe2m50bqwub5yspucrigddgs5y45p380efl73/tinymce/8/tinymce.min.js" referrerpolicy="origin" crossorigin="anonymous"></script>

<!-- Place the following <script> and <textarea> tags your HTML's <body> -->
<script>
tinymce.init({
  selector: '#naverHtmlEditor',
  height: 650,
  menubar: true,
  relative_urls: false,
  remove_script_host: false,
  convert_urls: false,
  content_style: 'body{word-break:break-word;} img{max-width:100% !important; height:auto !important; display:block;} table{max-width:100%;}',
  plugins: [
    'autolink', 'link', 'lists', 'table', 'searchreplace',
    'visualblocks', 'wordcount', 'code', 'charmap', 'emoticons'
  ],
  toolbar: 'undo redo | blocks | bold italic underline | alignleft aligncenter alignright | bullist numlist | link table | charmap emoticons | code',
  branding: false
});
</script>
	

<?php include '../footer.inc.php'; ?>