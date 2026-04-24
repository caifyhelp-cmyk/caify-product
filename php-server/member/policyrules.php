<?php 
   $currentPage = 'bg_page';
   include '../header.inc.php';
?>
<main class="main__policy">
     <h3 class="policy__title">이용약관</h3>
     <ul class="policy__tab">
        <li class="active"><a href="/member/policyrules.php">이용약관</a></li>
        <li><a href="/member/privacy.php">개인정보처리방침</a></li>
        <li><a href="/member/marketing_reception.php">마케팅 정보 수신 및 홍보 활용 동의</a></li>
        <li><a href="/member/policyrefund.php">환불 및 청약철회 안내</a></li>
     </ul>        
     <?php include 'policyrules.inc.php'; ?>   
</main>
<?php include '../footer.inc.php'; ?> 
</body>
</html>