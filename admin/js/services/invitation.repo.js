import { store, uid } from "../../../shared/js/core/store.js";

const KEY = "admin:invitations";

export const listInvitations = () => store.get(KEY, []);

export function createInvitation({
  email,
  editionId,
  roleIds,
  invitedBy,
  expiresInDays = 7,
}) {
  const normalizedEmail = String(email ?? "")
    .trim()
    .toLowerCase();

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(normalizedEmail)) {
    throw new Error("Enter a valid email address.");
  }

  if (!editionId || !Array.isArray(roleIds) || roleIds.length === 0) {
    throw new Error("Choose an edition and at least one role.");
  }

  const invitations = listInvitations();

  const pending = invitations.find(
    (item) =>
      item.email === normalizedEmail &&
      item.editionId === editionId &&
      item.status === "pending" &&
      new Date(item.expiresAt).getTime() > Date.now(),
  );

  if (pending) {
    throw new Error("There is already a pending invitation for this email.");
  }

  const token = uid("INV-", 24);
  const now = new Date();

  const invitation = {
    id: uid("INVID-", 8),
    token,
    email: normalizedEmail,
    editionId,
    roleIds: [...new Set(roleIds)],
    invitedBy: invitedBy?.id ?? null,
    invitedByName: invitedBy?.name ?? "Admin",
    status: "pending",
    createdAt: now.toISOString(),
    expiresAt: new Date(
      now.getTime() + expiresInDays * 24 * 60 * 60 * 1000,
    ).toISOString(),
    acceptedAt: null,
  };

  store.set(KEY, [...invitations, invitation]);

  return invitation;
}

export function getInvitation(token) {
  return listInvitations().find((item) => item.token === token) ?? null;
}

export function revokeInvitation(id) {
  const invitations = listInvitations();

  store.set(
    KEY,
    invitations.map((item) =>
      item.id === id && item.status === "pending"
        ? { ...item, status: "revoked", revokedAt: new Date().toISOString() }
        : item,
    ),
  );
}

export function acceptInvitation(token, { name, email }) {
  const invitations = listInvitations();
  const invitation = invitations.find((item) => item.token === token);

  if (!invitation) {
    throw new Error("This invitation link is invalid.");
  }

  if (invitation.status !== "pending") {
    throw new Error("This invitation has already been used or revoked.");
  }

  if (new Date(invitation.expiresAt).getTime() <= Date.now()) {
    store.set(
      KEY,
      invitations.map((item) =>
        item.id === invitation.id ? { ...item, status: "expired" } : item,
      ),
    );

    throw new Error(
      "This invitation has expired. Ask the administrator for a new one.",
    );
  }

  const normalizedEmail = String(email ?? "")
    .trim()
    .toLowerCase();

  if (normalizedEmail !== invitation.email) {
    throw new Error("The email does not match the invitation.");
  }

  if (!String(name ?? "").trim()) {
    throw new Error("Enter your full name.");
  }

  store.set(
    KEY,
    invitations.map((item) =>
      item.id === invitation.id
        ? {
            ...item,
            status: "accepted",
            acceptedAt: new Date().toISOString(),
          }
        : item,
    ),
  );

  return {
    email: invitation.email,
    editionId: invitation.editionId,
    roleIds: invitation.roleIds,
    name: String(name).trim(),
  };
}
