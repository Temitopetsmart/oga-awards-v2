import { adminApi } from "./admin-api.js";

/**
 * Committee recruitment in the admin, on the server. One call loads everything for the edition
 * being viewed; after every change it is loaded again, so the page always shows what is stored.
 * The server decides what is allowed and says why if it refuses.
 */
let state = {
  editionId: null,
  config: null,
  applications: [],
  members: [],
  people: [],
};

export async function loadCommittee(editionId) {
  const r = await adminApi(
    `/admin/committee?edition=${encodeURIComponent(editionId)}`,
  );
  state = {
    editionId,
    config: r.config,
    applications: r.applications,
    members: r.members,
    people: r.people,
  };
  return state;
}

export const getRecruitmentConfig = () => state.config;
export const listCommitteeApplications = () => state.applications;
export const listCommitteeMembers = () => state.members;
export const listAddablePeople = () => state.people;

async function change(path, method, body, editionId) {
  const res = await adminApi(path, {
    method,
    body: { edition: editionId, ...body },
  });
  await loadCommittee(editionId);
  return res;
}

export const saveRecruitmentConfig = (editionId, values) =>
  change("/admin/committee/config", "PUT", values, editionId);

/** action: shortlist | screen | approve | appoint | reserve | reconsider | withdraw */
export const applicationAction = (editionId, id, action, fields = {}) =>
  change(
    `/admin/committee/applications/${id}`,
    "POST",
    { action, ...fields },
    editionId,
  );

export const addCommitteeMember = (editionId, fields) =>
  change("/admin/committee/members", "POST", fields, editionId);
export const editCommitteeMember = (editionId, id, fields) =>
  change(`/admin/committee/members/${id}`, "PATCH", fields, editionId);
export const removeCommitteeMember = (editionId, id) =>
  change(`/admin/committee/members/${id}`, "DELETE", {}, editionId);
