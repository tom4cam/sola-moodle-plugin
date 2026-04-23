# SOLA xAI Realtime WebSocket Proxy

Standalone Node.js service that mediates xAI Realtime voice connections so the xAI master API key never reaches the browser.

## Why this exists

xAI Realtime is a WebSocket protocol and xAI does not issue ephemeral client secrets. Without this proxy the Moodle plugin would have to hand the master API key to every learner's browser (documented in the SOLA security audit as critical defect 3.1). This proxy closes that defect.

## Data flow

```
Browser              Moodle                  Proxy                 xAI
   |                   |                       |                     |
   |-- GET /rt_token -->|                       |                     |
   |<--- {jwt,wssurl}---|                       |                     |
   |                                            |                     |
   |--- WSS /rt?token=<jwt> -------------------->|                     |
   |                                            |--- WSS /v1/realtime->|
   |                                            |         (Bearer      |
   |                                            |          API key)    |
   |<== audio frames relayed ====================>|<== audio frames ===>|
```

The browser never sees the xAI key. Moodle never holds a long-lived xAI session. The proxy holds the key in its own env var.

## Install

```
sudo adduser --system --group --home /opt/sola-xai-rt-proxy sola
sudo mkdir -p /opt/sola-xai-rt-proxy
sudo cp server.js package.json /opt/sola-xai-rt-proxy/
sudo chown -R sola:sola /opt/sola-xai-rt-proxy
sudo -u sola bash -c 'cd /opt/sola-xai-rt-proxy && npm install --omit=dev'
sudo cp sola-xai-rt-proxy.service /etc/systemd/system/
sudo install -m 600 -o root -g root env.example /etc/sola-xai-rt-proxy.env
sudo $EDITOR /etc/sola-xai-rt-proxy.env   # fill in XAI_API_KEY and MOODLE_JWT_SECRET
sudo systemctl daemon-reload
sudo systemctl enable --now sola-xai-rt-proxy
```

## Environment file

`/etc/sola-xai-rt-proxy.env`:

```
XAI_API_KEY=xai-...
MOODLE_JWT_SECRET=<random 32+ byte hex string; match SOLA admin setting>
PROXY_LISTEN_PORT=8787
PROXY_LISTEN_HOST=127.0.0.1
LOG_LEVEL=info
```

The Moodle plugin settings must carry the same `MOODLE_JWT_SECRET` under `xai_proxy_jwt_secret`, and `xai_proxy_url` must point at the public wss URL that fronts this service (usually behind nginx with TLS termination).

## Nginx front

Typical fronting with nginx so the browser talks TLS to your domain:

```
location /xai-rt/ {
    proxy_pass http://127.0.0.1:8787/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 1h;
}
```

Then `xai_proxy_url` in SOLA settings is `wss://your-host/xai-rt/rt`.

## Health

`GET /health` returns `{"ok":true,"upstream":"wss://api.x.ai/v1/realtime"}`. Wire into your monitoring.

## Key rotation

1. Generate a new `MOODLE_JWT_SECRET`.
2. Update the SOLA admin setting first.
3. Update `/etc/sola-xai-rt-proxy.env` and `systemctl restart sola-xai-rt-proxy`.

A brief ~60 second window of "unauthorized" responses is expected while the two sides converge.

## Troubleshooting

- 401 on connect: JWT secret mismatch, expired token, or token replay. Check `journalctl -u sola-xai-rt-proxy -f` for the `auth_reject` reason code.
- Upstream error: check `XAI_API_KEY` validity and xAI status page.
- Learner sees voice disabled: confirm `xai_proxy_url` is set in SOLA settings and that the health endpoint returns 200.
