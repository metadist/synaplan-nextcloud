<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\IConfig;

/**
 * Research Chat threads for one Nextcloud user. The screen used to keep them
 * in the browser only, so leaving the page forgot the dialogue.
 */
class ResearchHistory
{
    public const PREF = 'research_chats';

    private const MAX_CHATS = 12;

    private const MAX_MESSAGES = 40;

    private const MAX_TEXT = 4000;

    private const MAX_BYTES = 350000;

    public function __construct(
        private IConfig $config,
    ) {
    }

    /**
     * @return list<array{id: string, title: string, updated: int}>
     */
    public function list(string $uid): array
    {
        $out = [];
        foreach ($this->load($uid) as $chat) {
            $out[] = [
                'id' => $chat['id'],
                'title' => $chat['title'],
                'updated' => $chat['updated'],
            ];
        }

        return $out;
    }

    /**
     * @return array{id: string, title: string, updated: int, messages: list<array{role: string, text: string, model?: string}>}|null
     */
    public function open(string $uid, string $id): ?array
    {
        foreach ($this->load($uid) as $chat) {
            if ($chat['id'] === $id) {
                return $chat;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return array{id: string, title: string, updated: int}
     */
    public function save(string $uid, string $id, array $messages): array
    {
        $clean = $this->cleanMessages($messages);
        $title = $this->titleFrom($clean);
        $chat = [
            'id' => $id,
            'title' => $title,
            'updated' => time(),
            'messages' => $clean,
        ];

        $chats = [];
        foreach ($this->load($uid) as $existing) {
            if ($existing['id'] !== $id) {
                $chats[] = $existing;
            }
        }
        array_unshift($chats, $chat);
        $chats = array_slice($chats, 0, self::MAX_CHATS);
        $this->store($uid, $this->fit($chats, $id));

        return [
            'id' => $chat['id'],
            'title' => $chat['title'],
            'updated' => $chat['updated'],
        ];
    }

    public function remove(string $uid, string $id): void
    {
        $chats = [];
        foreach ($this->load($uid) as $chat) {
            if ($chat['id'] !== $id) {
                $chats[] = $chat;
            }
        }
        $this->store($uid, $chats);
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9]{8,32}$/', $id) === 1;
    }

    /**
     * @return list<array{id: string, title: string, updated: int, messages: list<array{role: string, text: string, model?: string}>}>
     */
    private function load(string $uid): array
    {
        $raw = $this->config->getUserValue($uid, Application::APP_ID, self::PREF, '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        $chats = [];
        foreach ($decoded as $chat) {
            if (!is_array($chat) || !is_string($chat['id'] ?? null) || !self::validId($chat['id'])) {
                continue;
            }
            $messages = $this->cleanMessages(is_array($chat['messages'] ?? null) ? $chat['messages'] : []);
            $chats[] = [
                'id' => $chat['id'],
                'title' => $this->short(is_string($chat['title'] ?? null) && $chat['title'] !== '' ? $chat['title'] : $this->titleFrom($messages)),
                'updated' => is_int($chat['updated'] ?? null) ? $chat['updated'] : 0,
                'messages' => $messages,
            ];
        }

        usort($chats, static fn (array $a, array $b): int => $b['updated'] <=> $a['updated']);

        return $chats;
    }

    /**
     * @param list<array{id: string, title: string, updated: int, messages: list<array{role: string, text: string, model?: string}>}> $chats
     */
    private function store(string $uid, array $chats): void
    {
        $this->config->setUserValue(
            $uid,
            Application::APP_ID,
            self::PREF,
            $chats === [] ? '' : json_encode($chats, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Drop the oldest threads until the stored JSON fits. The thread just
     * written stays.
     *
     * @param list<array{id: string, title: string, updated: int, messages: list<array{role: string, text: string, model?: string}>}> $chats
     *
     * @return list<array{id: string, title: string, updated: int, messages: list<array{role: string, text: string, model?: string}>}>
     */
    private function fit(array $chats, string $keepId): array
    {
        while (count($chats) > 1 && strlen($this->encode($chats)) > self::MAX_BYTES) {
            $drop = count($chats) - 1;
            if ($chats[$drop]['id'] === $keepId) {
                $drop = 0;
            }
            array_splice($chats, $drop, 1);
        }

        return $chats;
    }

    /**
     * @param list<array{id: string, title: string, updated: int, messages: list<array{role: string, text: string, model?: string}>}> $chats
     */
    private function encode(array $chats): string
    {
        return json_encode($chats, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<mixed> $messages
     *
     * @return list<array{role: string, text: string, model?: string}>
     */
    private function cleanMessages(array $messages): array
    {
        $clean = [];
        foreach (array_slice($messages, -self::MAX_MESSAGES) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = $message['role'] ?? '';
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            $text = $this->short(trim((string) ($message['text'] ?? '')), self::MAX_TEXT);
            if ($text === '') {
                continue;
            }
            $row = ['role' => $role, 'text' => $text];
            $model = trim((string) ($message['model'] ?? ''));
            if ($role === 'assistant' && $model !== '') {
                $row['model'] = $this->short($model, 120);
            }
            $clean[] = $row;
        }

        return $clean;
    }

    /**
     * @param list<array{role: string, text: string, model?: string}> $messages
     */
    private function titleFrom(array $messages): string
    {
        foreach ($messages as $message) {
            if ($message['text'] !== '') {
                return $this->short($message['text'], 80);
            }
        }

        return '';
    }

    private function short(string $text, int $max = 80): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
}
