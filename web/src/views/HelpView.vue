<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import {
  LayoutDashboard, Clock, ClipboardList, FileText, FileSignature,
  Users, Phone, ShieldCheck, KeyRound, LifeBuoy, HelpCircle,
  ChevronDown, Search, Sparkles, MessageCircle, X,
} from 'lucide-vue-next'

const router = useRouter()

// The overview grid mirrors the client navigation, kept separate from the Q&A
// below. Each description is the section's opening sentence, verbatim from the
// source document.
const featureCards = [
  { id: 'prehled',   icon: LayoutDashboard, title: 'Přehled',               desc: 'Hlavní přehled slouží jako rychlá orientace ve spolupráci.' },
  { id: 'dochazka',  icon: Clock,           title: 'Docházka a záznamy',    desc: 'Sekce „Docházka a záznamy“ slouží k přehledu úklidů a důležitých záznamů z vašeho objektu.' },
  { id: 'zadosti',   icon: ClipboardList,   title: 'Požadavky a reklamace', desc: 'Tato sekce slouží k tomu, abyste nám mohli jednoduše zadat požadavek, reklamaci nebo jinou důležitou informaci k vašemu objektu.' },
  { id: 'faktury',   icon: FileText,        title: 'Fakturace',             desc: 'V sekci Fakturace najdete přehled vystavených faktur, které souvisí s vaší spoluprací s FAJN ÚKLID.' },
  { id: 'smlouvy',   icon: FileSignature,   title: 'Smlouvy a dokumenty',   desc: 'V této sekci najdete důležité dokumenty ke spolupráci.' },
  { id: 'personal',  icon: Users,           title: 'Personál',              desc: 'V sekci Personál můžete vidět vybrané informace o pracovnících, kteří se podílí na úklidu vašeho objektu.' },
  { id: 'kontakty',  icon: Phone,           title: 'Kontakty',              desc: 'V sekci Kontakty najdete hlavní kontaktní údaje na FAJN ÚKLID.' },
]

// Q&A grouped by portal area. Answers are structured blocks so we never inject
// raw HTML: p = paragraph, ul = bullet list, ol = numbered list,
// dl = labelled items (e.g. request states).
const faqGroups = [
  {
    id: 'pristup',
    icon: KeyRound,
    title: 'Přihlášení do portálu',
    items: [
      {
        id: 'jak-se-prihlasim',
        q: 'Jak se do portálu dostanu?',
        a: [
          { t: 'p', text: 'Přístup do portálu vám vytvoří FAJN ÚKLID. Po aktivaci účtu dostanete přihlašovací údaje, kterými se přihlásíte do aplikace.' },
          { t: 'p', text: 'Portál je určený primárně pro naše klienty. Pokud ještě nejste naším klientem a aplikaci jste si otevřeli z App Store, Google Play nebo z webu, můžete si prohlédnout základní informace o tom, co portál umí, a následně nás kontaktovat pro ukázku nebo osobní schůzku.' },
        ],
      },
      {
        id: 'jen-klienti',
        q: 'Je portál dostupný jen klientům?',
        a: [
          { t: 'p', text: 'Ano. Plná funkčnost portálu je dostupná pouze klientům FAJN ÚKLID, protože data v portálu jsou navázaná na konkrétní objekt, smlouvu, fakturaci a nastavení spolupráce.' },
          { t: 'p', text: 'Pokud s námi zatím nespolupracujete, rádi vám portál ukážeme jako součást představení našich služeb.' },
        ],
      },
    ],
  },
  {
    id: 'prehled',
    icon: LayoutDashboard,
    title: 'Přehled',
    items: [
      {
        id: 'k-cemu-prehled',
        q: 'K čemu slouží hlavní přehled?',
        a: [
          { t: 'p', text: 'Hlavní přehled slouží jako rychlá orientace ve spolupráci. Na jednom místě vidíte nejdůležitější informace, například:' },
          { t: 'ul', items: [
            'jestli u vás právě probíhá úklid,',
            'poslední nebo nadcházející záznamy,',
            'stav požadavků a reklamací,',
            'základní přehled faktur,',
            'rychlé odkazy do nejdůležitějších částí portálu.',
          ] },
          { t: 'p', text: 'Cílem přehledu není zobrazit úplně všechno, ale ukázat vám to, co je v danou chvíli nejdůležitější.' },
        ],
      },
    ],
  },
  {
    id: 'dochazka',
    icon: Clock,
    title: 'Docházka a záznamy',
    items: [
      {
        id: 'jak-funguje-dochazka',
        q: 'Jak funguje docházka?',
        a: [
          { t: 'p', text: 'Sekce „Docházka a záznamy“ slouží k přehledu úklidů a důležitých záznamů z vašeho objektu.' },
          { t: 'p', text: 'Pokud je u vašeho objektu aktivovaná QR docházka, pracovníci při příchodu a odchodu skenují QR kód. Díky tomu se v portálu mohou zobrazovat informace o tom, kdy byl úklid proveden.' },
          { t: 'p', text: 'Podle nastavení spolupráce může portál zobrazovat například:' },
          { t: 'ul', items: [
            'dny, kdy proběhl úklid,',
            'čas příchodu a odchodu,',
            'počet pracovníků,',
            'jména pracovníků,',
            'poznámky z objektu,',
            'záznamy od vedoucího nebo manažera,',
            'požadavky navázané na konkrétní den.',
          ] },
          { t: 'p', text: 'Rozsah zobrazených informací se může lišit podle toho, jak máme u vašeho objektu nastavený režim transparentnosti.' },
        ],
      },
      {
        id: 'proc-nevidim-detail',
        q: 'Proč někdy nevidím detail docházky?',
        a: [
          { t: 'p', text: 'Detail docházky se zobrazí pouze u objektů, kde je tato funkce aktivovaná a kde jsou data dostupná. Může se stát, že:' },
          { t: 'ul', items: [
            'QR docházka u objektu zatím není aktivní,',
            'data se ještě nenačetla,',
            'záznam čeká na zpracování,',
            'u daného klienta je nastavený pouze základní přehled bez detailních časů,',
            'konkrétní den neobsahuje žádný záznam.',
          ] },
          { t: 'p', text: 'Pokud si nejste jistí, zda má být docházka u vašeho objektu aktivní, kontaktujte nás.' },
        ],
      },
      {
        id: 'uklid-prave-probiha',
        q: 'Co znamená „Úklid právě probíhá“?',
        a: [
          { t: 'p', text: 'Pokud se v portálu zobrazí informace „Úklid právě probíhá“, znamená to, že je na objektu aktuálně zaznamenaný aktivní úklid.' },
          { t: 'p', text: 'Tato funkce pomáhá klientům vidět, že služba právě probíhá, aniž by museli psát nebo volat. Je to jeden z nástrojů, kterým chceme zvyšovat přehlednost a důvěru ve spolupráci.' },
        ],
      },
      {
        id: 'zaznamy-a-poznamky',
        q: 'Co jsou záznamy a poznámky?',
        a: [
          { t: 'p', text: 'Záznamy a poznámky slouží k tomu, aby se důležité informace z objektu neztratily. Může jít například o:' },
          { t: 'ul', items: [
            'poznámku pracovníka z úklidu,',
            'poznámku vedoucího nebo manažera po kontrole,',
            'informaci od klienta sdělenou přes telefon nebo WhatsApp,',
            'záznam o mimořádné situaci,',
            'požadavek navázaný na konkrétní den.',
          ] },
          { t: 'p', text: 'Smyslem je, aby důležité věci nebyly jen v chatu nebo v hlavě jednoho člověka, ale byly dohledatelné v historii spolupráce.' },
        ],
      },
    ],
  },
  {
    id: 'pozadavky',
    icon: ClipboardList,
    title: 'Požadavky a reklamace',
    items: [
      {
        id: 'k-cemu-pozadavky',
        q: 'K čemu slouží sekce Požadavky a reklamace?',
        a: [
          { t: 'p', text: 'Tato sekce slouží k tomu, abyste nám mohli jednoduše zadat požadavek, reklamaci nebo jinou důležitou informaci k vašemu objektu. Můžete nám tak nahlásit například:' },
          { t: 'ul', items: [
            'nedostatečně uklizené místo,',
            'potřebu mimořádné práce,',
            'změnu v harmonogramu,',
            'chybějící hygienický materiál,',
            'dotaz nebo organizační požadavek.',
          ] },
          { t: 'p', text: 'Cílem je, aby se požadavky neztrácely ve WhatsAppu, e-mailech nebo telefonátech a aby měly jasnou historii.' },
        ],
      },
      {
        id: 'po-odeslani',
        q: 'Co se stane po odeslání požadavku?',
        a: [
          { t: 'p', text: 'Po odeslání se požadavek zaeviduje v portálu a zároveň se odešle na e-mail FAJN ÚKLID.' },
          { t: 'p', text: 'Díky tomu ho máme v interním přehledu a můžeme se mu věnovat. O řešení vás budeme informovat podle situace – v portálu, telefonicky, e-mailem nebo přes běžný komunikační kanál, který spolu používáme.' },
        ],
      },
      {
        id: 'stavy-pozadavku',
        q: 'Jaké stavy může požadavek mít?',
        a: [
          { t: 'p', text: 'Požadavek může mít několik stavů:' },
          { t: 'dl', items: [
            { label: 'Nový', text: 'požadavek byl odeslán a čeká na zpracování.' },
            { label: 'V řešení', text: 'požadavkem se zabýváme.' },
            { label: 'Vyřešeno – čeká na potvrzení klienta', text: 'požadavek jsme označili jako vyřešený a čekáme na vaše potvrzení.' },
            { label: 'Uzavřeno', text: 'požadavek je dokončený.' },
          ] },
          { t: 'p', text: 'Pokud s řešením nesouhlasíte, můžete nám dát vědět a požadavek se může vrátit k dalšímu řešení.' },
        ],
      },
      {
        id: 'fotka-k-pozadavku',
        q: 'Můžu k požadavku přidat fotku?',
        a: [
          { t: 'p', text: 'Ano, pokud je tato možnost aktivní, můžete k požadavku přidat fotku nebo přílohu. Fotka nám pomůže rychleji pochopit situaci a lépe ji předat pracovníkům nebo vedoucímu.' },
        ],
      },
    ],
  },
  {
    id: 'fakturace',
    icon: FileText,
    title: 'Fakturace',
    items: [
      {
        id: 'jak-funguje-fakturace',
        q: 'Jak funguje fakturace v portálu?',
        a: [
          { t: 'p', text: 'V sekci Fakturace najdete přehled vystavených faktur, které souvisí s vaší spoluprací s FAJN ÚKLID.' },
          { t: 'p', text: 'Faktury se v portálu zobrazují díky propojení s naším fakturačním systémem. Díky tomu můžete mít přehled o důležitých údajích bez nutnosti dohledávat e-maily.' },
          { t: 'p', text: 'U faktury můžete vidět například:' },
          { t: 'ul', items: [
            'číslo faktury,',
            'datum vystavení,',
            'datum splatnosti,',
            'částku,',
            'stav úhrady,',
            'možnost stáhnout PDF.',
          ] },
        ],
      },
      {
        id: 'proc-nevidim-fakturu',
        q: 'Proč někdy fakturu ještě nevidím?',
        a: [
          { t: 'p', text: 'Faktura se v portálu zobrazí až ve chvíli, kdy je vystavená a dostupná v našem systému. Může se stát, že:' },
          { t: 'ul', items: [
            'faktura ještě nebyla vystavena,',
            'data se ještě nesynchronizovala,',
            'faktura je vystavená na jinou firmu/IČO,',
            'u vašeho účtu zatím není daná fakturační jednotka propojena.',
          ] },
          { t: 'p', text: 'Pokud fakturu v portálu nevidíte a myslíte si, že už by měla být dostupná, napište nám.' },
        ],
      },
      {
        id: 'nahrazuje-email-faktury',
        q: 'Nahrazuje portál e-mailovou komunikaci k fakturám?',
        a: [
          { t: 'p', text: 'Portál slouží jako přehledné místo, kde faktury najdete pohromadě. Díky tomu je nemusíte hledat v e-mailech. Způsob zasílání faktur se může řídit konkrétní dohodou a nastavením spolupráce.' },
        ],
      },
    ],
  },
  {
    id: 'smlouvy',
    icon: FileSignature,
    title: 'Smlouvy a dokumenty',
    items: [
      {
        id: 'k-cemu-smlouvy',
        q: 'K čemu slouží sekce Smlouvy a dokumenty?',
        a: [
          { t: 'p', text: 'V této sekci najdete důležité dokumenty ke spolupráci. Může jít například o:' },
          { t: 'ul', items: [
            'hlavní smlouvu,',
            'dodatky,',
            'harmonogramy,',
            'dokumenty k zimní údržbě,',
            'rozšíření služby,',
            'jiné důležité přílohy.',
          ] },
          { t: 'p', text: 'Tyto dokumenty sem nahráváme ručně, aby je klienti měli na jednom místě a nemuseli je hledat v e-mailové komunikaci nebo ve starých přílohách.' },
        ],
      },
      {
        id: 'proc-chybi-dokument',
        q: 'Proč některý dokument chybí?',
        a: [
          { t: 'p', text: 'Dokument se v portálu zobrazí až ve chvíli, kdy ho do systému nahrajeme.' },
          { t: 'p', text: 'Pokud dokument v portálu chybí, neznamená to automaticky, že neexistuje. Může být pouze zatím nenahraný.' },
          { t: 'p', text: 'V takovém případě nás prosím kontaktujte a dokument doplníme nebo vám ho pošleme jiným způsobem.' },
        ],
      },
    ],
  },
  {
    id: 'personal',
    icon: Users,
    title: 'Personál',
    items: [
      {
        id: 'jak-funguje-personal',
        q: 'Jak fungují informace o personálu?',
        a: [
          { t: 'p', text: 'V sekci Personál můžete vidět vybrané informace o pracovnících, kteří se podílí na úklidu vašeho objektu.' },
          { t: 'p', text: 'Cílem je, aby úklid nebyl anonymní. Chceme, abyste věděli, kdo se o vaše prostory stará, a aby spolupráce působila osobněji a důvěryhodněji.' },
          { t: 'p', text: 'U pracovníka se mohou zobrazovat například:' },
          { t: 'ul', items: [
            'jméno,',
            'fotografie,',
            'role,',
            'krátký popis,',
            'další veřejné informace schválené pro zobrazení v portálu.',
          ] },
        ],
      },
      {
        id: 'proc-chybi-personal',
        q: 'Proč někdy informace o personálu chybí?',
        a: [
          { t: 'p', text: 'Informace o personálu se mohou zobrazovat postupně a nemusí být vždy kompletní. Důvody mohou být například:' },
          { t: 'ul', items: [
            'pracovník je nový a informace ještě nejsou doplněné,',
            'nemáme k dispozici fotografii,',
            'některé informace nejsou vhodné ke zveřejnění,',
            'u daného pracovníka není zapnuté zobrazení v portálu,',
            'chráníme osobní údaje pracovníků.',
          ] },
        ],
      },
      {
        id: 'gdpr',
        q: 'Jak je to s GDPR?',
        a: [
          { t: 'p', text: 'V portálu zobrazujeme pouze informace, které jsou určené pro klientský přehled a které dávají smysl pro spolupráci. Nezobrazujeme citlivé interní informace, mzdy, osobní doklady ani jiné údaje, které do klientského portálu nepatří.' },
          { t: 'p', text: 'Cílem je ukázat lidskou stránku služby, ale zároveň chránit soukromí pracovníků i klientů.' },
        ],
      },
    ],
  },
  {
    id: 'kontakty',
    icon: Phone,
    title: 'Kontakty a komunikace',
    items: [
      {
        id: 'kde-kontakt',
        q: 'Kde najdu kontakt na FAJN ÚKLID?',
        a: [
          { t: 'p', text: 'V sekci Kontakty najdete hlavní kontaktní údaje na FAJN ÚKLID. Podle nastavení spolupráce zde může být také rychlý odkaz na WhatsApp skupinu, kterou s vámi používáme pro běžnou operativní komunikaci.' },
        ],
      },
      {
        id: 'nahrazuje-whatsapp',
        q: 'Nahrazuje portál WhatsApp?',
        a: [
          { t: 'p', text: 'Ne úplně. WhatsApp nebo telefon může dál sloužit pro rychlou domluvu. Portál ale pomáhá tam, kde je potřeba mít věci přehledně zaznamenané – například požadavky, reklamace, dokumenty, faktury nebo historii úklidů.' },
          { t: 'p', text: 'Jednoduše řečeno:' },
          { t: 'ul', items: [
            'WhatsApp je rychlá komunikace,',
            'portál je přehled a historie spolupráce.',
          ] },
        ],
      },
    ],
  },
  {
    id: 'bezpecnost',
    icon: ShieldCheck,
    title: 'Bezpečnost a soukromí',
    items: [
      {
        id: 'vidi-ostatni',
        q: 'Vidí ostatní klienti moje data?',
        a: [
          { t: 'p', text: 'Ne. Každý klient vidí pouze informace navázané na svůj účet a své objekty. Portál je navržený tak, aby klienti neměli přístup k údajům jiných klientů.' },
        ],
      },
      {
        id: 'vice-firem',
        q: 'Co když mám více firem nebo objektů?',
        a: [
          { t: 'p', text: 'Pokud máte pod jedním účtem více firem, IČO nebo objektů, portál může zobrazovat informace rozdělené podle konkrétní protistrany nebo objektu.' },
          { t: 'p', text: 'Díky tomu můžete mít více spoluprací na jednom místě a nemusíte se přihlašovat do více samostatných účtů.' },
        ],
      },
    ],
  },
  {
    id: 'nefunguje',
    icon: LifeBuoy,
    title: 'Když něco nefunguje',
    items: [
      {
        id: 'nesedi',
        q: 'Co mám dělat, když něco v portálu nesedí?',
        a: [
          { t: 'p', text: 'Pokud vidíte chybu, chybějící dokument, nesprávnou fakturu nebo vám něco není jasné, kontaktujte nás. Může se stát, že některá data čekají na doplnění, synchronizaci nebo ruční kontrolu.' },
          { t: 'p', text: 'Doporučený postup:' },
          { t: 'ol', items: [
            'Zkontrolujte, zda jste ve správném objektu nebo firmě.',
            'Podívejte se, zda nejde o data, která se doplňují ručně.',
            'Pokud si nejste jistí, napište nám přes portál, WhatsApp nebo e-mail.',
          ] },
        ],
      },
    ],
  },
  {
    id: 'ostatni',
    icon: HelpCircle,
    title: 'Nejčastější otázky',
    items: [
      {
        id: 'jen-nektere-funkce',
        q: 'Proč mám v portálu jen některé funkce?',
        a: [
          { t: 'p', text: 'Funkce portálu se mohou lišit podle toho, jakou službu u nás využíváte a co je u vašeho objektu aktivované. Například klient bez QR docházky může používat faktury, smlouvy a požadavky, ale nemusí vidět detailní docházkový kalendář.' },
        ],
      },
      {
        id: 'je-povinny',
        q: 'Je portál povinný?',
        a: [
          { t: 'p', text: 'Portál je nástroj pro lepší přehled a jednodušší spolupráci. Jeho cílem není komplikovat komunikaci, ale naopak ji zpřehlednit.' },
        ],
      },
      {
        id: 'mobil',
        q: 'Můžu portál používat na mobilu?',
        a: [
          { t: 'p', text: 'Ano. Portál je připravený pro použití z telefonu i počítače. Pokud používáte mobil, můžete si aplikaci přidat na plochu nebo ji používat jako aplikaci podle dostupné verze.' },
        ],
      },
      {
        id: 'sdilet-s-kolegou',
        q: 'Můžu portál sdílet s kolegou?',
        a: [
          { t: 'p', text: 'Přístup do portálu je potřeba řešit s FAJN ÚKLID. Pokud chcete přidat další osobu, kontaktujte nás a domluvíme vhodné nastavení.' },
        ],
      },
      {
        id: 'qr-aktivovana',
        q: 'Kde se dozvím, jestli je u mě aktivovaná QR docházka?',
        a: [
          { t: 'p', text: 'Pokud je u vašeho objektu QR docházka aktivovaná, uvidíte příslušné informace v sekci Docházka a záznamy. Pokud si nejste jistí, kontaktujte nás a ověříme nastavení.' },
        ],
      },
    ],
  },
]

// Diacritic-insensitive search so "docazka" matches "docházka".
function normalize(value) {
  return (value || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
}

function blockText(block) {
  if (block.t === 'p') return block.text
  if (block.t === 'dl') return block.items.map(i => `${i.label} ${i.text}`).join(' ')
  return block.items.join(' ')
}

const query = ref('')

const filteredGroups = computed(() => {
  const q = normalize(query.value.trim())
  if (!q) return faqGroups
  return faqGroups
    .map(group => {
      // A group-title hit (e.g. "bezpečnost") keeps the whole section, even when
      // no individual question repeats the word.
      if (normalize(group.title).includes(q)) return group
      return {
        ...group,
        items: group.items.filter(item =>
          normalize(item.q + ' ' + item.a.map(blockText).join(' ')).includes(q)
        ),
      }
    })
    .filter(group => group.items.length)
})

const hasResults = computed(() => filteredGroups.value.length > 0)

const isSearching = computed(() => query.value.trim().length > 0)
const matchCount = computed(() =>
  filteredGroups.value.reduce((total, group) => total + group.items.length, 0)
)

// Czech numeric agreement for "výsledek" (1 / 2–4 / 0+5+).
function resultsWord(n) {
  if (n === 1) return 'výsledek'
  if (n >= 2 && n <= 4) return 'výsledky'
  return 'výsledků'
}

function clearSearch() {
  query.value = ''
}

// Multiple answers can stay open at once; keyed by the item's unique id.
const openItems = ref({})
function toggle(id) {
  openItems.value[id] = !openItems.value[id]
}

function goToContact() {
  router.push('/kontakt')
}
</script>

<template>
  <div id="help-page" class="page-shell page-shell--md">
    <!-- Hero — intro text is verbatim from the source document -->
    <section id="help-hero" class="help-hero">
      <span id="help-hero-badge" class="help-hero-badge">
        <Sparkles :size="14" />
        Nápověda a otázky
      </span>
      <h1 id="help-hero-title" class="help-hero-title">Vítejte v klientském portálu FAJN ÚKLID</h1>
      <p id="help-hero-desc" class="help-hero-desc">
        Portál jsme vytvořili proto, abyste měli vše důležité ke spolupráci na jednom místě. Docházku,
        požadavky, faktury, smlouvy, dokumenty i informace o našem týmu najdete přehledně v jedné
        aplikaci – z počítače i z telefonu.
      </p>
      <p id="help-hero-desc-2" class="help-hero-desc">
        Naším cílem je, abyste nemuseli dohledávat dokumenty v e-mailech, psát opakovaně stejné
        požadavky nebo se ptát, kdy u vás proběhl úklid. Portál má přinést větší přehled, rychlejší
        komunikaci a větší jistotu v tom, co se ve vašem objektu děje.
      </p>

      <div id="help-search" class="help-search">
        <Search :size="18" class="help-search-icon" />
        <input
          id="help-search-input"
          v-model="query"
          type="search"
          class="help-search-field"
          placeholder="Hledat v otázkách…"
          aria-label="Hledat v otázkách"
        />
        <button
          v-if="isSearching"
          id="help-search-clear"
          type="button"
          class="help-search-clear"
          aria-label="Vymazat hledání"
          @click="clearSearch"
        >
          <X :size="18" />
        </button>
      </div>
    </section>

    <!-- General: what the portal offers. Hidden while searching so the results
         become the whole focus and visibly sit right under the search box. -->
    <section v-if="!isSearching" id="help-features" class="help-section">
      <div class="help-section-head">
        <h2 id="help-features-title" class="help-section-title">Co v portálu najdete?</h2>
        <p id="help-features-subtitle" class="help-section-subtitle">
          V portálu můžete podle nastavení vašeho účtu najít:
        </p>
      </div>

      <div id="help-features-grid" class="help-features-grid">
        <div
          v-for="card in featureCards"
          :id="`help-feature-${card.id}`"
          :key="card.id"
          class="card help-feature"
        >
          <span class="help-feature-icon"><component :is="card.icon" :size="20" /></span>
          <div class="help-feature-body">
            <h3 class="help-feature-title">{{ card.title }}</h3>
            <p class="help-feature-desc">{{ card.desc }}</p>
          </div>
        </div>
      </div>

      <p id="help-features-note" class="alert alert-info help-features-note">
        Některé funkce se mohou zobrazovat pouze klientům, u kterých jsou aktivované. Pokud například
        na vašem objektu zatím nepoužíváme QR docházku, sekce „Docházka a záznamy“ nemusí být dostupná
        nebo může obsahovat jen základní informace.
      </p>
    </section>

    <!-- Q&A -->
    <section id="help-faq" class="help-section">
      <div class="help-section-head">
        <h2 id="help-faq-title" class="help-section-title">
          {{ isSearching ? 'Výsledky hledání' : 'Časté otázky' }}
        </h2>
        <p v-if="!isSearching" id="help-faq-subtitle" class="help-section-subtitle">
          Rozklikněte otázku a zobrazí se odpověď.
        </p>
        <p v-else-if="matchCount" id="help-faq-count" class="help-section-subtitle">
          {{ matchCount }} {{ resultsWord(matchCount) }} pro „{{ query.trim() }}“
        </p>
      </div>

      <div v-if="!hasResults" id="help-faq-empty" class="card">
        <div class="empty-state">
          <Search :size="40" class="empty-state-icon" />
          <p class="empty-state-title">Nic jsme nenašli.</p>
          <p class="empty-state-text">
            Pro „{{ query.trim() }}“ jsme nenašli žádnou otázku. Zkuste jiná slova nebo se na nás
            rovnou obraťte.
          </p>
          <button id="help-faq-empty-clear" type="button" class="btn btn-outline btn-sm mt-20" @click="clearSearch">
            Zrušit hledání
          </button>
        </div>
      </div>

      <template v-else>
        <div
          v-for="group in filteredGroups"
          :id="`help-group-${group.id}`"
          :key="group.id"
          class="help-group"
        >
          <h3 class="help-group-title">
            <span class="help-group-icon"><component :is="group.icon" :size="18" /></span>
            {{ group.title }}
          </h3>

          <div class="help-accordion">
            <div
              v-for="item in group.items"
              :id="`help-qa-${item.id}`"
              :key="item.id"
              class="help-qa"
              :class="{ open: openItems[item.id] }"
            >
              <button
                :id="`help-qa-${item.id}-btn`"
                class="help-qa-question"
                :aria-expanded="openItems[item.id] ? 'true' : 'false'"
                :aria-controls="`help-qa-${item.id}-answer`"
                @click="toggle(item.id)"
              >
                <span class="help-qa-question-text">{{ item.q }}</span>
                <ChevronDown :size="18" class="help-qa-chevron" />
              </button>

              <div
                :id="`help-qa-${item.id}-answer`"
                class="help-qa-answer"
              >
                <div class="help-qa-answer-inner">
                  <template v-for="(block, bi) in item.a" :key="bi">
                    <p v-if="block.t === 'p'" class="help-answer-p">{{ block.text }}</p>
                    <ul v-else-if="block.t === 'ul'" class="help-answer-list">
                      <li v-for="(li, li2) in block.items" :key="li2">{{ li }}</li>
                    </ul>
                    <ol v-else-if="block.t === 'ol'" class="help-answer-list help-answer-list--ordered">
                      <li v-for="(li, li2) in block.items" :key="li2">{{ li }}</li>
                    </ol>
                    <dl v-else-if="block.t === 'dl'" class="help-answer-states">
                      <div v-for="(st, si) in block.items" :key="si" class="help-answer-state">
                        <dt class="help-answer-state-label">{{ st.label }}</dt>
                        <dd class="help-answer-state-text">{{ st.text }}</dd>
                      </div>
                    </dl>
                  </template>
                </div>
              </div>
            </div>
          </div>
        </div>
      </template>
    </section>

    <!-- CTA: "Potřebujete pomoct?" block recommended by the source document -->
    <section id="help-cta" class="help-cta">
      <span class="help-cta-icon"><MessageCircle :size="24" /></span>
      <div class="help-cta-body">
        <h2 id="help-cta-title" class="help-cta-title">Potřebujete pomoct?</h2>
        <p id="help-cta-desc" class="help-cta-desc">
          Napište nám nebo zavolejte – rádi vám pomůžeme a případně doplníme, co v portálu chybí.
        </p>
      </div>
      <button id="help-cta-btn" class="btn btn-primary" @click="goToContact">
        Přejít na kontakty
      </button>
    </section>
  </div>
</template>

<style scoped>
/* Hero */
.help-hero {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 14px;
  padding: 28px 22px;
  margin-bottom: var(--space-2xl);
  background:
    radial-gradient(circle at 12% 0%, rgba(59, 158, 181, 0.10) 0%, transparent 55%),
    radial-gradient(circle at 95% 110%, rgba(22, 36, 56, 0.06) 0%, transparent 55%),
    var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-xl);
}
@media (min-width: 640px) {
  .help-hero {
    padding: 40px 36px;
  }
}

.help-hero-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  border-radius: var(--radius-pill);
  background: var(--color-light);
  color: var(--color-primary);
  font-size: var(--fs-xs);
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.help-hero-title {
  font-size: var(--fs-3xl);
  font-weight: 700;
  color: var(--color-primary);
  line-height: 1.2;
  max-width: 20ch;
}

.help-hero-desc {
  font-size: var(--fs-md);
  color: var(--color-gray-600);
  line-height: 1.6;
  max-width: 56ch;
}

/* Search */
.help-search {
  position: relative;
  width: 100%;
  margin-top: 4px;
}
.help-search-icon {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--color-gray-400);
  pointer-events: none;
}
.help-search-field {
  width: 100%;
  padding: 12px 44px 12px 42px;
  border: 1.5px solid var(--color-gray-300);
  border-radius: var(--radius-lg);
  /* 16px keeps iOS Safari from auto-zooming on focus */
  font-size: 16px;
  color: var(--color-gray-800);
  background: var(--color-white);
  transition: var(--transition);
  outline: none;
}
@media (min-width: 768px) {
  .help-search-field { font-size: 14px; }
}
.help-search-field:focus {
  border-color: var(--color-mid);
  box-shadow: 0 0 0 3px rgba(59, 158, 181, 0.15);
}
/* Suppress the native ✕ so only our own clear button shows */
.help-search-field::-webkit-search-cancel-button {
  -webkit-appearance: none;
  appearance: none;
}
.help-search-clear {
  position: absolute;
  right: 6px;
  top: 50%;
  transform: translateY(-50%);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: none;
  border-radius: var(--radius-md);
  background: transparent;
  color: var(--color-gray-500);
  cursor: pointer;
  transition: var(--transition);
}
.help-search-clear:hover {
  background: var(--color-gray-100);
  color: var(--color-primary);
}
.help-search-clear:focus-visible {
  outline: 2px solid var(--color-mid);
  outline-offset: 2px;
}

/* Sections */
.help-section {
  margin-bottom: var(--space-2xl);
}
.help-section-head {
  margin-bottom: var(--space-lg);
}
.help-section-title {
  font-size: var(--fs-2xl);
  font-weight: 700;
  color: var(--color-primary);
  line-height: 1.25;
}
.help-section-subtitle {
  font-size: var(--fs-sm);
  color: var(--color-gray-600);
  margin-top: 4px;
}

/* Feature overview grid */
.help-features-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
}
@media (min-width: 640px) {
  .help-features-grid {
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
  }
}

.help-feature {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  padding: 18px;
}
.help-feature-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-md);
  background: var(--color-light);
  color: var(--color-accent);
}
.help-feature-body {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}
.help-feature-title {
  font-size: var(--fs-md);
  font-weight: 600;
  color: var(--color-primary);
}
.help-feature-desc {
  font-size: var(--fs-sm);
  color: var(--color-gray-600);
  line-height: 1.5;
}

.help-features-note {
  margin-top: 14px;
}

/* Q&A groups — section subheaders must clearly outrank the question rows:
   larger + heavier text, a solid accent icon chip, and a trailing divider line
   that questions never have. */
.help-group {
  margin-bottom: var(--space-2xl);
}
.help-group:last-child {
  margin-bottom: 0;
}
.help-group-title {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: var(--fs-xl);
  font-weight: 700;
  letter-spacing: -0.01em;
  color: var(--color-primary);
  margin-bottom: 16px;
}
/* Hairline extends from the title across the row, reading as a section rule */
.help-group-title::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--color-gray-200);
}
.help-group-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 34px;
  height: 34px;
  border-radius: var(--radius-md);
  background: var(--color-accent);
  color: var(--color-white);
}

.help-accordion {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.help-qa {
  background: var(--color-white);
  border: 1px solid var(--color-gray-200);
  border-radius: var(--radius-lg);
  overflow: hidden;
  transition: border-color var(--transition), box-shadow var(--transition);
}
.help-qa.open {
  border-color: var(--color-blue-border);
  box-shadow: var(--shadow-sm);
}

.help-qa-question {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  width: 100%;
  padding: 16px 18px;
  background: transparent;
  border: none;
  text-align: left;
  font-size: var(--fs-md);
  font-weight: 600;
  color: var(--color-primary);
  transition: var(--transition);
}
.help-qa-question:hover {
  background: var(--color-gray-50);
}
.help-qa-question-text {
  min-width: 0;
}
.help-qa-chevron {
  flex-shrink: 0;
  color: var(--color-gray-400);
  transition: transform var(--transition), color var(--transition);
}
.help-qa.open .help-qa-chevron {
  transform: rotate(180deg);
  color: var(--color-accent);
}

/* Smooth height animation via grid-template-rows 0fr → 1fr */
.help-qa-answer {
  display: grid;
  grid-template-rows: 0fr;
  transition: grid-template-rows 0.25s ease;
}
.help-qa.open .help-qa-answer {
  grid-template-rows: 1fr;
}
.help-qa-answer-inner {
  overflow: hidden;
  min-height: 0;
  /* Collapsed answers are taken out of the a11y tree and find-in-page, not just
     clipped; visibility flips to hidden only after the row finishes collapsing. */
  visibility: hidden;
  transition: visibility 0s linear 0.25s;
}
.help-qa.open .help-qa-answer-inner {
  padding: 0 18px 18px;
  visibility: visible;
  transition: visibility 0s;
}

.help-answer-p {
  font-size: var(--fs-md);
  color: var(--color-gray-700);
  line-height: 1.6;
}
.help-answer-p + .help-answer-p {
  margin-top: 10px;
}

.help-answer-list {
  margin: 10px 0 0;
  padding-left: 22px;
  display: flex;
  flex-direction: column;
  gap: 5px;
  font-size: var(--fs-md);
  color: var(--color-gray-700);
  line-height: 1.55;
}
.help-answer-list li {
  padding-left: 2px;
}
.help-answer-list--ordered {
  list-style: decimal;
}

/* Request-state definition list */
.help-answer-states {
  margin-top: 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.help-answer-state {
  padding: 10px 12px;
  background: var(--color-gray-50);
  border-radius: var(--radius-md);
}
.help-answer-state-label {
  font-size: var(--fs-sm);
  font-weight: 600;
  color: var(--color-primary);
}
.help-answer-state-text {
  font-size: var(--fs-sm);
  color: var(--color-gray-600);
  line-height: 1.5;
  margin-top: 2px;
}

/* CTA */
.help-cta {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 14px;
  padding: 24px;
  border-radius: var(--radius-xl);
  background:
    radial-gradient(circle at 90% 0%, rgba(59, 158, 181, 0.12) 0%, transparent 55%),
    var(--color-light);
}
@media (min-width: 640px) {
  .help-cta {
    flex-direction: row;
    align-items: center;
    gap: 18px;
  }
}
.help-cta-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 48px;
  height: 48px;
  border-radius: var(--radius-lg);
  background: var(--color-white);
  color: var(--color-accent);
}
.help-cta-body {
  flex: 1;
  min-width: 0;
}
.help-cta-title {
  font-size: var(--fs-lg);
  font-weight: 700;
  color: var(--color-primary);
}
.help-cta-desc {
  font-size: var(--fs-sm);
  color: var(--color-gray-700);
  line-height: 1.55;
  margin-top: 3px;
}
/* Mobile-first: full-width button; shrink to content once the CTA goes side-by-side */
.help-cta .btn {
  flex-shrink: 0;
  width: 100%;
  justify-content: center;
}
@media (min-width: 640px) {
  .help-cta .btn {
    width: auto;
  }
}
</style>
