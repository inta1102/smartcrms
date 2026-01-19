import "./bootstrap";

import focus from "@alpinejs/focus";

/**
 * =========================================================
 * ALPINE (Livewire v3 already provides Alpine)
 * - Jangan import "alpinejs"
 * - Plugin focus cukup dipasang SEKALI
 * =========================================================
 */
document.addEventListener("alpine:init", () => {
  if (!window.Alpine) return;

  // ✅ guard supaya plugin tidak kepasang berulang (kalau alpine:init kepanggil lagi)
  if (!window.__msaFocusInstalled) {
    window.Alpine.plugin(focus);
    window.__msaFocusInstalled = true;
  }
});

import flatpickr from "flatpickr";
import "flatpickr/dist/flatpickr.min.css";

/**
 * =========================================================
 * FLATPICKR
 * - Prevent double init via data flag
 * - Default value: now() if empty
 * - Auto close after selecting date
 * =========================================================
 */
function initDateTimePickers(root = document) {
  const els = root.querySelectorAll(".js-datetime:not([data-fp-initialized])");
  if (!els.length) return;

  els.forEach((el) => {
    el.setAttribute("data-fp-initialized", "1");

    flatpickr(el, {
      enableTime: true,
      time_24hr: true,
      dateFormat: "Y-m-d H:i",
      allowInput: true,

      onReady(_, __, instance) {
        if (!el.value) instance.setDate(new Date(), true);
      },

      onChange(selectedDates, __, instance) {
        if (selectedDates.length) instance.close();
      },
    });
  });
}

/**
 * =========================================================
 * RUPIAH INPUT FORMATTER
 * - Attach handler hanya sekali per input (data-rp-initialized)
 * =========================================================
 */
function formatRpDigits(digits) {
  if (!digits) return "";
  const withDots = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
  return "Rp " + withDots + ",-";
}

function onlyDigits(v) {
  return String(v || "").replace(/[^\d]/g, "");
}

function initRupiahInputs(root = document) {
  const inputs = root.querySelectorAll(".js-rupiah:not([data-rp-initialized])");
  if (!inputs.length) return;

  inputs.forEach((display) => {
    display.setAttribute("data-rp-initialized", "1");

    const hidden = display.parentElement.querySelector('input[type="hidden"][name]');
    if (!hidden) return;

    // init dari hidden (kalau ada)
    if (hidden.value) {
      display.value = formatRpDigits(onlyDigits(hidden.value));
    } else if (display.value) {
      const digits = onlyDigits(display.value);
      hidden.value = digits;
      display.value = formatRpDigits(digits);
    }

    const sync = () => {
      const digits = onlyDigits(display.value);
      hidden.value = digits;
      display.value = formatRpDigits(digits);
    };

    display.addEventListener("input", sync);
    display.addEventListener("blur", sync);
  });
}

/**
 * =========================================================
 * BOOTSTRAP INITIALIZERS
 * - DOMContentLoaded: initial load
 * - livewire:load & livewire:navigated: Livewire v3 navigation / rerender
 * =========================================================
 */
function initAll(root = document) {
  initDateTimePickers(root);
  initRupiahInputs(root);
}

// ✅ Normal full page load
document.addEventListener("DOMContentLoaded", () => {
  initAll(document);
});

// ✅ Livewire v3: rerender & navigasi
document.addEventListener("livewire:load", () => {
  initAll(document);
});

document.addEventListener("livewire:navigated", () => {
  initAll(document);
});
