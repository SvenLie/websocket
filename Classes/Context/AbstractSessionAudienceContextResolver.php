<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Context;

use Amp\Http\Server\Request;
use Psr\Http\Message\ServerRequestInterface;
use SvenLie\Websocket\Domain\Audience\AudienceContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/**
 * Base resolver that authenticates a connecting client as a standard TYPO3
 * frontend user via the {@see fe_typo_user} cookie and derives the generic
 * audience context: the frontend user uid and its frontend user group uids
 * (fe_groups, incl. subgroups and any groups added by
 * ModifyResolvedFrontendGroupsEvent listeners).
 *
 * This keeps the plain fe_users check inside the websocket package. Feature
 * packages extend this class and add their own domain-specific attributes via
 * {@see resolveAttributes()} — the websocket package itself stays free of any
 * feature/session-model specifics (e.g. userData/storeData).
 *
 * @SuppressWarnings("PHPMD.Superglobals")
 */
abstract class AbstractSessionAudienceContextResolver implements AudienceContextResolverInterface
{
    public function resolve(Request $request): AudienceContext
    {
        $psrRequest = $this->buildPsrRequest($request);

        $frontendUser = GeneralUtility::makeInstance(FrontendUserAuthentication::class);
        $frontendUser->start($psrRequest);

        if (!is_array($frontendUser->user) || (int)($frontendUser->user['uid'] ?? 0) === 0) {
            throw new \RuntimeException('no authenticated frontend user', 1757500001);
        }

        $userId = (int)$frontendUser->user['uid'];

        $frontendUser->fetchGroupData($psrRequest);
        $groupIds = array_map(
            static fn($uid): int => (int)$uid,
            array_keys($frontendUser->userGroups),
        );

        return new AudienceContext(
            userId: $userId,
            attributes: $this->resolveAttributes($psrRequest),
            groupIds: $groupIds,
        );
    }

    /**
     * Hook for domain-specific targetable attributes (e.g. a role or region id),
     * keyed by their attribute name. The PSR request carries the authenticated
     * fe_typo_user session/cookies. The default is no extra attributes.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     * @return array<string, int|list<int>>
     */
    protected function resolveAttributes(ServerRequestInterface $request): array
    {
        return [];
    }

    private function buildPsrRequest(Request $request): ServerRequestInterface
    {
        $host = $request->getHeader('host') ?? '';
        if ($host === '') {
            throw new \RuntimeException('missing host header', 1784028864);
        }

        $cookieParams = $this->parseCookieHeader($request->getHeader('cookie') ?? '');
        if (!isset($cookieParams['fe_typo_user'])) {
            throw new \RuntimeException('missing fe_typo_user cookie', 1784028863);
        }

        $sessionDomain = $this->resolveSessionDomain($cookieParams['fe_typo_user'], $host);

        $serverParams = [
            'HTTP_HOST' => $sessionDomain,
            'SERVER_NAME' => $sessionDomain,
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
            'SCRIPT_NAME' => '/index.php',
            'REQUEST_URI' => '/',
        ];

        $publicPath = Environment::getPublicPath();
        $normalizedParams = new NormalizedParams(
            $serverParams,
            $GLOBALS['TYPO3_CONF_VARS']['SYS'] ?? [],
            $publicPath . '/index.php',
            $publicPath,
        );

        return (new ServerRequest(
            uri: $request->getUri()->withHost($sessionDomain),
            method: 'GET',
            body: 'php://temp',
            serverParams: $serverParams
        ))
            ->withCookieParams($cookieParams)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('normalizedParams', $normalizedParams);
    }

    /**
     * Reads the scope domain the fe_typo_user JWT was issued for. TYPO3 stores the cookie
     * scope ({domain, hostOnly, path}) in the JWT payload; we only read it here to validate
     * the session against the correct host. The JWT signature is still checked by TYPO3, so
     * an attacker cannot widen the scope by tampering with the payload.
     */
    private function resolveSessionDomain(string $feTypoUserCookie, string $fallbackHost): string
    {
        $segments = explode('.', $feTypoUserCookie);
        if (count($segments) < 2) {
            return $fallbackHost;
        }

        $payload = base64_decode(strtr($segments[1], '-_', '+/'), true);
        if ($payload === false) {
            return $fallbackHost;
        }

        $data = json_decode($payload, true);
        $domain = is_array($data) ? ($data['scope']['domain'] ?? null) : null;

        return is_string($domain) && $domain !== '' ? $domain : $fallbackHost;
    }

    /**
     * @return array<string, string>
     */
    private function parseCookieHeader(string $header): array
    {
        $cookies = [];
        foreach (explode(';', $header) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            $cookies[trim($name)] = trim($value);
        }

        return $cookies;
    }
}
