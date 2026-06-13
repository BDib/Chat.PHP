<div style="padding: 20px; max-width: 1000px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h1>Manage Users</h1>
        <a href="?admin" style="color: var(--accent);">Back to Dashboard</a>
    </div>

    <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
        <thead>
            <tr style="text-align: left; border-bottom: 2px solid var(--border);">
                <th style="padding: 10px;">Username</th>
                <th style="padding: 10px;">Role</th>
                <th style="padding: 10px;">Rank</th>
                <th style="padding: 10px;">Banned</th>
                <th style="padding: 10px;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
            <tr style="border-bottom: 1px solid var(--border);">
                <td style="padding: 10px;"><?= htmlspecialchars($user['username']) ?></td>
                <form action="?admin=update_user" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= $user['id'] ?>">
                    <td style="padding: 10px;">
                        <select name="role" style="background: var(--bg-secondary); color: var(--text); border: 1px solid var(--border);">
                            <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                            <option value="moderator" <?= $user['role'] === 'moderator' ? 'selected' : '' ?>>Moderator</option>
                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </td>
                    <td style="padding: 10px;">
                        <input type="number" name="rank" value="<?= $user['rank'] ?>" min="0" max="9" style="width: 50px; background: var(--bg-secondary); color: var(--text); border: 1px solid var(--border);">
                    </td>
                    <td style="padding: 10px;">
                        <input type="checkbox" name="banned" <?= $user['banned'] ? 'checked' : '' ?>>
                    </td>
                    <td style="padding: 10px;">
                        <button type="submit" style="padding: 4px 10px; background: var(--accent); color: white; border: none; border-radius: 4px; cursor: pointer;">Save</button>
                    </td>
                </form>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
