<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { adminClients } from '../data/mockData.js'
import {
  ArrowLeft, Save, Plus, Trash2, Building2, MapPin, User, Users,
  Lock, Phone, Mail, FileSignature, Clock, ChevronDown, ChevronUp,
  Eye, EyeOff, Upload, CheckCircle2, AlertTriangle, ToggleLeft, ToggleRight,
  Globe, Shield, Copy,
} from 'lucide-vue-next'

const route  = useRoute()
const router = useRouter()

const isNew  = computed(() => route.params.id === 'novy')
const saving = ref(false)
const saved  = ref(false)

// ── Section nav ───────────────────────────────────────────────────────────────
const sections = [
  { id: 'sec-basic',    label: 'Základní informace', icon: User },
  { id: 'sec-logins',   label: 'Přihlašovací účty',  icon: Lock },
  { id: 'sec-icos',     label: 'IČO & Provozovny',   icon: Building2 },
  { id: 'sec-staff',    label: 'Personál',            icon: Users },
  { id: 'sec-contacts', label: 'Kontaktní osoby',     icon: Phone },
]
const activeSection = ref('sec-basic')

function scrollToSection(id) {
  activeSection.value = id
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

// Update active section on scroll
let observer = null
onMounted(() => {
  observer = new IntersectionObserver(
    entries => entries.forEach(e => { if (e.isIntersecting) activeSection.value = e.target.id }),
    { threshold: 0.3 }
  )
  sections.forEach(s => {
    const el = document.getElementById(s.id)
    if (el) observer.observe(el)
  })
})
onUnmounted(() => observer?.disconnect())

// ── ID generator ──────────────────────────────────────────────────────────────
let _idCounter = 100
function uid() { return `id-${++_idCounter}` }

// Other clients for IČO reassignment dropdown
const otherClients = computed(() =>
  adminClients.filter(c => c.clientId !== (isNew.value ? '' : 'CLI-001'))
)

// ── Form state ────────────────────────────────────────────────────────────────
const form = reactive({
  clientId:    isNew.value ? '' : 'CLI-001',
  displayName: isNew.value ? '' : 'Stavby Novák s.r.o.',
  notes:       isNew.value ? '' : 'Dlouhodobý klient od roku 2024. Preferuje kontakt e-mailem.',
  active:      true,

  logins: isNew.value ? [] : [
    { id: uid(), email: 'info@stavby-novak.cz',    restriction: 'all',      allowedIcos: [],           showPass: false, tempPass: '' },
    { id: uid(), email: 'ekonom@stavby-novak.cz',  restriction: 'icos',     allowedIcos: ['87654321'], showPass: false, tempPass: '' },
  ],

  icos: isNew.value ? [] : [
    {
      id: 'ico-1', ico: '12345678', officialName: 'Stavby Novák s.r.o.',
      freshqrEnabled: true, billingModel: 'hourly',
      contractUploaded: true, contractFile: 'Smlouva_StavbyNovak_2024.pdf',
      objects: [
        { id: 'obj-1', name: 'Kanceláře',  address: 'Budějovická 12, Praha 4', lat: 50.0523, lng: 14.4629, expanded: true },
        { id: 'obj-2', name: 'Sklad',      address: 'Průmyslová 5, Praha 4',   lat: 50.0400, lng: 14.4700, expanded: false },
      ],
      expanded: true,
    },
    {
      id: 'ico-2', ico: '87654321', officialName: 'Novák Holding a.s.',
      freshqrEnabled: false, billingModel: 'fixed',
      contractUploaded: false, contractFile: null,
      objects: [
        { id: 'obj-3', name: 'Recepce a vedení', address: 'Nádražní 5, Praha 5', lat: 50.0714, lng: 14.4027, expanded: false },
      ],
      expanded: false,
    },
  ],

  staff: isNew.value ? [] : [
    { id: uid(), name: 'Katarína Horáková', role: 'Vedoucí týmu',        assignedObjects: ['obj-1'],       tenure: '3 roky',           bio: 'Kateřina se stará o koordinaci.', hobbies: 'Zahradničení, jóga', phone: '',               showRole: true, showTenure: true, showBio: true, showHobbies: true, showPhone: false, expanded: false },
    { id: uid(), name: 'Dmytro Kovalenko',  role: 'Úklidový pracovník',  assignedObjects: ['obj-1'],       tenure: '1 rok a 4 měsíce', bio: 'Specializuje se na strojové mytí.',hobbies: 'Fotbal, cyklistika', phone: '+420 702 111 222', showRole: true, showTenure: true, showBio: true, showHobbies: true, showPhone: true,  expanded: false },
    { id: uid(), name: 'Monika Blahová',    role: 'Úklidová pracovnice', assignedObjects: ['obj-2'],       tenure: '2 roky',           bio: '',                                hobbies: '',                   phone: '',               showRole: true, showTenure: true, showBio: false,showHobbies: false,showPhone: false, expanded: false },
    { id: uid(), name: 'Andrij Melnyk',     role: 'Pomocný pracovník',   assignedObjects: ['obj-2'],       tenure: '8 měsíců',         bio: 'Nový člen týmu, rychle se učí.',  hobbies: 'Hudba',              phone: '',               showRole: true, showTenure: true, showBio: true, showHobbies: true, showPhone: false, expanded: false },
    { id: uid(), name: 'Oksana Petrenko',   role: 'Vedoucí týmu',        assignedObjects: ['obj-3'],       tenure: '2 roky',           bio: 'Zajišťuje úklid recepce.',        hobbies: 'Tenis, cestování',   phone: '',               showRole: true, showTenure: true, showBio: true, showHobbies: true, showPhone: false, expanded: false },
    { id: uid(), name: 'Taras Bondarenko',  role: 'Úklidový pracovník',  assignedObjects: ['obj-3'],       tenure: '5 měsíců',         bio: '',                                hobbies: '',                   phone: '',               showRole: true, showTenure: true, showBio: false,showHobbies: false,showPhone: false, expanded: false },
  ],

  contacts: isNew.value ? [] : [
    { id: uid(), name: 'Petr Novák',   role: 'Jednatel',         phone: '+420 602 111 333', email: 'petr.novak@stavby-novak.cz',   scope: 'global', icoId: null   },
    { id: uid(), name: 'Jana Marková', role: 'Ekonomka',         phone: '+420 731 222 444', email: 'jana.markova@stavby-novak.cz', scope: 'icos',   icoId: 'ico-1' },
    { id: uid(), name: 'Tomáš Říha',   role: 'Facility Manager', phone: '',                 email: 'tomas.riha@novak-holding.cz',  scope: 'icos',   icoId: 'ico-2' },
  ],
})

// ── All objects flattened (for staff assignment) ───────────────────────────────
const allObjects = computed(() =>
  form.icos.flatMap(ico =>
    ico.objects.map(obj => ({ ...obj, icoLabel: `${ico.officialName} · ${obj.name}` }))
  )
)

// ── Add / Remove helpers ──────────────────────────────────────────────────────
function addLogin() {
  form.logins.push({ id: uid(), email: '', restriction: 'all', allowedIcos: [], showPass: false, tempPass: '' })
}
function removeLogin(id) { form.logins = form.logins.filter(l => l.id !== id) }

function addIco() {
  form.icos.push({ id: uid(), ico: '', officialName: '', freshqrEnabled: false, billingModel: 'hourly', contractUploaded: false, contractFile: null, objects: [], expanded: true })
}
function removeIco(id) { form.icos = form.icos.filter(i => i.id !== id) }

function addObject(ico) {
  ico.objects.push({ id: uid(), name: '', address: '', lat: null, lng: null, expanded: true })
}
function removeObject(ico, objId) { ico.objects = ico.objects.filter(o => o.id !== objId) }

function addStaff() {
  form.staff.push({ id: uid(), name: '', role: '', assignedObjects: [], tenure: '', bio: '', hobbies: '', phone: '', showRole: true, showTenure: true, showBio: true, showHobbies: true, showPhone: false, expanded: true })
}
function removeStaff(id) { form.staff = form.staff.filter(s => s.id !== id) }

function addContact() {
  form.contacts.push({ id: uid(), name: '', role: '', phone: '', email: '', scope: 'global', icoId: null })
}
function removeContact(id) { form.contacts = form.contacts.filter(c => c.id !== id) }

// ── Contract upload mockup ────────────────────────────────────────────────────
function handleContractUpload(ico, event) {
  const file = event.target.files?.[0]
  if (file) { ico.contractFile = file.name; ico.contractUploaded = true }
}

// ── Map helpers ───────────────────────────────────────────────────────────────
// Pre-set coords for known addresses in mockup
const coordLookup = {
  'Budějovická 12, Praha 4':   { lat: 50.0523, lng: 14.4629 },
  'Průmyslová 5, Praha 4':     { lat: 50.0400, lng: 14.4700 },
  'Nádražní 5, Praha 5':       { lat: 50.0714, lng: 14.4027 },
}

function geocodeAddress(obj) {
  const found = Object.entries(coordLookup).find(([addr]) =>
    obj.address.toLowerCase().includes(addr.toLowerCase().split(',')[0])
  )
  if (found) {
    obj.lat = found[1].lat
    obj.lng = found[1].lng
  } else {
    obj.lat = 50.0755
    obj.lng = 14.4378
  }
}

function mapSrc(obj) {
  if (!obj.lat || !obj.lng) return ''
  const lat = obj.lat, lng = obj.lng, d = 0.008
  return `https://www.openstreetmap.org/export/embed.html?bbox=${lng-d},${lat-d/2},${lng+d},${lat+d/2}&layer=mapnik&marker=${lat},${lng}`
}

// ── Save ─────────────────────────────────────────────────────────────────────
function save() {
  saving.value = true
  setTimeout(() => {
    saving.value = false
    saved.value = true
    setTimeout(() => { saved.value = false }, 3000)
  }, 900)
}

function generateClientId() {
  form.clientId = 'CLI-' + Math.floor(Math.random() * 900 + 100)
}

function copyId() {
  navigator.clipboard?.writeText(form.clientId)
}

// ── Toggle helpers ────────────────────────────────────────────────────────────
function toggleIcoRestriction(login, ico) {
  const idx = login.allowedIcos.indexOf(ico)
  if (idx === -1) login.allowedIcos.push(ico)
  else login.allowedIcos.splice(idx, 1)
}
</script>

<template>
  <div class="edit-page">

    <!-- Top bar -->
    <div class="topbar">
      <button class="btn btn-ghost btn-sm back-btn" @click="router.push('/admin')">
        <ArrowLeft :size="16" /> Správa klientů
      </button>
      <div class="topbar-title">
        <span class="topbar-label">{{ isNew ? 'Nový klient' : form.displayName }}</span>
        <span v-if="!isNew" class="topbar-id">{{ form.clientId }}</span>
      </div>
      <div class="topbar-actions">
        <span v-if="saved" class="saved-msg">
          <CheckCircle2 :size="15" /> Uloženo
        </span>
        <button
          class="btn btn-sm"
          :class="form.active ? 'btn-outline' : 'btn-ghost'"
          @click="form.active = !form.active"
        >
          <component :is="form.active ? ToggleRight : ToggleLeft" :size="16" />
          {{ form.active ? 'Aktivní' : 'Neaktivní' }}
        </button>
        <button class="btn btn-primary btn-sm" :disabled="saving" @click="save">
          <Save :size="15" />
          {{ saving ? 'Ukládám...' : 'Uložit změny' }}
        </button>
      </div>
    </div>

    <!-- Split layout -->
    <div class="edit-layout">

      <!-- Left nav -->
      <nav class="section-nav">
        <button
          v-for="sec in sections"
          :key="sec.id"
          class="snav-item"
          :class="{ active: activeSection === sec.id }"
          @click="scrollToSection(sec.id)"
        >
          <component :is="sec.icon" :size="15" />
          <span>{{ sec.label }}</span>
        </button>
      </nav>

      <!-- Form content -->
      <div class="form-content">

        <!-- ═══ SECTION 1: Základní informace ═══════════════════════════════ -->
        <section id="sec-basic" class="form-section">
          <h2 class="sec-title"><User :size="18" /> Základní informace</h2>

          <div class="field-grid-2">
            <div class="form-group">
              <label class="form-label">Název pro portál *</label>
              <input v-model="form.displayName" type="text" class="form-input" placeholder="Firma s.r.o." />
              <p class="field-hint">Zobrazuje se klientovi v záhlaví portálu.</p>
            </div>
            <div class="form-group">
              <label class="form-label">ID klienta</label>
              <div class="input-with-btn">
                <input v-model="form.clientId" type="text" class="form-input" placeholder="CLI-XXX" :disabled="!isNew" />
                <button v-if="isNew" class="btn btn-ghost btn-sm" @click="generateClientId" title="Vygenerovat">
                  <Copy :size="14" />
                </button>
                <button v-else class="btn btn-ghost btn-sm" @click="copyId" title="Kopírovat">
                  <Copy :size="14" />
                </button>
              </div>
              <p class="field-hint">Unikátní identifikátor, nelze změnit po vytvoření.</p>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Interní poznámka</label>
            <textarea v-model="form.notes" class="form-input form-textarea" rows="3" placeholder="Poznámky pro interní potřebu (klient je nevidí)..." />
          </div>

          <div class="status-toggle-row">
            <div>
              <div class="form-label">Stav účtu</div>
              <p class="field-hint">Neaktivní klient se nemůže přihlásit do portálu.</p>
            </div>
            <button
              class="toggle-btn"
              :class="{ 'toggle-on': form.active }"
              @click="form.active = !form.active"
            >
              <span class="toggle-knob" />
            </button>
            <span :class="form.active ? 'text-success' : 'text-muted'" style="font-weight:500; font-size:14px;">
              {{ form.active ? 'Aktivní' : 'Neaktivní' }}
            </span>
          </div>
        </section>

        <div class="sec-divider" />

        <!-- ═══ SECTION 2: Přihlašovací účty ════════════════════════════════ -->
        <section id="sec-logins" class="form-section">
          <div class="sec-header-row">
            <h2 class="sec-title"><Lock :size="18" /> Přihlašovací účty</h2>
            <button class="btn btn-outline btn-sm" @click="addLogin">
              <Plus :size="14" /> Přidat účet
            </button>
          </div>
          <p class="sec-desc">Každý účet má vlastní e-mail a heslo. Přístup lze omezit na konkrétní IČO.</p>

          <div v-if="form.logins.length === 0" class="empty-list-hint">
            <Lock :size="28" /> Žádné přihlašovací účty. Přidejte alespoň jeden.
          </div>

          <div class="login-list">
            <div v-for="login in form.logins" :key="login.id" class="card login-card">
              <div class="login-header">
                <div class="login-email-wrap">
                  <Mail :size="15" class="text-mid" />
                  <input v-model="login.email" type="email" class="form-input login-email-input" placeholder="email@firma.cz" />
                </div>
                <button class="btn btn-ghost btn-sm danger-hover" @click="removeLogin(login.id)">
                  <Trash2 :size="15" />
                </button>
              </div>

              <!-- Temp password -->
              <div class="form-group" style="margin-top:12px;">
                <label class="form-label">
                  {{ isNew ? 'Počáteční heslo' : 'Nové heslo (reset)' }}
                </label>
                <div class="input-with-btn">
                  <input
                    v-model="login.tempPass"
                    :type="login.showPass ? 'text' : 'password'"
                    class="form-input"
                    placeholder="Zadejte nové heslo..."
                  />
                  <button class="btn btn-ghost btn-sm" @click="login.showPass = !login.showPass" tabindex="-1">
                    <EyeOff v-if="login.showPass" :size="14" />
                    <Eye    v-else                  :size="14" />
                  </button>
                </div>
                <p class="field-hint">Po uložení bude heslo zahashováno. Prázdné pole = heslo se nemění.</p>
              </div>

              <!-- IČO restriction -->
              <div class="restriction-wrap">
                <label class="form-label">Přístup k IČO</label>
                <div class="restriction-options">
                  <label class="radio-option" :class="{ active: login.restriction === 'all' }">
                    <input type="radio" v-model="login.restriction" value="all" />
                    <Globe :size="14" />
                    Všechna IČO
                  </label>
                  <label class="radio-option" :class="{ active: login.restriction === 'icos' }">
                    <input type="radio" v-model="login.restriction" value="icos" />
                    <Shield :size="14" />
                    Omezit na vybraná IČO
                  </label>
                </div>

                <div v-if="login.restriction === 'icos' && form.icos.length > 0" class="ico-checkboxes">
                  <label
                    v-for="ico in form.icos"
                    :key="ico.id"
                    class="ico-checkbox-item"
                    :class="{ checked: login.allowedIcos.includes(ico.ico) }"
                    @click="toggleIcoRestriction(login, ico.ico)"
                  >
                    <span class="ico-cb-box">
                      <CheckCircle2 v-if="login.allowedIcos.includes(ico.ico)" :size="14" />
                    </span>
                    <div>
                      <div style="font-weight:500; font-size:13px;">{{ ico.officialName || '(IČO bez názvu)' }}</div>
                      <div style="font-size:11px; color:var(--color-gray-500);">{{ ico.ico }}</div>
                    </div>
                  </label>
                  <p v-if="form.icos.length === 0" class="field-hint">Nejprve přidejte IČO v sekci níže.</p>
                </div>
              </div>
            </div>
          </div>
        </section>

        <div class="sec-divider" />

        <!-- ═══ SECTION 3: IČO & Provozovny ═════════════════════════════════ -->
        <section id="sec-icos" class="form-section">
          <div class="sec-header-row">
            <h2 class="sec-title"><Building2 :size="18" /> IČO &amp; Provozovny</h2>
            <button class="btn btn-outline btn-sm" @click="addIco">
              <Plus :size="14" /> Přidat IČO
            </button>
          </div>

          <div v-if="form.icos.length === 0" class="empty-list-hint">
            <Building2 :size="28" /> Žádná IČO. Přidejte první.
          </div>

          <div class="ico-cards">
            <div v-for="ico in form.icos" :key="ico.id" class="card ico-card">

              <!-- IČO header (collapsible) -->
              <div class="ico-card-header" @click="ico.expanded = !ico.expanded">
                <div class="ico-title-wrap">
                  <Building2 :size="16" class="text-mid" />
                  <div>
                    <span class="ico-card-name">{{ ico.officialName || '(Nové IČO)' }}</span>
                    <span class="ico-card-num">{{ ico.ico }}</span>
                  </div>
                </div>
                <div class="ico-header-right">
                  <span class="badge badge-info" style="font-size:11px;">{{ ico.objects.length }} provozovny</span>
                  <span v-if="ico.freshqrEnabled" class="badge badge-success" style="font-size:11px;">FreshQR</span>
                  <span v-if="ico.contractUploaded" class="badge badge-gray" style="font-size:11px;">Smlouva ✓</span>
                  <button class="btn btn-ghost btn-sm danger-hover" @click.stop="removeIco(ico.id)"><Trash2 :size="14" /></button>
                  <ChevronUp v-if="ico.expanded" :size="16" class="text-muted" />
                  <ChevronDown v-else :size="16" class="text-muted" />
                </div>
              </div>

              <div v-if="ico.expanded" class="ico-card-body">

                <!-- Basic IČO fields -->
                <div class="field-grid-2" style="margin-bottom:20px;">
                  <div class="form-group">
                    <label class="form-label">IČO *</label>
                    <input v-model="ico.ico" type="text" class="form-input" placeholder="12345678" maxlength="8" />
                  </div>
                  <div class="form-group">
                    <label class="form-label">Oficiální název firmy</label>
                    <input v-model="ico.officialName" type="text" class="form-input" placeholder="Firma s.r.o." />
                  </div>
                </div>

                <!-- Reassign IČO to different client_id -->
                <div class="form-group" style="margin-bottom:20px;">
                  <label class="form-label">Přeřadit pod jiný klientský účet</label>
                  <select v-model="ico.reassignTo" class="form-input" style="max-width:320px;">
                    <option :value="undefined">– ponechat u tohoto klienta –</option>
                    <option v-for="c in otherClients" :key="c.clientId" :value="c.clientId">
                      {{ c.displayName }} ({{ c.clientId }})
                    </option>
                  </select>
                  <p class="field-hint">Po uložení bude toto IČO přesunuto pod vybraného klienta.</p>
                </div>

                <!-- FreshQR + billing model -->
                <div class="ico-toggles-row">
                  <div class="toggle-field">
                    <div>
                      <div class="form-label">Docházka FreshQR</div>
                      <p class="field-hint">Zapne modul docházky pro toto IČO.</p>
                    </div>
                    <button class="toggle-btn" :class="{ 'toggle-on': ico.freshqrEnabled }" @click="ico.freshqrEnabled = !ico.freshqrEnabled">
                      <span class="toggle-knob" />
                    </button>
                    <span :class="ico.freshqrEnabled ? 'text-success' : 'text-muted'" style="font-size:13px; font-weight:500;">
                      {{ ico.freshqrEnabled ? 'Zapnuto' : 'Vypnuto' }}
                    </span>
                  </div>

                  <div class="toggle-field">
                    <div>
                      <div class="form-label">Fakturační model</div>
                      <p class="field-hint">Zobrazí se v modulu docházky.</p>
                    </div>
                    <select v-model="ico.billingModel" class="form-input" style="max-width:180px;">
                      <option value="hourly">Hodinová sazba</option>
                      <option value="fixed">Paušál</option>
                    </select>
                  </div>
                </div>

                <!-- Contract upload -->
                <div class="contract-upload-section">
                  <div class="form-label" style="margin-bottom:8px;">
                    <FileSignature :size="14" style="vertical-align:middle;" /> Smlouva (PDF)
                  </div>
                  <div v-if="ico.contractUploaded" class="contract-uploaded">
                    <div class="contract-file-row">
                      <FileSignature :size="16" class="text-success" />
                      <span class="fw-500">{{ ico.contractFile }}</span>
                      <button class="btn btn-ghost btn-sm" @click="ico.contractUploaded = false; ico.contractFile = null">
                        <Trash2 :size="13" />
                      </button>
                    </div>
                    <label class="btn btn-outline btn-sm" style="cursor:pointer; margin-top:8px;">
                      <Upload :size="13" /> Nahrát novou verzi
                      <input type="file" accept=".pdf" style="display:none;" @change="e => handleContractUpload(ico, e)" />
                    </label>
                  </div>
                  <div v-else class="contract-empty">
                    <div class="alert alert-warning" style="margin-bottom:12px;">
                      <AlertTriangle :size="16" />
                      Smlouva není nahrána. Klient uvidí výzvu ke kontaktu.
                    </div>
                    <label class="btn btn-primary btn-sm" style="cursor:pointer;">
                      <Upload :size="14" /> Nahrát smlouvu (PDF)
                      <input type="file" accept=".pdf" style="display:none;" @change="e => handleContractUpload(ico, e)" />
                    </label>
                  </div>
                </div>

                <!-- Objects / Provozovny -->
                <div class="objects-section">
                  <div class="objects-header">
                    <div class="form-label" style="margin-bottom:0;">
                      <MapPin :size="14" style="vertical-align:middle;" /> Provozovny
                    </div>
                    <button class="btn btn-ghost btn-sm" @click="addObject(ico)">
                      <Plus :size="13" /> Přidat provozovnu
                    </button>
                  </div>

                  <div v-if="ico.objects.length === 0" class="empty-list-hint" style="padding:16px;">
                    Žádné provozovny. Přidejte první.
                  </div>

                  <div class="object-cards">
                    <div v-for="obj in ico.objects" :key="obj.id" class="object-card">

                      <div class="obj-card-header" @click="obj.expanded = !obj.expanded">
                        <MapPin :size="14" class="text-mid" />
                        <span class="obj-name">{{ obj.name || '(Nová provozovna)' }}</span>
                        <span class="obj-address">{{ obj.address }}</span>
                        <button class="btn btn-ghost btn-sm danger-hover" @click.stop="removeObject(ico, obj.id)">
                          <Trash2 :size="13" />
                        </button>
                        <ChevronUp   v-if="obj.expanded" :size="14" class="text-muted" />
                        <ChevronDown v-else               :size="14" class="text-muted" />
                      </div>

                      <div v-if="obj.expanded" class="obj-card-body">
                        <div class="field-grid-2">
                          <div class="form-group">
                            <label class="form-label">Název provozovny *</label>
                            <input v-model="obj.name" type="text" class="form-input" placeholder="Kanceláře / Sklad / Recepce..." />
                          </div>
                          <div class="form-group">
                            <label class="form-label">Adresa</label>
                            <div class="input-with-btn">
                              <input v-model="obj.address" type="text" class="form-input" placeholder="Ulice č.p., Praha X" />
                              <button class="btn btn-ghost btn-sm" @click="geocodeAddress(obj)" title="Vyhledat na mapě">
                                <MapPin :size="14" />
                              </button>
                            </div>
                          </div>
                        </div>

                        <!-- Map -->
                        <div class="map-section">
                          <div v-if="obj.lat && obj.lng" class="map-wrap">
                            <iframe
                              :src="mapSrc(obj)"
                              class="map-iframe"
                              frameborder="0"
                              scrolling="no"
                              loading="lazy"
                              title="Poloha provozovny"
                            />
                            <div class="map-coords">
                              <span>{{ obj.lat.toFixed(5) }}, {{ obj.lng.toFixed(5) }}</span>
                              <button class="btn btn-ghost btn-sm" @click="obj.lat = null; obj.lng = null">
                                <Trash2 :size="12" /> Zrušit polohu
                              </button>
                            </div>
                          </div>
                          <div v-else class="map-placeholder">
                            <MapPin :size="32" style="color:var(--color-gray-400);" />
                            <p>Poloha není nastavena</p>
                            <button class="btn btn-outline btn-sm" @click="geocodeAddress(obj)">
                              <MapPin :size="14" /> Vyhledat adresu na mapě
                            </button>
                          </div>
                          <div class="field-grid-2" style="margin-top:10px;">
                            <div class="form-group">
                              <label class="form-label">Zeměpisná šířka</label>
                              <input v-model.number="obj.lat" type="number" class="form-input" placeholder="50.0523" step="0.0001" />
                            </div>
                            <div class="form-group">
                              <label class="form-label">Zeměpisná délka</label>
                              <input v-model.number="obj.lng" type="number" class="form-input" placeholder="14.4629" step="0.0001" />
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

              </div><!-- /ico-card-body -->
            </div>
          </div>
        </section>

        <div class="sec-divider" />

        <!-- ═══ SECTION 4: Personál ══════════════════════════════════════════ -->
        <section id="sec-staff" class="form-section">
          <div class="sec-header-row">
            <h2 class="sec-title"><Users :size="18" /> Personál</h2>
            <button class="btn btn-outline btn-sm" @click="addStaff">
              <Plus :size="14" /> Přidat pracovníka
            </button>
          </div>
          <p class="sec-desc">Každý pracovník je přiřazen k provozovnám a má vlastní nastavení GDPR viditelnosti.</p>

          <div v-if="form.staff.length === 0" class="empty-list-hint">
            <Users :size="28" /> Žádní pracovníci. Přidejte prvního.
          </div>

          <div class="staff-list">
            <div v-for="person in form.staff" :key="person.id" class="card staff-card">

              <div class="staff-card-header" @click="person.expanded = !person.expanded">
                <div class="staff-avatar">{{ person.name ? person.name.split(' ').map(w=>w[0]).join('').slice(0,2) : '?' }}</div>
                <div class="staff-header-info">
                  <span class="staff-name">{{ person.name || '(Nový pracovník)' }}</span>
                  <span class="staff-role text-muted">{{ person.role }}</span>
                </div>
                <div class="staff-assigned-tags">
                  <span v-for="objId in person.assignedObjects" :key="objId" class="badge badge-info" style="font-size:11px;">
                    {{ allObjects.find(o => o.id === objId)?.name || objId }}
                  </span>
                </div>
                <button class="btn btn-ghost btn-sm danger-hover" @click.stop="removeStaff(person.id)"><Trash2 :size="14" /></button>
                <ChevronUp v-if="person.expanded" :size="16" class="text-muted" />
                <ChevronDown v-else :size="16" class="text-muted" />
              </div>

              <div v-if="person.expanded" class="staff-card-body">
                <div class="field-grid-2">
                  <div class="form-group">
                    <label class="form-label">Jméno a příjmení *</label>
                    <input v-model="person.name" type="text" class="form-input" placeholder="Jan Novák" />
                  </div>
                  <div class="form-group">
                    <label class="form-label">Pozice / Role</label>
                    <input v-model="person.role" type="text" class="form-input" placeholder="Vedoucí týmu" />
                  </div>
                  <div class="form-group">
                    <label class="form-label">Délka spolupráce</label>
                    <input v-model="person.tenure" type="text" class="form-input" placeholder="2 roky" />
                  </div>
                  <div class="form-group">
                    <label class="form-label">Telefon</label>
                    <input v-model="person.phone" type="tel" class="form-input" placeholder="+420 7xx xxx xxx" />
                  </div>
                </div>
                <div class="field-grid-2">
                  <div class="form-group">
                    <label class="form-label">O pracovníkovi (bio)</label>
                    <textarea v-model="person.bio" class="form-input form-textarea" rows="2" placeholder="Krátký popis..." />
                  </div>
                  <div class="form-group">
                    <label class="form-label">Záliby</label>
                    <textarea v-model="person.hobbies" class="form-input form-textarea" rows="2" placeholder="Sport, cestování..." />
                  </div>
                </div>

                <!-- Object assignment -->
                <div class="form-group">
                  <label class="form-label">Přiřazení k provozovnám</label>
                  <div v-if="allObjects.length > 0" class="obj-checkboxes">
                    <label
                      v-for="obj in allObjects"
                      :key="obj.id"
                      class="ico-checkbox-item"
                      :class="{ checked: person.assignedObjects.includes(obj.id) }"
                      @click="() => { const i = person.assignedObjects.indexOf(obj.id); i === -1 ? person.assignedObjects.push(obj.id) : person.assignedObjects.splice(i,1) }"
                    >
                      <span class="ico-cb-box">
                        <CheckCircle2 v-if="person.assignedObjects.includes(obj.id)" :size="13" />
                      </span>
                      <span style="font-size:13px;">{{ obj.icoLabel }}</span>
                    </label>
                  </div>
                  <p v-else class="field-hint">Nejprve přidejte IČO a provozovny výše.</p>
                </div>

                <!-- GDPR toggles -->
                <div class="gdpr-section">
                  <div class="form-label" style="margin-bottom:10px;">Viditelnost v portálu (GDPR)</div>
                  <div class="gdpr-grid">
                    <label v-for="(label, key) in { showRole: 'Pozice', showTenure: 'Délka spolupráce', showBio: 'Bio', showHobbies: 'Záliby', showPhone: 'Telefon', showPhoto: 'Fotografie' }"
                      :key="key" class="gdpr-toggle-item">
                      <button class="toggle-btn toggle-sm" :class="{ 'toggle-on': person[key] }" @click="person[key] = !person[key]">
                        <span class="toggle-knob" />
                      </button>
                      <span>{{ label }}</span>
                    </label>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <div class="sec-divider" />

        <!-- ═══ SECTION 5: Kontaktní osoby ═══════════════════════════════════ -->
        <section id="sec-contacts" class="form-section">
          <div class="sec-header-row">
            <h2 class="sec-title"><Phone :size="18" /> Kontaktní osoby klienta</h2>
            <button class="btn btn-outline btn-sm" @click="addContact">
              <Plus :size="14" /> Přidat kontakt
            </button>
          </div>
          <p class="sec-desc">Osoby na straně zákazníka – koho kontaktovat při fakturaci, plánování nebo řešení provozu. Lze přiřadit ke konkrétnímu IČO.</p>

          <div v-if="form.contacts.length === 0" class="empty-list-hint">
            <Phone :size="28" /> Žádné kontaktní osoby.
          </div>

          <div class="contact-list">
            <div v-for="contact in form.contacts" :key="contact.id" class="card contact-edit-card">
              <div class="contact-edit-header">
                <div class="contact-scope-badge">
                  <Globe v-if="contact.scope === 'global'" :size="13" />
                  <Building2 v-else :size="13" />
                  {{ contact.scope === 'global' ? 'Celý účet' : 'Per IČO' }}
                </div>
                <button class="btn btn-ghost btn-sm danger-hover" @click="removeContact(contact.id)"><Trash2 :size="14" /></button>
              </div>
              <div class="field-grid-2">
                <div class="form-group">
                  <label class="form-label">Jméno</label>
                  <input v-model="contact.name" type="text" class="form-input" placeholder="Jméno Příjmení" />
                </div>
                <div class="form-group">
                  <label class="form-label">Pozice ve firmě</label>
                  <input v-model="contact.role" type="text" class="form-input" placeholder="Facility Manager, Ekonomka…" />
                </div>
                <div class="form-group">
                  <label class="form-label">Telefon</label>
                  <input v-model="contact.phone" type="tel" class="form-input" placeholder="+420 7xx xxx xxx" />
                </div>
                <div class="form-group">
                  <label class="form-label">E-mail</label>
                  <input v-model="contact.email" type="email" class="form-input" placeholder="jan@firma.cz" />
                </div>
              </div>

              <!-- Scope -->
              <div class="restriction-wrap" style="margin-top:0;">
                <label class="form-label">Platí pro</label>
                <div class="restriction-options">
                  <label class="radio-option" :class="{ active: contact.scope === 'global' }">
                    <input type="radio" v-model="contact.scope" value="global" />
                    <Globe :size="13" /> Celý zákaznický účet
                  </label>
                  <label class="radio-option" :class="{ active: contact.scope === 'icos' }">
                    <input type="radio" v-model="contact.scope" value="icos" />
                    <Building2 :size="13" /> Konkrétní IČO / firma
                  </label>
                </div>
                <div v-if="contact.scope === 'icos'" class="form-group" style="margin-top:10px;">
                  <label class="form-label">IČO / Firma</label>
                  <select v-model="contact.icoId" class="form-input">
                    <option :value="null">– vyberte –</option>
                    <option v-for="ico in form.icos" :key="ico.id" :value="ico.id">
                      {{ ico.officialName }} ({{ ico.ico }})
                    </option>
                  </select>
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- Bottom save bar -->
        <div class="bottom-save-bar">
          <button class="btn btn-ghost" @click="router.push('/admin')">Zrušit</button>
          <span v-if="saved" class="saved-msg"><CheckCircle2 :size="15" /> Změny uloženy</span>
          <button class="btn btn-primary" :disabled="saving" @click="save">
            <Save :size="16" />
            {{ saving ? 'Ukládám...' : 'Uložit změny' }}
          </button>
        </div>

      </div><!-- /form-content -->
    </div><!-- /edit-layout -->
  </div>
</template>

<style scoped>
/* ── Page layout ────────────────────────────────────────────────────────────── */
.edit-page {
  display: flex;
  flex-direction: column;
  min-height: 100%;
}

/* Top bar */
.topbar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 0 20px;
  flex-wrap: wrap;
}

.back-btn { color: var(--color-gray-600); }
.back-btn:hover { color: var(--color-primary); }

.topbar-title {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 1;
}

.topbar-label {
  font-size: 18px;
  font-weight: 700;
  color: var(--color-primary);
}

.topbar-id {
  font-size: 12px;
  background: var(--color-gray-100);
  color: var(--color-gray-600);
  padding: 2px 8px;
  border-radius: 6px;
  font-weight: 500;
}

.topbar-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.saved-msg {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 13px;
  color: var(--color-success);
  font-weight: 500;
}

/* Split layout */
.edit-layout {
  display: flex;
  gap: 24px;
  align-items: flex-start;
  flex: 1;
}

/* Section nav */
.section-nav {
  width: 200px;
  flex-shrink: 0;
  position: sticky;
  top: 20px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.snav-item {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 9px 12px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 500;
  color: var(--color-gray-600);
  background: none;
  border: none;
  cursor: pointer;
  text-align: left;
  transition: var(--transition);
  width: 100%;
}

.snav-item:hover {
  background: var(--color-gray-100);
  color: var(--color-primary);
}

.snav-item.active {
  background: var(--color-light);
  color: var(--color-primary);
  font-weight: 600;
}

/* Form content */
.form-content {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.form-section {
  padding: 8px 0 24px;
  scroll-margin-top: 20px;
}

.sec-title {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 16px;
  font-weight: 700;
  color: var(--color-primary);
  margin-bottom: 6px;
}

.sec-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}

.sec-desc {
  font-size: 13px;
  color: var(--color-gray-600);
  margin-bottom: 16px;
}

.sec-divider {
  height: 1px;
  background: var(--color-gray-200);
  margin: 8px 0 24px;
}

/* Field layouts */
.field-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}

.field-hint {
  font-size: 11px;
  color: var(--color-gray-500);
  margin-top: 4px;
}

.form-textarea { resize: vertical; min-height: 72px; }

.input-with-btn {
  display: flex;
  gap: 6px;
  align-items: center;
}
.input-with-btn .form-input { flex: 1; }

/* Toggle switch */
.status-toggle-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
  background: var(--color-gray-50);
  border-radius: var(--radius-md);
  border: 1px solid var(--color-gray-200);
}

.toggle-field {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  background: var(--color-gray-50);
  border-radius: var(--radius-md);
  border: 1px solid var(--color-gray-200);
}

.toggle-btn {
  position: relative;
  width: 44px;
  height: 24px;
  border-radius: 12px;
  background: var(--color-gray-300);
  border: none;
  cursor: pointer;
  transition: background 0.2s;
  flex-shrink: 0;
}
.toggle-btn.toggle-on { background: var(--color-success); }
.toggle-btn.toggle-sm { width: 36px; height: 20px; border-radius: 10px; }

.toggle-knob {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: white;
  box-shadow: 0 1px 3px rgba(0,0,0,0.2);
  transition: left 0.2s;
}
.toggle-sm .toggle-knob { width: 16px; height: 16px; }
.toggle-on .toggle-knob { left: calc(100% - 22px); }
.toggle-sm.toggle-on .toggle-knob { left: calc(100% - 18px); }

/* Radio options */
.restriction-wrap { margin-top: 12px; }

.restriction-options {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 6px;
  margin-bottom: 12px;
}

.radio-option {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 8px 14px;
  border-radius: var(--radius-md);
  border: 1.5px solid var(--color-gray-300);
  cursor: pointer;
  font-size: 13px;
  color: var(--color-gray-700);
  transition: var(--transition);
}
.radio-option input { display: none; }
.radio-option.active {
  border-color: var(--color-primary);
  background: var(--color-light);
  color: var(--color-primary);
  font-weight: 500;
}
.radio-option:hover { border-color: var(--color-mid); }

/* IČO checkboxes */
.ico-checkboxes, .obj-checkboxes {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 4px;
}

.ico-checkbox-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  border-radius: var(--radius-md);
  border: 1.5px solid var(--color-gray-200);
  cursor: pointer;
  transition: var(--transition);
  background: white;
}
.ico-checkbox-item:hover { border-color: var(--color-mid); }
.ico-checkbox-item.checked { border-color: var(--color-primary); background: var(--color-light); }

.ico-cb-box {
  width: 20px;
  height: 20px;
  border-radius: 5px;
  border: 1.5px solid var(--color-gray-300);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  color: var(--color-primary);
  background: white;
}
.checked .ico-cb-box { border-color: var(--color-primary); background: var(--color-light); }

/* Login card */
.login-list { display: flex; flex-direction: column; gap: 14px; }

.login-card { padding: 16px; }

.login-header {
  display: flex;
  align-items: center;
  gap: 10px;
}

.login-email-wrap {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 8px;
}

.login-email-input { flex: 1; }

/* IČO cards */
.ico-cards { display: flex; flex-direction: column; gap: 14px; }

.ico-card { padding: 0; overflow: hidden; }

.ico-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  cursor: pointer;
  gap: 12px;
}
.ico-card-header:hover { background: var(--color-gray-50); }

.ico-title-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 1;
}
.ico-card-name { font-weight: 600; color: var(--color-primary); font-size: 15px; }
.ico-card-num  { font-size: 12px; color: var(--color-gray-500); margin-left: 8px; }

.ico-header-right {
  display: flex;
  align-items: center;
  gap: 8px;
}

.ico-card-body {
  padding: 0 18px 18px;
  border-top: 1px solid var(--color-gray-100);
  padding-top: 16px;
}

.ico-toggles-row {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 20px;
}

/* Contract upload */
.contract-upload-section {
  padding: 14px 0;
  border-top: 1px solid var(--color-gray-100);
  border-bottom: 1px solid var(--color-gray-100);
  margin-bottom: 20px;
}
.contract-uploaded { display: flex; flex-direction: column; }
.contract-file-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  background: var(--color-success-light);
  border-radius: var(--radius-md);
  font-size: 13px;
}
.contract-empty {}

/* Objects */
.objects-section {}
.objects-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}
.object-cards { display: flex; flex-direction: column; gap: 8px; }

.object-card {
  border: 1.5px solid var(--color-gray-200);
  border-radius: var(--radius-md);
  overflow: hidden;
}

.obj-card-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  cursor: pointer;
  background: var(--color-gray-50);
}
.obj-card-header:hover { background: var(--color-gray-100); }

.obj-name { font-weight: 600; font-size: 13px; color: var(--color-primary); }
.obj-address { font-size: 12px; color: var(--color-gray-500); flex: 1; }

.obj-card-body { padding: 14px; }

/* Map */
.map-section { margin-top: 4px; }
.map-wrap {}
.map-iframe {
  width: 100%;
  height: 220px;
  border-radius: var(--radius-md);
  border: 1.5px solid var(--color-gray-200);
}
.map-coords {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 4px 0;
  font-size: 12px;
  color: var(--color-gray-500);
}
.map-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  height: 120px;
  border: 2px dashed var(--color-gray-300);
  border-radius: var(--radius-md);
  background: var(--color-gray-50);
  text-align: center;
  font-size: 13px;
  color: var(--color-gray-500);
}

/* Staff list */
.staff-list { display: flex; flex-direction: column; gap: 10px; }

.staff-card { padding: 0; overflow: hidden; }

.staff-card-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  cursor: pointer;
}
.staff-card-header:hover { background: var(--color-gray-50); }

.staff-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--color-mid);
  color: white;
  font-size: 13px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.staff-header-info {
  display: flex;
  flex-direction: column;
  flex: 1;
  gap: 2px;
}
.staff-name { font-weight: 600; font-size: 14px; color: var(--color-primary); }
.staff-role { font-size: 12px; }

.staff-assigned-tags { display: flex; gap: 4px; flex-wrap: wrap; }

.staff-card-body {
  padding: 0 16px 16px;
  border-top: 1px solid var(--color-gray-100);
  padding-top: 14px;
}

/* GDPR */
.gdpr-section {
  padding: 14px;
  background: var(--color-gray-50);
  border-radius: var(--radius-md);
  border: 1px solid var(--color-gray-200);
  margin-top: 4px;
}
.gdpr-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
}
.gdpr-toggle-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-gray-700);
  cursor: pointer;
}

/* Contacts */
.contact-list { display: flex; flex-direction: column; gap: 12px; }

.contact-edit-card { padding: 16px; }

.contact-edit-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.contact-scope-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  font-weight: 500;
  padding: 3px 10px;
  border-radius: var(--radius-pill);
  background: var(--color-light);
  color: var(--color-primary);
}

/* Empty hints */
.empty-list-hint {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 20px 16px;
  border: 2px dashed var(--color-gray-200);
  border-radius: var(--radius-md);
  font-size: 13px;
  color: var(--color-gray-500);
}

/* Danger hover */
.danger-hover:hover { color: var(--color-danger) !important; }

/* Bottom save bar */
.bottom-save-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  padding: 20px 0 8px;
  border-top: 1px solid var(--color-gray-200);
  margin-top: 12px;
}

/* Responsive */
@media (max-width: 900px) {
  .section-nav { display: none; }
  .field-grid-2 { grid-template-columns: 1fr; }
  .gdpr-grid    { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 600px) {
  .gdpr-grid { grid-template-columns: 1fr; }
  .ico-toggles-row { flex-direction: column; }
}
</style>
