import { Head } from '@inertiajs/react';
import { AlertTriangle, LineChart, MapPin, Wind } from 'lucide-react';
import type { ComponentType } from 'react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { usePortal, useTranslations } from '@/hooks/use-portal';
import { PublicLayout } from '@/layouts/public-layout';
import { formatDateTime } from '@/lib/datetime';

interface HomeProps {
    generatedAt: string;
}

interface RoadmapItem {
    key: string;
    icon: ComponentType<{ className?: string; 'aria-hidden'?: boolean }>;
}

const ROADMAP: RoadmapItem[] = [
    { key: 'roadmap_map', icon: MapPin },
    { key: 'roadmap_charts', icon: LineChart },
    { key: 'roadmap_alerts', icon: AlertTriangle },
    { key: 'roadmap_silam', icon: Wind },
];

export default function Home({ generatedAt }: HomeProps) {
    const { locale, displayTimezone } = usePortal();
    const t = useTranslations();

    return (
        <PublicLayout>
            <Head title={t('home_heading')} />

            <section className="space-y-4">
                <Badge variant="secondary">{t('home_foundation_badge')}</Badge>
                <h1 className="font-heading text-3xl font-semibold text-balance sm:text-4xl">
                    {t('home_heading')}
                </h1>
                <p className="max-w-3xl text-base text-muted-foreground">{t('home_intro')}</p>
            </section>

            <section className="mt-10 space-y-4">
                <div>
                    <h2 className="font-heading text-xl font-semibold">
                        {t('home_roadmap_heading')}
                    </h2>
                    <p className="text-sm text-muted-foreground">{t('home_roadmap_intro')}</p>
                </div>

                <ul className="grid gap-4 sm:grid-cols-2">
                    {ROADMAP.map(({ key, icon: Icon }) => (
                        <li key={key}>
                            <Card className="h-full">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Icon className="size-4 shrink-0" aria-hidden />
                                        {t(key)}
                                    </CardTitle>
                                    <CardDescription>
                                        <Badge variant="outline">{t('roadmap_status')}</Badge>
                                    </CardDescription>
                                </CardHeader>
                            </Card>
                        </li>
                    ))}
                </ul>
            </section>

            <section className="mt-10">
                <Card>
                    <CardContent className="space-y-1 text-sm text-muted-foreground">
                        <p>{t('time_timezone_notice', { timezone: displayTimezone })}</p>
                        <p>
                            {t('time_generated_at')}:{' '}
                            <time dateTime={generatedAt}>
                                {formatDateTime(generatedAt, locale.current, displayTimezone)}
                            </time>
                        </p>
                    </CardContent>
                </Card>
            </section>
        </PublicLayout>
    );
}
