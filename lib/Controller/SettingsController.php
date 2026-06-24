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
            'default_language' => $this->config->getAppValue(
                Application::APP_ID,
                'default_language',
                'en'
            ),
            'use_interface_language' => $this->config->getAppValue(
                Application::APP_ID,
                'use_interface_language',
                '1'
            ) === '1',
            'enable_memories' => $this->config->getAppValue(
                Application::APP_ID,
                'enable_memories',
                '1'
            ) === '1',
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
    public function saveSettings(
        string $synaplan_url = '',
        string $api_key = '',
        ?string $default_language = null,
        ?bool $use_interface_language = null,
        ?bool $enable_memories = null,
    ): JSONResponse {
        // Nextcloud may not extract JSON body params for PUT requests; always
        // re-read the raw body so we can also pick up the language/memory flags
        // (which the named-param extraction does not reliably surface for PUT).
        $body = file_get_contents('php://input');
        if ($body !== false && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                if ($synaplan_url === '' && isset($decoded['synaplan_url'])) {
                    $synaplan_url = trim((string) $decoded['synaplan_url']);
                }
                if ($api_key === '' && isset($decoded['api_key'])) {
                    $api_key = trim((string) $decoded['api_key']);
                }
                if ($default_language === null && isset($decoded['default_language'])) {
                    $default_language = trim((string) $decoded['default_language']);
                }
                if ($use_interface_language === null && array_key_exists('use_interface_language', $decoded)) {
                    $use_interface_language = (bool) $decoded['use_interface_language'];
                }
                if ($enable_memories === null && array_key_exists('enable_memories', $decoded)) {
                    $enable_memories = (bool) $decoded['enable_memories'];
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

        if ($default_language !== null && $default_language !== '') {
            $this->config->setAppValue(
                Application::APP_ID,
                'default_language',
                strtolower(substr($default_language, 0, 2))
            );
        }

        if ($use_interface_language !== null) {
            $this->config->setAppValue(
                Application::APP_ID,
                'use_interface_language',
                $use_interface_language ? '1' : '0'
            );
        }

        if ($enable_memories !== null) {
            $this->config->setAppValue(
                Application::APP_ID,
                'enable_memories',
                $enable_memories ? '1' : '0'
            );
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
