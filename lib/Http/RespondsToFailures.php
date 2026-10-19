<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Http;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\AppFramework\Http\JSONResponse;

/**
 * For controllers with a `$logger`: logs the exception and answers with its
 * {@see Failure}. A state the person can fix is logged at info, the rest as
 * an error.
 */
trait RespondsToFailures
{
    /**
     * @param array<string, mixed> $extra keys the frontend reads beside the failure
     */
    private function failed(\Throwable $e, string $action, array $extra = []): JSONResponse
    {
        $failure = Failure::fromThrowable($e);
        $context = [
            'app' => Application::APP_ID,
            'message' => $e->getMessage(),
            'code' => $failure->code,
            'exception' => $e,
        ];
        if ($failure->status >= 500) {
            $this->logger->error($action . ': {message}', $context);
        } else {
            $this->logger->info($action . ': {message}', $context);
        }

        return $failure->toResponse($extra);
    }
}
