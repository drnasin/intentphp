<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent;

class IntentScaffold
{
    /**
     * @return array<string, string>  filename => YAML content
     */
    public function getFiles(): array
    {
        return [
            'intent.yaml' => $this->manifest(),
            'auth.yaml' => $this->auth(),
            'data.yaml' => $this->data(),
            'baselines.yaml' => $this->baselines(),
        ];
    }

    private function manifest(): string
    {
        return <<<'YAML'
# IntentPHP Spec v0.1
# This is the root manifest for your project's security invariants.
# See: https://github.com/drnasin/intentphp

version: "0.1"

project:
  name: example-app
  framework: laravel

includes:
  - auth.yaml
  - data.yaml
  - baselines.yaml

defaults:
  authMode: deny_by_default
  baselineRequireExpiry: true
  baselineExpiredIsError: true
YAML;
    }

    private function auth(): string
    {
        return <<<'YAML'
# Auth invariants — guards, roles, abilities, and route authorization rules.

auth:
  guards:
    web: session
    api: sanctum

  roles:
    admin: {}
    user: {}

  abilities:
    post.view: "View posts"
    post.edit: "Edit posts"

  rules:
    - id: auth.admin_area
      match:
        routes:
          prefix: "/admin"
      require:
        authenticated: true
        guard: web
        rolesAny: [admin]

    # Example: intentionally public endpoint
    # - id: auth.webhook
    #   match:
    #     routes:
    #       name: "webhook.*"
    #   require:
    #     public: true
    #     reason: "Webhook endpoint; verified by signature"
YAML;
    }

    private function data(): string
    {
        return <<<'YAML'
# Data invariants — model mass-assignment policies.

data:
  models:
    App\Models\Post:
      massAssignment:
        mode: explicit_allowlist
        allow: [title, body, status]
        forbid: [user_id]
YAML;
    }

    private function baselines(): string
    {
        return <<<'YAML'
# Baseline findings — tolerated security debt with mandatory expiry dates.
# Run `php artisan guard:intent validate` to check for expired entries.

baseline:
  findings: []
YAML;
    }
}
