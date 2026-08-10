#!/bin/sh
set -eu

cert_dir="${1:-certs}"
cert_file="$cert_dir/localhost.pem"
key_file="$cert_dir/localhost-key.pem"

if [ -f "$cert_file" ] && [ -f "$key_file" ]; then
  echo "Local HTTPS certificate already exists in $cert_dir."
  exit 0
fi

if ! command -v mkcert >/dev/null 2>&1; then
  echo "mkcert is required to generate a trusted local HTTPS certificate." >&2
  echo "Install it with: brew install mkcert nss" >&2
  echo "Then run: mkcert -install" >&2
  exit 1
fi

mkdir -p "$cert_dir"

if ! mkcert -install; then
  echo "Could not install the mkcert local CA into the system trust store automatically." >&2
  echo "Run this once in a normal terminal if the browser does not trust the certificate: mkcert -install" >&2
fi

mkcert \
  -cert-file "$cert_file" \
  -key-file "$key_file" \
  localhost 127.0.0.1 ::1

echo "Generated local HTTPS certificate in $cert_dir."
