<?php
    $currentPage = 'contact';
    include 'header.inc.php'; 
    ?> 
    <main class="main">
        <div class="main__container">
            <section class="section contact contact--form">
                <div class="contact__aside">
                    <div class="contact__aside-inner">
                        <img src="../images/common/caify_logo_glass_01.png" alt="caify_glass_logo_visual" class="caify-glass--visual">
                        <div class="contact__aside-info">
                            <img class="caify-logo" src="../images/common/caify_logo.png" alt="caify_logo">
                            <h2 class="title-main">서비스 문의하기</h2>
                            <p class="contact__aside-desc">내용 작성 후 제출해주시면<br>
                            빠른 시간 내에 연락 드리겠습니다.</p>
                            <ul class="contact__aside-list">
                                <li class="contact__aside-item">전화번호 1551-7940</li>
                                <li class="contact__aside-item">이메일 contact@caify.ai</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="contact__contents">
                    <div class="contact__contents-inner">
                        <div class="contact__contents-card">
                            <?php /*  기업 정보 */?>
                            <fieldset class="contact-form__group contact-form__group--company">
                                <legend class="contact-form__group-title">기업 정보
                                </legend>
                                <ul class="contact-form__list">
                                    <li class="contact-form__item">
                                        <label for="company_name" class="contact-form__label">기업명<span class="text-required">*</span></label>
                                        <input class="contact-form__input form-input--focus-border" type="text" id="company_name" placeholder="기업명을 입력해주세요.">
                                    </li>
                                    <li class="contact-form__item">
                                        <label for="company_industry" class="contact-form__label">업종<span class="text-required">*</span></label>
                                        <input class="contact-form__input form-input--focus-border" type="text" id="company_industry" placeholder="업종을 입력해주세요.">
                                    </li>
                                    <li class="contact-form__item">
                                        <label for="company_position" class="contact-form__label">직급/직책</label>
                                        <input class="contact-form__input form-input--focus-border" type="text" id="company_position" placeholder="직급/직책을 입력해주세요.">
                                    </li>
                                </ul>
                            </fieldset>
                            <?php /* // end : 기업 정보 */?>
    
                            <?php /* 담당자 정보 */?>
                            <fieldset class="contact-form__group contact-form__group--user">
                                <legend class="contact-form__group-title">담당자 정보</legend>
                                <ul class="contact-form__list">
                                <?php /* 성함 */?>
                                    <li class="contact-form__item">
                                        <label for="user_name" class="contact-form__label">성함<span class="text-required">*</span></label>
                                        <input class="contact-form__input form-input--focus-border" type="text" id="user_name" name="user_name" placeholder="회사명을 입력해주세요.">
                                    </li>
                                    <?php /*  연락처 */?>
                                    <li class="contact-form__item">
                                        <label for="user_phone" class="contact-form__label">연락처<span class="text-required">*</span></label>
                                        <input class="contact-form__input form-input--focus-border" type="text" id="user_phone" name="user_phone" placeholder="회사명을 입력해주세요.">
                                    </li>
                                    <?php /* 이메일 */?>
                                    <li class="contact-form__item contact-form__item--email">
                                        <label for="user_email" class="contact-form__label">이메일<span class="text-required">*</span>
                                        </label>
                                        <div class="email-form">
                                            <div class="email-form__input-wrap form-input--focus-border">
                                                <input class="contact-form__input form-input--focus-border" type="text" id="user_email" name="user_email" placeholder="이메일을 입력해주세요.">
                                                <span class="email-form__at">@</span>
                                                <div class="email-form__select-wrap">
                                                    <select class="email-form__select-box form-input--focus-border" name="select_email" id="select_email" required>
                                                        <option class="email-form__select-option" value="" selected disabled hidden>선택하기</option>
                                                        <option value="self_write">직접입력</option>
                                                        <option value="naver.com">naver.com</option>
                                                        <option value="gmail.com">gmail.com</option>
                                                        <option value="daum.net">daum.net</option>
                                                        <option value="nate.com">nate.com</option>
                                                        <option value="hanmail.net">hanmail.net</option>
                                                        <option value="hotmail.com">hotmail.com</option>
                                                        <option value="yahoo.com">yahoo.com</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <?php /* email-form__input-wrap : 
                                        option:직접입력 선택시 -> 
                                        이메일 값이 올바르지 않을경우 email-form__notice : {display: block;} 변경
                                        ex) @ 빼먹기, option value값의 이메일 예시리스트와 주소값이 맞는지
                                        */?>
                                        <div class="email-form__notice">이메일 형식이 올바르지 않습니다.</div>
                                    </li>
                                    <?php /* end : 이메일 */?>
                                    <?php /* 유형 */?>
                                    <li class="contact-form__item contact-form__item--subscribe">
                                        <label for="service_type" class="contact-form__label">구독유형<span class="text-required">*</span></label>
                                        <div class="contact-form__select-wrap">
                                            <select class="contact-form__select form-input--focus-border" id="service_type" name="service_type">
                                                <option value="" selected disabled hidden>서비스를 선택하세요</option>
                                                <option value="blog">블로그</option>
                                                <option value="homepage">홈페이지</option>
                                                <option value="package-blog-homepage">패키지(블로그 + 홈페이지)</option>
                                                <option value="package-blog-homepage">기타</option>
                                            </select>
                                        </div>
                                    </li>
                                    <?php /*  유입경로 */?>
                                    <li class="contact-form__referrer">
                                        <span class="contact-form__label">유입경로<span class="text-required">*</span></span>
                                        <div class="contact-form__radio-wrap">
                                            <div class="contact-form__radio-group">
                                                <label class="contact-form__radio">
                                                    <input class="contact-form__radio-input" type="radio" name="inflow" value="ad">
                                                    <span class="contact-form__radio-text">광고</span>
                                                </label>
                                                <label class="contact-form__radio">
                                                    <input class="contact-form__radio-input" type="radio" name="inflow" value="blog">
                                                    <span class="contact-form__radio-text">블로그</span>
                                                </label>
                                                <label class="contact-form__radio">
                                                    <input class="contact-form__radio-input" type="radio" name="inflow" value="introduce">
                                                    <span class="contact-form__radio-text">소개</span>
                                                </label>
                                                <label class="contact-form__radio">
                                                    <input class="contact-form__radio-input" type="radio" name="inflow" value="search">
                                                    <span class="contact-form__radio-text">검색</span>
                                                </label>
                                                <label class="contact-form__radio">
                                                    <input class="contact-form__radio-input" type="radio" name="inflow" value="etc">
                                                    <span class="contact-form__radio-text">기타</span>
                                                </label>
                                            </div>
                                            <?php /* 기타 label , contact-form__input--etc
                                            기타 label 과 contact-form__input--etc가 각 클릭시 서로 연동되게 하기
                                            */?>
                                            <input class="contact-form__input contact-form__input--etc form-input--focus-border" type="text" name="inflow_etc" placeholder="직접입력">
                                            <?php /* // end : 기타 label */?>
                                        </div>
                                    </li>
                                    <?php /*  문의내용 */?>
                                    <li class="contact-form__contact-message">
                                        <label for="contact_message" class="contact-form__label">문의내용<span class="text-required">*</span></label>
                                        <textarea class="contact-form__textarea form-input--focus-border" id="contact_message" name="contact_message" placeholder="문의하실 내용을 입력해주세요."></textarea>
                                    </li>
                                </ul>
                            </fieldset>
                            <?php /*  동의 + 문의버튼 */?>
                            <div class="contact-form__submit">
                                <div class="agree">
                                    <input class="agree-input" type="checkbox" id="agree_item"/>
                                    <label class="agree-label" for="agree_item">
                                        <a href="/member/privacy.php" class="agree-link" target="_blank">개인정보 수집 및 이용</a>에 동의합니다.
                                    </label>
                                </div>
                                <button class="btn-caify" type="submit">문의하기</button>
                            </div>
                            <?php /* // end : 동의 + 문의버튼 */?>
                        </div>
                    </div>
                </div>
            </section>
        </div>
        <div class="dim">
		    <div class="alert">
                <div class="alert__inner">
                    <?php /* 닫기 버튼 : start */?>
                    <?php /* 버튼 클릭시 : dim 제거 */?>
                    <?php /* dim제거 후 (확인버튼과 같은 기능) : contact 초기화 페이지 이동(새로고침침) */?>
                    <button class="alert__close" type="button">
                        <span class="ir">닫기</span>
                    </button>
                    <?php /* // end : 닫기 버튼 */?>

                    <div class="alert__title-wrap">
                        <img class="alert__icon" src="../images/common/payment_check.png" alt="payment_check">
                        <p class="alert__title">제출이 완료되었습니다</p>
                    </div>
                    <div class="alert__btn-wrap">
                        <a class="alert__btn alert__btn--primary" href="/index.php">서비스 소개 보러가기</a>
                        <?php /* 확인 버튼 : start */?>
                        <?php /* 버튼 클릭시 : dim 제거 */?>
                        <?php /* dim제거 후 (닫기버튼과 같은 기능) : contact 초기화 페이지 이동(새로고침) */?>
                        <button class="alert__btn alert__btn--ghost" type="button">확인</button>
                    </div>
                </div>
		    </div>
        </div>
    </main>
<?php include 'footer.inc.php'; ?> 
</body>
</html>