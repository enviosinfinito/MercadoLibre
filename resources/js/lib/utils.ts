import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export type { ClassValue };

export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}

const dateTimeFormatter = new Intl.DateTimeFormat('es-MX', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

/**
 * Fecha/hora legible en es-MX (p. ej. "7 ago 2026, 10:44 a.m.").
 */
export function formatDateTime(value: string | Date | null | undefined): string {
    if (value == null || value === '') {
        return '—';
    }

    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return dateTimeFormatter.format(date);
}

const MONTHS_ES = [
    'enero',
    'febrero',
    'marzo',
    'abril',
    'mayo',
    'junio',
    'julio',
    'agosto',
    'septiembre',
    'octubre',
    'noviembre',
    'diciembre',
] as const;

function startOfLocalDay(date: Date): number {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
}

function formatClock(date: Date): string {
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${hours}:${minutes}`;
}

/**
 * Fecha/hora amigable en español (p. ej. "Hoy a las 16:49", "Ayer a las 09:05", "21 de agosto a las 14:30").
 */
export function formatFriendlyDateTime(value: string | Date | null | undefined): string {
    if (value == null || value === '') {
        return '—';
    }

    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    const now = new Date();
    const dayDiff = Math.round((startOfLocalDay(date) - startOfLocalDay(now)) / 86_400_000);
    const time = formatClock(date);

    if (dayDiff === 0) {
        return `Hoy a las ${time}`;
    }

    if (dayDiff === -1) {
        return `Ayer a las ${time}`;
    }

    const day = date.getDate();
    const month = MONTHS_ES[date.getMonth()];
    const yearSuffix = date.getFullYear() !== now.getFullYear() ? ` de ${date.getFullYear()}` : '';

    return `${day} de ${month}${yearSuffix} a las ${time}`;
}

/**
 * Relativo corto en español (p. ej. "hace 2 h", "hace 3 d").
 */
export function formatRelativeShort(value: string | Date | null | undefined): string {
    if (value == null || value === '') {
        return '';
    }

    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const diffMs = date.getTime() - Date.now();
    const absMs = Math.abs(diffMs);
    const past = diffMs <= 0;

    const minute = 60_000;
    const hour = 60 * minute;
    const day = 24 * hour;

    let amount: number;
    let unit: string;

    if (absMs < minute) {
        return past ? 'ahora' : 'en un momento';
    }

    if (absMs < hour) {
        amount = Math.round(absMs / minute);
        unit = 'min';
    } else if (absMs < day) {
        amount = Math.round(absMs / hour);
        unit = 'h';
    } else if (absMs < 30 * day) {
        amount = Math.round(absMs / day);
        unit = 'd';
    } else {
        amount = Math.round(absMs / (30 * day));
        unit = 'mes';
        if (amount !== 1) {
            unit = 'meses';
        }
    }

    if (unit === 'mes') {
        return past ? `hace 1 mes` : `en 1 mes`;
    }

    return past ? `hace ${amount} ${unit}` : `en ${amount} ${unit}`;
}

/**
 * Duración corta entre dos timestamps (p. ej. "12 min", "2 h", "3 d").
 * Vacío si faltan fechas o answered está antes de asked.
 */
export function formatElapsedDuration(
    from: string | Date | null | undefined,
    to: string | Date | null | undefined,
): string {
    if (from == null || from === '' || to == null || to === '') {
        return '';
    }

    const start = from instanceof Date ? from : new Date(from);
    const end = to instanceof Date ? to : new Date(to);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
        return '';
    }

    const diffMs = end.getTime() - start.getTime();
    if (diffMs < 0) {
        return '';
    }

    const minute = 60_000;
    const hour = 60 * minute;
    const day = 24 * hour;

    if (diffMs < minute) {
        return '< 1 min';
    }
    if (diffMs < hour) {
        return `${Math.round(diffMs / minute)} min`;
    }
    if (diffMs < day) {
        return `${Math.round(diffMs / hour)} h`;
    }
    if (diffMs < 30 * day) {
        return `${Math.round(diffMs / day)} d`;
    }

    const months = Math.round(diffMs / (30 * day));
    return months === 1 ? '1 mes' : `${months} meses`;
}
