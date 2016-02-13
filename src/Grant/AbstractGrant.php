<?php
/**
 * OAuth 2.0 Abstract grant
 *
 * @package     league/oauth2-server
 * @author      Alex Bilbie <hello@alexbilbie.com>
 * @copyright   Copyright (c) Alex Bilbie
 * @license     http://mit-license.org/
 * @link        https://github.com/thephpleague/oauth2-server
 */

namespace League\OAuth2\Server\Grant;

use League\Event\EmitterAwareTrait;
use League\Event\EmitterInterface;
use League\Event\Event;
use League\OAuth2\Server\Entities\AccessTokenEntity;
use League\OAuth2\Server\Entities\AuthCodeEntity;
use League\OAuth2\Server\Entities\Interfaces\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntity;
use League\OAuth2\Server\Entities\ScopeEntity;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Abstract grant class
 */
abstract class AbstractGrant implements GrantTypeInterface
{
    use EmitterAwareTrait;

    const SCOPE_DELIMITER_STRING = ' ';

    /**
     * Grant responds with
     *
     * @var string
     */
    protected $respondsWith = 'token';

    /**
     * @var ServerRequestInterface
     */
    protected $request;

    /**
     * @var ClientRepositoryInterface
     */
    protected $clientRepository;

    /**
     * @var AccessTokenRepositoryInterface
     */
    protected $accessTokenRepository;

    /**
     * @var ScopeRepositoryInterface
     */
    protected $scopeRepository;

    /**
     * @var string
     */
    protected $pathToPrivateKey;

    /**
     * @var string
     */
    protected $pathToPublicKey;

    /**
     * @var \DateInterval
     */
    protected $refreshTokenTTL;

    /**
     * @param ClientRepositoryInterface $clientRepository
     */
    public function setClientRepository(ClientRepositoryInterface $clientRepository)
    {
        $this->clientRepository = $clientRepository;
    }

    /**
     * @param AccessTokenRepositoryInterface $accessTokenRepository
     */
    public function setAccessTokenRepository(AccessTokenRepositoryInterface $accessTokenRepository)
    {
        $this->accessTokenRepository = $accessTokenRepository;
    }

    /**
     * @param ScopeRepositoryInterface $scopeRepository
     */
    public function setScopeRepository(ScopeRepositoryInterface $scopeRepository)
    {
        $this->scopeRepository = $scopeRepository;
    }

    /**
     * @param string $pathToPrivateKey
     */
    public function setPathToPrivateKey($pathToPrivateKey)
    {
        $this->pathToPrivateKey = $pathToPrivateKey;
    }

    /**
     * @param string $pathToPublicKey
     */
    public function setPathToPublicKey($pathToPublicKey)
    {
        $this->pathToPublicKey = $pathToPublicKey;
    }

    /**
     * @inheritdoc
     */
    public function setEmitter(EmitterInterface $emitter = null)
    {
        $this->emitter = $emitter;
    }

    /**
     * @inheritdoc
     */
    public function setRefreshTokenTTL(\DateInterval $refreshTokenTTL)
    {
        $this->refreshTokenTTL = $refreshTokenTTL;
    }

    /**
     * {@inheritdoc}
     */
    public function respondsWith()
    {
        return $this->respondsWith;
    }

    /**
     * Validate the client
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @param bool                                     $validateSecret
     * @param bool                                     $validateRedirectUri
     *
     * @return \League\OAuth2\Server\Entities\Interfaces\ClientEntityInterface
     * @throws \League\OAuth2\Server\Exception\OAuthServerException
     */
    protected function validateClient(
        ServerRequestInterface $request,
        $validateSecret = true,
        $validateRedirectUri = false
    ) {
        $clientId = $this->getRequestParameter(
            'client_id',
            $request,
            $this->getServerParameter('PHP_AUTH_USER', $request)
        );
        if (is_null($clientId)) {
            throw OAuthServerException::invalidRequest('client_id', null, '`%s` parameter is missing');
        }

        $clientSecret = $this->getRequestParameter(
            'client_secret',
            $request,
            $this->getServerParameter('PHP_AUTH_PW', $request)
        );
        if (is_null($clientSecret) && $validateSecret === true) {
            throw OAuthServerException::invalidRequest('client_secret', null, '`%s` parameter is missing');
        }

        $redirectUri = $this->getRequestParameter('redirect_uri', $request, null);
        if (is_null($redirectUri) && $validateRedirectUri === true) {
            throw OAuthServerException::invalidRequest('redirect_uri', null, '`%s` parameter is missing');
        }

        $client = $this->clientRepository->getClientEntity(
            $clientId,
            $clientSecret,
            $redirectUri,
            $this->getIdentifier()
        );

        if (!$client instanceof ClientEntityInterface) {
            $this->getEmitter()->emit(new Event('client.authentication.failed', $request));

            throw OAuthServerException::invalidClient();
        }

        return $client;
    }

    /**
     * Validate scopes in the request
     *
     * @param \Psr\Http\Message\ServerRequestInterface                        $request
     * @param \League\OAuth2\Server\Entities\Interfaces\ClientEntityInterface $client
     * @param string                                                          $redirectUri
     *
     * @return \League\OAuth2\Server\Entities\ScopeEntity[]
     *
     * @throws \League\OAuth2\Server\Exception\OAuthServerException
     */
    public function validateScopes(
        ServerRequestInterface $request,
        ClientEntityInterface $client,
        $redirectUri = null
    ) {
        $requestedScopes = $this->getRequestParameter('scope', $request);
        $scopesList = array_filter(
            explode(self::SCOPE_DELIMITER_STRING, trim($requestedScopes)),
            function ($scope) {
                return !empty($scope);
            }
        );

        $scopes = [];
        foreach ($scopesList as $scopeItem) {
            $scope = $this->scopeRepository->getScopeEntityByIdentifier(
                $scopeItem,
                $this->getIdentifier(),
                $client->getIdentifier()
            );

            if (($scope instanceof ScopeEntity) === false) {
                throw OAuthServerException::invalidScope($scopeItem, null, null, $redirectUri);
            }

            $scopes[] = $scope;
        }

        return $scopes;
    }

    /**
     * Retrieve request parameter.
     *
     * @param string                                   $parameter
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @param mixed                                    $default
     *
     * @return null|string
     */
    protected function getRequestParameter($parameter, ServerRequestInterface $request, $default = null)
    {
        return (isset($request->getParsedBody()[$parameter])) ? $request->getParsedBody()[$parameter] : $default;
    }

    /**
     * Retrieve query string parameter.
     *
     * @param string                                   $parameter
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @param mixed                                    $default
     *
     * @return null|string
     */
    protected function getQueryStringParameter($parameter, ServerRequestInterface $request, $default = null)
    {
        return (isset($request->getQueryParams()[$parameter])) ? $request->getQueryParams()[$parameter] : $default;
    }

    /**
     * Retrieve cookie parameter.
     *
     * @param string                                   $parameter
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @param mixed                                    $default
     *
     * @return null|string
     */
    protected function getCookieParameter($parameter, ServerRequestInterface $request, $default = null)
    {
        return (isset($request->getCookieParams()[$parameter])) ? $request->getCookieParams()[$parameter] : $default;
    }

    /**
     * Retrieve server parameter.
     *
     * @param string|array                             $parameter
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @param mixed                                    $default
     *
     * @return null|string
     */
    protected function getServerParameter($parameter, ServerRequestInterface $request, $default = null)
    {
        return (isset($request->getServerParams()[$parameter])) ? $request->getServerParams()[$parameter] : $default;
    }

    /**
     * Issue an access token
     *
     * @param \DateInterval                                                   $tokenTTL
     * @param \League\OAuth2\Server\Entities\Interfaces\ClientEntityInterface $client
     * @param string                                                          $userIdentifier
     * @param array                                                           $scopes
     *
     * @return \League\OAuth2\Server\Entities\AccessTokenEntity
     */
    protected function issueAccessToken(
        \DateInterval $tokenTTL,
        ClientEntityInterface $client,
        $userIdentifier,
        array $scopes = []
    ) {
        $accessToken = new AccessTokenEntity();
        $accessToken->setIdentifier($this->generateUniqueIdentifier());
        $accessToken->setExpiryDateTime((new \DateTime())->add($tokenTTL));
        $accessToken->setClient($client);
        $accessToken->setUserIdentifier($userIdentifier);

        foreach ($scopes as $scope) {
            $accessToken->addScope($scope);
        }

        return $accessToken;
    }

    /**
     * Issue an auth code
     *
     * @param \DateInterval                                                   $tokenTTL
     * @param \League\OAuth2\Server\Entities\Interfaces\ClientEntityInterface $client
     * @param string                                                          $userIdentifier
     * @param string                                                          $redirectUri
     * @param array                                                           $scopes
     *
     * @return \League\OAuth2\Server\Entities\AuthCodeEntity
     * @throws \League\OAuth2\Server\Exception\OAuthServerException
     */
    protected function issueAuthCode(
        \DateInterval $tokenTTL,
        ClientEntityInterface $client,
        $userIdentifier,
        $redirectUri,
        array $scopes = []
    ) {
        $authCode = new AuthCodeEntity();
        $authCode->setIdentifier($this->generateUniqueIdentifier());
        $authCode->setExpiryDateTime((new \DateTime())->add($tokenTTL));
        $authCode->setClient($client);
        $authCode->setUserIdentifier($userIdentifier);
        $authCode->setRedirectUri($redirectUri);

        foreach ($scopes as $scope) {
            $authCode->addScope($scope);
        }

        return $authCode;
    }

    /**
     * @param \League\OAuth2\Server\Entities\AccessTokenEntity $accessToken
     *
     * @return \League\OAuth2\Server\Entities\RefreshTokenEntity
     */
    protected function issueRefreshToken(AccessTokenEntity $accessToken)
    {
        $refreshToken = new RefreshTokenEntity();
        $refreshToken->setIdentifier($this->generateUniqueIdentifier());
        $refreshToken->setExpiryDateTime((new \DateTime())->add($this->refreshTokenTTL));
        $refreshToken->setAccessToken($accessToken);

        return $refreshToken;
    }

    /**
     * Generate a new unique identifier
     *
     * @param int $length
     *
     * @return string
     *
     * @throws \League\OAuth2\Server\Exception\OAuthServerException
     */
    protected function generateUniqueIdentifier($length = 40)
    {
        try {
            return bin2hex(random_bytes($length));
        } catch (\TypeError $e) {
            throw OAuthServerException::serverError('An unexpected error has occurred');
        } catch (\Error $e) {
            throw OAuthServerException::serverError('An unexpected error has occurred');
        } catch (\Exception $e) {
            throw OAuthServerException::serverError('Could not generate a random string');
        }
    }

    /**
     * @inheritdoc
     */
    public function canRespondToRequest(ServerRequestInterface $request)
    {
        return (
            isset($request->getParsedBody()['grant_type'])
            && $request->getParsedBody()['grant_type'] === $this->getIdentifier()
        );
    }
}
