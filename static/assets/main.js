(function () {
  // Мобильное меню
  var burger = document.querySelector(".burger");
  var nav = document.getElementById("nav");
  if (burger && nav) {
    burger.addEventListener("click", function () {
      var open = nav.classList.toggle("open");
      burger.setAttribute("aria-expanded", open ? "true" : "false");
    });
    nav.addEventListener("click", function (ev) {
      if (ev.target.closest("a")) {
        nav.classList.remove("open");
        burger.setAttribute("aria-expanded", "false");
      }
    });
  }

  // Появление блоков при прокрутке (CSS-переходы, без тяжёлых библиотек)
  var items = document.querySelectorAll(".rv");
  if ("IntersectionObserver" in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { en.target.classList.add("in"); io.unobserve(en.target); }
      });
    }, { rootMargin: "0px 0px -8% 0px", threshold: 0.05 });
    items.forEach(function (el) { io.observe(el); });
  } else {
    items.forEach(function (el) { el.classList.add("in"); });
  }

  // Маска телефона: +7 (7XX) XXX-XX-XX. 8 в начале → 7, вставка «+7 701…» и «8701…» тоже работает.
  function phoneDigits(v) {
    var d = String(v).replace(/\D/g, "");
    if (d.charAt(0) === "8") d = "7" + d.slice(1);
    if (d && d.charAt(0) !== "7") d = "7" + d;
    return d.slice(0, 11);
  }
  // Скобка и дефисы ставятся только когда за ними уже есть цифры — иначе Backspace «упирается» в них.
  function phoneFormat(d) {
    if (!d) return "";
    var r = "+7";
    if (d.length > 1) r += " (" + d.slice(1, 4);
    if (d.length > 4) r += ") " + d.slice(4, 7);
    if (d.length > 7) r += "-" + d.slice(7, 9);
    if (d.length > 9) r += "-" + d.slice(9, 11);
    return r;
  }
  // Простая маска — запасной вариант, пока грузится (или если не загрузилась) intl-tel-input.
  document.querySelectorAll("input[data-phone]").forEach(function (inp) {
    inp.addEventListener("focus", function () { if (!inp._iti && !inp.value) inp.value = "+7 ("; });
    inp.addEventListener("blur", function () { if (!inp._iti && phoneDigits(inp.value).length <= 1) inp.value = ""; });
    inp.addEventListener("input", function () {
      if (inp._iti) return;
      var raw = inp.value.replace(/\D/g, "");
      // вставили 10 цифр без кода страны (7012345678) — код 7 дописываем, а не принимаем первую 7 за него
      var d = inp.value.indexOf("+") < 0 && raw.length === 10 ? "7" + raw : phoneDigits(inp.value);
      inp.value = d.length > 1 ? phoneFormat(d) : (inp.value ? "+7 (" : "");
      inp.setCustomValidity("");
    });
  });

  // +7 — Казахстан и Россия. База Google пропускает зарезервированные, но пустые диапазоны (+7 666…),
  // поэтому сверяем с реальными кодами: KZ мобильные 700–708, 747, 771–778, городские 71x/72x; RU 3xx/4xx/8xx/9xx.
  // Та же проверка — на сервере (api/send.php).
  function kzRuOk(e164) {
    var d = String(e164 || "").replace(/\D/g, "");
    if (d.charAt(0) !== "7") return true;
    return d.length === 11 && /^7(?:70[0-8]|747|77[1-8]|7[12]\d|[3489]\d\d)/.test(d);
  }

  // Телефон: intl-tel-input (github.com/jackocnr/intl-tel-input) — флаг и выбор страны, по умолчанию Казахстан;
  // формат и проверка по базе Google libphonenumber (коды операторов: +7 707, +7 747… — да, +7 666 — нет).
  // Грузим после загрузки страницы, чтобы не тормозить первый показ.
  var ITI = "https://cdn.jsdelivr.net/npm/intl-tel-input@29.5.3/dist/";
  var phones = document.querySelectorAll("input[data-phone]");
  if (phones.length) window.addEventListener("load", function () {
    var css = document.createElement("link");
    css.rel = "stylesheet"; css.href = ITI + "css/intlTelInput.min.css";
    document.head.appendChild(css);
    var js = document.createElement("script");
    js.src = ITI + "js/intlTelInput.min.js";
    js.onload = function () {
      var kk = document.documentElement.lang === "kk";
      phones.forEach(function (inp) {
        var digits = phoneDigits(inp.value);
        inp.value = digits.length > 1 ? "+" + digits : "";
        inp.placeholder = "";
        inp.removeAttribute("maxlength");
        inp._iti = window.intlTelInput(inp, {
          initialCountry: "kz",
          countryOrder: ["kz", "ru", "uz", "kg"],
          separateDialCode: true,
          strictMode: true,
          countryNameLocale: kk ? "kk" : "ru",
          allowedNumberTypes: ["MOBILE", "FIXED_LINE"],
          uiTranslations: { searchPlaceholder: kk ? "Іздеу" : "Поиск", searchEmptyState: kk ? "Ештеңе табылмады" : "Ничего не найдено" },
          loadUtils: function () { return import(ITI + "js/utils.js"); }
        });
      });
    };
    document.head.appendChild(js);
  });

  // Форма заявки → /api/send.php → Telegram
  document.querySelectorAll("form.form").forEach(function (form) {
    var ts = form.querySelector('input[name="ts"]');
    if (ts) ts.value = String(Date.now());
    var status = form.querySelector(".form-status");
    var btn = form.querySelector('button[type="submit"]');
    var btnHtml = btn.innerHTML;

    form.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var phone = form.querySelector("input[data-phone]");
      var iti = phone && phone._iti;
      // isValidNumber() = null, пока не догрузилась база номеров, — тогда проверит сервер
      var bad = phone && (iti ? iti.isValidNumber() === false || !phone.value.trim() || !kzRuOk(iti.getNumber())
                              : phoneDigits(phone.value).length !== 11 || !kzRuOk("+" + phoneDigits(phone.value)));
      var consent = form.querySelector('input[name="consent"]');
      if (consent && !consent.checked) {
        status.className = "form-status err";
        status.textContent = form.dataset.consentErr;
        consent.focus();
        return;
      }
      if (bad) {
        status.className = "form-status err";
        status.textContent = phone.dataset.err;
        phone.focus();
        return;
      }
      var data = new FormData(form);
      if (iti) data.set("contact", iti.getNumber() || phone.value);
      btn.disabled = true;
      btn.textContent = form.dataset.sending;
      status.className = "form-status";
      status.textContent = "";

      fetch(form.action, { method: "POST", body: data, headers: { Accept: "application/json" } })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (res) {
          if (!res.ok) throw new Error("send failed");
          if (window.clarity) clarity("event", "lead");   // заявка — отметка в Clarity для фильтра записей
          form.reset();
          if (iti) iti.setNumber("");
          if (ts) ts.value = String(Date.now());
          status.className = "form-status ok";
          status.textContent = form.dataset.ok;
        })
        .catch(function () {
          status.className = "form-status err";
          status.textContent = form.dataset.err;
        })
        .finally(function () {
          btn.disabled = false;
          btn.innerHTML = btnHtml;
        });
    });
  });
})();
