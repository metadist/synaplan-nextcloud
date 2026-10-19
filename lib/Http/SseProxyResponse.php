<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use Psr\Log\LoggerInterface;

/**
 * Streams Synaplan's OpenAI-compatible SSE chat response straight to the
 * browser, chunk by chunk, using a direct cURL transfer.
 *
 * Why cURL instead of the Nextcloud HTTP client: with the buffered client the
 * upstream body was only handed over once complete, so the answer arrived all
 * at once ("Thinking…" then a full message). cURL's write callback hands us
 * each network chunk the instant it arrives, and we echo + flush it
 * immediately, defeating PHP/Nextcloud output buffering so the answer truly
 * streams token by token.
 *
 * A Synaplan error status or a failed connection ends the stream with one
 * error event that carries a {@see Failure} code, never Synaplan's raw body or
 * the cURL message.
 */
class SseProxyResponse extends Response implements ICallbackResponse
{
    /** Enough of an upstream error body to read its error code. */
    private const ERROR_BODY_LIMIT = 8192;

    private int $upstreamStatus = 0;
    private string $errorBody = '';

    /**
     * @param string                 $url        Upstream chat-completions endpoint
     * @param string                 $apiKey     Synaplan API key
     * @param string                 $payload    JSON request body (messages + stream:true)
     * @param (\Closure(): Failure)|null $keyRefused What a 401 means for this person
     */
    public function __construct(
        private string $url,
        private string $apiKey,
        private string $payload,
        private LoggerInterface $logger,
        private ?\Closure $keyRefused = null,
    ) {
        parent::__construct();

        $this->setStatus(Http::STATUS_OK);
        $this->addHeader('Content-Type', 'text/event-stream; charset=utf-8');
        $this->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        $this->addHeader('Connection', 'keep-alive');
        // Tell intermediary proxies (nginx ingress / FrankenPHP) not to buffer.
        $this->addHeader('X-Accel-Buffering', 'no');
    }

    public function callback(IOutput $output): void
    {
        // Defeat every layer of buffering between us and the browser.
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        if (\function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
            @apache_setenv('dont-vary', '1');
        }
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        // Prime the connection so proxies with a minimum-bytes flush threshold
        // release the stream straight away (SSE comment line, ignored by clients).
        echo ': ' . str_repeat(' ', 2048) . "\n\n";
        $this->flush();

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $this->payload,
            \CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $this->apiKey,
                'Accept: text/event-stream',
                'Content-Type: application/json',
                // Force plain-text SSE — a gzipped body cannot be relayed raw.
                'Accept-Encoding: identity',
            ],
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_TIMEOUT => 300,
            \CURLOPT_CONNECTTIMEOUT => 15,
            \CURLOPT_BUFFERSIZE => 256,
            \CURLOPT_HEADERFUNCTION => function ($handle, string $header): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
                    $this->upstreamStatus = (int) $match[1];
                }

                return \strlen($header);
            },
            \CURLOPT_WRITEFUNCTION => function ($handle, string $data): int {
                if (connection_aborted()) {
                    return 0;
                }
                if ($this->upstreamStatus >= 400) {
                    $room = self::ERROR_BODY_LIMIT - \strlen($this->errorBody);
                    $this->errorBody .= substr($data, 0, max(0, $room));

                    return \strlen($data);
                }
                echo $data;
                $this->flush();

                return \strlen($data);
            },
        ]);

        $completed = curl_exec($ch) !== false;
        if (!connection_aborted()) {
            if (!$completed) {
                $this->logger->error('SSE chat proxy failed: {message}', [
                    'app' => 'synaplan_integration',
                    'message' => curl_error($ch),
                ]);
                $this->send(new Failure(Http::STATUS_BAD_GATEWAY, Failure::SYNAPLAN_UNREACHABLE));
            } elseif ($this->upstreamStatus >= 400) {
                $this->logger->error('SSE chat proxy: Synaplan answered HTTP {status}', [
                    'app' => 'synaplan_integration',
                    'status' => $this->upstreamStatus,
                ]);
                $this->send($this->upstreamStatus === Http::STATUS_UNAUTHORIZED && $this->keyRefused !== null
                    ? ($this->keyRefused)()
                    : Failure::fromSynaplanResponse($this->upstreamStatus, $this->errorBody));
            }
        }

        curl_close($ch);
    }

    /**
     * The frames that end a stream with a failure the browser can name.
     */
    public static function errorEvent(Failure $failure): string
    {
        $error = ['code' => $failure->code];
        if ($failure->message !== null) {
            $error['message'] = $failure->message;
        }

        return 'data: ' . json_encode(['error' => $error]) . "\n\ndata: [DONE]\n\n";
    }

    private function send(Failure $failure): void
    {
        echo self::errorEvent($failure);
        $this->flush();
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
