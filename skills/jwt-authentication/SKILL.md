---
name: jwt-authentication
description: >
  Use when implementing stateless JWT authentication in Symfony with LexikJWTAuthenticationBundle —
  login endpoint, token issuance, refresh tokens, custom claims, and the JWT firewall. Use when
  the task mentions JWT, bearer token, access token, refresh token, or stateless API auth.
---

# JWT Authentication (Symfony + LexikJWTAuthenticationBundle)

## Setup

```bash
composer require lexik/jwt-authentication-bundle
php bin/console lexik:jwt:generate-keypair        # writes config/jwt/private.pem + public.pem
```

```yaml
# config/packages/lexik_jwt_authentication.yaml
lexik_jwt_authentication:
    secret_key: '%env(resolve:JWT_SECRET_KEY)%'
    public_key: '%env(resolve:JWT_PUBLIC_KEY)%'
    pass_phrase: '%env(JWT_PASSPHRASE)%'
    token_ttl: 900                                # 15 min access token — keep it SHORT
```

The private key and passphrase are secrets — store them in the Symfony secrets vault, never commit them.

## Firewall

```yaml
# config/packages/security.yaml
security:
    firewalls:
        login:
            pattern: ^/api/login$
            stateless: true
            json_login:
                check_path: /api/login          # POST { "email": "...", "password": "..." }
                username_path: email
                password_path: password
                success_handler: lexik_jwt_authentication.handler.authentication_success
                failure_handler: lexik_jwt_authentication.handler.authentication_failure
        api:
            pattern: ^/api
            stateless: true
            jwt: ~                               # validates the Bearer token

    access_control:
        - { path: ^/api/login,   roles: PUBLIC_ACCESS }
        - { path: ^/api/token/refresh, roles: PUBLIC_ACCESS }
        - { path: ^/api,         roles: ROLE_USER }
```

`json_login` + the success handler issues the token; the `jwt` authenticator validates it on every subsequent request. Both firewalls are **stateless** — no session.

## Login response

`POST /api/login` with credentials returns:

```json
{ "token": "eyJ0eXAiOiJKV1Qi..." }
```

The client sends it back as `Authorization: Bearer <token>` on protected requests.

## Short access tokens + refresh tokens

Access tokens must be **short-lived** (5–15 min) because they can't be revoked before expiry. For "stay logged in", pair them with a **refresh token** (`gesdinet/jwt-refresh-token-bundle`):

```yaml
# config/packages/gesdinet_jwt_refresh_token.yaml
gesdinet_jwt_refresh_token:
    refresh_token_class: App\Entity\RefreshToken
    ttl: 2592000                                 # 30 days
    single_use: true                             # rotate on each refresh
```

```yaml
# routes
api_refresh_token:
    path: /api/token/refresh
    controller: gesdinet.jwtrefreshtoken::refresh
```

Refresh tokens are stored server-side, so they **can** be revoked (logout, password change, compromise). `single_use: true` rotates them, limiting replay.

## Custom claims

Add claims (e.g. roles, tenant) at issuance via an event listener; read them later without a DB hit.

```php
#[AsEventListener(event: Events::JWT_CREATED)]
final class JwtCreatedListener
{
    public function __invoke(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User) {
            $payload = $event->getData();
            $payload['uid'] = $user->getId()->toRfc4122();
            $payload['tenant'] = $user->getTenantId();
            $event->setData($payload);
        }
    }
}
```

Keep the payload small and **never put secrets or sensitive data in a JWT** — it's base64, not encrypted; anyone holding it can read it.

## Reading the authenticated user

```php
#[Route('/api/me', methods: ['GET'])]
public function me(#[CurrentUser] User $user): JsonResponse
{
    return $this->json(UserResponse::fromEntity($user));
}
```

## Security conventions

- Always serve over **HTTPS** — a bearer token on plain HTTP is a credential in the clear.
- Short access TTL + rotating refresh tokens; revoke refresh tokens on logout/password change.
- Validate `exp`/`iat` (Lexik does this) — don't disable signature/expiry checks.
- Don't store JWTs in `localStorage` for browser SPAs if you can use an `HttpOnly` cookie (XSS risk).
- 401 on missing/expired/invalid token; 403 on valid token lacking the role.

## Gotchas

- Agent sets a long access-token TTL (hours/days) — keep access tokens short; use refresh tokens for longevity.
- Agent uses only access tokens and tries to "revoke" them — access JWTs can't be revoked before expiry; use server-side refresh tokens.
- Agent puts sensitive data in the JWT payload — it's readable; only put non-secret claims.
- Agent commits `private.pem` / the passphrase — store keys/passphrase in the secrets vault.
- Agent makes the JWT firewall stateful — set `stateless: true`.
- Agent reads the user from a request field instead of `#[CurrentUser]` / the token.
- Agent disables expiry or signature validation to "make it work" — never bypass token validation.
- Agent uses Lexik 2.x annotation/config — use the current bundle config + `json_login` (Symfony 7).
