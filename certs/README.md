# Local Mercure TLS certificate (dev)

The dev Mercure hub serves **HTTPS on `localhost:3001`** so browsers and OBS negotiate **HTTP/2**.
HTTP/2 multiplexes every realtime stream over a single connection, which removes the HTTP/1.1
**~6-connections-per-host** limit - without it, opening several overlays/pages at once leaves SSE
connections stuck "pending" with no events.

The cert is generated locally with [mkcert](https://github.com/FiloSottile/mkcert) (a tiny tool that
creates a locally-trusted CA, so no browser warnings and the host-run API trusts it too).

## One-time setup

```bash
# 1. Install mkcert (see the mkcert README for your OS), then install its local CA:
mkcert -install

# 2. From the repo root, generate the Mercure cert into ./certs/ :
mkcert -cert-file certs/mercure.pem -key-file certs/mercure-key.pem localhost 127.0.0.1 ::1

# 3. (Re)create the Mercure container to pick up the cert:
docker compose up -d mercure
```

That's it. `https://localhost:3001/.well-known/mercure` is now served over HTTP/2 with a trusted cert.

## Notes

- The `.pem` files are gitignored - each developer generates their own.
- The dev URLs already point to `https://localhost:3001` (`api/.env`, `frontend/.env`). Restart the API
  and `pnpm dev` after the first setup so they pick up the new env.
- Prod is unaffected: it serves Mercure behind a real HTTPS domain (`docker-compose.prod.yml`), so it
  already uses HTTP/2.
- If you ever need to roll back to plain HTTP: set the three `MERCURE*URL` back to `http://localhost:3001`
  and remove the `tls …` line from the mercure service in `docker-compose.yml`.
```
