<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

/**
 * Controller for full-page views.
 */
class PageController extends Controller
{
	public function __construct(IRequest $request)
	{
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Research chat page.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function research(): TemplateResponse
	{
		Util::addScript(Application::APP_ID, 'synaplan_integration-research');

		return new TemplateResponse(Application::APP_ID, 'research');
	}
}
