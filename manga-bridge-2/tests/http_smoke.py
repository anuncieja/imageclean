"""Actual signed Python -> PHP -> WordPress -> MariaDB round trip, loopback CI only."""
import base64
import os
from pathlib import Path
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "engine"))
from protocol import Client, TransportError, key_bytes

checks = 0
def check(condition):
    global checks
    if not condition:
        raise AssertionError("HTTP integration contract failed")
    checks += 1

job_id = Path("/tmp/mbi2-http-job-id").read_text().strip()
endpoint = "http://127.0.0.1:8085/wp-json/mbi2/v1"
key = key_bytes(os.environ["MBI2_HMAC_KEY_B64"])
client = Client(endpoint, key, job_id, allow_loopback=True)
job = client.get()
check(job["state"] == "queued" and job["published"] == 0)
client.event("claim")
job = client.get()
claim_revision = job["revision"]
check(job["state"] == "running" and job["attempts"] == 1)
client.event("progress", {"checked": 7, "worker_http": 0})
check(client.get()["checkpoint"]["checked"] == 7)
client.event("ready", {"complete": True, "test_fixture": True})
ready = client.get()
check(ready["state"] == "ready" and ready["published"] == 0)
check(ready["revision"] == claim_revision + 2)
bad = Client(endpoint, b"z" * 32, job_id, allow_loopback=True)
try:
    bad.get()
    raise AssertionError("Invalid signature accepted")
except TransportError as error:
    check(str(error) == "callback_http_401")
print(f"HTTP communication checks: {checks}; actual PHP/WordPress/MariaDB round trips; published=0")
