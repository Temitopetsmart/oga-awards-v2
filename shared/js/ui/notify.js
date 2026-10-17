/**
 * One place for every alert, confirmation and toast (SweetAlert2).
 * Pages never call Swal directly, so the look and behaviour stay consistent.
 */
const swal = () => {
  if (!window.Swal) throw new Error("SweetAlert2 is not loaded on this page.");
  return window.Swal;
};

const base = {
  buttonsStyling: false,
  background: "#181818",
  color: "#f7f5ef",
  customClass: {
    popup: "oga-swal",
    confirmButton: "btn btn-gold",
    cancelButton: "btn btn-ghost",
    denyButton: "btn btn-ghost",
  },
};

export const notify = {
  success: (title, text) =>
    swal().fire({ ...base, icon: "success", title, text }),
  error: (title, text) => swal().fire({ ...base, icon: "error", title, text }),
  info: (title, text) => swal().fire({ ...base, icon: "info", title, text }),
  warning: (title, text) =>
    swal().fire({ ...base, icon: "warning", title, text }),

  toast: (icon, title) =>
    swal().fire({
      toast: true,
      position: "top-end",
      timer: 3500,
      timerProgressBar: true,
      showConfirmButton: false,
      icon,
      title,
      background: base.background,
      color: base.color,
      customClass: { popup: "oga-swal oga-swal--toast" },
    }),

  /** Resolves true/false. Use danger:true for destructive actions (cancel is focused). */
  async confirm({
    title = "Are you sure?",
    text = "",
    confirmText = "Confirm",
    cancelText = "Cancel",
    danger = false,
  } = {}) {
    const result = await swal().fire({
      ...base,
      icon: danger ? "warning" : "question",
      title,
      text,
      showCancelButton: true,
      confirmButtonText: confirmText,
      cancelButtonText: cancelText,
      reverseButtons: true,
      focusCancel: danger,
    });
    return result.isConfirmed;
  },

  loading: (title = "Please wait…") =>
    swal().fire({
      ...base,
      title,
      allowOutsideClick: false,
      allowEscapeKey: false,
      showConfirmButton: false,
      didOpen: () => swal().showLoading(),
    }),
  close: () => swal().close(),

  /** Standard way to show a caught error to the user. */
  failure(err, fallback = "Something went wrong. Please try again.") {
    console.error(err);
    return notify.error("Oops", err?.userMessage ?? fallback);
  },
};
