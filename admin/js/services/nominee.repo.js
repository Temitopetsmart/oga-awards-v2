// admin/js/services/nominee.repo.js
import { store } from "../../../shared/js/core/store.js";
import { collection } from "./admin-store.js";
import { viewing, inEdition } from "./scope.js";

/**
 * Nominees of the edition being viewed = seed nominees (admin store) + public self-registrations
 * (the public site keeps those under 'oga:nominees'). Status changes are written back to
 * whichever store owns the record.
 */
const seeds = collection("nominees", { seed: "nominees", prefix: "NOM-" });
const REG_KEY = "nominees";
const registered = () => store.get(REG_KEY, []);

export async function listNominees(editionId = viewing()) {
  const base = (await seeds.all())
    .filter((n) => inEdition(n, editionId))
    .map((n) => ({ ...n, source: "admin" }));
  const reg = registered()
    .filter((n) => inEdition(n, editionId))
    .map((n) => ({ ...n, source: "registration" }));
  return [...reg, ...base];
}

export const pendingNomineeCount = (editionId = viewing()) =>
  registered().filter((n) => n.status === "pending" && inEdition(n, editionId))
    .length;

export async function setNomineeStatus(id, status) {
  const reg = registered();
  const r = reg.find((n) => n.id === id);
  if (r) {
    r.status = status;
    store.set(REG_KEY, reg);
    return;
  }
  await seeds.update(id, { status });
}

export async function saveNominee(id, data) {
  const reg = registered();
  const r = reg.find((n) => n.id === id);
  if (r) {
    Object.assign(r, data);
    store.set(REG_KEY, reg);
    return;
  }
  if (id) await seeds.update(id, data);
  else await seeds.create({ occasionId: viewing(), photo: null, ...data });
}

export async function removeNominee(id) {
  const reg = registered();
  if (reg.some((n) => n.id === id)) {
    store.set(
      REG_KEY,
      reg.filter((n) => n.id !== id),
    );
    return;
  }
  await seeds.remove(id);
}
