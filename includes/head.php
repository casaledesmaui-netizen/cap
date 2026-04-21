<?php
$title = ($page_title ?? 'Page') . ' | ' . APP_NAME;

// Build a root-relative path to assets that works from any subdirectory depth
$depth = substr_count(str_replace('\\','/',($_SERVER['SCRIPT_NAME'] ?? '')), '/') - 1;
$root  = str_repeat('../', max(0, $depth));
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($title); ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=plus-jakarta-sans:300,400,500,600,700|sora:400,500,600,700,800&display=swap">
<link rel="stylesheet" href="<?php echo $root; ?>assets/css/style.css">
<script>
(function () {
    var t = localStorage.getItem('theme');
    if (t === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.documentElement.setAttribute('data-bs-theme', 'dark');
    }
})();
</script>
