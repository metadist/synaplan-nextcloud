<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Tests\Unit\Http;

use OCA\SynaplanIntegration\Exception\FailureException;
use OCA\SynaplanIntegration\Http\Failure;
use OCA\SynaplanIntegration\Http\SseProxyResponse;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

final class FailureTest extends TestCase
{
    public function testAMachineCodeFromSynaplanIsKept(): void
    {
        $failure = Failure::fromSynaplanResponse(403, '{"success":false,"error":"insufficient_scope","required":["chat"]}');

        self::assertSame([403, 'insufficient_scope', null], [$failure->status, $failure->code, $failure->message]);
    }

    public function testASynaplanSentenceIsPassedOnAsTheMessage(): void
    {
        $sentence = 'The translation was cut off because the document is too long for one answer. Try a shorter document.';

        $failure = Failure::fromSynaplanResponse(422, (string) json_encode(['success' => false, 'error' => $sentence]));

        self::assertSame([422, 'synaplan_rejected', $sentence], [$failure->status, $failure->code, $failure->message]);
    }

    public function testTheChatCompletionsErrorShapeKeepsCodeAndSentence(): void
    {
        $sentence = 'No model specified and no default chat model is configured.';
        $failure = Failure::fromSynaplanResponse(400, (string) json_encode([
            'error' => ['code' => 'model_not_found', 'message' => $sentence, 'type' => 'invalid_request_error'],
        ]));

        self::assertSame([400, 'model_not_found', $sentence], [$failure->status, $failure->code, $failure->message]);
    }

    public function testACodeBesideASentenceKeepsBoth(): void
    {
        $failure = Failure::fromSynaplanResponse(403, '{"error":"This key may not use this route.","code":"insufficient_scope"}');

        self::assertSame(['insufficient_scope', 'This key may not use this route.'], [$failure->code, $failure->message]);
    }

    public function testANestedSentenceWithoutCodeIsPassedOnAsTheMessage(): void
    {
        $failure = Failure::fromSynaplanResponse(400, '{"error":{"message":"The prompt is empty."}}');

        self::assertSame(['synaplan_rejected', 'The prompt is empty.'], [$failure->code, $failure->message]);
    }

    public function testRateLimitAndSizeGetTheirOwnCodes(): void
    {
        self::assertSame('rate_limited', Failure::fromSynaplanResponse(429, '{"error":"Too many requests."}')->code);
        self::assertSame('file_too_large', Failure::fromSynaplanResponse(413, '')->code);
    }

    public function testARefusedKeyIsNeverPassedOnAs401(): void
    {
        $failure = Failure::fromSynaplanResponse(401, '{"error":"Invalid API key"}');

        self::assertSame([502, 'synaplan_key_rejected'], [$failure->status, $failure->code]);
    }

    public function testServerErrorsAndProxyStatusesBecomeABadGateway(): void
    {
        foreach ([500, 503, 407, 412, 302] as $status) {
            $failure = Failure::fromSynaplanResponse($status, '<html>Bad Gateway</html>');
            self::assertSame([502, 'synaplan_error', null], [$failure->status, $failure->code, $failure->message], (string) $status);
        }
    }

    public function testAProxyErrorPageIsNotTakenForASynaplanRejection(): void
    {
        $forbidden = Failure::fromSynaplanResponse(403, '<html><body>403 Forbidden</body></html>');
        self::assertSame([502, 'synaplan_error'], [$forbidden->status, $forbidden->code]);

        $busy = Failure::fromSynaplanResponse(429, '<html><body>Too Many Requests</body></html>');
        self::assertSame([429, 'rate_limited', null], [$busy->status, $busy->code, $busy->message]);
    }

    public function testAnUnusualClientStatusIsAnswered422(): void
    {
        self::assertSame(422, Failure::fromSynaplanResponse(405, '{"error":"Method Not Allowed"}')->status);
    }

    public function testALongBodyIsNotPassedOn(): void
    {
        $failure = Failure::fromSynaplanResponse(400, (string) json_encode(['error' => str_repeat('Stack frame. ', 50)]));

        self::assertNull($failure->message);
    }

    public function testAnHttpExceptionIsReadFromItsResponse(): void
    {
        $failure = Failure::fromThrowable(new HttpErrorException(403, '{"error":"insufficient_scope"}'));

        self::assertSame([403, 'insufficient_scope'], [$failure->status, $failure->code]);
    }

    public function testAFailedConnectionIsUnreachable(): void
    {
        $failure = Failure::fromThrowable(new \RuntimeException('cURL error 28: Operation timed out after 120001 milliseconds'));

        self::assertSame([502, 'synaplan_unreachable'], [$failure->status, $failure->code]);
    }

    public function testAMissingFileIsNotFound(): void
    {
        self::assertSame([404, 'file_not_found'], [
            Failure::fromThrowable(new NotFoundException('File not found'))->status,
            Failure::fromThrowable(new NotFoundException('File not found'))->code,
        ]);
    }

    public function testTheAppsOwnStateKeepsItsFailure(): void
    {
        self::assertSame('consent_required', Failure::fromThrowable(FailureException::consentRequired())->code);
    }

    public function testAnythingElseIsUnexpectedAndKeepsItsTextOutOfTheResponse(): void
    {
        $response = Failure::fromThrowable(new \LogicException('Undefined index at /var/www/html/custom_apps'))->toResponse(['groups' => []]);

        self::assertSame(500, $response->getStatus());
        self::assertSame(['groups' => [], 'success' => false, 'error' => 'unexpected'], $response->getData());
    }

    public function testAStreamEndsWithOneErrorEvent(): void
    {
        self::assertSame(
            "\n\ndata: {\"error\":{\"code\":\"synaplan_rejected\",\"message\":\"Try a shorter document.\"}}\n\ndata: [DONE]\n\n",
            SseProxyResponse::errorEvent(new Failure(422, 'synaplan_rejected', 'Try a shorter document.')),
        );
        self::assertSame(
            "\n\ndata: {\"error\":{\"code\":\"synaplan_unreachable\"}}\n\ndata: [DONE]\n\n",
            SseProxyResponse::errorEvent(new Failure(502, 'synaplan_unreachable')),
        );
    }
}

/** Shaped like the Guzzle exception the Nextcloud HTTP client throws for an error status. */
final class HttpErrorException extends \RuntimeException
{
    public function __construct(
        int $status,
        private string $body,
    ) {
        parent::__construct('Client error: `POST http://backend/api/v1/summary/generate` resulted in a `' . $status . '` response', $status);
    }

    public function getResponse(): object
    {
        return new class ($this->body) {
            public function __construct(
                private string $body,
            ) {
            }

            public function getBody(): string
            {
                return $this->body;
            }
        };
    }
}
