"""One bounded GitHub Actions execution; emits no source URLs, bodies or secrets."""
import json
import os
import socket
import sys
import uuid
from engine import Blocked, Yield, SafeHTTP, collect_work
from protocol import Client, Cancelled, TransportError, key_bytes

def main(client_factory=Client, http_factory=SafeHTTP):
    job_id = os.environ.get("MBI2_JOB_ID", "")
    uuid.UUID(job_id)
    client = client_factory(os.environ["MBI2_WORDPRESS_ENDPOINT"], key_bytes(os.environ["MBI2_HMAC_KEY_B64"]), job_id)
    generation = os.environ.get("MBI2_GENERATION", "")
    if generation:
        uuid.UUID(generation)
        client.generation = generation
    job = client.get()
    client.event("claim")
    checkpoint = job.get("checkpoint", {})
    source_host = __import__("urllib.parse", fromlist=["urlsplit"]).urlsplit(job["source_url"]).hostname
    allowed_sources = {"mangalivre.blog", "www.mangalivre.blog", "mangalivre.to", "www.mangalivre.to"}
    media = {h.strip().lower() for h in os.environ.get("MBI2_ALLOWED_MEDIA_HOSTS", "").split(",") if h.strip()}
    media |= allowed_sources
    http = http_factory(allowed_sources, media, heartbeat=lambda: client.event("progress", checkpoint))
    def progress(value):
        nonlocal checkpoint
        checkpoint = value
        client.event("progress", checkpoint)
    try:
        if source_host not in allowed_sources:
            raise Blocked("unsupported_source")
        result = collect_work(job["source_url"], http, checkpoint, progress, publication=job.get("publish_on_ready") is True)
        client.event("ready", result)
        print(json.dumps({"event": "wordpress_result_received", "job_id": job_id, "source_http": http.requests, "worker_http": 0, "published": 0}))
    except (Yield, TimeoutError, ConnectionError, socket.gaierror, OSError):
        client.event("waiting_origin", checkpoint, "network_or_resource_wait")
        print(json.dumps({"event": "waiting_origin", "job_id": job_id, "source_http": http.requests, "worker_http": 0}))
    except (Blocked, ValueError, KeyError, json.JSONDecodeError) as error:
        code = str(error) if isinstance(error, Blocked) else "source_structure_error"
        client.event("blocked", checkpoint, code)
        print(json.dumps({"event": "blocked", "job_id": job_id, "source_http": http.requests, "worker_http": 0, "reason": code}))

if __name__ == "__main__":
    try:
        main()
    except Cancelled:
        print('{"event":"cancelled_or_superseded","published":0}')
    except (TransportError, ValueError, KeyError, OSError):
        print('{"event":"transport_or_configuration_failure","published":0}')
        sys.exit(1)
