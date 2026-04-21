<?php
// head.php — Standard <head> block included on every admin page.
// The $page_title variable should be set in each page before including this.
$title = ($page_title ?? 'Page') . ' | ' . APP_NAME;
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $title; ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=plus-jakarta-sans:300,400,500,600,700|sora:400,500,600,700,800&display=swap">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
<script>
(function () {
    var t = localStorage.getItem('theme');
    if (t === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.documentElement.setAttribute('data-bs-theme', 'dark');
    }
})();
</script>
