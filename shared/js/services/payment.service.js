import { userError } from "../core/store.js";

/**
 * Real payments only: the server gives us the provider's secure payment page, we leave this site for it, and the
 * provider sends the buyer back. The server then checks the payment itself before counting anything.
 */
export async function checkout(order) {
  if (order.paymentLink) {
    location.assign(order.paymentLink);
    return new Promise(() => {}); // the browser is leaving
  }
  if (order.free) return; // nothing to pay
  throw userError("We could not start the payment. Please try again.");
}
