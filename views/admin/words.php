<div style="padding: 20px; max-width: 800px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h1>Manage Allowed Words</h1>
        <a href="?admin" style="color: var(--accent);">Back to Dashboard</a>
    </div>
    <p style="margin-top: 10px; color: var(--text-muted);">One word per line. Used for Rank 0 filtering.</p>

    <form action="?admin=update_words" method="POST" style="margin-top: 20px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <textarea name="words" style="width: 100%; height: 500px; background: var(--bg-secondary); color: var(--text); border: 1px solid var(--border); padding: 10px; font-family: monospace;"><?= htmlspecialchars($words) ?></textarea>
        <div style="margin-top: 20px;">
            <button type="submit" class="load-more" style="width: 100%;">Save Word List</button>
        </div>
    </form>
</div>
