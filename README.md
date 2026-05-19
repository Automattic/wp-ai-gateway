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

## Provider Credentials

WP AI Gateway binds provider credentials before dispatching through WordPress AI Client.

Credential resolution order:

- `wp_ai_gateway_provider_api_key` filter
- Environment variable, e.g. `OPENCODE_API_KEY`
- Constant, e.g. `OPENCODE_API_KEY`
- Connectors-style option, e.g. `connectors_ai_opencode_api_key`

This means a site with `ai-provider-for-opencode` and `connectors_ai_opencode_api_key` configured can expose OpenCode Go through `site-default` without giving the upstream OpenCode key to the external client.

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
