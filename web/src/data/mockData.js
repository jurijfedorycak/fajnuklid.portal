// ─── AUTH / SESSION ───────────────────────────────────────────────────────────
export const currentUser = {
  email: 'info@stavby-novak.cz',
  displayName: 'Stavby Novák s.r.o.',
  clientId: 'CLI-001',
  isAdmin: false,
  icos: [
    { ico: '12345678', name: 'Stavby Novák s.r.o.', address: 'Budějovická 12, Praha 4' },
    { ico: '87654321', name: 'Novák Holding a.s.', address: 'Nádražní 5, Praha 5' },
  ],
  activeIco: '12345678',
}

export const adminUser = {
  email: 'jurij.fedorycak@fajnuklid.cz',
  displayName: 'Jurij Fedoryčak',
  clientId: 'ADMIN',
  isAdmin: true,
}

// ─── INVOICES ─────────────────────────────────────────────────────────────────
export const invoices = [
  { id: 'FV2026-042', issued: '2026-02-01', due: '2026-02-15', amount: 8400,  status: 'paid',     varSymbol: '2026042', daysRelative: -13 },
  { id: 'FV2026-031', issued: '2026-01-15', due: '2026-01-29', amount: 7200,  status: 'paid',     varSymbol: '2026031', daysRelative: -30 },
  { id: 'FV2026-018', issued: '2026-01-01', due: '2026-01-15', amount: 6800,  status: 'paid',     varSymbol: '2026018', daysRelative: -45 },
  { id: 'FV2026-055', issued: '2026-02-15', due: '2026-03-01', amount: 8400,  status: 'unpaid',   varSymbol: '2026055', daysRelative: 0 },
  { id: 'FV2026-068', issued: '2026-03-01', due: '2026-03-15', amount: 8400,  status: 'unpaid',   varSymbol: '2026068', daysRelative: 14 },
  { id: 'FV2025-198', issued: '2025-12-01', due: '2025-12-15', amount: 6600,  status: 'overdue',  varSymbol: '2025198', daysRelative: -76 },
  { id: 'FV2025-175', issued: '2025-11-01', due: '2025-11-15', amount: 6600,  status: 'paid',     varSymbol: '2025175', daysRelative: -106 },
  { id: 'FV2025-152', issued: '2025-10-01', due: '2025-10-15', amount: 6200,  status: 'paid',     varSymbol: '2025152', daysRelative: -137 },
  { id: 'FV2025-130', issued: '2025-09-01', due: '2025-09-15', amount: 6200,  status: 'paid',     varSymbol: '2025130', daysRelative: -167 },
  { id: 'FV2025-108', issued: '2025-08-01', due: '2025-08-15', amount: 6000,  status: 'paid',     varSymbol: '2025108', daysRelative: -198 },
  { id: 'FV2025-088', issued: '2025-07-01', due: '2025-07-15', amount: 6000,  status: 'paid',     varSymbol: '2025088', daysRelative: -228 },
  { id: 'FV2025-065', issued: '2025-06-01', due: '2025-06-15', amount: 5800,  status: 'paid',     varSymbol: '2025065', daysRelative: -259 },
]

// ─── PERSONNEL (grouped by IČO → object/provozovna) ──────────────────────────
export const personnelByLocation = [
  {
    ico: '12345678',
    icoName: 'Stavby Novák s.r.o.',
    objects: [
      {
        id: 'obj-1',
        name: 'Kanceláře',
        address: 'Budějovická 12, Praha 4',
        staff: [
          {
            id: 1,
            name: 'Katarína Horáková',
            role: 'Vedoucí týmu',
            tenure: '3 roky',
            bio: 'Kateřina se stará o koordinaci úklidu ve vašich prostorách. Je spolehlivá, precizní a vždy na místě včas.',
            hobbies: 'Zahradničení, vaření, jóga',
            phone: null,
            showPhoto: false, showPhone: false, showRole: true,
            showHobbies: true, showTenure: true, showBio: true,
          },
          {
            id: 2,
            name: 'Dmytro Kovalenko',
            role: 'Úklidový pracovník',
            tenure: '1 rok a 4 měsíce',
            bio: 'Dmytro je pečlivý a pracovitý. Specializuje se na hluboké čištění a strojové mytí podlah.',
            hobbies: 'Fotbal, cyklistika',
            phone: '+420 702 111 222',
            showPhoto: false, showPhone: true, showRole: true,
            showHobbies: true, showTenure: true, showBio: true,
          },
        ],
      },
      {
        id: 'obj-2',
        name: 'Sklad',
        address: 'Průmyslová 5, Praha 4',
        staff: [
          {
            id: 3,
            name: 'Monika Blahová',
            role: 'Úklidová pracovnice',
            tenure: '2 roky',
            bio: null,
            hobbies: null,
            phone: null,
            showPhoto: false, showPhone: false, showRole: true,
            showHobbies: false, showTenure: true, showBio: false,
          },
          {
            id: 4,
            name: 'Andrij Melnyk',
            role: 'Pomocný pracovník',
            tenure: '8 měsíců',
            bio: 'Andrij je novým členem našeho týmu. Rychle se učí a plně se zapojil do práce.',
            hobbies: 'Hudba, filmový nadšenec',
            phone: null,
            showPhoto: false, showPhone: false, showRole: true,
            showHobbies: true, showTenure: true, showBio: true,
          },
        ],
      },
    ],
  },
  {
    ico: '87654321',
    icoName: 'Novák Holding a.s.',
    objects: [
      {
        id: 'obj-3',
        name: 'Recepce a vedení',
        address: 'Nádražní 5, Praha 5',
        staff: [
          {
            id: 5,
            name: 'Oksana Petrenko',
            role: 'Vedoucí týmu',
            tenure: '2 roky a 1 měsíc',
            bio: 'Oksana zajišťuje každodenní úklid recepce a kancelářských prostor. Je komunikativní a spolehlivá.',
            hobbies: 'Tenis, cestování',
            phone: null,
            showPhoto: false, showPhone: false, showRole: true,
            showHobbies: true, showTenure: true, showBio: true,
          },
          {
            id: 6,
            name: 'Taras Bondarenko',
            role: 'Úklidový pracovník',
            tenure: '5 měsíců',
            bio: null,
            hobbies: null,
            phone: null,
            showPhoto: false, showPhone: false, showRole: true,
            showHobbies: false, showTenure: true, showBio: false,
          },
        ],
      },
    ],
  },
]

// Flat list for backward compat (dashboard uses this for total count)
export const personnel = personnelByLocation.flatMap(g => g.objects.flatMap(o => o.staff))

// ─── CONTRACT ─────────────────────────────────────────────────────────────────
export const contract = {
  contractsEnabled: true,
  hasPdf: true,
  filename: 'Smlouva_StavbyNovak_2024.pdf',
  uploadedAt: '2024-03-15',
}

// ─── CONTACT ─────────────────────────────────────────────────────────────────
export const contacts = [
  {
    name: 'Jurij Fedoryčak',
    role: 'Jednatel',
    phone: '+420 773 023 608',
    email: 'jurij.fedorycak@fajnuklid.cz',
  },
  {
    name: 'Olena Fedorychak',
    role: 'Vaše úklidová manažerka',
    phone: '+420 608 045 256',
    email: 'vaseuklidovka@fajnuklid.cz',
  },
]

export const companies = [
  {
    name: 'FAJN ÚKLID PRAHA s.r.o.',
    address: 'Bellušova 1854/24, 155 00 Praha 5',
    ico: '08999457',
    dic: 'CZ08999457',
    registration: 'Zapsaná v obchodním rejstříku vedeném Městským soudem v Praze, oddíl C, vložka 328945',
  },
  {
    name: 'Fajn Facility Management s.r.o.',
    address: 'Bellušova 1854/24, Stodůlky, 155 00 Praha 5',
    ico: '21328331',
    dic: '',
    registration: 'Zapsaná v obchodním rejstříku vedeném u Městského soudu v Praze, oddíl C, vložka 328945',
  },
]

// ─── ADMIN – all clients ──────────────────────────────────────────────────────
export const adminClients = [
  {
    clientId: 'CLI-001',
    email: 'info@stavby-novak.cz',
    displayName: 'Stavby Novák s.r.o.',
    icos: ['12345678', '87654321'],
    active: true,
    lastLogin: '2026-02-28',
  },
  {
    clientId: 'CLI-002',
    email: 'uklidovka@restaurace-prazska.cz',
    displayName: 'Restaurace Pražská',
    icos: ['55566677'],
    active: true,
    lastLogin: '2026-02-25',
  },
  {
    clientId: 'CLI-003',
    email: 'sprava@bytdom-vinohrady.cz',
    displayName: 'Bytový dům Vinohrady',
    icos: ['33344455'],
    active: true,
    lastLogin: '2026-02-20',
  },
  {
    clientId: 'CLI-004',
    email: 'office@mediflex.cz',
    displayName: 'Mediflex s.r.o.',
    icos: ['11122233'],
    active: false,
    lastLogin: '2025-11-10',
  },
  {
    clientId: 'CLI-005',
    email: 'info@logistika-benes.cz',
    displayName: 'Logistika Beneš a.s.',
    icos: ['99988877', '44455566'],
    active: true,
    lastLogin: '2026-02-27',
  },
]

// ─── ADMIN – employees (Fajn Úklid staff) ───────────────────────────────────
export const adminEmployees = [
  {
    id: 'emp-1',
    firstName: 'Katarína',
    lastName: 'Horáková',
    role: 'Vedoucí týmu',
    phone: '+420 608 111 222',
    tenureText: '3 roky',
    bio: 'Kateřina se stará o koordinaci úklidu ve vašich prostorách. Je spolehlivá, precizní a vždy na místě včas.',
    hobbies: 'Zahradničení, vaření, jóga',
    photo: null,
    contractFile: 'DPP_Horakova_2024.pdf',
    showInPortal: true,
    showPhoto: false,
    showPhone: false,
    showRole: true,
    showHobbies: true,
    showTenure: true,
    showBio: true,
  },
  {
    id: 'emp-2',
    firstName: 'Dmytro',
    lastName: 'Kovalenko',
    role: 'Úklidový pracovník',
    phone: '+420 702 111 222',
    tenureText: '1 rok a 4 měsíce',
    bio: 'Dmytro je pečlivý a pracovitý. Specializuje se na hluboké čištění a strojové mytí podlah.',
    hobbies: 'Fotbal, cyklistika',
    photo: null,
    contractFile: 'HPP_Kovalenko_2025.pdf',
    showInPortal: true,
    showPhoto: false,
    showPhone: true,
    showRole: true,
    showHobbies: true,
    showTenure: true,
    showBio: true,
  },
  {
    id: 'emp-3',
    firstName: 'Monika',
    lastName: 'Blahová',
    role: 'Úklidová pracovnice',
    phone: '',
    tenureText: '2 roky',
    bio: '',
    hobbies: '',
    photo: null,
    contractFile: null,
    showInPortal: true,
    showPhoto: false,
    showPhone: false,
    showRole: true,
    showHobbies: false,
    showTenure: true,
    showBio: false,
  },
  {
    id: 'emp-4',
    firstName: 'Andrij',
    lastName: 'Melnyk',
    role: 'Pomocný pracovník',
    phone: '',
    tenureText: '8 měsíců',
    bio: 'Andrij je novým členem našeho týmu. Rychle se učí a plně se zapojil do práce.',
    hobbies: 'Hudba, filmový nadšenec',
    photo: null,
    contractFile: 'DPC_Melnyk_2025.pdf',
    showInPortal: true,
    showPhoto: false,
    showPhone: false,
    showRole: true,
    showHobbies: true,
    showTenure: true,
    showBio: true,
  },
  {
    id: 'emp-5',
    firstName: 'Oksana',
    lastName: 'Petrenko',
    role: 'Vedoucí týmu',
    phone: '+420 773 555 666',
    tenureText: '2 roky a 1 měsíc',
    bio: 'Oksana zajišťuje každodenní úklid recepce a kancelářských prostor. Je komunikativní a spolehlivá.',
    hobbies: 'Tenis, cestování',
    photo: null,
    contractFile: 'HPP_Petrenko_2024.pdf',
    showInPortal: true,
    showPhoto: false,
    showPhone: false,
    showRole: true,
    showHobbies: true,
    showTenure: true,
    showBio: true,
  },
  {
    id: 'emp-6',
    firstName: 'Taras',
    lastName: 'Bondarenko',
    role: 'Úklidový pracovník',
    phone: '',
    tenureText: '5 měsíců',
    bio: '',
    hobbies: '',
    photo: null,
    contractFile: null,
    showInPortal: false,
    showPhoto: false,
    showPhone: false,
    showRole: true,
    showHobbies: false,
    showTenure: true,
    showBio: false,
  },
  {
    id: 'emp-7',
    firstName: 'Iveta',
    lastName: 'Procházková',
    role: 'Úklidová pracovnice',
    phone: '+420 604 333 444',
    tenureText: '4 roky',
    bio: 'Iveta je naše nejzkušenější pracovnice. Zvládá i náročné prostory a vždy odvede skvělou práci.',
    hobbies: 'Čtení, zahradničení',
    photo: null,
    contractFile: 'HPP_Prochazkova_2022.pdf',
    showInPortal: true,
    showPhoto: false,
    showPhone: false,
    showRole: true,
    showHobbies: true,
    showTenure: true,
    showBio: true,
  },
]

// ─── ATTENDANCE (calendar mockup) ────────────────────────────────────────────
export const freshqrActive = true

// Cleaning days — only dates and an optional public note (no names, no times)
export const cleaningDays = [
  // January 2026
  { date: '2026-01-03', note: '' },
  { date: '2026-01-06', note: '' },
  { date: '2026-01-08', note: '' },
  { date: '2026-01-10', note: '' },
  { date: '2026-01-13', note: '' },
  { date: '2026-01-15', note: '' },
  { date: '2026-01-17', note: '' },
  { date: '2026-01-20', note: '' },
  { date: '2026-01-22', note: '' },
  { date: '2026-01-24', note: '' },
  { date: '2026-01-27', note: '' },
  { date: '2026-01-29', note: 'Hloubkový úklid' },
  { date: '2026-01-31', note: '' },

  // February 2026
  { date: '2026-02-03', note: '' },
  { date: '2026-02-05', note: '' },
  { date: '2026-02-07', note: '' },
  { date: '2026-02-10', note: '' },
  { date: '2026-02-12', note: '' },
  { date: '2026-02-14', note: '' },
  { date: '2026-02-17', note: '' },
  { date: '2026-02-19', note: '' },
  { date: '2026-02-21', note: '' },
  { date: '2026-02-24', note: '' },
  { date: '2026-02-26', note: '' },
  { date: '2026-02-28', note: 'Strojové čištění podlah' },

  // March 2026 (today = 2026-03-01 is ongoing)
  { date: '2026-03-01', note: '', ongoing: true },
]
