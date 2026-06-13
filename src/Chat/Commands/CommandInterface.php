<?php

namespace App\Chat\Commands;

use App\Chat\ChatService;

interface CommandInterface
{
    public function getName(): string;
    public function getPattern(): string;
    public function getRoles(): array;
    public function getUsage(): string;
    public function execute(array $args, ChatService $chatService, object $currentUser, string $ip): void;
}
