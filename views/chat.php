<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        Online
        <button class="close-sidebar" onclick="toggleSidebar()">
            <svg class="icon">
                <use href="#icon-x"/>
            </svg>
        </button>
    </div>
    <div class="users"></div>
</aside>
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<!-- Main chat -->
<main class="main">
    <div class="chat-header">
        <button class="open-sidebar" onclick="toggleSidebar()">
            <svg class="icon">
                <use href="#icon-menu"/>
            </svg>
        </button>
        <span><?= htmlspecialchars($title) ?></span>
        <span style="flex:1"></span>
        <button class="theme-toggle" onclick="cycleTheme()" title="Toggle theme">
            <svg class="icon">
                <use href="#icon-sun"/>
            </svg>
        </button>
        <span style="font-size:13px;font-weight:400;color:var(--text-muted)"><?= htmlspecialchars($currentUser->username) ?></span>
        <a href="?logout" style="font-size:13px">logout</a>
    </div>

    <div class="messages" id="messages">
        <button class="load-more" id="load-more">Load older messages</button>
    </div>
    <div class="new-messages-badge" id="new-messages-badge"></div>

    <div class="reply-preview" id="reply-preview">
        <span class="reply-preview-text"></span>
        <button class="reply-preview-close" onclick="cancelReply()">
            <svg class="icon">
                <use href="#icon-x"/>
            </svg>
        </button>
    </div>
    <form class="chat-form" id="chat-form">
        <input type="file" id="file-input" accept="image/gif" style="display:none">
        <input type="text" name="message" placeholder="Type a message..." autocomplete="off"
               maxlength="<?= $currentUser->rank === 0 ? 70 : 2000 ?>">
        <button type="button" class="chat-upload"
                onclick="document.getElementById('file-input').click()"<?php if ($currentUser->rank < 8): ?> style="display:none"<?php endif; ?>>
            <svg class="icon">
                <use href="#icon-image"/>
            </svg>
        </button>
        <button type="submit">Send</button>
    </form>
</main>

<script>
    const CONFIG = {
        myUsername: <?= json_encode($currentUser->username) ?>,
        csrfToken: <?= json_encode($csrfToken) ?>
    };
</script>
<script src="assets/js/app.js"></script>
