<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Groups a conversation's messages into turns and stores turn_number.
 *
 * A turn starts at a real user message (text, not only tool_result) and is
 * complete once an assistant message follows.
 */
class ConversationTurnCalculator
{
    public function store(Conversation $conversation): void
    {
        $turns = $this->calculate($conversation);

        if (empty($turns)) {
            return;
        }

        DB::transaction(function () use ($turns) {
            foreach ($turns as $turnNumber => $messages) {
                $messageIds = collect($messages)->pluck('id');
                Message::whereIn('id', $messageIds)
                    ->update(['turn_number' => $turnNumber]);
            }
        });
    }

    /**
     * @return array<int, Message[]> turn_number => messages
     */
    public function calculate(Conversation $conversation): array
    {
        $messages = $conversation->messages()->orderBy('sequence')->get();
        $turns = [];
        $currentTurn = null;
        $turnNumber = 0;
        $hasResponse = false;

        foreach ($messages as $message) {
            $isRealUserMessage = $message->role === 'user'
                && $this->hasRealUserContent($message);

            if ($isRealUserMessage) {
                if ($currentTurn !== null && $hasResponse) {
                    $turns[$turnNumber] = $currentTurn;
                    $turnNumber++;
                    $currentTurn = [];
                    $hasResponse = false;
                }

                $currentTurn = $currentTurn ?? [];
                $currentTurn[] = $message;
            } elseif ($currentTurn !== null) {
                // Assistant or tool_result message
                $currentTurn[] = $message;

                if ($message->role === 'assistant') {
                    $hasResponse = true;
                }
            }
        }

        if ($currentTurn !== null && $hasResponse) {
            $turns[$turnNumber] = $currentTurn;
        }

        return $turns;
    }

    private function hasRealUserContent(Message $message): bool
    {
        $content = $message->content;

        if (!is_array($content)) {
            return is_string($content) && !empty($content);
        }

        return collect($content)
            ->contains(fn($block) => ($block['type'] ?? '') === 'text');
    }
}
