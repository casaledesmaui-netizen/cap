<?php
$title = ($page_title ?? 'Page') . ' | ' . APP_NAME;
?>
<meta charset="UTF-8">
<meta http-equiv="Cache-Control" content="max-age=3600">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $title; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Sora:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/bootstrap.min.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/bootstrap-icons.css">
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
