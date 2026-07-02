import { computed } from 'vue'
import {
  LayoutDashboard, FileText, Users, FileSignature,
  Clock, Phone, Settings, UserCog, Palette, ClipboardList,
} from 'lucide-vue-next'
import { useAuth } from '../stores/auth'

// Single source of truth for the primary navigation, shared by the desktop
// sidebar drawer and the mobile floating bottom bar. Route-specific badges
// (e.g. the admin open-requests count) are injected by the consuming component.
export function useNavItems() {
  const { isAdmin, attendanceEnabled } = useAuth()

  // Docházka is gated on the client's FreshQR activation — clients with no
  // activated QR system never see the attendance tab (see attendanceEnabled).
  const clientNavItems = computed(() => [
    { name: 'Přehled',      route: '/prehled',   icon: LayoutDashboard },
    ...(attendanceEnabled.value ? [{ name: 'Docházka a záznamy', route: '/dochazka', icon: Clock }] : []),
    { name: 'Požadavky a reklamace', route: '/zadosti', icon: ClipboardList },
    { name: 'Smlouvy a dokumenty', route: '/smlouva', icon: FileSignature },
    { name: 'Personál',     route: '/personal',  icon: Users },
    { name: 'Fakturace',    route: '/faktury',   icon: FileText },
    { name: 'Kontakty',     route: '/kontakt',   icon: Phone },
  ])

  const adminNavItems = computed(() => [
    { name: 'Klienti',      route: '/admin/clients',        icon: Users },
    { name: 'Zaměstnanci',  route: '/admin/employees',      icon: UserCog },
    { name: 'Tým Fajn',     route: '/admin/staff-contacts', icon: Phone },
    { name: 'Žádosti',      route: '/admin/zadosti',        icon: ClipboardList },
    { name: 'Design',       route: '/admin/design-tokens',  icon: Palette },
    { name: 'Nastavení',    route: '/admin/nastaveni',      icon: Settings },
  ])

  const navItems = computed(() => isAdmin.value ? adminNavItems.value : clientNavItems.value)

  return { clientNavItems, adminNavItems, navItems }
}
