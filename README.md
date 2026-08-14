# WP AI Gateway

OpenAI-compatible AI gateway for WordPress, backed by the WordPress AI Client.

WP AI Gateway lets external clients use a WordPress site as an AI provider endpoint. The site owns authentication, model routing, and provider credentials; clients only receive a scoped gateway token.

```text
Any OpenAI-compatible client
        |
        v
WP AI Gateway
        |
        v
WordPress AI Client
        |
        v
Configured provider and model
```

## Requirements

- WordPress 7.0 or newer, including the bundled WordPress AI Client
- PHP 7.4 or newer
- At least one WordPress AI Client provider plugin configured on the site

## What It Does

- Exposes OpenAI-compatible `/models`, `/chat/completions`, and `/embeddings` REST endpoints.
- Authenticates external clients with independently revocable site-issued bearer tokens.
- Supports short-lived, WordPress-user-bound runtime credentials limited to `site-default`.
- Routes `site-default` to the provider/model configured on the WordPress site.
- Resolves provider API keys from Connectors-style options, constants, environment variables, or the `wp_ai_gateway_provider_api_key` filter.
- Preserves provider-supplied authentication when a provider owns its own request-auth flow.

## Architecture

The plugin is intentionally generic. It does not know which OpenAI-compatible client is calling it, and it does not special-case any provider plugin.

```text
plugin.php
  |
  v
Plugin bootstrap
  |-- RestController       OpenAI-compatible REST routes
  |-- TokenAuthenticator   Scoped client minting, policy, and validation
  |-- ProviderRouter       site-default and provider:model-id routing
  |-- AiClientBridge       WordPress AI Client registry/model dispatch
  |-- OpenAiResponse       OpenAI-compatible payload/error helpers
  |-- SettingsPage         wp-admin provider/model settings
  `-- CliCommand           wp ai-gateway configure/token/status
```

Core files:

- `plugin.php` loads the plugin and registers hooks.
- `inc/constants.php` defines option names, REST namespace, and `site-default`.
- `inc/class-rest-controller.php` owns `/models` and `/chat/completions`.
- `inc/class-token-authenticator.php` owns external client bearer-token behavior.
- `inc/class-provider-router.php` resolves `site-default` and `provider:model-id` model names.
- `inc/class-ai-client-bridge.php` adapts normalized requests to WordPress AI Client.
- `inc/class-openai-response.php` keeps response and error shapes OpenAI-compatible.
- `inc/class-settings-page.php` owns the minimal wp-admin settings page.
- `inc/class-cli-command.php` owns automation-friendly WP-CLI setup/status commands.

## Endpoints

```text
GET  /wp-json/wp-ai-gateway/v1/models
POST /wp-json/wp-ai-gateway/v1/chat/completions
POST /wp-json/wp-ai-gateway/v1/embeddings
```

The `/models` response always includes `site-default`, plus provider-qualified aliases discovered from the site's registered WordPress AI Client providers when model discovery succeeds. Discovered provider models include provider-neutral `capabilities` and `gateway_metadata` fields so retrieval workflows can identify `embedding_generation` support without knowing provider-specific model shapes.

Embedding requests use the same `site-default` and `provider:model-id` routing as chat completions. Execution requires an upstream WordPress AI Client embedding generation result API. Until that provider-neutral upstream API is available, the gateway returns an OpenAI-shaped `501 unsupported_capability` response instead of making provider-specific HTTP calls.

External model aliases use this shape:

```text
provider:model-id
```

For example:

```text
example-provider:example-model
```

## Setup

Install and activate the plugin on a WordPress 7.0+ site.

Configure the site-default route:

```bash
wp ai-gateway configure example-provider example-model
```

Generate or rotate the trusted legacy site-wide bearer token:

```bash
wp ai-gateway token
```

Store the printed token in the trusted external client. It is stored on the site as a SHA-256 hash and is not shown again. Running this command again invalidates the previous legacy token.

For automation that needs the token value without WP-CLI's success message, use:

```bash
wp ai-gateway token --porcelain
```

For a hosted or ephemeral runtime, issue a constrained credential instead:

```bash
wp ai-gateway runtime-token --user=123 --expires-in=3600 --label=runtime-123 --porcelain
```

Runtime credentials are independently revocable, bound to an existing WordPress user, limited to `site-default`, and expire after one hour by default. The plaintext token is returned once; only its SHA-256 hash is stored.

List non-secret client metadata, rotate one client, or revoke one client without affecting others:

```bash
wp ai-gateway clients --format=json
wp ai-gateway rotate <client-id> --expires-in=3600 --porcelain
wp ai-gateway revoke <client-id>
```

When a scoped credential authenticates, the gateway establishes its bound WordPress user and fires `wp_ai_gateway_client_authenticated` with a non-secret principal. Provider integrations can use that hook to bind user-owned provider authentication for the request.

Check setup status without exposing secret values:

```bash
wp ai-gateway status --format=json
```

Example status shape:

```json
{
  "configured": true,
  "provider": "example-provider",
  "model": "example-model",
  "token_hash_exists": true,
  "client_count": 1,
  "ai_client_available": true,
  "registered_providers": ["example-provider"],
  "provider_registered": true,
  "endpoints": {
    "models": "https://example.com/wp-json/wp-ai-gateway/v1/models",
    "chat_completions": "https://example.com/wp-json/wp-ai-gateway/v1/chat/completions"
  }
}
```

## Provider Credentials

WP AI Gateway binds provider API keys before dispatching through WordPress AI Client only when an API key is available from the gateway's credential sources.

Credential resolution order:

- `wp_ai_gateway_provider_api_key` filter
- Environment variable, e.g. `EXAMPLE_PROVIDER_API_KEY`
- Constant, e.g. `EXAMPLE_PROVIDER_API_KEY`
- Connectors-style option, e.g. `connectors_ai_example_provider_api_key`

This means a site with a provider plugin and matching credential source configured can expose that provider through `site-default` without giving the upstream provider credential to the external client.

Providers that supply their own request authentication can be configured without a gateway-managed API key:

```bash
wp ai-gateway configure example-provider example-model
```

In that path the gateway validates only the external bearer token, then dispatches to the provider registry without injecting API-key authentication.

## Smoke Tests

This repository includes a lightweight PHP smoke test that stubs the WordPress and AI Client surfaces used by the plugin:

```bash
php -l plugin.php
php -l tests/smoke.php
php tests/smoke.php
```

The smoke covers missing bearer token, invalid bearer token, unconfigured provider/model, `/models` shape with embedding metadata, `site-default` routing, provider-qualified model routing, embedding response usage/request metadata, machine-readable status, and provider-supplied auth without gateway API-key injection.

## OpenAI-Compatible Example

```bash
curl https://example.com/wp-json/wp-ai-gateway/v1/chat/completions \
  -H "Authorization: Bearer $WP_AI_GATEWAY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "site-default",
    "messages": [
      { "role": "user", "content": "Say hello from WordPress." }
    ]
  }'
```

## Scope

This is intentionally small for the first version.

In scope now:

- Legacy site-wide bearer-token auth
- Multiple scoped client credentials with expiry, revocation, rotation, user binding, and model allowlists
- `site-default` model routing
- OpenAI-compatible text chat responses
- OpenAI-compatible embedding request routing surface
- Provider-neutral model capability and retrieval metadata
- WordPress AI Client provider dispatch

Future work:

- Full embedding execution once WordPress AI Client exposes provider-neutral embedding results
- Usage metering beyond provider-returned usage metadata
- Budgets and quotas
- Streaming
- Admin UI for token rotation
- Rich multimodal message support

## AI Assistance

This initial plugin scaffold was drafted with AI assistance using GPT-5.5, then reviewed and directed by Chris Huber.
