import { usePage } from '@inertiajs/react';
import { CheckCircle, XCircle, Info, X } from 'lucide-react';
import { useEffect, useState } from 'react';

type FlashType = 'success' | 'error' | 'info' | null;

export default function FlashMessages() {
    const { flash } = usePage<any>().props;
    const [visible, setVisible] = useState(false);
    const [current, setCurrent] = useState<{
        type: FlashType;
        msg: string;
    } | null>(null);

    useEffect(() => {
        const type: FlashType = flash?.success
            ? 'success'
            : flash?.error
              ? 'error'
              : flash?.info
                ? 'info'
                : null;
        const msg = flash?.success ?? flash?.error ?? flash?.info ?? null;

        if (!msg) return;

        setCurrent({ type, msg });
        setVisible(true);

        const t = setTimeout(() => setVisible(false), 4500);
        return () => clearTimeout(t);
    }, [flash]);

    if (!visible || !current) return null;

    const config = {
        success: {
            icon: <CheckCircle size={16} />,
            cls: 'border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300',
        },
        error: {
            icon: <XCircle size={16} />,
            cls: 'border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300',
        },
        info: {
            icon: <Info size={16} />,
            cls: 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300',
        },
    }[current.type!];

    return (
        <div
            className={`fixed top-4 right-4 z-50 flex items-center gap-3 rounded-xl border px-4 py-3 text-sm font-medium shadow-lg transition-all duration-300 ${config.cls}`}
        >
            {config.icon}
            <span>{current.msg}</span>
            <button
                onClick={() => setVisible(false)}
                className="ml-2 opacity-50 transition-opacity hover:opacity-100"
            >
                <X size={14} />
            </button>
        </div>
    );
}
