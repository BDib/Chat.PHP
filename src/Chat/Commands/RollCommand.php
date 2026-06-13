<?php

namespace App\Chat\Commands;

use App\Chat\ChatService;

class RollCommand extends AbstractCommand
{
    public function getName(): string { return 'roll'; }
    public function getPattern(): string { return '#^/roll (\d+)d(\d+)$#'; }
    public function getRoles(): array { return ['admin', 'moderator', 'user']; }
    public function getUsage(): string { return '/roll <N>d<S> (e.g. 2d6, 1d20)'; }

    public function execute(array $args, ChatService $chatService, object $currentUser, string $ip): void
    {
        if ($currentUser->rank < 3) {
            $this->jsonResponse(['error' => 'You need rank 3+ to roll dice.']);
        }
        $count = (int)$args[0];
        $sides = (int)$args[1];
        if ($count < 1 || $count > 10) $this->jsonResponse(['error' => 'Roll 1-10 dice.']);
        if ($sides < 2 || $sides > 100) $this->jsonResponse(['error' => 'Dice must have 2-100 sides.']);

        $rolls = [];
        for ($i = 0; $i < $count; $i++) $rolls[] = random_int(1, $sides);
        $total = array_sum($rolls);
        $detail = implode(' + ', $rolls);
        $name = $currentUser->username;
        $msg = $count > 1 ? "{$name} rolled {$count}d{$sides}: {$detail} = {$total}" : "{$name} rolled d{$sides}: {$total}";
        $chatService->systemMessage($msg, $currentUser->username, $ip);
    }
}
