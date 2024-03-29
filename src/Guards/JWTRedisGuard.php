<?php

namespace Chantouch\JWTRedis\Guards;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Chantouch\JWTRedis\Facades\RedisCache;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

class JWTRedisGuard extends JWTGuard implements Guard
{
    /**
     * @OVERRIDE!
     *
     * Log a user into the application using their credentials.
     *
     * @param array $credentials
     *
     * @return bool
     */
    public function once(array $credentials = [])
    {
        if ($this->validate($credentials)) {
            $this->setUser($this->lastAttempted);

            $this->storeRedis(true);

            return true;
        }

        return false;
    }

    /**
     * @OVERRIDE!
     *
     * Get the currently authenticated user.
     *
     * !Important; Made some changes this method for check authed user without db query.
     *
     * @return Authenticatable|null
     */
    public function user(): ?Authenticatable
    {
        return $this->user ?? $this->retrieveByRedis();
    }

    /**
     * @OVERRIDE!
     *
     * Attempt to authenticate the user using the given credentials and return the token.
     *
     * !Important; Made some changes this method for banned user can't get token.
     *
     * @param array $credentials
     * @param bool  $login
     *
     * @throws AuthorizationException
     *
     * @return bool|string
     */
    public function attempt(array $credentials = [], $login = true)
    {
        $this->removeAuthFromRedis();

        $this->lastAttempted = $user = $this->provider->retrieveByCredentials($credentials);

        if ($this->hasValidCredentials($user, $credentials)) {
            if (config('jwt-redis.check_banned_user')) {
                if (!$user->checkUserStatus()) {
                    throw new AuthorizationException('Your account has been blocked by the administrator.');
                }
            }

            $this->refreshAuthFromRedis($user);

            return $login ? $this->login($user) : true;
        }

        return false;
    }

    /**
     * @return mixed
     */
    public function retrieveByRedis()
    {
        return $this->request->authedUser ?? $this->getOrSetRedis();
    }

    /**
     * @return mixed
     */
    public function getOrSetRedis()
    {
        return $this->getAuthFromRedis() ?? $this->setAuthToRedis();
    }

    /**
     * @return mixed
     */
    public function getAuthFromRedis()
    {
        return RedisCache::key($this->getRedisKeyFromClaim())->getCache();
    }

    /**
     * @param $user
     * @return mixed
     */
    public function refreshAuthFromRedis($user)
    {
        return RedisCache::key($user->getRedisKey())->data($user)->refreshCache();
    }

    /**
     * @return mixed
     */
    public function removeAuthFromRedis()
    {
        return RedisCache::key($this->getRedisKeyFromClaim())->removeCache();
    }

    /**
     * @return string
     */
    public function getRedisKeyFromClaim()
    {
        return 'auth_'.$this->request->claim;
    }

    /**
     * @return mixed
     */
    public function setAuthToRedis()
    {
        if ($this->request->bearerToken()) {
            return $this->storeRedis();
        }

        // If token not found, we need to return null.
        // Because Laravel needs this user object even if empty.
        return null;
    }

    /**
     * @param bool $login
     *
     * @return mixed
     */
    public function storeRedis(bool $login = false)
    {
        // If is Login value true, user cached from lastAttempt object.
        // else user cached from token in request object.
        if (!$login) {
            return RedisCache::key($this->getRedisKeyFromClaim())
                ->data(JWTAuth::parseToken()->authenticate()->load(config('jwt-redis.cache_relations')))
                ->cache();
        }

        return RedisCache::key($this->lastAttempted->getRedisKey())->data($this->lastAttempted)->cache();
    }
}
