import React, { useCallback, useEffect, useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import { ServerContext } from '@/state/server';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import { Button } from '@/components/elements/button/index';
import Input from '@/components/elements/Input';
import http from '@/api/http';
import FlashMessageRender from '@/components/FlashMessageRender';
import useFlash from '@/plugins/useFlash';

type LogSource = { id: string; label: string; exists?: boolean; size?: number | null };

type LogEntry = {
    time?: string;
    type?: string;
    ip?: string;
    code?: string;
    message?: string;
    size_label?: string;
    referer?: string;
    ua?: string;
    source?: string;
    detail?: string;
    raw?: string;
};

type LogsResponse = {
    sources?: LogSource[];
    entries?: LogEntry[];
    note?: string;
    count?: number;
};

const GRID =
    '150px 70px 120px 72px minmax(180px, 1fr) 70px 110px';

const Toolbar = styled.div`
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;

    button {
        height: 40px;
    }

    select {
        height: 40px;
        min-width: 180px;
        background: #3f4a5a;
        border: 1px solid #4b5563;
        border-radius: 4px;
        color: #f3f4f6;
        padding: 0 10px;
        font-size: 14px;
    }
`;

const FilterRow = styled.div`
    display: grid;
    gap: 8px;
    margin-bottom: 16px;
    grid-template-columns: 1fr;

    @media (min-width: 960px) {
        grid-template-columns: 1.1fr 1fr 0.7fr 1.6fr 120px;
    }

    input,
    button {
        height: 40px !important;
        width: 100%;
    }
`;

const Sheet = styled.div`
    width: 100%;
    overflow-x: auto;
    border: 1px solid #374151;
    border-radius: 6px;
    background: rgba(30, 41, 59, 0.55);
`;

const Head = styled.div`
    display: grid;
    grid-template-columns: ${GRID};
    gap: 0;
    min-width: 900px;
    background: #1f2937;
    color: #d1d5db;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border-bottom: 1px solid #374151;

    > div {
        padding: 10px 12px;
    }
`;

const Row = styled.button<{ $bad?: boolean }>`
    display: grid;
    grid-template-columns: ${GRID};
    gap: 0;
    min-width: 900px;
    width: 100%;
    text-align: left;
    border: 0;
    border-top: 1px solid #374151;
    background: ${(p) => (p.$bad ? 'rgba(127, 29, 29, 0.28)' : 'transparent')};
    color: ${(p) => (p.$bad ? '#fecaca' : '#e5e7eb')};
    cursor: pointer;
    font-size: 13px;
    padding: 0;

    &:hover {
        background: ${(p) => (p.$bad ? 'rgba(127, 29, 29, 0.38)' : 'rgba(55, 65, 81, 0.45)')};
    }

    > div {
        padding: 10px 12px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        min-width: 0;
    }

    .mono {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-size: 12px;
    }
`;

const Detail = styled.div`
    min-width: 900px;
    border-top: 1px solid #374151;
    background: #0b1220;
    padding: 12px;
    color: #e5e7eb;
    font-size: 12px;

    pre {
        margin: 8px 0 0;
        padding: 12px;
        max-height: 280px;
        overflow: auto;
        white-space: pre-wrap;
        word-break: break-word;
        background: rgba(0, 0, 0, 0.45);
        border-radius: 4px;
    }
`;

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const [logSource, setLogSource] = useState('all');
    const [filterIp, setFilterIp] = useState('');
    const [filterCode, setFilterCode] = useState('');
    const [filterQ, setFilterQ] = useState('');
    const [filterType, setFilterType] = useState('');
    const [live, setLive] = useState(false);
    const [loading, setLoading] = useState(true);
    const [open, setOpen] = useState<number | null>(null);
    const [page, setPage] = useState(1);
    const [data, setData] = useState<LogsResponse | null>(null);
    const PAGE_SIZE = 25;

    const load = useCallback(() => {
        setLoading(true);
        clearFlashes('prm-hosting-logs');
        http
            .get<LogsResponse>(`/api/client/extensions/prmhosting/servers/${uuid}/hosting/logs`, {
                params: {
                    source: logSource || 'all',
                    ip: filterIp || undefined,
                    code: filterCode || undefined,
                    q: filterQ || undefined,
                    type: filterType || undefined,
                    limit: 500,
                },
            })
            .then(({ data: payload }) => {
                setData(payload);
                setPage(1);
                setOpen(null);
            })
            .catch((error) => clearAndAddHttpError({ key: 'prm-hosting-logs', error }))
            .finally(() => setLoading(false));
    }, [uuid, logSource, filterIp, filterCode, filterQ, filterType, clearFlashes, clearAndAddHttpError]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (!live) return;
        const t = setInterval(load, 4000);
        return () => clearInterval(t);
    }, [live, load]);

    const entries = data?.entries || [];
    const sources = data?.sources || [];
    const total = entries.length;
    const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    const safePage = Math.min(page, pages);
    const start = (safePage - 1) * PAGE_SIZE;
    const slice = entries.slice(start, start + PAGE_SIZE);
    const from = total === 0 ? 0 : start + 1;
    const to = Math.min(start + PAGE_SIZE, total);

    return (
        <ServerContentBlock title={'Günlükler'} css={tw`max-w-full`}>
            <FlashMessageRender byKey={'prm-hosting-logs'} css={tw`mb-4`} />
            <p css={tw`text-sm text-neutral-300 mb-4`}>
                Nginx erişim/hata, PHP-FPM ve Laravel (varsa). Kaynak: <code>/logs</code> ve{' '}
                <code>/www/storage/logs</code>.
            </p>

            <Toolbar>
                <Button.Text onClick={() => setLive((v) => !v)} css={live ? tw`text-green-400` : undefined}>
                    {live ? 'Canlı açık' : 'Gerçek zamanlı başlat'}
                </Button.Text>
                <Button onClick={() => load()} disabled={loading}>
                    Yenile
                </Button>
                <select value={logSource} onChange={(e) => setLogSource(e.target.value)}>
                    <option value={'all'}>Tüm günlükler</option>
                    {sources.map((s) => (
                        <option key={s.id} value={s.id} disabled={s.exists === false}>
                            {s.label}
                            {s.exists === false ? ' (yok)' : ''}
                        </option>
                    ))}
                </select>
                {data?.note ? <span css={tw`text-xs text-neutral-400`}>{data.note}</span> : null}
            </Toolbar>

            <FilterRow>
                <Input
                    value={filterType}
                    onChange={(e) => setFilterType(e.target.value)}
                    placeholder={'Tür (access/error/app)'}
                />
                <Input value={filterIp} onChange={(e) => setFilterIp(e.target.value)} placeholder={'IP'} />
                <Input value={filterCode} onChange={(e) => setFilterCode(e.target.value)} placeholder={'Kod'} />
                <Input value={filterQ} onChange={(e) => setFilterQ(e.target.value)} placeholder={'Mesaj'} />
                <Button onClick={() => load()} disabled={loading}>
                    Filtrele
                </Button>
            </FilterRow>

            {loading && !entries.length ? (
                <Spinner centered />
            ) : (
                <Sheet>
                    <Head>
                        <div>Tarih</div>
                        <div>Tür</div>
                        <div>IP</div>
                        <div>Kod</div>
                        <div>Mesaj</div>
                        <div>Boyut</div>
                        <div>Kaynak</div>
                    </Head>
                    {slice.map((row, i) => {
                        const abs = start + i;
                        const bad =
                            row.type === 'error' ||
                            (!!row.code && /^\d+$/.test(row.code) && Number(row.code) >= 400) ||
                            ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'].includes(
                                String(row.code || '').toUpperCase()
                            );
                        const isOpen = open === abs;
                        return (
                            <React.Fragment key={abs}>
                                <Row
                                    type={'button'}
                                    $bad={bad}
                                    onClick={() => setOpen(isOpen ? null : abs)}
                                    title={row.message || ''}
                                >
                                    <div>{row.time || '—'}</div>
                                    <div>{row.type || '—'}</div>
                                    <div className={'mono'}>{row.ip || '—'}</div>
                                    <div style={{ fontWeight: 700 }}>{row.code || '—'}</div>
                                    <div className={'mono'}>{row.message || '—'}</div>
                                    <div>{row.size_label || '—'}</div>
                                    <div style={{ color: '#9ca3af' }}>{row.source || '—'}</div>
                                </Row>
                                {isOpen && (row.detail || row.ua || row.referer || row.raw) ? (
                                    <Detail>
                                        {row.referer ? (
                                            <div>
                                                <strong>Referer:</strong> {row.referer}
                                            </div>
                                        ) : null}
                                        {row.ua ? (
                                            <div>
                                                <strong>User-Agent:</strong> {row.ua}
                                            </div>
                                        ) : null}
                                        <pre>{row.detail || row.raw || ''}</pre>
                                    </Detail>
                                ) : null}
                            </React.Fragment>
                        );
                    })}
                    {!total ? (
                        <div css={tw`px-3 py-6 text-neutral-400 text-center`}>
                            Kayıt yok — site çalışıyor mu / log dosyası oluştu mu kontrol et.
                        </div>
                    ) : null}
                </Sheet>
            )}

            {total > 0 ? (
                <div css={tw`flex flex-wrap items-center justify-between gap-2 mt-3`}>
                    <span css={tw`text-xs text-neutral-400`}>
                        {from}–{to} / {total} · Sayfa {safePage}/{pages} (25 kayıt)
                    </span>
                    <div css={tw`flex gap-1`}>
                        <Button.Text
                            disabled={safePage <= 1}
                            onClick={() => {
                                setOpen(null);
                                setPage((p) => Math.max(1, p - 1));
                            }}
                        >
                            Önceki
                        </Button.Text>
                        <Button.Text
                            disabled={safePage >= pages}
                            onClick={() => {
                                setOpen(null);
                                setPage((p) => Math.min(pages, p + 1));
                            }}
                        >
                            Sonraki
                        </Button.Text>
                    </div>
                </div>
            ) : null}
        </ServerContentBlock>
    );
};
