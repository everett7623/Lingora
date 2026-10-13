# External service ledger

Checked 2026-09-24 for release documentation. URLs are informational metadata for administrators; the plugin does not rely on them for transport.

| Provider | Service | Terms | Privacy | Status |
| --- | --- | --- | --- | --- |
| OpenAI | https://openai.com | https://openai.com/policies/business-terms/ | https://openai.com/policies/privacy-policy | URL reachable from release environment; API model catalog remains account-dependent |
| Anthropic | https://www.anthropic.com | https://www.anthropic.com/legal/commercial-terms | https://www.anthropic.com/legal/privacy | Terms URL reachable; privacy endpoint may deny automated requests, so administrator should verify current page |
| DeepSeek | https://www.deepseek.com | https://cdn.deepseek.com/policies/zh-CN/deepseek-terms-of-use.html | https://cdn.deepseek.com/policies/zh-CN/deepseek-privacy-policy.html | Both official CDN policy pages returned HTTP 200 during release check |
| Google Gemini API | https://ai.google.dev | https://ai.google.dev/gemini-api/terms | https://policies.google.com/privacy | Both pages returned HTTP 200 during release check |
| xAI | https://x.ai | https://x.ai/legal/terms-of-service-enterprise | https://x.ai/legal/privacy-policy | Terms URL returned HTTP 200; privacy page denied automated fetch; administrator should verify current page |
| Dots AI | unknown | unknown | unknown | No project-verified public legal URLs; UI returns null metadata |
| Sub2API | site-admin gateway | site-admin gateway | site-admin gateway | Self-hosted or operator-selected; UI returns null metadata |
| Custom gateway | site-admin gateway | site-admin gateway | site-admin gateway | URL and policy are provided by the administrator; UI returns null metadata |

## Data sent

With explicit consent and valid credentials, the selected provider receives the public source text or selected public attribute, target language, model ID, and translation prompt. Background requests use the same boundary. Credentials are sent only as HTTP authentication headers to the configured HTTPS authority; redirects are disabled and response bodies are capped.

The plugin does not send content when AI consent is false. It does not intentionally collect drafts, private/password-protected posts, authenticated output, admin/REST/AJAX pages, account/cart/checkout requests, dynamic shortcode output, or scanner content from a withdrawn source. Existing dictionary data stays local unless an administrator invokes an AI operation for it.
