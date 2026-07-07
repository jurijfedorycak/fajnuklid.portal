<script setup>
import {
  ref,
  computed,
  onMounted,
  onBeforeUnmount,
  watch,
  nextTick,
} from "vue";
import { RouterLink } from "vue-router";
import { Doughnut } from "vue-chartjs";
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from "chart.js";
import {
  Calendar,
  ChevronDown,
  ChevronRight,
  ArrowRight,
  CheckCircle2,
  Loader2,
  ClipboardList,
  Plus,
  Sparkles,
  FileText,
  BarChart3,
  Users,
  FileSignature,
  MapPin,
  Sunrise,
  Sun,
  Sunset,
  Moon,
} from "lucide-vue-next";
import {
  dashboardService,
  maintenanceRequestService,
  REQUEST_STATUSES,
} from "../api";
import { useAuth } from "../stores/auth";
import ReviewPromptCard from "../components/dashboard/ReviewPromptCard.vue";

// ── Maintenance request widget ──────────────────────────────────────────────
const requestsWidgetLoading = ref(true);
const latestOpenRequest = ref(null);

async function fetchLatestOpenRequest() {
  requestsWidgetLoading.value = true;
  try {
    const res = await maintenanceRequestService.list({
      status: "open",
      limit: 1,
    });
    if (res.success && Array.isArray(res.data) && res.data.length) {
      latestOpenRequest.value = res.data[0];
    } else {
      latestOpenRequest.value = null;
    }
  } catch (e) {
    latestOpenRequest.value = null;
  } finally {
    requestsWidgetLoading.value = false;
  }
}

function requestStatusMeta(key) {
  return (
    REQUEST_STATUSES.find((s) => s.key === key) || {
      label: key,
      badge: "badge-gray",
    }
  );
}

function formatRequestDate(d) {
  if (!d) return "";
  return new Date(d).toLocaleDateString("cs-CZ", {
    day: "numeric",
    month: "numeric",
    year: "numeric",
  });
}

ChartJS.register(ArcElement, Tooltip, Legend);

const { user, attendanceEnabled } = useAuth();

// ── State ────────────────────────────────────────────────────────────────────
const loading = ref(true);
const refetching = ref(false);
const error = ref(null);
// Guards against double-fetch on initial mount when the watcher fires after
// the server-validated activeIco syncs back into local state.
let initialFetchDone = false;
// Token used to discard responses from superseded fetches (e.g. rapid IČO clicks).
let fetchToken = 0;

// Reactive "today" — refreshed on tab visibility change and a 60-second tick so
// month labels and greetings stay correct on dashboards left open overnight.
// `today` exposes both a Date snapshot and the ISO string FE callers use.
const today = ref(new Date());
let todayInterval = null;
const todayIso = computed(() => formatISODate(today.value));
const todayYear = computed(() => today.value.getFullYear());
const todayMonth = computed(() => today.value.getMonth());

function refreshToday() {
  const next = new Date();
  // Compare by Y/M/D so we only retrigger reactivity when the calendar day
  // actually changes — a 60-second interval would otherwise rerun every
  // computed every minute.
  const cur = today.value;
  if (
    next.getFullYear() !== cur.getFullYear() ||
    next.getMonth() !== cur.getMonth() ||
    next.getDate() !== cur.getDate()
  ) {
    today.value = next;
  }
}

function handleVisibilityChange() {
  if (document.visibilityState === "visible") {
    refreshToday();
  }
}

const startOfYearStr = `${today.value.getFullYear()}-01-01`;
const todayStr = formatISODate(today.value);

const range = ref({ from: startOfYearStr, to: todayStr });
const activeIco = ref(user.value?.active_ico || null);

const dashboardData = ref({
  currentUser: null,
  companies: [],
  activeIco: null,
  dateRange: { from: startOfYearStr, to: todayStr },
  overview: {
    invoices: {
      total: 0,
      paidCount: 0,
      unpaidCount: 0,
      overdueCount: 0,
      nextDue: null,
    },
    personnel: { count: 0, locationName: "" },
    contract: { hasPdf: false, contractsEnabled: false },
  },
  cleaningDays: [],
  lastCleaningDate: null,
  ongoingCleaning: null,
  reviewPrompt: null,
  recentInvoices: [],
});

const reviewPrompt = computed(() => dashboardData.value.reviewPrompt || null);

// ── Fetch ────────────────────────────────────────────────────────────────────
async function fetchDashboard(initial = false) {
  const myToken = ++fetchToken;
  if (initial) {
    loading.value = true;
  } else {
    refetching.value = true;
  }
  error.value = null;
  try {
    const response = await dashboardService.getDashboard({
      ico: activeIco.value || undefined,
      from: range.value.from,
      to: range.value.to,
    });
    // Discard stale responses (user clicked a different IČO mid-flight)
    if (myToken !== fetchToken) return;
    if (response.success) {
      dashboardData.value = response.data;
      // Sync local state with server-validated values (e.g. fallback IČO).
      // Set initialFetchDone BEFORE the assignment so the watcher can fire
      // exactly once if the value actually changes.
      if (
        response.data.activeIco &&
        response.data.activeIco !== activeIco.value
      ) {
        // Suppress watcher reaction to this server-driven sync.
        suppressWatch = true;
        activeIco.value = response.data.activeIco;
      }
    } else {
      error.value = response.message || "Nepodařilo se načíst data";
    }
  } catch (err) {
    if (myToken !== fetchToken) return;
    error.value =
      err.response?.data?.message || err.message || "Nepodařilo se načíst data";
  } finally {
    if (myToken === fetchToken) {
      loading.value = false;
      refetching.value = false;
      initialFetchDone = true;
    }
  }
}

let suppressWatch = false;

onMounted(() => fetchDashboard(true));
onMounted(fetchLatestOpenRequest);

watch([activeIco, () => range.value.from, () => range.value.to], () => {
  if (suppressWatch) {
    suppressWatch = false;
    return;
  }
  if (!initialFetchDone) return;
  fetchDashboard(false);
});

// When the wall-clock day rolls over (e.g. a dashboard left open past midnight),
// refresh the dashboard so the "Úklidy – {month}" widget catches up with
// today's data instead of pinning yesterday's ongoing cleaning.
watch(todayIso, (_next, _prev) => {
  if (!initialFetchDone) return;
  fetchDashboard(false);
});

// ── Computed slices ─────────────────────────────────────────────────────────
const companies = computed(() => dashboardData.value.companies || []);
const overview = computed(() => dashboardData.value.overview || {});
const invoicesOverview = computed(() => overview.value.invoices || {});
const personnelOverview = computed(() => overview.value.personnel || {});
const contract = computed(() => overview.value.contract || { hasPdf: false });
const recentInvoices = computed(() => dashboardData.value.recentInvoices || []);
const cleaningDays = computed(() => dashboardData.value.cleaningDays || []);
const ongoingCleaning = computed(
  () => dashboardData.value.ongoingCleaning || null,
);

// Greeting text + matching time-of-day icon (sunrise → sun → sunset → moon).
// Reads the hour from the reactive `today` snapshot so a dashboard reopened on
// a new day picks up the correct part of the day. Sun icons carry a warm tone,
// the night moon a cooler one — both via CSS tokens (no hardcoded colors).
const timeOfDay = computed(() => {
  const h = today.value.getHours();
  if (h >= 5 && h < 12)
    return { text: "Dobré ráno", icon: Sunrise, color: "var(--color-warning)" };
  if (h >= 12 && h < 18)
    return {
      text: "Dobré odpoledne",
      icon: Sun,
      color: "var(--color-warning)",
    };
  if (h >= 18 && h < 22)
    return { text: "Dobrý večer", icon: Sunset, color: "var(--color-warning)" };
  return { text: "Dobrý večer", icon: Moon, color: "var(--color-mid)" };
});

const greetingTarget = computed(() => {
  const personal = dashboardData.value.currentUser?.greeting;
  if (typeof personal === "string" && personal.trim() !== "") {
    return personal.trim();
  }
  const fullName =
    dashboardData.value.currentUser?.displayName ||
    user.value?.display_name ||
    user.value?.email ||
    "Klient";
  return fullName.split(" ")[0];
});

// ── Donut chart ─────────────────────────────────────────────────────────────
// Read color tokens from the CSS custom properties defined in style.css
// (CLAUDE.md rule 3: never hardcode color values).
function cssVar(name) {
  if (typeof window === "undefined") return "";
  return getComputedStyle(document.documentElement)
    .getPropertyValue(name)
    .trim();
}

const chartData = computed(() => ({
  labels: ["Zaplaceno", "Nezaplaceno", "Po splatnosti"],
  datasets: [
    {
      data: [
        invoicesOverview.value.paidCount || 0,
        invoicesOverview.value.unpaidCount || 0,
        invoicesOverview.value.overdueCount || 0,
      ],
      backgroundColor: [
        cssVar("--color-success"),
        cssVar("--color-gray-300"),
        cssVar("--color-danger"),
      ],
      borderWidth: 0,
      // Hover "pop-out" disabled per client request — segments stay put on hover.
      hoverOffset: 0,
      hoverBorderWidth: 0,
    },
  ],
}));

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  // Kill the hover highlight/pop entirely: no active-element animation and the
  // tooltip is suppressed so the donut reads as a static overview graphic.
  events: [],
  animation: { animateRotate: true, animateScale: false },
  plugins: {
    legend: { display: false },
    tooltip: { enabled: false },
  },
  cutout: "70%",
};

// ── Date range picker ───────────────────────────────────────────────────────
const datePickerOpen = ref(false);
const customFrom = ref(range.value.from);
const customTo = ref(range.value.to);
const datePickerWrapRef = ref(null);

function onDocumentClick(e) {
  if (!datePickerOpen.value) return;
  if (datePickerWrapRef.value && !datePickerWrapRef.value.contains(e.target)) {
    datePickerOpen.value = false;
  }
}

function onDocumentKeydown(e) {
  if (e.key === "Escape" && datePickerOpen.value) {
    datePickerOpen.value = false;
    // Return focus to the trigger button for accessibility
    nextTick(() =>
      document.getElementById("dashboard-date-range-btn")?.focus(),
    );
  }
}

onMounted(() => {
  document.addEventListener("mousedown", onDocumentClick);
  document.addEventListener("keydown", onDocumentKeydown);
  document.addEventListener("visibilitychange", handleVisibilityChange);
  todayInterval = setInterval(refreshToday, 60_000);
});

onBeforeUnmount(() => {
  document.removeEventListener("mousedown", onDocumentClick);
  document.removeEventListener("keydown", onDocumentKeydown);
  document.removeEventListener("visibilitychange", handleVisibilityChange);
  if (todayInterval !== null) {
    clearInterval(todayInterval);
    todayInterval = null;
  }
});

const PRESETS = [
  { id: "thisMonth", label: "Tento měsíc" },
  { id: "lastMonth", label: "Minulý měsíc" },
  { id: "thisYear", label: "Tento rok" },
  { id: "lastYear", label: "Minulý rok" },
  { id: "custom", label: "Vlastní" },
];

const activePreset = ref("thisYear");

function applyPreset(id) {
  const now = new Date();
  let from, to;
  if (id === "thisMonth") {
    from = new Date(now.getFullYear(), now.getMonth(), 1);
    to = now;
  } else if (id === "lastMonth") {
    from = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    to = new Date(now.getFullYear(), now.getMonth(), 0);
  } else if (id === "thisYear") {
    from = new Date(now.getFullYear(), 0, 1);
    to = now;
  } else if (id === "lastYear") {
    from = new Date(now.getFullYear() - 1, 0, 1);
    to = new Date(now.getFullYear() - 1, 11, 31);
  } else {
    activePreset.value = "custom";
    return;
  }
  activePreset.value = id;
  range.value = { from: formatISODate(from), to: formatISODate(to) };
  customFrom.value = range.value.from;
  customTo.value = range.value.to;
  datePickerOpen.value = false;
}

function applyCustomRange() {
  if (!customFrom.value || !customTo.value) return;
  let from = customFrom.value;
  let to = customTo.value;
  if (from > to) [from, to] = [to, from];
  range.value = { from, to };
  activePreset.value = "custom";
  datePickerOpen.value = false;
}

function cancelCustomRange() {
  customFrom.value = range.value.from;
  customTo.value = range.value.to;
  datePickerOpen.value = false;
}

const dateRangeLabel = computed(
  () => `${formatCsDate(range.value.from)} – ${formatCsDate(range.value.to)}`,
);

// Gated on attendanceEnabled so the chip follows the same "hide all FreshQR UI
// when off" rule as the calendars and the live pill.
const lastCleaningDate = computed(() =>
  attendanceEnabled.value ? dashboardData.value.lastCleaningDate || null : null,
);

// ── Cleaning summary (per-month counts, computed from each calendar's cells) ─
function countDone(cells) {
  let n = 0;
  for (const c of cells) if (c.status === "done") n++;
  return n;
}

// True when the client has no data at all — used to show a WOW onboarding hero
// instead of a wall of zeros on the brand-new client's first visit.
const isBrandNewClient = computed(() => {
  return (
    (invoicesOverview.value.total || 0) === 0 &&
    cleaningDays.value.length === 0 &&
    !contract.value.hasPdf
  );
});

// Onboarding hero steps. The attendance step is dropped for clients with no
// activated QR system so the brand-new hero never promises a calendar the
// portal won't show them; step numbers come from the rendered index so the
// sequence stays 1..N without gaps.
const onboardingSteps = computed(() => {
  const steps = [
    {
      id: "personnel",
      title: "Poznejte svůj tým",
      desc: "Zjistěte, kdo se o vás postará a jak se s ním spojit.",
    },
    {
      id: "attendance",
      title: "Sledujte docházku",
      desc: "V kalendáři hned uvidíte, kdy se u vás uklízelo.",
    },
    {
      id: "request",
      title: "Pošlete požadavek",
      desc: "Reklamace, dotaz, cokoliv – odpovíme co nejdříve.",
    },
  ];
  return attendanceEnabled.value
    ? steps
    : steps.filter((s) => s.id !== "attendance");
});

const hasAnyInvoiceData = computed(
  () => (invoicesOverview.value.total || 0) > 0,
);
const hasOverdueInvoices = computed(
  () => (invoicesOverview.value.overdueCount || 0) > 0,
);

// Single source for the Splatnost stat card. Priority mirrors the previous
// metric: a concrete upcoming due date beats the aggregate states.
const dueStatus = computed(() => {
  const next = invoicesOverview.value.nextDue;
  if (next) {
    const d = next.daysRelative;
    const label =
      d === 0
        ? "dnes"
        : d === 1
        ? "zítra"
        : `za ${d} ${d >= 2 && d <= 4 ? "dny" : "dní"}`;
    return {
      kind: "next",
      label,
      badge: next.documentNumber,
    };
  }
  if (hasOverdueInvoices.value) {
    return {
      kind: "overdue",
      label: `${invoicesOverview.value.overdueCount} po splatnosti`,
    };
  }
  if (hasAnyInvoiceData.value) {
    return { kind: "paid", label: "Splaceno" };
  }
  return { kind: "none", label: "Zatím žádné faktury" };
});

const personnelCountLabel = computed(() => {
  const n = personnelOverview.value.count || 0;
  const word =
    n === 1 ? "pracovník" : n >= 2 && n <= 4 ? "pracovníci" : "pracovníků";
  return `${n} ${word}`;
});

const MONTHS = [
  "leden",
  "únor",
  "březen",
  "duben",
  "květen",
  "červen",
  "červenec",
  "srpen",
  "září",
  "říjen",
  "listopad",
  "prosinec",
];
const currentMonthLabel = computed(
  () => `${MONTHS[todayMonth.value]} ${todayYear.value}`,
);

const WEEKDAYS_SHORT = ["Po", "Út", "St", "Čt", "Pá", "So", "Ne"];

const cleaningByDate = computed(() => {
  const map = new Map();
  for (const d of cleaningDays.value) map.set(d.date, d);
  return map;
});

function buildMonthCells(year, month, keyPrefix) {
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  // Convert JS Sun=0..Sat=6 to Mon-first Po=0..Ne=6 so cells align under the headers.
  const leadingBlanks = (new Date(year, month, 1).getDay() + 6) % 7;

  const cells = [];
  for (let i = 0; i < leadingBlanks; i++) {
    cells.push({ key: `${keyPrefix}-pre-${i}`, blank: true });
  }
  for (let d = 1; d <= daysInMonth; d++) {
    const iso = formatISODate(new Date(year, month, d));
    const cleaning = cleaningByDate.value.get(iso);
    cells.push({
      key: iso,
      date: iso,
      day: d,
      isToday: iso === todayIso.value,
      status: cleaning?.status || null,
    });
  }
  while (cells.length % 7 !== 0) {
    cells.push({ key: `${keyPrefix}-post-${cells.length}`, blank: true });
  }
  return cells;
}

const calendarCells = computed(() =>
  buildMonthCells(todayYear.value, todayMonth.value, "cur"),
);

const prevMonthYear = computed(() =>
  todayMonth.value === 0 ? todayYear.value - 1 : todayYear.value,
);
const prevMonthIndex = computed(() =>
  todayMonth.value === 0 ? 11 : todayMonth.value - 1,
);
const prevMonthLabel = computed(
  () => `${MONTHS[prevMonthIndex.value]} ${prevMonthYear.value}`,
);
const prevMonthCells = computed(() =>
  buildMonthCells(prevMonthYear.value, prevMonthIndex.value, "prev"),
);

const currentDoneCount = computed(() => countDone(calendarCells.value));
const prevDoneCount = computed(() => countDone(prevMonthCells.value));

// ── Helpers ─────────────────────────────────────────────────────────────────
function formatISODate(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

function formatCsDate(iso) {
  if (!iso) return "";
  const [y, m, d] = iso.split("-");
  return `${parseInt(d, 10)}. ${parseInt(m, 10)}. ${y}`;
}

function formatAmount(n, currency = "Kč") {
  return `${Number(n).toLocaleString("cs-CZ")} ${currency}`;
}

function statusBadge(status) {
  if (status === "paid") return { cls: "badge-success", label: "Zaplaceno" };
  if (status === "overdue")
    return { cls: "badge-danger", label: "Po splatnosti" };
  return { cls: "badge-info", label: "Nezaplaceno" };
}

function selectCompany(ico) {
  if (ico === activeIco.value) return;
  activeIco.value = ico;
}
</script>

<template>
  <div id="dashboard-page" class="page-shell page-shell--lg">
    <!-- Loading state -->
    <div
      v-if="loading"
      id="dashboard-loading"
      class="dash-card dashboard-loading-state"
      role="status"
      aria-live="polite"
    >
      <Loader2 :size="32" class="spin dashboard-loading-icon" />
      <p class="dashboard-loading-text">Načítám přehled...</p>
    </div>

    <!-- Error state -->
    <div
      v-else-if="error"
      id="dashboard-error"
      class="alert alert-danger dashboard-error-state"
    >
      <span>{{ error }}</span>
      <button
        id="dashboard-error-retry-btn"
        type="button"
        class="btn btn-sm btn-outline"
        @click="fetchDashboard(true)"
      >
        Zkusit znovu
      </button>
    </div>

    <!-- Content -->
    <template v-else>
      <!-- Hero: warm time-of-day icon + two-line greeting + meta chips -->
      <header id="dashboard-hero" class="dashboard-hero">
        <div id="dashboard-hero-top" class="hero-top">
          <span id="dashboard-hero-icon" class="hero-icon" aria-hidden="true">
            <component
              :is="timeOfDay.icon"
              :size="24"
              :style="{ color: timeOfDay.color }"
            />
          </span>
          <h1 id="dashboard-greeting" class="dashboard-greeting">
            <span id="dashboard-greeting-time" class="greeting-line"
              >{{ timeOfDay.text }},</span
            >
            <span id="dashboard-greeting-name" class="greeting-name">{{
              greetingTarget
            }}</span>
          </h1>
        </div>

        <div
          v-if="
            personnelOverview.locationName ||
            lastCleaningDate ||
            companies.length >= 2
          "
          id="dashboard-hero-chips"
          class="hero-chips"
        >
          <span
            v-if="personnelOverview.locationName"
            id="dashboard-chip-location"
            class="hero-chip"
          >
            <MapPin :size="13" aria-hidden="true" />
            {{ personnelOverview.locationName }}
          </span>
          <span
            v-if="lastCleaningDate"
            id="dashboard-chip-last-cleaning"
            class="hero-chip"
          >
            <Calendar :size="13" aria-hidden="true" />
            Poslední úklid: {{ formatCsDate(lastCleaningDate) }}
          </span>
          <span
            v-if="companies.length >= 2"
            id="dashboard-company-switcher"
            class="hero-chip-group"
            role="group"
            aria-label="Přepínač společnosti"
          >
            <button
              v-for="company in companies"
              :key="company.id"
              :id="`dashboard-company-chip-${company.id}`"
              type="button"
              class="hero-chip hero-chip-company"
              :class="{ active: company.ico === activeIco }"
              :aria-pressed="company.ico === activeIco"
              :title="`${company.name} (IČO: ${company.ico})`"
              @click="selectCompany(company.ico)"
            >
              {{ company.name }}
            </button>
          </span>
        </div>
      </header>

      <!-- Live "cleaning in progress" pill — only while a cleaner is on-site.
           Gated on attendanceEnabled too so it follows the same "hide all FreshQR
           UI when off" rule as the Úklidy card, rather than relying solely on the
           backend withholding ongoingCleaning. Links to the attendance detail. -->
      <div
        v-if="attendanceEnabled && ongoingCleaning"
        id="dashboard-live-cleaning"
        class="live-pill-wrap"
        role="status"
        aria-live="polite"
      >
        <RouterLink
          id="dashboard-live-cleaning-link"
          to="/dochazka"
          class="live-pill"
        >
          <span class="live-pill-row">
            <span class="live-pill-indicator" aria-hidden="true">
              <span class="live-pill-dot" />
            </span>
            <span class="live-pill-title">Úklid právě probíhá</span>
            <ArrowRight :size="14" class="live-pill-arrow" aria-hidden="true" />
          </span>
        </RouterLink>
      </div>

      <!-- Brand-new-client onboarding hero -->
      <section
        v-if="isBrandNewClient"
        id="dashboard-onboarding-hero"
        class="onboarding-hero dashboard-onboarding"
      >
        <span class="onboarding-hero-badge">
          <Sparkles :size="12" aria-hidden="true" />
          Vítejte ve vašem portálu
        </span>
        <div class="onboarding-hero-icon">
          <Sparkles :size="28" aria-hidden="true" />
        </div>
        <h2 id="dashboard-onboarding-title" class="onboarding-hero-title">
          Všechno důležité o úklidu vašich prostor na jednom místě
        </h2>
        <p id="dashboard-onboarding-desc" class="onboarding-hero-desc">
          Až začnou probíhat první úklidy a přijdou vaše první faktury, uvidíte
          je tady. Mezitím se můžete rozhlédnout nebo nám napsat jakoukoliv
          zprávu.
        </p>

        <div class="onboarding-hero-steps">
          <div
            v-for="(step, index) in onboardingSteps"
            :key="step.id"
            class="onboarding-hero-step"
            :id="`dashboard-onboarding-step-${step.id}`"
          >
            <span class="onboarding-hero-step-num">{{ index + 1 }}</span>
            <div class="onboarding-hero-step-text">
              <span class="onboarding-hero-step-title">{{ step.title }}</span>
              <span class="onboarding-hero-step-desc">{{ step.desc }}</span>
            </div>
          </div>
        </div>

        <div class="onboarding-hero-actions">
          <RouterLink
            id="dashboard-onboarding-cta-personnel"
            to="/personal"
            class="btn btn-primary btn-sm"
          >
            <Users :size="14" aria-hidden="true" />
            <span>Poznat svůj tým</span>
          </RouterLink>
          <RouterLink
            id="dashboard-onboarding-cta-request"
            to="/zadosti/nova"
            class="btn btn-outline btn-sm"
          >
            <Plus :size="14" aria-hidden="true" />
            <span>Vytvořit požadavek</span>
          </RouterLink>
        </div>
      </section>

      <!-- Date range field — filters the invoice stats below -->
      <div
        ref="datePickerWrapRef"
        id="dashboard-date-picker-wrap"
        class="date-picker-wrap"
        :class="{ 'is-refetching': refetching }"
      >
        <button
          id="dashboard-date-range-btn"
          type="button"
          class="date-range-field"
          :aria-expanded="datePickerOpen"
          aria-haspopup="dialog"
          aria-controls="dashboard-date-picker-popover"
          @click="datePickerOpen = !datePickerOpen"
        >
          <Calendar :size="17" class="date-range-icon" aria-hidden="true" />
          <span id="dashboard-date-range-label" class="date-range-label">{{
            dateRangeLabel
          }}</span>
          <ChevronDown
            :size="16"
            class="date-range-chevron"
            aria-hidden="true"
          />
        </button>
        <div
          v-if="datePickerOpen"
          id="dashboard-date-picker-popover"
          class="date-popover"
          role="group"
          aria-label="Vybrat období"
        >
          <div class="chip-group date-presets">
            <button
              v-for="preset in PRESETS"
              :key="preset.id"
              :id="`dashboard-date-preset-${preset.id}`"
              type="button"
              class="chip"
              :class="{ active: activePreset === preset.id }"
              @click="applyPreset(preset.id)"
            >
              {{ preset.label }}
            </button>
          </div>
          <div
            v-if="activePreset === 'custom'"
            id="dashboard-date-custom"
            class="date-custom"
          >
            <div class="form-group">
              <label for="dashboard-date-custom-from" class="form-label"
                >Od</label
              >
              <input
                id="dashboard-date-custom-from"
                v-model="customFrom"
                type="date"
                class="form-input"
              />
            </div>
            <div class="form-group">
              <label for="dashboard-date-custom-to" class="form-label"
                >Do</label
              >
              <input
                id="dashboard-date-custom-to"
                v-model="customTo"
                type="date"
                class="form-input"
              />
            </div>
            <div class="date-custom-actions">
              <button
                id="dashboard-date-custom-cancel"
                type="button"
                class="btn btn-ghost btn-sm"
                @click="cancelCustomRange"
              >
                Zrušit
              </button>
              <button
                id="dashboard-date-custom-apply"
                type="button"
                class="btn btn-primary btn-sm"
                @click="applyCustomRange"
              >
                Použít
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Stat card grid: Faktury / Splatnost / Tým / Smlouva -->
      <section
        id="dashboard-stat-grid"
        class="stat-grid"
        :class="{ 'is-refetching': refetching }"
      >
        <RouterLink
          id="dashboard-stat-invoices"
          to="/faktury"
          class="dash-card stat-card"
        >
          <span id="dashboard-stat-invoices-head" class="stat-head">
            <span class="stat-icon" aria-hidden="true">
              <FileText :size="17" />
            </span>
            <span class="stat-label">Faktury</span>
          </span>
          <span id="dashboard-stat-invoices-body" class="stat-body">
            <span
              id="dashboard-stat-invoices-count"
              class="stat-value"
              :class="{ 'stat-value-muted': !invoicesOverview.total }"
            >
              {{ invoicesOverview.total }}
            </span>
            <svg
              id="dashboard-stat-invoices-sparkline"
              class="stat-sparkline"
              viewBox="0 0 64 24"
              aria-hidden="true"
            >
              <path
                class="sparkline-area"
                d="M2 19 C 10 17, 15 12, 23 13 S 38 6, 46 8 S 59 4, 62 5 L 62 22 L 2 22 Z"
              />
              <path
                class="sparkline-line"
                d="M2 19 C 10 17, 15 12, 23 13 S 38 6, 46 8 S 59 4, 62 5"
              />
            </svg>
          </span>
        </RouterLink>

        <div id="dashboard-stat-due" class="dash-card stat-card">
          <span id="dashboard-stat-due-head" class="stat-head">
            <span class="stat-icon" aria-hidden="true">
              <BarChart3 :size="17" />
            </span>
            <span class="stat-label">Splatnost</span>
          </span>
          <span id="dashboard-stat-due-body" class="stat-body stat-body-status">
            <span
              id="dashboard-stat-due-status"
              class="stat-status"
              :class="`stat-status-${dueStatus.kind}`"
            >
              <span
                v-if="dueStatus.kind !== 'none'"
                class="stat-status-dot"
                aria-hidden="true"
              />
              {{ dueStatus.label }}
            </span>
            <span
              v-if="dueStatus.badge"
              id="dashboard-stat-due-doc"
              class="badge badge-info"
              >{{ dueStatus.badge }}</span
            >
          </span>
        </div>

        <RouterLink
          id="dashboard-stat-team"
          to="/personal"
          class="dash-card stat-card"
        >
          <span id="dashboard-stat-team-head" class="stat-head">
            <span class="stat-icon" aria-hidden="true">
              <Users :size="17" />
            </span>
            <span class="stat-label">Tým</span>
          </span>
          <span id="dashboard-stat-team-foot" class="team-foot">
            <span id="dashboard-stat-team-count" class="team-badge">{{
              personnelCountLabel
            }}</span>
            <ChevronRight :size="16" class="team-arrow" aria-hidden="true" />
          </span>
        </RouterLink>

        <div id="dashboard-stat-contract" class="dash-card stat-card">
          <span id="dashboard-stat-contract-head" class="stat-head">
            <span class="stat-icon" aria-hidden="true">
              <FileSignature :size="17" />
            </span>
            <span class="stat-label">Smlouva</span>
          </span>
          <span
            id="dashboard-stat-contract-body"
            class="stat-body stat-body-status"
          >
            <RouterLink
              v-if="contract.hasPdf"
              id="dashboard-stat-contract-link"
              to="/smlouva"
              class="stat-link"
            >
              Zobrazit
              <ArrowRight :size="14" aria-hidden="true" />
            </RouterLink>
            <span
              v-else
              id="dashboard-stat-contract-pending"
              class="stat-status stat-status-none"
              >Připravujeme</span
            >
          </span>
        </div>
      </section>

      <!-- Mid row: Docházka + Požadavky -->
      <section
        id="dashboard-mid-row"
        class="dashboard-mid-row"
        :class="{ 'mid-row-single': !attendanceEnabled }"
      >
        <article
          v-if="attendanceEnabled"
          id="dashboard-cleaning-card"
          class="dash-card cleaning-card"
        >
          <div class="card-header-row">
            <h3 id="dashboard-cleaning-title" class="card-title">
              Docházka a přehled
            </h3>
            <RouterLink
              id="dashboard-cleaning-detail-link"
              to="/dochazka"
              class="card-link"
            >
              Detail <ArrowRight :size="14" />
            </RouterLink>
          </div>

          <div class="cleaning-body">
            <div
              v-if="cleaningDays.length > 0"
              id="dashboard-cleaning-prev-calendar"
              class="mini-calendar prev-calendar"
              role="grid"
              :aria-label="`Kalendář úklidů – ${prevMonthLabel}`"
            >
              <div class="mc-month-row">
                <span class="mc-month-label" aria-hidden="true">{{
                  prevMonthLabel
                }}</span>
                <span class="mc-month-stat">
                  <CheckCircle2 :size="12" aria-hidden="true" />
                  {{ prevDoneCount }} proběhlo
                </span>
              </div>
              <div class="mc-header" role="row">
                <span
                  v-for="label in WEEKDAYS_SHORT"
                  :key="`prev-${label}`"
                  class="mc-weekday"
                  role="columnheader"
                  >{{ label }}</span
                >
              </div>
              <div class="mc-grid">
                <div
                  v-for="cell in prevMonthCells"
                  :key="cell.key"
                  :id="
                    cell.date ? `dashboard-cleaning-prev-${cell.date}` : null
                  "
                  class="mc-cell"
                  :class="{
                    'mc-blank': cell.blank,
                    'mc-done': cell.status === 'done',
                    'mc-ongoing': cell.status === 'ongoing',
                  }"
                  :role="cell.blank ? 'presentation' : 'gridcell'"
                  :title="cell.date || ''"
                >
                  <span v-if="!cell.blank" class="mc-num">{{ cell.day }}</span>
                </div>
              </div>
            </div>
            <div
              v-if="cleaningDays.length > 0"
              id="dashboard-cleaning-calendar"
              class="mini-calendar current-calendar"
              role="grid"
              :aria-label="`Kalendář úklidů – ${currentMonthLabel}`"
            >
              <div class="mc-month-row">
                <span class="mc-month-label" aria-hidden="true">{{
                  currentMonthLabel
                }}</span>
                <span class="mc-month-stat">
                  <CheckCircle2 :size="12" aria-hidden="true" />
                  {{ currentDoneCount }} proběhlo
                </span>
              </div>
              <div class="mc-header" role="row">
                <span
                  v-for="label in WEEKDAYS_SHORT"
                  :key="label"
                  class="mc-weekday"
                  role="columnheader"
                  >{{ label }}</span
                >
              </div>
              <div class="mc-grid">
                <div
                  v-for="cell in calendarCells"
                  :key="cell.key"
                  :id="cell.date ? `dashboard-cleaning-day-${cell.date}` : null"
                  class="mc-cell"
                  :class="{
                    'mc-blank': cell.blank,
                    'mc-done': cell.status === 'done',
                    'mc-ongoing': cell.status === 'ongoing',
                    'mc-today': cell.isToday,
                  }"
                  :role="cell.blank ? 'presentation' : 'gridcell'"
                  :title="cell.date || ''"
                >
                  <span v-if="!cell.blank" class="mc-num">{{ cell.day }}</span>
                </div>
              </div>
            </div>
            <div v-else id="dashboard-cleaning-empty" class="inline-empty">
              <span class="inline-empty-icon">
                <Calendar :size="22" aria-hidden="true" />
              </span>
              <span class="inline-empty-title">Zatím žádné úklidy</span>
              <span class="inline-empty-desc">
                Po prvním úklidu se tu rozsvítí zelené dny.
                <RouterLink to="/dochazka" class="inline-empty-link"
                  >Otevřít kalendář</RouterLink
                >
              </span>
            </div>
          </div>
        </article>

        <article id="dashboard-requests-card" class="dash-card requests-widget">
          <div class="card-header-row">
            <h3 id="dashboard-requests-title" class="card-title">
              <ClipboardList
                :size="18"
                class="card-title-icon"
                aria-hidden="true"
              />
              Požadavky a reklamace
            </h3>
            <RouterLink
              v-if="latestOpenRequest"
              id="dashboard-requests-all-link"
              to="/zadosti"
              class="card-link"
            >
              Zobrazit všechny <ArrowRight :size="14" />
            </RouterLink>
          </div>

          <div v-if="requestsWidgetLoading" class="requests-widget-loading">
            <Loader2 :size="18" class="spin" />
          </div>

          <RouterLink
            v-else-if="latestOpenRequest"
            id="dashboard-requests-latest"
            :to="`/zadosti/${latestOpenRequest.id}`"
            class="requests-widget-row"
          >
            <div class="rwr-main">
              <div class="rwr-title">{{ latestOpenRequest.title }}</div>
              <div class="rwr-meta">
                {{ formatRequestDate(latestOpenRequest.createdAt) }}
              </div>
            </div>
            <span
              class="badge"
              :class="requestStatusMeta(latestOpenRequest.status).badge"
            >
              {{ requestStatusMeta(latestOpenRequest.status).label }}
            </span>
          </RouterLink>

          <div
            v-else
            id="dashboard-requests-empty"
            class="requests-widget-empty"
          >
            Máte problém, dotaz nebo mimořádnou žádost? Vytvořte požadavek a my
            se vám co nejdříve ozveme s řešením.
          </div>

          <div class="requests-widget-actions">
            <RouterLink
              id="dashboard-requests-create-btn"
              to="/zadosti/nova"
              class="btn btn-primary btn-sm"
            >
              <Plus :size="14" />
              <span>Vytvořit požadavek</span>
            </RouterLink>
          </div>
        </article>
      </section>

      <!-- Bottom row: Donut + Recent invoices -->
      <section id="dashboard-bottom-row" class="dashboard-bottom-row">
        <article id="dashboard-chart-card" class="dash-card chart-card">
          <h3 id="dashboard-chart-title" class="card-title">Přehled faktur</h3>
          <template v-if="hasAnyInvoiceData">
            <div id="dashboard-chart-wrap" class="chart-wrap">
              <Doughnut :data="chartData" :options="chartOptions" />
            </div>
            <div class="chart-totals">
              <div id="dashboard-chart-legend-paid" class="total-row">
                <span class="total-dot total-dot-paid" />
                <span>Zaplaceno</span>
                <strong>{{ invoicesOverview.paidCount || 0 }}</strong>
              </div>
              <div id="dashboard-chart-legend-unpaid" class="total-row">
                <span class="total-dot total-dot-unpaid" />
                <span>Nezaplaceno</span>
                <strong>{{ invoicesOverview.unpaidCount || 0 }}</strong>
              </div>
              <div id="dashboard-chart-legend-overdue" class="total-row">
                <span class="total-dot total-dot-overdue" />
                <span>Po splatnosti</span>
                <strong>{{ invoicesOverview.overdueCount || 0 }}</strong>
              </div>
            </div>
          </template>
          <div v-else id="dashboard-chart-empty" class="inline-empty">
            <span class="inline-empty-icon">
              <FileText :size="22" aria-hidden="true" />
            </span>
            <span class="inline-empty-title">Zatím žádné faktury</span>
            <span class="inline-empty-desc">
              Jakmile vám vystavíme první fakturu, uvidíte tu rychlý přehled
              podle stavu.
            </span>
          </div>
        </article>

        <article
          id="dashboard-recent-invoices-card"
          class="dash-card recent-card"
        >
          <div class="card-header-row">
            <h3 id="dashboard-recent-title" class="card-title">
              Poslední faktury
            </h3>
            <RouterLink
              v-if="recentInvoices.length > 0"
              id="dashboard-recent-all-link"
              to="/faktury"
              class="card-link"
            >
              Zobrazit vše <ArrowRight :size="14" />
            </RouterLink>
          </div>
          <div v-if="recentInvoices.length > 0" class="table-wrap">
            <table id="dashboard-recent-invoices-table" class="data-table">
              <thead>
                <tr>
                  <th>Číslo</th>
                  <th>Splatnost</th>
                  <th class="text-right">Částka</th>
                  <th>Stav</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="inv in recentInvoices"
                  :id="`dashboard-recent-row-${inv.id}`"
                  :key="inv.id"
                >
                  <td class="fw-500">{{ inv.documentNumber }}</td>
                  <td>{{ formatCsDate(inv.dueDate) }}</td>
                  <td class="text-right fw-500">
                    {{ formatAmount(inv.amount, inv.currency || "Kč") }}
                  </td>
                  <td>
                    <span class="badge" :class="statusBadge(inv.status).cls">
                      {{ statusBadge(inv.status).label }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else id="dashboard-recent-empty" class="inline-empty">
            <span class="inline-empty-icon">
              <FileText :size="22" aria-hidden="true" />
            </span>
            <span class="inline-empty-title">Žádné faktury v tomto období</span>
            <span class="inline-empty-desc">
              Až vám fakturu vystavíme, objeví se tady na přehledu.
            </span>
          </div>
        </article>
      </section>

      <ReviewPromptCard
        v-if="reviewPrompt && reviewPrompt.show"
        :google-url="reviewPrompt.googleUrl"
      />
    </template>
  </div>
</template>

<style scoped>
/* ── Page shell & shared card ───────────────────────────────────────────── */
/* White card on the tinted page canvas — deliberately NOT the global .card
   (gray-50 on white), the dashboard inverts that relationship. */
.dash-card {
  background: var(--color-white);
  border: 1px solid var(--color-gray-100);
  border-radius: var(--radius-2xl);
  box-shadow: var(--shadow-card);
  padding: var(--space-lg);
}
@media (min-width: 480px) {
  .dash-card {
    padding: var(--space-xl);
  }
}

.date-picker-wrap.is-refetching,
.stat-grid.is-refetching {
  opacity: 0.6;
}

/* Tighter inter-section spacing on desktop — 24px stacks added up to dead air. */
@media (min-width: 1024px) {
  .dashboard-hero,
  .live-pill-wrap,
  .date-picker-wrap,
  .stat-grid,
  .dashboard-mid-row {
    margin-bottom: 16px;
  }
}

/* ── Live "cleaning in progress" pill ───────────────────────────────────── */
/* Status semantics live on the wrapper; the pill itself stays a plain link. */
.live-pill-wrap {
  margin-bottom: 20px;
}

.live-pill {
  display: flex;
  align-items: center;
  width: fit-content;
  max-width: 100%;
  padding: 10px 16px;
  border-radius: var(--radius-2xl);
  background: var(--color-success-light);
  text-decoration: none;
  transition: var(--transition);
}

.live-pill:hover {
  box-shadow: var(--shadow-sm);
}

.live-pill-row {
  display: inline-flex;
  align-items: center;
  gap: 10px;
}

.live-pill-indicator {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 14px;
  height: 14px;
  flex-shrink: 0;
}

.live-pill-dot {
  position: relative;
  z-index: 1;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--color-success);
}

.live-pill-dot::after {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: 50%;
  background: var(--color-success);
  animation: live-cleaning-pulse 1.8s ease-out infinite;
}

@keyframes live-cleaning-pulse {
  0% {
    transform: scale(1);
    opacity: 0.55;
  }
  100% {
    transform: scale(2.6);
    opacity: 0;
  }
}

.live-pill-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-success);
}

.live-pill-arrow {
  color: var(--color-success);
  flex-shrink: 0;
}

@media (prefers-reduced-motion: reduce) {
  .live-pill-dot::after {
    animation: none;
  }
}

/* ── Loading state ──────────────────────────────────────────────────────── */
.dashboard-error-state {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.dashboard-loading-state {
  padding: 40px;
  text-align: center;
}

.dashboard-loading-icon {
  color: var(--color-accent);
}

.dashboard-loading-text {
  margin-top: 12px;
  color: var(--color-gray-600);
}

/* ── Hero ───────────────────────────────────────────────────────────────── */
.dashboard-hero {
  display: flex;
  flex-direction: column;
  gap: 64px;
  padding: 64px 0 20px 0;
}
@media (min-width: 768px) {
  .dashboard-hero {
    padding: 64px 0 0 0;
  }
}

.hero-top {
  display: flex;
  align-items: center;
  gap: 14px;
}

.hero-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: var(--radius-lg);
  background: var(--color-warning-light);
  flex-shrink: 0;
}

/* Two deliberate lines on phones; a single line once the sidebar layout kicks
   in and there is room to spare. */
.dashboard-greeting {
  display: flex;
  flex-direction: column;
  font-size: var(--fs-4xl);
  font-weight: 700;
  color: var(--color-primary);
  line-height: 1.15;
  letter-spacing: -0.01em;
  overflow-wrap: anywhere;
  min-width: 0;
}
@media (min-width: 768px) {
  .dashboard-greeting {
    flex-direction: row;
    flex-wrap: wrap;
    column-gap: 0.35ch;
  }
}

.hero-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.hero-chip-group {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 8px;
}

.hero-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border-radius: var(--radius-pill);
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  box-shadow: var(--shadow-sm);
  font-size: 12px;
  font-weight: 500;
  color: var(--color-gray-700);
}

.hero-chip svg {
  color: var(--color-gray-500);
  flex-shrink: 0;
}

.hero-chip-company {
  cursor: pointer;
  transition: var(--transition);
  max-width: 20ch;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: inline-block;
  font-family: inherit;
}

.hero-chip-company:hover {
  border-color: var(--color-gray-300);
}

.hero-chip-company.active {
  background: var(--color-primary);
  border-color: var(--color-primary);
  color: var(--color-white);
}

/* ── Date range field ───────────────────────────────────────────────────── */
.date-picker-wrap {
  position: relative;
  margin-bottom: 20px;
  transition: opacity 0.2s ease;
}

.date-range-field {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 13px 16px;
  border-radius: var(--radius-xl);
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  box-shadow: var(--shadow-sm);
  font-size: 14px;
  font-weight: 500;
  font-family: inherit;
  color: var(--color-primary);
  cursor: pointer;
  transition: var(--transition);
}

.date-range-field:hover {
  border-color: var(--color-accent);
}

.date-range-icon,
.date-range-chevron {
  color: var(--color-gray-500);
  flex-shrink: 0;
}

.date-range-label {
  flex: 1;
  text-align: left;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Desktop: the field doesn't need the whole row to stay legible */
@media (min-width: 640px) {
  .date-range-field {
    max-width: 420px;
  }
}

/* Full width under the field on mobile; capped to the field width above */
.date-popover {
  position: absolute;
  top: calc(100% + 8px);
  left: 0;
  z-index: 30;
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg);
  padding: 16px;
  width: min(320px, 100%);
}

.date-presets {
  margin-bottom: 0;
}

.date-custom {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid var(--color-gray-200);
}

.date-custom .form-group {
  margin-bottom: 10px;
}

.date-custom-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}

/* ── Stat card grid ─────────────────────────────────────────────────────── */
.stat-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
  margin-bottom: 24px;
  transition: opacity 0.2s ease;
}

.stat-grid > * {
  min-width: 0;
}

@media (min-width: 1024px) {
  .stat-grid {
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
  }
}

.stat-card {
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 14px;
  min-height: 112px;
  text-decoration: none;
  overflow: hidden;
  transition: var(--transition);
}

a.stat-card:hover {
  box-shadow: var(--shadow-md);
}

.stat-head {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.stat-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: var(--radius-md);
  background: var(--color-gray-100);
  color: var(--color-primary);
  flex-shrink: 0;
}

.stat-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-gray-700);
}

.stat-body {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 8px;
}

.stat-value {
  font-size: var(--fs-3xl);
  font-weight: 700;
  color: var(--color-primary);
  line-height: 1;
}

.stat-value-muted {
  color: var(--color-gray-400);
}

/* Decorative flourish only — hence a static path, no chart library */
.stat-sparkline {
  width: 64px;
  height: 24px;
  flex-shrink: 0;
}

.sparkline-line {
  fill: none;
  stroke: var(--color-accent);
  stroke-width: 2;
  stroke-linecap: round;
}

.sparkline-area {
  fill: var(--color-accent);
  opacity: 0.1;
}

.stat-body-status {
  align-items: center;
  justify-content: flex-start;
  flex-wrap: wrap;
}

.stat-status {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 14px;
  font-weight: 600;
}

.stat-status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: currentColor;
  flex-shrink: 0;
}

.stat-status-paid {
  color: var(--color-success);
}

.stat-status-next {
  color: var(--color-primary);
}

.stat-status-overdue {
  color: var(--color-danger);
}

.stat-status-none {
  color: var(--color-gray-500);
  font-weight: 500;
  font-size: 13px;
}

.stat-link {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 14px;
  font-weight: 600;
  color: var(--color-accent);
}

.stat-link:hover {
  color: var(--color-primary);
}

/* Tým card — worker profiles live on the Personál page, the card only
   carries the count and the link to /personal. */
.team-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.team-badge {
  display: inline-flex;
  padding: 5px 10px;
  border-radius: var(--radius-pill);
  background: var(--color-gray-100);
  color: var(--color-primary);
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}

.team-arrow {
  color: var(--color-primary);
  flex-shrink: 0;
}

/* ── Požadavky a reklamace widget ───────────────────────────────────────── */
.card-title-icon {
  vertical-align: -3px;
  margin-right: 6px;
  color: var(--color-mid);
}

.requests-widget-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px 0;
  color: var(--color-mid);
}

.requests-widget-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 14px;
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-md);
  text-decoration: none;
  transition: var(--transition);
}

.requests-widget-row:hover {
  border-color: var(--color-accent);
  box-shadow: var(--shadow-sm);
}

.rwr-main {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}

.rwr-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.rwr-meta {
  font-size: 12px;
  color: var(--color-gray-500);
}

.requests-widget-empty {
  padding: 4px 0;
  font-size: 13px;
  line-height: 1.5;
  color: var(--color-gray-600);
}

.requests-widget-actions {
  display: flex;
  margin-top: 14px;
}

/* ── Mid row ────────────────────────────────────────────────────────────── */
/* Mobile-first: stacked. Two-column at lg.
   align-items defaults to stretch so sibling cards have the same height on
   desktop — keeps the row visually tidy when one card has more content. */
.dashboard-mid-row {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
  margin-bottom: 24px;
}

/* Grid items default to min-width:auto, letting an intrinsically wide child
   blow out the 1fr column. Force shrink to the column width so cards always
   respect the viewport. */
.dashboard-mid-row > * {
  min-width: 0;
}

@media (min-width: 1024px) {
  .dashboard-mid-row {
    grid-template-columns: 1fr 360px;
  }
  /* Attendance hidden: only the requests widget remains, so it takes the full
     row instead of leaving the calendar column empty. */
  .dashboard-mid-row.mid-row-single {
    grid-template-columns: 1fr;
  }
}

.card-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-primary);
}

.card-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.card-link {
  font-size: 13px;
  color: var(--color-accent);
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.card-link:hover {
  color: var(--color-primary);
}

/* Cleaning card body: calendar on top + summary pills below on mobile;
   side-by-side on desktop. Container queries (not viewport) drive the
   transition so the layout reacts to the actual card width, which depends on
   the surrounding grid and sidebar — not to the viewport width alone. */
.cleaning-card {
  container-type: inline-size;
}

.cleaning-body {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* Prev month is hidden until the card is wide enough to fit two calendars.
   Uses two-class selector so it wins over `.mini-calendar { display: flex }`. */
.mini-calendar.prev-calendar {
  display: none;
}

/* At ~480px container width the card can fit two month calendars side-by-side
   (prev + current). Each gets an equal-width slot, capped at 340px so cells
   never balloon on ultrawide screens. */
@container (min-width: 480px) {
  .cleaning-body {
    flex-direction: row;
    align-items: flex-start;
    justify-content: center;
    gap: 28px;
  }
  .mini-calendar.prev-calendar {
    display: flex;
  }
  /* Each calendar gets equal flex grow up to ~400px; bigger ceiling than the
     mobile-centered 340 cap so wide-desktop card areas don't leave a giant
     ring of empty space around two tiny calendars. */
  .cleaning-body .mini-calendar,
  .cleaning-body #dashboard-cleaning-empty {
    flex: 1 1 0;
    max-width: 400px;
    margin-inline: 0;
  }
}

/* Two further bumps as the card grows — both the inter-calendar gap and the
   side breathing room scale up so the layout never feels cramped or marooned
   in dead space. */
@container (min-width: 680px) {
  .cleaning-body {
    gap: 48px;
  }
}

@container (min-width: 900px) {
  .cleaning-body {
    gap: 72px;
  }
}

@container (min-width: 1100px) {
  .cleaning-body {
    gap: 104px;
  }
}

/* Mini month calendar: 7-column grid aligned to weekday headers. Lets clients
   read the rhythm of the month at a glance — gaps between cleanings, day-of-week
   patterns, position of today. max-width keeps cells compact on wide desktops;
   margin-inline auto centers the calendar in cards wider than 340px. */
.mini-calendar {
  display: flex;
  flex-direction: column;
  /* Vertical rhythm inside a calendar: month-row (14) → weekday row (8) → grid.
     The asymmetric pair is intentional — it separates the calendar label from
     the table-like body more clearly than a uniform gap. */
  gap: 8px;
  width: 100%;
  max-width: 340px;
  margin-inline: auto;
}

/* Month row: month label + per-month done count badge. Each calendar carries
   its own count so wide-desktop's two-month layout shows totals separately.
   Flush with the grid edges so the label aligns with the leftmost cell column
   and the badge with the rightmost — the row reads as a header for the grid. */
.mc-month-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 6px;
}

.mc-month-label {
  font-size: 14px;
  font-weight: 700;
  color: var(--color-primary);
  letter-spacing: -0.01em;
  text-transform: capitalize;
}

.mc-month-stat {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  font-weight: 600;
  color: var(--color-success);
  background: var(--color-success-light);
  padding: 3px 10px;
  border-radius: var(--radius-pill);
  white-space: nowrap;
}

.mc-header,
.mc-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 6px;
}

.mc-weekday {
  font-size: 10px;
  font-weight: 600;
  text-align: center;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-gray-500);
  padding: 2px 0;
}

.mc-cell {
  aspect-ratio: 1 / 1;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-sm);
  font-size: 13px;
  font-weight: 500;
  color: var(--color-gray-500);
  transition: transform 0.12s ease;
}

.mc-blank {
  visibility: hidden;
}

.mc-num {
  line-height: 1;
}

.mc-done {
  background: var(--color-success-light);
  color: var(--color-success);
  font-weight: 600;
}

.mc-ongoing {
  background: var(--color-white);
  color: var(--color-primary);
  font-weight: 600;
  box-shadow: inset 0 0 0 1.5px var(--color-primary);
}

.mc-today {
  box-shadow: inset 0 0 0 1.5px var(--color-accent);
  color: var(--color-primary);
}

/* Today + cleaning status: accent ring on top of the status background. */
.mc-today.mc-done {
  box-shadow: inset 0 0 0 1.5px var(--color-accent);
}

.inline-empty-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-left: 4px;
  color: var(--color-accent);
  font-weight: 500;
  white-space: nowrap;
}
.inline-empty-link:hover {
  color: var(--color-primary);
}

/* Onboarding hero on dashboard — sits between greeting and metrics on first visit */
.dashboard-onboarding {
  margin-bottom: 24px;
}

/* Cards in a row are stretched to equal height — make each card a flex column
   so its content can center vertically in the available space instead of
   leaving white space at the bottom. */
.cleaning-card,
.chart-card,
.recent-card,
.requests-widget {
  display: flex;
  flex-direction: column;
}

.cleaning-card .cleaning-body {
  flex: 1;
  justify-content: center;
}

.chart-card #dashboard-chart-empty,
.recent-card #dashboard-recent-empty {
  flex: 1;
  justify-content: center;
}

/* Requests: push the CTA to the card foot when stretched next to the calendar */
.requests-widget .requests-widget-actions {
  margin-top: auto;
  padding-top: 14px;
}

/* ── Bottom row ─────────────────────────────────────────────────────────── */
/* Mobile-first: stacked, chart comes first. Two-column at lg.
   align-items defaults to stretch so sibling cards have the same height. */
.dashboard-bottom-row {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}

/* See note on .dashboard-mid-row > * — same grid-item min-width:auto trap. */
.dashboard-bottom-row > * {
  min-width: 0;
}

@media (min-width: 1024px) {
  .dashboard-bottom-row {
    grid-template-columns: 280px 1fr;
  }
}

.chart-card .card-title {
  margin-bottom: 16px;
}

/* Mobile: square chart capped so it doesn't dominate a phone screen.
   Tablet: lift the cap so the chart scales with its card.
   Desktop (lg): side-by-side layout — fixed height, sized to feel compact next to the legend. */
.chart-wrap {
  position: relative;
  aspect-ratio: 1 / 1;
  max-width: 190px;
  max-height: 190px;
  margin: 0 auto;
}
@media (min-width: 640px) {
  .chart-wrap {
    max-width: none;
    max-height: none;
  }
}
@media (min-width: 1024px) {
  .chart-wrap {
    aspect-ratio: auto;
    height: 160px;
    margin: 0;
  }
}

.chart-totals {
  margin-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.total-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-gray-700);
}

.total-row strong {
  margin-left: auto;
  font-weight: 600;
  color: var(--color-primary);
}

.total-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}

.total-dot-paid {
  background: var(--color-success);
}

.total-dot-unpaid {
  background: var(--color-gray-300);
}

.total-dot-overdue {
  background: var(--color-danger);
}

.recent-card .card-title {
  margin-bottom: 0;
}

/* ── Spin animation ─────────────────────────────────────────────────────── */
.spin {
  animation: spin 1.5s linear infinite;
}

@keyframes spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}
</style>
