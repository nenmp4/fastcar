<?php /** Registro do service worker — include antes de </body>. */ ?>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/admin/sw.js', { scope: '/admin/' }).catch(function(){});
}
</script>
