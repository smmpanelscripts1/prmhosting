import React, { useCallback, useEffect, useMemo, useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import { Button } from '@/components/elements/button/index';
import Input from '@/components/elements/Input';
import http from '@/api/http';
import FlashMessageRender from '@/components/FlashMessageRender';
import useFlash from '@/plugins/useFlash';

type PackageRow = {
    name: string;
    version: string;
    description?: string;
    bucket?: string;
    homepage?: string;
    source?: string;
    latest?: string | null;
    outdated?: boolean;
};

type ComposerPayload = {
    enabled?: boolean;
    mode?: string;
    www_dir?: string;
    has_composer_json?: boolean;
    has_composer_lock?: boolean;
    composer_json?: Record<string, unknown> | null;
    packages?: PackageRow[];
    pending_job?: { action?: string; packages?: string[]; queued_at?: number } | null;
    last_status?: { state?: string; action?: string; detail?: string; at?: number } | null;
    note?: string;
    restart_hint?: string;
};

type Filter = 'all' | 'outdated';

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearFlashes, addFlash, clearAndAddHttpError } = useFlash();
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [data, setData] = useState<ComposerPayload | null>(null);
    const [filter, setFilter] = useState<Filter>('all');
    const [editingJson, setEditingJson] = useState(false);
    const [jsonText, setJsonText] = useState('');
    const [requirePkg, setRequirePkg] = useState('');

    const applyPayload = useCallback((payload: ComposerPayload) => {
        setData(payload);
        if (payload.composer_json) {
            setJsonText(JSON.stringify(payload.composer_json, null, 2));
        } else if (!payload.has_composer_json) {
            setJsonText('{\n    "require": {\n    }\n}\n');
        }
    }, []);

    const load = useCallback(() => {
        setLoading(true);
        clearFlashes('prm-hosting-composer');
        http
            .get<ComposerPayload>(`/api/client/extensions/prmhosting/servers/${uuid}/hosting/composer`)
            .then(({ data: payload }) => applyPayload(payload))
            .catch((error) => clearAndAddHttpError({ key: 'prm-hosting-composer', error }))
            .finally(() => setLoading(false));
    }, [uuid, clearFlashes, clearAndAddHttpError, applyPayload]);

    useEffect(() => {
        load();
    }, [load]);

    const saveSettings = (body: Record<string, unknown>, okMsg: string) => {
        setBusy(true);
        clearFlashes('prm-hosting-composer');
        http
            .put<{ ok?: boolean; composer?: ComposerPayload }>(
                `/api/client/extensions/prmhosting/servers/${uuid}/hosting/composer`,
                body
            )
            .then(({ data: res }) => {
                if (res.composer) applyPayload(res.composer);
                addFlash({ key: 'prm-hosting-composer', type: 'success', message: okMsg });
            })
            .catch((error) => clearAndAddHttpError({ key: 'prm-hosting-composer', error }))
            .finally(() => setBusy(false));
    };

    const queueJob = (action: string, packages: string[] = []) => {
        setBusy(true);
        clearFlashes('prm-hosting-composer');
        http
            .post<{ ok?: boolean; restart_hint?: string } & ComposerPayload>(
                `/api/client/extensions/prmhosting/servers/${uuid}/hosting/composer/job`,
                { action, packages }
            )
            .then(({ data: res }) => {
                applyPayload(res);
                addFlash({
                    key: 'prm-hosting-composer',
                    type: 'success',
                    message: res.restart_hint || 'İş kuyruğa alındı. Sunucuyu Restart et.',
                });
            })
            .catch((error) => clearAndAddHttpError({ key: 'prm-hosting-composer', error }))
            .finally(() => setBusy(false));
    };

    const packages = data?.packages || [];
    const outdatedCount = useMemo(() => packages.filter((p) => p.outdated).length, [packages]);
    const visible = useMemo(
        () => (filter === 'outdated' ? packages.filter((p) => p.outdated) : packages),
        [packages, filter]
    );

    const modeLabel = data?.mode === 'development' ? 'Geliştirme' : 'Üretim';
    const modeHint =
        data?.mode === 'development'
            ? 'Dev bağımlılıklar dahil kurulur.'
            : 'Üretime hazır uygulamalar için (--no-dev).';

    if (loading && !data) {
        return (
            <ServerContentBlock title={'PHP Composer'}>
                <Spinner centered />
            </ServerContentBlock>
        );
    }

    return (
        <ServerContentBlock title={'PHP Composer'}>
            <FlashMessageRender byKey={'prm-hosting-composer'} css={tw`mb-4`} />

            <div css={tw`flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4`}>
                <p css={tw`text-sm text-neutral-300 m-0`}>
                    `/www` Composer. Yükle/Güncelle Restart’ta egg ile çalışır. Klasik WordPress’te composer.json
                    olmaz.
                </p>
                <Button onClick={load} disabled={busy || loading} css={tw`flex-shrink-0`}>
                    Yeniden tara
                </Button>
            </div>

            <div css={tw`bg-neutral-700 rounded p-4 mb-4 space-y-5`}>
                <div css={tw`flex flex-col lg:flex-row lg:items-center gap-3`}>
                    <div css={tw`lg:w-56 flex-shrink-0`}>
                        <div css={tw`text-sm font-semibold text-neutral-100`}>
                            Klasör: {data?.www_dir || '/www'}
                        </div>
                    </div>
                    <div css={tw`flex-1 text-sm text-neutral-300`}>
                        {data?.has_composer_json ? 'composer.json bulundu' : 'composer.json yok'}
                        {data?.has_composer_lock ? ' · lock var' : ' · lock yok'}
                    </div>
                    <div css={tw`flex flex-wrap items-center gap-2`}>
                        <Button disabled={busy} onClick={() => setEditingJson((v) => !v)}>
                            {editingJson ? 'Düzenlemeyi kapat' : "composer.json düzenle"}
                        </Button>
                        <label css={tw`flex items-center gap-2 text-xs text-neutral-200 cursor-pointer`}>
                            <input
                                type={'checkbox'}
                                checked={!!data?.enabled}
                                disabled={busy}
                                onChange={(e) =>
                                    saveSettings(
                                        { enabled: e.target.checked },
                                        e.target.checked ? 'Composer açıldı' : 'Composer kapatıldı'
                                    )
                                }
                            />
                            COMPOSER_STATUS
                        </label>
                    </div>
                </div>

                <div css={tw`border-t border-neutral-600`} />

                <div css={tw`flex flex-col lg:flex-row lg:items-center gap-3`}>
                    <div css={tw`lg:w-56 flex-shrink-0`}>
                        <div css={tw`text-sm font-semibold text-neutral-100`}>Mod: {modeLabel}</div>
                    </div>
                    <div css={tw`flex-1 text-sm text-neutral-300`}>{modeHint}</div>
                    <div>
                        <Button
                            disabled={busy}
                            onClick={() =>
                                saveSettings(
                                    { mode: data?.mode === 'development' ? 'production' : 'development' },
                                    'Mod güncellendi'
                                )
                            }
                        >
                            Modu değiştir
                        </Button>
                    </div>
                </div>

                <div css={tw`border-t border-neutral-600`} />

                <div css={tw`flex flex-col lg:flex-row lg:items-center gap-3`}>
                    <div css={tw`lg:w-56 flex-shrink-0`}>
                        <div css={tw`text-sm font-semibold text-neutral-100`}>Paket ekle / kaldır</div>
                    </div>
                    <div css={tw`flex-1 text-sm text-neutral-300`}>
                        vendor/package veya vendor/package:^1.0 — Restart sonrası uygulanır.
                    </div>
                    <div css={tw`flex flex-wrap items-center gap-2`}>
                        <Input
                            value={requirePkg}
                            onChange={(e) => setRequirePkg(e.target.value)}
                            placeholder={'vendor/package'}
                            css={tw`w-44`}
                        />
                        <Button
                            disabled={busy || !requirePkg.trim()}
                            onClick={() => {
                                queueJob('require', [requirePkg.trim()]);
                                setRequirePkg('');
                            }}
                        >
                            Require
                        </Button>
                        <Button
                            disabled={busy || !requirePkg.trim()}
                            onClick={() => {
                                queueJob('remove', [requirePkg.trim()]);
                                setRequirePkg('');
                            }}
                        >
                            Remove
                        </Button>
                    </div>
                </div>

                {editingJson ? (
                    <div css={tw`border-t border-neutral-600 pt-4`}>
                        <label css={tw`text-xs uppercase text-neutral-400 font-semibold`}>composer.json</label>
                        <textarea
                            css={tw`mt-2 w-full bg-neutral-800 border border-neutral-600 rounded px-3 py-2 text-sm font-mono text-neutral-100 min-h-[220px]`}
                            value={jsonText}
                            onChange={(e) => setJsonText(e.target.value)}
                            disabled={busy}
                        />
                        <div css={tw`flex gap-2 mt-3`}>
                            <Button
                                disabled={busy}
                                onClick={() => {
                                    try {
                                        JSON.parse(jsonText);
                                    } catch {
                                        addFlash({
                                            key: 'prm-hosting-composer',
                                            type: 'error',
                                            message: 'Geçersiz JSON',
                                        });
                                        return;
                                    }
                                    saveSettings({ composer_json: jsonText }, 'composer.json kaydedildi');
                                    setEditingJson(false);
                                }}
                            >
                                Kaydet
                            </Button>
                            <Button disabled={busy} onClick={() => setEditingJson(false)}>
                                İptal
                            </Button>
                        </div>
                    </div>
                ) : null}
            </div>

            {(data?.pending_job || data?.last_status) && (
                <p css={tw`text-xs text-neutral-400 mb-3`}>
                    {data.pending_job ? (
                        <span>
                            Bekleyen iş: <strong>{data.pending_job.action}</strong>
                            {data.pending_job.packages?.length
                                ? ` (${data.pending_job.packages.join(', ')})`
                                : ''}{' '}
                            — Restart gerekli.
                        </span>
                    ) : null}
                    {data.last_status ? (
                        <span>
                            {data.pending_job ? ' · ' : ''}
                            Son durum: {data.last_status.state}/{data.last_status.action}
                            {data.last_status.at
                                ? ` · ${new Date(data.last_status.at * 1000).toLocaleString()}`
                                : ''}
                        </span>
                    ) : null}
                </p>
            )}

            <div css={tw`bg-neutral-700 rounded p-4`}>
                <div css={tw`flex flex-wrap items-center gap-2 mb-4`}>
                    <h3 css={tw`text-sm font-semibold text-neutral-100 m-0 mr-2`}>Paket bağımlılıkları</h3>
                    <Button disabled={busy} onClick={() => queueJob('install')}>
                        Yükle
                    </Button>
                    <Button disabled={busy} onClick={() => queueJob('update')}>
                        Güncelle
                    </Button>
                    <div css={tw`flex gap-2 ml-auto`}>
                        <button
                            type={'button'}
                            onClick={() => setFilter('all')}
                            css={[
                                tw`text-xs px-3 py-1.5 rounded border`,
                                filter === 'all'
                                    ? tw`bg-neutral-100 text-neutral-900 border-neutral-100`
                                    : tw`bg-neutral-800 text-neutral-300 border-neutral-600`,
                            ]}
                        >
                            Hepsi
                        </button>
                        <button
                            type={'button'}
                            onClick={() => setFilter('outdated')}
                            css={[
                                tw`text-xs px-3 py-1.5 rounded border`,
                                filter === 'outdated'
                                    ? tw`bg-neutral-100 text-neutral-900 border-neutral-100`
                                    : tw`bg-neutral-800 text-neutral-300 border-neutral-600`,
                            ]}
                        >
                            Güncellemeler ({outdatedCount})
                        </button>
                    </div>
                </div>

                {loading ? (
                    <Spinner centered />
                ) : (
                    <div css={tw`overflow-x-auto rounded border border-neutral-600`}>
                        <table css={tw`w-full text-sm text-left min-w-[900px]`}>
                            <thead>
                                <tr css={tw`bg-neutral-800 text-neutral-300 text-xs uppercase`}>
                                    <th css={tw`px-3 py-2 font-semibold`}>İsim</th>
                                    <th css={tw`px-3 py-2 font-semibold w-24`}>Sürüm</th>
                                    <th css={tw`px-3 py-2 font-semibold w-24`}>Güncelle</th>
                                    <th css={tw`px-3 py-2 font-semibold`}>Tanım</th>
                                    <th css={tw`px-3 py-2 font-semibold w-20`}>Kaynak</th>
                                    <th css={tw`px-3 py-2 font-semibold w-32`}>Durum</th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((pkg) => {
                                    const src = pkg.homepage || pkg.source || '';
                                    const ok = !pkg.outdated;
                                    return (
                                        <tr
                                            key={`${pkg.bucket}-${pkg.name}`}
                                            css={tw`border-t border-neutral-600 text-neutral-200`}
                                        >
                                            <td css={tw`px-3 py-2`}>
                                                <span css={tw`font-medium`}>{pkg.name}</span>
                                                <span
                                                    css={[
                                                        tw`ml-2 text-[10px] px-1.5 py-0.5 rounded font-bold`,
                                                        pkg.bucket === 'dev'
                                                            ? tw`bg-yellow-700 text-yellow-100`
                                                            : tw`bg-green-800 text-green-100`,
                                                    ]}
                                                >
                                                    {pkg.bucket === 'dev' ? 'dev' : 'prod'}
                                                </span>
                                            </td>
                                            <td css={tw`px-3 py-2`}>{pkg.version || '—'}</td>
                                            <td css={tw`px-3 py-2`}>{pkg.latest || pkg.version || '—'}</td>
                                            <td css={tw`px-3 py-2 text-neutral-400 max-w-xs truncate`} title={pkg.description || ''}>
                                                {pkg.description || '—'}
                                            </td>
                                            <td css={tw`px-3 py-2`}>
                                                {src ? (
                                                    <a
                                                        href={src}
                                                        target={'_blank'}
                                                        rel={'noreferrer'}
                                                        css={tw`text-blue-400 hover:underline`}
                                                    >
                                                        Kaynak
                                                    </a>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                            <td css={tw`px-3 py-2`}>
                                                <span css={ok ? tw`text-green-400 font-semibold` : tw`text-blue-400 font-semibold`}>
                                                    {ok ? 'Güncel' : 'Güncelleme var'}
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {!visible.length ? (
                                    <tr>
                                        <td colSpan={6} css={tw`px-3 py-8 text-center text-neutral-400`}>
                                            {data?.has_composer_json ? (
                                                'Paket listesi boş — Yükle ile kur veya lock oluştur.'
                                            ) : (
                                                <>
                                                    Bu sitede <code>/www/composer.json</code> yok (WordPress’te
                                                    normal). Laravel vb. için düzenle veya Require ile oluştur.
                                                </>
                                            )}
                                        </td>
                                    </tr>
                                ) : null}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </ServerContentBlock>
    );
};
