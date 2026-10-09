<?php
/** Shared authenticated-app layout close. */
use Verity\Support\Security;
$NONCE = $NONCE ?? '';
?>
</main>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<script src="/assets/confirm.js" nonce="<?= Security::h($NONCE) ?>"></script>
<?php if (!empty($appScript)): ?>
<script src="<?= Security::h($appScript) ?>" nonce="<?= Security::h($NONCE) ?>"></script>
<?php endif; ?>
</body>
</html>
