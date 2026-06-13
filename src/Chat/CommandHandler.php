<?php

namespace App\Chat;

use App\Config;
use App\Database;
use App\Chat\Commands\CommandInterface;
use App\Chat\Commands\RollCommand;
use App\Chat\Commands\HelpCommand;
use App\Chat\Commands\InfoCommand;
use App\Chat\Commands\ModCommand;
use App\Chat\Commands\BanCommand;
use App\Chat\Commands\UnbanCommand;
use App\Chat\Commands\KickCommand;
use App\Chat\Commands\MuteCommand;
use App\Chat\Commands\IpCommand;
use App\Chat\Commands\WorldCommand;
use App\Chat\Commands\SysCommand;
use App\Chat\Commands\PromoteCommand;

class CommandHandler
{
    /** @var CommandInterface[] */
    private array $commands = [];

    public function __construct(
        private ChatService $chatService,
        private object $currentUser,
        private string $ip
    ) {
        $this->registerCommands();
    }

    private function registerCommands(): void
    {
        $this->commands[] = new HelpCommand();
        $this->commands[] = new RollCommand();
        $this->commands[] = new InfoCommand();
        $this->commands[] = new ModCommand();
        $this->commands[] = new BanCommand();
        $this->commands[] = new UnbanCommand();
        $this->commands[] = new KickCommand();
        $this->commands[] = new MuteCommand();
        $this->commands[] = new IpCommand();
        $this->commands[] = new WorldCommand();
        $this->commands[] = new SysCommand();
        $this->commands[] = new PromoteCommand();
    }

    public function handle(string $text): bool
    {
        if ($text === '' || $text[0] !== '/') return false;

        $parts = explode(' ', substr($text, 1));
        $cmdName = $parts[0];

        foreach ($this->commands as $command) {
            if ($command->getName() === $cmdName) {
                if (!in_array($this->currentUser->role, $command->getRoles(), true)) {
                    $this->jsonResponse(['error' => 'Permission denied']);
                }

                if (preg_match($command->getPattern(), $text, $m)) {
                    $args = array_slice($m, 1);
                    $command->execute($args, $this->chatService, $this->currentUser, $this->ip);
                    return true;
                }
                $this->jsonResponse(['error' => "Usage: {$command->getUsage()}"]);
            }
        }

        $available = [];
        foreach ($this->commands as $command) {
            if (in_array($this->currentUser->role, $command->getRoles(), true)) {
                $available[] = $command->getUsage();
            }
        }
        $list = $available ? "Unknown command. Available:\n" . implode("\n", $available) : 'No commands available.';
        $this->jsonResponse(['error' => $list]);
        return true;
    }

    private function jsonResponse(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
