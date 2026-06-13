# Application Features & User Experience

Modernized PHP Chat is designed to be a lightweight yet powerful real-time communication tool. This document outlines the core features and the user experience from three perspectives: Regular User, Moderator, and Administrator.

## Core Chat Experience

- **Real-time Polling**: Messages appear instantly as they are sent, utilizing efficient SQLite/MariaDB/Postgres backends.
- **Rich Text Support**:
  - `*text*` -> **Bold** (Rank 4+)
  - `_text_` -> *Italic* (Rank 2+)
  - `__text__` -> <u>Underline</u> (Rank 5+)
  - `# text` -> Large Text (Rank 9)
  - `~text` -> Gradient text (Rank 6+)
  - `~~text` -> Wave animation (Rank 9)
  - `^text` -> Fire animation (Rank 6+)
  - `^^text` -> Cold breeze animation (Rank 9)
- **Image Sharing**: Upload and view GIF images (Rank 8+).
- **Replies**: Click any message to quote it in your reply. Clicking a quote scrolls back to the original message.
- **Theme Support**: Light, Dark, and System (Auto) modes.

## Progression System (Ranks)

Users gain "Rank" through activity, unlocking new features:

- **Rank 0 (Newcomer)**: Restricted to 70 character messages, 6 per minute. Forced word filtering (pronounceable only, no bad words).
- **Rank 1 (Regular)**: 2000 character messages. Access to `/promote`.
- **Rank 2 (Chatter)**: Italic text support.
- **Rank 3 (Local)**: DICE rolling (`/roll 2d6`) and Custom status (`/status`).
- **Rank 4 (Trusted)**: Custom name colors (`/color #FF0000`). Bold text.
- **Rank 5 (Veteran)**: Post clickable URLs. Underline text.
- **Rank 6 (Elite)**: Gradient and Fire text effects.
- **Rank 7 (Guardian)**: Muting lower ranks.
- **Rank 8 (Champion)**: GIF uploads and Kicking lower ranks.
- **Rank 9 (Legend)**: Big text, Wave and Cold effects.

## Administrative Dashboard

Administrators have access to a dedicated panel to manage the entire instance:
- **User Management**: Change roles (User, Moderator, Admin), adjust ranks manually, and issue permanent bans.
- **Word Filtering**: Live edit the `words.txt` dictionary used for Rank 0 filtering.
- **System Information**: Overview of environment and database status.

## Security Features

- **CSRF Protection**: All state-changing actions (sending messages, admin updates) are protected by tokens.
- **Session Management**: Secure, HttpOnly, and SameSite cookies.
- **IP Protection**: IP address data is collected for moderation but stripped from API responses to regular users.
- **Rate Limiting**: Cooldowns on commands and message frequency based on rank and role.
