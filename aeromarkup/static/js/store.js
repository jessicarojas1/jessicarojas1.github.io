/* AeroMarkup — offline-first IndexedDB store.
   Authoritative local datastore so the entire app works with no network.
   Every record carries a stable `client_uid` so it can sync idempotently. */

const DB_NAME = "aeromarkup";
const DB_VERSION = 2;
const STORES = {
  programs:     { keyPath: "id", indexes: [] },
  projects:     { keyPath: "id", indexes: [["program_id", "program_id"]] },
  drawings:     { keyPath: "id", indexes: [["project_id", "project_id"]] },
  strokes:      { keyPath: "id", indexes: [["drawing_id", "drawing_id"]] },
  annotations:  { keyPath: "id", indexes: [["drawing_id", "drawing_id"]] },
  layers:       { keyPath: "id", indexes: [["drawing_id", "drawing_id"]] },
  ncrs:         { keyPath: "id", indexes: [["project_id", "project_id"], ["status", "status"]] },
  inspections:  { keyPath: "id", indexes: [["project_id", "project_id"]] },
  inspection_items: { keyPath: "id", indexes: [["inspection_id", "inspection_id"]] },
  approvals:    { keyPath: "id", indexes: [["entity", "entity_key"]] },
  comments:     { keyPath: "id", indexes: [["entity", "entity_key"]] },
  audit:        { keyPath: "id", indexes: [["ts", "ts"]] },
  meta:         { keyPath: "id", indexes: [] },
};

let _db = null;

export function uid() {
  return crypto.randomUUID ? crypto.randomUUID()
    : "id-" + Date.now() + "-" + Math.random().toString(16).slice(2);
}

export function openDB() {
  return new Promise((res, rej) => {
    const r = indexedDB.open(DB_NAME, DB_VERSION);
    r.onupgradeneeded = (e) => {
      const db = e.target.result;
      for (const [name, def] of Object.entries(STORES)) {
        let store;
        if (!db.objectStoreNames.contains(name)) {
          store = db.createObjectStore(name, { keyPath: def.keyPath });
        } else {
          store = e.target.transaction.objectStore(name);
        }
        for (const [idxName, keyPath] of def.indexes) {
          if (!store.indexNames.contains(idxName)) store.createIndex(idxName, keyPath, { unique: false });
        }
      }
    };
    r.onsuccess = () => { _db = r.result; res(_db); };
    r.onerror = () => rej(r.error);
  });
}

function os(store, mode = "readonly") {
  return _db.transaction(store, mode).objectStore(store);
}

export function put(store, val) {
  return new Promise((res, rej) => {
    const rq = os(store, "readwrite").put(val);
    rq.onsuccess = () => res(val);
    rq.onerror = () => rej(rq.error);
  });
}

export function get(store, id) {
  return new Promise((res, rej) => {
    const rq = os(store).get(id);
    rq.onsuccess = () => res(rq.result || null);
    rq.onerror = () => rej(rq.error);
  });
}

export function all(store) {
  return new Promise((res, rej) => {
    const rq = os(store).getAll();
    rq.onsuccess = () => res(rq.result || []);
    rq.onerror = () => rej(rq.error);
  });
}

export function byIndex(store, index, value) {
  return new Promise((res, rej) => {
    const rq = os(store).index(index).getAll(value);
    rq.onsuccess = () => res(rq.result || []);
    rq.onerror = () => rej(rq.error);
  });
}

export function del(store, id) {
  return new Promise((res, rej) => {
    const rq = os(store, "readwrite").delete(id);
    rq.onsuccess = () => res();
    rq.onerror = () => rej(rq.error);
  });
}

export async function count(store) {
  return (await all(store)).length;
}

/* meta key/value helpers (cursors, settings, session) */
export async function getMeta(key, fallback = null) {
  const r = await get("meta", key);
  return r ? r.value : fallback;
}
export function setMeta(key, value) {
  return put("meta", { id: key, value });
}
