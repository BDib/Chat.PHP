<div class="auth">
    <div class="auth-box">
        <div class="auth-tabs">
            <button class="auth-tab active" onclick="switchTab('login')" type="button"><?= __('login') ?></button>
            <button class="auth-tab" onclick="switchTab('register')" type="button"><?= __('register') ?></button>
        </div>
        <?php if ($authError): ?>
            <div class="auth-error"><?= htmlspecialchars($authError) ?></div>
        <?php endif; ?>
        <form class="auth-panel active" id="tab-login" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <div class="field">
                <label for="login-username"><?= __('username') ?></label>
                <input type="text" id="login-username" name="username" maxlength="32" required autofocus
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="login-password"><?= __('password') ?></label>
                <input type="password" id="login-password" name="password" required>
            </div>
            <button type="submit" name="action" value="login"><?= __('login') ?></button>
        </form>
        <form class="auth-panel" id="tab-register" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <div class="field">
                <label for="reg-username"><?= __('username') ?></label>
                <input type="text" id="reg-username" name="username" minlength="5" maxlength="9" pattern="[a-z]+"
                       title="Letters only, no numbers" required
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="reg-password"><?= __('password') ?></label>
                <input type="password" id="reg-password" name="password" required minlength="6">
            </div>
            <button type="submit" name="action" value="register"><?= __('register') ?></button>
        </form>
    </div>
</div>
<script>
    function switchTab(tab) {
        document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'))
        document.querySelectorAll('.auth-panel').forEach(p => p.classList.remove('active'))
        document.querySelector('#tab-' + tab).classList.add('active')
        document.querySelectorAll('.auth-tab').forEach(t => {
            if (t.textContent.toLowerCase() === tab) t.classList.add('active')
        })
    }
    <?php if (($_POST['action'] ?? '') === 'register'): ?>
    switchTab('register')
    <?php endif; ?>
</script>
