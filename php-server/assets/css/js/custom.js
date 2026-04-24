/*********************************************************
  아코디언
 *********************************************************/

document.addEventListener("DOMContentLoaded", () => {
    const dropdownMenus = document.querySelectorAll(".lcp-accordion__toggle");

    
    const urlParams = new URLSearchParams(window.location.search);
    const faqId = urlParams.get('id');
    const isFaqPageWithId = window.location.pathname.includes('/board/faq.php') && faqId && faqId !== '0' && faqId !== '';

    
    if (dropdownMenus.length > 0 && !isFaqPageWithId) {
        const firstToggle = dropdownMenus[0];
        const firstDropdown = firstToggle.parentElement;
        const firstContent = firstDropdown.querySelector(".lcp-accordion__content");
        const firstIcon = firstToggle.querySelector(".lcp-accordion__icon");

        firstDropdown.classList.add("active");
        firstContent.style.maxHeight = firstContent.scrollHeight + "px";
        firstIcon.style.transform = "translateY(-50%) rotate(180deg)";
    }

    dropdownMenus.forEach(menu => {
        menu.addEventListener("click", () => {
            const dropdown = menu.parentElement;
            const content = dropdown.querySelector(".lcp-accordion__content");
            const icon = menu.querySelector(".lcp-accordion__icon");

            // 다른 활성화된 드롭다운 닫기
            document.querySelectorAll(".lcp-accordion").forEach(otherDropdown => {
                if (otherDropdown !== dropdown) {
                    otherDropdown.classList.remove("active");
                    const otherContent = otherDropdown.querySelector(".lcp-accordion__content");
                    const otherIcon = otherDropdown.querySelector(".lcp-accordion__icon");
                    if (otherContent) otherContent.style.maxHeight = null;
                    if (otherIcon) otherIcon.style.transform = null; // 아이콘 초기화
                }
            });

            // 현재 드롭다운 토글
            if (dropdown.classList.contains("active")) {
                dropdown.classList.remove("active");
                content.style.maxHeight = null;
                icon.style.transform = null; // 아이콘 초기화
            } else {
                dropdown.classList.add("active");
                content.style.maxHeight = content.scrollHeight + "px"; // 내용 높이에 맞게 설정
                icon.style.transform = "translateY(-50%) rotate(180deg)"; // 화살표 회전
            }
        });
    });
});

//클릭시 active 클래스 추가
const toggleActiveElements = document.querySelectorAll(".toggle__active");

toggleActiveElements.forEach((element) => {
    element.addEventListener("click", () => {
        element.classList.toggle("active");
    });
});
