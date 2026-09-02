export type ListDateTime = {
    dateTime: string | null;
    label: string;
    time: string;
};

const timeFormatter = new Intl.DateTimeFormat('en-GB', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
});

const dateFormatter = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

const currentYearDateFormatter = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
});

export function formatListDateTime(value?: string | Date | null, now = new Date()): ListDateTime {
    if (!value) return { dateTime: null, label: 'Unknown', time: '' };

    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) return { dateTime: null, label: 'Unknown', time: '' };

    const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const startYesterday = new Date(startToday);
    startYesterday.setDate(startToday.getDate() - 1);

    let label: string;
    if (date >= startToday && date <= now) {
        const elapsedMinutes = Math.floor((now.getTime() - date.getTime()) / 60_000);

        if (elapsedMinutes < 1) {
            label = 'Just now';
        } else if (elapsedMinutes < 60) {
            label = `${elapsedMinutes} ${elapsedMinutes === 1 ? 'minute' : 'minutes'} ago`;
        } else {
            label = 'Today';
        }
    } else if (date >= startYesterday && date < startToday) {
        label = 'Yesterday';
    } else {
        label = date.getFullYear() === now.getFullYear() ? currentYearDateFormatter.format(date) : dateFormatter.format(date);
    }

    return {
        dateTime: date.toISOString(),
        label,
        time: timeFormatter.format(date),
    };
}
