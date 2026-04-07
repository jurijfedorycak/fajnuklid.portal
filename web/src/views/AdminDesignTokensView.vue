<script setup>
import { ref } from 'vue'
import { Check, Copy } from 'lucide-vue-next'

const copiedVar = ref(null)

const brandColors = [
  { name: '--color-primary', label: 'Primary', desc: 'Hlavní tmavá' },
  { name: '--color-mid', label: 'Mid', desc: 'Střední modrá' },
  { name: '--color-light', label: 'Light', desc: 'Světlá modrá' },
  { name: '--color-light-hover', label: 'Light Hover', desc: 'Světlá hover' },
  { name: '--color-white', label: 'White', desc: 'Bílá' },
]

const grayColors = [
  { name: '--color-gray-50', label: 'Gray 50' },
  { name: '--color-gray-100', label: 'Gray 100' },
  { name: '--color-gray-200', label: 'Gray 200' },
  { name: '--color-gray-300', label: 'Gray 300' },
  { name: '--color-gray-400', label: 'Gray 400' },
  { name: '--color-gray-500', label: 'Gray 500' },
  { name: '--color-gray-600', label: 'Gray 600' },
  { name: '--color-gray-700', label: 'Gray 700' },
  { name: '--color-gray-800', label: 'Gray 800' },
]

const semanticColors = [
  { name: '--color-danger', label: 'Danger', desc: 'Chyba' },
  { name: '--color-danger-light', label: 'Danger Light', desc: 'Chyba pozadí' },
  { name: '--color-success', label: 'Success', desc: 'Úspěch' },
  { name: '--color-success-light', label: 'Success Light', desc: 'Úspěch pozadí' },
  { name: '--color-warning', label: 'Warning', desc: 'Varování' },
  { name: '--color-warning-light', label: 'Warning Light', desc: 'Varování pozadí' },
]

const radiusTokens = [
  { name: '--radius-sm', label: 'Small', value: '6px' },
  { name: '--radius-md', label: 'Medium', value: '8px' },
  { name: '--radius-lg', label: 'Large', value: '12px' },
  { name: '--radius-xl', label: 'Extra Large', value: '16px' },
  { name: '--radius-pill', label: 'Pill', value: '20px' },
]

const shadowTokens = [
  { name: '--shadow-sm', label: 'Small' },
  { name: '--shadow-md', label: 'Medium' },
  { name: '--shadow-lg', label: 'Large' },
]

const fontWeights = [
  { weight: 300, label: 'Light' },
  { weight: 400, label: 'Regular' },
  { weight: 500, label: 'Medium' },
  { weight: 600, label: 'Semi Bold' },
  { weight: 700, label: 'Bold' },
]

const fontSizes = [
  { size: '11px', label: 'Tiny' },
  { size: '12px', label: 'Small' },
  { size: '13px', label: 'Caption' },
  { size: '14px', label: 'Body' },
  { size: '15px', label: 'Large Body' },
  { size: '16px', label: 'Subtitle' },
  { size: '18px', label: 'Title' },
  { size: '22px', label: 'Heading' },
]

async function copyToClipboard(varName) {
  try {
    await navigator.clipboard.writeText(`var(${varName})`)
    copiedVar.value = varName
    setTimeout(() => {
      copiedVar.value = null
    }, 1500)
  } catch (err) {
    console.error('Kopírování selhalo:', err)
  }
}

</script>

<template>
  <div id="dt-page" class="design-tokens-page">
    <header id="dt-header" class="page-header">
      <div id="dt-header-content">
        <h1 id="dt-page-title" class="page-title">Design Tokeny</h1>
        <p id="dt-page-subtitle" class="page-subtitle">Přehled všech CSS proměnných a komponent</p>
      </div>
    </header>

    <!-- COLORS SECTION -->
    <section id="dt-colors-section" class="dt-section">
      <h2 id="dt-colors-title" class="dt-section-title">Barvy</h2>

      <!-- Brand Colors -->
      <div id="dt-brand-colors" class="dt-subsection">
        <h3 id="dt-brand-colors-title" class="dt-subsection-title">Značkové barvy</h3>
        <div id="dt-brand-colors-grid" class="dt-color-grid">
          <div
            v-for="color in brandColors"
            :key="color.name"
            :id="`dt-color-${color.name.replace('--', '')}`"
            class="dt-color-card"
            @click="copyToClipboard(color.name)"
          >
            <div
              class="dt-color-swatch"
              :style="{ background: `var(${color.name})` }"
            >
              <span class="dt-copy-icon">
                <Check v-if="copiedVar === color.name" :size="16" />
                <Copy v-else :size="16" />
              </span>
            </div>
            <div class="dt-color-info">
              <span class="dt-color-label">{{ color.label }}</span>
              <code class="dt-color-var">{{ color.name }}</code>
              <span v-if="color.desc" class="dt-color-desc">{{ color.desc }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Gray Scale -->
      <div id="dt-gray-colors" class="dt-subsection">
        <h3 id="dt-gray-colors-title" class="dt-subsection-title">Škála šedé</h3>
        <div id="dt-gray-colors-grid" class="dt-color-grid dt-color-grid-compact">
          <div
            v-for="color in grayColors"
            :key="color.name"
            :id="`dt-color-${color.name.replace('--', '')}`"
            class="dt-color-card dt-color-card-compact"
            @click="copyToClipboard(color.name)"
          >
            <div
              class="dt-color-swatch dt-color-swatch-compact"
              :style="{ background: `var(${color.name})` }"
            >
              <span class="dt-copy-icon">
                <Check v-if="copiedVar === color.name" :size="14" />
                <Copy v-else :size="14" />
              </span>
            </div>
            <div class="dt-color-info">
              <span class="dt-color-label">{{ color.label }}</span>
              <code class="dt-color-var">{{ color.name }}</code>
            </div>
          </div>
        </div>
      </div>

      <!-- Semantic Colors -->
      <div id="dt-semantic-colors" class="dt-subsection">
        <h3 id="dt-semantic-colors-title" class="dt-subsection-title">Sémantické barvy</h3>
        <div id="dt-semantic-colors-grid" class="dt-color-grid">
          <div
            v-for="color in semanticColors"
            :key="color.name"
            :id="`dt-color-${color.name.replace('--', '')}`"
            class="dt-color-card"
            @click="copyToClipboard(color.name)"
          >
            <div
              class="dt-color-swatch"
              :style="{ background: `var(${color.name})` }"
            >
              <span class="dt-copy-icon">
                <Check v-if="copiedVar === color.name" :size="16" />
                <Copy v-else :size="16" />
              </span>
            </div>
            <div class="dt-color-info">
              <span class="dt-color-label">{{ color.label }}</span>
              <code class="dt-color-var">{{ color.name }}</code>
              <span v-if="color.desc" class="dt-color-desc">{{ color.desc }}</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- TYPOGRAPHY SECTION -->
    <section id="dt-typography-section" class="dt-section">
      <h2 id="dt-typography-title" class="dt-section-title">Typografie</h2>

      <div id="dt-font-family" class="dt-subsection">
        <h3 id="dt-font-family-title" class="dt-subsection-title">Písmo</h3>
        <div id="dt-font-demo" class="dt-font-demo">
          <span id="dt-font-name" class="dt-font-name">Rubik</span>
          <span id="dt-font-sample" class="dt-font-sample">ABCDEFGHIJKLMNOPQRSTUVWXYZ abcdefghijklmnopqrstuvwxyz 0123456789</span>
        </div>
      </div>

      <div id="dt-font-weights" class="dt-subsection">
        <h3 id="dt-font-weights-title" class="dt-subsection-title">Váhy písma</h3>
        <div id="dt-font-weights-grid" class="dt-weights-grid">
          <div
            v-for="fw in fontWeights"
            :key="fw.weight"
            :id="`dt-font-weight-${fw.weight}`"
            class="dt-weight-item"
          >
            <span class="dt-weight-sample" :style="{ fontWeight: fw.weight }">Fajnuklid</span>
            <span class="dt-weight-label">{{ fw.label }} ({{ fw.weight }})</span>
          </div>
        </div>
      </div>

      <div id="dt-font-sizes" class="dt-subsection">
        <h3 id="dt-font-sizes-title" class="dt-subsection-title">Velikosti písma</h3>
        <div id="dt-font-sizes-list" class="dt-sizes-list">
          <div
            v-for="fs in fontSizes"
            :key="fs.size"
            :id="`dt-font-size-${fs.size.replace('px', '')}`"
            class="dt-size-item"
          >
            <span class="dt-size-sample" :style="{ fontSize: fs.size }">Ukázkový text</span>
            <span class="dt-size-label">{{ fs.label }} – {{ fs.size }}</span>
          </div>
        </div>
      </div>
    </section>

    <!-- BORDER RADIUS SECTION -->
    <section id="dt-radius-section" class="dt-section">
      <h2 id="dt-radius-title" class="dt-section-title">Zaoblení rohů</h2>
      <div id="dt-radius-grid" class="dt-radius-grid">
        <div
          v-for="r in radiusTokens"
          :key="r.name"
          :id="`dt-radius-${r.name.replace('--radius-', '')}`"
          class="dt-radius-card"
          @click="copyToClipboard(r.name)"
        >
          <div class="dt-radius-box" :style="{ borderRadius: `var(${r.name})` }">
            <span class="dt-copy-icon">
              <Check v-if="copiedVar === r.name" :size="14" />
              <Copy v-else :size="14" />
            </span>
          </div>
          <div class="dt-radius-info">
            <span class="dt-radius-label">{{ r.label }}</span>
            <code class="dt-radius-var">{{ r.name }}</code>
            <span class="dt-radius-value">{{ r.value }}</span>
          </div>
        </div>
      </div>
    </section>

    <!-- SHADOWS SECTION -->
    <section id="dt-shadows-section" class="dt-section">
      <h2 id="dt-shadows-title" class="dt-section-title">Stíny</h2>
      <div id="dt-shadows-grid" class="dt-shadows-grid">
        <div
          v-for="s in shadowTokens"
          :key="s.name"
          :id="`dt-shadow-${s.name.replace('--shadow-', '')}`"
          class="dt-shadow-card"
          @click="copyToClipboard(s.name)"
        >
          <div class="dt-shadow-box" :style="{ boxShadow: `var(${s.name})` }">
            <span class="dt-copy-icon">
              <Check v-if="copiedVar === s.name" :size="14" />
              <Copy v-else :size="14" />
            </span>
          </div>
          <div class="dt-shadow-info">
            <span class="dt-shadow-label">{{ s.label }}</span>
            <code class="dt-shadow-var">{{ s.name }}</code>
          </div>
        </div>
      </div>
    </section>

    <!-- UI COMPONENTS SECTION -->
    <section id="dt-components-section" class="dt-section">
      <h2 id="dt-components-title" class="dt-section-title">UI Komponenty</h2>

      <!-- Buttons -->
      <div id="dt-buttons" class="dt-subsection">
        <h3 id="dt-buttons-title" class="dt-subsection-title">Tlačítka</h3>
        <div id="dt-buttons-row-variants" class="dt-buttons-row">
          <button id="dt-btn-primary" class="btn btn-primary">Primary</button>
          <button id="dt-btn-outline" class="btn btn-outline">Outline</button>
          <button id="dt-btn-ghost" class="btn btn-ghost">Ghost</button>
          <button id="dt-btn-danger" class="btn btn-danger">Danger</button>
        </div>
        <div id="dt-buttons-row-sizes" class="dt-buttons-row">
          <button id="dt-btn-sm" class="btn btn-primary btn-sm">Malé</button>
          <button id="dt-btn-md" class="btn btn-primary">Střední</button>
          <button id="dt-btn-lg" class="btn btn-primary btn-lg">Velké</button>
        </div>
      </div>

      <!-- Forms -->
      <div id="dt-forms" class="dt-subsection">
        <h3 id="dt-forms-title" class="dt-subsection-title">Formuláře</h3>
        <div id="dt-forms-demo" class="dt-form-demo">
          <div id="dt-form-group-text" class="form-group">
            <label class="form-label">Textové pole</label>
            <input id="dt-input-text" type="text" class="form-input" placeholder="Zadejte text..." />
          </div>
          <div id="dt-form-group-email" class="form-group">
            <label class="form-label">E-mail</label>
            <input id="dt-input-email" type="email" class="form-input" placeholder="vas@email.cz" />
          </div>
          <div id="dt-form-group-disabled" class="form-group">
            <label class="form-label">Zakázané pole</label>
            <input id="dt-input-disabled" type="text" class="form-input" value="Nelze editovat" disabled />
          </div>
        </div>
      </div>

      <!-- Badges -->
      <div id="dt-badges" class="dt-subsection">
        <h3 id="dt-badges-title" class="dt-subsection-title">Odznaky</h3>
        <div id="dt-badges-row" class="dt-badges-row">
          <span id="dt-badge-success" class="badge badge-success">Úspěch</span>
          <span id="dt-badge-danger" class="badge badge-danger">Chyba</span>
          <span id="dt-badge-warning" class="badge badge-warning">Varování</span>
          <span id="dt-badge-info" class="badge badge-info">Info</span>
          <span id="dt-badge-gray" class="badge badge-gray">Neutrální</span>
        </div>
      </div>

      <!-- Alerts -->
      <div id="dt-alerts" class="dt-subsection">
        <h3 id="dt-alerts-title" class="dt-subsection-title">Upozornění</h3>
        <div id="dt-alerts-stack" class="dt-alerts-stack">
          <div id="dt-alert-info" class="alert alert-info">
            Toto je informační zpráva pro uživatele.
          </div>
          <div id="dt-alert-success" class="alert alert-success">
            Operace byla úspěšně dokončena.
          </div>
          <div id="dt-alert-warning" class="alert alert-warning">
            Pozor, toto vyžaduje vaši pozornost.
          </div>
          <div id="dt-alert-danger" class="alert alert-danger">
            Došlo k chybě při zpracování.
          </div>
        </div>
      </div>

      <!-- Chips -->
      <div id="dt-chips" class="dt-subsection">
        <h3 id="dt-chips-title" class="dt-subsection-title">Čipy / Filtry</h3>
        <div class="chip-group">
          <button id="dt-chip-all" class="chip active">Všechny</button>
          <button id="dt-chip-active" class="chip">Aktivní</button>
          <button id="dt-chip-inactive" class="chip">Neaktivní</button>
          <button id="dt-chip-archived" class="chip">Archivované</button>
        </div>
      </div>

      <!-- Cards -->
      <div id="dt-cards" class="dt-subsection">
        <h3 id="dt-cards-title" class="dt-subsection-title">Karty</h3>
        <div id="dt-cards-grid" class="dt-cards-grid">
          <div id="dt-card-example" class="card">
            <h4 class="dt-card-title">Ukázková karta</h4>
            <p class="dt-card-text">Toto je obsah karty s běžným textem a informacemi.</p>
          </div>
          <div id="dt-card-example-2" class="card">
            <h4 class="dt-card-title">Další karta</h4>
            <p class="dt-card-text">Karty se používají pro seskupení souvisejícího obsahu.</p>
          </div>
        </div>
      </div>

      <!-- Avatars -->
      <div id="dt-avatars" class="dt-subsection">
        <h3 id="dt-avatars-title" class="dt-subsection-title">Avatary</h3>
        <div id="dt-avatars-row" class="dt-avatars-row">
          <div id="dt-avatar-sm" class="avatar avatar-sm">SM</div>
          <div id="dt-avatar-md" class="avatar avatar-md">MD</div>
          <div id="dt-avatar-lg" class="avatar avatar-lg">LG</div>
          <div id="dt-avatar-xl" class="avatar avatar-xl">XL</div>
        </div>
      </div>

      <!-- Table -->
      <div id="dt-table" class="dt-subsection">
        <h3 id="dt-table-title" class="dt-subsection-title">Tabulka</h3>
        <div class="table-wrap">
          <table id="dt-table-example" class="data-table">
            <thead>
              <tr>
                <th>Jméno</th>
                <th>E-mail</th>
                <th>Stav</th>
                <th class="text-right">Akce</th>
              </tr>
            </thead>
            <tbody>
              <tr id="dt-table-row-1">
                <td>Jan Novák</td>
                <td>jan@example.cz</td>
                <td><span class="badge badge-success">Aktivní</span></td>
                <td class="text-right">
                  <button class="btn btn-ghost btn-sm">Upravit</button>
                </td>
              </tr>
              <tr id="dt-table-row-2">
                <td>Marie Dvořáková</td>
                <td>marie@example.cz</td>
                <td><span class="badge badge-warning">Čeká</span></td>
                <td class="text-right">
                  <button class="btn btn-ghost btn-sm">Upravit</button>
                </td>
              </tr>
              <tr id="dt-table-row-3">
                <td>Petr Svoboda</td>
                <td>petr@example.cz</td>
                <td><span class="badge badge-danger">Neaktivní</span></td>
                <td class="text-right">
                  <button class="btn btn-ghost btn-sm">Upravit</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Skeleton -->
      <div id="dt-skeleton" class="dt-subsection">
        <h3 id="dt-skeleton-title" class="dt-subsection-title">Skeleton načítání</h3>
        <div id="dt-skeleton-demo" class="dt-skeleton-demo">
          <div id="dt-skeleton-line-1" class="skeleton" style="height: 20px; width: 60%; margin-bottom: 12px;"></div>
          <div id="dt-skeleton-line-2" class="skeleton" style="height: 14px; width: 80%; margin-bottom: 8px;"></div>
          <div id="dt-skeleton-line-3" class="skeleton" style="height: 14px; width: 70%;"></div>
        </div>
      </div>

      <!-- Empty State -->
      <div id="dt-empty-state" class="dt-subsection">
        <h3 id="dt-empty-state-title" class="dt-subsection-title">Prázdný stav</h3>
        <div class="card">
          <div id="dt-empty-state-demo" class="empty-state">
            <div class="empty-state-icon">
              <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
            </div>
            <div class="empty-state-title">Žádné výsledky</div>
            <div class="empty-state-text">Zkuste upravit vyhledávací kritéria nebo filtry.</div>
          </div>
        </div>
      </div>
    </section>

    <!-- Layout tokens -->
    <section id="dt-layout-section" class="dt-section">
      <h2 id="dt-layout-title" class="dt-section-title">Layout</h2>
      <div id="dt-layout-tokens" class="dt-layout-tokens">
        <div id="dt-layout-sidebar" class="dt-layout-item" @click="copyToClipboard('--sidebar-width')">
          <code class="dt-layout-var">--sidebar-width</code>
          <span class="dt-layout-value">240px</span>
          <span class="dt-copy-icon-inline">
            <Check v-if="copiedVar === '--sidebar-width'" :size="14" />
            <Copy v-else :size="14" />
          </span>
        </div>
        <div id="dt-layout-transition" class="dt-layout-item" @click="copyToClipboard('--transition')">
          <code class="dt-layout-var">--transition</code>
          <span class="dt-layout-value">0.2s ease</span>
          <span class="dt-copy-icon-inline">
            <Check v-if="copiedVar === '--transition'" :size="14" />
            <Copy v-else :size="14" />
          </span>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.design-tokens-page {
  padding: 0 0 40px;
}

.dt-section {
  margin-bottom: 40px;
}

.dt-section-title {
  font-size: 18px;
  font-weight: 600;
  color: var(--color-primary);
  margin-bottom: 20px;
  padding-bottom: 10px;
  border-bottom: 2px solid var(--color-gray-200);
}

.dt-subsection {
  margin-bottom: 28px;
}

.dt-subsection-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-gray-700);
  margin-bottom: 12px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

/* Color Grid */
.dt-color-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 16px;
}

.dt-color-grid-compact {
  grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
  gap: 12px;
}

.dt-color-card {
  background: var(--color-white);
  border-radius: var(--radius-md);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
  cursor: pointer;
  transition: var(--transition);
}

.dt-color-card:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-2px);
}

.dt-color-card-compact {
  border-radius: var(--radius-sm);
}

.dt-color-swatch {
  height: 80px;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
}

.dt-color-swatch-compact {
  height: 60px;
}

.dt-copy-icon {
  opacity: 0;
  color: white;
  background: rgba(0, 0, 0, 0.3);
  padding: 6px;
  border-radius: var(--radius-sm);
  transition: var(--transition);
}

.dt-color-card:hover .dt-copy-icon {
  opacity: 1;
}

.dt-color-info {
  padding: 10px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.dt-color-label {
  font-size: 13px;
  font-weight: 500;
  color: var(--color-gray-800);
}

.dt-color-var {
  font-size: 11px;
  color: var(--color-gray-500);
  font-family: monospace;
}

.dt-color-desc {
  font-size: 11px;
  color: var(--color-gray-400);
}

/* Typography */
.dt-font-demo {
  background: var(--color-white);
  padding: 20px;
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
}

.dt-font-name {
  display: block;
  font-size: 24px;
  font-weight: 600;
  color: var(--color-primary);
  margin-bottom: 12px;
}

.dt-font-sample {
  display: block;
  font-size: 14px;
  color: var(--color-gray-600);
  word-break: break-all;
}

.dt-weights-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 12px;
}

.dt-weight-item {
  background: var(--color-white);
  padding: 16px;
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.dt-weight-sample {
  font-size: 20px;
  color: var(--color-primary);
}

.dt-weight-label {
  font-size: 12px;
  color: var(--color-gray-500);
}

.dt-sizes-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  background: var(--color-white);
  padding: 16px;
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
}

.dt-size-item {
  display: flex;
  align-items: baseline;
  gap: 16px;
  padding: 8px 0;
  border-bottom: 1px solid var(--color-gray-100);
}

.dt-size-item:last-child {
  border-bottom: none;
}

.dt-size-sample {
  color: var(--color-gray-800);
  min-width: 180px;
}

.dt-size-label {
  font-size: 12px;
  color: var(--color-gray-500);
}

/* Radius */
.dt-radius-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 16px;
}

.dt-radius-card {
  background: var(--color-white);
  padding: 16px;
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
  cursor: pointer;
  transition: var(--transition);
}

.dt-radius-card:hover {
  box-shadow: var(--shadow-md);
}

.dt-radius-box {
  width: 64px;
  height: 64px;
  background: var(--color-light);
  border: 2px solid var(--color-mid);
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.dt-radius-box .dt-copy-icon {
  background: var(--color-primary);
  color: white;
}

.dt-radius-card:hover .dt-copy-icon {
  opacity: 1;
}

.dt-radius-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.dt-radius-label {
  font-size: 13px;
  font-weight: 500;
  color: var(--color-gray-800);
}

.dt-radius-var {
  font-size: 11px;
  color: var(--color-gray-500);
  font-family: monospace;
}

.dt-radius-value {
  font-size: 11px;
  color: var(--color-gray-400);
}

/* Shadows */
.dt-shadows-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 16px;
}

.dt-shadow-card {
  background: var(--color-white);
  padding: 16px;
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: var(--transition);
}

.dt-shadow-card:hover {
  transform: translateY(-2px);
}

.dt-shadow-box {
  width: 100%;
  height: 80px;
  background: var(--color-white);
  border-radius: var(--radius-md);
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.dt-shadow-box .dt-copy-icon {
  background: var(--color-gray-200);
  color: var(--color-gray-600);
}

.dt-shadow-card:hover .dt-copy-icon {
  opacity: 1;
}

.dt-shadow-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.dt-shadow-label {
  font-size: 13px;
  font-weight: 500;
  color: var(--color-gray-800);
}

.dt-shadow-var {
  font-size: 11px;
  color: var(--color-gray-500);
  font-family: monospace;
}

/* Buttons */
.dt-buttons-row {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 16px;
}

/* Forms */
.dt-form-demo {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 16px;
  background: var(--color-white);
  padding: 20px;
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
}

.dt-form-demo .form-group {
  margin-bottom: 0;
}

/* Badges */
.dt-badges-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

/* Alerts */
.dt-alerts-stack {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

/* Cards */
.dt-cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 16px;
}

.dt-card-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-primary);
  margin-bottom: 8px;
}

.dt-card-text {
  font-size: 13px;
  color: var(--color-gray-600);
}

/* Avatars */
.dt-avatars-row {
  display: flex;
  align-items: center;
  gap: 16px;
}

/* Skeleton */
.dt-skeleton-demo {
  background: var(--color-white);
  padding: 20px;
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
}

/* Layout tokens */
.dt-layout-tokens {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.dt-layout-item {
  display: flex;
  align-items: center;
  gap: 12px;
  background: var(--color-white);
  padding: 12px 16px;
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
  cursor: pointer;
  transition: var(--transition);
}

.dt-layout-item:hover {
  box-shadow: var(--shadow-md);
}

.dt-layout-var {
  font-size: 13px;
  font-family: monospace;
  color: var(--color-gray-700);
}

.dt-layout-value {
  font-size: 12px;
  color: var(--color-gray-500);
}

.dt-copy-icon-inline {
  color: var(--color-gray-400);
  transition: var(--transition);
}

.dt-layout-item:hover .dt-copy-icon-inline {
  color: var(--color-primary);
}

/* Responsive */
@media (max-width: 768px) {
  .dt-color-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  .dt-radius-grid,
  .dt-shadows-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  .dt-weights-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
