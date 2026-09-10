#!/usr/bin/env python3
"""Reads the project's docker-compose.yml and serves a single page
linking to every service that publishes a web-facing port."""

import os
import re
import yaml
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

COMPOSE_PATH = "/compose/docker-compose.yml"
ENV_PATH = "/compose/.env"
SELF_SERVICE = "dashboard"

# Ports that belong to non-HTTP services (databases, directories, caches, ...)
# and shouldn't be offered as clickable links.
NON_HTTP_PORTS = {22, 21, 25, 53, 110, 123, 143, 389, 465, 587, 636, 993, 995,
                   3306, 5432, 5672, 6379, 9200, 9300, 11211, 27017}

FRIENDLY_NAMES = {
    "laravel.test": "Application",
    "phpmyadmin": "phpMyAdmin",
    "phpldapadmin": "phpLDAPadmin",
    "mysql": "MySQL",
    "redis": "Redis",
    "openldap": "OpenLDAP",
}

VAR_PATTERN = re.compile(r"\$\{([A-Za-z_][A-Za-z0-9_]*)(:-([^}]*))?\}")


def load_env_file(path):
    values = {}
    if not os.path.isfile(path):
        return values
    with open(path, "r") as handle:
        for line in handle:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, _, value = line.partition("=")
            values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def substitute_vars(text, env):
    def replace(match):
        name, _, default = match.groups()
        return env.get(name, os.environ.get(name, default or ""))

    return VAR_PATTERN.sub(replace, text)


def friendly_name(service_name):
    if service_name in FRIENDLY_NAMES:
        return FRIENDLY_NAMES[service_name]
    return service_name.replace(".", " ").replace("_", " ").replace("-", " ").title()


def host_port_from_mapping(mapping):
    mapping = str(mapping)
    # container_port[/protocol] is always the last segment.
    parts = mapping.split(":")
    if len(parts) < 2:
        return None  # container-only port, not published to the host
    host_port = parts[-2]
    try:
        return int(host_port)
    except ValueError:
        return None  # port ranges or non-numeric values aren't supported


def discover_services():
    env = {**load_env_file(ENV_PATH), **os.environ}

    with open(COMPOSE_PATH, "r") as handle:
        raw = handle.read()

    compose = yaml.safe_load(substitute_vars(raw, env)) or {}
    services = compose.get("services", {}) or {}

    discovered = []
    for name, config in services.items():
        if name == SELF_SERVICE or not isinstance(config, dict):
            continue

        for mapping in config.get("ports", []) or []:
            port = host_port_from_mapping(mapping)
            if port is None or port in NON_HTTP_PORTS:
                continue
            discovered.append((friendly_name(name), port))
            break  # one link per service is enough

    discovered.sort(key=lambda item: item[0].lower())
    return discovered


PAGE_TEMPLATE = """<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Project Dashboard</title>
<style>
  * {{ box-sizing: border-box; }}
  body {{
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    padding: 2rem;
  }}
  main {{
    width: 100%;
    max-width: 720px;
  }}
  h1 {{
    color: #fff;
    text-align: center;
    font-weight: 600;
    margin-bottom: 1.5rem;
  }}
  .grid {{
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
  }}
  a.card {{
    display: block;
    background: #fff;
    border-radius: 12px;
    padding: 1.25rem;
    text-decoration: none;
    color: #1f2937;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
  }}
  a.card:hover {{
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
  }}
  .name {{
    font-size: 1.1rem;
    font-weight: 600;
  }}
  .port {{
    margin-top: 0.25rem;
    font-size: 0.85rem;
    color: #6b7280;
  }}
  .empty {{
    text-align: center;
    color: #fff;
  }}
</style>
</head>
<body>
<main>
  <h1>Project Dashboard</h1>
  <div class="grid">
    {cards}
  </div>
</main>
</body>
</html>
"""

CARD_TEMPLATE = """<a class="card" href="http://localhost:{port}/" target="_blank" rel="noopener">
      <div class="name">{name}</div>
      <div class="port">localhost:{port}</div>
    </a>"""


def render_page():
    try:
        services = discover_services()
    except Exception as exc:  # keep the dashboard up even if compose is malformed
        return PAGE_TEMPLATE.format(
            cards=f'<p class="empty">Could not read docker-compose.yml: {exc}</p>'
        )

    if not services:
        cards = '<p class="empty">No web services found.</p>'
    else:
        cards = "\n    ".join(CARD_TEMPLATE.format(name=name, port=port) for name, port in services)

    return PAGE_TEMPLATE.format(cards=cards)


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path not in ("/", "/index.html"):
            self.send_response(404)
            self.end_headers()
            return

        body = render_page().encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format, *args):
        pass  # keep container logs quiet


if __name__ == "__main__":
    server = ThreadingHTTPServer(("0.0.0.0", 80), Handler)
    server.serve_forever()
