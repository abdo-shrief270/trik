# Edge and proxy setup

> **The live configuration is [`infra/edge/Caddyfile`](../../infra/edge/Caddyfile)** (the `trik-edge` image,
> see [deploy.md](deploy.md)). There the edge is its own container on a fixed address and the API trusts only
> it: `TRUSTED_PROXIES=172.30.0.10`. The principles below still apply; the snippets are the original sketch.

Traffic path: **Cloudflare → Caddy (same host as the app, TLS) → Octane on 127.0.0.1:8080**.

The app trusts `X-Forwarded-*` only from `TRUSTED_PROXIES` (default `127.0.0.1,::1`). This matters:

- If the proxy isn't trusted, every user shares the proxy's IP, so rate limits and lockouts hit everyone at once.
- If everyone is trusted, clients can fake their IP.

## Caddy

```caddyfile
{
    servers {
        # real client IP from Cloudflare, only when the request comes from Cloudflare
        trusted_proxies static 173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22 141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20 197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13 104.24.0.0/14 172.64.0.0/13 131.0.72.0/22
        client_ip_headers CF-Connecting-IP
    }
}

api.trik.chat {
    request_body {
        max_size 2MB
    }
    @webhooks path /webhooks/*
    request_body @webhooks {
        max_size 1MB
    }
    reverse_proxy 127.0.0.1:8080   # sets X-Forwarded-For/Proto/Host from the client IP Caddy resolved
    header Strict-Transport-Security "max-age=31536000; includeSubDomains"
}
```

- Re-check the Cloudflare ranges against https://www.cloudflare.com/ips/ when setting up (they change rarely).
- The app port (8080) listens on 127.0.0.1 only, so nothing reaches it without Caddy.
- Firewall: 443 open; everything else on the WireGuard network (#360).
