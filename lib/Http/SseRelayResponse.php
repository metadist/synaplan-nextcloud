<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * Relays a Server-Sent Events (SSE) stream from an upstream resource straight
 * to the client, flushing each chunk as it arrives.
 *
 * Used to proxy Synaplan's OpenAI-compatible streaming chat endpoint so chat
 * answers appear token-by-token instead of after one long blocking request.
 *
 * @template-extends Response<int, array<string, mixed>>
 */
class SseRelayResponse extends Response implements ICallbackResponse
{
    /** @var resource */
    private $upstream;

    /**
     * @param resource $upstream Readable stream of the upstream SSE body
     */
    public function __construct($upstream)
    {
        parent::__construct();

        $this->upstream = $upstream;
        $this->setStatus(Http::STATUS_OK);
        $this->addHeader('Content-Type', 'text/event-stream; charset=utf-8');
        $this->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        // Tell intermediary proxies (nginx ingress / FrankenPHP) not to buffer.
        $this->addHeader('X-Accel-Buffering', 'no');
    }

    public function callback(IOutput $output): void
    {
        // Drop any active output buffering so chunks reach the client immediately.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $upstream = $this->upstream;
        if (!\is_resource($upstream)) {
            return;
        }

        while (!feof($upstream)) {
            if (connection_aborted()) {
                break;
            }

            $chunk = fread($upstream, 8192);
            if ($chunk === false) {
                break;
            }

            if ($chunk !== '') {
                echo $chunk;
                flush();
            }
        }

        fclose($upstream);
    }
}
