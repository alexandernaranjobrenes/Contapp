import {
    BookOpenIcon, BotIcon, HandshakeIcon, LandmarkIcon, PackageIcon, PercentIcon, ReceiptIcon, TagsIcon, UsersIcon,
} from '@lucide/vue';

/**
 * Las secciones del menú con el nombre y el ícono de AppLayout.vue: agrupan
 * el catálogo del Panel y ponen el ícono de cada elemento.
 */
export const SECTIONS = {
    accounting: { label: 'Contabilidad', icon: BookOpenIcon },
    cost_fx: { label: 'Centros de costo y cambiario', icon: TagsIcon },
    inventory: { label: 'Inventario', icon: PackageIcon },
    billing: { label: 'Facturación', icon: ReceiptIcon },
    payroll: { label: 'Planillas', icon: UsersIcon },
    business_partners: { label: 'Socios de negocio', icon: HandshakeIcon },
    banking: { label: 'Bancos', icon: LandmarkIcon },
    tax: { label: 'Impuestos', icon: PercentIcon },
    conti: { label: 'Conti', icon: BotIcon },
};

export const SIZES = {
    small: 'Pequeño: una columna',
    medium: 'Mediano: dos columnas',
    large: 'Ancho: toda la fila',
};
