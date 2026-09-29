  </div><!-- /.page-content -->
</div><!-- /.main-content -->

<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
// Prevent double-submission on forms marked with data-once="true"
// Also auto-applies to any form containing a button[data-once]
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form[data-once="true"]').forEach(function(form) {
        form.addEventListener('submit', function() {
            var btn = form.querySelector('button[type="submit"], input[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.textContent = btn.dataset.sending || 'Sending…';
            }
        });
    });
});
</script>
</body>
</html>
