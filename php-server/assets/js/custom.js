/*********************************************************
  아코디언
 *********************************************************/

document.addEventListener("DOMContentLoaded", () => {
    const dropdownMenus = document.querySelectorAll(".lcp-accordion__toggle");

    dropdownMenus.forEach(menu => {
        menu.addEventListener("click", () => {
            const dropdown = menu.parentElement;
            const content = dropdown.querySelector(".lcp-accordion__content");

            // 다른 활성화된 드롭다운 닫기
            document.querySelectorAll(".lcp-accordion").forEach(otherDropdown => {
                if (otherDropdown !== dropdown) {
                    otherDropdown.classList.remove("active");
                    const otherContent = otherDropdown.querySelector(".lcp-accordion__content");
                    if (otherContent) otherContent.style.maxHeight = null;
                }
            });

            // 현재 드롭다운 토글
            if (dropdown.classList.contains("active")) {
                dropdown.classList.remove("active");
                content.style.maxHeight = null;
            } else {
                dropdown.classList.add("active");
                content.style.maxHeight = content.scrollHeight + "px"; // 내용 높이에 맞게 설정
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
