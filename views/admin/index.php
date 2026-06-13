<div style="padding: 20px; max-width: 800px; margin: 0 auto;">
    <h1>Admin Dashboard</h1>
    <p>Welcome, <?= htmlspecialchars($currentUser->username) ?>.</p>

    <div style="display: flex; gap: 20px; margin-top: 20px;">
        <a href="?admin=users" class="load-more" style="text-decoration: none;">Manage Users</a>
        <a href="?admin=words" class="load-more" style="text-decoration: none;">Manage Allowed Words</a>
        <a href="./" class="load-more" style="text-decoration: none; background: #888;">Back to Chat</a>
    </div>

    <div style="margin-top: 40px; padding: 20px; background: var(--bg-secondary); border-radius: 8px;">
        <h3>System Info</h3>
        <p>PHP Version: <?= PHP_VERSION ?></p>
        <p>Database Driver: <?= \App\Config::get('DB_DRIVER', 'sqlite') ?></p>
    </div>
</div>
