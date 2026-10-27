<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\CloudStateService;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCA\SynaplanIntegration\Service\SynaplanConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Batched searchable state for Files rows, sidebar detail for one file or
 * folder, and the person's searchable files for the Synaplan page.
 */
class CloudStatusController extends Controller
{
    public function __construct(
        IRequest $request,
        private CloudStateService $cloudState,
        private SynaplanClient $synaplanClient,
        private SynaplanConfig $synaplanConfig,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function status(): JSONResponse
    {
        if (!$this->synaplanConfig->isPerUserAccountsEnabled()) {
            return new JSONResponse(['success' => true, 'enabled' => false, 'items' => [], 'watches' => []]);
        }

        $items = $this->readItems();
        if ($items === []) {
            return new JSONResponse(['success' => true, 'enabled' => false, 'items' => [], 'watches' => []]);
        }

        $answer = $this->cloudState->status($items);
        if (!$answer['enabled']) {
            return new JSONResponse(['success' => true, 'enabled' => false, 'items' => [], 'watches' => []]);
        }

        return new JSONResponse([
            'success' => true,
            'enabled' => true,
            'unreachable' => (bool) ($answer['unreachable'] ?? false),
            'items' => $answer['items'],
            'watches' => $answer['watches'],
            'glyphs' => $this->cloudState->glyphOverrides(),
            'openSynaplanUrl' => $this->synaplanConfig->getPublicBaseUrl() . '/files',
        ]);
    }

    #[NoAdminRequired]
    public function detail(int $fileId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->synaplanConfig->isPerUserAccountsEnabled()) {
            return new JSONResponse(['success' => true, 'enabled' => false]);
        }

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        $node = $userFolder->getFirstNodeById($fileId);
        if (!$node instanceof File && !$node instanceof Folder) {
            return new JSONResponse(['success' => false, 'error' => 'not_found'], 404);
        }

        $etag = (string) $node->getEtag();
        $answer = $this->cloudState->status([['id' => (string) $fileId, 'version' => $etag]]);
        if (!$answer['enabled']) {
            return new JSONResponse(['success' => true, 'enabled' => false]);
        }
        if ((bool) ($answer['unreachable'] ?? false)) {
            return new JSONResponse([
                'success' => true,
                'enabled' => true,
                'unreachable' => true,
            ]);
        }

        $item = $answer['items'][0] ?? [
            'id' => (string) $fileId,
            'state' => 'none',
            'knowledge_folder' => null,
            'file_id' => null,
            'kept_up_to_date' => false,
        ];
        $versionState = (string) ($item['state'] ?? 'none');
        $synaplanFileId = isset($item['file_id']) && is_int($item['file_id']) ? $item['file_id'] : null;
        if ($synaplanFileId !== null) {
            $remote = $this->cloudState->detail($synaplanFileId);
            if (is_array($remote)) {
                $item = array_merge($remote, $item, ['state' => $versionState]);
            }
        }

        return new JSONResponse([
            'success' => true,
            'enabled' => true,
            'unreachable' => false,
            'kind' => $node instanceof Folder ? 'folder' : 'file',
            'fileId' => $fileId,
            'name' => $node->getName(),
            'modified' => $node->getMTime(),
            'state' => (string) ($item['state'] ?? 'none'),
            'knowledgeFolder' => is_array($item['knowledge_folder'] ?? null)
                ? (string) ($item['knowledge_folder']['name'] ?? $item['knowledge_folder']['key'] ?? '')
                : null,
            'keptUpToDate' => (bool) ($item['kept_up_to_date'] ?? false),
            'synaplanFileId' => $synaplanFileId,
            'watches' => $answer['watches'],
            'glyphs' => $this->cloudState->glyphOverrides(),
            'openSynaplanUrl' => $this->synaplanConfig->getPublicBaseUrl() . '/files',
        ]);
    }

    #[NoAdminRequired]
    public function searchable(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->synaplanConfig->isPerUserAccountsEnabled()) {
            return new JSONResponse(['success' => true, 'files' => []]);
        }
        try {
            $recent = $this->synaplanClient->listRecentFiles(100);
        } catch (\Throwable) {
            return new JSONResponse(['success' => true, 'files' => []]);
        }

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        $candidates = [];
        $probeItems = [];
        foreach (is_array($recent['files'] ?? null) ? $recent['files'] : [] as $row) {
            if (!is_array($row) || ($row['source'] ?? '') !== 'nextcloud') {
                continue;
            }
            $sourceId = isset($row['source_id']) ? (string) $row['source_id'] : '';
            $ncId = ctype_digit($sourceId) ? (int) $sourceId : 0;
            if ($ncId <= 0 || isset($candidates[$sourceId])) {
                continue;
            }
            $node = $userFolder->getFirstNodeById($ncId);
            if (!$node instanceof File) {
                continue;
            }
            $path = $userFolder->getRelativePath($node->getPath()) ?? $node->getName();
            $candidates[$sourceId] = [
                'id' => (int) ($row['id'] ?? 0),
                'fileId' => $ncId,
                'name' => (string) ($row['filename'] ?? $path),
                'path' => ltrim($path, '/'),
                'knowledgeFolder' => (string) ($row['group_key'] ?? ''),
                'openUrl' => $this->urlGenerator->getAbsoluteURL('/index.php/f/' . $ncId),
            ];
            $probeItems[] = ['id' => $sourceId, 'version' => (string) $node->getEtag()];
        }

        $files = [];
        if ($probeItems !== []) {
            $checked = $this->cloudState->status($probeItems);
            foreach ($checked['items'] as $statusRow) {
                $id = is_string($statusRow['id'] ?? null) ? $statusRow['id'] : '';
                $state = (string) ($statusRow['state'] ?? 'none');
                if ($id !== '' && $state !== 'none' && isset($candidates[$id])) {
                    $files[] = $candidates[$id];
                }
            }
        }

        return new JSONResponse([
            'success' => true,
            'files' => $files,
            'openSynaplanUrl' => $this->synaplanConfig->getPublicBaseUrl() . '/files',
        ]);
    }

    /**
     * @return list<array{id: string, version: string}>
     */
    private function readItems(): array
    {
        $raw = $this->request->getParam('items');
        if (!is_array($raw)) {
            $body = file_get_contents('php://input');
            $decoded = $body !== false && $body !== '' ? json_decode($body, true) : null;
            $raw = is_array($decoded) && is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        }
        $items = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            $version = trim((string) ($item['version'] ?? ''));
            if ($id === '' || strlen($id) > 255 || strlen($version) > 255) {
                continue;
            }
            $items[] = ['id' => $id, 'version' => $version];
            if (count($items) >= 500) {
                break;
            }
        }

        return $items;
    }
}
