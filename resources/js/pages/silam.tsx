import { Head } from '@inertiajs/react';
import { ExternalLink, Wind } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useTranslations } from '@/hooks/use-portal';
import { PublicLayout } from '@/layouts/public-layout';

interface SilamProps {
    silamUrl: string;
}

export default function Silam({ silamUrl }: SilamProps) {
    const t = useTranslations();

    return (
        <PublicLayout>
            <Head title={t('silam_heading')} />

            <section className="space-y-4">
                <Badge variant="secondary">
                    <Wind aria-hidden />
                    {t('silam_forecast_badge')}
                </Badge>
                <h1 className="font-heading text-3xl font-semibold text-balance sm:text-4xl">
                    {t('silam_heading')}
                </h1>
                <p className="max-w-3xl text-base text-muted-foreground">{t('silam_intro')}</p>
                <Button asChild variant="outline">
                    <a href={silamUrl} target="_blank" rel="noreferrer">
                        {t('silam_open_external')}
                        <ExternalLink aria-hidden />
                    </a>
                </Button>
            </section>

            <section className="mt-8" aria-label={t('silam_frame_region')}>
                <Card className="p-0">
                    <CardContent className="p-0">
                        <iframe
                            className="h-[75vh] min-h-[36rem] w-full border-0"
                            src={silamUrl}
                            title={t('silam_frame_title')}
                            loading="lazy"
                            referrerPolicy="no-referrer"
                            sandbox="allow-forms allow-popups allow-same-origin allow-scripts"
                            allow="fullscreen"
                            allowFullScreen
                        />
                    </CardContent>
                </Card>
                <p className="mt-3 text-sm text-muted-foreground">{t('silam_fallback')}</p>
            </section>
        </PublicLayout>
    );
}
