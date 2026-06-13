<div style="padding: 20px; max-width: 600px; margin: 0 auto;">
    <h1>Profile Settings</h1>
    <p>Username: <strong><?= htmlspecialchars($currentUser->username) ?></strong> (Rank <?= $currentUser->rank ?>)</p>

    <form action="?profile=update" method="POST" style="margin-top: 20px; display: flex; flex-direction: column; gap: 15px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div>
            <label style="display: block; font-weight: 600;">Change Password</label>
            <input type="password" name="password" minlength="6" placeholder="New password (leave blank to keep current)" style="width: 100%; padding: 8px; background: var(--bg-secondary); border: 1px solid var(--border); color: var(--text);">
        </div>

        <?php if ($currentUser->rank >= 4): ?>
        <div>
            <label style="display: block; font-weight: 600;">Name Color</label>
            <input type="text" name="color" value="<?= htmlspecialchars($currentUser->color ?? '') ?>" placeholder="#RRGGBB or 'reset'" style="width: 100%; padding: 8px; background: var(--bg-secondary); border: 1px solid var(--border); color: var(--text);">
        </div>
        <?php endif; ?>

        <?php if ($currentUser->rank >= 3): ?>
        <div>
            <label style="display: block; font-weight: 600;">Status Word</label>
            <input type="text" name="status" value="<?= htmlspecialchars($currentUser->status ?? '') ?>" placeholder="Single lowercase word or 'reset'" style="width: 100%; padding: 8px; background: var(--bg-secondary); border: 1px solid var(--border); color: var(--text);">
        </div>
        <?php endif; ?>

        <div style="margin-top: 10px; display: flex; gap: 10px;">
            <button type="submit" class="load-more" style="flex: 1;">Save Changes</button>
            <a href="./" class="load-more" style="text-decoration: none; background: #888; flex: 1; text-align: center; line-height: 2;">Back to Chat</a>
        </div>
    </form>
</div>
