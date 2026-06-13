<?php

namespace App\Chat\Commands;

use App\Chat\ChatService;

class HelpCommand extends AbstractCommand
{
    public function getName(): string { return 'help'; }
    public function getPattern(): string { return '#^/help$#'; }
    public function getRoles(): array { return ['admin', 'moderator', 'user']; }
    public function getUsage(): string { return '/help'; }

    public function execute(array $args, ChatService $chatService, object $currentUser, string $ip): void
    {
        $rank = $currentUser->rank;
        $this->jsonResponse(['error' => <<<HELP
        You are rank {$rank}.

        As you chat, you earn rank and unlock new abilities:

        Rank 0 — Newcomer
        Send short messages (70 chars), 6 per minute.

        Rank 1 — Regular
        Longer messages (2000 chars), 10 per minute.
        /promote <user> — promote someone (up to your rank).

        Rank 2 — Chatter
         Style your messages: start with _ for italic.
        20 messages per minute.

        Rank 3 — Local
        /status <word> — show a custom status in the sidebar.
        /roll <N>d<S> — roll dice (e.g. 2d6, 1d20).

        Rank 4 — Trusted
        /color <#RRGGBB|reset> — pick a name color.
        Start with * for bold.

        Rank 5 — Veteran
        Post URLs freely.
        Start with __ for underline.

        Rank 6 — Elite
        Start with ~ for rainbow gradient text.
        Start with ^ for animated fire text.

        Rank 7 — Guardian
        /mute <user> <time> — silence lower ranks (max 5m).
        /unmute <user> — remove a mute.

        Rank 8 — Champion
        Upload GIF images.
        /kick <user> <time> — kick lower ranks (max 10m).
        /unkick <user> — remove a kick.

        Rank 9 — Legend
        Start a message with # to make it big and bold.
        Start with ~~ for wave text.
        Start with ^^ for cold breeze text.
        HELP]);
    }
}
