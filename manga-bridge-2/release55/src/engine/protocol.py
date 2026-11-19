"""Wire protocol shared with the WordPress publisher."""
import base64
import hashlib
import hmac
import json
import secrets
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

MAX_BODY = 262144

def key_bytes(encoded):
    key = base64.b64decode(encoded, validate=True)
    if len(key) != 32:
        raise ValueError("invalid_key")
    return key

def signature(key, method, route, timestamp, nonce, body):
    canonical = "\n".join(("mbi2.v1", method, route, str(timestamp), nonce, hashlib.sha256(body).hexdigest()))
    return hmac.new(key, canonical.encode(), hashlib.sha256).hexdigest()

class Cancelled(Exception):
    pass

class TransportError(Exception):
    pass

class Client:
    resource = "jobs"
    maximum = MAX_BODY
    def __init__(self, endpoint, key, job_id, allow_loopback=False):
        part = urllib.parse.urlsplit(endpoint)
        local_test = allow_loopback and part.hostname in ("127.0.0.1", "localhost")
        if part.username or part.password or part.query or part.fragment or (part.scheme != "https" and not local_test):
            raise ValueError("invalid_endpoint")
        uuid.UUID(job_id)
        self.endpoint = endpoint.rstrip("/")
        self.key, self.job_id = key, job_id
        self.generation = None
        self.attempt = str(uuid.uuid4())
        self.revision = None

    def request(self, method, suffix="", data=None):
        route = self.resource + "/" + self.job_id + suffix
        body = b"" if data is None else json.dumps(data, ensure_ascii=False, separators=(",", ":")).encode()
        if len(body) > self.maximum:
            raise TransportError("checkpoint_too_large")
        stamp, nonce = str(int(time.time())), secrets.token_hex(16)
        headers = {"X-MBI2-Timestamp": stamp, "X-MBI2-Nonce": nonce,
                   "X-MBI2-Signature": signature(self.key, method, route, stamp, nonce, body),
                   "Content-Type": "application/json", "User-Agent": "MangaBridge-GitHub/1.0"}
        # No request/response bodies, endpoint URLs or authorization values are logged.
        request = urllib.request.Request(self.endpoint + "/" + route, data=body if method == "POST" else None, headers=headers, method=method)
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, hdrs, newurl):
                raise TransportError("callback_redirect_refused")
        try:
            with urllib.request.build_opener(NoRedirect()).open(request, timeout=12) as response:
                raw = response.read(self.maximum + 1)
            if len(raw) > self.maximum:
                raise TransportError("response_too_large")
            result = json.loads(raw)
            if not isinstance(result, dict):
                raise TransportError("invalid_response")
            return result
        except urllib.error.HTTPError as exc:
            if exc.code == 409:
                raise Cancelled("revision_or_lease_changed") from None
            raise TransportError("callback_http_" + str(exc.code)) from None

    def get(self):
        job = self.request("GET")
        if job.get("state") == "cancelled":
            raise Cancelled("cancelled")
        if self.generation is None:
            self.generation = job["generation"]
        if job.get("generation") != self.generation:
            raise Cancelled("generation_changed")
        self.revision = job["revision"]
        return job

    def event(self, kind, checkpoint=None, reason=""):
        if self.revision is None:
            self.get()
        data = {"kind": kind, "generation": self.generation, "sequence": self.revision + 1,
                "attempt": self.attempt, "checkpoint": checkpoint or {}, "reason": reason}
        # A lost response gets the same sequence and payload. Only the first claim
        # can legitimately race WordPress recording the dispatch response.
        refreshed = False
        for n in range(3):
            try:
                result = self.request("POST", "/events", data)
                if result.get("id") != self.job_id or result.get("revision") != data["sequence"]:
                    raise TransportError("wrong_event_receipt")
                self.revision = result["revision"]
                return result
            except Cancelled:
                if kind != "claim" or refreshed or n == 2:
                    raise
                job = self.get()
                if job["state"] not in ("dispatching", "queued") or (job["state"] == "queued" and job["reason"] != "github_dispatched"):
                    raise
                data["sequence"] = self.revision + 1
                refreshed = True
            except (urllib.error.URLError, TimeoutError, ConnectionError):
                if n == 2:
                    raise TransportError("callback_unreachable") from None
                time.sleep(0.5 * (2 ** n))
