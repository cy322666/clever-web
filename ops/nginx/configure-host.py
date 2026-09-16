#!/usr/bin/env python3
"""Update the managed host-nginx include for deployment or code rollback."""
import argparse
from pathlib import Path
import subprocess


def render(project: Path) -> str:
    template = project / "ops/nginx/clever-web-app.conf.template"
    if template.is_file():
        app_root = str(project.resolve() / "application")
        if any(char in app_root for char in ['"', "$", "\\", "\n", "\r"]):
            raise ValueError("Project path contains unsupported nginx characters")
        return template.read_text().replace("__APPLICATION_ROOT__", app_root)

    # Revisions predating native FastCGI expose artisan serve or web on 8080.
    return """client_max_body_size 200m;
location / {
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection $connection_upgrade;
    proxy_pass http://127.0.0.1:8080;
}
"""


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--project-path", type=Path, required=True)
    parser.add_argument("--render-only", action="store_true")
    args = parser.parse_args()
    content = render(args.project_path)
    if args.render_only:
        print(content, end="")
        return

    target = Path("/etc/nginx/snippets/clever-web-app.conf")
    if not target.is_file():
        raise RuntimeError("Provision the app server include before deploying native FPM")
    previous = target.read_text()
    try:
        target.write_text(content)
        subprocess.run(["nginx", "-t"], check=True)
        subprocess.run(["systemctl", "reload", "nginx"], check=True)
    except Exception:
        target.write_text(previous)
        subprocess.run(["nginx", "-t"], check=True)
        subprocess.run(["systemctl", "reload", "nginx"], check=True)
        raise


if __name__ == "__main__":
    main()
