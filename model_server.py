import json
import math
import os
import re
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse


def _read_json_body(handler: BaseHTTPRequestHandler) -> dict:
    length = int(handler.headers.get("Content-Length", "0") or "0")
    raw = handler.rfile.read(length) if length > 0 else b""
    try:
        data = json.loads(raw.decode("utf-8") if raw else "{}")
    except Exception:
        return {}
    return data if isinstance(data, dict) else {}


def _send_json(handler: BaseHTTPRequestHandler, status: int, payload: dict) -> None:
    raw = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    handler.send_response(status)
    handler.send_header("Access-Control-Allow-Origin", "*")
    handler.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
    handler.send_header("Access-Control-Allow-Headers", "Content-Type")
    handler.send_header("Content-Type", "application/json; charset=utf-8")
    handler.send_header("Content-Length", str(len(raw)))
    handler.end_headers()
    handler.wfile.write(raw)


def _normalize_text(s: str | None) -> str:
    if not isinstance(s, str):
        return ""
    return " ".join(s.strip().split())


def _merge_context_question(context: str | None, question: str) -> str:
    c = _normalize_text(context)
    q = _normalize_text(question)
    if c:
        return f"{c}\n{q}"
    return q


_TOKEN_RE = re.compile(r"[0-9A-Za-zÀ-ÖØ-öø-ÿ\u0100-\u024F\u1E00-\u1EFF]+", re.UNICODE)


def _tokenize(text: str) -> list[str]:
    return [m.group(0).lower() for m in _TOKEN_RE.finditer(text)]


class EmbeddingIndex:
    def __init__(self, texts: list[str], answers: list[str], contexts: list[str | None], questions: list[str]):
        self.texts = texts
        self.answers = answers
        self.contexts = contexts
        self.questions = questions
        self.embeddings = None

    def build(self, encoder, batch_size: int = 16):
        import numpy as np

        embs = encoder(self.texts, batch_size=batch_size)
        norms = np.linalg.norm(embs, axis=1, keepdims=True)
        norms[norms == 0] = 1.0
        self.embeddings = (embs / norms).astype("float32")

    def search(self, query_emb):
        import numpy as np

        if self.embeddings is None:
            raise RuntimeError("Index not built")
        q = query_emb.astype("float32")
        qn = np.linalg.norm(q)
        if qn == 0:
            return 0, 0.0
        q = q / qn
        scores = self.embeddings @ q
        best_idx = int(scores.argmax())
        return best_idx, float(scores[best_idx])


class BM25Index:
    def __init__(self, texts: list[str], answers: list[str], contexts: list[str | None], questions: list[str]):
        self.texts = texts
        self.answers = answers
        self.contexts = contexts
        self.questions = questions

        self._doc_tfs: list[dict[str, int]] = []
        self._doc_lens: list[int] = []
        self._idf: dict[str, float] = {}
        self._avg_len: float = 0.0

    def build(self) -> None:
        df: dict[str, int] = {}
        doc_tfs: list[dict[str, int]] = []
        doc_lens: list[int] = []

        for text in self.texts:
            tokens = _tokenize(text)
            tf: dict[str, int] = {}
            for t in tokens:
                tf[t] = tf.get(t, 0) + 1
            doc_tfs.append(tf)
            doc_lens.append(len(tokens))

            seen = set(tf.keys())
            for t in seen:
                df[t] = df.get(t, 0) + 1

        n = len(self.texts)
        self._avg_len = (sum(doc_lens) / n) if n else 0.0

        idf: dict[str, float] = {}
        for t, dft in df.items():
            idf[t] = math.log(1.0 + (n - dft + 0.5) / (dft + 0.5))

        self._doc_tfs = doc_tfs
        self._doc_lens = doc_lens
        self._idf = idf

    def search(self, query: str) -> tuple[int, float]:
        q_tokens = _tokenize(query)
        if not q_tokens:
            return 0, 0.0

        qtf: dict[str, int] = {}
        for t in q_tokens:
            qtf[t] = qtf.get(t, 0) + 1

        k1 = 1.2
        b = 0.75

        best_idx = 0
        best_score = -1.0
        avg_len = self._avg_len if self._avg_len > 0 else 1.0

        for i, tf in enumerate(self._doc_tfs):
            dl = self._doc_lens[i] if self._doc_lens[i] > 0 else 1
            score = 0.0
            for t in qtf.keys():
                f = tf.get(t, 0)
                if f <= 0:
                    continue
                idf = self._idf.get(t, 0.0)
                denom = f + k1 * (1.0 - b + b * (dl / avg_len))
                score += idf * (f * (k1 + 1.0)) / (denom if denom != 0 else 1.0)

            if score > best_score:
                best_score = score
                best_idx = i

        score01 = best_score / (best_score + 10.0) if best_score > 0 else 0.0
        return best_idx, float(score01)


class TransformerEncoder:
    def __init__(self, model_id: str, local_files_only: bool):
        import torch
        from transformers import AutoModel, AutoTokenizer

        self.model_id = model_id
        self.device = "cuda" if torch.cuda.is_available() else "cpu"
        self.tokenizer = AutoTokenizer.from_pretrained(model_id, local_files_only=local_files_only)
        self.model = AutoModel.from_pretrained(model_id, local_files_only=local_files_only)
        self.model.to(self.device)
        self.model.eval()

    def __call__(self, texts: list[str], batch_size: int = 16):
        import numpy as np
        import torch

        out = []
        for i in range(0, len(texts), batch_size):
            batch = texts[i : i + batch_size]
            enc = self.tokenizer(
                batch,
                padding=True,
                truncation=True,
                max_length=256,
                return_tensors="pt",
            )
            enc = {k: v.to(self.device) for k, v in enc.items()}
            with torch.no_grad():
                model_out = self.model(**enc)
                last_hidden = model_out.last_hidden_state
                mask = enc.get("attention_mask")
                if mask is None:
                    pooled = last_hidden.mean(dim=1)
                else:
                    mask = mask.unsqueeze(-1).to(last_hidden.dtype)
                    summed = (last_hidden * mask).sum(dim=1)
                    denom = mask.sum(dim=1).clamp(min=1e-9)
                    pooled = summed / denom
            out.append(pooled.detach().cpu().numpy())
        return np.concatenate(out, axis=0)

    def encode_one(self, text: str):
        return self([text], batch_size=1)[0]


class ModelRegistry:
    def __init__(self, dataset_path: str, min_score: float):
        self.dataset_path = dataset_path
        self.min_score = min_score
        self.enable_transformers = (os.getenv("ENABLE_TRANSFORMERS", "0") or "0").strip() == "1"
        self.local_files_only = (os.getenv("HF_LOCAL_ONLY", "0") or "0").strip() == "1"
        self._lock = threading.Lock()
        self._engines: dict[str, tuple[str, object]] = {}
        self._dataset: tuple[list[str], list[str], list[str | None], list[str]] | None = None
        self._bm25_index: BM25Index | None = None
        self._warned_transformer_fail = False
        self._last_transformer_error: str | None = None

        texts, answers, contexts, questions = self._load_dataset()
        self._dataset = (texts, answers, contexts, questions)
        bm25 = BM25Index(texts=texts, answers=answers, contexts=contexts, questions=questions)
        bm25.build()
        self._bm25_index = bm25

    def _model_id(self, key: str) -> str:
        key = key.lower().strip()
        if key in ("indoroberta", "indo-roberta", "indoroberta-base", "roberta"):
            return "akahana/roberta-base-indonesia"
        return "indobenchmark/indobert-base-p1"

    def _load_dataset(self) -> tuple[list[str], list[str], list[str | None], list[str]]:
        raw = open(self.dataset_path, "rb").read()
        data = json.loads(raw.decode("utf-8"))
        if not isinstance(data, list):
            raise RuntimeError("Dataset harus berupa array JSON")

        texts: list[str] = []
        answers: list[str] = []
        contexts: list[str | None] = []
        questions: list[str] = []

        for item in data:
            if not isinstance(item, dict):
                continue
            q = item.get("pertanyaan")
            a = item.get("jawaban")
            c = item.get("konteks")
            if not isinstance(q, str) or not isinstance(a, str):
                continue
            q = _normalize_text(q)
            a = a.strip()
            c = c if isinstance(c, str) else None
            if q == "" or a == "":
                continue
            texts.append(_merge_context_question(c, q))
            answers.append(a)
            contexts.append(c)
            questions.append(q)

        if not texts:
            raise RuntimeError("Dataset kosong setelah dibersihkan")

        return texts, answers, contexts, questions

    def _get_or_build(self, model_key: str):
        with self._lock:
            if model_key in self._engines:
                return self._engines[model_key]

            if self._dataset is None:
                texts, answers, contexts, questions = self._load_dataset()
                self._dataset = (texts, answers, contexts, questions)
            else:
                texts, answers, contexts, questions = self._dataset

            if not self.enable_transformers:
                engine: tuple[str, object] = ("bm25", self._bm25_index)
                self._engines[model_key] = engine
                return engine

            try:
                model_id = self._model_id(model_key)
                encoder = TransformerEncoder(model_id=model_id, local_files_only=self.local_files_only)
                index = EmbeddingIndex(texts=texts, answers=answers, contexts=contexts, questions=questions)
                index.build(encoder, batch_size=16)
                engine: tuple[str, object] = ("embedding", (encoder, index))
                self._last_transformer_error = None
            except Exception as e:
                self._last_transformer_error = str(e)
                if not self._warned_transformer_fail:
                    print(f"Transformers init failed, falling back to bm25: {e}", flush=True)
                    self._warned_transformer_fail = True
                if self._bm25_index is None:
                    index = BM25Index(texts=texts, answers=answers, contexts=contexts, questions=questions)
                    index.build()
                    self._bm25_index = index
                engine = ("bm25", self._bm25_index)

            self._engines[model_key] = engine
            return engine

    def predict(self, question: str, context: str | None, model_key: str):
        query_text = _merge_context_question(context, question)
        engine_type, engine = self._get_or_build(model_key)

        if engine_type == "embedding":
            encoder, index = engine
            emb = encoder.encode_one(query_text)
            best_idx, score = index.search(emb)
            used_model = model_key
        else:
            index = engine
            best_idx, score = index.search(query_text)
            used_model = "bm25"

        if score < self.min_score:
            return {
                "answer": "Maaf, saya belum menemukan jawaban yang tepat. Bisa dijelaskan sedikit lagi pertanyaannya?",
                "score": score,
                "model": used_model,
                "matched_question": None,
            }

        return {
            "answer": index.answers[best_idx],
            "score": score,
            "model": used_model,
            "matched_question": index.questions[best_idx],
            "konteks": index.contexts[best_idx],
        }


class Handler(BaseHTTPRequestHandler):
    registry: ModelRegistry | None = None

    def do_OPTIONS(self):
        self.send_response(204)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.send_header("Access-Control-Max-Age", "86400")
        self.end_headers()

    def do_GET(self):
        path = urlparse(self.path).path
        if path == "/":
            _send_json(
                self,
                200,
                {
                    "service": "indo-model-server",
                    "status": "ok",
                    "endpoints": {
                        "health": "GET /health",
                        "debug": "GET /debug",
                        "predict": "POST /predict",
                    },
                },
            )
            return
        if path == "/health":
            _send_json(self, 200, {"status": "ok"})
            return
        if path == "/debug":
            reg = Handler.registry
            _send_json(
                self,
                200,
                {
                    "python": sys.version.split()[0],
                    "enable_transformers": bool(getattr(reg, "enable_transformers", False)),
                    "hf_local_only": bool(getattr(reg, "local_files_only", False)),
                    "last_transformer_error": getattr(reg, "_last_transformer_error", None),
                },
            )
            return
        _send_json(self, 404, {"error": "not found"})

    def do_POST(self):
        path = urlparse(self.path).path
        if path != "/predict":
            _send_json(self, 404, {"error": "not found"})
            return

        data = _read_json_body(self)
        question = _normalize_text(data.get("question"))
        context = data.get("context")
        context = _normalize_text(context) if isinstance(context, str) else None
        model_key = _normalize_text(data.get("model")) or "indobert"

        if question == "":
            _send_json(self, 400, {"error": "question is required"})
            return

        if Handler.registry is None:
            _send_json(self, 500, {"error": "registry not initialized"})
            return

        try:
            result = Handler.registry.predict(question=question, context=context, model_key=model_key)
        except Exception as e:
            _send_json(self, 500, {"error": str(e)})
            return

        _send_json(self, 200, result)

    def log_message(self, fmt, *args):
        return


def main():
    host = os.getenv("HOST", "127.0.0.1")
    port = int(os.getenv("PORT", "8010"))
    dataset_path = os.getenv("DATASET_PATH", os.path.join(os.path.dirname(__file__), "dataset_nabila.json"))
    min_score = float(os.getenv("MIN_SCORE", "0.20"))

    Handler.registry = ModelRegistry(dataset_path=dataset_path, min_score=min_score)
    print(
        f"ENABLE_TRANSFORMERS={int(Handler.registry.enable_transformers)} HF_LOCAL_ONLY={int(Handler.registry.local_files_only)}",
        flush=True,
    )
    server = ThreadingHTTPServer((host, port), Handler)
    print(f"Model server listening on http://{host}:{port}", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
