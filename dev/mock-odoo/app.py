import json
import os
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

HOST = "0.0.0.0"
PORT = 8069
USER_ID = int(os.environ.get("MOCK_ODOO_USER_ID", "42"))
DATA_DIR = Path("/data")
DATA_DIR.mkdir(parents=True, exist_ok=True)
REQUEST_LOG = DATA_DIR / "requests.jsonl"
FAILURE_MODE = "normal"

ROUTING_DATA = {
    "crm.stage": [
        {"id": 5, "name": "Website Enquiry"},
        {"id": 7, "name": "Programme Registration"},
        {"id": 9, "name": "Referral"},
    ],
    "utm.medium": [
        {"id": 3, "name": "Direct"},
        {"id": 6, "name": "Website"},
    ],
    "crm.tag": [
        {"id": 1, "name": "Website Enquiry"},
        {"id": 2, "name": "West Centre"},
        {"id": 4, "name": "Programme Registration"},
        {"id": 8, "name": "Youth Referral"},
    ],
}

ALLOWED_FAILURE_MODES = {
    "normal",
    "auth_reject",
    "invalid_json",
    "rpc_error",
    "field_error",
    "lead_zero",
    "http_500",
}


def rpc_result(request_id, result):
    return {"jsonrpc": "2.0", "id": request_id, "result": result}


def rpc_error(request_id, code, message, data=None):
    payload = {"jsonrpc": "2.0", "id": request_id, "error": {"code": code, "message": message}}
    if data is not None:
        payload["error"]["data"] = data
    return payload


def append_request(payload):
    record = {"received_at": datetime.now(timezone.utc).isoformat(), "payload": payload}
    with REQUEST_LOG.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(record, ensure_ascii=False) + "\n")


def request_summary():
    summary = {"total": 0, "authenticate": 0, "create": 0, "search_read": 0}
    if not REQUEST_LOG.exists():
        return summary
    with REQUEST_LOG.open("r", encoding="utf-8") as handle:
        for line in handle:
            try:
                record = json.loads(line)
                payload = record.get("payload") or {}
                params = payload.get("params") or {}
                service = params.get("service")
                method = params.get("method")
                args = params.get("args") or []
                summary["total"] += 1
                if service == "common" and method == "authenticate":
                    summary["authenticate"] += 1
                elif service == "object" and method == "execute_kw" and len(args) >= 5:
                    operation = args[4]
                    if operation == "create":
                        summary["create"] += 1
                    elif operation == "search_read":
                        summary["search_read"] += 1
            except Exception:
                continue
    return summary


class Handler(BaseHTTPRequestHandler):
    server_version = "OdooCRMMock/0.3"

    def _write_json(self, status, payload):
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _write_raw(self, status, content_type, body):
        encoded = body.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def do_GET(self):
        if self.path == "/health":
            self._write_json(200, {"status": "ok", "service": "mock-odoo", "version": "0.3", "failure_mode": FAILURE_MODE})
            return
        if self.path == "/requests/summary":
            self._write_json(200, request_summary())
            return
        self._write_json(404, {"status": "not_found"})

    def do_POST(self):
        global FAILURE_MODE

        if self.path == "/control":
            try:
                length = int(self.headers.get("Content-Length", "0"))
                payload = json.loads(self.rfile.read(length).decode("utf-8")) if length > 0 else {}
            except Exception as exc:
                self._write_json(400, {"status": "invalid_control", "message": str(exc)})
                return
            mode = payload.get("mode", FAILURE_MODE)
            if mode not in ALLOWED_FAILURE_MODES:
                self._write_json(400, {"status": "invalid_mode"})
                return
            FAILURE_MODE = mode
            if payload.get("clear_requests"):
                try:
                    REQUEST_LOG.unlink(missing_ok=True)
                except Exception:
                    pass
            self._write_json(200, {"status": "ok", "version": "0.3", "failure_mode": FAILURE_MODE})
            return

        if self.path != "/jsonrpc":
            self._write_json(404, {"status": "not_found"})
            return

        if FAILURE_MODE == "http_500":
            self._write_json(500, {"status": "simulated_failure"})
            return
        if FAILURE_MODE == "invalid_json":
            self._write_raw(200, "application/json; charset=utf-8", "{invalid-json")
            return

        try:
            length = int(self.headers.get("Content-Length", "0"))
            payload = json.loads(self.rfile.read(length).decode("utf-8"))
            append_request(payload)
        except Exception as exc:
            self._write_json(400, rpc_error(None, -32700, "Parse error", str(exc)))
            return

        request_id = payload.get("id")
        params = payload.get("params") or {}
        service = params.get("service")
        method = params.get("method")
        args = params.get("args") or []

        if service == "common" and method == "authenticate":
            if FAILURE_MODE == "auth_reject":
                self._write_json(200, rpc_result(request_id, False))
            else:
                self._write_json(200, rpc_result(request_id, USER_ID))
            return

        if service == "object" and method == "execute_kw":
            if len(args) < 6:
                self._write_json(200, rpc_error(request_id, 100, "Invalid execute_kw arguments"))
                return
            model = args[3]
            operation = args[4]
            operation_args = args[5]
            kwargs = args[6] if len(args) > 6 and isinstance(args[6], dict) else {}

            if model == "crm.lead" and operation == "create":
                if FAILURE_MODE == "rpc_error":
                    self._write_json(200, rpc_error(request_id, 200, "Simulated Odoo RPC failure", {"name": "odoo.exceptions.UserError"}))
                    return
                if FAILURE_MODE == "field_error":
                    self._write_json(200, rpc_error(request_id, 201, "Invalid field on crm.lead", {"name": "builtins.ValueError", "message": "Invalid field 'synthetic_bad_field' on model 'crm.lead'"}))
                    return
                if FAILURE_MODE == "lead_zero":
                    self._write_json(200, rpc_result(request_id, 0))
                    return
                lead_id = 1000
                if isinstance(operation_args, list) and operation_args:
                    lead_id += len(operation_args)
                self._write_json(200, rpc_result(request_id, lead_id))
                return

            if model in ROUTING_DATA and operation == "search_read":
                records = list(ROUTING_DATA[model])
                limit = kwargs.get("limit")
                if isinstance(limit, int) and limit >= 0:
                    records = records[:limit]
                self._write_json(200, rpc_result(request_id, records))
                return

            self._write_json(200, rpc_error(request_id, 101, "Unsupported mock operation", {"model": model, "operation": operation}))
            return

        self._write_json(200, rpc_error(request_id, 102, "Unsupported JSON-RPC method"))

    def log_message(self, fmt, *args):
        return


if __name__ == "__main__":
    server = ThreadingHTTPServer((HOST, PORT), Handler)
    print(f"Mock Odoo listening on http://{HOST}:{PORT}", flush=True)
    server.serve_forever()
