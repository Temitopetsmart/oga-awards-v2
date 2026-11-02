import { money } from "../core/format.js";
import { delay, userError } from "../core/store.js";
import { notify } from "../ui/notify.js";

/**
 * DEMO payment adapter. In production, checkout() will redirect to the payment provider
 * and the backend will verify the transaction — keep this function signature.
 */
export async function checkout(order) {
  // Real payments: the server gave us the provider's secure payment page. We leave this page; the provider sends
  // the buyer back here, and the server then checks the payment itself before counting anything.
  if (order.paymentLink) {
    location.assign(order.paymentLink);
    return new Promise(() => {}); // the browser is leaving
  }
  if (order.free) return; // free tickets: nothing to pay
  const ok = await notify.confirm({
    title: "Demo payment",
    text: `This is where the secure payment page opens. Simulate a successful payment of ${money(order.amount)} (ref ${order.reference})?`,
    confirmText: "Simulate success",
    cancelText: "Cancel",
  });
  if (!ok)
    throw Object.assign(
      userError("Payment was cancelled. You have not been charged."),
      { cancelled: true },
    );
  await delay(800);
  return { status: "success", reference: order.reference };
}
