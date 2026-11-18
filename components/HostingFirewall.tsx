import React, { useCallback, useEffect, useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import { Button } from '@/components/elements/button/index';
import http from '@/api/http';
import FlashMessageRender from '@/components/FlashMessageRender';
import useFlash from '@/plugins/useFlash';

type FirewallPayload = {
    enabled?: boolean;
    mode?: string;
    rules?: { ip?: string; note?: string }[];
    updated_at?: number;
    restart_hint?: string;
};

type Policy = 'allow_all' | 'whitelist' | 'blacklist';

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { clearFlashes, addFlash, clearAndAddHttpError } = useFlash();
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [enabled, setEnabled] = useState(false);
    const [fwPolicy, setFwPolicy] = useState<Policy>('allow_all');
    const [rulesText, setRulesText] = useState('');
    const [meta, setMeta] = useState('');

    const load = useCallback(() => {
        setLoading(true);
        clearFlashes('prm-hosting-fw');
        http
            .get<FirewallPayload>(`/api/client/extensions/prmhosting/servers/${uuid}/hosting/firewall`)
            .then(({ data }) => {
                setEnabled(!!data.enabled);
                const next: Policy =
                    data.mode === 'whitelist' || data.mode === 'blacklist' ? data.mode : 'allow_all';
                setFwPolicy(next);
                setRulesText((data.rules || []).map((r) => r.ip || '').filter(Boolean).join('\n'));
                setMeta(
                    data.updated_at
                        ? `Son kayıt: ${new Date(data.updated_at * 1000).toLocaleString()}`
                        : 'Henüz kaydedilmedi'
                );
            })
            .catch((error) => clearAndAddHttpError({ key: 'prm-hosting-fw', error }))
            .finally(() => setLoading(false));
    }, [uuid, clearFlashes, clearAndAddHttpError]);

    useEffect(() => {
        load();
    }, [load]);

    const save = () => {
        setBusy(true);
        clearFlashes('prm-hosting-fw');
        const rules = rulesText
            .split(/[\r\n,;]+/)
            .map((s) => s.trim())
            .filter(Boolean)
            .map((ip) => ({ ip, note: '' }));
        http
            .put<{ ok?: boolean; firewall?: FirewallPayload }>(
                `/api/client/extensions/prmhosting/servers/${uuid}/hosting/firewall`,
                { enabled, mode: fwPolicy, rules }
            )
            .then(({ data }) => {
                const fw = data.firewall;
                if (fw?.updated_at) {
                    setMeta(
                        `Son kayıt: ${new Date(fw.updated_at * 1000).toLocaleString()}` +
                            (fw.restart_hint ? ` — ${fw.restart_hint}` : '')
                    );
                }
                addFlash({
                    key: 'prm-hosting-fw',
                    type: 'success',
                    message: fw?.restart_hint || 'Firewall kaydedildi. Nginx için sunucuyu restart et.',
                });
            })
            .catch((error) => clearAndAddHttpError({ key: 'prm-hosting-fw', error }))
            .finally(() => setBusy(false));
    };

    if (loading) {
        return (
            <ServerContentBlock title={'Site Firewall'}>
                <Spinner centered />
            </ServerContentBlock>
        );
    }

    return (
        <ServerContentBlock title={'Site Firewall'}>
            <FlashMessageRender byKey={'prm-hosting-fw'} css={tw`mb-4`} />
            <p css={tw`text-sm text-neutral-300 mb-4`}>
                Bu siteye kim erişsin — nginx <code>allow</code> / <code>deny</code>. Host SSH/MySQL firewall’ı
                SecGuard admin’dedir.
            </p>

            <div css={tw`bg-neutral-700 rounded p-4 mb-4 space-y-4`}>
                <label css={tw`flex items-center gap-2 text-sm text-neutral-100 cursor-pointer`}>
                    <input type={'checkbox'} checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
                    Site firewall aktif
                </label>

                <div>
                    <label css={tw`text-xs uppercase text-neutral-400 font-semibold`}>Politika</label>
                    <select
                        css={tw`mt-1 w-full bg-neutral-800 border border-neutral-600 rounded px-3 py-2 text-sm`}
                        value={fwPolicy}
                        disabled={!enabled}
                        onChange={(e) => setFwPolicy(e.target.value as Policy)}
                    >
                        <option value={'allow_all'}>Allow all — herkese açık</option>
                        <option value={'whitelist'}>Whitelist — sadece listedeki IP</option>
                        <option value={'blacklist'}>Blacklist — listedeki IP engelli</option>
                    </select>
                </div>

                <div>
                    <label css={tw`text-xs uppercase text-neutral-400 font-semibold`}>IP / CIDR listesi</label>
                    <textarea
                        css={tw`mt-1 w-full bg-neutral-800 border border-neutral-600 rounded px-3 py-2 text-sm font-mono min-h-[160px]`}
                        value={rulesText}
                        disabled={!enabled || fwPolicy === 'allow_all'}
                        placeholder={'203.0.113.10\n10.0.0.0/8'}
                        onChange={(e) => setRulesText(e.target.value)}
                    />
                    <p css={tw`text-xs text-neutral-400 mt-1`}>
                        Whitelist boş kaydedilemez. Kayıttan sonra sunucuyu restart et.
                    </p>
                </div>

                <p css={tw`text-xs text-neutral-400`}>{meta}</p>

                <div css={tw`flex gap-2`}>
                    <Button onClick={save} disabled={busy}>
                        {busy ? 'Kaydediliyor…' : 'Firewall kaydet'}
                    </Button>
                    <Button.Text onClick={load} disabled={busy}>
                        Yenile
                    </Button.Text>
                </div>
            </div>
        </ServerContentBlock>
    );
};
