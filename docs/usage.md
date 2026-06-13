# Usage Tutorial

This guide walks you through using Modernized PHP Chat as a regular user and managing it as an administrator.

## For Regular Users

### 1. Registration & Security
When you first visit the site, you'll see the Login/Register screen.
- Usernames must be 5-9 lowercase letters and pronounceable.
- **Password Strength**: Your password must be at least 8 characters long and contain uppercase, lowercase, and numbers.
- Once registered, you start at **Rank 0**.

### 2. Chatting
Simply type in the bottom box and press Enter or click "Send".
- At Rank 0, your messages are limited to 70 characters and filtered for quality.
- Click on a message to **Reply** to it.
- Your status and name color can be managed in the **Settings** page (top right).

### 3. Unlocking Features
Your Rank determines what you can do:
- **Rank 2**: Italics (`_text_`).
- **Rank 4**: Bold (`*text*`) and custom colors.
- **Rank 6**: Fire (`^text`) and Gradient (`~text`) effects.
- **Rank 9**: Big text (`# text`) and Wave (`~~text`) effects.

## For Administrators

### 1. Accessing the Dashboard
If your user has the `admin` role, an "admin" link will appear in the chat header.

### 2. Managing Users
In the **Manage Users** dashboard:
- **Roles**: Upgrade trusted users to `moderator` or `admin`.
- **Ranks**: Instantly change a user's rank to grant or revoke feature access.
- **Moderation**: Ban problematic users with a single click.

### 3. Global Configuration
- **Word List**: Edit the `words.txt` dictionary directly from the dashboard to fine-tune what Rank 0 users can say.
- **Environment**: Use the `.env` file to switch languages (10 supported) or change the database backend.

### 4. Admin Commands
Type these in the chat box:
- `/ip <username>`: Check for alt-accounts and IP history.
- `/ipban <username>`: Ban a user's entire IP range.
- `/world <effect>`: Trigger a world-wide effect (shake, disco, rain, matrix).
- `/sys <msg>`: Send a global system notification.
