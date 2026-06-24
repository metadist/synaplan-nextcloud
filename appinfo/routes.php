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

		// Document AI API
		['name' => 'api#summarize', 'url' => '/api/v1/summarize/{fileId}', 'verb' => 'POST'],
		['name' => 'api#translate', 'url' => '/api/v1/translate/{fileId}', 'verb' => 'POST'],

		// Knowledge (file upload + vectorization)
		['name' => 'api#uploadToKnowledge', 'url' => '/api/v1/knowledge/upload/{fileId}', 'verb' => 'POST'],
		['name' => 'api#getGroups', 'url' => '/api/v1/knowledge/groups', 'verb' => 'GET'],
		['name' => 'api#getModels', 'url' => '/api/v1/knowledge/models', 'verb' => 'GET'],

		// Client runtime config (language + memory availability) for the chat UI
		['name' => 'api#clientConfig', 'url' => '/api/v1/client-config', 'verb' => 'GET'],

		// Chat API
		['name' => 'chat#chat', 'url' => '/api/v1/chat', 'verb' => 'POST'],
		['name' => 'chat#chatStream', 'url' => '/api/v1/chat/stream', 'verb' => 'POST'],

		// Media generation API
		['name' => 'media#generate', 'url' => '/api/v1/media/generate', 'verb' => 'POST'],
		['name' => 'media#save', 'url' => '/api/v1/media/save', 'verb' => 'POST'],
		['name' => 'media#proxy', 'url' => '/api/v1/media/proxy', 'verb' => 'GET'],
	],
];
