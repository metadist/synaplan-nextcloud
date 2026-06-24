<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\IConfig;
use OCP\IUserSession;

/**
 * Resolves the output language for AI operations.
 *
 * Two admin-configurable inputs drive the result:
 *  - default_language:        fallback language code (ISO 639-1, e.g. "de").
 *  - use_interface_language:  when enabled, the signed-in user's Nextcloud
 *                             interface language wins over the default — so a
 *                             German user gets German answers automatically,
 *                             while a French colleague gets French, without any
 *                             per-user configuration.
 *
 * This fixes the long-standing "answers are always English" complaint: the
 * chat/streaming endpoints used a hard-coded "en", ignoring both the admin
 * default and the user's locale.
 */
class LanguageService
{
    public const DEFAULT_LANGUAGE = 'en';

    /**
     * Languages we can name for the model instruction. The short ISO code is
     * mapped to the English language name (what we feed the model) so the
     * instruction is unambiguous regardless of the user's locale.
     *
     * @var array<string, string>
     */
    public const SUPPORTED = [
        'en' => 'English',
        'de' => 'German',
        'fr' => 'French',
        'es' => 'Spanish',
        'it' => 'Italian',
        'pt' => 'Portuguese',
        'nl' => 'Dutch',
        'pl' => 'Polish',
        'tr' => 'Turkish',
        'ru' => 'Russian',
        'uk' => 'Ukrainian',
        'cs' => 'Czech',
        'sk' => 'Slovak',
        'da' => 'Danish',
        'sv' => 'Swedish',
        'nb' => 'Norwegian',
        'fi' => 'Finnish',
        'el' => 'Greek',
        'ro' => 'Romanian',
        'hu' => 'Hungarian',
        'bg' => 'Bulgarian',
        'hr' => 'Croatian',
        'ja' => 'Japanese',
        'zh' => 'Chinese',
        'ko' => 'Korean',
        'ar' => 'Arabic',
    ];

    public function __construct(
        private IConfig $config,
        private IUserSession $userSession,
    ) {
    }

    /**
     * The admin-configured default language (always a supported code).
     */
    public function getDefaultLanguage(): string
    {
        $code = $this->normalize(
            $this->config->getAppValue(Application::APP_ID, 'default_language', self::DEFAULT_LANGUAGE)
        );

        return $code !== '' ? $code : self::DEFAULT_LANGUAGE;
    }

    /**
     * Whether the signed-in user's interface language should override the default.
     */
    public function useInterfaceLanguage(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, 'use_interface_language', '1') === '1';
    }

    /**
     * Resolve the effective output language code for the current user.
     */
    public function resolveLanguage(): string
    {
        $default = $this->getDefaultLanguage();

        if (!$this->useInterfaceLanguage()) {
            return $default;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return $default;
        }

        $interfaceLang = $this->config->getUserValue($user->getUID(), 'core', 'lang', '');
        $code = $this->normalize($interfaceLang);

        return $code !== '' ? $code : $default;
    }

    /**
     * English name of a language code, for use in model instructions.
     */
    public function getLanguageName(string $code): string
    {
        $code = $this->normalize($code);

        return self::SUPPORTED[$code] ?? self::SUPPORTED[self::DEFAULT_LANGUAGE];
    }

    /**
     * Normalise a locale string (e.g. "pt_BR", "DE") to a supported short code,
     * or '' when we have no entry for it (caller falls back to the default).
     */
    public function normalize(string $lang): string
    {
        $lang = strtolower(trim($lang));
        if ($lang === '') {
            return '';
        }

        $short = substr($lang, 0, 2);

        return isset(self::SUPPORTED[$short]) ? $short : '';
    }
}
