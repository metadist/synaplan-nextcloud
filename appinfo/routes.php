<?php

declare(strict_types=1);

return [
    'routes' => [
        // Pages
        ['name' => 'page#research', 'url' => '/research', 'verb' => 'GET'],

        // Settings API
        ['name' => 'settings#getSettings', 'url' => '/api/v1/settings', 'verb' => 'GET'],
        ['name' => 'settings#saveSettings', 'url' => '/api/v1/settings', 'verb' => 'PUT'],
        ['name' => 'settings#testConnection', 'url' => '/api/v1/settings/test', 'verb' => 'POST'],
        ['name' => 'settings#registerInstance', 'url' => '/api/v1/settings/register-instance', 'verb' => 'POST'],
        ['name' => 'settings#forgetInstance', 'url' => '/api/v1/settings/forget-instance', 'verb' => 'POST'],
        ['name' => 'settings#instanceStatus', 'url' => '/api/v1/settings/instance-status', 'verb' => 'GET'],

        // Document AI API
        ['name' => 'api#summarize', 'url' => '/api/v1/summarize/{fileId}', 'verb' => 'POST'],
        ['name' => 'api#translate', 'url' => '/api/v1/translate/{fileId}', 'verb' => 'POST'],

        // Knowledge (file upload + vectorization + lifecycle)
        ['name' => 'api#uploadToKnowledge', 'url' => '/api/v1/knowledge/upload/{fileId}', 'verb' => 'POST'],
        ['name' => 'api#knowledgeStatus', 'url' => '/api/v1/knowledge/status/{fileId}', 'verb' => 'GET'],
        ['name' => 'api#removeFromKnowledge', 'url' => '/api/v1/knowledge/{fileId}', 'verb' => 'DELETE'],
        ['name' => 'api#getGroups', 'url' => '/api/v1/knowledge/groups', 'verb' => 'GET'],
        ['name' => 'api#getModels', 'url' => '/api/v1/knowledge/models', 'verb' => 'GET'],
        ['name' => 'cloudStatus#status', 'url' => '/api/v1/cloud/status', 'verb' => 'POST'],
        ['name' => 'cloudStatus#detail', 'url' => '/api/v1/cloud/files/{fileId}', 'verb' => 'GET'],
        ['name' => 'cloudStatus#searchable', 'url' => '/api/v1/cloud/searchable', 'verb' => 'GET'],

        // Client runtime config (language + memory availability) for the chat UI
        ['name' => 'api#clientConfig', 'url' => '/api/v1/client-config', 'verb' => 'GET'],

        // Per-user "Activate AI" consent (per-user mode only)
        ['name' => 'consent#getConsent', 'url' => '/api/v1/ai-consent', 'verb' => 'GET'],
        ['name' => 'consent#setConsent', 'url' => '/api/v1/ai-consent', 'verb' => 'POST'],

        // Account linking (existing Synaplan account)
        ['name' => 'link#start', 'url' => '/link/start', 'verb' => 'GET'],
        ['name' => 'link#callback', 'url' => '/link/callback', 'verb' => 'GET'],
        ['name' => 'link#disconnect', 'url' => '/link/disconnect', 'verb' => 'POST'],
        ['name' => 'link#status', 'url' => '/api/v1/link/status', 'verb' => 'GET'],

        // Copies Synaplan pushes, and the folder a person wants them in
        ['name' => 'inbound#receive', 'url' => '/api/v1/inbound', 'verb' => 'POST'],
        ['name' => 'preferences#get', 'url' => '/api/v1/preferences', 'verb' => 'GET'],
        ['name' => 'preferences#save', 'url' => '/api/v1/preferences', 'verb' => 'PUT'],
        ['name' => 'preferences#allowFolder', 'url' => '/api/v1/preferences/read-folders', 'verb' => 'POST'],
        ['name' => 'fromSynaplan#list', 'url' => '/api/v1/from-synaplan', 'verb' => 'GET'],
        ['name' => 'fromSynaplan#copy', 'url' => '/api/v1/from-synaplan', 'verb' => 'POST'],

        // Admin control panel: activated AI users (admin-only)
        ['name' => 'adminAiUsers#list', 'url' => '/api/v1/admin/ai-users', 'verb' => 'GET'],
        ['name' => 'adminAiUsers#usage', 'url' => '/api/v1/admin/ai-users/{uid}/usage', 'verb' => 'GET'],
        ['name' => 'adminAiUsers#deactivate', 'url' => '/api/v1/admin/ai-users/{uid}/deactivate', 'verb' => 'POST'],

        // Chat API
        ['name' => 'chat#chat', 'url' => '/api/v1/chat', 'verb' => 'POST'],
        ['name' => 'chat#chatStream', 'url' => '/api/v1/chat/stream', 'verb' => 'POST'],

        // Research Chat threads (not used by the one-shot file actions)
        ['name' => 'researchHistory#list', 'url' => '/api/v1/research/conversations', 'verb' => 'GET'],
        ['name' => 'researchHistory#open', 'url' => '/api/v1/research/conversations/{id}', 'verb' => 'GET'],
        ['name' => 'researchHistory#save', 'url' => '/api/v1/research/conversations/{id}', 'verb' => 'PUT'],
        ['name' => 'researchHistory#remove', 'url' => '/api/v1/research/conversations/{id}', 'verb' => 'DELETE'],

        // Media generation API
        ['name' => 'media#generate', 'url' => '/api/v1/media/generate', 'verb' => 'POST'],
        ['name' => 'media#save', 'url' => '/api/v1/media/save', 'verb' => 'POST'],
        ['name' => 'media#proxy', 'url' => '/api/v1/media/proxy', 'verb' => 'GET'],
    ],
];
