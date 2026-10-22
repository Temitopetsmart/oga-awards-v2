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
  if (!String(editionId ?? "").trim()) {
    throw userError("A valid edition is required.");
  }

  const current = getRecruitmentConfig(editionId);
  const next = { ...current, ...patch, editionId };

  next.targetApplicants = Number(next.targetApplicants);
  next.photoMaxSizeKB = Number(next.photoMaxSizeKB);
  next.enabled = Boolean(next.enabled);
  next.photoUploadsEnabled = Boolean(next.photoUploadsEnabled);

  if (!Number.isInteger(next.targetApplicants) || next.targetApplicants < 1) {
    throw userError("The applicant target must be at least 1.");
  }

  if (
    !Number.isInteger(next.photoMaxSizeKB) ||
    next.photoMaxSizeKB < 50 ||
    next.photoMaxSizeKB > 10240
  ) {
    throw userError("Photo size must be between 50 KB and 10 MB.");
  }

  if (next.enabled && (!next.opensAt || !next.closesAt)) {
    throw userError(
      "Set both the opening and closing date before enabling recruitment.",
    );
  }

  const opens = next.opensAt ? new Date(next.opensAt).getTime() : null;
  const closes = next.closesAt ? new Date(next.closesAt).getTime() : null;

  if (next.opensAt && !Number.isFinite(opens)) {
    throw userError("Enter a valid opening date and time.");
  }

  if (next.closesAt && !Number.isFinite(closes)) {
    throw userError("Enter a valid closing date and time.");
  }

  if (opens !== null && closes !== null && closes <= opens) {
    throw userError("The closing date must be after the opening date.");
  }

  next.allowedPhotoTypes = Array.isArray(next.allowedPhotoTypes)
    ? next.allowedPhotoTypes.filter((type) =>
        ["image/jpeg", "image/png", "image/webp"].includes(type),
      )
    : ["image/jpeg", "image/png", "image/webp"];

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

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    throw userError("Enter a valid email address.");
  }

  const requiredFields = [
    ["name", "Enter your full name."],
    ["phone", "Enter your phone number."],
    ["department", "Enter your department."],
    ["level", "Choose your level."],
    ["unitId", "Choose your preferred committee unit."],
    ["motivation", "Tell us why you want to join the committee."],
  ];

  for (const [key, message] of requiredFields) {
    if (!String(data[key] ?? "").trim()) {
      throw userError(message);
    }
  }

  if (String(data.name).trim().length < 2) {
    throw userError("Your name must contain at least 2 characters.");
  }

  if (String(data.motivation).trim().length < 30) {
    throw userError(
      "Your motivation statement must contain at least 30 characters.",
    );
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
  const { historyNote = "", ...changes } = patch;

  if (
    changes.status &&
    !Object.prototype.hasOwnProperty.call(COMMITTEE_STAGES, changes.status)
  ) {
    throw userError("That committee application stage is not recognised.");
  }

  const stageChanged =
    Boolean(changes.status) && changes.status !== current.status;

  const next = {
    ...current,
    ...changes,
    id: current.id,
    editionId: current.editionId,
    email: current.email,
    createdAt: current.createdAt,
    updatedAt: now(),
  };

  if (stageChanged) {
    next.history = [
      ...(current.history ?? []),
      {
        stage: changes.status,
        at: next.updatedAt,
        actor,
        note: String(historyNote).trim(),
      },
    ];
  }

  applications[index] = next;
  store.set(APPLICATIONS, applications);

  return next;
}

export function queueCommitteeNotification(application, type, details = {}) {
  if (!application?.id || !application?.editionId || !application?.email) {
    throw userError(
      "A valid application and recipient email are required to queue a notification.",
    );
  }

  const outbox = store.get(OUTBOX, []);

  const message = {
    id: uid("MAIL-", 8),
    editionId: application.editionId,
    applicationId: application.id,
    recipient: String(application.email).trim().toLowerCase(),
    applicantName: String(application.name ?? "Applicant").trim(),
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
