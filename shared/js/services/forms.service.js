import { store, delay, uid } from "../core/store.js";

/** DEMO adapter for simple submissions. Replace with PHP API calls later; keep the signatures. */
const save = async (key, prefix, data) => {
  await delay(600);
  const record = {
    id: uid(prefix),
    ...data,
    createdAt: new Date().toISOString(),
  };
  store.set(key, [...store.get(key, []), record]);
  return record;
};

export const submitCommitteeApplication = (data) =>
  save("committee-applications", "APP-", data);
export const sendContactMessage = (data) =>
  save("contact-messages", "MSG-", data);
