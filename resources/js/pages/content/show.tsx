import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, FileText } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { usePortal, useTranslations } from '@/hooks/use-portal';
import { PublicLayout } from '@/layouts/public-layout';
import { formatDateTime } from '@/lib/datetime';

interface ContentItem {
    slug: string;
    type: 'page' | 'news' | 'bulletin' | 'health_advice';
    title: string;
    summary: string | null;
    body: string;
    publishedAt: string | null;
}

interface ContentShowProps {
    content: ContentItem;
}

export default function ContentShow({ content }: ContentShowProps) {
    const { locale, displayTimezone } = usePortal();
    const t = useTranslations();

    return (
        <PublicLayout>
            <Head title={`${content.title} — ${t('brand_name')}`} />

            <Button asChild variant="ghost" className="mb-5 -ml-2">
                <Link href="/">
                    <ArrowLeft aria-hidden />
                    {t('content_back')}
                </Link>
            </Button>

            <article className="space-y-7">
                <header className="space-y-4">
                    <Badge variant="secondary">
                        <FileText aria-hidden />
                        {t(`content_type_${content.type}`)}
                    </Badge>
                    <h1 className="font-heading text-3xl font-semibold text-balance sm:text-5xl">
                        {content.title}
                    </h1>
                    {content.summary && (
                        <p className="max-w-3xl text-lg leading-8 text-muted-foreground">
                            {content.summary}
                        </p>
                    )}
                    {content.publishedAt && (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <CalendarDays className="size-4" aria-hidden />
                            {t('content_published_at')}:{' '}
                            <time dateTime={content.publishedAt}>
                                {formatDateTime(
                                    content.publishedAt,
                                    locale.current,
                                    displayTimezone,
                                )}
                            </time>
                        </p>
                    )}
                </header>

                <Card>
                    <CardContent className="pt-6">
                        <div className="text-base leading-8 whitespace-pre-wrap">
                            {content.body}
                        </div>
                    </CardContent>
                </Card>
            </article>
        </PublicLayout>
    );
}
