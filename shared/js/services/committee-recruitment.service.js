import { store, uid, userError } from "../core/store.js";

const APPLICATIONS = "committee-applications";
const CONFIG_PREFIX = "committee-recruitment:";
const OUTBOX = "committee-notification-outbox";

export const COMMITTEE_STAGES = {
  submitted: "Application received",
  shortlisted: "Shortlisted for screening",
  screened: "Screening completed",
  approved: "Approved",
  reserve: "Reserve list",
  unsuccessful: "Not selected",
  reconsideration: "Reconsideration",
  appointed: "Appointed",
  withdrawn: "Withdrawn",
};

const now = () => new Date().toISOString();

export function getRecruitmentConfig(editionId) {
  return store.get(`${CONFIG_PREFIX}${editionId}`, {
    editionId,
    enabled: false,
    opensAt: "",
    closesAt: "",
    targetApplicants: 100,
    screeningDate: "",
    screeningTime: "",
    screeningVenue: "",
    screeningInstructions: "",
    approvalMessage: "",
    unsuccessfulMessage:
      "Thank you for your interest. You may be considered again if a position becomes available.",
    reserveMessage:
      "You are on the reserve list and may be contacted if a position becomes available.",
    onboardingMessage: "",
    whatsappGroupUrl: "",
    photoUploadsEnabled: true,
    photoMaxSizeKB: 1024,
    allowedPhotoTypes: ["image/jpeg", "image/png", "image/webp"],
  });
}

export function saveRecruitmentConfig(editionId, patch) {
  const current = getRecruitmentConfig(editionId);
  const next = { ...current, ...patch, editionId };

  if (
    !Number.isInteger(Number(next.targetApplicants)) ||
    Number(next.targetApplicants) < 1
  ) {
    throw userError("The applicant target must be at least 1.");
  }

  if (
    !Number.isInteger(Number(next.photoMaxSizeKB)) ||
    Number(next.photoMaxSizeKB) < 50 ||
    Number(next.photoMaxSizeKB) > 10240
  ) {
    throw userError("Photo size must be between 50 KB and 10 MB.");
  }

  if (
    next.opensAt &&
    next.closesAt &&
    new Date(next.closesAt) <= new Date(next.opensAt)
  ) {
    throw userError("The closing date must be after the opening date.");
  }

  store.set(`${CONFIG_PREFIX}${editionId}`, next);
  return next;
}

export function getApplicationWindow(editionId, at = new Date()) {
  const config = getRecruitmentConfig(editionId);
  const time = at.getTime();
  const opens = config.opensAt ? new Date(config.opensAt).getTime() : null;
  const closes = config.closesAt ? new Date(config.closesAt).getTime() : null;

  const open =
    config.enabled &&
    opens !== null &&
    closes !== null &&
    Number.isFinite(opens) &&
    Number.isFinite(closes) &&
    time >= opens &&
    time <= closes;

  return {
    open,
    config,
    reason: !config.enabled
      ? "Applications are currently disabled."
      : opens === null || closes === null
        ? "The application dates have not been configured."
        : time < opens
          ? "Applications have not opened yet."
          : time > closes
            ? "The application period has closed."
            : "",
  };
}

export function listCommitteeApplications(editionId) {
  return store
    .get(APPLICATIONS, [])
    .filter((item) => item.editionId === editionId)
    .map((item) => ({
      ...item,
      status: item.status ?? "submitted",
    }))
    .sort((a, b) => String(b.createdAt).localeCompare(String(a.createdAt)));
}

export function submitRecruitmentApplication(data) {
  const editionId = String(data.editionId ?? "").trim();

  if (!editionId) {
    throw userError("The application edition could not be identified.");
  }

  const window = getApplicationWindow(editionId);

  if (!window.open) {
    throw userError(window.reason || "Applications are closed.");
  }

  const email = String(data.email ?? "")
    .trim()
    .toLowerCase();

  if (!email) {
    throw userError("An email address is required.");
  }

  const applications = store.get(APPLICATIONS, []);
  const duplicate = applications.some(
    (item) =>
      item.editionId === editionId &&
      String(item.email).toLowerCase() === email &&
      !["withdrawn"].includes(item.status),
  );

  if (duplicate) {
    throw userError(
      "An application with this email already exists for this edition.",
    );
  }

  const application = {
    id: uid("APP-", 8),
    ...data,
    editionId,
    email,
    status: "submitted",
    screening: null,
    screeningResult: null,
    decision: null,
    decisionReason: "",
    createdAt: now(),
    updatedAt: now(),
    history: [
      {
        stage: "submitted",
        at: now(),
        note: "Application submitted.",
      },
    ],
  };

  store.set(APPLICATIONS, [...applications, application]);

  queueCommitteeNotification(application, "application_received");

  return application;
}

export function updateCommitteeApplication(
  id,
  editionId,
  patch,
  actor = "Admin",
) {
  const applications = store.get(APPLICATIONS, []);
  const index = applications.findIndex(
    (item) => item.id === id && item.editionId === editionId,
  );

  if (index < 0) {
    throw userError("The application could not be found in this edition.");
  }

  const current = applications[index];
  const next = {
    ...current,
    ...patch,
    id: current.id,
    editionId: current.editionId,
    email: current.email,
    createdAt: current.createdAt,
    updatedAt: now(),
  };

  if (patch.status && patch.status !== current.status) {
    if (!COMMITTEE_STAGES[patch.status]) {
      throw userError("That committee application stage is not recognised.");
    }

    next.history = [
      ...(current.history ?? []),
      {
        stage: patch.status,
        at: now(),
        actor,
        note: String(patch.historyNote ?? ""),
      },
    ];
  }

  applications[index] = next;
  store.set(APPLICATIONS, applications);

  return next;
}

export function queueCommitteeNotification(application, type, details = {}) {
  const outbox = store.get(OUTBOX, []);

  const message = {
    id: uid("MAIL-", 8),
    editionId: application.editionId,
    applicationId: application.id,
    recipient: application.email,
    applicantName: application.name,
    type,
    details,
    status: "queued_demo",
    createdAt: now(),
    note: "Demo queue only. No email has been sent.",
  };

  store.set(OUTBOX, [...outbox, message]);
  return message;
}

export function getCommitteeNotificationOutbox(editionId) {
  return store
    .get(OUTBOX, [])
    .filter((item) => item.editionId === editionId)
    .sort((a, b) => String(b.createdAt).localeCompare(String(a.createdAt)));
}
