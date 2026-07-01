<script setup>
import { ref } from "vue";
import { useRouter } from "vue-router";
import { Star, Clock } from "lucide-vue-next";
import { reviewPromptService } from "../../api";

const props = defineProps({
  // Google Business Profile review link. The card is only rendered by the parent
  // when this is present, but we still guard on it below.
  googleUrl: { type: String, default: null },
});

// Ratings at or above this go to Google; below it we route to a private complaint.
// Mirrors ReviewPromptService::GOOGLE_MIN_RATING on the backend (source of truth for
// what actually gets recorded) — kept here only to open the tab within the click.
const GOOGLE_MIN_RATING = 4;

const router = useRouter();

const visible = ref(true);
const hoverRating = ref(0);
const selectedRating = ref(0);
const submitting = ref(false);
const done = ref(false);
const doneMessage = ref("");

function starFilled(index) {
  const active = hoverRating.value || selectedRating.value;
  return index <= active;
}

async function pickRating(rating) {
  if (submitting.value || done.value) return;
  selectedRating.value = rating;

  const goesToGoogle = rating >= GOOGLE_MIN_RATING;
  // Open Google synchronously inside the click gesture so popup blockers don't eat
  // the tab after the awaited request below.
  if (goesToGoogle && props.googleUrl) {
    window.open(props.googleUrl, "_blank", "noopener");
  }

  submitting.value = true;
  try {
    await reviewPromptService.complete(rating);
  } catch (e) {
    // Recording is best-effort — the client already saw the outcome, so we don't
    // block them on a failed write.
  } finally {
    submitting.value = false;
  }

  done.value = true;
  if (goesToGoogle) {
    doneMessage.value =
      "Děkujeme! Ve vedlejší záložce nám prosím zanechte pár slov.";
  } else {
    doneMessage.value = "Děkujeme za zpětnou vazbu, pomůže nám zlepšit se.";
    router.push({ path: "/zadosti/nova", query: { category: "reklamace" } });
  }
}

async function snoozeLater() {
  if (submitting.value) return;
  submitting.value = true;
  try {
    await reviewPromptService.snooze();
  } catch (e) {
    // Ignore — hide locally regardless so the block isn't sticky this session.
  } finally {
    submitting.value = false;
    visible.value = false;
  }
}
</script>

<template>
  <section
    v-if="visible"
    id="dashboard-review-prompt"
    class="card review-prompt"
    aria-labelledby="dashboard-review-prompt-title"
  >
    <div class="review-inner">
      <template v-if="!done">
        <span class="review-badge" aria-hidden="true">
          <Star :size="20" :stroke-width="1.75" />
        </span>

        <h3 id="dashboard-review-prompt-title" class="review-title">
          Jste s úklidem spokojeni?
        </h3>
        <p id="dashboard-review-prompt-subtitle" class="review-subtitle">
          Ohodnoťte nás hvězdičkami — pomůže to ostatním klientům.
        </p>

        <div
          id="dashboard-review-prompt-stars"
          class="review-stars"
          role="group"
          aria-label="Hodnocení hvězdičkami"
          @mouseleave="hoverRating = 0"
        >
          <button
            v-for="i in 5"
            :id="`dashboard-review-prompt-star-${i}`"
            :key="i"
            type="button"
            class="review-star"
            :class="{ 'review-star-on': starFilled(i) }"
            :disabled="submitting"
            :aria-label="`${i} z 5 hvězdiček`"
            @mouseenter="hoverRating = i"
            @focus="hoverRating = i"
            @blur="hoverRating = 0"
            @click="pickRating(i)"
          >
            <Star
              :size="34"
              :fill="starFilled(i) ? 'currentColor' : 'none'"
              :stroke-width="1.5"
              aria-hidden="true"
            />
          </button>
        </div>

        <button
          id="dashboard-review-prompt-later"
          type="button"
          class="review-later"
          :disabled="submitting"
          @click="snoozeLater"
        >
          Zanechat později
        </button>
        <span id="dashboard-review-prompt-hint" class="review-hint">
          <Clock :size="13" aria-hidden="true" />
          Zobrazí se znovu za 14 dní
        </span>
      </template>

      <template v-else>
        <span class="review-badge review-badge-done" aria-hidden="true">
          <Star :size="20" :stroke-width="1.75" fill="currentColor" />
        </span>
        <p id="dashboard-review-prompt-thanks" class="review-thanks-text">
          {{ doneMessage }}
        </p>
      </template>
    </div>
  </section>
</template>

<style scoped>
/* Matches the 24px vertical rhythm between dashboard sections. As the only
   call-to-action among flat gray readout cards, it lifts to white with a soft
   shadow so it reads as actionable without a heavy tint. */
.review-prompt {
  margin-top: 24px;
  background: var(--color-white);
  box-shadow: var(--shadow-sm);
}

/* Centered, width-constrained block: in a full-width card this keeps the content
   as one tidy group with symmetric padding, instead of a lonely left column (empty
   right) or edge-to-edge content (empty middle). */
.review-inner {
  max-width: 420px;
  margin: 0 auto;
  padding: 8px 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

.review-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: var(--color-light);
  color: var(--color-mid);
  margin-bottom: 14px;
}

.review-badge-done {
  background: var(--color-warning-light);
  color: var(--color-warning);
}

.review-title {
  font-size: var(--fs-lg);
  font-weight: 600;
  color: var(--color-primary);
  margin: 0;
}

.review-subtitle {
  font-size: 13px;
  color: var(--color-gray-500);
  margin: 6px 0 0;
}

.review-stars {
  display: flex;
  gap: 4px;
  margin: 18px 0;
}

.review-star {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 2px;
  border: none;
  background: transparent;
  color: var(--color-gray-300);
  cursor: pointer;
  border-radius: var(--radius-sm);
  transition: color var(--transition), transform var(--transition);
}

.review-star-on {
  color: var(--color-warning);
}

.review-star:hover:not(:disabled) {
  transform: scale(1.12);
}

.review-star:focus-visible {
  outline: 2px solid var(--color-mid);
  outline-offset: 2px;
}

.review-star:disabled {
  cursor: default;
}

.review-later {
  border: none;
  background: transparent;
  padding: 0;
  font-size: 13px;
  font-weight: 500;
  color: var(--color-mid);
  cursor: pointer;
}

.review-later:hover:not(:disabled) {
  color: var(--color-primary);
}

.review-hint {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 8px;
  font-size: 12px;
  color: var(--color-gray-400);
}

.review-thanks-text {
  font-size: var(--fs-md);
  color: var(--color-primary);
  margin: 0;
  max-width: 32ch;
}
</style>
