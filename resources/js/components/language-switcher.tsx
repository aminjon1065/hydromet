import { Link } from '@inertiajs/react';
import { Check, Languages } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePortal, useTranslations } from '@/hooks/use-portal';

export function LanguageSwitcher() {
    const { locale } = usePortal();
    const t = useTranslations();

    const active = locale.available.find((option) => option.value === locale.current);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" aria-label={t('language_label')}>
                    <Languages aria-hidden="true" />
                    {active?.label ?? locale.current}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuLabel>{t('language_label')}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {locale.available.map((option) => (
                    <DropdownMenuItem key={option.value} asChild>
                        <Link
                            href={`/language/${option.value}`}
                            lang={option.value}
                            aria-current={option.value === locale.current ? 'true' : undefined}
                        >
                            {option.label}
                            {option.value === locale.current ? (
                                <Check className="ml-auto" aria-hidden="true" />
                            ) : null}
                        </Link>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
