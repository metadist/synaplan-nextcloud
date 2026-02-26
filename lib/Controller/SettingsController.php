<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * API controller for admin settings.
 *
 * Handles reading/writing Synaplan connection configuration
 * and testing the connection to the Synaplan API.
 */
class SettingsController extends Controller
{
    public function __construct(
        IRequest $request,
        private IConfig $config,
        private SynaplanClient $synaplanClient,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Return current settings (API key is masked).
     */
    public function getSettings(): JSONResponse
    {
        $apiKey = $this->config->getAppValue(Application::APP_ID, 'api_key', '');

        return new JSONResponse([
            'synaplan_url' => $this->config->getAppValue(
                Application::APP_ID,
                'synaplan_url',
                'http://localhost:8000'
            ),
            'api_key_set' => $apiKey !== '',
            'api_key_masked' => $apiKey !== '' ? $this->maskApiKey($apiKey) : '',
        ]);
    }

    /**
     * Persist settings.
     *
     * Accepts JSON body: { synaplan_url?: string, api_key?: string }
     *
     * Named parameters work when NC framework extracts them.
     * Falls back to raw JSON body parsing for PUT requests.
     */
    public function saveSettings(string $synaplan_url = '', string $api_key = ''): JSONResponse
    {
        // Nextcloud may not extract JSON body params for PUT requests
        if ($synaplan_url === '' && $api_key === '') {
            $body = file_get_contents('php://input');
            if ($body !== false && $body !== '') {
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    $synaplan_url = trim((string) ($decoded['synaplan_url'] ?? ''));
                    $api_key = trim((string) ($decoded['api_key'] ?? ''));
                }
            }
        }

        if ($synaplan_url !== '') {
            $this->config->setAppValue(
                Application::APP_ID,
                'synaplan_url',
                rtrim($synaplan_url, '/')
            );
        }

        if ($api_key !== '') {
            $this->config->setAppValue(Application::APP_ID, 'api_key', $api_key);
        }

        return new JSONResponse(['success' => true]);
    }

    /**
     * Test connection to Synaplan API.
     *
     * Always returns HTTP 200 — the endpoint itself succeeds,
     * only the external health check may fail.
     */
    public function testConnection(): JSONResponse
    {
        try {
            $result = $this->synaplanClient->healthCheck();

            return new JSONResponse([
                'success' => true,
                'status' => $result['status'] ?? 'unknown',
                'providers' => $result['providers'] ?? [],
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mask an API key for display: show first 6 and last 4 chars.
     */
    private function maskApiKey(string $key): string
    {
        if (strlen($key) <= 10) {
            return str_repeat('•', strlen($key));
        }

        return substr($key, 0, 6) . str_repeat('•', strlen($key) - 10) . substr($key, -4);
    }
}
