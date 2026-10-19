<?php

/**
 * Reads Claude Code's own (native) sub-agents: the Agent/Task tool, not `pd subagent:run`.
 * Claude Code writes them to ~/.claude/projects/<project>/<session>/subagents/agent-<id>.jsonl
 * (+ .meta.json). Those files are only readable by appuser, so this plain PHP script (no
 * framework, fast) runs as appuser; NativeSubAgentMonitor calls it directly or via
 * `docker exec -u appuser <queue>`.
 *
 *   php native-subagents.php summary <claudeSessionId>...
 *   php native-subagents.php events <claudeSessionId> <agentId> <fromLine>
 *
 * Output: JSON on stdout.
 */

$home = getenv('HOME') ?: '/home/appuser';
$mode = $argv[1] ?? '';

function agentDir(string $home, string $sessionId): ?string
{
    if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $sessionId)) {
        return null;
    }
    $dirs = glob($home . '/.claude/projects/*/' . $sessionId . '/subagents', GLOB_ONLYDIR) ?: [];

    return $dirs[0] ?? null;
}

/** Last $bytes of a file as complete JSONL entries. */
function tailEntries(string $file, int $bytes = 262144): array
{
    $size = filesize($file);
    $fh = fopen($file, 'r');
    if ($size > $bytes) {
        fseek($fh, $size - $bytes);
        fgets($fh); // drop the partial first line
    }
    $entries = [];
    while (($line = fgets($fh)) !== false) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $entries[] = $decoded;
        }
    }
    fclose($fh);

    return $entries;
}

function toolLabel(string $name, array $input): string
{
    $arg = $input['file_path'] ?? $input['path'] ?? $input['notebook_path'] ?? null;
    $arg = $arg ? basename((string) $arg)
        : ($input['command'] ?? $input['pattern'] ?? $input['url'] ?? $input['query'] ?? $input['description'] ?? '');
    $arg = trim(preg_replace('/\s+/', ' ', (string) $arg));
    if (mb_strlen($arg) > 70) {
        $arg = mb_substr($arg, 0, 69) . '…';
    }

    return $arg === '' ? $name : "{$name} · {$arg}";
}

function compactResult($content): string
{
    if (is_array($content)) {
        $parts = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                $parts[] = (string) $block;
            } elseif (($block['type'] ?? '') === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            } elseif (($block['type'] ?? '') === 'image') {
                $parts[] = '[afbeelding]';
            } else {
                $parts[] = json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        $content = implode("\n", $parts);
    }
    $content = (string) $content;

    return mb_strlen($content) > 4000 ? mb_substr($content, 0, 4000) . "\n… (" . number_format(mb_strlen($content)) . ' tekens)' : $content;
}

/** Finished = last assistant entry ended the turn and only attachments follow. */
function isFinished(array $entries): bool
{
    for ($i = count($entries) - 1; $i >= 0; $i--) {
        $type = $entries[$i]['type'] ?? '';
        if ($type === 'attachment' || $type === 'system') {
            continue;
        }
        if ($type === 'assistant') {
            return in_array($entries[$i]['message']['stop_reason'] ?? null, ['end_turn', 'stop_sequence'], true);
        }

        return false;
    }

    return false;
}

if ($mode === 'summary') {
    $out = [];
    foreach (array_slice($argv, 2) as $sessionId) {
        $dir = agentDir($home, $sessionId);
        if ($dir === null) {
            continue;
        }
        foreach (glob($dir . '/agent-*.meta.json') ?: [] as $metaFile) {
            $agentId = substr(basename($metaFile, '.meta.json'), strlen('agent-'));
            $jsonl = $dir . '/agent-' . $agentId . '.jsonl';
            if (!is_file($jsonl)) {
                continue;
            }
            $meta = json_decode((string) file_get_contents($metaFile), true) ?: [];
            $entries = tailEntries($jsonl);

            // first timestamp from the head of the file
            $fh = fopen($jsonl, 'r');
            $first = json_decode((string) fgets($fh), true) ?: [];
            fclose($fh);

            $steps = [];
            for ($i = count($entries) - 1; $i >= 0 && count($steps) < 5; $i--) {
                $entry = $entries[$i];
                if (($entry['type'] ?? '') !== 'assistant' || !is_array($entry['message']['content'] ?? null)) {
                    continue;
                }
                foreach (array_reverse($entry['message']['content']) as $block) {
                    if (($block['type'] ?? '') === 'tool_use') {
                        $steps[] = toolLabel((string) ($block['name'] ?? 'Tool'), (array) ($block['input'] ?? []));
                    }
                }
            }

            $last = end($entries) ?: [];
            $out[] = [
                'session_id' => $sessionId,
                'agent_id' => $agentId,
                'description' => (string) ($meta['description'] ?? ''),
                'agent_type' => (string) ($meta['agentType'] ?? 'general-purpose'),
                'background' => ($meta['requestShape'] ?? '') === 'background',
                'started_at' => $first['timestamp'] ?? null,
                'last_at' => $last['timestamp'] ?? date('c', filemtime($jsonl)),
                'modified_at' => date('c', filemtime($jsonl)),
                'finished' => isFinished($entries),
                'steps' => array_slice($steps, 0, 5),
            ];
        }
    }
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

if ($mode === 'events') {
    $sessionId = $argv[2] ?? '';
    $agentId = $argv[3] ?? '';
    $from = max(0, (int) ($argv[4] ?? 0));
    $dir = agentDir($home, $sessionId);
    $jsonl = $dir && preg_match('/^[a-z0-9]{4,40}$/', $agentId) ? $dir . '/agent-' . $agentId . '.jsonl' : null;
    if (!$jsonl || !is_file($jsonl)) {
        echo json_encode(['events' => [], 'next' => $from, 'finished' => true, 'missing' => true]);
        exit(0);
    }

    $events = [];
    $line = 0;
    $all = [];
    $fh = fopen($jsonl, 'r');
    while (($raw = fgets($fh)) !== false) {
        $entry = json_decode($raw, true);
        if (!is_array($entry)) {
            continue;
        }
        $all[] = $entry;
        if ($line++ < $from) {
            continue;
        }
        $content = $entry['message']['content'] ?? null;
        $type = $entry['type'] ?? '';
        $bi = 'n' . $line;

        if ($type === 'user') {
            if (is_string($content)) {
                $events[] = ['type' => 'user', 'content' => mb_substr($content, 0, 20000)];
                continue;
            }
            foreach ((array) $content as $block) {
                if (($block['type'] ?? '') === 'tool_result') {
                    $events[] = [
                        'type' => 'tool_result',
                        'content' => compactResult($block['content'] ?? ''),
                        'metadata' => ['tool_id' => $block['tool_use_id'] ?? null, 'is_error' => (bool) ($block['is_error'] ?? false)],
                    ];
                } elseif (($block['type'] ?? '') === 'text') {
                    $events[] = ['type' => 'user', 'content' => mb_substr((string) ($block['text'] ?? ''), 0, 20000)];
                }
            }
        } elseif ($type === 'assistant' && is_array($content)) {
            foreach ($content as $i => $block) {
                $b = $bi . '-' . $i;
                $blockType = $block['type'] ?? '';
                if ($blockType === 'thinking' && trim((string) ($block['thinking'] ?? '')) !== '') {
                    array_push($events, ['type' => 'thinking_start', 'block_index' => $b], ['type' => 'thinking_delta', 'block_index' => $b, 'content' => (string) $block['thinking']], ['type' => 'thinking_stop', 'block_index' => $b]);
                } elseif ($blockType === 'text' && trim((string) ($block['text'] ?? '')) !== '') {
                    array_push($events, ['type' => 'text_start', 'block_index' => $b], ['type' => 'text_delta', 'block_index' => $b, 'content' => (string) $block['text']], ['type' => 'text_stop', 'block_index' => $b]);
                } elseif ($blockType === 'tool_use') {
                    array_push(
                        $events,
                        ['type' => 'tool_use_start', 'block_index' => $b, 'metadata' => ['tool_id' => $block['id'] ?? null, 'tool_name' => $block['name'] ?? 'Tool']],
                        ['type' => 'tool_use_delta', 'block_index' => $b, 'content' => json_encode($block['input'] ?? new stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                        ['type' => 'tool_use_stop', 'block_index' => $b]
                    );
                }
            }
        }
    }
    fclose($fh);

    echo json_encode([
        'events' => $events,
        'next' => $line,
        'finished' => isFinished(array_slice($all, -50)),
        'started_at' => $all[0]['timestamp'] ?? null,
        'last_at' => end($all)['timestamp'] ?? null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit(0);
}

fwrite(STDERR, "usage: summary <sid>... | events <sid> <agentId> <from>\n");
exit(1);
