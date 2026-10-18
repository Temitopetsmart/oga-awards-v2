import { getSeed } from "../../../shared/js/core/http.js";
import { store, uid } from "../../../shared/js/core/store.js";

/**
 * DEMO data store for the admin (localStorage). Each collection is seeded once from a
 * /data/*.json file. Replace the bodies with PHP API calls later; keep the method names.
 */
export function collection(name, { seed = null, prefix = "ID-" } = {}) {
  const key = `admin:${name}`;
  const read = async () => {
    let rows = store.get(key);
    if (rows === null) {
      rows = seed ? structuredClone(await getSeed(seed)) : [];
      store.set(key, rows);
    }
    return rows;
  };
  return {
    all: read,
    async get(id) {
      return (await read()).find((r) => r.id === id) ?? null;
    },
    async create(record) {
      const rows = await read();
      const row = {
        id: uid(prefix),
        createdAt: new Date().toISOString(),
        ...record,
      };
      store.set(key, [...rows, row]);
      return row;
    },
    async update(id, patch) {
      const rows = await read();
      const row = rows.find((r) => r.id === id);
      if (!row) return null;
      Object.assign(row, patch);
      store.set(key, rows);
      return row;
    },
    async remove(id) {
      store.set(
        key,
        (await read()).filter((r) => r.id !== id),
      );
    },
    async replace(rows) {
      store.set(key, rows);
      return rows;
    },
  };
}

/** A whole JSON document (e.g. occasions, committee) seeded from a data file and edited as one unit. */
export function documentStore(name, { seed }) {
  const key = `admin:${name}`;
  return {
    async get() {
      let doc = store.get(key);
      if (doc === null) {
        doc = structuredClone(await getSeed(seed));
        store.set(key, doc);
      }
      return doc;
    },
    save(doc) {
      store.set(key, doc);
      return doc;
    },
  };
}

/* Audit trail: who did what, newest first (capped). */
const AUDIT = "admin:audit";
export function logAudit(admin, action, target = "") {
  const entry = {
    id: uid("AUD-"),
    at: new Date().toISOString(),
    admin: admin?.name ?? "System",
    action,
    target,
  };
  store.set(AUDIT, [entry, ...store.get(AUDIT, [])].slice(0, 500));
}
export const getAudit = () => store.get(AUDIT, []);
