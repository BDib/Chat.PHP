# Usage Tutorial

This guide walks you through using Modernized PHP Chat as a regular user and managing it as an administrator.

## For Regular Users

### 1. Registration
When you first visit the site, you'll see the Login/Register screen.
- Usernames must be 5-9 lowercase letters.
- Usernames must be "pronounceable" (the system blocks gibberish like `asdfgh`).
- Once registered, you start at **Rank 0**.

### 2. Chatting
Simply type in the bottom box and press Enter or click "Send".
- At Rank 0, your messages are limited to 70 characters.
- If you use common bad words or gibberish, they will be filtered.
- Click on a message to **Reply** to it.

### 3. Unlocking Features
The more you chat, the more your Rank increases (managed by Admins/Moderators).
- **Rank 2**: Use `_text_` for italics.
- **Rank 3**: Use `/roll 2d20` to roll dice. Use `/status happy` to set a sidebar status.
- **Rank 4**: Use `/color #FF5500` to change your name color.
- **Rank 8**: Click the image icon to upload a GIF.

## For Administrators

### 1. Accessing the Dashboard
If your user has the `admin` role, an "admin" link will appear in the top right of the chat header. Clicking this takes you to `?admin`.

### 2. Managing Users
Go to the **Manage Users** section.
- **Role**: Promote a user to `moderator` to give them kick/mute powers, or `admin` for full dashboard access.
- **Rank**: Manually set a user's rank (0-9) to unlock specific features for them.
- **Banned**: Check this box and save to permanently ban a user.

### 3. Word List Configuration
The **Manage Allowed Words** section allows you to edit the dictionary used for Rank 0 users.
- This is a plain text list of valid words.
- Suffixes (like -ing, -ed, -s) are handled automatically by the code, so you only need to add the base stems.

### 4. Chat Commands
Admins have access to powerful commands in the chat box:
- `/ip <username>`: View all IP addresses used by a user and find potential alt-accounts.
- `/ipban <username>`: Ban all IPs associated with a user.
- `/world shake`: Trigger a screen-shake effect for all online users.
- `/sys <message>`: Send a system-wide broadcast message.
