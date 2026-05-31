# WP AI Gateway

OpenAI-compatible AI gateway for WordPress, backed by the WordPress AI Client.

WP AI Gateway lets external clients use a WordPress site as an AI provider endpoint. The site owns authentication, model routing, and provider credentials; clients only receive a scoped gateway token.

```text
OpenCode / external clients
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

- Exposes OpenAI-compatible `/models` and `/chat/completions` REST endpoints.
- Authenticates external clients with a site-issued bearer token.
- Routes `site-default` to the provider/model configured on the WordPress site.
- Resolves provider API keys from Connectors-style options, constants, environment variables, or the `wp_ai_gateway_provider_api_key` filter.
- Preserves provider-supplied authentication when a provider, such as Codex, owns its own OAuth or request-auth flow.

## Endpoints

```text
GET  /wp-json/wp-ai-gateway/v1/models
POST /wp-json/wp-ai-gateway/v1/chat/completions
```

The `/models` response always includes `site-default`, plus provider-qualified aliases discovered from the site's registered WordPress AI Client providers when model discovery succeeds.

External model aliases use this shape:

```text
provider:model-id
```

For example:

```text
opencode:opencode-go/kimi-k2.6
```

## Setup

Install and activate the plugin on a WordPress 7.0+ site.

Configure the site-default route:

```bash
wp ai-gateway configure opencode opencode-go/kimi-k2.6
```

Generate a gateway bearer token:

```bash
wp ai-gateway token
```

Store the printed token in the external client. It is stored on the site as a SHA-256 hash and is not shown again.

For automation that needs the token value without WP-CLI's success message, use:

```bash
wp ai-gateway token --porcelain
```

Check setup status without exposing secret values:

```bash
wp ai-gateway status --format=json
```

Example status shape:

```json
{
  "configured": true,
  "provider": "codex",
  "model": "gpt-5.5",
  "token_hash_exists": true,
  "ai_client_available": true,
  "registered_providers": ["codex"],
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
- Environment variable, e.g. `OPENCODE_API_KEY`
- Constant, e.g. `OPENCODE_API_KEY`
- Connectors-style option, e.g. `connectors_ai_opencode_api_key`

This means a site with `ai-provider-for-opencode` and `connectors_ai_opencode_api_key` configured can expose OpenCode Go through `site-default` without giving the upstream OpenCode key to the external client.

Providers that supply their own request authentication, such as a Codex OAuth-capable provider, can be configured without `CODEX_API_KEY`, `OPENAI_API_KEY`, or another gateway-managed API key:

```bash
wp ai-gateway configure codex gpt-5.5
```

In that path the gateway validates only the external bearer token, then dispatches to the provider registry without injecting API-key authentication.

## Smoke Tests

This repository includes a lightweight PHP smoke test that stubs the WordPress and AI Client surfaces used by the plugin:

```bash
php -l plugin.php
php -l tests/smoke.php
php tests/smoke.php
```

The smoke covers missing bearer token, invalid bearer token, unconfigured provider/model, `/models` shape, machine-readable status, and the codex-without-API-key dispatch path with a fake provider registry.

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

- Bearer-token auth
- `site-default` model routing
- OpenAI-compatible text chat responses
- WordPress AI Client provider dispatch

Future work:

- Usage metering
- Budgets and quotas
- Multiple client tokens
- Streaming
- Admin UI for token rotation
- Rich multimodal message support
- Per-client model allowlists

## AI Assistance

This initial plugin scaffold was drafted with AI assistance using OpenCode (GPT-5.5), then reviewed and directed by Chris Huber.
